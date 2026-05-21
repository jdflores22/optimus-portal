<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Manifest;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Environment;

class AuditLogExportService
{
    public function __construct(
        private Environment $twig,
        private ParameterBagInterface $params
    ) {
    }

    /**
     * Export audit logs to PDF format
     * 
     * @param Manifest $manifest The manifest
     * @param array $auditLogs Array of AuditLog entities
     * @return string PDF file path
     */
    public function exportToPDF(Manifest $manifest, array $auditLogs): string
    {
        $exportPath = $this->params->get('audit_log.export_path');
        
        if (!is_dir($exportPath)) {
            mkdir($exportPath, 0755, true);
        }

        // Generate HTML content
        $html = $this->twig->render('audit/export_pdf.html.twig', [
            'manifest' => $manifest,
            'auditLogs' => $auditLogs,
            'exportDate' => new \DateTime(),
        ]);

        // Configure Dompdf
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Save PDF
        $filename = sprintf(
            'audit_trail_manifest_%s_%s.pdf',
            $manifest->getManifestNumber(),
            date('Y-m-d_His')
        );
        
        $filepath = $exportPath . '/' . $filename;
        file_put_contents($filepath, $dompdf->output());

        return $filepath;
    }

    /**
     * Export audit logs to CSV format
     * 
     * @param Manifest $manifest The manifest
     * @param array $auditLogs Array of AuditLog entities
     * @return string CSV file path
     */
    public function exportToCSV(Manifest $manifest, array $auditLogs): string
    {
        $exportPath = $this->params->get('audit_log.export_path');
        
        if (!is_dir($exportPath)) {
            mkdir($exportPath, 0755, true);
        }

        $filename = sprintf(
            'audit_trail_manifest_%s_%s.csv',
            $manifest->getManifestNumber(),
            date('Y-m-d_His')
        );
        
        $filepath = $exportPath . '/' . $filename;
        $output = fopen($filepath, 'w');

        // Write CSV header
        fputcsv($output, [
            'Timestamp',
            'User',
            'User Role',
            'Action',
            'Entity Type',
            'Entity ID',
            'Changes',
            'IP Address'
        ]);

        // Write data rows
        foreach ($auditLogs as $log) {
            $user = $log->getUser();
            $changes = json_encode($log->getChanges());
            
            fputcsv($output, [
                $log->getTimestamp()->format('Y-m-d H:i:s'),
                $user->getFullName() ?? $user->getEmail(),
                $user->getRole()->value,
                $log->getAction(),
                $log->getEntityType(),
                $log->getEntityId(),
                $changes,
                $log->getIpAddress()
            ]);
        }

        fclose($output);

        return $filepath;
    }

    /**
     * Export audit logs to JSON format
     * 
     * @param Manifest $manifest The manifest
     * @param array $auditLogs Array of AuditLog entities
     * @return string JSON file path
     */
    public function exportToJSON(Manifest $manifest, array $auditLogs): string
    {
        $exportPath = $this->params->get('audit_log.export_path');
        
        if (!is_dir($exportPath)) {
            mkdir($exportPath, 0755, true);
        }

        $filename = sprintf(
            'audit_trail_manifest_%s_%s.json',
            $manifest->getManifestNumber(),
            date('Y-m-d_His')
        );
        
        $filepath = $exportPath . '/' . $filename;

        $data = [
            'manifest' => [
                'id' => $manifest->getId(),
                'manifestNumber' => $manifest->getManifestNumber(),
                'exportedAt' => date('Y-m-d H:i:s'),
            ],
            'auditLogs' => array_map(function($log) {
                $user = $log->getUser();
                return [
                    'timestamp' => $log->getTimestamp()->format('Y-m-d H:i:s'),
                    'user' => [
                        'id' => $user->getId(),
                        'name' => $user->getFullName() ?? $user->getEmail(),
                        'role' => $user->getRole()->value,
                    ],
                    'action' => $log->getAction(),
                    'entityType' => $log->getEntityType(),
                    'entityId' => $log->getEntityId(),
                    'changes' => $log->getChanges(),
                    'ipAddress' => $log->getIpAddress(),
                ];
            }, $auditLogs)
        ];

        file_put_contents(
            $filepath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        return $filepath;
    }
}
