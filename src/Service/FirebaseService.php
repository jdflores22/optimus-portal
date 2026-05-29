<?php

namespace App\Service;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Firestore;
use Google\Cloud\Firestore\FirestoreClient;

/**
 * Firebase Service
 * Provides access to Firebase Firestore database
 */
class FirebaseService
{
    private ?FirestoreClient $firestore = null;
    private string $serviceAccountPath;

    public function __construct(string $projectDir)
    {
        $this->serviceAccountPath = $projectDir . '/config/firebase/service-account.json';
    }

    /**
     * Get Firestore client instance
     */
    public function getFirestore(): FirestoreClient
    {
        if ($this->firestore === null) {
            if (!file_exists($this->serviceAccountPath)) {
                throw new \RuntimeException(
                    'Firebase service account file not found at: ' . $this->serviceAccountPath
                );
            }

            // Create Firestore client directly with service account
            $this->firestore = new FirestoreClient([
                'keyFilePath' => $this->serviceAccountPath
            ]);
        }

        return $this->firestore;
    }

    /**
     * Create a document in a collection
     */
    public function createDocument(string $collection, array $data, ?string $documentId = null): string
    {
        $db = $this->getFirestore();
        
        if ($documentId) {
            $docRef = $db->collection($collection)->document($documentId);
            $docRef->set($data);
            return $documentId;
        } else {
            $docRef = $db->collection($collection)->add($data);
            return $docRef->id();
        }
    }

    /**
     * Get a document by ID
     */
    public function getDocument(string $collection, string $documentId): ?array
    {
        $db = $this->getFirestore();
        $docRef = $db->collection($collection)->document($documentId);
        $snapshot = $docRef->snapshot();

        if ($snapshot->exists()) {
            return $snapshot->data();
        }

        return null;
    }

    /**
     * Update a document
     */
    public function updateDocument(string $collection, string $documentId, array $data): void
    {
        $db = $this->getFirestore();
        $docRef = $db->collection($collection)->document($documentId);
        $docRef->update($data);
    }

    /**
     * Delete a document
     */
    public function deleteDocument(string $collection, string $documentId): void
    {
        $db = $this->getFirestore();
        $docRef = $db->collection($collection)->document($documentId);
        $docRef->delete();
    }

    /**
     * Query documents in a collection
     */
    public function queryDocuments(string $collection, array $filters = [], int $limit = 100): array
    {
        $db = $this->getFirestore();
        $query = $db->collection($collection);

        // Apply filters
        foreach ($filters as $filter) {
            [$field, $operator, $value] = $filter;
            $query = $query->where($field, $operator, $value);
        }

        // Apply limit
        $query = $query->limit($limit);

        $documents = $query->documents();
        $results = [];

        foreach ($documents as $document) {
            $results[] = [
                'id' => $document->id(),
                'data' => $document->data()
            ];
        }

        return $results;
    }

    /**
     * Get all documents in a collection
     */
    public function getAllDocuments(string $collection): array
    {
        $db = $this->getFirestore();
        $documents = $db->collection($collection)->documents();
        $results = [];

        foreach ($documents as $document) {
            $results[] = [
                'id' => $document->id(),
                'data' => $document->data()
            ];
        }

        return $results;
    }

    /**
     * Batch write operations
     */
    public function batchWrite(array $operations): void
    {
        $db = $this->getFirestore();

        foreach ($operations as $operation) {
            $type = $operation['type'];
            $collection = $operation['collection'];
            $documentId = $operation['documentId'];
            $docRef = $db->collection($collection)->document($documentId);

            switch ($type) {
                case 'set':
                    $docRef->set($operation['data']);
                    break;
                case 'update':
                    $docRef->update($operation['data']);
                    break;
                case 'delete':
                    $docRef->delete();
                    break;
            }
        }
    }

    /**
     * Run a transaction
     */
    public function runTransaction(callable $callback): mixed
    {
        $db = $this->getFirestore();
        return $db->runTransaction($callback);
    }
}
