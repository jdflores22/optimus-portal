<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;

class DompdfFactory
{
    public static function create(): Dompdf
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isFontSubsettingEnabled', true);
        $options->set('isPhpEnabled', false);
        $options->set('chroot', realpath(__DIR__ . '/../../'));
        
        return new Dompdf($options);
    }
}
