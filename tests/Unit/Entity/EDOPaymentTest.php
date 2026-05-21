<?php

namespace App\Tests\Unit\Entity;

use App\Entity\EDOPayment;
use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\Enum\PaymentStatus;
use PHPUnit\Framework\TestCase;

class EDOPaymentTest extends TestCase
{
    private User $submitter;
    private User $validator;
    private Manifest $manifest;

    protected function setUp(): void
    {
        $this->submitter = $this->createMock(User::class);
        $this->submitter->method('getEmail')->willReturn('broker@example.com');
        
        $this->validator = $this->createMock(User::class);
        $this->validator->method('getEmail')->willReturn('admin@example.com');
        
        $this->manifest = $this->createMock(Manifest::class);
        $this->manifest->method('getManifestNumber')->willReturn('MAN-2024-001');
    }

    public function testEDOPaymentCreation(): void
    {
        $edoPayment = new EDOPayment();
        
        // Test default values set in constructor
        $this->assertEquals(PaymentStatus::PENDING_VALIDATION, $edoPayment->getStatus());
        $this->assertInstanceOf(\DateTimeInterface::class, $edoPayment->getCreatedAt());
        $this->assertNull($edoPayment->getValidatedBy());
        $this->assertNull($edoPayment->getValidatedAt());
        $this->assertNull($edoPayment->getRejectionReason());
    }

    public function testVerifyMethod(): void
    {
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->submitter);
        
        // Initial state should be PENDING_VALIDATION
        $this->assertEquals(PaymentStatus::PENDING_VALIDATION, $edoPayment->getStatus());
        
        // Call verify method
        $edoPayment->verify($this->validator);
        
        // Assert status changed to VERIFIED
        $this->assertEquals(PaymentStatus::VERIFIED, $edoPayment->getStatus());
        
        // Assert validatedBy is set
        $this->assertEquals($this->validator, $edoPayment->getValidatedBy());
        
        // Assert validatedAt is set to current datetime
        $this->assertInstanceOf(\DateTimeInterface::class, $edoPayment->getValidatedAt());
        $this->assertEqualsWithDelta(
            time(),
            $edoPayment->getValidatedAt()->getTimestamp(),
            2,
            'validatedAt should be set to current time'
        );
        
