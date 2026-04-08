<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Recipe;
use App\Entity\Ingredient;
use App\Entity\RecipeIngredient;
use App\Form\RecipeType;
use App\Form\RecipeChoices;
use App\Repository\RecipeRepository;
use App\Service\ImageUploader;
use App\Service\JowBlogRecipeLinkCollector;
use App\Service\JowRecipeImporter;
use App\Service\RecipeCsvImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/recettes')]
class RecipeController extends AbstractController
{
    private const RECIPE_INDEX_PER_PAGE = 12;

    #[Route('', name: 'app_recipe_index', methods: ['GET'])]
    public function index(Request $request, RecipeRepository $recipeRepository): Response
    {
        $listData = $this->computeRecipeListData($request, $recipeRepository);

        return $this->render('recipe/index.html.twig', array_merge($listData, [
            'mainIngredientChoices' => RecipeChoices::mainIngredients(),
            'seasonalityChoices' => RecipeChoices::seasonality(),
            'difficultyChoices' => RecipeChoices::difficulties(),
            'sortChoices' => [
                'name' => 'Nom',
                'cost' => 'Prix',
                'prep' => 'Temps prep.',
                'cook' => 'Temps cuisson',
                'total' => 'Temps total',
                'calories' => 'Calories',
                'difficulty' => 'Difficulte',
            ],
        ]));
    }

    #[Route('/api/suggestions', name: 'app_recipe_api_suggestions', methods: ['GET'])]
    public function apiSuggestions(Request $request, RecipeRepository $recipeRepository): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(20, max(5, (int) $request->query->get('perPage', 8)));
        $offset = ($page - 1) * $perPage;

        $result = $recipeRepository->suggestByNameSearch($q, $perPage, $offset);
        $loaded = count($result['items']);

