<?php

namespace App\Tests\Service;

use App\Entity\DocumentVerification;
use App\Entity\Enum\DocumentTemplateType;
use App\Repository\DocumentVerificationRepository;
use App\Service\DocumentVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class DocumentVerificationServiceTest extends TestCase
{
    public function testGetOrCreateVerificationUrlCreatesRecord(): void
    {
        $repository = $this->createMock(DocumentVerificationRepository::class);
        $repository->method('findOneBySubject')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(DocumentVerification::class));
        $entityManager->expects($this->once())->method('flush');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with(
                'document_verification_show',
                $this->callback(fn (array $params) => isset($params['token']) && strlen($params['token']) === 64),
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://example.com/verify/document/new-token');

        $service = new DocumentVerificationService($repository, $entityManager, $urlGenerator);

        $url = $service->getOrCreateVerificationUrl(
            DocumentTemplateType::NOA,
            'noa',
            42,
            'NOA-TEST-001',
            ['document_number' => 'NOA-TEST-001'],
        );

        $this->assertSame('https://example.com/verify/document/new-token', $url);
    }

    public function testGetOrCreateVerificationUrlReusesExistingToken(): void
    {
        $existing = new DocumentVerification();
        $existing->setVerificationToken(str_repeat('a', 64));
        $existing->setDocumentType(DocumentTemplateType::NOA);
        $existing->setSubjectType('noa');
        $existing->setSubjectId(42);
        $existing->setDocumentNumber('NOA-TEST-001');

        $repository = $this->createMock(DocumentVerificationRepository::class);
        $repository->method('findOneBySubject')->willReturn($existing);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with(
                'document_verification_show',
                ['token' => str_repeat('a', 64)],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://example.com/verify/document/' . str_repeat('a', 64));

        $service = new DocumentVerificationService($repository, $entityManager, $urlGenerator);

        $url = $service->getOrCreateVerificationUrl(
            DocumentTemplateType::NOA,
            'noa',
            42,
            'NOA-TEST-001',
            ['document_number' => 'NOA-TEST-001', 'bl_number' => 'BL-001'],
        );

        $this->assertSame('https://example.com/verify/document/' . str_repeat('a', 64), $url);
    }

    public function testBuildPreviewSampleUrlUsesPreviewToken(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with(
                'document_verification_show',
                ['token' => DocumentVerificationService::PREVIEW_SAMPLE_TOKEN],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://example.com/verify/document/preview-sample');

        $service = new DocumentVerificationService(
            $this->createMock(DocumentVerificationRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $urlGenerator,
        );

        $this->assertSame(
            'https://example.com/verify/document/preview-sample',
            $service->buildPreviewSampleUrl(),
        );
    }
}
