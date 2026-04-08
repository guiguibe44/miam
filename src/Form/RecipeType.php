<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Recipe;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RecipeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom'])
            ->add('preparationTimeMinutes', null, ['label' => 'Temps de preparation (min)'])
            ->add('cookTimeMinutes', IntegerType::class, [
                'label' => 'Temps de cuisson (min)',
                'required' => false,
                'empty_data' => 0,
            ])
            ->add('estimatedCost', MoneyType::class, [
                'label' => 'Prix max. par portion (EUR, pour filtres)',
                'currency' => 'EUR',
                'divisor' => 1,
                'scale' => 2,
                'help' => 'Import Jow : borne haute de la fourchette. Ajustez si besoin.',
            ])
            ->add('portionPriceLabel', TextType::class, [
                'label' => 'Fourchette de prix (texte)',
                'required' => false,
                'help' => 'Ex : libelle copie depuis Jow ou note perso.',
            ])
            ->add('difficulty', ChoiceType::class, [
                'label' => 'Difficulte',
                'required' => false,
                'placeholder' => 'Non renseignee',
                'choices' => RecipeChoices::difficulties(),
            ])
            ->add('caloriesPerPortion', IntegerType::class, [
                'label' => 'Calories / portion (kcal)',
                'required' => false,
            ])
            ->add('mainIngredient', ChoiceType::class, [
                'label' => 'Aliment principal',
                'choices' => RecipeChoices::mainIngredients(),
            ])
            ->add('seasonality', ChoiceType::class, [
                'label' => 'Saisonnalite',
                'choices' => RecipeChoices::seasonality(),
            ])
            ->add('imageUrl', UrlType::class, [
                'label' => 'Image (URL)',
                'required' => false,
            ])
            ->add('sourceUrl', UrlType::class, [
                'label' => 'Lien source (ex. Jow)',
                'required' => false,
            ])
            ->add('steps', TextareaType::class, [
                'label' => 'Étapes',
                'required' => false,
                'attr' => [
                    'rows' => 10,
                    'placeholder' => "1. Préparer les ingrédients\n2. ...",
                ],
                'help' => 'Une étape par ligne, ou format numéroté libre.',
            ])
            ->add('imageFile', FileType::class, [
                'label' => 'Uploader une image',
                'mapped' => false,
                'required' => false,
            ])
            ->add('recipeIngredients', CollectionType::class, [
                'label' => 'Ingredients',
                'entry_type' => RecipeIngredientFormType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'attr' => ['class' => 'recipe-ingredients-collection'],
                'error_bubbling' => false,
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $recipe = $event->getData();
            if (!$recipe instanceof Recipe) {
                return;
            }

            foreach ($recipe->getRecipeIngredients()->toArray() as $line) {
                if ($line->getIngredientName() === '') {
                    $recipe->removeRecipeIngredient($line);
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Recipe::class,
        ]);
    }
}