        return $this->json([
            'items' => $result['items'],
            'page' => $page,
            'perPage' => $perPage,
            'total' => $result['total'],
            'hasMore' => $offset + $loaded < $result['total'],
        ]);
    }

    #[Route('/api/list-fragment', name: 'app_recipe_api_list_fragment', methods: ['GET'])]
    public function apiListFragment(Request $request, RecipeRepository $recipeRepository): JsonResponse
    {
        $listData = $this->computeRecipeListData($request, $recipeRepository);

        $html = $this->renderView('recipe/_list_main.html.twig', [
            'recipes' => $listData['recipes'],
            'pagination' => $listData['pagination'],
            'filter_query' => $listData['filter_query'],
            'hasActiveFilters' => $listData['hasActiveFilters'],
        ]);

        return $this->json(['html' => $html]);
    }

    #[Route('/importer', name: 'app_recipe_import', methods: ['GET'])]
    public function importPage(): Response
    {
        return $this->render('recipe/import.html.twig');
    }

    #[Route('/nouvelle', name: 'app_recipe_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, RecipeRepository $recipeRepository, ImageUploader $imageUploader): Response
    {
        $recipe = new Recipe();
        $recipe->addRecipeIngredient(new RecipeIngredient());

        $form = $this->createForm(RecipeType::class, $recipe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->hasDuplicateRecipe($recipeRepository, $recipe)) {
                $this->addFlash('error', 'Une recette avec le meme nom et la meme URL existe deja.');

                return $this->render('recipe/form.html.twig', [
                    'recipe' => $recipe,
                    'form' => $form,
                    'pageTitle' => 'Nouvelle recette',
                ]);
            }

            $this->syncIngredients($recipe, $entityManager);
            $this->handleImageUpload($form->get('imageFile')->getData(), $recipe, $imageUploader);

            $entityManager->persist($recipe);
            $entityManager->flush();

            return $this->redirectToRoute('app_recipe_index');
        }

        return $this->render('recipe/form.html.twig', [
            'recipe' => $recipe,
            'form' => $form,
            'pageTitle' => 'Nouvelle recette',
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_recipe_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Recipe $recipe, EntityManagerInterface $entityManager, RecipeRepository $recipeRepository, ImageUploader $imageUploader): Response
    {
        $form = $this->createForm(RecipeType::class, $recipe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->hasDuplicateRecipe($recipeRepository, $recipe, $recipe->getId())) {
                $this->addFlash('error', 'Une recette avec le meme nom et la meme URL existe deja.');

                return $this->render('recipe/form.html.twig', [
                    'recipe' => $recipe,
                    'form' => $form,
                    'pageTitle' => sprintf('Modifier "%s"', $recipe->getName()),
                ]);
            }

            $this->syncIngredients($recipe, $entityManager);
            $this->handleImageUpload($form->get('imageFile')->getData(), $recipe, $imageUploader);
            $entityManager->flush();

            return $this->redirectToRoute('app_recipe_index');
        }

        return $this->render('recipe/form.html.twig', [
            'recipe' => $recipe,
            'form' => $form,
            'pageTitle' => sprintf('Modifier "%s"', $recipe->getName()),
        ]);
    }

    #[Route('/{id}', name: 'app_recipe_show', methods: ['GET'])]
    public function show(Recipe $recipe): Response
    {
        return $this->render('recipe/show.html.twig', [
            'recipe' => $recipe,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'app_recipe_delete', methods: ['POST'])]
    public function delete(Request $request, Recipe $recipe, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid(sprintf('delete_%d', $recipe->getId()), (string) $request->request->get('_token'))) {
            $entityManager->remove($recipe);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_recipe_index');
    }

    #[Route('/import/jow', name: 'app_recipe_import_jow', methods: ['POST'])]
    public function importFromJow(Request $request, JowRecipeImporter $jowRecipeImporter, EntityManagerInterface $entityManager, RecipeRepository $recipeRepository): Response
    {
        if (!$this->isCsrfTokenValid('import_jow', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $url = trim((string) $request->request->get('jow_url'));
        if ($url === '') {
            $this->addFlash('error', 'Merci de coller une URL de recette Jow.');

            return $this->redirectToRoute('app_recipe_import');
        }

        $overwriteExisting = $request->request->getBoolean('overwrite_existing');

        try {
            $recipe = $this->importSingleJowRecipeFromUrl($url, $jowRecipeImporter, $entityManager, $recipeRepository, $overwriteExisting);
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_recipe_import');
        }

        if ($recipe === null) {
            $this->addFlash('error', 'Recette deja importee (meme nom et meme URL).');

            return $this->redirectToRoute('app_recipe_import');
        }

        $entityManager->flush();

        $this->addFlash('success', sprintf($overwriteExisting ? 'Recette Jow importée/mise à jour : %s' : 'Recette Jow importée : %s', $recipe->getName()));

        return $this->redirectToRoute('app_recipe_edit', ['id' => $recipe->getId()]);
    }

    #[Route('/import/jow/blog', name: 'app_recipe_import_jow_blog', methods: ['POST'])]
    public function importFromJowBlog(
        Request $request,
        JowBlogRecipeLinkCollector $jowBlogRecipeLinkCollector,
        JowRecipeImporter $jowRecipeImporter,
        EntityManagerInterface $entityManager,
        RecipeRepository $recipeRepository,
    ): Response {
        if (!$this->isCsrfTokenValid('import_jow_blog', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $blogUrl = trim((string) $request->request->get('jow_blog_url'));
        if ($blogUrl === '') {
            $this->addFlash('error', 'Merci de coller l\'URL de l\'article de planification (blog Jow).');

            return $this->redirectToRoute('app_recipe_import');
        }

        $covers = (int) $request->request->get('jow_blog_covers', 4);
        if ($covers < 1) {
            $covers = 4;
        }
        if ($covers > 20) {
            $covers = 20;
        }

        try {
            $recipeUrls = $jowBlogRecipeLinkCollector->collectRecipeUrls($blogUrl);
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_recipe_import');
        }

        if ($recipeUrls === []) {
            $this->addFlash('error', 'Aucune recette trouvee sur cette page (iframes menu ou liens /recipes/).');

            return $this->redirectToRoute('app_recipe_import');
        }

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $overwriteExisting = $request->request->getBoolean('overwrite_existing');

        foreach ($recipeUrls as $recipeUrl) {
            $urlWithCovers = $this->recipeUrlWithCovers($recipeUrl, $covers);
            try {
                $recipe = $this->importSingleJowRecipeFromUrl($urlWithCovers, $jowRecipeImporter, $entityManager, $recipeRepository, $overwriteExisting);
                if ($recipe === null) {
                    ++$skipped;
                    continue;
                }
                $entityManager->flush();
                if ($overwriteExisting && $recipe->getId() !== null && $recipe->getSourceUrl() !== null) {
                    ++$updated;
                } else {
                    ++$imported;
                }
            } catch (\Throwable $e) {
                $errors[] = sprintf('%s — %s', $urlWithCovers, $e->getMessage());
            }
        }

        $this->addFlash('success', sprintf(
            'Blog importé : %d nouvelle(s), %d mise(s) à jour, %d ignorée(s), %d lien(s) trouvé(s).',
            $imported,
            $updated,
            $skipped,
            count($recipeUrls)
        ));

        foreach (array_slice($errors, 0, 5) as $line) {
            $this->addFlash('error', $line);
        }
        if (count($errors) > 5) {
            $this->addFlash('error', sprintf('... et %d autre(s) erreur(s).', count($errors) - 5));
        }

        return $this->redirectToRoute('app_recipe_index');
    }

    #[Route('/import/csv', name: 'app_recipe_import_csv', methods: ['POST'])]
    public function importCsv(Request $request, RecipeCsvImporter $recipeCsvImporter, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('import_csv', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $file = $request->files->get('csv_file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', 'Merci de choisir un fichier CSV valide.');

            return $this->redirectToRoute('app_recipe_import');
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        if ($extension !== 'csv') {
            $this->addFlash('error', 'Extension attendue : .csv');

            return $this->redirectToRoute('app_recipe_import');
        }

        try {
            $result = $recipeCsvImporter->importFromUploadedFile(
                $file,
                $entityManager,
                $request->request->getBoolean('overwrite_existing')
            );
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Import CSV impossible : '.$e->getMessage());

            return $this->redirectToRoute('app_recipe_import');
        }

        $summaryLabel = $result['imported'] > 0 ? 'success' : (($result['errors'] ?? []) !== [] ? 'error' : 'warning');
        $this->addFlash(
            $summaryLabel,
            sprintf(
                'Résultat import CSV — importées: %d, mises à jour: %d, ignorées: %d, erreurs: %d.',
                (int) $result['imported'],
                (int) ($result['updated'] ?? 0),
                (int) $result['skipped'],
                count($result['errors'] ?? [])
            )
        );

        foreach (array_slice($result['errors'], 0, 12) as $message) {
            $this->addFlash('error', $message);
        }
        if (count($result['errors']) > 12) {
            $this->addFlash('error', sprintf('... et %d autre(s) message(s).', count($result['errors']) - 12));
        }

        $skippedNames = array_values(array_unique(array_filter($result['skippedNames'] ?? [], static fn (mixed $v): bool => is_string($v) && $v !== '')));
        if ($skippedNames !== []) {
            $preview = implode(', ', array_slice($skippedNames, 0, 8));
            $suffix = count($skippedNames) > 8 ? sprintf(' … (+%d)', count($skippedNames) - 8) : '';
            $this->addFlash('warning', 'Ignorées (déjà présentes) : '.$preview.$suffix);
        }

        if ($result['imported'] === 0 && $result['skipped'] === 0 && $result['errors'] === []) {
            $this->addFlash('warning', 'Aucune ligne de donnée importée (fichier vide, uniquement des lignes vides, ou format non reconnu).');
        }

        return $this->redirectToRoute('app_recipe_import');
    }

    private function importSingleJowRecipeFromUrl(
        string $url,
        JowRecipeImporter $jowRecipeImporter,
        EntityManagerInterface $entityManager,
        RecipeRepository $recipeRepository,
        bool $overwriteExisting = false,
    ): ?Recipe {
        $imported = $jowRecipeImporter->importFromUrl($url);
        $existing = $recipeRepository->findDuplicateByNameAndSourceUrl($imported['name'], $imported['sourceUrl']);
        if ($existing instanceof Recipe && !$overwriteExisting) {
            return null;
        }
        $recipe = $existing instanceof Recipe ? $existing : (new Recipe());
        $recipe
            ->setName($imported['name'])
            ->setImageUrl($imported['imageUrl'])
            ->setPreparationTimeMinutes($imported['preparationTimeMinutes'])
            ->setCookTimeMinutes($imported['cookTimeMinutes'])
            ->setEstimatedCost($imported['estimatedCost'])
            ->setPortionPriceLabel($imported['portionPriceLabel'] ?? null)
            ->setDifficulty($imported['difficulty'] ?? null)
            ->setCaloriesPerPortion($imported['caloriesPerPortion'] ?? null)
            ->setSourceUrl($imported['sourceUrl'])
            ->setSteps($imported['steps'] ?? null)
            ->setMainIngredient($imported['mainIngredient'])
            ->setSeasonality('toute_annee');
        if ($existing instanceof Recipe) {
            foreach ($recipe->getRecipeIngredients()->toArray() as $line) {
                $recipe->removeRecipeIngredient($line);
            }
        }

        foreach ($imported['ingredients'] as $item) {
            $ingredient = $entityManager->getRepository(Ingredient::class)->findOneBy(['name' => $item['name']]);
            if ($ingredient === null) {
                $ingredient = (new Ingredient())
                    ->setName($item['name'])
                    ->setDefaultUnit($item['unit']);
                $entityManager->persist($ingredient);
            }

            $recipeIngredient = (new RecipeIngredient())
                ->setIngredientName($item['name'])
                ->setIngredient($ingredient)
                ->setCategory($ingredient->getCategory())
                ->setQuantity($item['quantity'])
                ->setUnit($item['unit']);
            $recipe->addRecipeIngredient($recipeIngredient);
        }

        if (!$existing instanceof Recipe) {
            $entityManager->persist($recipe);
        }

        return $recipe;
    }

    /**
     * @return array{
     *   filters: array<string, mixed>,
     *   recipes: list<Recipe>,
     *   filter_query: array<string, string|int|float>,
     *   hasActiveFilters: bool,
     *   pagination: array{page: int, perPage: int, total: int, totalPages: int}
     * }
     */
    private function computeRecipeListData(Request $request, RecipeRepository $recipeRepository): array
    {
        $filters = $this->parseRecipeListFilters($request);

        $total = $recipeRepository->countByFilters($filters);
        $perPage = self::RECIPE_INDEX_PER_PAGE;
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, (int) $request->query->get('page', 1));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $recipes = $total > 0
            ? $recipeRepository->findByFilters($filters, $perPage, $offset)
            : [];

        return [
            'filters' => $filters,
            'recipes' => $recipes,
            'filter_query' => $this->recipeListFilterQueryParams($filters),
            'hasActiveFilters' => $this->recipeListHasActiveFilters($filters),
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRecipeListFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query->get('q', '')),
            'mainIngredient' => trim((string) $request->query->get('mainIngredient', '')),
            'seasonality' => trim((string) $request->query->get('seasonality', '')),
            'difficulty' => trim((string) $request->query->get('difficulty', '')),
            'maxCost' => $request->query->get('maxCost') !== null && $request->query->get('maxCost') !== ''
                ? (float) $request->query->get('maxCost')
                : null,
            'maxPrep' => $request->query->get('maxPrep') !== null && $request->query->get('maxPrep') !== ''
                ? (int) $request->query->get('maxPrep')
                : null,
            'maxCook' => $request->query->get('maxCook') !== null && $request->query->get('maxCook') !== ''
                ? (int) $request->query->get('maxCook')
                : null,
            'maxTotal' => $request->query->get('maxTotal') !== null && $request->query->get('maxTotal') !== ''
                ? (int) $request->query->get('maxTotal')
                : null,
            'maxCalories' => $request->query->get('maxCalories') !== null && $request->query->get('maxCalories') !== ''
                ? (int) $request->query->get('maxCalories')
                : null,
            'sort' => (string) $request->query->get('sort', 'name'),
            'dir' => (string) $request->query->get('dir', 'ASC'),
        ];
    }

    private function recipeUrlWithCovers(string $recipeUrl, int $covers): string
    {
        $parts = parse_url($recipeUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return $recipeUrl;
        }

        parse_str($parts['query'] ?? '', $queryParams);
        $queryParams['coversCount'] = (string) max(1, $covers);

        return sprintf(
            '%s://%s%s?%s',
            $parts['scheme'],
            $parts['host'],
            $parts['path'],
            http_build_query($queryParams)
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, string|int|float>
     */
    private function recipeListFilterQueryParams(array $filters): array
    {
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            $params['q'] = $filters['q'];
        }
        if (($filters['mainIngredient'] ?? '') !== '') {
            $params['mainIngredient'] = $filters['mainIngredient'];
        }
        if (($filters['seasonality'] ?? '') !== '') {
            $params['seasonality'] = $filters['seasonality'];
        }
        if (($filters['difficulty'] ?? '') !== '') {
            $params['difficulty'] = $filters['difficulty'];
        }
        if (($filters['maxCost'] ?? null) !== null) {
            $params['maxCost'] = $filters['maxCost'];
        }
        if (($filters['maxPrep'] ?? null) !== null) {
            $params['maxPrep'] = $filters['maxPrep'];
        }
        if (($filters['maxCook'] ?? null) !== null) {
            $params['maxCook'] = $filters['maxCook'];
        }
        if (($filters['maxTotal'] ?? null) !== null) {
            $params['maxTotal'] = $filters['maxTotal'];
        }
        if (($filters['maxCalories'] ?? null) !== null) {
            $params['maxCalories'] = $filters['maxCalories'];
        }
        if (($filters['sort'] ?? 'name') !== 'name') {
            $params['sort'] = $filters['sort'];
        }
        if (($filters['dir'] ?? 'ASC') !== 'ASC') {
            $params['dir'] = $filters['dir'];
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function recipeListHasActiveFilters(array $filters): bool
    {
        if (($filters['q'] ?? '') !== '') {
            return true;
        }
        if (($filters['mainIngredient'] ?? '') !== '') {
            return true;
        }
        if (($filters['seasonality'] ?? '') !== '') {
            return true;
        }
        if (($filters['difficulty'] ?? '') !== '') {
            return true;
        }
        if (($filters['maxCost'] ?? null) !== null) {
            return true;
        }
        if (($filters['maxPrep'] ?? null) !== null) {
            return true;
        }
        if (($filters['maxCook'] ?? null) !== null) {
            return true;
        }
        if (($filters['maxTotal'] ?? null) !== null) {
            return true;
        }
        if (($filters['maxCalories'] ?? null) !== null) {
            return true;
        }

        return false;
    }

    private function hasDuplicateRecipe(RecipeRepository $recipeRepository, Recipe $recipe, ?int $excludeId = null): bool
    {
        return $recipeRepository->findDuplicateByNameAndSourceUrl($recipe->getName(), $recipe->getSourceUrl(), $excludeId) !== null;
    }

    private function syncIngredients(Recipe $recipe, EntityManagerInterface $entityManager): void
    {
        foreach ($recipe->getRecipeIngredients() as $line) {
            $name = trim($line->getIngredientName());
            if ($name === '') {
                continue;
            }

            $ingredient = $entityManager->getRepository(Ingredient::class)->findOneBy(['name' => $name]);
            if ($ingredient === null) {
                $ingredient = (new Ingredient())
                    ->setName($name)
                    ->setDefaultUnit($line->getUnit() !== '' ? $line->getUnit() : 'g');
                $entityManager->persist($ingredient);
            }

            if ($line->getCategory() === '' || $line->getCategory() === 'autre') {
                $line->setCategory($ingredient->getCategory());
            } elseif ($ingredient->getCategory() === 'autre') {
                $ingredient->setCategory($line->getCategory());
            }
            $line->setIngredient($ingredient);
        }
    }

    private function handleImageUpload(mixed $imageFile, Recipe $recipe, ImageUploader $imageUploader): void
    {
        if ($imageFile instanceof UploadedFile) {
            $recipe->setImageUrl($imageUploader->upload($imageFile));
        }
    }
}
