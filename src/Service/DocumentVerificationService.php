<?php

namespace App\Service;

use App\Entity\DocumentVerification;
use App\Entity\Enum\DocumentTemplateType;
use App\Repository\DocumentVerificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class DocumentVerificationService
{
    public const PREVIEW_SAMPLE_TOKEN = 'preview-sample';

    public function __construct(
        private DocumentVerificationRepository $repository,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function getOrCreateVerificationUrl(
        DocumentTemplateType $documentType,
        string $subjectType,
        int $subjectId,
        string $documentNumber,
        array $summary,
    ): string {
        $record = $this->repository->findOneBySubject($documentType, $subjectType, $subjectId);

        if (!$record) {
            $record = new DocumentVerification();
            $record->setVerificationToken($this->generateToken());
            $record->setDocumentType($documentType);
            $record->setSubjectType($subjectType);
            $record->setSubjectId($subjectId);
            $record->setDocumentNumber($documentNumber);

            $this->entityManager->persist($record);
        }

        $record->setDocumentNumber($documentNumber);
        $record->setSummary($summary);
        $this->entityManager->flush();

        return $this->buildVerificationUrl($record->getVerificationToken());
    }

    public function buildVerificationUrl(string $token): string
    {
        return $this->urlGenerator->generate(
            'document_verification_show',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    public function buildPreviewSampleUrl(): string
    {
        return $this->buildVerificationUrl(self::PREVIEW_SAMPLE_TOKEN);
    }

    public function findByToken(string $token): ?DocumentVerification
    {
        if ($token === self::PREVIEW_SAMPLE_TOKEN) {
            return null;
        }

        return $this->repository->findByToken($token);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function appendVerificationContext(
        array $context,
        DocumentTemplateType $documentType,
        string $subjectType,
        int $subjectId,
        string $documentNumber,
        array $summary,
    ): array {
        $context['document'] ??= [];
        $context['document']['verification_url'] = $this->getOrCreateVerificationUrl(
            $documentType,
            $subjectType,
            $subjectId,
            $documentNumber,
            $summary,
        );
        $context['document']['number'] = $documentNumber;

        return $context;
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
