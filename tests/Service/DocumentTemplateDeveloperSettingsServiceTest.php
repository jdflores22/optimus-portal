<?php

namespace App\Tests\Service;

use App\Service\DocumentTemplateDeveloperSettingsService;
use PHPUnit\Framework\TestCase;

class DocumentTemplateDeveloperSettingsServiceTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir() . '/document_template_developer_settings_' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->configPath)) {
            unlink($this->configPath);
        }
    }

    public function testDefaultsToDevEnvironmentWhenUnset(): void
    {
        $service = $this->createService('dev');

        $this->assertTrue($service->isNoaPdfRegenerateEnabled());
    }

    public function testDefaultsToDisabledOutsideDevWhenUnset(): void
    {
        $service = $this->createService('prod');

        $this->assertFalse($service->isNoaPdfRegenerateEnabled());
    }

    public function testPersistsExplicitToggle(): void
    {
        $service = $this->createService('prod');

        $service->setNoaPdfRegenerateEnabled(true);
        $this->assertTrue($service->isNoaPdfRegenerateEnabled());

        $service->setNoaPdfRegenerateEnabled(false);
        $this->assertFalse($service->isNoaPdfRegenerateEnabled());

        $service->setManifestBlPdfRegenerateEnabled(true);
        $this->assertTrue($service->isManifestBlPdfRegenerateEnabled());

        $service->setManifestBlPdfRegenerateEnabled(false);
        $this->assertFalse($service->isManifestBlPdfRegenerateEnabled());

        $service->setBillingPdfRegenerateEnabled(true);
        $this->assertTrue($service->isBillingPdfRegenerateEnabled());

        $service->setBillingPdfRegenerateEnabled(false);
        $this->assertFalse($service->isBillingPdfRegenerateEnabled());

        $service->setOfficialReceiptPdfRegenerateEnabled(true);
        $this->assertTrue($service->isOfficialReceiptPdfRegenerateEnabled());

        $service->setOfficialReceiptPdfRegenerateEnabled(false);
        $this->assertFalse($service->isOfficialReceiptPdfRegenerateEnabled());

        $service->setEdoPdfRegenerateEnabled(true);
        $this->assertTrue($service->isEdoPdfRegenerateEnabled());

        $service->setEdoPdfRegenerateEnabled(false);
        $this->assertFalse($service->isEdoPdfRegenerateEnabled());
    }

    public function testManifestBlDefaultsToDevEnvironmentWhenUnset(): void
    {
        $service = $this->createService('dev');

        $this->assertTrue($service->isManifestBlPdfRegenerateEnabled());
    }

    public function testBillingDefaultsToDevEnvironmentWhenUnset(): void
    {
        $service = $this->createService('dev');

        $this->assertTrue($service->isBillingPdfRegenerateEnabled());
    }

    public function testBillingDefaultsToDisabledOutsideDevWhenUnset(): void
    {
        $service = $this->createService('prod');

        $this->assertFalse($service->isBillingPdfRegenerateEnabled());
    }

    public function testOfficialReceiptDefaultsToDisabledOutsideDevWhenUnset(): void
    {
        $service = $this->createService('prod');

        $this->assertFalse($service->isOfficialReceiptPdfRegenerateEnabled());
    }

    public function testEdoDefaultsToDevEnvironmentWhenUnset(): void
    {
        $service = $this->createService('dev');

        $this->assertTrue($service->isEdoPdfRegenerateEnabled());
    }

    public function testEdoDefaultsToDisabledOutsideDevWhenUnset(): void
    {
        $service = $this->createService('prod');

        $this->assertFalse($service->isEdoPdfRegenerateEnabled());
    }

    private function createService(string $environment): DocumentTemplateDeveloperSettingsService
    {
        $projectDir = dirname($this->configPath);
        $service = new DocumentTemplateDeveloperSettingsService($projectDir, $environment);

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('configPath');
        $property->setAccessible(true);
        $property->setValue($service, $this->configPath);

        return $service;
    }
}
