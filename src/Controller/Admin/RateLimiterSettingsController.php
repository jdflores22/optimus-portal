<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

#[Route('/admin/rate-limiter-settings')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class RateLimiterSettingsController extends AbstractController
{
    private string $configPath;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir
    ) {
        $this->configPath = $projectDir . '/config/packages/rate_limiter.yaml';
    }

    #[Route('', name: 'admin_rate_limiter_settings', methods: ['GET'])]
    public function index(): Response
    {
        $config = $this->loadConfig();
        
        return $this->render('admin/rate_limiter_settings/index.html.twig', [
            'limiters' => $config['framework']['rate_limiter'] ?? []
        ]);
    }

    #[Route('/update', name: 'admin_rate_limiter_settings_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        $limiterName = $request->request->get('limiter_name');
        $limit = (int) $request->request->get('limit');
        $interval = $request->request->get('interval');

        if (empty($limiterName) || $limit <= 0 || empty($interval)) {
            $this->addFlash('error', 'Invalid input. Please check all fields.');
            return $this->redirectToRoute('admin_rate_limiter_settings');
        }

        try {
            $config = $this->loadConfig();
            
            if (!isset($config['framework']['rate_limiter'][$limiterName])) {
                $this->addFlash('error', 'Rate limiter not found.');
                return $this->redirectToRoute('admin_rate_limiter_settings');
            }

            // Update the configuration
            $config['framework']['rate_limiter'][$limiterName]['limit'] = $limit;
            $config['framework']['rate_limiter'][$limiterName]['interval'] = $interval;

            // Save the configuration
            $this->saveConfig($config);

            $this->addFlash('success', "Rate limiter '{$limiterName}' updated successfully. Changes will take effect after cache clear.");
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update configuration: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_rate_limiter_settings');
    }

    private function loadConfig(): array
    {
        if (!file_exists($this->configPath)) {
            throw new \RuntimeException('Rate limiter configuration file not found.');
        }

        return Yaml::parseFile($this->configPath);
    }

    private function saveConfig(array $config): void
    {
        $yaml = Yaml::dump($config, 4, 2);
        file_put_contents($this->configPath, $yaml);
    }
}
