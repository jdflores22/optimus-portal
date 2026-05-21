<?php

namespace App\Controller;

use App\Entity\NotificationPreferences;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings/notifications')]
#[IsGranted('ROLE_USER')]
class NotificationPreferencesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly \App\Service\PushNotificationService $pushNotificationService
    ) {
    }

    #[Route('', name: 'notification_preferences', methods: ['GET'])]
    public function getPreferences(): Response
    {
        // Redirect to account settings page with notifications tab
        return $this->redirectToRoute('account_settings', ['tab' => 'notifications']);
    }

    #[Route('', name: 'notification_preferences_update', methods: ['POST'])]
    public function updatePreferences(Request $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();

            // Get or create preferences
            $preferences = $this->getOrCreatePreferences($user);

            // Track if DND was previously enabled
            $wasDndEnabled = $preferences->isDoNotDisturbEnabled();

            // Update enabled notification types
            $enabledTypes = $request->request->all('enabled_types') ?? [];
            $validTypes = array_keys($this->getNotificationTypes());
            $filteredTypes = array_filter($enabledTypes, fn($type) => in_array($type, $validTypes));
            $preferences->setEnabledTypes($filteredTypes);

            // Update Do Not Disturb settings
            $dndEnabled = $request->request->getBoolean('dnd_enabled', false);
            $preferences->setDoNotDisturbEnabled($dndEnabled);

            if ($dndEnabled) {
                $startTime = $request->request->get('dnd_start');
                $endTime = $request->request->get('dnd_end');

                if ($startTime) {
                    $preferences->setDoNotDisturbStart(new \DateTime($startTime));
                }
                if ($endTime) {
                    $preferences->setDoNotDisturbEnd(new \DateTime($endTime));
                }
            } else {
                $preferences->setDoNotDisturbStart(null);
                $preferences->setDoNotDisturbEnd(null);
            }

            $this->entityManager->flush();

            // If DND was disabled, deliver queued notifications
            $queuedCount = 0;
            if ($wasDndEnabled && !$dndEnabled) {
                $queuedCount = $this->pushNotificationService->deliverQueuedNotifications($user);
                $this->logger->info('Delivered queued notifications after DND disabled', [
                    'user_id' => $user->getId(),
                    'count' => $queuedCount,
                ]);
            }

            $this->logger->info('Notification preferences updated', [
                'user_id' => $user->getId(),
                'enabled_types' => $filteredTypes,
                'dnd_enabled' => $dndEnabled,
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Notification preferences updated successfully',
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to update notification preferences', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Failed to update notification preferences',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/device/{id}', name: 'notification_preferences_remove_device', methods: ['DELETE'])]
    public function removeDevice(int $id): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();

            $subscription = $this->entityManager
                ->getRepository(\App\Entity\PushSubscription::class)
                ->find($id);

            if (!$subscription) {
                return $this->json([
                    'success' => false,
                    'error' => 'Device not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Verify subscription belongs to authenticated user
            if ($subscription->getUser()->getId() !== $user->getId()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Unauthorized to remove this device',
                ], Response::HTTP_FORBIDDEN);
            }

            $this->entityManager->remove($subscription);
            $this->entityManager->flush();

            $this->logger->info('Push subscription removed from settings', [
                'user_id' => $user->getId(),
                'subscription_id' => $id,
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Device removed successfully',
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to remove device', [
                'subscription_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Failed to remove device',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function getOrCreatePreferences(User $user): NotificationPreferences
    {
        $preferences = $this->entityManager
            ->getRepository(NotificationPreferences::class)
            ->findOneBy(['user' => $user]);

        if (!$preferences) {
            $preferences = new NotificationPreferences();
            $preferences->setUser($user);
            $this->entityManager->persist($preferences);
            $this->entityManager->flush();
        }

        return $preferences;
    }

    private function getNotificationTypes(): array
    {
        return [
            'manifest_payment_required' => 'Manifest Payment Required',
            'manifest_consignee_declared' => 'Manifest Consignee Declared',
            'manifest_access_granted' => 'Manifest Access Granted',
            'noa_generated' => 'NOA Generated',
            'billing_generated' => 'Billing Generated',
            'payment_rejected' => 'Payment Rejected',
            'payment_submitted' => 'Payment Submitted',
            'payment_approved' => 'Payment Approved',
            'payment_validated' => 'Payment Validated',
            'bl_uploaded' => 'Bill of Lading Uploaded',
            'edo_generated' => 'EDO Generated',
        ];
    }
}
