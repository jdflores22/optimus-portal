<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Minishlink\WebPush\VAPID;

#[AsCommand(
    name: 'app:generate-vapid-keys',
    description: 'Generate VAPID keys for Web Push notifications',
)]
class GenerateVapidKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Generating VAPID Keys for Web Push Notifications');
        
        try {
            // Generate VAPID keys
            $keys = VAPID::createVapidKeys();
            
            $io->success('VAPID keys generated successfully!');
            $io->newLine();
            
            $io->section('Add these to your .env file:');
            $io->text([
                'VAPID_PUBLIC_KEY=' . $keys['publicKey'],
                'VAPID_PRIVATE_KEY=' . $keys['privateKey'],
                'VAPID_SUBJECT=mailto:noreply@optimus-shipping.com',
            ]);
            
            $io->newLine();
            $io->note('Make sure to clear your cache after updating .env: php bin/console cache:clear');
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to generate VAPID keys: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
