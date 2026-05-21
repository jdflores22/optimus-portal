<?php

namespace App\Form;

use App\Entity\Consignee;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form type for NOA creation
 * Requirements: 1.1-1.8, 13.1-13.5
 */
class NOACreationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('blNumber', TextType::class, [
                'label' => 'BL Number',
                'required' => true,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Enter Bill of Lading number',
                    'maxlength' => 50
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'BL number is required']),
                    new Assert\Length(['max' => 50])
                ]
            ])
            ->add('vesselNumber', TextType::class, [
                'label' => 'Vessel Number',
                'required' => true,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Enter vessel number',
                    'maxlength' => 50
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Vessel number is required']),
                    new Assert\Length(['max' => 50])
                ]
            ])
            ->add('eta', DateTimeType::class, [
                'label' => 'Estimated Time of Arrival (ETA)',
                'required' => true,
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-input',
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'ETA is required']),
                    new Assert\GreaterThanOrEqual([
                        'value' => 'today',
                        'message' => 'ETA must be today or a future date'
                    ])
                ]
            ])
            ->add('cyLocation', TextType::class, [
                'label' => 'CY Empty Return Location',
                'required' => true,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Enter Container Yard location',
                    'maxlength' => 100
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'CY location is required']),
                    new Assert\Length(['max' => 100])
                ]
            ])
            ->add('consignee', EntityType::class, [
                'label' => 'Consignee',
                'class' => Consignee::class,
                'choice_label' => function (Consignee $consignee) {
                    return $consignee->getUser()->getEmail() . ' - ' . $consignee->getUser()->getFullName();
                },
                'placeholder' => 'Select consignee',
                'required' => true,
                'attr' => [
                    'class' => 'form-select',
                    'data-search' => 'true'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Consignee is required'])
                ]
            ])
            ->add('containers', CollectionType::class, [
                'entry_type' => ContainerCollectionType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                'attr' => [
                    'class' => 'container-collection'
                ],
                'constraints' => [
                    new Assert\Count([
                        'min' => 1,
                        'minMessage' => 'At least one container is required'
                    ])
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'noa_creation',
        ]);
    }
}
