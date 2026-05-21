<?php

namespace App\Tests\Service;

use App\Entity\Manifest;
use App\Entity\Payment;
use App\Entity\Billing;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\User;
use App\Entity\Enum\PaymentType;
use App\Service\ManifestNotificationService;
use App\Service\NotificationGateway;
use App\Service\InAppNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Integration test to verify ManifestNotificationService correctly routes
 * notifications through NotificationGateway
 */
class ManifestNotificationServiceIntegrationTest extends TestCase
{
    private ManifestNotificationService $service;
    private NotificationGateway $gateway;
    private EntityManagerInterface $entityManager;
    private InAppNotificationService $inAppService;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(NotificationGateway::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->inAppService = $this->createMock(InAppNotificationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ManifestNotificationService(
            $this->entityManager,
            $this->gateway,
            $this->inAppService,
            $this->logger
        );
    }

    public function testNotifyManifestAccessPaymentRequiredUsesGateway(): void
    {
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('MAN-001');
        $manifest->method('getBroker')->willReturn(null);
        $manifest->method('getConsignee')->willReturn(null);

        // Expect gateway to be called with correct parameters
        $this->gateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                $this->isType('array'),
                'Manifest Access Payment Required',
                $this->stringContains('MAN-001'),
                'manifest_payment_required',
                $this->callback(function ($metadata) {
                    return isset($metadata['manifest_id']) &&
                           isset($metadata['manifest_number']) &&
                           isset($metadata['amount']) &&
                           $metadata['amount'] === 500.00;
                })
            );

        $this->service->notifyManifestAccessPaymentRequired($manifest);
    }

    public function testNotifyConsigneeDeclaredUsesGateway(): void
    {
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('MAN-001');
        $manifest->method('getVesselName')->willReturn('MV Test Vessel');
        $manifest->method('getVoyageNumber')->willReturn('V001');
        $manifest->method('getBroker')->willReturn(null);
        $manifest->method('getConsignee')->willReturn(null);

        $this->gateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                $this->isType('array'),
                'You Have Been Assigned to a Manifest',
                $this->stringContains('MAN-001'),
                'manifest_consignee_declared',
                $this->callback(function ($metadata) {
                    return isset($metadata['manifest_id']) &&
                           isset($metadata['vessel_name']) &&
                           isset($metadata['voyage_number']);
                })
            );

        $this->service->notifyConsigneeDeclared($manifest);
    }

    public function testNotifyManifestAccessGrantedUsesGateway(): void
    {
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('MAN-001');
        $manifest->method('getBroker')->willReturn(null);
        $manifest->method('getConsignee')->willReturn(null);

        $this->gateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                $this->isType('array'),
                'Manifest Access Granted',
                $this->stringContains('MAN-001'),
                'manifest_access_granted',
                $this->callback(function ($metadata) {
                    return isset($metadata['manifest_id']) &&
                           isset($metadata['manifest_number']);
                })
            );

        $this->service->notifyManifestAccessGranted($manifest);
    }

    public function testNotifyNOAGeneratedUsesGateway(): void
    {
        $noaDocument = $this->createMock(\App\Entity\NOADocument::class);
        $noaDocument->method('getId')->willReturn(1);
        $noaDocument->method('getNoaNumber')->willReturn('NOA-001');

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('MAN-001');
        $manifest->method('getNoaDocument')->willReturn($noaDocument);
        $manifest->method('getBroker')->willReturn(null);
        $manifest->method('getConsignee')->willReturn(null);

        $this->gateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                $this->isType('array'),
                'Notice of Arrival Generated',
                $this->stringContains('MAN-001'),
                'noa_generated',
                $this->callback(function ($metadata) {
                    return isset($metadata['manifest_id']) &&
                           isset($metadata['noa_id']) &&
                           isset($metadata['noa_number']);
                })
            );

        $this->service->notifyNOAGenerated($manifest, '/path/to/noa.pdf');
    }

    public function testNotifyBillingGeneratedUsesGateway(): void
    {
        $billing = $this->createMock(Billing::class);
        $billing->method('getId')->willReturn(1);
        $billing->method('getTotalAmount')->willReturn(5000.00);
        $billing->method('getFreightCharges')->willReturn(3000.00);
        $billing->method('getThcCharges')->willReturn(2000.00);

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('MAN-001');
        $manifest->method('getBroker')->willReturn(null);
        $manifest->method('getConsignee')->willReturn(null);

        $this->gateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                $this->isType('array'),
                'Billing Document Generated',
                $this->stringContains('MAN-001'),
                'billing_generated',
                $this->callback(function ($metadata) {
                    return isset($metadata['manifest_id']) &&
                           isset($metadata['billing_id']) &&
                           isset($metadata['total_amount']) &&
                           $metadata['total_amount'] === 5000.00;
                })
            );

        $this->service->notifyBillingGenerated($manifest, $billing);
    }

    public function testNotifyPaymentRejectedUsesGateway(): void
    {
        $submitter = $this->createMock(User::class);
        $submitter->method('getId')->willReturn(1);
        $submitter->method('getEmail')->willReturn('submitter@example.com');

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('MAN-001');

        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getAmount')->willReturn(500.00);
        $payment->method('getSubmittedBy')->willReturn($submitter);
        $payment->method('getManifest')->willReturn($manifest);

        $this->gateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                [$submitter],
                'Payment Rejected',
                $this->stringContains('rejected'),
                'payment_rejected',
                $this->callback(function ($metadata) {
                    return isset($metadata['manifest_id']) &&
                           isset($metadata['payment_id']) &&
                           isset($metadata['reason']) &&
                           $metadata['reason'] === 'Invalid receipt';
                })
            );

        $this->service->notifyPaymentRejected($payment, 'Invalid receipt');
    }

    public function testNotifyEDOGeneratedUsesGateway(): void
    {
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('MAN-001');
        $manifest->method('getBroker')->willReturn(null);
        $manifest->method('getConsignee')->willReturn(null);

        $edo = $this->createMock(ElectronicDeliveryOrder::class);
        $edo->method('getId')->willReturn(1);
        $edo->method('getEdoNumber')->willReturn('EDO-001');
        $edo->method('getManifest')->willReturn($manifest);

        $this->gateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                $this->isType('array'),
                'Electronic Delivery Order Generated',
                $this->stringContains('EDO-001'),
                'edo_generated',
                $this->callback(function ($metadata) {
                    return isset($metadata['manifest_id']) &&
                           isset($metadata['edo_id']) &&
                           isset($metadata['edo_number']);
                })
            );

        $this->service->notifyEDOGenerated($edo);
    }
}

