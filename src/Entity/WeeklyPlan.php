<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class WeeklyPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $weekStartAt;

    /** @var Collection<int, MealSlot> */
    #[ORM\OneToMany(mappedBy: 'weeklyPlan', targetEntity: MealSlot::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $mealSlots;

    public function __construct()
    {
        $this->weekStartAt = new \DateTimeImmutable('monday this week');
        $this->mealSlots = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWeekStartAt(): \DateTimeImmutable
    {
        return $this->weekStartAt;
    }

    public function setWeekStartAt(\DateTimeImmutable $weekStartAt): self
    {
        $this->weekStartAt = $weekStartAt;

        return $this;
    }

    /** @return Collection<int, MealSlot> */
    public function getMealSlots(): Collection
    {
        return $this->mealSlots;
    }

    public function addMealSlot(MealSlot $mealSlot): self
    {
        if (!$this->mealSlots->contains($mealSlot)) {
            $this->mealSlots->add($mealSlot);
            $mealSlot->setWeeklyPlan($this);
        }

        return $this;
    }
}
