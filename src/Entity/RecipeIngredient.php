<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class RecipeIngredient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'recipeIngredients')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Recipe $recipe = null;

    #[ORM\ManyToOne(inversedBy: 'recipeIngredients')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Ingredient $ingredient = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private string $ingredientName = '';

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    #[Assert\Positive]
    private string $quantity = '1.00';

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    private string $unit = '';

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    private string $category = 'autre';

    public function getId(): ?int
    {
        return $this->id;
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

    public function getIngredient(): ?Ingredient
    {
        return $this->ingredient;
    }

    public function setIngredient(?Ingredient $ingredient): self
    {
        $this->ingredient = $ingredient;

        return $this;
    }

    public function getIngredientName(): string
    {
        if ($this->ingredientName !== '') {
            return $this->ingredientName;
        }

        return $this->ingredient?->getName() ?? '';
    }

    public function setIngredientName(string $ingredientName): self
    {
        $this->ingredientName = trim($ingredientName);

        return $this;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnit(): string
    {
        return $this->unit;
    }

    public function setUnit(string $unit): self
    {
        $this->unit = $unit;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $normalized = trim((string) $category);
        $this->category = $normalized !== '' ? $normalized : 'autre';

        return $this;
    }
}
