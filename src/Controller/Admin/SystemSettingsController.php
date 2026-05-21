<?php

namespace App\Controller\Admin;

use App\Entity\ContainerSize;
use App\Entity\ContainerType;
use App\Entity\ShippingLine;
use App\Entity\Terminal;
use App\Repository\ContainerSizeRepository;
use App\Repository\ContainerTypeRepository;
use App\Repository\ShippingLineRepository;
use App\Repository\TerminalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

#[Route('/admin/system-settings')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class SystemSettingsController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        private EntityManagerInterface $entityManager,
        private ShippingLineRepository $shippingLineRepository,
        private TerminalRepository $terminalRepository,
        private ContainerTypeRepository $containerTypeRepository,
        private ContainerSizeRepository $containerSizeRepository
    ) {
        $this->rateLimiterConfigPath = $projectDir . '/config/packages/rate_limiter.yaml';
        $this->sessionConfigPath = $projectDir . '/config/session_config.json';
    }

    private string $rateLimiterConfigPath;
    private string $sessionConfigPath;

    #[Route('', name: 'admin_system_settings', methods: ['GET'])]
    public function index(): Response
    {
        $rateLimiters = $this->loadRateLimiters();
        $sessionConfig = $this->loadSessionConfig();
        $shippingLines = $this->shippingLineRepository->findAll();
        $terminals = $this->terminalRepository->findAll();
        $containerTypes = $this->containerTypeRepository->findAll();
        $containerSizes = $this->containerSizeRepository->findAll();
        
        return $this->render('admin/system_settings/index.html.twig', [
            'rateLimiters' => $rateLimiters,
            'sessionConfig' => $sessionConfig,
            'shippingLines' => $shippingLines,
            'terminals' => $terminals,
            'containerTypes' => $containerTypes,
            'containerSizes' => $containerSizes,
        ]);
    }

    #[Route('/rate-limiter/update', name: 'admin_system_settings_rate_limiter_update', methods: ['POST'])]
    public function updateRateLimiter(Request $request): Response
    {
        $limiterName = $request->request->get('limiter_name');
        $limit = (int) $request->request->get('limit');
        $interval = $request->request->get('interval');

        if (empty($limiterName) || $limit <= 0 || empty($interval)) {
            $this->addFlash('error', 'Invalid input. Please check all fields.');
            return $this->redirectToRoute('admin_system_settings');
        }

        try {
            $config = Yaml::parseFile($this->rateLimiterConfigPath);
            
            if (!isset($config['framework']['rate_limiter'][$limiterName])) {
                $this->addFlash('error', 'Rate limiter not found.');
                return $this->redirectToRoute('admin_system_settings');
            }

            // Update the configuration
            $config['framework']['rate_limiter'][$limiterName]['limit'] = $limit;
            $config['framework']['rate_limiter'][$limiterName]['interval'] = $interval;

            // Save the configuration
            $yaml = Yaml::dump($config, 4, 2);
            file_put_contents($this->rateLimiterConfigPath, $yaml);

            $this->addFlash('success', "Rate limiter '{$limiterName}' updated successfully. Run 'php bin/console cache:clear' to apply changes.");
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update configuration: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_system_settings');
    }

    #[Route('/session/update', name: 'admin_system_settings_session_update', methods: ['POST'])]
    public function updateSessionConfig(Request $request): Response
    {
        $desktopTimeout = (int) $request->request->get('desktop_timeout_minutes');
        $checkInterval = (int) $request->request->get('check_interval_seconds');
        $pwaPingInterval = (int) $request->request->get('pwa_ping_interval_minutes');

        if ($desktopTimeout < 1 || $desktopTimeout > 1440) {
            $this->addFlash('error', 'Desktop timeout must be between 1 and 1440 minutes.');
            return $this->redirectToRoute('admin_system_settings');
        }

        if ($checkInterval < 10 || $checkInterval > 300) {
            $this->addFlash('error', 'Check interval must be between 10 and 300 seconds.');
            return $this->redirectToRoute('admin_system_settings');
        }

        if ($pwaPingInterval < 1 || $pwaPingInterval > 60) {
            $this->addFlash('error', 'PWA ping interval must be between 1 and 60 minutes.');
            return $this->redirectToRoute('admin_system_settings');
        }

        try {
            $config = [
                'desktop_timeout_minutes' => $desktopTimeout,
                'check_interval_seconds' => $checkInterval,
                'pwa_ping_interval_minutes' => $pwaPingInterval
            ];

            $json = json_encode($config, JSON_PRETTY_PRINT);
            file_put_contents($this->sessionConfigPath, $json);

            $this->addFlash('success', 'Session configuration updated successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update session configuration: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_system_settings');
    }

    private function loadRateLimiters(): array
    {
        if (!file_exists($this->rateLimiterConfigPath)) {
            return [];
        }

        $config = Yaml::parseFile($this->rateLimiterConfigPath);
        return $config['framework']['rate_limiter'] ?? [];
    }

    private function loadSessionConfig(): array
    {
        if (!file_exists($this->sessionConfigPath)) {
            return [
                'desktop_timeout_minutes' => 30,
                'check_interval_seconds' => 60,
                'pwa_ping_interval_minutes' => 5
            ];
        }
        
        $json = file_get_contents($this->sessionConfigPath);
        return json_decode($json, true) ?? [];
    }
}
