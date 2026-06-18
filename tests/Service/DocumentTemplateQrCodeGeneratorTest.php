<?php

namespace App\Tests\Service;

use App\Service\DocumentTemplateQrCodeGenerator;
use PHPUnit\Framework\TestCase;

class DocumentTemplateQrCodeGeneratorTest extends TestCase
{
    public function testGenerateDataUriReturnsPngPayload(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for QR code generation.');
        }

        $generator = new DocumentTemplateQrCodeGenerator();
        $dataUri = $generator->generateDataUri('NOA-TEST-001', 80);

        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);
        $this->assertNotSame('', substr($dataUri, strlen('data:image/png;base64,')));
    }

    public function testGenerateDataUriReturnsEmptyForBlankValue(): void
    {
        $generator = new DocumentTemplateQrCodeGenerator();

        $this->assertSame('', $generator->generateDataUri(''));
        $this->assertSame('', $generator->generateDataUri('—'));
    }
}
