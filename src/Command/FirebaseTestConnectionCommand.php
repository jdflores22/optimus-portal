<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Service\FirebaseService;

#[AsCommand(
    name: 'firebase:test-connection',
    description: 'Test Firebase Firestore connection',
)]
class FirebaseTestConnectionCommand extends Command
{
    public function __construct(
        private FirebaseService $firebaseService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Firebase Connection Test');

        try {
            // Test connection by creating a test document
            $io->text('Testing Firestore connection...');
            
            $testData = [
                'test' => true,
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => 'Connection test successful'
            ];

            $docId = $this->firebaseService->createDocument('_test', $testData, 'connection_test');
            $io->success('Successfully created test document with ID: ' . $docId);

            // Read the document back
            $io->text('Reading test document...');
            $document = $this->firebaseService->getDocument('_test', $docId);
            
            if ($document) {
                $io->success('Successfully read test document');
                $io->table(['Field', 'Value'], [
                    ['test', $document['test'] ? 'true' : 'false'],
                    ['timestamp', $document['timestamp']],
                    ['message', $document['message']]
                ]);
            }

            // Delete the test document
            $io->text('Cleaning up test document...');
            $this->firebaseService->deleteDocument('_test', $docId);
            $io->success('Test document deleted');

            $io->success('Firebase connection test completed successfully!');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Firebase connection test failed: ' . $e->getMessage());
            $io->note('Make sure you have:');
            $io->listing([
                'Created a Firebase project',
                'Downloaded the service account JSON file',
                'Placed it at: config/firebase/service-account.json',
                'Enabled Firestore in your Firebase project'
            ]);
            return Command::FAILURE;
        }
    }
}