        // Assert rejectionReason is cleared
        $this->assertNull($edoPayment->getRejectionReason());
    }

    public function testVerifyMethodClearsRejectionReason(): void
    {
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->submitter);
        
        // First reject the payment
        $edoPayment->reject($this->validator, 'Invalid receipt');
        $this->assertEquals('Invalid receipt', $edoPayment->getRejectionReason());
        
        // Then verify it (simulating resubmission and approval)
        $edoPayment->verify($this->validator);
        
        // Assert rejectionReason is cleared
        $this->assertNull($edoPayment->getRejectionReason());
        $this->assertEquals(PaymentStatus::VERIFIED, $edoPayment->getStatus());
    }

    public function testRejectMethod(): void
    {
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->submitter);
        
        $rejectionReason = 'Receipt is not clear';
        
        // Initial state should be PENDING_VALIDATION
        $this->assertEquals(PaymentStatus::PENDING_VALIDATION, $edoPayment->getStatus());
        
        // Call reject method
        $edoPayment->reject($this->validator, $rejectionReason);
        
        // Assert status changed to REJECTED
        $this->assertEquals(PaymentStatus::REJECTED, $edoPayment->getStatus());
        
        // Assert validatedBy is set
        $this->assertEquals($this->validator, $edoPayment->getValidatedBy());
        
        // Assert validatedAt is set to current datetime
        $this->assertInstanceOf(\DateTimeInterface::class, $edoPayment->getValidatedAt());
        $this->assertEqualsWithDelta(
            time(),
            $edoPayment->getValidatedAt()->getTimestamp(),
            2,
            'validatedAt should be set to current time'
        );
        
        // Assert rejectionReason is set
        $this->assertEquals($rejectionReason, $edoPayment->getRejectionReason());
    }

    public function testRejectMethodWithDifferentReasons(): void
    {
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->submitter);
        
        $reasons = [
            'Receipt is not clear',
            'Amount does not match',
            'Invalid payment method',
            'Receipt is expired'
        ];
        
        foreach ($reasons as $reason) {
            $edoPayment->reject($this->validator, $reason);
            $this->assertEquals($reason, $edoPayment->getRejectionReason());
            $this->assertEquals(PaymentStatus::REJECTED, $edoPayment->getStatus());
        }
    }

    public function testGettersAndSetters(): void
    {
        $edoPayment = new EDOPayment();
        
        // Test manifest
        $edoPayment->setManifest($this->manifest);
        $this->assertEquals($this->manifest, $edoPayment->getManifest());
        
        // Test amount
        $edoPayment->setAmount(750.50);
        $this->assertEquals(750.50, $edoPayment->getAmount());
        
        // Test receiptFilePath
        $edoPayment->setReceiptFilePath('/uploads/receipts/test.pdf');
        $this->assertEquals('/uploads/receipts/test.pdf', $edoPayment->getReceiptFilePath());
        
        // Test submittedBy
        $edoPayment->setSubmittedBy($this->submitter);
        $this->assertEquals($this->submitter, $edoPayment->getSubmittedBy());
        
        // Test validatedBy
        $edoPayment->setValidatedBy($this->validator);
        $this->assertEquals($this->validator, $edoPayment->getValidatedBy());
        
        // Test validatedAt
        $validatedAt = new \DateTime('2024-01-15 10:30:00');
        $edoPayment->setValidatedAt($validatedAt);
        $this->assertEquals($validatedAt, $edoPayment->getValidatedAt());
        
        // Test rejectionReason
        $edoPayment->setRejectionReason('Test reason');
        $this->assertEquals('Test reason', $edoPayment->getRejectionReason());
        
        // Test status
        $edoPayment->setStatus(PaymentStatus::VERIFIED);
        $this->assertEquals(PaymentStatus::VERIFIED, $edoPayment->getStatus());
    }

    public function testStatusTransitionFromPendingToVerified(): void
    {
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->submitter);
        
        // Initial state
        $this->assertEquals(PaymentStatus::PENDING_VALIDATION, $edoPayment->getStatus());
        $this->assertNull($edoPayment->getValidatedBy());
        $this->assertNull($edoPayment->getValidatedAt());
        
        // Transition to VERIFIED
        $edoPayment->verify($this->validator);
        
        $this->assertEquals(PaymentStatus::VERIFIED, $edoPayment->getStatus());
        $this->assertNotNull($edoPayment->getValidatedBy());
        $this->assertNotNull($edoPayment->getValidatedAt());
    }

    public function testStatusTransitionFromPendingToRejected(): void
    {
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->submitter);
        
        // Initial state
        $this->assertEquals(PaymentStatus::PENDING_VALIDATION, $edoPayment->getStatus());
        $this->assertNull($edoPayment->getValidatedBy());
        $this->assertNull($edoPayment->getValidatedAt());
        $this->assertNull($edoPayment->getRejectionReason());
        
        // Transition to REJECTED
        $edoPayment->reject($this->validator, 'Invalid receipt');
        
        $this->assertEquals(PaymentStatus::REJECTED, $edoPayment->getStatus());
        $this->assertNotNull($edoPayment->getValidatedBy());
        $this->assertNotNull($edoPayment->getValidatedAt());
        $this->assertEquals('Invalid receipt', $edoPayment->getRejectionReason());
    }

    public function testMultipleValidators(): void
    {
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->submitter);
        
        $validator1 = $this->createMock(User::class);
        $validator1->method('getEmail')->willReturn('admin1@example.com');
        
        $validator2 = $this->createMock(User::class);
        $validator2->method('getEmail')->willReturn('admin2@example.com');
        
        // First validator rejects
        $edoPayment->reject($validator1, 'Needs clarification');
        $this->assertEquals($validator1, $edoPayment->getValidatedBy());
        
        // Second validator verifies (after resubmission)
        $edoPayment->verify($validator2);
        $this->assertEquals($validator2, $edoPayment->getValidatedBy());
        $this->assertEquals(PaymentStatus::VERIFIED, $edoPayment->getStatus());
    }

    public function testAmountPrecision(): void
    {
        $edoPayment = new EDOPayment();
        
        // Test various amount formats
        $amounts = [
            100.00,
            100.50,
            999.99,
            1234.56,
            0.01
        ];
        
        foreach ($amounts as $amount) {
            $edoPayment->setAmount($amount);
            $this->assertEquals($amount, $edoPayment->getAmount());
        }
    }

    public function testCreatedAtIsImmutable(): void
    {
        $edoPayment = new EDOPayment();
        $originalCreatedAt = $edoPayment->getCreatedAt();
        
        // Wait a moment
        usleep(10000); // 10ms
        
        // Create another instance
        $edoPayment2 = new EDOPayment();
        
        // Original createdAt should not change
        $this->assertEquals($originalCreatedAt, $edoPayment->getCreatedAt());
        
        // New instance should have different createdAt
        $this->assertNotEquals($originalCreatedAt, $edoPayment2->getCreatedAt());
    }

    public function testValidatedAtTimestamp(): void
    {
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($this->manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setReceiptFilePath('/uploads/receipts/receipt123.pdf');
        $edoPayment->setSubmittedBy($this->submitter);
        
        $beforeVerify = new \DateTime();
        $edoPayment->verify($this->validator);
        $afterVerify = new \DateTime();
        
        $validatedAt = $edoPayment->getValidatedAt();
        
        // Ensure validatedAt is between before and after timestamps
        $this->assertGreaterThanOrEqual(
            $beforeVerify->getTimestamp(),
            $validatedAt->getTimestamp()
        );
        $this->assertLessThanOrEqual(
            $afterVerify->getTimestamp(),
            $validatedAt->getTimestamp()
        );
    }
}
