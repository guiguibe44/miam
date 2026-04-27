<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PlanningCriteria;
use App\Entity\MealSlot;
use App\Entity\Recipe;
use App\Entity\ShoppingListItemState;
use App\Entity\WeeklyPlan;
use App\Form\PlanningFilterType;
use App\Repository\RecipeRepository;
use App\Service\WeekIngredientsAggregator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/planification')]
class PlanningController extends AbstractController
{
    private const MAX_PERIOD_DAYS = 31;

    #[Route('', name: 'app_planning_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $planningHistory = $this->buildPlanningHistory($entityManager);
        $nextMeals = $this->buildNextMeals($entityManager);

        $newWeekStart = $planningHistory !== []
            ? $planningHistory[0]['weekStart']->modify('+7 days')
            : $this->defaultMondayThisWeek();
        $newWeekEnd = $newWeekStart->modify('+6 days');

        return $this->render('planning/index.html.twig', [
            'planningHistory' => $planningHistory,
            'nextMeals' => $nextMeals,
            'newWeekStart' => $newWeekStart,
            'newWeekEnd' => $newWeekEnd,
        ]);
    }

    #[Route('/configurer', name: 'app_planning_configure', methods: ['GET'])]
    public function configure(
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response
    {
        if ($request->query->get('planning_go_slots') === '1') {
            $params = $request->query->all();
            unset($params['planning_go_slots']);
            $params['planning_show_slots'] = '1';

            return $this->redirect($this->generateUrl('app_planning_configure').'?'.http_build_query($params));
        }

        $planningShowSlots = $request->query->getBoolean('planning_show_slots');

        $criteria = new PlanningCriteria();
        $criteria->periodStart = $this->defaultMondayThisWeek();
        $criteria->periodEnd = $this->defaultSundayThisWeek();
        $criteria->seasonality = $this->detectSeason();

        $form = $this->createForm(PlanningFilterType::class, $criteria);
        $form->handleRequest($request);

        $this->normalizeCriteriaPeriod($criteria);

        $slots = $this->buildSlotsForPeriod($criteria);
        $slotsByDate = $this->groupSlotsByDate($slots);

        $selectedRecipesBySlot = $this->mergeSelectedMapsForPeriod(
            $entityManager,
            $criteria->periodStart,
            $criteria->periodEnd
        );
        $enabledSlotsBySlot = $this->mergeEnabledSlotsMapForPeriod(
            $entityManager,
            $criteria->periodStart,
            $criteria->periodEnd
        );
        $existingPlanWeeks = $this->existingPlanWeeksForPeriod(
            $entityManager,
            $criteria->periodStart,
            $criteria->periodEnd
        );
        return $this->render('planning/configure.html.twig', [
            'form' => $form,
            'slots' => $slots,
            'slotsByDate' => $slotsByDate,
            'selectedRecipesBySlot' => $selectedRecipesBySlot,
            'enabledSlotsBySlot' => $enabledSlotsBySlot,
            'existingPlanWeeks' => $existingPlanWeeks,
            'criteria' => $criteria,
            'planningShowSlots' => $planningShowSlots,
            'planSuggestUrl' => $this->generateUrl('app_planning_api_recipe_suggest'),
            'planRelatedUrl' => $this->generateUrl('app_planning_api_recipe_related'),
            'planLibraryUrl' => $this->generateUrl('app_planning_api_recipe_library'),
        ]);
    }

    #[Route('/api/recettes/library', name: 'app_planning_api_recipe_library', methods: ['GET'])]
    public function apiRecipeLibrary(Request $request, RecipeRepository $recipeRepository): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $limit = min(80, max(10, (int) $request->query->get('limit', 40)));

        return $this->json([
            'items' => $recipeRepository->findForPlanningLibrary($q, $limit),
        ]);
    }

    #[Route('/historique/{weekStart}/supprimer', name: 'app_planning_history_delete', methods: ['POST'])]
    public function deleteHistoryWeek(string $weekStart, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('planning_delete_'.$weekStart, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $weekStartAt = $this->parseWeekStart($weekStart);
        if ($weekStartAt === null) {
            $this->addFlash('error', 'Semaine invalide.');

            return $this->redirectToRoute('app_planning_index');
        }

        $plan = $entityManager->getRepository(WeeklyPlan::class)->findOneBy(['weekStartAt' => $weekStartAt]);
        if (!$plan instanceof WeeklyPlan) {
            $this->addFlash('warning', 'Cette planification n’existe plus.');

            return $this->redirectToRoute('app_planning_index');
        }

        $entityManager->remove($plan);
        $entityManager->flush();
        $this->addFlash('success', 'Planification supprimée.');

        return $this->redirectToRoute('app_planning_index');
    }

    #[Route('/historique/{weekStart}/dupliquer', name: 'app_planning_history_duplicate', methods: ['POST'])]
    public function duplicateHistoryWeek(string $weekStart, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('planning_duplicate_'.$weekStart, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $sourceWeekStart = $this->parseWeekStart($weekStart);
        if ($sourceWeekStart === null) {
            $this->addFlash('error', 'Semaine invalide.');

            return $this->redirectToRoute('app_planning_index');
        }

        $source = $entityManager->getRepository(WeeklyPlan::class)->findOneBy(['weekStartAt' => $sourceWeekStart]);
        if (!$source instanceof WeeklyPlan) {
            $this->addFlash('warning', 'La semaine source est introuvable.');

            return $this->redirectToRoute('app_planning_index');
        }

        $targetWeekStart = $sourceWeekStart->modify('+7 days');
        $targetExists = $entityManager->getRepository(WeeklyPlan::class)->findOneBy(['weekStartAt' => $targetWeekStart]);
        if ($targetExists instanceof WeeklyPlan) {
            $this->addFlash('warning', 'Impossible de dupliquer : la semaine suivante existe déjà.');

            return $this->redirectToRoute('app_planning_index');
        }

        $target = (new WeeklyPlan())->setWeekStartAt($targetWeekStart);
        foreach ($source->getMealSlots() as $sourceSlot) {
            $target->addMealSlot(
                (new MealSlot())
                    ->setServedOn($sourceSlot->getServedOn()->modify('+7 days'))
                    ->setMealType($sourceSlot->getMealType())
                    ->setRecipe($sourceSlot->getRecipe())
            );
        }

        $entityManager->persist($target);
        $entityManager->flush();

        $this->addFlash('success', 'Planification dupliquée sur la semaine suivante.');

        return $this->redirectToRoute('app_planning_index');
    }

    #[Route('/api/recettes/suggest', name: 'app_planning_api_recipe_suggest', methods: ['GET'])]
    public function apiRecipeSuggest(Request $request, RecipeRepository $recipeRepository): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $limit = min(12, max(5, (int) $request->query->get('limit', 8)));
        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * $limit;

        if (mb_strlen($q) < 2) {
            return $this->json(['items' => [], 'total' => 0, 'page' => $page, 'perPage' => $limit, 'hasMore' => false]);
        }

        $result = $recipeRepository->suggestByNameSearchForPlanning($q, $limit, $offset);
        $loaded = count($result['items']);

        return $this->json([
            'items' => $result['items'],
            'page' => $page,
            'perPage' => $limit,
            'total' => $result['total'],
            'hasMore' => $offset + $loaded < $result['total'],
        ]);
    }

    #[Route('/api/recettes/related', name: 'app_planning_api_recipe_related', methods: ['GET'])]
    public function apiRecipeRelated(Request $request, RecipeRepository $recipeRepository): JsonResponse
    {
        $recipeId = (int) $request->query->get('recipeId', 0);
        $similarLimit = min(6, max(1, (int) $request->query->get('similarLimit', 3)));
        $differentLimit = min(6, max(1, (int) $request->query->get('differentLimit', 3)));
        $initialLimit = min(8, max(1, (int) $request->query->get('initialLimit', 3)));
        if ($recipeId <= 0) {
            return $this->json([
                'neverSelected' => $recipeRepository->findNeverSelectedForPlanning($initialLimit),
                'recentlySelected' => $recipeRepository->findRecentlySelectedForPlanning($initialLimit),
            ]);
        }

        $excludeIdsRaw = $request->query->all()['excludeIds'] ?? [];
        $excludeIds = [];
        if (is_array($excludeIdsRaw)) {
            foreach ($excludeIdsRaw as $value) {
                $id = (int) $value;
                if ($id > 0) {
                    $excludeIds[] = $id;
                }
            }
        }
        $excludeIds = array_values(array_unique($excludeIds));

        $groups = $recipeRepository->findSuggestionGroupsByRecipeId(
            $recipeId,
            $excludeIds,
            $similarLimit,
            $differentLimit
        );

        return $this->json($groups);
    }

    #[Route('/enregistrer', name: 'app_planning_save', methods: ['POST'])]
    public function savePlanning(Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('planning_save_all', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $assignRaw = $request->request->all()['recipe_assign'] ?? [];
        if (!is_array($assignRaw)) {
            $assignRaw = [];
        }
        $enabledRaw = $request->request->all()['slot_enabled'] ?? [];
        if (!is_array($enabledRaw)) {
            $enabledRaw = [];
        }

        $dirty = false;

        $slotKeys = array_unique(array_merge(array_keys($assignRaw), array_keys($enabledRaw)));
        foreach ($slotKeys as $slotKey) {
            if (!is_string($slotKey) || !preg_match('/^(\d{4}-\d{2}-\d{2})_(midi|soir)$/', $slotKey, $m)) {
                continue;
            }

            try {
                $servedOn = (new \DateTimeImmutable($m[1]))->setTime(0, 0, 0);
            } catch (\Exception) {
                continue;
            }

            $mealType = $m[2];
            $recipeId = (int) ($assignRaw[$slotKey] ?? 0);
            $isEnabled = isset($enabledRaw[$slotKey]);

            $weeklyPlan = $this->findOrCreatePlanForAnyDate($entityManager, $servedOn);

            $mealSlot = $entityManager->getRepository(MealSlot::class)->findOneBy([
                'weeklyPlan' => $weeklyPlan,
                'servedOn' => $servedOn,
                'mealType' => $mealType,
            ]);

            if (!$isEnabled) {
                if ($mealSlot !== null) {
                    $entityManager->remove($mealSlot);
                    $dirty = true;
                }

                continue;
            }

            if ($recipeId <= 0) {
                if ($mealSlot === null) {
                    $mealSlot = (new MealSlot())
                        ->setWeeklyPlan($weeklyPlan)
                        ->setServedOn($servedOn)
                        ->setMealType($mealType);
                    $entityManager->persist($mealSlot);
                    $dirty = true;
                } elseif ($mealSlot->getRecipe() !== null) {
                    $mealSlot->setRecipe(null);
                    $dirty = true;
                }

                continue;
            }

            $recipe = $entityManager->getRepository(Recipe::class)->find($recipeId);
            if ($recipe === null) {
                continue;
            }

            if ($mealSlot === null) {
                $mealSlot = (new MealSlot())
                    ->setWeeklyPlan($weeklyPlan)
                    ->setServedOn($servedOn)
                    ->setMealType($mealType);
                $entityManager->persist($mealSlot);
                $dirty = true;
            } elseif ($mealSlot->getRecipe()?->getId() !== $recipe->getId()) {
                $dirty = true;
            }

            $previousRecipeId = $mealSlot->getRecipe()?->getId();
            if ($previousRecipeId !== $recipe->getId()) {
                $recipe
                    ->incrementPlanningSelectionCount()
                    ->setPlanningLastSelectedAt(new \DateTimeImmutable('now'));
            }

            $mealSlot->setRecipe($recipe);
        }

        $entityManager->flush();

        if ($dirty) {
            $this->addFlash('success', 'Planification enregistree.');
        } else {
            $this->addFlash('warning', 'Aucun changement detecte (memes recettes ou champs laisses vides).');
        }

        return $this->redirectAfterPlanningSave($request);
    }

    #[Route('/courses', name: 'app_planning_shopping_list', methods: ['GET'])]
    public function shoppingList(
        Request $request,
        WeekIngredientsAggregator $aggregator,
        EntityManagerInterface $entityManager,
    ): Response
    {
        $period = $this->parseShoppingPeriodFromStrings(
            $request->query->get('start'),
            $request->query->get('end')
        );
        if ($period === null) {
            $this->addFlash('error', 'Période invalide ou trop longue (maximum '.self::MAX_PERIOD_DAYS.' jours).');

            return $this->redirectToRoute('app_planning_configure');
        }

        $lines = $aggregator->aggregateLines($period['start'], $period['end']);
        $shoppingStates = $this->loadShoppingStates($entityManager, $period['start'], $period['end'], $lines);

        return $this->render('planning/shopping_list.html.twig', [
            'periodStart' => $period['start'],
            'periodEnd' => $period['end'],
            'lines' => $lines,
            'shoppingStates' => $shoppingStates,
        ]);
    }

    #[Route('/courses/enregistrer', name: 'app_planning_shopping_save', methods: ['POST'])]
    public function shoppingListSave(
        Request $request,
        WeekIngredientsAggregator $aggregator,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('planning_shopping_save', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $period = $this->parseShoppingPeriodFromStrings(
            $request->request->get('start'),
            $request->request->get('end')
        );
        if ($period === null) {
            $this->addFlash('error', 'Période invalide.');

            return $this->redirectToRoute('app_planning_configure');
        }

        $lines = $aggregator->aggregateLines($period['start'], $period['end']);
        $choicesRaw = $request->request->all()['shopping_state'] ?? [];
        $choices = is_array($choicesRaw) ? $choicesRaw : [];

        $statesByKey = $this->loadShoppingStateEntities($entityManager, $period['start'], $period['end']);
        $dirty = false;

        foreach ($lines as $line) {
            $lineKey = $this->buildShoppingLineKey($line['category'], $line['ingredientName'], $line['unit']);
            $choice = $choices[$lineKey] ?? [];
            $status = $this->normalizeShoppingChoice($choice);

            $entity = $statesByKey[$lineKey] ?? null;

            if ($status === null) {
                if ($entity instanceof ShoppingListItemState) {
                    $entityManager->remove($entity);
                    $dirty = true;
                }

                continue;
            }

            if (!$entity instanceof ShoppingListItemState) {
                $entity = (new ShoppingListItemState())
                    ->setPeriodStart($period['start'])
                    ->setPeriodEnd($period['end'])
                    ->setCategoryKey(mb_strtolower(trim($line['category'])))
                    ->setIngredientKey(mb_strtolower(trim($line['ingredientName'])))
                    ->setUnitKey(mb_strtolower(trim($line['unit'])));
                $entityManager->persist($entity);
                $dirty = true;
            }

            if ($entity->getStatus() !== $status) {
                $entity->setStatus($status);
                $dirty = true;
            }
        }

        if ($dirty) {
            $entityManager->flush();
            $this->addFlash('success', 'Liste des courses enregistrée.');
        } else {
            $this->addFlash('warning', 'Aucun changement sur la liste des courses.');
        }

        return $this->redirectToRoute('app_planning_shopping_list', [
            'start' => $period['start']->format('Y-m-d'),
            'end' => $period['end']->format('Y-m-d'),
        ]);
    }

    #[Route('/courses/email', name: 'app_planning_shopping_email', methods: ['POST'])]
    public function shoppingListEmail(
        Request $request,
        WeekIngredientsAggregator $aggregator,
        MailerInterface $mailer,
        ValidatorInterface $validator,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('planning_shopping_email', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $period = $this->parseShoppingPeriodFromStrings(
            $request->request->get('start'),
            $request->request->get('end')
        );
        if ($period === null) {
            $this->addFlash('error', 'Période invalide.');

            return $this->redirectToRoute('app_planning_configure');
        }

        $emailTo = trim((string) $request->request->get('email'));
        $violations = $validator->validate($emailTo, [new Assert\NotBlank(message: 'Indique une adresse e-mail.'), new Assert\Email(message: 'Adresse e-mail invalide.')]);
        if (count($violations) > 0) {
            $this->addFlash('error', (string) $violations[0]->getMessage());

            return $this->redirectToRoute('app_planning_shopping_list', [
                'start' => $period['start']->format('Y-m-d'),
                'end' => $period['end']->format('Y-m-d'),
            ]);
        }

        $lines = $aggregator->aggregateLines($period['start'], $period['end']);
        [$subject, $htmlBody, $textBody] = $this->buildShoppingEmailContent(
            $lines,
            $period['start'],
            $period['end']
        );

        $fromRaw = trim((string) ($_ENV['MAILER_FROM'] ?? ''));
        $from = $fromRaw !== '' ? Address::create($fromRaw) : new Address('noreply@localhost', 'Miam');

        $message = (new Email())
            ->from($from)
            ->to($emailTo)
            ->subject($subject)
            ->html($htmlBody)
            ->text($textBody);

        try {
            $mailer->send($message);
            $this->addFlash('success', 'La liste a été envoyée à '.$emailTo.'.');
        } catch (TransportExceptionInterface $e) {
            $this->addFlash(
                'error',
                'Envoi impossible : vérifie la configuration MAILER_DSN (et MAILER_FROM) sur le serveur. '.$e->getMessage()
            );
        }

        return $this->redirectToRoute('app_planning_shopping_list', [
            'start' => $period['start']->format('Y-m-d'),
            'end' => $period['end']->format('Y-m-d'),
        ]);
    }

    /**
     * @param array<int, array{ingredientName: string, unit: string, quantity: string, lineCount: int, category: string, categoryLabel: string}> $lines
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function buildShoppingEmailContent(array $lines, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $range = 'du '.$start->format('d/m/Y').' au '.$end->format('d/m/Y');
        $subject = 'Miam — Liste des courses '.$range;

        $textRows = [];
        foreach ($lines as $row) {
            $textRows[] = $row['quantity'].' '.$row['unit'].' — '.$row['ingredientName'];
        }
        $textBody = "Liste des ingrédients (recettes planifiées enregistrées) — {$range}\n\n";
        $textBody .= $textRows === []
            ? "Aucun ingrédient : aucune recette enregistrée sur cette période, ou recettes sans ingrédients.\n"
            : implode("\n", $textRows)."\n";

        $htmlRows = '';
        foreach ($lines as $row) {
            $htmlRows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
                htmlspecialchars($row['ingredientName'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($row['quantity'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($row['unit'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }

        $htmlBody = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>';
        $htmlBody .= '<p><strong>Liste des ingrédients</strong> — recettes planifiées enregistrées<br>'
            .htmlspecialchars($range, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';

        if ($lines === []) {
            $htmlBody .= '<p>Aucun ingrédient pour cette période.</p>';
        } else {
            $htmlBody .= '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse">';
            $htmlBody .= '<thead><tr><th>Ingrédient</th><th>Quantité</th><th>Unité</th></tr></thead><tbody>';
            $htmlBody .= $htmlRows;
            $htmlBody .= '</tbody></table>';
        }
        $htmlBody .= '</body></html>';

        return [$subject, $htmlBody, $textBody];
    }

    /**
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}|null
     */
    private function parseShoppingPeriodFromStrings(mixed $startRaw, mixed $endRaw): ?array
    {
        if (!is_string($startRaw) || !is_string($endRaw)) {
            return null;
        }
        $startStr = trim($startRaw);
        $endStr = trim($endRaw);
        if ($startStr === '' || $endStr === '') {
            return null;
        }

        try {
            $start = (new \DateTimeImmutable($startStr))->setTime(0, 0, 0);
            $end = (new \DateTimeImmutable($endStr))->setTime(0, 0, 0);
        } catch (\Exception) {
            return null;
        }

        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        $days = $start->diff($end)->days;
        if ($days > self::MAX_PERIOD_DAYS) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return list<array{
     *   servedOn: \DateTimeImmutable,
     *   dateKey: string,
     *   dateTitle: string,
     *   weekdayKey: string,
     *   weekStartKey: string,
     *   mealType: string,
     *   mealLabel: string,
     *   slotKey: string
     * }>
     */
    private function buildSlotsForPeriod(PlanningCriteria $criteria): array
    {
        $start = $criteria->periodStart?->setTime(0, 0, 0);
        $end = $criteria->periodEnd?->setTime(0, 0, 0);
        if ($start === null || $end === null) {
            return [];
        }

        $mealTypes = [MealSlot::MEAL_TYPE_LUNCH, MealSlot::MEAL_TYPE_DINNER];
        $slots = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $weekdayKey = strtolower($cursor->format('l'));
            foreach ($mealTypes as $mealType) {
                $slotKey = $cursor->format('Y-m-d').'_'.$mealType;
                $weekStartKey = $this->mondayContaining($cursor)->format('Y-m-d');
                $slots[] = [
                    'servedOn' => $cursor,
                    'dateKey' => $cursor->format('Y-m-d'),
                    'dateTitle' => $this->formatFrenchDayTitle($cursor),
                    'weekdayKey' => $weekdayKey,
                    'weekStartKey' => $weekStartKey,
                    'mealType' => $mealType,
                    'mealLabel' => $mealType === MealSlot::MEAL_TYPE_LUNCH ? 'Midi' : 'Soir',
                    'slotKey' => $slotKey,
                ];
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $slots;
    }

    /**
     * @param list<array{servedOn: \DateTimeImmutable, dateKey: string, ...}> $slots
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupSlotsByDate(array $slots): array
    {
        $byDate = [];
        foreach ($slots as $slot) {
            $k = $slot['dateKey'];
            if (!isset($byDate[$k])) {
                $byDate[$k] = [];
            }
            $byDate[$k][] = $slot;
        }
        ksort($byDate);

        return $byDate;
    }

    private function formatFrenchDayTitle(\DateTimeImmutable $d): string
    {
        if (class_exists(\IntlDateFormatter::class)) {
            $fmt = new \IntlDateFormatter(
                'fr_FR',
                \IntlDateFormatter::FULL,
                \IntlDateFormatter::NONE,
                'Europe/Paris',
                \IntlDateFormatter::GREGORIAN,
                'EEEE d MMMM yyyy'
            );
            $out = $fmt->format($d);
            if (is_string($out) && $out !== '') {
                return mb_strtoupper(mb_substr($out, 0, 1)).mb_substr($out, 1);
            }
        }

        return $d->format('d/m/Y');
    }

    private function normalizeCriteriaPeriod(PlanningCriteria $criteria): void
    {
        if ($criteria->periodStart === null) {
            $criteria->periodStart = $this->defaultMondayThisWeek();
        }
        if ($criteria->periodEnd === null) {
            $criteria->periodEnd = $this->defaultSundayThisWeek();
        }

        $criteria->periodStart = $criteria->periodStart->setTime(0, 0, 0);
        $criteria->periodEnd = $criteria->periodEnd->setTime(0, 0, 0);

        if ($criteria->periodEnd < $criteria->periodStart) {
            $tmp = $criteria->periodStart;
            $criteria->periodStart = $criteria->periodEnd;
            $criteria->periodEnd = $tmp;
        }

        $days = $criteria->periodStart->diff($criteria->periodEnd)->days;
        if ($days > self::MAX_PERIOD_DAYS) {
            $criteria->periodEnd = $criteria->periodStart->modify('+'.self::MAX_PERIOD_DAYS.' days');
        }
    }

    private function defaultMondayThisWeek(): \DateTimeImmutable
    {
        return $this->mondayContaining(new \DateTimeImmutable('today'));
    }

    private function defaultSundayThisWeek(): \DateTimeImmutable
    {
        return $this->defaultMondayThisWeek()->modify('+6 days');
    }

    private function mondayContaining(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $dow = (int) $date->format('N');

        return $date->modify('-'.($dow - 1).' days')->setTime(0, 0, 0);
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

    private function findOrCreatePlanForAnyDate(EntityManagerInterface $entityManager, \DateTimeImmutable $anyDate): WeeklyPlan
    {
        $weekMonday = $this->mondayContaining($anyDate->setTime(0, 0, 0));

        return $this->findOrCreatePlan($entityManager, $weekMonday);
    }

    private function detectSeason(): string
    {
        $month = (int) (new \DateTimeImmutable())->format('n');

        return match (true) {
            $month <= 2 || $month === 12 => 'hiver',
            $month <= 5 => 'printemps',
            $month <= 8 => 'ete',
            default => 'automne',
        };
    }

    private function findOrCreatePlan(EntityManagerInterface $entityManager, \DateTimeImmutable $weekStart): WeeklyPlan
    {
        /** @var WeeklyPlan|null $plan */
        $plan = $entityManager->getRepository(WeeklyPlan::class)->findOneBy(['weekStartAt' => $weekStart]);
        if ($plan !== null) {
            return $plan;
        }

        $plan = (new WeeklyPlan())->setWeekStartAt($weekStart);
        $entityManager->persist($plan);
        $entityManager->flush();

        return $plan;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildSelectedRecipesMap(WeeklyPlan $weeklyPlan): array
    {
        $map = [];
        foreach ($weeklyPlan->getMealSlots() as $mealSlot) {
            if ($mealSlot->getRecipe() === null) {
                continue;
            }

            $recipe = $mealSlot->getRecipe();
            $key = $mealSlot->getServedOn()->format('Y-m-d').'_'.$mealSlot->getMealType();
            $map[$key] = [
                'id' => (int) $recipe->getId(),
                'name' => $recipe->getName(),
                'imageUrl' => $recipe->getImageUrl(),
                'preparationTimeMinutes' => $recipe->getPreparationTimeMinutes(),
                'cookTimeMinutes' => $recipe->getCookTimeMinutes(),
                'totalTimeMinutes' => $recipe->getTotalTimeMinutes(),
                'estimatedCost' => $recipe->getEstimatedCost(),
                'caloriesPerPortion' => $recipe->getCaloriesPerPortion(),
                'difficulty' => $recipe->getDifficulty(),
                'mainIngredient' => $recipe->getMainIngredient(),
            ];
        }

        return $map;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function mergeSelectedMapsForPeriod(
        EntityManagerInterface $entityManager,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
    ): array {
        $merged = [];
        $weekMonday = $this->mondayContaining($periodStart);
        $lastMonday = $this->mondayContaining($periodEnd);

        while ($weekMonday <= $lastMonday) {
            $plan = $entityManager->getRepository(WeeklyPlan::class)->findOneBy(['weekStartAt' => $weekMonday]);
            if ($plan instanceof WeeklyPlan) {
                $merged = array_merge($merged, $this->buildSelectedRecipesMap($plan));
            }
            $weekMonday = $weekMonday->modify('+7 days');
        }

        return $merged;
    }

    /**
     * @return array<string, bool>
     */
    private function mergeEnabledSlotsMapForPeriod(
        EntityManagerInterface $entityManager,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
    ): array {
        $enabled = [];
        $weekMonday = $this->mondayContaining($periodStart);
        $lastMonday = $this->mondayContaining($periodEnd);

        while ($weekMonday <= $lastMonday) {
            $plan = $entityManager->getRepository(WeeklyPlan::class)->findOneBy(['weekStartAt' => $weekMonday]);
            if ($plan instanceof WeeklyPlan) {
                foreach ($plan->getMealSlots() as $mealSlot) {
                    $key = $mealSlot->getServedOn()->format('Y-m-d').'_'.$mealSlot->getMealType();
                    $enabled[$key] = true;
                }
            }
            $weekMonday = $weekMonday->modify('+7 days');
        }

        return $enabled;
    }

    /**
     * @return array<string, bool>
     */
    private function existingPlanWeeksForPeriod(
        EntityManagerInterface $entityManager,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
    ): array {
        $weeks = [];
        $weekMonday = $this->mondayContaining($periodStart);
        $lastMonday = $this->mondayContaining($periodEnd);

        while ($weekMonday <= $lastMonday) {
            $plan = $entityManager->getRepository(WeeklyPlan::class)->findOneBy(['weekStartAt' => $weekMonday]);
            if ($plan instanceof WeeklyPlan) {
                $weeks[$weekMonday->format('Y-m-d')] = true;
            }
            $weekMonday = $weekMonday->modify('+7 days');
        }

        return $weeks;
    }

    private function redirectAfterPlanningSave(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('app_planning_index');
    }

    /**
     * @return list<array{
     *   weekStart: \DateTimeImmutable,
     *   weekEnd: \DateTimeImmutable,
     *   totalSlots: int,
     *   assignedSlots: int
     * }>
     */
    private function buildPlanningHistory(EntityManagerInterface $entityManager): array
    {
        $qb = $entityManager->createQueryBuilder();
        $rows = $qb
            ->select('p.weekStartAt AS weekStartAt')
            ->addSelect('COUNT(ms.id) AS totalSlots')
            ->addSelect('COUNT(r.id) AS assignedSlots')
            ->from(WeeklyPlan::class, 'p')
            ->leftJoin('p.mealSlots', 'ms')
            ->leftJoin('ms.recipe', 'r')
            ->groupBy('p.id')
            ->orderBy('p.weekStartAt', 'DESC')
            ->setMaxResults(12)
            ->getQuery()
            ->getArrayResult();

        $history = [];
        foreach ($rows as $row) {
            $weekStartRaw = $row['weekStartAt'] ?? null;
            if (!$weekStartRaw instanceof \DateTimeInterface) {
                continue;
            }

            $weekStart = \DateTimeImmutable::createFromInterface($weekStartRaw)->setTime(0, 0, 0);
            $history[] = [
                'weekStart' => $weekStart,
                'weekEnd' => $weekStart->modify('+6 days'),
                'totalSlots' => (int) ($row['totalSlots'] ?? 0),
                'assignedSlots' => (int) ($row['assignedSlots'] ?? 0),
            ];
        }

        return $history;
    }

    /**
     * @return list<array{servedOn: \DateTimeImmutable, mealLabel: string, recipeId: int, recipeName: string, recipeImageUrl: ?string}>
     */
    private function buildNextMeals(EntityManagerInterface $entityManager): array
    {
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        $qb = $entityManager->createQueryBuilder();
        $slots = $qb
            ->select('ms', 'r')
            ->from(MealSlot::class, 'ms')
            ->innerJoin('ms.recipe', 'r')
            ->where('ms.servedOn >= :today')
            ->setParameter('today', $today)
            ->orderBy('ms.servedOn', 'ASC')
            ->addOrderBy('ms.mealType', 'ASC')
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($slots as $slot) {
            if (!$slot instanceof MealSlot || $slot->getRecipe() === null) {
                continue;
            }
            $recipe = $slot->getRecipe();
            $out[] = [
                'servedOn' => $slot->getServedOn(),
                'mealLabel' => $slot->getMealType() === MealSlot::MEAL_TYPE_LUNCH ? 'Midi' : 'Soir',
                'recipeId' => (int) $recipe->getId(),
                'recipeName' => $recipe->getName(),
                'recipeImageUrl' => $recipe->getImageUrl(),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{ingredientName: string, unit: string, quantity: string, lineCount: int, category: string, categoryLabel: string}> $lines
     *
     * @return array<string, string>
     */
    private function loadShoppingStates(
        EntityManagerInterface $entityManager,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        array $lines,
    ): array {
        $statesByKey = $this->loadShoppingStateEntities($entityManager, $periodStart, $periodEnd);
        $result = [];
        foreach ($lines as $line) {
            $lineKey = $this->buildShoppingLineKey($line['category'], $line['ingredientName'], $line['unit']);
            $state = $statesByKey[$lineKey] ?? null;
            if ($state instanceof ShoppingListItemState) {
                $result[$lineKey] = $state->getStatus();
            }
        }

        return $result;
    }

    /**
     * @return array<string, ShoppingListItemState>
     */
    private function loadShoppingStateEntities(
        EntityManagerInterface $entityManager,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
    ): array {
        $entities = $entityManager->getRepository(ShoppingListItemState::class)->findBy([
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
        ]);
        $byKey = [];
        foreach ($entities as $entity) {
            if (!$entity instanceof ShoppingListItemState) {
                continue;
            }
            $k = $this->buildShoppingLineKey($entity->getCategoryKey(), $entity->getIngredientKey(), $entity->getUnitKey());
            $byKey[$k] = $entity;
        }

        return $byKey;
    }

    private function buildShoppingLineKey(string $category, string $ingredientName, string $unit): string
    {
        return mb_strtolower(trim($category)).'|'.mb_strtolower(trim($ingredientName)).'|'.mb_strtolower(trim($unit));
    }

    private function normalizeShoppingChoice(mixed $choice): ?string
    {
        if (!is_array($choice)) {
            return null;
        }
        $isInStock = in_array(ShoppingListItemState::STATUS_IN_STOCK, $choice, true);
        $isToBuy = in_array(ShoppingListItemState::STATUS_TO_BUY, $choice, true);
        if ($isInStock) {
            return ShoppingListItemState::STATUS_IN_STOCK;
        }
        if ($isToBuy) {
            return ShoppingListItemState::STATUS_TO_BUY;
        }

        return null;
    }

}
