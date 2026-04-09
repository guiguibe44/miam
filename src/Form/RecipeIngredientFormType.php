<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\RecipeIngredient;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RecipeIngredientFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ingredientName', TextType::class, [
                'label' => 'Ingredient',
                'required' => false,
            ])
            ->add('quantity', NumberType::class, [
                'label' => 'Quantite',
                'scale' => 2,
                'html5' => true,
                'required' => false,
                'empty_data' => '1.00',
            ])
            ->add('unit', TextType::class, [
                'label' => 'Unite (g, ml, piece…)',
                'required' => false,
                'empty_data' => 'g',
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Catégorie',
                'required' => false,
                'placeholder' => false,
                'empty_data' => 'autre',
                'choices' => IngredientCategories::choices(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RecipeIngredient::class,
        ]);
    }
}
