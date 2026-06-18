<?php

namespace App\Service;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class DocumentTemplateQrCodeGenerator
{
    public function generateDataUri(string $data, int $size = 80): string
    {
        $data = trim($data);
        if ($data === '' || $data === '—') {
            return '';
        }

        $pixelSize = max(64, min(400, $size * 3));

        $qrCode = new QrCode(
            data: $data,
            size: $pixelSize,
            margin: 4,
        );

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        return 'data:image/png;base64,' . base64_encode($result->getString());
    }
}
