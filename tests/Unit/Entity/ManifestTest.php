<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Manifest;
use App\Entity\EDOPayment;
use App\Entity\User;
use App\Entity\Enum\WorkflowState;
use PHPUnit\Framework\TestCase;

class ManifestTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = $this->createMock(User::class);
        $this->user->method('getEmail')->willReturn('user@example.com');
    }

    public function testManifestCreation(): void
    {
        $manifest = new Manifest();
        
        // Test default values set in constructor
        $this->assertEquals(WorkflowState::MANIFEST_UPLOADED, $manifest->getWorkflowState());
        $this->assertInstanceOf(\DateTimeInterface::class, $manifest->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $manifest->getUpdatedAt());
        
        // Test edoPayments collection is initialized
        $this->assertCount(0, $manifest->getEdoPayments());
    }

    public function testGetEdoPayments(): void
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('MAN-2024-001');
        $manifest->setCreatedBy($this->user);
        
        // Initially empty
        $edoPayments = $manifest->getEdoPayments();
        $this->assertCount(0, $edoPayments);
        
        // Add an EDO payment
        $edoPayment = new EDOPayment();
        $manifest->addEdoPayment($edoPayment);
        
        // Should now contain one payment
        $edoPayments = $manifest->getEdoPayments();
        $this->assertCount(1, $edoPayments);
        $this->assertTrue($edoPayments->contains($edoPayment));
    }

    public function testAddEdoPayment(): void
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('MAN-2024-001');
        $manifest->setCreatedBy($this->user);
        
        $edoPayment = new EDOPayment();
        
        // Add payment
        $result = $manifest->addEdoPayment($edoPayment);
        
        // Should return self for fluent interface
        $this->assertSame($manifest, $result);
        
        // Payment should be in collection
        $this->assertTrue($manifest->getEdoPayments()->contains($edoPayment));
        $this->assertCount(1, $manifest->getEdoPayments());
        
        // Payment should have manifest set
        $this->assertSame($manifest, $edoPayment->getManifest());
    }

    public function testAddEdoPaymentPreventsDoubles(): void
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('MAN-2024-001');
        $manifest->setCreatedBy($this->user);
        
        $edoPayment = new EDOPayment();
        
        // Add same payment twice
        $manifest->addEdoPayment($edoPayment);
        $manifest->addEdoPayment($edoPayment);
        
        // Should only be added once
        $this->assertCount(1, $manifest->getEdoPayments());
    }

    public function testAddMultipleEdoPayments(): void
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('MAN-2024-001');
        $manifest->setCreatedBy($this->user);
        
        $edoPayment1 = new EDOPayment();
        $edoPayment2 = new EDOPayment();
        $edoPayment3 = new EDOPayment();
        
        $manifest->addEdoPayment($edoPayment1);
        $manifest->addEdoPayment($edoPayment2);
        $manifest->addEdoPayment($edoPayment3);
        
        $this->assertCount(3, $manifest->getEdoPayments());
        $this->assertTrue($manifest->getEdoPayments()->contains($edoPayment1));
        $this->assertTrue($manifest->getEdoPayments()->contains($edoPayment2));
        $this->assertTrue($manifest->getEdoPayments()->contains($edoPayment3));
    }

    public function testRemoveEdoPayment(): void
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('MAN-2024-001');
        $manifest->setCreatedBy($this->user);
        
        $edoPayment = new EDOPayment();
        $manifest->addEdoPayment($edoPayment);
        
        // Verify payment is added
        $this->assertCount(1, $manifest->getEdoPayments());
        
        // Remove payment
        $result = $manifest->removeEdoPayment($edoPayment);
        
        // Should return self for fluent interface
        $this->assertSame($manifest, $result);
        
        // Payment should be removed from collection
        $this->assertCount(0, $manifest->getEdoPayments());
        $this->assertFalse($manifest->getEdoPayments()->contains($edoPayment));
    }

    public function testRemoveEdoPaymentNotInCollection(): void
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('MAN-2024-001');
        $manifest->setCreatedBy($this->user);
        
        $edoPayment1 = new EDOPayment();
        $edoPayment2 = new EDOPayment();
        
        $manifest->addEdoPayment($edoPayment1);
        
        // Try to remove payment that was never added
        $manifest->removeEdoPayment($edoPayment2);
        
        // Should still have the first payment
        $this->assertCount(1, $manifest->getEdoPayments());
        $this->assertTrue($manifest->getEdoPayments()->contains($edoPayment1));
    }

    public function testRemoveEdoPaymentFromMultiple(): void
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('MAN-2024-001');
        $manifest->setCreatedBy($this->user);
        
        $edoPayment1 = new EDOPayment();
        $edoPayment2 = new EDOPayment();
        $edoPayment3 = new EDOPayment();
        
        $manifest->addEdoPayment($edoPayment1);
        $manifest->addEdoPayment($edoPayment2);
        $manifest->addEdoPayment($edoPayment3);
        
        // Remove middle payment
        $manifest->removeEdoPayment($edoPayment2);
        
        $this->assertCount(2, $manifest->getEdoPayments());
        $this->assertTrue($manifest->getEdoPayments()->contains($edoPayment1));
        $this->assertFalse($manifest->getEdoPayments()->contains($edoPayment2));
        $this->assertTrue($manifest->getEdoPayments()->contains($edoPayment3));
    }

    public function testGetManifestAccessPaymentReturnsFirstPayment(): void
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('MAN-2024-001');
        $manifest->setCreatedBy($this->user);
        
        $edoPayment = new EDOPayment();
        $manifest->addEdoPayment($edoPayment);
        
        $result = $manifest->getManifestAccessPayment();
        
        $this->assertSame($edoPayment, $result);
    }

    public function testGetManifestAccessPaymentReturnsNullWhenEmpty(): void
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('MAN-2024-001');
        $manifest->setCreatedBy($this->user);
        
        $result = $manifest->getManifestAccessPayment();
        
        $this->assertNull($result);
    }

    public function testGetManifestAccessPaymentReturnsFirstWhenMultiple(): void
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('MAN-2024-001');
        $manifest->setCreatedBy($this->user);
        
        $edoPayment1 = new EDOPayment();
        $edoPayment2 = new EDOPayment();
        $edoPayment3 = new EDOPayment();
        
        $manifest->addEdoPayment($edoPayment1);
        $manifest->addEdoPayment($edoPayment2);
        $manifest->addEdoPayment($edoPayment3);
        
        $result = $manifest->getManifestAccessPayment();
        
        // Should return the first payment
        $this->assertSame($edoPayment1, $result);
    }

    public function testGetManifestAccessPaymentAfterRemoval(): void
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('MAN-2024-001');
        $manifest->setCreatedBy($this->user);
        
        $edoPayment1 = new EDOPayment();
        $edoPayment2 = new EDOPayment();
        
        $manifest->addEdoPayment($edoPayment1);
        $manifest->addEdoPayment($edoPayment2);
        
        // Remove first payment
        $manifest->removeEdoPayment($edoPayment1);
        
        $result = $manifest->getManifestAccessPayment();
        
        // Should now return the second payment
        $this->assertSame($edoPayment2, $result);
    }

    public function testEdoPaymentBidirectionalRelationship(): void
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('MAN-2024-001');
        $manifest->setCreatedBy($this->user);
        
        $edoPayment = new EDOPayment();
        
        // Add payment to manifest
        $manifest->addEdoPayment($edoPayment);
        
        // Verify bidirectional relationship
        $this->assertSame($manifest, $edoPayment->getManifest());
        $this->assertTrue($manifest->getEdoPayments()->contains($edoPayment));
        
        // Remove payment from manifest
        $manifest->removeEdoPayment($edoPayment);
        
        // Verify payment is removed from collection
        $this->assertFalse($manifest->getEdoPayments()->contains($edoPayment));
    }

    public function testFluentInterfaceChaining(): void
    {
        $manifest = new Manifest();
        $edoPayment1 = new EDOPayment();
        $edoPayment2 = new EDOPayment();
        
        // Test method chaining
        $result = $manifest
            ->setManifestNumber('MAN-2024-001')
            ->setCreatedBy($this->user)
            ->addEdoPayment($edoPayment1)
            ->addEdoPayment($edoPayment2);
        
        $this->assertSame($manifest, $result);
        $this->assertCount(2, $manifest->getEdoPayments());
    }
}
