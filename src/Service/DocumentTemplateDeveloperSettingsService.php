<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class DocumentTemplateDeveloperSettingsService
{
    private string $configPath;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
        $this->configPath = $projectDir . '/config/document_template_developer_settings.json';
    }

    public function isNoaPdfRegenerateEnabled(): bool
    {
        $settings = $this->load();

        if (!array_key_exists('noa_pdf_regenerate_enabled', $settings)) {
            return $this->environment === 'dev';
        }

        return (bool) $settings['noa_pdf_regenerate_enabled'];
    }

    public function setNoaPdfRegenerateEnabled(bool $enabled): void
    {
        $settings = $this->load();
        $settings['noa_pdf_regenerate_enabled'] = $enabled;
        $this->save($settings);
    }

    public function isManifestBlPdfRegenerateEnabled(): bool
    {
        $settings = $this->load();

        if (!array_key_exists('manifest_bl_pdf_regenerate_enabled', $settings)) {
            return $this->environment === 'dev';
        }

        return (bool) $settings['manifest_bl_pdf_regenerate_enabled'];
    }

    public function setManifestBlPdfRegenerateEnabled(bool $enabled): void
    {
        $settings = $this->load();
        $settings['manifest_bl_pdf_regenerate_enabled'] = $enabled;
        $this->save($settings);
    }

    public function isBillingPdfRegenerateEnabled(): bool
    {
        $settings = $this->load();

        if (!array_key_exists('billing_pdf_regenerate_enabled', $settings)) {
            return $this->environment === 'dev';
        }

        return (bool) $settings['billing_pdf_regenerate_enabled'];
    }

    public function setBillingPdfRegenerateEnabled(bool $enabled): void
    {
        $settings = $this->load();
        $settings['billing_pdf_regenerate_enabled'] = $enabled;
        $this->save($settings);
    }

    public function isOfficialReceiptPdfRegenerateEnabled(): bool
    {
        $settings = $this->load();

        if (!array_key_exists('official_receipt_pdf_regenerate_enabled', $settings)) {
            return $this->environment === 'dev';
        }

        return (bool) $settings['official_receipt_pdf_regenerate_enabled'];
    }

    public function setOfficialReceiptPdfRegenerateEnabled(bool $enabled): void
    {
        $settings = $this->load();
        $settings['official_receipt_pdf_regenerate_enabled'] = $enabled;
        $this->save($settings);
    }

    public function isEdoPdfRegenerateEnabled(): bool
    {
        $settings = $this->load();

        if (!array_key_exists('edo_pdf_regenerate_enabled', $settings)) {
            return $this->environment === 'dev';
        }

        return (bool) $settings['edo_pdf_regenerate_enabled'];
    }

    public function setEdoPdfRegenerateEnabled(bool $enabled): void
    {
        $settings = $this->load();
        $settings['edo_pdf_regenerate_enabled'] = $enabled;
        $this->save($settings);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if (!is_file($this->configPath)) {
            return [];
        }

        $json = file_get_contents($this->configPath);
        if ($json === false || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function save(array $settings): void
    {
        $directory = dirname($this->configPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        file_put_contents($this->configPath, $json);
    }
}
