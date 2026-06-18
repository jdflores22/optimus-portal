<?php

namespace App\Tests\Service;

use App\Entity\Consignee;
use App\Entity\Container;
use App\Entity\ContainerSize;
use App\Entity\ContainerType;
use App\Entity\Enum\ContainerStatus;
use App\Entity\NOA;
use App\Entity\StaffUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\DocumentTemplateContextBuilder;
use App\Service\DocumentTemplateRenderer;
use App\Service\DocumentVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;

class DocumentTemplateIntegrationTest extends TestCase
{
    private function createRenderer(Environment $twig): DocumentTemplateRenderer
    {
        $verificationService = $this->createMock(DocumentVerificationService::class);
        $verificationService->method('buildPreviewSampleUrl')
            ->willReturn('https://example.com/verify/document/preview-sample');

        return new DocumentTemplateRenderer(
            $twig,
            new \App\Service\DocumentTemplateSampleDataProvider(),
            new \App\Service\DocumentTemplateVerticalLayout(),
            new \App\Service\DocumentTemplateQrCodeGenerator(),
            $verificationService,
            dirname(__DIR__, 2),
        );
    }

    public function testBuildNoaContextMapsEntityFields(): void
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $builder = new DocumentTemplateContextBuilder($entityManager);

        $consignee = new Consignee();
        $consignee->setEmail('consignee@example.com');
        $consignee->setBusinessName('ABC Trading Corp');
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setStatus(AccountStatus::APPROVED);
        $consignee->setPasswordHash('hash');

        $creator = new StaffUser();
        $creator->setEmail('staff@example.com');
        $creator->setRole(UserRole::SL_STAFF);
        $creator->setStatus(AccountStatus::APPROVED);
        $creator->setPasswordHash('hash');
        $creator->setFirstName('John');
        $creator->setLastName('Doe');
        $creator->setDepartment('Terminal');

        $noa = new NOA();
        $noa->setNoaNumber('NOA-TEST-001');
        $noa->setBlNumber('BL-001');
        $noa->setVesselNumber('MV TEST');
        $noa->setEta(new \DateTime('2026-06-20 08:00:00'));
        $noa->setPortLocation('Manila');
        $noa->setConsignee($consignee);
        $noa->setCreatedBy($creator);

        $type = new ContainerType();
        $type->setName('Dry');
        $size = new ContainerSize();
        $size->setName('40ft');
        $size->setTeuValue(2.0);

        $container = new Container();
        $container->setContainerNumber('MSCU1234567');
        $container->setContainerType($type);
        $container->setContainerSize($size);
        $container->setStatus(ContainerStatus::PENDING);
        $noa->addContainer($container);

        $context = $builder->buildNoaContext($noa);

