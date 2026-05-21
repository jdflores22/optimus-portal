<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Avatar\ConfigurationValidatorService;
use App\Service\Avatar\ConfigurationReloaderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'avatar-colors:validate-config',
    description: 'Validate and optionally reload avatar colors configuration'
)]
class ValidateAvatarColorsConfigCommand extends Command
{
    public function __construct(
        private readonly ConfigurationValidatorService $validator,
        private readonly ConfigurationReloaderService $reloader
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Validate and optionally reload avatar colors configuration')
            ->addOption(
                'reload',
                'r',
                InputOption::VALUE_NONE,
                'Reload configuration after validation'
            )
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED,
                'Path to configuration file (defaults to config/packages/avatar_colors.yaml)'
            )
            ->setHelp(
                'This command validates the avatar colors configuration and optionally reloads it at runtime.' . PHP_EOL .
                'Use --reload to apply configuration changes without restarting the application.' . PHP_EOL .
                'Use --file to specify a custom configuration file path.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $configFile = $input->getOption('file');
        $shouldReload = $input->getOption('reload');

        $io->title('Avatar Colors Configuration Validation');

        // Determine configuration file path
        if ($configFile === null) {
            $projectDir = $this->getApplication()?->getKernel()?->getProjectDir() ?? getcwd();
            $configFile = $projectDir . '/config/packages/avatar_colors.yaml';
        }

        $io->section('Validating Configuration');
        $io->text("Configuration file: <info>{$configFile}</info>");

        // Validate configuration file
        if (!file_exists($configFile)) {
            $io->error("Configuration file not found: {$configFile}");
            return Command::FAILURE;
        }

        $isValid = $this->validator->validateConfigurationFile($configFile);

        if (!$isValid) {
            $io->error('Configuration validation failed:');
            $errors = $this->validator->getValidationErrors();
            
            foreach ($errors as $error) {
                $io->text("  • <fg=red>{$error['message']}</>");
            }
            
            return Command::FAILURE;
        }

        $io->success('Configuration validation passed!');

        // Show configuration summary
        $this->showConfigurationSummary($io, $configFile);

        // Reload configuration if requested
        if ($shouldReload) {
            $io->section('Reloading Configuration');
            
            try {
                $newConfig = $this->reloader->reloadConfiguration();
                
                $io->success('Configuration reloaded successfully!');
                $io->text([
                    "Colors loaded: <info>" . count($newConfig['colors'] ?? []) . "</info>",
                    "Role variations enabled: <info>" . ($newConfig['role_variations']['enabled'] ? 'Yes' : 'No') . "</info>",
                    "Cache enabled: <info>" . ($newConfig['cache']['enabled'] ? 'Yes' : 'No') . "</info>"
                ]);
                
            } catch (\Exception $e) {
                $io->error('Failed to reload configuration: ' . $e->getMessage());
                return Command::FAILURE;
            }
        } else {
            $io->note('Use --reload option to apply configuration changes at runtime.');
        }

        return Command::SUCCESS;
    }

    private function showConfigurationSummary(SymfonyStyle $io, string $configFile): void
    {
        try {
            $config = \Symfony\Component\Yaml\Yaml::parseFile($configFile);
            
            // Extract avatar colors config
            $avatarConfig = $config['parameters']['avatar_colors'] ?? 
                           $config['avatar_colors'] ?? 
                           $config;

            $io->section('Configuration Summary');
            
            $colorsCount = count($avatarConfig['colors'] ?? []);
            $roleVariationsEnabled = $avatarConfig['role_variations']['enabled'] ?? false;
            $cacheEnabled = $avatarConfig['cache']['enabled'] ?? true;
            $minContrastRatio = $avatarConfig['accessibility']['min_contrast_ratio'] ?? 4.5;
            
            $io->definitionList(
                ['Colors defined' => $colorsCount],
                ['Role variations' => $roleVariationsEnabled ? 'Enabled' : 'Disabled'],
                ['Cache' => $cacheEnabled ? 'Enabled' : 'Disabled'],
                ['Min contrast ratio' => $minContrastRatio],
                ['WCAG AA enforcement' => ($avatarConfig['accessibility']['enforce_wcag_aa'] ?? true) ? 'Enabled' : 'Disabled']
            );

            if (isset($avatarConfig['role_variations']['variations'])) {
                $io->text('<info>Configured roles:</info>');
                foreach ($avatarConfig['role_variations']['variations'] as $role => $config) {
                    $intensity = $config['intensity'] ?? 'N/A';
                    $io->text("  • {$role}: intensity {$intensity}");
                }
            }
            
        } catch (\Exception $e) {
            $io->warning('Could not parse configuration for summary: ' . $e->getMessage());
        }
    }
}