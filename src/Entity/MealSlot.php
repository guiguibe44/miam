<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MealSlotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MealSlotRepository::class)]
class MealSlot
{
    public const MEAL_TYPE_LUNCH = 'midi';
    public const MEAL_TYPE_DINNER = 'soir';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'mealSlots')]
    #[ORM\JoinColumn(nullable: false)]
    private ?WeeklyPlan $weeklyPlan = null;

    #[ORM\Column]
    private \DateTimeImmutable $servedOn;

    #[ORM\Column(length: 10)]
    #[Assert\Choice(choices: [self::MEAL_TYPE_LUNCH, self::MEAL_TYPE_DINNER])]
    private string $mealType = self::MEAL_TYPE_LUNCH;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Recipe $recipe = null;

    public function __construct()
    {
        $this->servedOn = new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWeeklyPlan(): ?WeeklyPlan
    {
        return $this->weeklyPlan;
    }

    public function setWeeklyPlan(?WeeklyPlan $weeklyPlan): self
    {
        $this->weeklyPlan = $weeklyPlan;

        return $this;
    }

    public function getServedOn(): \DateTimeImmutable
    {
        return $this->servedOn;
    }

    public function setServedOn(\DateTimeImmutable $servedOn): self
    {
        $this->servedOn = $servedOn;

        return $this;
    }

    public function getMealType(): string
    {
        return $this->mealType;
    }

    public function setMealType(string $mealType): self
    {
        $this->mealType = $mealType;

        return $this;
    }

    public function getRecipe(): ?Recipe
    {
        return $this->recipe;
    }

    public function setRecipe(?Recipe $recipe): self
    {
        $this->recipe = $recipe;

        return $this;
    }
}
