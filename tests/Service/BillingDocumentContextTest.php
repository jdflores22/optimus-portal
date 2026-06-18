<?php

namespace App\Tests\Service;

use App\Entity\Billing;
use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Manifest;
use App\Entity\StaffUser;
use App\Service\DocumentTemplateContextBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class BillingDocumentContextTest extends TestCase
{
    public function testBuildBillingContextMapsLegacyInvoiceFields(): void
    {
        $repository = $this->createMock(\App\Repository\AccreditationSubmissionRepository::class);
        $repository->method('findByApplicantAndShippingLine')->willReturn(null);
        $repository->method('findByApplicant')->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')
            ->with(\App\Entity\AccreditationSubmission::class)
            ->willReturn($repository);

        $builder = new DocumentTemplateContextBuilder($entityManager);

        $broker = new Broker();
        $broker->setEmail('broker@example.com');
        $broker->setFullName('June Dionelle Flores');
        $broker->setRole(UserRole::BROKER);
        $broker->setStatus(AccountStatus::APPROVED);
        $broker->setPasswordHash('hash');

        $consignee = new Consignee();
        $consignee->setEmail('logistics@abctrading.ph');
        $consignee->setBusinessName('ABC Trading Corporation');
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setStatus(AccountStatus::APPROVED);
        $consignee->setPasswordHash('hash');

        $manifest = new Manifest();
        $manifest->setManifestNumber('MNF-2026-2010');
        $manifest->setBlNumber('BL20260602210');
        $manifest->setBroker($broker);
        $manifest->setConsignee($consignee);

        $generatedBy = new StaffUser();
        $generatedBy->setEmail('staff@example.com');
        $generatedBy->setRole(UserRole::ACCOUNTING);
        $generatedBy->setStatus(AccountStatus::APPROVED);
        $generatedBy->setPasswordHash('hash');
        $generatedBy->setFirstName('Jaydee');
        $generatedBy->setLastName('Dela Cruz');
        $generatedBy->setDepartment('Accounting');

        $billing = new Billing();
        $billing->setManifest($manifest);
        $billing->setGeneratedBy($generatedBy);
        $billing->setFreightCharges('66815.66');
        $billing->setThcCharges('92628.00');
        $billing->setOriginalCurrency('USD');
        $billing->setExchangeRate('61.7520');
        $billing->setFreightChargesUsd('1082.00');
        $billing->setThcChargesUsd('1500.00');
        $billing->setTotalAmountUsd('2622.00');
        $billing->computeTotal();

        $reflection = new \ReflectionProperty($billing, 'id');
        $reflection->setValue($billing, 37);

        $context = $builder->buildBillingContext($billing);

        $this->assertSame('00037', $context['billing']['invoice_number']);
        $this->assertSame('UNPAID', $context['billing']['status']);
        $this->assertSame('1 USD = P61.7520', $context['billing']['exchange_rate_display']);
        $this->assertSame('MNF-2026-2010', $context['manifest']['number']);
        $this->assertSame('ABC Trading Corporation', $context['consignee']['name']);
        $this->assertSame('logistics@abctrading.ph', $context['consignee']['email']);
        $this->assertSame('June Dionelle Flores', $context['broker']['name']);
        $this->assertArrayNotHasKey('address', $context['broker']);
        $this->assertCount(2, $context['charges']['table']);
        $this->assertSame('Freight Charges', $context['charges']['table'][0][1]);
        $this->assertStringContainsString('Jaydee Dela Cruz', $context['generated']['by']);
    }

    public function testBuildBillingContextUsesConsigneeAccreditationAddress(): void
    {
        $broker = new Broker();
        $broker->setEmail('broker@example.com');
        $broker->setFullName('Michael Rodriguez');
        $broker->setRole(UserRole::BROKER);
        $broker->setStatus(AccountStatus::APPROVED);
        $broker->setPasswordHash('hash');

        $consignee = new Consignee();
        $consignee->setEmail('consignee@example.com');
        $consignee->setBusinessName('Global Imports Inc.');
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setStatus(AccountStatus::APPROVED);
        $consignee->setPasswordHash('hash');

        $manifest = new Manifest();
        $manifest->setManifestNumber('AMQ00147852');
        $manifest->setBlNumber('AMQ00147852');
        $manifest->setBroker($broker);
        $manifest->setConsignee($consignee);

        $generatedBy = new StaffUser();
        $generatedBy->setEmail('staff@example.com');
        $generatedBy->setRole(UserRole::ACCOUNTING);
        $generatedBy->setStatus(AccountStatus::APPROVED);
        $generatedBy->setPasswordHash('hash');
        $generatedBy->setFirstName('Jaydee');
        $generatedBy->setLastName('Dela Cruz');
        $generatedBy->setDepartment('Accounting');

        $submission = new \App\Entity\AccreditationSubmission();
        $submission->setApplicant($consignee);
        $submission->setSubmittedData([
            'business_address' => 'Unit 15A, Pacific Star Building, Sen. Gil Puyat Ave, Makati City 1226',
        ]);

        $repository = $this->createMock(\App\Repository\AccreditationSubmissionRepository::class);
        $repository->method('findByApplicantAndShippingLine')->willReturn($submission);
        $repository->method('findByApplicant')->willReturn([$submission]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')
            ->with(\App\Entity\AccreditationSubmission::class)
            ->willReturn($repository);

        $builder = new DocumentTemplateContextBuilder($entityManager);

        $billing = new Billing();
        $billing->setManifest($manifest);
        $billing->setGeneratedBy($generatedBy);
        $billing->setFreightCharges('1000.00');
        $billing->setThcCharges('500.00');
        $billing->computeTotal();

        $context = $builder->buildBillingContext($billing);

        $this->assertSame(
            'Unit 15A, Pacific Star Building, Sen. Gil Puyat Ave, Makati City 1226',
            $context['consignee']['address']
        );
        $this->assertSame('Global Imports Inc.', $context['consignee']['name']);
    }

    public function testBuildBillingContextFormatsSasStructuredAddress(): void
    {
        $consignee = new Consignee();
        $consignee->setEmail('floresjaydee5@gmail.com');
        $consignee->setBusinessName('Apex Logistics Solutions Inc');
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setStatus(AccountStatus::APPROVED);
        $consignee->setPasswordHash('hash');

        $manifest = new Manifest();
        $manifest->setManifestNumber('AMQ00147852');
        $manifest->setBlNumber('AMQ00147852');
        $manifest->setConsignee($consignee);

        $generatedBy = new StaffUser();
        $generatedBy->setEmail('staff@example.com');
        $generatedBy->setRole(UserRole::ACCOUNTING);
        $generatedBy->setStatus(AccountStatus::APPROVED);
        $generatedBy->setPasswordHash('hash');
        $generatedBy->setFirstName('Jaydee');
        $generatedBy->setLastName('Dela Cruz');
        $generatedBy->setDepartment('Accounting');

        $submission = new \App\Entity\AccreditationSubmission();
        $submission->setApplicant($consignee);
        $submission->setSubmittedData([
            'field_1781511486160' => [
                'region_id' => '1',
                'region_name' => 'National Capital Region (NCR)',
                'province_id' => '1',
                'province_name' => 'Metro Manila',
                'city_id' => '5',
                'city_name' => 'Makati',
                'barangay_id' => '1426',
                'barangay_name' => 'Pinagkaisahan',
                'street' => 'Unit 508E Star Bldg.',
            ],
        ]);

        $repository = $this->createMock(\App\Repository\AccreditationSubmissionRepository::class);
        $repository->method('findByApplicantAndShippingLine')->willReturn($submission);
        $repository->method('findByApplicant')->willReturn([$submission]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')
            ->with(\App\Entity\AccreditationSubmission::class)
            ->willReturn($repository);

        $builder = new DocumentTemplateContextBuilder($entityManager);

        $billing = new Billing();
        $billing->setManifest($manifest);
        $billing->setGeneratedBy($generatedBy);
        $billing->setFreightCharges('1000.00');
        $billing->setThcCharges('500.00');
        $billing->computeTotal();

        $context = $builder->buildBillingContext($billing);

        $this->assertSame(
            'Unit 508E Star Bldg., Pinagkaisahan, Makati, Metro Manila, National Capital Region (NCR)',
            $context['consignee']['address']
        );
    }
}
