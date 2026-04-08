<?php

declare(strict_types=1);

namespace App\Dto;

class PlanningCriteria
{
    public ?\DateTimeImmutable $periodStart = null;

    public ?\DateTimeImmutable $periodEnd = null;

    /** @var list<string> */
    public array $days = [];

    /** @var list<string> */
    public array $mealTypes = [];

    public ?float $maxCost = null;

    public ?string $mainIngredient = null;

    public ?int $maxPreparationTime = null;

    public ?int $maxCookTime = null;

    public ?int $maxTotalTime = null;

    public ?string $seasonality = null;

    public ?string $difficulty = null;

    public ?int $maxCalories = null;
}
