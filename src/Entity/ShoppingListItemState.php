<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'shopping_list_item_state')]
#[ORM\UniqueConstraint(name: 'uniq_shopping_state_period_item', columns: ['period_start', 'period_end', 'category_key', 'ingredient_key', 'unit_key'])]
class ShoppingListItemState
{
    public const STATUS_IN_STOCK = 'in_stock';
    public const STATUS_TO_BUY = 'to_buy';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column]
    private \DateTimeImmutable $periodEnd;

    #[ORM\Column(length: 180)]
    private string $ingredientKey = '';

    #[ORM\Column(length: 40)]
    private string $categoryKey = 'autre';

    #[ORM\Column(length: 80)]
    private string $unitKey = '';

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_TO_BUY;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function setPeriodStart(\DateTimeImmutable $periodStart): self
    {
        $this->periodStart = $periodStart;

        return $this;
    }

    public function getPeriodEnd(): \DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function setPeriodEnd(\DateTimeImmutable $periodEnd): self
    {
        $this->periodEnd = $periodEnd;

        return $this;
    }

    public function getIngredientKey(): string
    {
        return $this->ingredientKey;
    }

    public function setIngredientKey(string $ingredientKey): self
    {
        $this->ingredientKey = $ingredientKey;

        return $this;
    }

    public function getUnitKey(): string
    {
        return $this->unitKey;
    }

    public function getCategoryKey(): string
    {
        return $this->categoryKey;
    }

    public function setCategoryKey(string $categoryKey): self
    {
        $this->categoryKey = $categoryKey;

        return $this;
    }

    public function setUnitKey(string $unitKey): self
    {
        $this->unitKey = $unitKey;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }
}
