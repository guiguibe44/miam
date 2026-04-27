<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Recipe;
use App\Entity\Ingredient;
use App\Entity\MealSlot;
use App\Entity\RecipeIngredient;
use App\Entity\WeeklyPlan;
use App\Form\RecipeType;
use App\Form\RecipeChoices;
use App\Repository\RecipeRepository;
use App\Service\ImageUploader;
use App\Service\JowBlogRecipeLinkCollector;
use App\Service\JowRecipeImporter;
use App\Service\RecipeCsvImporter;
use App\Service\RecipeSchemaOrgImporter;
use App\Service\RecipeWeb750gImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
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
    public function index(
        Request $request,
        RecipeRepository $recipeRepository,
        EntityManagerInterface $entityManager,
    ): Response
    {
        $listData = $this->computeRecipeListData($request, $recipeRepository);
        $planningContext = $this->buildRecipePlanningContext($entityManager);

        return $this->render('recipe/index.html.twig', array_merge($listData, $planningContext, [
            'mainIngredientChoices' => RecipeChoices::mainIngredients(),
            'seasonalityChoices' => RecipeChoices::seasonality(),
            'difficultyChoices' => RecipeChoices::difficulties(),
            'recipeOriginChoices' => RecipeChoices::recipeOrigins(),
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
    public function apiListFragment(
        Request $request,
        RecipeRepository $recipeRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse
    {
        $listData = $this->computeRecipeListData($request, $recipeRepository);
        $planningContext = $this->buildRecipePlanningContext($entityManager);

        $html = $this->renderView('recipe/_list_main.html.twig', [
            'recipes' => $listData['recipes'],
            'pagination' => $listData['pagination'],
            'filter_query' => $listData['filter_query'],
            'hasActiveFilters' => $listData['hasActiveFilters'],
            'planningSuggestion' => $planningContext['planningSuggestion'],
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
        $wantsAutoSaveJson = $request->headers->get('X-Recipe-Auto-Save') === '1';
        $form = $this->createForm(RecipeType::class, $recipe);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                if ($this->hasDuplicateRecipe($recipeRepository, $recipe, $recipe->getId())) {
                    if ($wantsAutoSaveJson) {
                        return $this->json(
                            [
                                'saved' => false,
                                'message' => 'Une recette avec le meme nom et la meme URL existe deja.',
                            ],
                            Response::HTTP_CONFLICT
                        );
                    }

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

                if ($wantsAutoSaveJson) {
                    return $this->json(['saved' => true]);
                }

                return $this->redirectToRoute('app_recipe_index');
            }

            if ($wantsAutoSaveJson) {
                return $this->json(
                    [
                        'saved' => false,
                        'errors' => $this->collectFormErrorMessages($form),
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        return $this->render('recipe/form.html.twig', [
            'recipe' => $recipe,
            'form' => $form,
            'pageTitle' => sprintf('Modifier "%s"', $recipe->getName()),
        ]);
    }

    #[Route('/{id}', name: 'app_recipe_show', methods: ['GET'])]
    public function show(Recipe $recipe, EntityManagerInterface $entityManager): Response
    {
        $planningContext = $this->buildRecipePlanningContext($entityManager);

        return $this->render('recipe/show.html.twig', [
            'recipe' => $recipe,
            'planningWeeks' => $planningContext['planningWeeks'],
            'mealTypeChoices' => $planningContext['mealTypeChoices'],
            'weekdayChoices' => $planningContext['weekdayChoices'],
            'planningSuggestion' => $planningContext['planningSuggestion'],
            'availableSlotsByWeek' => $planningContext['availableSlotsByWeek'],
        ]);
    }

    #[Route('/{id}/planifier', name: 'app_recipe_plan', methods: ['POST'])]
    public function plan(
        Request $request,
        Recipe $recipe,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('recipe_plan_'.$recipe->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $weekStartRaw = trim((string) $request->request->get('week_start'));
        $slotChoice = trim((string) $request->request->get('slot_choice'));
        $mealType = trim((string) $request->request->get('meal_type'));
        $weekday = (int) $request->request->get('weekday', 1);

        if ($slotChoice !== '' && preg_match('/^([1-7])\|(midi|soir)$/', $slotChoice, $m) === 1) {
            $weekday = (int) $m[1];
            $mealType = $m[2];
        }

        $weekStart = $this->parseWeekStart($weekStartRaw);
        if ($weekStart === null) {
            $this->addFlash('error', 'Semaine invalide.');

            return $this->redirectToRoute('app_recipe_show', ['id' => $recipe->getId()]);
        }

        if (!in_array($mealType, [MealSlot::MEAL_TYPE_LUNCH, MealSlot::MEAL_TYPE_DINNER], true)) {
            $this->addFlash('error', 'Type de repas invalide.');

            return $this->redirectToRoute('app_recipe_show', ['id' => $recipe->getId()]);
        }

        if ($weekday < 1 || $weekday > 7) {
            $this->addFlash('error', 'Jour invalide.');

            return $this->redirectToRoute('app_recipe_show', ['id' => $recipe->getId()]);
        }

        $servedOn = $weekStart->modify('+'.($weekday - 1).' days');
        $weeklyPlan = $this->findOrCreatePlan($entityManager, $weekStart);

        $mealSlot = $entityManager->getRepository(MealSlot::class)->findOneBy([
            'weeklyPlan' => $weeklyPlan,
            'servedOn' => $servedOn,
            'mealType' => $mealType,
        ]);

        if (!$mealSlot instanceof MealSlot) {
            $mealSlot = (new MealSlot())
                ->setWeeklyPlan($weeklyPlan)
                ->setServedOn($servedOn)
                ->setMealType($mealType);
            $entityManager->persist($mealSlot);
        }

        $previousRecipeId = $mealSlot->getRecipe()?->getId();
        $mealSlot->setRecipe($recipe);

        if ($previousRecipeId !== $recipe->getId()) {
            $recipe
                ->incrementPlanningSelectionCount()
                ->setPlanningLastSelectedAt(new \DateTimeImmutable('now'));
        }

        $entityManager->flush();

        $mealTypeLabel = $mealType === MealSlot::MEAL_TYPE_LUNCH ? 'midi' : 'soir';
        $this->addFlash(
            'success',
            sprintf('Recette ajoutée à la planification du %s (%s, %s).', $servedOn->format('d/m/Y'), $this->weekdayChoices()[$weekday], $mealTypeLabel)
        );

        return $this->redirectToRoute('app_recipe_show', ['id' => $recipe->getId()]);
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
            $imported = $jowRecipeImporter->importFromUrl($url);
            $recipe = $this->persistImportedRecipe($imported, Recipe::ORIGIN_JOW, $entityManager, $recipeRepository, $overwriteExisting);
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
                $imported = $jowRecipeImporter->importFromUrl($urlWithCovers);
                $recipe = $this->persistImportedRecipe($imported, Recipe::ORIGIN_JOW, $entityManager, $recipeRepository, $overwriteExisting);
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

    #[Route('/import/750g', name: 'app_recipe_import_750g', methods: ['POST'])]
    public function importFrom750g(
        Request $request,
        RecipeWeb750gImporter $recipeWeb750gImporter,
        EntityManagerInterface $entityManager,
        RecipeRepository $recipeRepository,
    ): Response {
        if (!$this->isCsrfTokenValid('import_750g', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $url = trim((string) $request->request->get('recipe_750g_url'));
        if ($url === '') {
            $this->addFlash('error', 'Merci de coller une URL de recette 750g (…-r12345.htm).');

            return $this->redirectToRoute('app_recipe_import');
        }

        $overwriteExisting = $request->request->getBoolean('overwrite_existing');

        try {
            $imported = $recipeWeb750gImporter->importFromUrl($url);
            $recipe = $this->persistImportedRecipe($imported, Recipe::ORIGIN_750G, $entityManager, $recipeRepository, $overwriteExisting);
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_recipe_import');
        }

        if ($recipe === null) {
            $this->addFlash('error', 'Recette deja importee (meme nom et meme URL).');

            return $this->redirectToRoute('app_recipe_import');
        }

        $entityManager->flush();

        $this->addFlash('success', sprintf($overwriteExisting ? 'Recette 750g importee/mise a jour : %s' : 'Recette 750g importee : %s', $recipe->getName()));

        return $this->redirectToRoute('app_recipe_edit', ['id' => $recipe->getId()]);
    }

    #[Route('/import/schema-recipe', name: 'app_recipe_import_schema_recipe', methods: ['POST'])]
    public function importFromSchemaRecipe(
        Request $request,
        RecipeSchemaOrgImporter $recipeSchemaOrgImporter,
        EntityManagerInterface $entityManager,
        RecipeRepository $recipeRepository,
    ): Response {
        if (!$this->isCsrfTokenValid('import_schema_recipe', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $url = trim((string) $request->request->get('schema_recipe_url'));
        if ($url === '') {
            $this->addFlash('error', 'Merci de coller l’URL de la page recette (JSON-LD schema.org / Recipe).');

            return $this->redirectToRoute('app_recipe_import');
        }

        $overwriteExisting = $request->request->getBoolean('overwrite_existing');

        try {
            $imported = $recipeSchemaOrgImporter->importFromUrl($url);
            $recipeOrigin = $this->recipeOriginForStructuredDataUrl((string) ($imported['sourceUrl'] ?? ''));
            $recipe = $this->persistImportedRecipe($imported, $recipeOrigin, $entityManager, $recipeRepository, $overwriteExisting);
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_recipe_import');
        }

        if ($recipe === null) {
            $this->addFlash('error', 'Recette deja importee (meme nom et meme URL).');

            return $this->redirectToRoute('app_recipe_import');
        }

        $entityManager->flush();

        $this->addFlash('success', sprintf($overwriteExisting ? 'Recette importee/mise a jour : %s' : 'Recette importee : %s', $recipe->getName()));

        return $this->redirectToRoute('app_recipe_edit', ['id' => $recipe->getId()]);
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

    /**
     * @param array{
     *   name:string,
     *   sourceUrl:?string,
     *   imageUrl:?string,
     *   preparationTimeMinutes:int,
     *   cookTimeMinutes:int,
     *   estimatedCost:string,
     *   portionPriceLabel:?string,
     *   difficulty:?string,
     *   caloriesPerPortion:?int,
     *   mainIngredient:string,
     *   steps:?string,
     *   ingredients:list<array{name:string,quantity:string,unit:string}>
     * } $imported
     */
    private function persistImportedRecipe(
        array $imported,
        string $recipeOrigin,
        EntityManagerInterface $entityManager,
        RecipeRepository $recipeRepository,
        bool $overwriteExisting = false,
    ): ?Recipe {
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
            ->setSeasonality('toute_annee')
            ->setRecipeOrigin($recipeOrigin);
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

    private function recipeOriginForStructuredDataUrl(string $sourceUrl): string
    {
        $host = parse_url($sourceUrl, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $host = strtolower($host);
            if ($host === 'marmiton.org' || str_ends_with($host, '.marmiton.org')) {
                return Recipe::ORIGIN_MARMITON;
            }
        }

        return Recipe::ORIGIN_WEB;
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
            'recipeOrigin' => trim((string) $request->query->get('recipeOrigin', '')),
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
        if (($filters['recipeOrigin'] ?? '') !== '') {
            $params['recipeOrigin'] = $filters['recipeOrigin'];
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
        if (($filters['recipeOrigin'] ?? '') !== '') {
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

    /**
     * @return array{
     *   planningWeeks: list<array{weekStart: string, label: string}>,
     *   weekdayChoices: array<int, string>,
     *   mealTypeChoices: array<string, string>,
     *   planningSuggestion: array{weekStart: string, weekday: int, mealType: string, label: string},
     *   availableSlotsByWeek: array<string, list<array{value: string, label: string}>>,
     *   dayMealsByWeek: array<string, array<string, list<array{mealType: string, mealLabel: string, recipeName: ?string}>>>
     * }
     */
    private function buildRecipePlanningContext(EntityManagerInterface $entityManager): array
    {
        $weekdayChoices = $this->weekdayChoices();
        $mealTypeChoices = [
            MealSlot::MEAL_TYPE_LUNCH => 'Midi',
            MealSlot::MEAL_TYPE_DINNER => 'Soir',
        ];
        $planningWeeks = $this->buildPlanningWeekOptions($entityManager);
        $availableSlotsByWeek = $this->buildAvailableFreeSlotsByWeek($entityManager, $planningWeeks);
        $dayMealsByWeek = $this->buildDayMealsByWeek($entityManager, $planningWeeks);
        $planningSuggestion = $this->findSuggestedFreeSlot($entityManager);

        return [
            'planningWeeks' => $planningWeeks,
            'weekdayChoices' => $weekdayChoices,
            'mealTypeChoices' => $mealTypeChoices,
            'planningSuggestion' => $planningSuggestion,
            'availableSlotsByWeek' => $availableSlotsByWeek,
            'dayMealsByWeek' => $dayMealsByWeek,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function weekdayChoices(): array
    {
        return [
            1 => 'Lundi',
            2 => 'Mardi',
            3 => 'Mercredi',
            4 => 'Jeudi',
            5 => 'Vendredi',
            6 => 'Samedi',
            7 => 'Dimanche',
        ];
    }

    /**
     * @return list<array{weekStart: string, label: string}>
     */
    private function buildPlanningWeekOptions(EntityManagerInterface $entityManager): array
    {
        $todayMonday = $this->mondayContaining(new \DateTimeImmutable('today'));
        $optionsByKey = [];

        $plans = $entityManager->getRepository(WeeklyPlan::class)->findBy([], ['weekStartAt' => 'ASC'], 24);
        foreach ($plans as $plan) {
            if (!$plan instanceof WeeklyPlan) {
                continue;
            }
            $weekStart = $plan->getWeekStartAt()->setTime(0, 0, 0);
            if ($weekStart < $todayMonday->modify('-56 days')) {
                continue;
            }
            $optionsByKey[$weekStart->format('Y-m-d')] = $weekStart;
        }

        if ($optionsByKey === []) {
            for ($i = 0; $i < 4; ++$i) {
                $weekStart = $todayMonday->modify('+'.(7 * $i).' days');
                $optionsByKey[$weekStart->format('Y-m-d')] = $weekStart;
            }
        }

        ksort($optionsByKey);
        $out = [];
        foreach ($optionsByKey as $key => $weekStart) {
            $weekEnd = $weekStart->modify('+6 days');
            $isExisting = $entityManager->getRepository(WeeklyPlan::class)->findOneBy(['weekStartAt' => $weekStart]) instanceof WeeklyPlan;
            $out[] = [
                'weekStart' => $key,
                'label' => sprintf(
                    'Semaine du %s au %s%s',
                    $weekStart->format('d/m/Y'),
                    $weekEnd->format('d/m/Y'),
                    $isExisting ? '' : ' (nouvelle)'
                ),
            ];
        }

        return $out;
    }

    /**
     * @return array{weekStart: string, weekday: int, mealType: string, label: string}
     */
    private function findSuggestedFreeSlot(EntityManagerInterface $entityManager): array
    {
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        $nowHour = (int) (new \DateTimeImmutable('now'))->format('H');
        $defaultMealType = $nowHour < 15 ? MealSlot::MEAL_TYPE_LUNCH : MealSlot::MEAL_TYPE_DINNER;

        $plans = $entityManager->getRepository(WeeklyPlan::class)->findBy([], ['weekStartAt' => 'ASC'], 24);
        foreach ($plans as $plan) {
            if (!$plan instanceof WeeklyPlan) {
                continue;
            }

            $weekStart = $plan->getWeekStartAt()->setTime(0, 0, 0);
            if ($weekStart->modify('+6 days') < $today) {
                continue;
            }

            $slotsByKey = [];
            foreach ($plan->getMealSlots() as $slot) {
                $slotsByKey[$slot->getServedOn()->format('Y-m-d').'_'.$slot->getMealType()] = $slot;
            }

            for ($i = 0; $i < 7; ++$i) {
                $servedOn = $weekStart->modify('+'.$i.' days');
                if ($servedOn < $today) {
                    continue;
                }
                foreach ([MealSlot::MEAL_TYPE_LUNCH, MealSlot::MEAL_TYPE_DINNER] as $mealType) {
                    $slotKey = $servedOn->format('Y-m-d').'_'.$mealType;
                    $slot = $slotsByKey[$slotKey] ?? null;
                    if (!$slot instanceof MealSlot || $slot->getRecipe() === null) {
                        $weekday = (int) $servedOn->format('N');
                        $mealLabel = $mealType === MealSlot::MEAL_TYPE_LUNCH ? 'midi' : 'soir';

                        return [
                            'weekStart' => $weekStart->format('Y-m-d'),
                            'weekday' => $weekday,
                            'mealType' => $mealType,
                            'label' => sprintf('Créneau conseillé : %s %s', $this->weekdayChoices()[$weekday], $mealLabel),
                        ];
                    }
                }
            }
        }

        $fallbackDate = $today;
        $weekday = (int) $fallbackDate->format('N');
        $weekStart = $this->mondayContaining($fallbackDate);
        $mealLabel = $defaultMealType === MealSlot::MEAL_TYPE_LUNCH ? 'midi' : 'soir';

        return [
            'weekStart' => $weekStart->format('Y-m-d'),
            'weekday' => $weekday,
            'mealType' => $defaultMealType,
            'label' => sprintf('Créneau conseillé : %s %s', $this->weekdayChoices()[$weekday], $mealLabel),
        ];
    }

    /**
     * @param list<array{weekStart: string, label: string}> $planningWeeks
     *
     * @return array<string, list<array{value: string, label: string, readonly: bool}>>
     */
    private function buildAvailableFreeSlotsByWeek(EntityManagerInterface $entityManager, array $planningWeeks): array
    {
        $out = [];

        foreach ($planningWeeks as $weekOption) {
            $weekStart = $this->parseWeekStart((string) $weekOption['weekStart']);
            if (!$weekStart instanceof \DateTimeImmutable) {
                continue;
            }

            $plan = $entityManager->getRepository(WeeklyPlan::class)->findOneBy(['weekStartAt' => $weekStart]);
            if (!$plan instanceof WeeklyPlan) {
                $out[$weekStart->format('Y-m-d')] = [];

                continue;
            }

            $slots = [];
            foreach ($plan->getMealSlots() as $mealSlot) {
                $weekday = (int) $mealSlot->getServedOn()->format('N');
                $mealType = $mealSlot->getMealType();
                if (!isset($this->weekdayChoices()[$weekday])) {
                    continue;
                }
                if (!in_array($mealType, [MealSlot::MEAL_TYPE_LUNCH, MealSlot::MEAL_TYPE_DINNER], true)) {
                    continue;
                }
                $mealLabel = $mealType === MealSlot::MEAL_TYPE_LUNCH ? 'midi' : 'soir';
                $recipeName = $mealSlot->getRecipe()?->getName();
                $slots[] = [
                    'value' => $weekday.'|'.$mealType,
                    'label' => $this->weekdayChoices()[$weekday].' — '.$mealLabel.(
                        $recipeName !== null ? ' (déjà planifiée : '.$recipeName.')' : ' (libre)'
                    ),
                    'readonly' => $recipeName !== null,
                ];
            }

            usort(
                $slots,
                static fn (array $a, array $b): int => strcmp($a['value'], $b['value'])
            );

            $out[$weekStart->format('Y-m-d')] = array_values(
                array_unique($slots, SORT_REGULAR)
            );
        }

        return $out;
    }

    /**
     * @param list<array{weekStart: string, label: string}> $planningWeeks
     *
     * @return array<string, array<string, list<array{mealType: string, mealLabel: string, recipeName: ?string}>>>
     */
    private function buildDayMealsByWeek(EntityManagerInterface $entityManager, array $planningWeeks): array
    {
        $out = [];

        foreach ($planningWeeks as $weekOption) {
            $weekStart = $this->parseWeekStart((string) $weekOption['weekStart']);
            if (!$weekStart instanceof \DateTimeImmutable) {
                continue;
            }

            $weekKey = $weekStart->format('Y-m-d');
            $out[$weekKey] = [];

            $plan = $entityManager->getRepository(WeeklyPlan::class)->findOneBy(['weekStartAt' => $weekStart]);
            if (!$plan instanceof WeeklyPlan) {
                continue;
            }

            foreach ($plan->getMealSlots() as $mealSlot) {
                $weekday = (int) $mealSlot->getServedOn()->format('N');
                if ($weekday < 1 || $weekday > 7) {
                    continue;
                }

                $mealType = $mealSlot->getMealType();
                if (!in_array($mealType, [MealSlot::MEAL_TYPE_LUNCH, MealSlot::MEAL_TYPE_DINNER], true)) {
                    continue;
                }

                $dayKey = (string) $weekday;
                if (!isset($out[$weekKey][$dayKey])) {
                    $out[$weekKey][$dayKey] = [];
                }

                $out[$weekKey][$dayKey][] = [
                    'mealType' => $mealType,
                    'mealLabel' => $mealType === MealSlot::MEAL_TYPE_LUNCH ? 'Midi' : 'Soir',
                    'recipeName' => $mealSlot->getRecipe()?->getName(),
                ];
            }

            foreach ($out[$weekKey] as $dayKey => $rows) {
                usort(
                    $rows,
                    static fn (array $a, array $b): int => strcmp((string) $a['mealType'], (string) $b['mealType'])
                );
                $out[$weekKey][$dayKey] = $rows;
            }
        }

        return $out;
    }

    private function parseWeekStart(string $weekStart): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($weekStart))->setTime(0, 0, 0);
        } catch (\Exception) {
            return null;
        }
    }

    private function mondayContaining(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $dow = (int) $date->format('N');

        return $date->modify('-'.($dow - 1).' days')->setTime(0, 0, 0);
    }

    private function findOrCreatePlan(EntityManagerInterface $entityManager, \DateTimeImmutable $weekStart): WeeklyPlan
    {
        /** @var WeeklyPlan|null $plan */
        $plan = $entityManager->getRepository(WeeklyPlan::class)->findOneBy(['weekStartAt' => $weekStart]);
        if ($plan instanceof WeeklyPlan) {
            return $plan;
        }

        $plan = (new WeeklyPlan())->setWeekStartAt($weekStart);
        $entityManager->persist($plan);

        return $plan;
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

    /**
     * @return list<string>
     */
    private function collectFormErrorMessages(FormInterface $form): array
    {
        $messages = [];
        foreach ($form->getErrors(true) as $error) {
            $messages[] = $error->getMessage();
        }

        return $messages;
    }
}
