<?php

namespace App\DataFixtures;

use App\Entity\Enum\FormStatus;
use App\Entity\Enum\FormType;
use App\Entity\FormConfiguration;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class FormConfigurationFixtures extends Fixture
{
    public const CONSIGNEE_FORM_REFERENCE = 'form-consignee';
    public const BROKER_FORM_REFERENCE = 'form-broker';

    public function load(ObjectManager $manager): void
    {
        // Create Consignee Accreditation Form
        $consigneeForm = new FormConfiguration();
        $consigneeForm->setName('Consignee Accreditation Form');
        $consigneeForm->setType(FormType::CONSIGNEE);
        $consigneeForm->setFields([
            'fields' => [
                [
                    'id' => 'business_registration_number',
                    'label' => 'Business Registration Number',
                    'type' => 'text',
                    'required' => true,
                    'validation' => [
                        'pattern' => '^[A-Z0-9]{10}$',
                        'message' => 'Must be 10 alphanumeric characters'
                    ],
                    'order' => 1
                ],
                [
                    'id' => 'business_license',
                    'label' => 'Business License Document',
                    'type' => 'file',
                    'required' => true,
                    'validation' => [
                        'allowedTypes' => ['pdf', 'jpg', 'png'],
                        'maxSize' => 10485760
                    ],
                    'order' => 2
                ],
                [
                    'id' => 'tax_identification',
                    'label' => 'Tax Identification Number',
                    'type' => 'text',
                    'required' => true,
                    'validation' => [
                        'pattern' => '^[0-9]{9}$',
                        'message' => 'Must be 9 digits'
                    ],
                    'order' => 3
                ],
                [
                    'id' => 'business_address',
                    'label' => 'Business Address',
                    'type' => 'text',
                    'required' => true,
                    'validation' => [
                        'minLength' => 10,
                        'maxLength' => 500
                    ],
                    'order' => 4
                ],
                [
                    'id' => 'contact_person',
                    'label' => 'Contact Person Name',
                    'type' => 'text',
                    'required' => true,
                    'validation' => [
                        'minLength' => 2,
                        'maxLength' => 100
                    ],
                    'order' => 5
                ],
                [
                    'id' => 'contact_phone',
                    'label' => 'Contact Phone Number',
                    'type' => 'text',
                    'required' => true,
                    'validation' => [
                        'pattern' => '^\\+?[1-9]\\d{1,14}$',
                        'message' => 'Must be a valid phone number'
                    ],
                    'order' => 6
                ],
                [
                    'id' => 'business_type',
                    'label' => 'Type of Business',
                    'type' => 'dropdown',
                    'required' => true,
                    'validation' => [
                        'options' => [
                            'import' => 'Import',
                            'export' => 'Export',
                            'both' => 'Import & Export',
                            'manufacturing' => 'Manufacturing',
                            'retail' => 'Retail',
                            'wholesale' => 'Wholesale'
                        ]
                    ],
                    'order' => 7
                ],
                [
                    'id' => 'years_in_business',
                    'label' => 'Years in Business',
                    'type' => 'number',
                    'required' => true,
                    'validation' => [
                        'min' => 0,
                        'max' => 100
                    ],
                    'order' => 8
                ],
                [
                    'id' => 'insurance_certificate',
                    'label' => 'Insurance Certificate',
                    'type' => 'file',
                    'required' => false,
                    'validation' => [
                        'allowedTypes' => ['pdf', 'jpg', 'png'],
                        'maxSize' => 10485760
                    ],
                    'order' => 9
                ],
                [
                    'id' => 'compliance_agreement',
                    'label' => 'I agree to comply with all shipping regulations',
                    'type' => 'checkbox',
                    'required' => true,
                    'validation' => [],
                    'order' => 10
                ]
            ]
        ]);
        $consigneeForm->publish();
        $manager->persist($consigneeForm);
        $this->addReference(self::CONSIGNEE_FORM_REFERENCE, $consigneeForm);

        // Create Broker Registration Form
        $brokerForm = new FormConfiguration();
        $brokerForm->setName('Broker Registration Form');
        $brokerForm->setType(FormType::BROKER);
        $brokerForm->setFields([
            'fields' => [
                [
                    'id' => 'broker_license_number',
                    'label' => 'Broker License Number',
                    'type' => 'text',
                    'required' => true,
                    'validation' => [
                        'pattern' => '^BRK[0-9]{8}$',
                        'message' => 'Must start with BRK followed by 8 digits'
                    ],
                    'order' => 1
                ],
                [
                    'id' => 'broker_license_document',
                    'label' => 'Broker License Document',
                    'type' => 'file',
                    'required' => true,
                    'validation' => [
                        'allowedTypes' => ['pdf'],
                        'maxSize' => 10485760
                    ],
                    'order' => 2
                ],
                [
                    'id' => 'company_registration',
                    'label' => 'Company Registration Certificate',
                    'type' => 'file',
                    'required' => true,
                    'validation' => [
                        'allowedTypes' => ['pdf', 'jpg', 'png'],
                        'maxSize' => 10485760
                    ],
                    'order' => 3
                ],
                [
                    'id' => 'business_address',
                    'label' => 'Business Address',
                    'type' => 'text',
                    'required' => true,
                    'validation' => [
                        'minLength' => 10,
                        'maxLength' => 500
                    ],
                    'order' => 4
                ],
                [
                    'id' => 'authorized_representative',
                    'label' => 'Authorized Representative Name',
                    'type' => 'text',
                    'required' => true,
                    'validation' => [
                        'minLength' => 2,
                        'maxLength' => 100
                    ],
                    'order' => 5
                ],
                [
                    'id' => 'representative_id',
                    'label' => 'Representative ID Document',
                    'type' => 'file',
                    'required' => true,
                    'validation' => [
                        'allowedTypes' => ['pdf', 'jpg', 'png'],
                        'maxSize' => 10485760
                    ],
                    'order' => 6
                ],
                [
                    'id' => 'service_areas',
                    'label' => 'Service Areas',
                    'type' => 'checkbox',
                    'required' => true,
                    'validation' => [
                        'options' => [
                            'customs_clearance' => 'Customs Clearance',
                            'freight_forwarding' => 'Freight Forwarding',
                            'cargo_handling' => 'Cargo Handling',
                            'documentation' => 'Documentation Services',
                            'warehousing' => 'Warehousing',
                            'transportation' => 'Transportation'
                        ]
                    ],
                    'order' => 7
                ],
                [
                    'id' => 'experience_years',
                    'label' => 'Years of Experience in Shipping',
                    'type' => 'number',
                    'required' => true,
                    'validation' => [
                        'min' => 1,
                        'max' => 50
                    ],
                    'order' => 8
                ],
                [
                    'id' => 'financial_guarantee',
                    'label' => 'Financial Guarantee Document',
                    'type' => 'file',
                    'required' => true,
                    'validation' => [
                        'allowedTypes' => ['pdf'],
                        'maxSize' => 10485760
                    ],
                    'order' => 9
                ],
                [
                    'id' => 'professional_indemnity',
                    'label' => 'Professional Indemnity Insurance',
                    'type' => 'file',
                    'required' => true,
                    'validation' => [
                        'allowedTypes' => ['pdf', 'jpg', 'png'],
                        'maxSize' => 10485760
                    ],
                    'order' => 10
                ],
                [
                    'id' => 'terms_acceptance',
                    'label' => 'I accept the terms and conditions of broker operations',
                    'type' => 'checkbox',
                    'required' => true,
                    'validation' => [],
                    'order' => 11
                ],
                [
                    'id' => 'regulatory_compliance',
                    'label' => 'I confirm compliance with all maritime regulations',
                    'type' => 'checkbox',
                    'required' => true,
                    'validation' => [],
                    'order' => 12
                ]
            ]
        ]);
        $brokerForm->publish();
        $manager->persist($brokerForm);
        $this->addReference(self::BROKER_FORM_REFERENCE, $brokerForm);

        $manager->flush();
    }
}