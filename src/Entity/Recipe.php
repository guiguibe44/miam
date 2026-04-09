<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RecipeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RecipeRepository::class)]
class Recipe
{
    public const ORIGIN_MAISON = 'maison';

    public const ORIGIN_JOW = 'jow';

    public const ORIGIN_750G = '750g';

    /** Import depuis une URL exposant schema.org Recipe (JSON-LD), ex. blogs. */
    public const ORIGIN_WEB = 'web';

    /** Import schema.org depuis marmiton.org */
    public const ORIGIN_MARMITON = 'marmiton';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(name: 'recipe_origin', length: 16, options: ['default' => self::ORIGIN_MAISON])]
    #[Assert\Choice(choices: ['maison', 'jow', '750g', 'web', 'marmiton'])]
    private string $recipeOrigin = self::ORIGIN_MAISON;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Positive]
    private int $preparationTimeMinutes = 30;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $cookTimeMinutes = 0;

    #[ORM\Column(type: 'decimal', precision: 7, scale: 2)]
    #[Assert\PositiveOrZero]
    private string $estimatedCost = '0.00';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $mainIngredient = '';

    #[ORM\Column(length: 16)]
    #[Assert\Choice(choices: ['hiver', 'printemps', 'ete', 'automne', 'toute_annee'])]
    private string $seasonality = 'toute_annee';

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $difficulty = null;

    #[ORM\Column(nullable: true)]
    private ?int $caloriesPerPortion = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $portionPriceLabel = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $steps = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $planningSelectionCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $planningLastSelectedAt = null;

    /** @var Collection<int, RecipeIngredient> */
    #[ORM\OneToMany(mappedBy: 'recipe', targetEntity: RecipeIngredient::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $recipeIngredients;

    public function __construct()
    {
        $this->recipeIngredients = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getRecipeOrigin(): string
    {
        return $this->recipeOrigin;
    }

    public function setRecipeOrigin(string $recipeOrigin): self
    {
        $this->recipeOrigin = $recipeOrigin;

        return $this;
    }

    public function getRecipeOriginLabel(): string
    {
        return match ($this->recipeOrigin) {
            self::ORIGIN_JOW => 'Jow',
            self::ORIGIN_750G => '750g',
            self::ORIGIN_MARMITON => 'Marmiton',
            self::ORIGIN_WEB => 'Web',
            default => 'Maison',
        };
    }

    public function getPreparationTimeMinutes(): int
    {
        return $this->preparationTimeMinutes;
    }

    public function setPreparationTimeMinutes(int $preparationTimeMinutes): self
    {
        $this->preparationTimeMinutes = $preparationTimeMinutes;

        return $this;
    }

    public function getCookTimeMinutes(): int
    {
        return $this->cookTimeMinutes;
    }

    public function setCookTimeMinutes(int $cookTimeMinutes): self
    {
        $this->cookTimeMinutes = $cookTimeMinutes;

        return $this;
    }

    public function getTotalTimeMinutes(): int
    {
        return $this->preparationTimeMinutes + $this->cookTimeMinutes;
    }

    public function getEstimatedCost(): string
    {
        return $this->estimatedCost;
    }

    public function setEstimatedCost(string $estimatedCost): self
    {
        $this->estimatedCost = $estimatedCost;

        return $this;
    }

    public function getMainIngredient(): string
    {
        return $this->mainIngredient;
    }

    public function setMainIngredient(string $mainIngredient): self
    {
        $this->mainIngredient = $mainIngredient;

        return $this;
    }

    public function getSeasonality(): string
    {
        return $this->seasonality;
    }

    public function setSeasonality(string $seasonality): self
    {
        $this->seasonality = $seasonality;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    public function getDifficulty(): ?string
    {
        return $this->difficulty;
    }

    public function setDifficulty(?string $difficulty): self
    {
        $this->difficulty = $difficulty;

        return $this;
    }

    public function getCaloriesPerPortion(): ?int
    {
        return $this->caloriesPerPortion;
    }

    public function setCaloriesPerPortion(?int $caloriesPerPortion): self
    {
        $this->caloriesPerPortion = $caloriesPerPortion;

        return $this;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): self
    {
        $this->sourceUrl = $sourceUrl;

        return $this;
    }

    public function getPortionPriceLabel(): ?string
    {
        return $this->portionPriceLabel;
    }

    public function setPortionPriceLabel(?string $portionPriceLabel): self
    {
        $this->portionPriceLabel = $portionPriceLabel;

        return $this;
    }

    public function getSteps(): ?string
    {
        return $this->steps;
    }

    public function setSteps(?string $steps): self
    {
        $steps = $steps !== null ? trim($steps) : null;
        $this->steps = $steps !== '' ? $steps : null;

        return $this;
    }

    public function getPlanningSelectionCount(): int
    {
        return $this->planningSelectionCount;
    }

    public function setPlanningSelectionCount(int $planningSelectionCount): self
    {
        $this->planningSelectionCount = max(0, $planningSelectionCount);

        return $this;
    }

    public function incrementPlanningSelectionCount(): self
    {
        ++$this->planningSelectionCount;

        return $this;
    }

    public function getPlanningLastSelectedAt(): ?\DateTimeImmutable
    {
        return $this->planningLastSelectedAt;
    }

    public function setPlanningLastSelectedAt(?\DateTimeImmutable $planningLastSelectedAt): self
    {
        $this->planningLastSelectedAt = $planningLastSelectedAt;

        return $this;
    }

    /** @return Collection<int, RecipeIngredient> */
    public function getRecipeIngredients(): Collection
    {
        return $this->recipeIngredients;
    }

    public function addRecipeIngredient(RecipeIngredient $recipeIngredient): self
    {
        if (!$this->recipeIngredients->contains($recipeIngredient)) {
            $this->recipeIngredients->add($recipeIngredient);
            $recipeIngredient->setRecipe($this);
        }

        return $this;
    }

    public function removeRecipeIngredient(RecipeIngredient $recipeIngredient): self
    {
        if ($this->recipeIngredients->removeElement($recipeIngredient) && $recipeIngredient->getRecipe() === $this) {
            $recipeIngredient->setRecipe(null);
        }

        return $this;
    }
}
