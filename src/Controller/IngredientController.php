<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Ingredient;
use App\Form\IngredientCategories;
use App\Repository\IngredientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/ingredients')]
class IngredientController extends AbstractController
{
    #[Route('', name: 'app_ingredient_index', methods: ['GET'])]
    public function index(Request $request, IngredientRepository $ingredientRepository): Response
    {
        $filters = [
            'q' => trim((string) $request->query->get('q', '')),
            'category' => trim((string) $request->query->get('category', '')),
            'sort' => (string) $request->query->get('sort', 'name'),
            'dir' => strtoupper((string) $request->query->get('dir', 'ASC')) === 'DESC' ? 'DESC' : 'ASC',
        ];

        return $this->render('ingredient/index.html.twig', [
            'rows' => $ingredientRepository->findForAdmin($filters),
            'duplicateSuggestions' => $ingredientRepository->findDuplicateSuggestions(),
            'filters' => $filters,
            'categoryChoices' => IngredientCategories::choices(),
            'categoryLabelsByValue' => IngredientCategories::labelsByValue(),
        ]);
    }

    #[Route('/classer', name: 'app_ingredient_bulk_classify', methods: ['POST'])]
    public function bulkClassify(Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('ingredient_mass', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $ids = array_values(array_filter(array_map('intval', (array) $request->request->all('selected_ids'))));
        $category = trim((string) $request->request->get('bulk_category', ''));
        if ($ids === [] || $category === '') {
            $this->addFlash('warning', 'Sélectionne des ingrédients et une catégorie.');

            return $this->redirectToRoute('app_ingredient_index');
        }

        $ingredients = $entityManager->getRepository(Ingredient::class)->findBy(['id' => $ids]);
        if ($ingredients === []) {
            $this->addFlash('warning', 'Aucun ingrédient trouvé.');

            return $this->redirectToRoute('app_ingredient_index');
        }

        foreach ($ingredients as $ingredient) {
            if ($ingredient instanceof Ingredient) {
                $ingredient->setCategory($category);
                foreach ($ingredient->getRecipeIngredients() as $line) {
                    $line->setCategory($category);
                }
            }
        }

        $entityManager->flush();
        $this->addFlash('success', 'Catégories mises à jour pour la sélection.');

        return $this->redirectToRoute('app_ingredient_index');
    }

    #[Route('/fusionner', name: 'app_ingredient_merge', methods: ['POST'])]
    public function merge(Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('ingredient_mass', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $targetId = (int) $request->request->get('target_id', 0);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->request->all('selected_ids')))));
        $sourceIds = array_values(array_filter($ids, static fn (int $id): bool => $id !== $targetId));

        if ($targetId <= 0 || $sourceIds === []) {
            $this->addFlash('warning', 'Sélectionne au moins 2 ingrédients et un ingrédient cible.');

            return $this->redirectToRoute('app_ingredient_index');
        }

        $target = $entityManager->getRepository(Ingredient::class)->find($targetId);
        if (!$target instanceof Ingredient) {
            $this->addFlash('error', 'Ingrédient cible introuvable.');

            return $this->redirectToRoute('app_ingredient_index');
        }

        $sources = $entityManager->getRepository(Ingredient::class)->findBy(['id' => $sourceIds]);
        if ($sources === []) {
            $this->addFlash('warning', 'Aucun ingrédient source à fusionner.');

            return $this->redirectToRoute('app_ingredient_index');
        }

        foreach ($sources as $source) {
            if (!$source instanceof Ingredient) {
                continue;
            }
            foreach ($source->getRecipeIngredients() as $line) {
                $line->setIngredient($target);
                $line->setIngredientName($target->getName());
                if ($line->getCategory() === '' || $line->getCategory() === 'autre') {
                    $line->setCategory($target->getCategory());
                }
            }
            $entityManager->remove($source);
        }

        $entityManager->flush();
        $this->addFlash('success', 'Ingrédients fusionnés.');

        return $this->redirectToRoute('app_ingredient_index');
    }
}
