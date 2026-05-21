<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Avatar\AvatarColorServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to invalidate avatar colors cache.
 * 
 * This command can be used to clear the avatar colors cache,
 * typically after configuration changes or for maintenance.
 */
#[AsCommand(
    name: 'avatar:clear-cache',
    description: 'Clear avatar colors cache'
)]
class InvalidateAvatarColorsCacheCommand extends Command
{
    public function __construct(
        private readonly AvatarColorServiceInterface $avatarColorService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Clear avatar colors cache')
            ->setHelp('This command clears the avatar colors cache, forcing regeneration of all cached colors.')
            ->addOption(
                'config-change',
                'c',
                InputOption::VALUE_NONE,
                'Indicate that this invalidation is due to a configuration change'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            $configChange = $input->getOption('config-change');

            $io->title('Avatar Colors Cache Invalidation');

            if ($configChange) {
                $io->info('Invalidating cache due to configuration change...');
                $this->avatarColorService->invalidateCacheOnConfigChange();
            } else {
                $io->info('Clearing all avatar colors cache...');
                $this->avatarColorService->clearCache();
            }

            $io->success('Avatar colors cache cleared successfully.');

            // Display cache statistics
            $stats = $this->avatarColorService->getCacheStats();
            $io->section('Cache Configuration');
            $io->table(
                ['Setting', 'Value'],
                [
                    ['Cache Enabled', $stats['cache_enabled'] ? 'Yes' : 'No'],
                    ['Cache TTL', $stats['cache_ttl'] ?? 'N/A'],
                    ['Cache Prefix', $stats['cache_prefix'] ?? 'N/A'],
                    ['Timestamp', date('Y-m-d H:i:s', $stats['timestamp'] ?? time())]
                ]
            );

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Cache invalidation failed: ' . $e->getMessage());
            $this->logger->error('Avatar colors cache invalidation command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }
}