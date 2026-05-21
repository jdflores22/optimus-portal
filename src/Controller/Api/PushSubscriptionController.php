<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\PushNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/push')]
class PushSubscriptionController extends AbstractController
{
    public function __construct(
        private readonly PushNotificationService $pushNotificationService,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route('/subscribe', name: 'api_push_subscribe', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function subscribe(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            // Validate required fields
            if (!isset($data['endpoint']) || !isset($data['keys']['p256dh']) || !isset($data['keys']['auth'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Missing required subscription data. Required: endpoint, keys.p256dh, keys.auth'
                ], Response::HTTP_BAD_REQUEST);
            }

            /** @var User $user */
            $user = $this->getUser();

            $subscription = $this->pushNotificationService->registerSubscription($user, $data);

            $this->logger->info('Push subscription registered', [
                'user_id' => $user->getId(),
                'subscription_id' => $subscription->getId()
            ]);

            return $this->json([
                'success' => true,
                'subscription_id' => $subscription->getId(),
                'message' => 'Push subscription registered successfully'
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            $this->logger->error('Failed to register push subscription', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Failed to register push subscription'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/unsubscribe/{id}', name: 'api_push_unsubscribe', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function unsubscribe(int $id): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();

            // Verify subscription belongs to authenticated user
            $subscription = $this->pushNotificationService->getSubscriptionById($id);

            if (!$subscription) {
                return $this->json([
                    'success' => false,
                    'error' => 'Subscription not found'
                ], Response::HTTP_NOT_FOUND);
            }

            if ($subscription->getUser()->getId() !== $user->getId()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Unauthorized to delete this subscription'
                ], Response::HTTP_FORBIDDEN);
            }

            $this->pushNotificationService->removeSubscription($id);

            $this->logger->info('Push subscription removed', [
                'user_id' => $user->getId(),
                'subscription_id' => $id
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Push subscription removed successfully'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to remove push subscription', [
                'subscription_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Failed to remove push subscription'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/subscriptions', name: 'api_push_subscriptions', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function listSubscriptions(): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();

            $subscriptions = $this->pushNotificationService->getActiveSubscriptionsByUser($user);

            $subscriptionData = array_map(function ($subscription) {
                return [
                    'id' => $subscription->getId(),
                    'created_at' => $subscription->getCreatedAt()->format('Y-m-d H:i:s'),
                    'last_used_at' => $subscription->getLastUsedAt()?->format('Y-m-d H:i:s'),
                    'user_agent' => $subscription->getUserAgent()
                ];
            }, $subscriptions);

            return $this->json([
                'success' => true,
                'subscriptions' => $subscriptionData,
                'count' => count($subscriptionData)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to list push subscriptions', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Failed to retrieve subscriptions'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/vapid-public-key', name: 'api_push_vapid_key', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function getVapidPublicKey(): JsonResponse
    {
        try {
            $publicKey = $this->pushNotificationService->getVapidPublicKey();

            return $this->json([
                'success' => true,
                'publicKey' => $publicKey
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve VAPID public key', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Failed to retrieve VAPID public key'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
