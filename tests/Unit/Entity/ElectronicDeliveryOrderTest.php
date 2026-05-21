<?php

namespace App\Tests\Unit\Entity;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\EDOPayment;
use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\Enum\EDOStatus;
use App\Entity\Enum\PaymentStatus;
use PHPUnit\Framework\TestCase;

class ElectronicDeliveryOrderTest extends TestCase
{
    private Manifest $manifest;
    private User $user;

    protected function setUp(): void
    {
        $this->manifest = $this->createMock(Manifest::class);
        $this->manifest->method('getManifestNumber')->willReturn('MAN-2024-001');
        
        $this->user = $this->createMock(User::class);
        $this->user->method('getEmail')->willReturn('user@example.com');
    }

    public function testElectronicDeliveryOrderCreation(): void
    {
        $edo = new ElectronicDeliveryOrder();
        
        // Test default values set in constructor
        $this->assertEquals(EDOStatus::PENDING_RELEASE, $edo->getStatus());
        $this->assertInstanceOf(\DateTimeInterface::class, $edo->getGeneratedAt());
        $this->assertNull($edo->getEdoPayment());
        $this->assertNull($edo->getReleasedBy());
        $this->assertNull($edo->getReleasedAt());
        $this->assertNull($edo->getRejectionReason());
    }

    public function testGetEdoPayment(): void
    {
        $edo = new ElectronicDeliveryOrder();
        
        // Initially should be null
        $this->assertNull($edo->getEdoPayment());
        
        // Create and set EDOPayment
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->user);
        
        $edo->setEdoPayment($edoPayment);
        
