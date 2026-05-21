<?php

namespace App\Form;

use App\Entity\NOA;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form type for Manifest creation
 * Requirements: 2.1-2.8
 */
class ManifestCreationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('noa', EntityType::class, [
                'label' => 'Select NOA',
                'class' => NOA::class,
                'choice_label' => function (NOA $noa) {
                    return sprintf(
                        '%s - BL: %s - Vessel: %s - ETA: %s',
                        $noa->getNoaNumber(),
                        $noa->getBlNumber(),
                        $noa->getVesselNumber(),
                        $noa->getEta()->format('Y-m-d H:i')
                    );
                },
                'placeholder' => 'Select an existing NOA',
                'required' => true,
                'attr' => [
                    'class' => 'form-select',
                    'data-noa-selector' => 'true'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'NOA selection is required'])
                ]
            ])
            ->add('blFile', FileType::class, [
                'label' => 'Bill of Lading (BL) File',
                'required' => true,
                'attr' => [
                    'class' => 'form-input',
                    'accept' => '.pdf,.jpg,.jpeg,.png'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'BL file is required']),
                    new Assert\File([
                        'maxSize' => '10M',
                        'mimeTypes' => [
                            'application/pdf',
                            'image/jpeg',
                            'image/png'
                        ],
                        'mimeTypesMessage' => 'Please upload a valid PDF or image file'
                    ])
                ]
            ])
            ->add('blNumber', TextType::class, [
                'label' => 'BL Number (for validation)',
                'required' => true,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Enter BL number to validate',
                    'maxlength' => 50
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'BL number is required for validation']),
                    new Assert\Length(['max' => 50])
                ],
                'help' => 'This must match the BL number from the selected NOA'
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'manifest_creation',
        ]);
    }
}
