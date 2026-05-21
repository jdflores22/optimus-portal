<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Service\Avatar\AvatarColorServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to warm up avatar colors cache for frequent users.
 * 
 * This command can be run periodically to pre-populate the cache
 * with avatar colors for users who are accessed frequently.
 */
#[AsCommand(
    name: 'avatar:warm-cache',
    description: 'Warm up avatar colors cache for frequent users'
)]
class WarmAvatarColorsCommand extends Command
{
    public function __construct(
        private readonly AvatarColorServiceInterface $avatarColorService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Warm up avatar colors cache for frequent users')
            ->setHelp('This command pre-populates the avatar colors cache for users who are accessed frequently, improving performance.')
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of users to warm up (default: 100)',
                100
            )
            ->addOption(
                'recent-days',
                'r',
                InputOption::VALUE_OPTIONAL,
                'Only warm cache for users active in the last N days (default: 30)',
                30
            )
            ->addOption(
                'all-users',
                'a',
                InputOption::VALUE_NONE,
                'Warm cache for all users (ignores limit and recent-days options)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            $limit = (int) $input->getOption('limit');
            $recentDays = (int) $input->getOption('recent-days');
            $allUsers = $input->getOption('all-users');

            $io->title('Avatar Colors Cache Warming');

            // Get users to warm up
            $users = $this->getFrequentUsers($limit, $recentDays, $allUsers);
            
            if (empty($users)) {
                $io->warning('No users found to warm up cache for.');
                return Command::SUCCESS;
            }

            $io->info(sprintf('Found %d users to warm up cache for.', count($users)));

            // Warm up cache
            $io->progressStart(count($users));
            
            $warmedCount = 0;
            $failedCount = 0;

            foreach ($users as $user) {
                try {
                    // Warm up cache for this user
                    $this->avatarColorService->warmUpCache([$user]);
                    $warmedCount++;
                } catch (\Exception $e) {
                    $this->logger->error('Failed to warm cache for user', [
                        'user_id' => $user->getId(),
                        'error' => $e->getMessage()
                    ]);
                    $failedCount++;
                }
                
                $io->progressAdvance();
            }

            $io->progressFinish();

            // Display results
            $io->success(sprintf(
                'Cache warming completed. Warmed: %d, Failed: %d, Total: %d',
                $warmedCount,
                $failedCount,
                count($users)
            ));

            if ($failedCount > 0) {
                $io->warning(sprintf('%d users failed to warm up. Check logs for details.', $failedCount));
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Cache warming failed: ' . $e->getMessage());
            $this->logger->error('Avatar colors cache warming command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Get frequent users for cache warming.
     */
    private function getFrequentUsers(int $limit, int $recentDays, bool $allUsers): array
    {
        try {
            $queryBuilder = $this->entityManager->createQueryBuilder()
                ->select('u')
                ->from(User::class, 'u')
                ->where('u.isActive = :active')
                ->setParameter('active', true);

            if (!$allUsers) {
                // Filter by recent activity if not warming all users
                $cutoffDate = new \DateTime(sprintf('-%d days', $recentDays));
                $queryBuilder
                    ->andWhere('u.lastLoginAt >= :cutoff OR u.createdAt >= :cutoff')
                    ->setParameter('cutoff', $cutoffDate)
                    ->setMaxResults($limit);
            }

            // Order by last login to prioritize recently active users
            $queryBuilder->orderBy('u.lastLoginAt', 'DESC');

            return $queryBuilder->getQuery()->getResult();

        } catch (\Exception $e) {
            $this->logger->error('Failed to get frequent users for cache warming', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}