        // Should return the EDOPayment
        $this->assertSame($edoPayment, $edo->getEdoPayment());
        $this->assertInstanceOf(EDOPayment::class, $edo->getEdoPayment());
    }

    public function testSetEdoPayment(): void
    {
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');
        $edo->setManifest($this->manifest);
        $edo->setPdfPath('/uploads/edos/edo123.pdf');
        
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->user);
        
        // Set the payment
        $result = $edo->setEdoPayment($edoPayment);
        
        // Should return self for fluent interface
        $this->assertSame($edo, $result);
        
        // Payment should be set
        $this->assertSame($edoPayment, $edo->getEdoPayment());
    }

    public function testSetEdoPaymentWithNull(): void
    {
        $edo = new ElectronicDeliveryOrder();
        
        // Set a payment first
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->user);
        
        $edo->setEdoPayment($edoPayment);
        $this->assertNotNull($edo->getEdoPayment());
        
        // Set to null
        $edo->setEdoPayment(null);
        
        // Should be null
        $this->assertNull($edo->getEdoPayment());
    }

    public function testEdoPaymentRelationship(): void
    {
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');
        $edo->setManifest($this->manifest);
        $edo->setPdfPath('/uploads/edos/edo123.pdf');
        
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->user);
        
        // Establish relationship
        $edo->setEdoPayment($edoPayment);
        
        // Verify relationship
        $this->assertSame($edoPayment, $edo->getEdoPayment());
        $this->assertSame($this->manifest, $edoPayment->getManifest());
    }

    public function testEdoPaymentWithVerifiedStatus(): void
    {
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');
        $edo->setManifest($this->manifest);
        $edo->setPdfPath('/uploads/edos/edo123.pdf');
        
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->user);
        
        $validator = $this->createMock(User::class);
        $validator->method('getEmail')->willReturn('admin@example.com');
        
        // Verify the payment
        $edoPayment->verify($validator);
        
        // Set payment to EDO
        $edo->setEdoPayment($edoPayment);
        
        // Verify payment status
        $this->assertEquals(PaymentStatus::VERIFIED, $edo->getEdoPayment()->getStatus());
    }

    public function testEdoPaymentWithRejectedStatus(): void
    {
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');
        $edo->setManifest($this->manifest);
        $edo->setPdfPath('/uploads/edos/edo123.pdf');
        
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->user);
        
        $validator = $this->createMock(User::class);
        $validator->method('getEmail')->willReturn('admin@example.com');
        
        // Reject the payment
        $edoPayment->reject($validator, 'Invalid receipt');
        
        // Set payment to EDO
        $edo->setEdoPayment($edoPayment);
        
        // Verify payment status
        $this->assertEquals(PaymentStatus::REJECTED, $edo->getEdoPayment()->getStatus());
        $this->assertEquals('Invalid receipt', $edo->getEdoPayment()->getRejectionReason());
    }

    public function testEdoPaymentWithPendingStatus(): void
    {
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');
        $edo->setManifest($this->manifest);
        $edo->setPdfPath('/uploads/edos/edo123.pdf');
        
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->user);
        
        // Set payment to EDO (payment is in PENDING_VALIDATION status by default)
        $edo->setEdoPayment($edoPayment);
        
        // Verify payment status
        $this->assertEquals(PaymentStatus::PENDING_VALIDATION, $edo->getEdoPayment()->getStatus());
    }

    public function testReplaceEdoPayment(): void
    {
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');
        $edo->setManifest($this->manifest);
        $edo->setPdfPath('/uploads/edos/edo123.pdf');
        
        // First payment
        $edoPayment1 = new EDOPayment();
        $edoPayment1->setManifest($this->manifest);
        $edoPayment1->setAmount(500.00);
        $edoPayment1->setReceiptFilePath('/uploads/receipts/receipt1.pdf');
        $edoPayment1->setSubmittedBy($this->user);
        
        $edo->setEdoPayment($edoPayment1);
        $this->assertSame($edoPayment1, $edo->getEdoPayment());
        
        // Second payment (replacement)
        $edoPayment2 = new EDOPayment();
        $edoPayment2->setManifest($this->manifest);
        $edoPayment2->setAmount(750.00);
        $edoPayment2->setReceiptFilePath('/uploads/receipts/receipt2.pdf');
        $edoPayment2->setSubmittedBy($this->user);
        
        $edo->setEdoPayment($edoPayment2);
        
        // Should now reference the second payment
        $this->assertSame($edoPayment2, $edo->getEdoPayment());
        $this->assertNotSame($edoPayment1, $edo->getEdoPayment());
    }

    public function testEdoPaymentAmountRetrieval(): void
    {
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');
        $edo->setManifest($this->manifest);
        $edo->setPdfPath('/uploads/edos/edo123.pdf');
        
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(1250.75);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->user);
        
        $edo->setEdoPayment($edoPayment);
        
        // Verify we can access payment amount through EDO
        $this->assertEquals(1250.75, $edo->getEdoPayment()->getAmount());
    }

    public function testEdoPaymentReceiptPathRetrieval(): void
    {
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');
        $edo->setManifest($this->manifest);
        $edo->setPdfPath('/uploads/edos/edo123.pdf');
        
        $receiptPath = '/uploads/receipts/receipt_abc123.pdf';
        
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath($receiptPath);
        $edoPayment->setSubmittedBy($this->user);
        
        $edo->setEdoPayment($edoPayment);
        
        // Verify we can access receipt path through EDO
        $this->assertEquals($receiptPath, $edo->getEdoPayment()->getReceiptFilePath());
    }

    public function testEdoPaymentSubmitterRetrieval(): void
    {
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');
        $edo->setManifest($this->manifest);
        $edo->setPdfPath('/uploads/edos/edo123.pdf');
        
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->user);
        
        $edo->setEdoPayment($edoPayment);
        
        // Verify we can access submitter through EDO
        $this->assertSame($this->user, $edo->getEdoPayment()->getSubmittedBy());
    }

    public function testFluentInterfaceWithEdoPayment(): void
    {
        $edo = new ElectronicDeliveryOrder();
        
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->user);
        
        // Test method chaining
        $result = $edo
            ->setEdoNumber('EDO-2024-001')
            ->setManifest($this->manifest)
            ->setPdfPath('/uploads/edos/edo123.pdf')
            ->setEdoPayment($edoPayment)
            ->setStatus(EDOStatus::RELEASED);
        
        $this->assertSame($edo, $result);
        $this->assertSame($edoPayment, $edo->getEdoPayment());
    }

    public function testEdoWithoutPayment(): void
    {
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');
        $edo->setManifest($this->manifest);
        $edo->setPdfPath('/uploads/edos/edo123.pdf');
        
        // EDO can exist without payment (nullable relationship)
        $this->assertNull($edo->getEdoPayment());
        
        // EDO should still be functional
        $this->assertEquals('EDO-2024-001', $edo->getEdoNumber());
        $this->assertEquals(EDOStatus::PENDING_RELEASE, $edo->getStatus());
    }

    public function testEdoPaymentManifestConsistency(): void
    {
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');
        $edo->setManifest($this->manifest);
        $edo->setPdfPath('/uploads/edos/edo123.pdf');
        
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->user);
        
        $edo->setEdoPayment($edoPayment);
        
        // Both EDO and EDOPayment should reference the same manifest
        $this->assertSame($edo->getManifest(), $edo->getEdoPayment()->getManifest());
    }

    public function testMultipleEdosWithDifferentPayments(): void
    {
        $manifest1 = $this->createMock(Manifest::class);
        $manifest1->method('getManifestNumber')->willReturn('MAN-2024-001');
        
        $manifest2 = $this->createMock(Manifest::class);
        $manifest2->method('getManifestNumber')->willReturn('MAN-2024-002');
        
        // First EDO with payment
        $edo1 = new ElectronicDeliveryOrder();
        $edo1->setEdoNumber('EDO-2024-001');
        $edo1->setManifest($manifest1);
        $edo1->setPdfPath('/uploads/edos/edo1.pdf');
        
        $payment1 = new EDOPayment();
        $payment1->setManifest($manifest1);
        $payment1->setAmount(500.00);
        $payment1->setReceiptFilePath('/uploads/receipts/receipt1.pdf');
        $payment1->setSubmittedBy($this->user);
        
        $edo1->setEdoPayment($payment1);
        
        // Second EDO with different payment
        $edo2 = new ElectronicDeliveryOrder();
        $edo2->setEdoNumber('EDO-2024-002');
        $edo2->setManifest($manifest2);
        $edo2->setPdfPath('/uploads/edos/edo2.pdf');
        
        $payment2 = new EDOPayment();
        $payment2->setManifest($manifest2);
        $payment2->setAmount(750.00);
        $payment2->setReceiptFilePath('/uploads/receipts/receipt2.pdf');
        $payment2->setSubmittedBy($this->user);
        
        $edo2->setEdoPayment($payment2);
        
        // Verify each EDO has its own payment
        $this->assertNotSame($edo1->getEdoPayment(), $edo2->getEdoPayment());
        $this->assertEquals(500.00, $edo1->getEdoPayment()->getAmount());
        $this->assertEquals(750.00, $edo2->getEdoPayment()->getAmount());
    }

    public function testEdoPaymentCreatedAtTimestamp(): void
    {
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');
        $edo->setManifest($this->manifest);
        $edo->setPdfPath('/uploads/edos/edo123.pdf');
        
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->user);
        
        $edo->setEdoPayment($edoPayment);
        
        // Verify payment has createdAt timestamp
        $this->assertInstanceOf(\DateTimeInterface::class, $edo->getEdoPayment()->getCreatedAt());
        
        // Verify createdAt is recent (within last 5 seconds)
        $this->assertEqualsWithDelta(
            time(),
            $edo->getEdoPayment()->getCreatedAt()->getTimestamp(),
            5,
            'Payment createdAt should be recent'
        );
    }

    public function testEdoStatusIndependentOfPaymentStatus(): void
    {
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');
        $edo->setManifest($this->manifest);
        $edo->setPdfPath('/uploads/edos/edo123.pdf');
        
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->user);
        
        $edo->setEdoPayment($edoPayment);
        
        // EDO status and payment status are independent
        $this->assertEquals(EDOStatus::PENDING_RELEASE, $edo->getStatus());
        $this->assertEquals(PaymentStatus::PENDING_VALIDATION, $edo->getEdoPayment()->getStatus());
        
        // Change EDO status
        $edo->setStatus(EDOStatus::RELEASED);
        
        // Payment status should remain unchanged
        $this->assertEquals(EDOStatus::RELEASED, $edo->getStatus());
        $this->assertEquals(PaymentStatus::PENDING_VALIDATION, $edo->getEdoPayment()->getStatus());
    }
}