        $this->assertSame('NOA-TEST-001', $context['noa']['number']);
        $this->assertSame('ABC Trading Corp', $context['consignee']['name']);
        $this->assertCount(1, $context['containers']['table']);
        $this->assertSame('MSCU1234567', $context['containers']['table'][0][0]);
    }

    public function testBuildManifestBlContextMergesNoaAndManifestFields(): void
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $builder = new DocumentTemplateContextBuilder($entityManager);

        $consignee = new Consignee();
        $consignee->setEmail('consignee@example.com');
        $consignee->setBusinessName('ABC Trading Corp');
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setStatus(AccountStatus::APPROVED);
        $consignee->setPasswordHash('hash');

        $noa = new NOA();
        $noa->setNoaNumber('NOA-TEST-001');
        $noa->setBlNumber('BL-001');
        $noa->setVesselNumber('MV TEST');
        $noa->setEta(new \DateTime('2026-06-20 08:00:00'));
        $noa->setPortLocation('Manila');
        $noa->setConsignee($consignee);

        $creator = new StaffUser();
        $creator->setEmail('staff@example.com');
        $creator->setRole(UserRole::SL_STAFF);
        $creator->setStatus(AccountStatus::APPROVED);
        $creator->setPasswordHash('hash');
        $creator->setFirstName('John');
        $creator->setLastName('Doe');
        $creator->setDepartment('Terminal');
        $noa->setCreatedBy($creator);

        $context = $builder->buildManifestBlContext($noa, 'MNF-GEN-001');

        $this->assertSame('MNF-GEN-001', $context['manifest']['number']);
        $this->assertSame('BL-001', $context['manifest']['bl_number']);
        $this->assertSame('MV TEST', $context['manifest']['vessel_name']);
        $this->assertSame('2026-06-20 08:00', $context['manifest']['arrival_date']);
        $this->assertSame('NOA-TEST-001', $context['noa']['number']);
        $this->assertSame('ABC Trading Corp', $context['consignee']['name']);
    }

    public function testRendererResolvesInfoRowPlaceholders(): void
    {
        $twig = new Environment(new ArrayLoader([
            'document_template/pdf/document.html.twig' => '{% for element in elements %}{{ element.resolvedValue }}{% endfor %}',
            'document_template/pdf/_element.html.twig' => '{{ element.resolvedValue }}',
        ]));

        $renderer = $this->createRenderer($twig);

        $template = new \App\Entity\DocumentTemplateConfiguration();
        $template->setName('Test');
        $template->setDocumentType(\App\Entity\Enum\DocumentTemplateType::NOA);
        $template->setLayout([
            'canvas' => ['backgroundColor' => '#fff', 'showPageBorder' => false, 'margin' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]],
            'elements' => [
                [
                    'id' => 'el_1',
                    'type' => 'info_row',
                    'order' => 1,
                    'label' => 'NOA Number',
                    'placeholder' => 'noa.number',
                    'style' => [],
                ],
            ],
        ]);

        $html = $renderer->render($template, [
            'noa' => ['number' => 'NOA-TEST-001'],
        ], false);

        $this->assertStringContainsString('NOA-TEST-001', $html);
        $this->assertStringNotContainsString('{{ noa.number }}', $html);
    }

    public function testRendererResolvesFooterPlaceholders(): void
    {
        $twig = new Environment(new ArrayLoader([
            'document_template/pdf/document.html.twig' => '{{ elements[0].content }}',
            'document_template/pdf/_element.html.twig' => '',
        ]));

        $renderer = $this->createRenderer($twig);

        $template = new \App\Entity\DocumentTemplateConfiguration();
        $template->setName('Test');
        $template->setDocumentType(\App\Entity\Enum\DocumentTemplateType::NOA);
        $template->setLayout([
            'canvas' => ['backgroundColor' => '#fff', 'showPageBorder' => false, 'margin' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]],
            'elements' => [
                [
                    'id' => 'el_1',
                    'type' => 'footer',
                    'order' => 1,
                    'content' => 'Generated on {{ generated.date }} by {{ generated.by }}',
                    'style' => [],
                ],
            ],
        ]);

        $html = $renderer->render($template, [
            'generated' => ['date' => '2026-06-17', 'by' => 'Admin'],
        ], false);

        $this->assertStringContainsString('Generated on 2026-06-17 by Admin', $html);
    }

    public function testRendererResolvesLabelPlaceholders(): void
    {
        $twig = new Environment(new ArrayLoader([
            'document_template/pdf/document.html.twig' => '{{ elements[0].label }}|{{ elements[1].resolvedValue }}',
            'document_template/pdf/_element.html.twig' => '',
        ]));

        $renderer = $this->createRenderer($twig);

        $template = new \App\Entity\DocumentTemplateConfiguration();
        $template->setName('Test');
        $template->setDocumentType(\App\Entity\Enum\DocumentTemplateType::BILLING);
        $template->setLayout([
            'canvas' => ['backgroundColor' => '#fff', 'showPageBorder' => false, 'margin' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]],
            'elements' => [
                [
                    'id' => 'el_1',
                    'type' => 'field_label',
                    'order' => 1,
                    'label' => 'Generated on: {{ generated.date }}',
                    'style' => [],
                ],
                [
                    'id' => 'el_2',
                    'type' => 'field_value',
                    'order' => 2,
                    'placeholder' => '{{ generated.by }}',
                    'style' => [],
                ],
            ],
        ]);

        $html = $renderer->render($template, [
            'generated' => ['date' => 'June 17, 2026', 'by' => 'Jane Staff'],
        ], false);

        $this->assertStringContainsString('Generated on: June 17, 2026|Jane Staff', $html);
    }

    public function testRendererRendersSlashDividerInPdfHtml(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $renderer = $this->createRenderer($twig);

        $template = new \App\Entity\DocumentTemplateConfiguration();
        $template->setName('Divider Test');
        $template->setDocumentType(\App\Entity\Enum\DocumentTemplateType::NOA);
        $template->setLayout([
            'canvas' => [
                'backgroundColor' => '#fff',
                'showPageBorder' => false,
                'layoutMode' => 'free',
                'width' => 794,
                'height' => 1123,
                'margin' => ['top' => 48, 'right' => 48, 'bottom' => 48, 'left' => 48],
            ],
            'elements' => [
                [
                    'id' => 'el_divider',
                    'type' => 'divider',
                    'order' => 1,
                    'dividerStyle' => 'slash',
                    'slashWeight' => 'thin',
                    'slashCount' => 5,
                    'height' => 17,
                    'style' => ['color' => '#1e3a5f', 'marginTop' => 12, 'marginBottom' => 12],
                    'position' => ['x' => 48, 'y' => 200, 'width' => 698, 'pinY' => true],
                ],
            ],
        ]);

        $html = $renderer->render($template, [], true);

        $this->assertGreaterThanOrEqual(5, substr_count($html, '>/</td>'));
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringContainsString('#1e3a5f', $html);
    }

    public function testRendererPreservesSavedFreeLayoutPositions(): void
    {
        $twig = new Environment(new ArrayLoader([
            'document_template/pdf/document.html.twig' => '{% for element in elements %}{{ element.position.y }};{% endfor %}',
            'document_template/pdf/_element.html.twig' => '',
        ]));

        $renderer = $this->createRenderer($twig);

        $template = new \App\Entity\DocumentTemplateConfiguration();
        $template->setName('Test');
        $template->setDocumentType(\App\Entity\Enum\DocumentTemplateType::NOA);
        $template->setLayout([
            'canvas' => [
                'layoutMode' => 'free',
                'width' => 794,
                'height' => 1123,
                'margin' => ['top' => 48, 'left' => 48, 'right' => 48, 'bottom' => 48],
            ],
            'elements' => [
                [
                    'id' => 'el_table',
                    'type' => 'table',
                    'order' => 1,
                    'position' => ['x' => 48, 'y' => 600, 'width' => 698, 'measuredHeight' => 82],
                    'placeholder' => 'containers.table',
                    'columns' => ['A', 'B'],
                    'style' => [],
                ],
                [
                    'id' => 'el_footer',
                    'type' => 'footer',
                    'order' => 2,
                    'position' => ['x' => 48, 'y' => 686, 'width' => 698, 'measuredHeight' => 36],
                    'content' => 'Footer',
                    'style' => [],
                ],
            ],
        ]);

        $html = $renderer->render($template, [], false);

        $this->assertStringContainsString('600;', $html);
        $this->assertStringContainsString('686;', $html);
        $this->assertStringNotContainsString('102;', $html);
    }

    public function testRendererEmbedsQrCodeImageForQrBlock(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for QR code generation.');
        }

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));

        $renderer = $this->createRenderer($twig);

        $template = new \App\Entity\DocumentTemplateConfiguration();
        $template->setName('Test');
        $template->setPaperSize('A4');
        $template->setOrientation('portrait');
        $template->setDocumentType(\App\Entity\Enum\DocumentTemplateType::NOA);
        $template->setLayout([
            'canvas' => [
                'layoutMode' => 'free',
                'width' => 794,
                'height' => 1123,
                'margin' => ['top' => 48, 'left' => 48, 'right' => 48, 'bottom' => 48],
            ],
            'elements' => [
                [
                    'id' => 'el_qr',
                    'type' => 'qr_code',
                    'order' => 1,
                    'placeholder' => 'noa.number',
                    'size' => 80,
                    'position' => ['x' => 560, 'y' => 72, 'width' => 120, 'pinY' => true],
                    'style' => [],
                ],
            ],
        ]);

        $html = $renderer->render($template, [
            'noa' => ['number' => 'NOA-TEST-001'],
        ], false);

        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('NOA-TEST-001', $html);
        $this->assertStringNotContainsString('>QR<', $html);
    }

    public function testRendererPrefersVerificationUrlForQrBlock(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for QR code generation.');
        }

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $renderer = $this->createRenderer($twig);

        $template = new \App\Entity\DocumentTemplateConfiguration();
        $template->setName('Test');
        $template->setPaperSize('A4');
        $template->setOrientation('portrait');
        $template->setDocumentType(\App\Entity\Enum\DocumentTemplateType::NOA);
        $template->setLayout([
            'canvas' => [
                'layoutMode' => 'free',
                'width' => 794,
                'height' => 1123,
                'margin' => ['top' => 48, 'left' => 48, 'right' => 48, 'bottom' => 48],
            ],
            'elements' => [
                [
                    'id' => 'el_qr',
                    'type' => 'qr_code',
                    'order' => 1,
                    'placeholder' => 'noa.number',
                    'size' => 80,
                    'position' => ['x' => 560, 'y' => 72, 'width' => 120, 'pinY' => true],
                    'style' => [],
                ],
            ],
        ]);

        $html = $renderer->render($template, [
            'noa' => ['number' => 'NOA-TEST-001'],
            'document' => ['verification_url' => 'https://example.com/verify/document/abc123def456'],
        ], false);

        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringNotContainsString('NOA-TEST-001', $html);
        $this->assertStringNotContainsString('https://example.com/verify/document/abc123def456', $html);
        $this->assertStringNotContainsString('>QR<', $html);
    }
}
