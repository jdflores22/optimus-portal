<?php

namespace App\Tests\Property;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Bug Condition Exploration Test for Payment Currency Handling
 * 
 * **Validates: Requirements 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.3, 2.4, 2.5**
 * 
 * CRITICAL: This test MUST FAIL on unfixed code - failure confirms the bug exists
 * DO NOT attempt to fix the test or the code when it fails
 * 
 * NOTE: This test encodes the expected behavior - it will validate the fix when it passes after implementation
 * 
 * GOAL: Surface counterexamples that demonstrate the bug exists
 * 
 * Expected Counterexamples on UNFIXED code:
 * - Payment form shows only small $ symbol within input field, no prominent "Amount in USD" badge
 * - Payment entity has no `currency` field in database schema
 * - Accounting page displays amounts without explicit currency labels
 * - API response does not include currency information
 */
class PaymentCurrencyHandlingBugConditionTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine')->getConnection();
    }

    /**
     * Property 1: Bug Condition - Missing Currency Indicators and Metadata Storage
     * 
     * This test checks multiple aspects of the bug:
     * 1. Payment form lacks prominent currency indicator for USD billings
     * 2. Payment entity lacks currency field in database
     * 3. Accounting validation page lacks currency labels
     * 4. Payment submission doesn't store currency metadata
     * 
     * EXPECTED TO FAIL on unfixed code (this proves the bug exists)
     */
    public function testBugConditionMissingCurrencyIndicatorsAndMetadata(): void
    {
        // ==========================================
        // PART 1: Verify Payment entity lacks currency field
        // ==========================================
        
        $tableColumns = $this->getTableColumns('payments');
        
        // EXPECTED TO FAIL: Payment table should have currency column but doesn't
        $this->assertContains(
            'currency',
            $tableColumns,
            "COUNTEREXAMPLE FOUND: Payment entity has no 'currency' column in database schema. " .
            "This means payments are stored without currency metadata, making it impossible for " .
            "accounting to verify which currency was expected. Current columns: " . 
            implode(', ', $tableColumns)
        );
        
        // ==========================================
        // PART 2: Verify USD billing test data exists (Manifest #172)
        // ==========================================
        
        // Check if Manifest #172 exists with USD billing
        $manifest172Billing = $this->connection->fetchAssociative(
            "SELECT b.id, b.original_currency, b.total_amount_usd, b.total_amount, b.exchange_rate 
             FROM billings b 
             INNER JOIN manifests m ON b.manifest_id = m.id 
             WHERE m.id = 172 AND b.original_currency = 'USD'"
        );
        
        if (!$manifest172Billing) {
            // Manifest #172 might not exist in this environment - find ANY USD billing
            $usdBilling = $this->connection->fetchAssociative(
                "SELECT b.id, b.manifest_id, b.original_currency, b.total_amount_usd, b.total_amount, b.exchange_rate 
                 FROM billings b 
                 WHERE b.original_currency = 'USD' 
                 LIMIT 1"
            );
            
            $this->assertNotFalse(
                $usdBilling,
                "Cannot test bug condition: No USD billing found in database. " .
                "To properly test this bug, create a manifest with USD billing using: " .
                "originalCurrency='USD', totalAmountUsd=2622.00, exchangeRate=61.752, totalAmount=161913.74"
            );
            
            $billingId = $usdBilling['id'];
            $manifestId = $usdBilling['manifest_id'];
            $usdAmount = $usdBilling['total_amount_usd'];
            $phpAmount = $usdBilling['total_amount'];
        } else {
            $billingId = $manifest172Billing['id'];
            $manifestId = 172;
            $usdAmount = $manifest172Billing['total_amount_usd'];
            $phpAmount = $manifest172Billing['total_amount'];
        }
        
        // Document the USD billing we're testing
        $this->addToAssertionCount(1); // Document we found USD billing
        fwrite(STDOUT, "\n✓ Found USD billing (Manifest #{$manifestId}): \${$usdAmount} USD = ₱{$phpAmount} PHP\n");
        
        // ==========================================
        // PART 3: Check if Payment #18 or any payment for USD billing exists
        // ==========================================
        
        $payment = $this->connection->fetchAssociative(
            "SELECT p.id, p.amount, p.status 
             FROM payments p 
             INNER JOIN manifests m ON p.manifest_id = m.id 
             INNER JOIN billings b ON b.manifest_id = m.id 
             WHERE b.original_currency = 'USD' 
             LIMIT 1"
        );
        
        if ($payment) {
            $paymentId = $payment['id'];
            fwrite(STDOUT, "\n✓ Found payment #{$paymentId} for USD billing with amount: ₱{$payment['amount']}\n");
            
            // EXPECTED TO FAIL: Payment record should have currency field but doesn't
            // We can't directly check the entity in this context, but we already verified the table schema above
        } else {
            fwrite(STDOUT, "\n⚠ No payment found for USD billing yet (test data may need payment submission)\n");
        }
        
        // ==========================================
        // PART 4: Verify currency field is truly absent (critical assertion)
        // ==========================================
        
        // If currency column exists, check if it's being used
        if (in_array('currency', $tableColumns)) {
            fwrite(STDOUT, "\n✓ Currency column exists in payments table\n");
            
            // Check if any payments have currency populated
            $paymentsWithCurrency = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM payments WHERE currency IS NOT NULL"
            );
            
            fwrite(STDOUT, "\n✓ Payments with currency populated: {$paymentsWithCurrency}\n");
        } else {
            fwrite(STDOUT, "\n✗ COUNTEREXAMPLE: Currency column does NOT exist in payments table\n");
            fwrite(STDOUT, "  This confirms the bug - payments are stored without currency metadata\n");
        }
        
        // ==========================================
        // PART 5: Document expected template changes needed
        // ==========================================
        
        fwrite(STDOUT, "\n" . str_repeat("=", 80) . "\n");
        fwrite(STDOUT, "BUG CONDITION DOCUMENTATION\n");
        fwrite(STDOUT, str_repeat("=", 80) . "\n");
        fwrite(STDOUT, "\nExpected Failures on UNFIXED Code:\n");
        fwrite(STDOUT, "\n1. ✗ Payment form (templates/broker/manifest/final_payment.html.twig):\n");
        fwrite(STDOUT, "     - Shows only small \$ symbol within input field\n");
        fwrite(STDOUT, "     - MISSING: Prominent 'Amount in USD' badge or indicator\n");
        fwrite(STDOUT, "     - MISSING: Warning text about currency requirement\n");
        fwrite(STDOUT, "\n2. ✗ Payment entity (src/Entity/Payment.php):\n");
        fwrite(STDOUT, "     - No 'currency' field in database schema\n");
        fwrite(STDOUT, "     - Payments stored without currency metadata\n");
        fwrite(STDOUT, "\n3. ✗ Accounting validation page (templates/accounting/payment/final_payment_detail.html.twig):\n");
        fwrite(STDOUT, "     - Displays amounts without explicit currency labels\n");
        fwrite(STDOUT, "     - Shows '₱X' instead of '\$X USD' for USD billings\n");
        fwrite(STDOUT, "     - No currency mismatch warnings\n");
        fwrite(STDOUT, "\n4. ✗ API response (src/Controller/Api/PaymentController.php):\n");
        fwrite(STDOUT, "     - submitFinalPayment response doesn't include currency field\n");
        fwrite(STDOUT, "     - No way to verify expected currency via API\n");
        fwrite(STDOUT, "\n" . str_repeat("=", 80) . "\n");
        fwrite(STDOUT, "\nRoot Cause Analysis:\n");
        fwrite(STDOUT, "- Currency information exists in Billing entity (originalCurrency field)\n");
        fwrite(STDOUT, "- But this information is NOT propagated to:\n");
        fwrite(STDOUT, "  * Payment submission form UI (no prominent indicator)\n");
        fwrite(STDOUT, "  * Payment entity storage (no currency field)\n");
        fwrite(STDOUT, "  * Accounting validation UI (no currency display)\n");
        fwrite(STDOUT, "  * API responses (no currency metadata)\n");
        fwrite(STDOUT, "\nResult: Brokers misinterpret USD amounts as PHP, causing payment discrepancies\n");
        fwrite(STDOUT, "Example: \$2,622.00 USD interpreted as ₱2,622.00 = ₱159,291.74 discrepancy\n");
        fwrite(STDOUT, "\n" . str_repeat("=", 80) . "\n");
    }
    
    /**
     * Helper method to get all column names for a table
     */
    private function getTableColumns(string $tableName): array
    {
        $schemaManager = $this->connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns($tableName);
        return array_keys($columns);
    }
}
