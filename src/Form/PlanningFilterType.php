<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\PlanningCriteria;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PlanningFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('periodStart', DateType::class, [
                'label' => 'Debut de periode',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => true,
            ])
            ->add('periodEnd', DateType::class, [
                'label' => 'Fin de periode',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => true,
            ])
            ->add('mainIngredient', ChoiceType::class, [
                'label' => 'Aliment principal',
                'required' => false,
                'placeholder' => 'Tous',
                'choices' => RecipeChoices::mainIngredients(),
            ])
            ->add('seasonality', ChoiceType::class, [
                'label' => 'Saisonnalite',
                'required' => false,
                'placeholder' => 'Toutes saisons',
                'choices' => [
                    'Hiver' => 'hiver',
                    'Printemps' => 'printemps',
                    'Ete' => 'ete',
                    'Automne' => 'automne',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlanningCriteria::class,
            'method' => 'GET',
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }
}
