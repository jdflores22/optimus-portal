<?php

namespace App\Service;

use App\Entity\DocumentTemplateConfiguration;
use App\Entity\Enum\DocumentTemplateType;

class DocumentTemplateSampleDataProvider
{
    public function getSampleData(DocumentTemplateType $type): array
    {
        return match ($type) {
            DocumentTemplateType::NOA => [
                'noa' => [
                    'number' => 'NOA-20260616-0003',
                    'bl_number' => 'BL-PH-2026-0042',
                    'vessel_number' => 'MV PACIFIC STAR',
                    'eta' => '2026-06-20 08:00',
                    'port_location' => 'Manila North Harbor',
                    'container_count' => '3',
                ],
                'consignee' => [
                    'name' => 'ABC Trading Corporation',
                    'email' => 'logistics@abctrading.ph',
                ],
                'generated' => [
                    'date' => date('Y-m-d H:i:s'),
                    'by' => 'Terminal Team',
                ],
                'company' => [
                    'name' => 'OPTIMUS Shipping Lines',
                ],
                'containers' => [
                    'table' => [
                        ['MSCU1234567', 'Dry', '40ft', '2.0'],
                        ['TCLU7654321', 'Reefer', '20ft', '1.0'],
                        ['GESU9876543', 'Dry', '20ft', '1.0'],
                    ],
                ],
            ],
            DocumentTemplateType::EDO => [
                'platform' => [
                    'brand' => 'OPTIMUS',
                    'tagline' => 'MARITIME LOGISTICS PLATFORM',
                ],
                'edo' => [
                    'status' => 'ELECTRONIC RELEASE',
                    'document_title' => 'ELECTRONIC DELIVERY ORDER',
                    'document_subtitle' => 'CONTAINER RELEASE ORDER',
                    'port_directive' => 'Please release the above cargo container(s) to the Consignee/Broker/Hauler. Free Demurrage time is valid until 2400H of the specified validity date. Pre-advise notice is mandatory for container returns to MICT/ATI to prevent shut out fees.',
                    'generated_by' => 'Maria Santos',
                ],
                'manifest' => [
                    'number' => 'MNF-2026-2010',
                    'bl_number' => 'BL20260602210',
                ],
                'noa' => [
                    'number' => 'NOA-20260602-0013',
                ],
                'consignee' => [
                    'name' => 'HOPE IT SOLUTIONS',
                ],
                'broker' => [
                    'name' => 'JUNE DIONELLE FLORES',
                ],
                'company' => [
                    'name' => 'OPTIMUS SHIPPING LINE',
                ],
                'shipping' => [
                    'line' => 'OPTIMUS SHIPPING LINE',
                ],
                'vessel' => [
                    'name' => 'WANHAI-PIONEER-V210',
                    'voyage' => 'N/A',
                    'display' => 'WANHAI-PIONEER-V210 / N/A',
                ],
                'generated' => [
                    'date' => date('d-M-Y H:i'),
                    'datetime' => date('Y-m-d H:i:s'),
                    'by' => 'Maria Santos',
                ],
                'staff' => [
                    'name' => 'Maria Santos',
                    'role' => 'SL Staff',
                ],
                'signatures' => [
                    'authorized_title' => 'AUTHORIZED REPRESENTATIVE',
                    'authorized_company' => 'OPTIMUS SHIPPING LINE',
                    'prepared_title' => 'PREPARED BY',
                    'prepared_by' => 'Maria Santos',
                    'received_title' => 'DATE/TIME RECEIVED',
                ],
                'containers' => [
                    'table' => [
                        ['1', 'WHLU8765432', '40 Feet', 'DRY', '—', '—', '—', 'EDO-202606-0002', '11-JUN-2026', 'ATI Terminal Facility'],
                    ],
                ],
            ],
            DocumentTemplateType::MANIFEST_BL => [
                'manifest' => [
                    'number' => 'MNF-2026-0088',
                    'bl_number' => 'BL-PH-2026-0042',
                    'vessel_name' => 'MV PACIFIC STAR',
                    'voyage_number' => 'V-2026-042',
                    'arrival_date' => '2026-06-20 08:00',
                    'issue_date' => date('Y-m-d'),
                    'container_count' => '3',
                ],
                'noa' => [
                    'number' => 'NOA-20260616-0003',
                    'bl_number' => 'BL-PH-2026-0042',
                    'vessel_number' => 'MV PACIFIC STAR',
                    'eta' => '2026-06-20 08:00',
                    'port_location' => 'Manila North Harbor',
                    'container_count' => '3',
                ],
                'consignee' => [
                    'name' => 'ABC Trading Corporation',
                    'email' => 'logistics@abctrading.ph',
                ],
                'generated' => [
                    'date' => date('Y-m-d H:i:s'),
                    'by' => 'Terminal Team',
                ],
                'company' => ['name' => 'OPTIMUS Shipping Lines'],
                'containers' => [
                    'table' => [
                        ['MSCU1234567', 'Dry', '40ft', '2.0'],
                        ['TCLU7654321', 'Reefer', '20ft', '1.0'],
                        ['GESU9876543', 'Dry', '20ft', '1.0'],
                    ],
                ],
            ],
            DocumentTemplateType::BILLING => [
                'billing' => [
                    'id' => '1042',
                    'invoice_number' => '00037',
                    'invoice_date' => 'Jun 02, 2026',
                    'due_date' => 'Jul 02, 2026',
                    'status' => 'UNPAID',
                    'currency' => 'USD',
                    'exchange_rate' => '61.7520',
                    'exchange_rate_display' => '1 USD = P61.7520',
                    'freight_charges' => 'P66,815.66',
                    'freight_charges_usd' => '$1,082.00',
                    'thc_charges' => 'P92,628.00',
                    'thc_charges_usd' => '$1,500.00',
                    'additional_charges_total' => 'P2,470.08',
                    'additional_charges_total_usd' => '$40.00',
                    'additional_charges_count' => '4',
                    'total_amount' => 'P161,913.74',
                    'total_amount_usd' => '(Original: $2,622.00)',
                    'total_amount_display' => 'P161,913.74',
                    'amount_primary' => '$2,622.00',
                    'amount_secondary' => '(P161,913.74)',
                    'amount_header' => '$2,622.00 (P161,913.74)',
                    'note' => 'Payment due within 30 days. Original charges in USD converted to PHP at rate: 1 USD = P61.7520',
                ],
                'manifest' => [
                    'number' => 'MNF-2026-2010',
                    'bl_number' => 'BL20260602210',
                ],
                'consignee' => [
                    'name' => 'ABC Trading Corporation',
                    'email' => 'logistics@abctrading.ph',
                    'address' => 'Unit 15A, Pacific Star Building, Sen. Gil Puyat Ave, Makati City 1226',
                ],
                'broker' => [
                    'name' => 'June Dionelle Flores',
                    'email' => 'broker@example.com',
                ],
                'company' => [
                    'name' => 'OPTIMUS SHIPPING LINE',
                    'address' => 'Port of Manila, South Harbor, Manila, Philippines',
                    'phone' => '',
                    'email' => '',
                ],
                'generated' => [
                    'date' => 'June 02, 2026 12:17 PM',
                    'by' => 'Jaydee Dela Cruz',
                ],
                'charges' => [
                    'table' => [
                        ['1', 'Freight Charges', "$1,082.00\nP66,815.66", '0%'],
                        ['2', 'Terminal Handling Charges (THC)', "$1,500.00\nP92,628.00", '0%'],
                        ['3', 'Other Fee', 'P617.52', '0%'],
                        ['4', 'System Fee', 'P617.52', '0%'],
                        ['5', 'Fuel Surcharge', 'P617.52', '0%'],
                        ['6', 'Cleaning Container', 'P617.52', '0%'],
                    ],
                    'additional_table' => [
                        ['1', 'Other Fee', 'P617.52', '0%'],
                        ['2', 'System Fee', 'P617.52', '0%'],
                        ['3', 'Fuel Surcharge', 'P617.52', '0%'],
                        ['4', 'Cleaning Container', 'P617.52', '0%'],
                    ],
                ],
            ],
            DocumentTemplateType::OFFICIAL_RECEIPT => [
                'billing' => [
                    'id' => '1042',
                    'invoice_number' => '00000020',
                    'invoice_date' => 'Jun 02, 2026',
                    'due_date' => 'Jun 17, 2026',
                    'status' => 'PAID',
                    'currency' => 'USD',
                    'exchange_rate_display' => '1 USD = P61.7520',
                    'total_amount_display' => 'P161,913.74',
                    'note' => 'Official receipt for final payment on billing #00037.',
                ],
                'receipt' => [
                    'number' => 'OR-00000020',
                    'date' => 'Jun 17, 2026',
                    'amount' => '$2,622.00',
                    'currency' => 'USD',
                    'payment_id' => '20',
                ],
                'manifest' => [
                    'number' => 'MNF-2026-2010',
                    'bl_number' => 'BL20260602210',
                ],
                'consignee' => [
                    'name' => 'ABC Trading Corporation',
                    'email' => 'logistics@abctrading.ph',
                    'address' => 'Unit 15A, Pacific Star Building, Sen. Gil Puyat Ave, Makati City 1226',
                ],
                'broker' => [
                    'name' => 'June Dionelle Flores',
                    'email' => 'broker@example.com',
                ],
                'company' => [
                    'name' => 'OPTIMUS SHIPPING LINE',
                    'address' => 'Port of Manila, South Harbor, Manila, Philippines',
                ],
                'generated' => [
                    'date' => 'June 17, 2026 10:30 AM',
                    'by' => 'Accounting Team',
                ],
                'charges' => [
                    'table' => [
                        ['1', 'Freight Charges', "$1,082.00\nP66,815.66", '0%'],
                        ['2', 'Terminal Handling Charges (THC)', "$1,500.00\nP92,628.00", '0%'],
                    ],
                ],
            ],
            DocumentTemplateType::CERTIFICATE => [
                'certificate' => [
                    'title' => 'Certificate of Compliance',
                    'recipient' => 'ABC Trading Corporation',
                    'date' => date('Y-m-d'),
                    'reference' => 'CERT-2026-0042',
                ],
                'generated' => ['date' => date('Y-m-d H:i:s')],
                'company' => ['name' => 'OPTIMUS Shipping Lines'],
            ],
        };
    }
}
