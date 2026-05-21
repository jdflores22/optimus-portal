<?php

namespace App\Form;

use App\Entity\ContainerType;
use App\Entity\ContainerSize;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form type for container collection in NOA
 * Requirements: 1.7
 */
class ContainerCollectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('containerNumber', TextType::class, [
                'label' => 'Container Number',
                'required' => true,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'e.g., ABCD1234567',
                    'maxlength' => 20
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Container number is required']),
                    new Assert\Length(['max' => 20])
                ]
            ])
            ->add('containerType', EntityType::class, [
                'label' => 'Container Type',
                'class' => ContainerType::class,
                'choice_label' => 'name',
                'placeholder' => 'Select type',
                'required' => true,
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Container type is required'])
                ]
            ])
            ->add('containerSize', EntityType::class, [
                'label' => 'Container Size',
                'class' => ContainerSize::class,
                'choice_label' => function (ContainerSize $size) {
                    return $size->getName() . ' (' . $size->getTeuValue() . ' TEU)';
                },
                'placeholder' => 'Select size',
                'required' => true,
                'attr' => [
                    'class' => 'form-select',
                    'data-teu-calculator' => 'true'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Container size is required'])
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
