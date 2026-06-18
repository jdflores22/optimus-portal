<?php

namespace App\Tests\Service;

use App\Entity\Container;
use App\Entity\ContainerSize;
use App\Entity\ContainerType;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Manifest;
use App\Entity\NOA;
use App\Entity\ShippingLine;
use App\Entity\StaffUser;
use App\Service\DocumentTemplateContextBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DocumentTemplateEdoContextTest extends KernelTestCase
{
    public function testBuildEdoBulkContextIncludesContainerTableRows(): void
    {
        self::bootKernel();

        /** @var DocumentTemplateContextBuilder $builder */
        $builder = self::getContainer()->get(DocumentTemplateContextBuilder::class);

        $shippingLine = $this->createMock(ShippingLine::class);
        $shippingLine->method('getBrandName')->willReturn('OPTIMUS SHIPPING LINE');
        $shippingLine->method('getPortalConfig')->willReturn([]);

        $noa = $this->createMock(NOA::class);
        $noa->method('getNoaNumber')->willReturn('NOA-20260602-0013');
        $noa->method('getBlNumber')->willReturn('BL20260602210');
        $noa->method('getVesselNumber')->willReturn('WANHAI-PIONEER-V210');

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getManifestNumber')->willReturn('MNF-2026-2010');
        $manifest->method('getNoa')->willReturn($noa);
        $manifest->method('getVoyageNumber')->willReturn('N/A');
        $manifest->method('getConsignee')->willReturn(null);
        $manifest->method('getBroker')->willReturn(null);

        $containerSize = $this->createMock(ContainerSize::class);
        $containerSize->method('getName')->willReturn('40 Feet');

        $containerType = $this->createMock(ContainerType::class);
        $containerType->method('getCode')->willReturn('DRY');

        $container = $this->createMock(Container::class);
        $container->method('getContainerNumber')->willReturn('WHLU8765432');
        $container->method('getContainerSize')->willReturn($containerSize);
        $container->method('getContainerType')->willReturn($containerType);
        $container->method('getCyAllocation')->willReturn(null);

        $edo = $this->createMock(ElectronicDeliveryOrder::class);
        $edo->method('getManifest')->willReturn($manifest);
        $edo->method('getShippingLine')->willReturn($shippingLine);
        $edo->method('getContainer')->willReturn($container);
        $edo->method('getEdoNumber')->willReturn('EDO-202606-0002');
        $edo->method('getExpiresAt')->willReturn(new \DateTimeImmutable('2026-06-11'));
        $edo->method('getCyLocation')->willReturn('ATI Terminal Facility');
        $edo->method('getGeneratedByName')->willReturn(null);

        $context = $builder->buildEdoBulkContext([$edo]);

        self::assertSame('OPTIMUS SYSTEM', $context['generated']['by']);
        self::assertSame('OPTIMUS SYSTEM', $context['signatures']['prepared_by']);

        self::assertSame('BL20260602210', $context['manifest']['bl_number']);
        self::assertSame('NOA-20260602-0013', $context['noa']['number']);
        self::assertSame('ELECTRONIC RELEASE', $context['edo']['status']);
        self::assertCount(1, $context['containers']['table']);
        self::assertSame('WHLU8765432', $context['containers']['table'][0][1]);
        self::assertSame('EDO-202606-0002', $context['containers']['table'][0][7]);
        self::assertSame('ATI Terminal Facility', $context['containers']['table'][0][9]);
    }

    public function testBuildEdoBulkContextUsesSlStaffGeneratorName(): void
    {
        self::bootKernel();

        /** @var DocumentTemplateContextBuilder $builder */
        $builder = self::getContainer()->get(DocumentTemplateContextBuilder::class);

        $shippingLine = $this->createMock(ShippingLine::class);
        $shippingLine->method('getBrandName')->willReturn('OPTIMUS SHIPPING LINE');
        $shippingLine->method('getPortalConfig')->willReturn([]);

        $noa = $this->createMock(NOA::class);
        $noa->method('getNoaNumber')->willReturn('NOA-20260602-0013');
        $noa->method('getBlNumber')->willReturn('BL20260602210');
        $noa->method('getVesselNumber')->willReturn('WANHAI-PIONEER-V210');

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getManifestNumber')->willReturn('MNF-2026-2010');
        $manifest->method('getNoa')->willReturn($noa);
        $manifest->method('getVoyageNumber')->willReturn('N/A');
        $manifest->method('getConsignee')->willReturn(null);
        $manifest->method('getBroker')->willReturn(null);

        $containerSize = $this->createMock(ContainerSize::class);
        $containerSize->method('getName')->willReturn('40 Feet');

        $containerType = $this->createMock(ContainerType::class);
        $containerType->method('getCode')->willReturn('DRY');

        $container = $this->createMock(Container::class);
        $container->method('getContainerNumber')->willReturn('WHLU8765432');
        $container->method('getContainerSize')->willReturn($containerSize);
        $container->method('getContainerType')->willReturn($containerType);
        $container->method('getCyAllocation')->willReturn(null);

        $edo = $this->createMock(ElectronicDeliveryOrder::class);
        $edo->method('getManifest')->willReturn($manifest);
        $edo->method('getShippingLine')->willReturn($shippingLine);
        $edo->method('getContainer')->willReturn($container);
        $edo->method('getEdoNumber')->willReturn('EDO-202606-0002');
        $edo->method('getExpiresAt')->willReturn(new \DateTimeImmutable('2026-06-11'));
        $edo->method('getCyLocation')->willReturn('ATI Terminal Facility');
        $edo->method('getGeneratedByName')->willReturn('Stored Name Fallback');

        $staffUser = $this->createMock(StaffUser::class);
        $staffUser->method('getFullName')->willReturn('Juan Dela Cruz');

        $context = $builder->buildEdoBulkContext([$edo], $staffUser);

        self::assertSame('Juan Dela Cruz', $context['generated']['by']);
        self::assertSame('Juan Dela Cruz', $context['signatures']['prepared_by']);
        self::assertSame('Juan Dela Cruz', $context['staff']['name']);
        self::assertSame('SL Staff', $context['staff']['role']);
        self::assertSame('Juan Dela Cruz', $context['edo']['generated_by']);
    }

    public function testBuildEdoBulkContextFallsBackToStoredGeneratorName(): void
    {
        self::bootKernel();

        /** @var DocumentTemplateContextBuilder $builder */
        $builder = self::getContainer()->get(DocumentTemplateContextBuilder::class);

        $shippingLine = $this->createMock(ShippingLine::class);
        $shippingLine->method('getBrandName')->willReturn('OPTIMUS SHIPPING LINE');
        $shippingLine->method('getPortalConfig')->willReturn([]);

        $noa = $this->createMock(NOA::class);
        $noa->method('getNoaNumber')->willReturn('NOA-20260602-0013');
        $noa->method('getBlNumber')->willReturn('BL20260602210');
        $noa->method('getVesselNumber')->willReturn('WANHAI-PIONEER-V210');

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getManifestNumber')->willReturn('MNF-2026-2010');
        $manifest->method('getNoa')->willReturn($noa);
        $manifest->method('getVoyageNumber')->willReturn('N/A');
        $manifest->method('getConsignee')->willReturn(null);
        $manifest->method('getBroker')->willReturn(null);

        $containerSize = $this->createMock(ContainerSize::class);
        $containerSize->method('getName')->willReturn('40 Feet');

        $containerType = $this->createMock(ContainerType::class);
        $containerType->method('getCode')->willReturn('DRY');

        $container = $this->createMock(Container::class);
        $container->method('getContainerNumber')->willReturn('WHLU8765432');
        $container->method('getContainerSize')->willReturn($containerSize);
        $container->method('getContainerType')->willReturn($containerType);
        $container->method('getCyAllocation')->willReturn(null);

        $edo = $this->createMock(ElectronicDeliveryOrder::class);
        $edo->method('getManifest')->willReturn($manifest);
        $edo->method('getShippingLine')->willReturn($shippingLine);
        $edo->method('getContainer')->willReturn($container);
        $edo->method('getEdoNumber')->willReturn('EDO-202606-0002');
        $edo->method('getExpiresAt')->willReturn(new \DateTimeImmutable('2026-06-11'));
        $edo->method('getCyLocation')->willReturn('ATI Terminal Facility');
        $edo->method('getGeneratedByName')->willReturn('Ana Reyes');

        $context = $builder->buildEdoBulkContext([$edo]);

        self::assertSame('Ana Reyes', $context['generated']['by']);
        self::assertSame('Ana Reyes', $context['signatures']['prepared_by']);
    }
}
