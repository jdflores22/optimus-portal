<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

#[Route('/api/exchange-rate', name: 'api_exchange_rate_')]
class ExchangeRateController extends AbstractController
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    #[Route('/usd-php', name: 'usd_php', methods: ['GET'])]
    public function getUsdToPhp(): JsonResponse
    {
        try {
            // Use Frankfurter.app (European Central Bank data - reliable and free)
            $response = $this->httpClient->request('GET', 'https://api.frankfurter.app/latest?from=USD&to=PHP');
            $data = $response->toArray();

            if (isset($data['rates']['PHP'])) {
                return new JsonResponse([
                    'success' => true,
                    'rate' => $data['rates']['PHP'],
                    'date' => $data['date'] ?? date('Y-m-d'),
                    'source' => 'European Central Bank (Frankfurter)'
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Frankfurter API failed: ' . $e->getMessage());
        }

        // Fallback: Return current market rate
        return new JsonResponse([
            'success' => false,
            'rate' => 57.50,
            'date' => date('Y-m-d'),
            'source' => 'fallback',
            'message' => 'Unable to fetch real-time rates from European Central Bank. Using fallback rate.'
        ], 200);
    }
}
