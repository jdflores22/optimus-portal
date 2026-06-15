<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\DompdfFactory;

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>OPTIMUS End-to-End Process Guide</title>
<style>
    @page { margin: 22mm 18mm 24mm 18mm; }
    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 10pt;
        color: #1a1a2e;
        line-height: 1.45;
    }
    .cover {
        text-align: center;
        padding-top: 70mm;
        page-break-after: always;
    }
    .cover h1 {
        font-size: 26pt;
        color: #0f4c81;
        margin-bottom: 8px;
    }
    .cover .subtitle {
        font-size: 13pt;
        color: #4a5568;
        margin-bottom: 40px;
    }
    .cover .meta {
        font-size: 9pt;
        color: #718096;
    }
    h2 {
        font-size: 14pt;
        color: #0f4c81;
        border-bottom: 2px solid #0f4c81;
        padding-bottom: 4px;
        margin-top: 18px;
        margin-bottom: 10px;
        page-break-after: avoid;
    }
    h3 {
        font-size: 11pt;
        color: #2d3748;
        margin-top: 14px;
        margin-bottom: 6px;
        page-break-after: avoid;
    }
    p { margin: 0 0 8px 0; }
    ul, ol { margin: 0 0 10px 0; padding-left: 18px; }
    li { margin-bottom: 4px; }
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 8px 0 14px 0;
        font-size: 9pt;
        page-break-inside: avoid;
    }
    th {
        background: #0f4c81;
        color: #fff;
        text-align: left;
        padding: 6px 8px;
        font-weight: bold;
    }
    td {
        border: 1px solid #cbd5e0;
        padding: 5px 8px;
        vertical-align: top;
    }
    tr:nth-child(even) td { background: #f7fafc; }
    .note {
        background: #ebf8ff;
        border-left: 4px solid #3182ce;
        padding: 8px 10px;
        margin: 10px 0;
        font-size: 9pt;
    }
    .phase-box {
        background: #f0fff4;
        border: 1px solid #9ae6b4;
        padding: 10px 12px;
        margin: 10px 0 14px 0;
        border-radius: 4px;
    }
    .workflow-order {
        background: #fffaf0;
        border: 1px solid #f6ad55;
        padding: 10px 12px;
        margin: 10px 0 14px 0;
        font-weight: bold;
        text-align: center;
        font-size: 9.5pt;
    }
    .toc { page-break-after: always; }
    .toc li { margin-bottom: 6px; }
    .developer {
        margin-top: 36px;
        padding-top: 14px;
        border-top: 1px solid #e2e8f0;
        font-size: 9pt;
        color: #4a5568;
    }
    .developer strong {
        display: block;
        font-size: 10pt;
        color: #0f4c81;
        margin-bottom: 2px;
    }
    .footer-note {
        font-size: 8pt;
        color: #718096;
        margin-top: 20px;
        border-top: 1px solid #e2e8f0;
        padding-top: 8px;
    }
    .page-break { page-break-before: always; }
    code {
        font-family: DejaVu Sans Mono, monospace;
        font-size: 8.5pt;
        background: #edf2f7;
        padding: 1px 4px;
    }
</style>
</head>
<body>

<div class="cover">
    <h1>OPTIMUS Portal</h1>
    <div class="subtitle">End-to-End Process Guide</div>
    <p>From consignee registration and broker linking<br>through accreditation and manifest workflow to eDO release</p>
    <div class="meta" style="margin-top: 50px;">
        Shipping Line Management System<br>
    </div>
    <div class="developer">
        <strong>TNSDS</strong>
        TRANS-NET SOFTWARE DEVELOPMENT SERVICES
    </div>
</div>

<div class="toc">
    <h2>Contents</h2>
    <ol>
        <li>Overview</li>
        <li>Phase 1 — User Onboarding
            <ul>
                <li>Consignee registration</li>
                <li>Linking consignee and broker</li>
                <li>Accreditation (per shipping line)</li>
            </ul>
        </li>
        <li>Phase 2 — Manifest Workflow
            <ul>
                <li>Workflow states</li>
                <li>Steps 1–8 (NOA to eDO release)</li>
            </ul>
        </li>
        <li>Role responsibilities</li>
        <li>Happy-path checklist</li>
    </ol>
</div>

<h2>1. Overview</h2>
<p>OPTIMUS manages shipping-line operations in two major phases before cargo can be released:</p>
<div class="phase-box">
    <strong>Phase 1 — Onboarding:</strong> Consignee and broker registration, email verification, broker linking via referral codes, and per-shipping-line accreditation.<br><br>
    <strong>Phase 2 — Manifest workflow:</strong> Notice of Arrival (NOA) creation through billing, payment validation, eDO generation, and final eDO release.
</div>
<p>Only after accreditation is fully approved can brokers and consignees operate on a shipping line's manifests, payments, and releases.</p>

<h2>2. Phase 1 — User Onboarding</h2>

<h3>2.1 Consignee registration</h3>
<table>
    <tr><th>Step</th><th>Actor</th><th>Action</th><th>URL</th></tr>
    <tr><td>1</td><td>Consignee</td><td>Register (email, password, business name)</td><td><code>/register/consignee</code></td></tr>
    <tr><td>2</td><td>System</td><td>Sends verification email; status → EMAIL_UNVERIFIED</td><td>—</td></tr>
    <tr><td>3</td><td>Consignee</td><td>Clicks verification link in email</td><td><code>/verify-email/{token}</code></td></tr>
    <tr><td>4</td><td>System</td><td>Email verified; account status → PENDING</td><td>—</td></tr>
    <tr><td>5</td><td>Consignee</td><td>Logs in (email must be verified)</td><td><code>/login</code></td></tr>
</table>
<div class="note">Consignees can log in while status is PENDING, but full shipping-line access requires completed accreditation and final admin approval.</div>

<h3>2.2 Link consignee ↔ broker</h3>
<p><strong>Primary method — referral code:</strong></p>
<ol>
    <li>Consignee generates a referral code at <code>/consignee/referral-codes</code></li>
    <li>Consignee shares the code with the broker</li>
    <li>Broker registers at <code>/register/broker</code> and enters the referral code</li>
    <li>System creates an active Consignee–Broker relationship automatically</li>
</ol>
<p>Consignees can view linked brokers at <code>/consignee/brokers</code>. Brokers switch between consignee workspaces using the broker workspace switcher.</p>
<div class="note"><strong>Accreditation gate:</strong> Consignees must have at least one active broker link before submitting accreditation.</div>

<h3>2.3 Accreditation (per shipping line)</h3>
<p>Both consignees and brokers apply separately for each shipping line they wish to use.</p>
<table>
    <tr><th>Step</th><th>Actor</th><th>Action</th><th>URL</th></tr>
    <tr><td>1</td><td>Applicant</td><td>Open accreditation list</td><td><code>/accreditation</code></td></tr>
    <tr><td>2</td><td>Applicant</td><td>Submit dynamic form (documents, address, etc.)</td><td><code>/accreditation/submit/{shippingLineId}</code></td></tr>
    <tr><td>3</td><td>System</td><td>Submission status → PENDING</td><td>—</td></tr>
    <tr><td>4</td><td>Evaluator</td><td>Reviews application</td><td><code>/evaluator/application/{id}</code></td></tr>
    <tr><td>5</td><td>Evaluator</td><td>Decision: Approved / Denied / Rejected / Compliance Required</td><td>—</td></tr>
    <tr><td>6</td><td>Applicant</td><td>If compliance required: resubmit only flagged fields</td><td><code>/accreditation/submit/{id}</code></td></tr>
    <tr><td>7</td><td>Shipping Lines Admin</td><td>Final approval (after evaluator approved)</td><td><code>/admin/application/{id}</code></td></tr>
    <tr><td>8</td><td>System</td><td>Account → APPROVED; accredited for shipping line</td><td>—</td></tr>
</table>

<div class="page-break"></div>

<h2>3. Phase 2 — Manifest Workflow</h2>

<div class="workflow-order">
    Workflow Order:<br>
    1. Create NOA → 2. Generate BL → 3. Upload BL → 4. Generate Billing →
    5. Submit Payment → 6. Generate eDO → 7. Release eDO
</div>
<p>The manifest workflow list is managed by SL Staff at <code>/manifest-workflow</code>.</p>

<h3>3.1 Workflow states</h3>
<table>
    <tr><th>#</th><th>State</th><th>Display name</th><th>Typical actor</th></tr>
    <tr><td>0</td><td><code>manifest_uploaded</code></td><td>Manifest uploaded</td><td>Legacy / bulk import</td></tr>
    <tr><td>1</td><td><code>noa_generated</code></td><td>NOA generated</td><td>SL Staff</td></tr>
    <tr><td>2</td><td><code>bl_generated</code></td><td>BL generated</td><td>SL Staff</td></tr>
    <tr><td>3</td><td><code>bl_uploaded</code></td><td>BL uploaded</td><td>Broker</td></tr>
    <tr><td>4</td><td><code>billing_generated</code></td><td>Billing generated</td><td>Accounting</td></tr>
    <tr><td>5</td><td><code>payment_submitted</code></td><td>Payment submitted</td><td>Broker</td></tr>
    <tr><td>6</td><td><code>payment_verified</code></td><td>Payment verified</td><td>Accounting</td></tr>
    <tr><td>7</td><td><code>edo_generated</code></td><td>eDO generated</td><td>SL Staff</td></tr>
    <tr><td>8</td><td><code>edo_released</code></td><td>eDO released</td><td>System Admin</td></tr>
</table>

<h3>3.2 Step 1 — Create NOA</h3>
<table>
    <tr><th>Item</th><th>Detail</th></tr>
    <tr><td>Actor</td><td>SL Staff (<code>ROLE_SL_STAFF</code>)</td></tr>
    <tr><td>List</td><td><code>/manifest-workflow</code></td></tr>
    <tr><td>Create (single)</td><td><code>/manifest-workflow/create</code></td></tr>
    <tr><td>Bulk import</td><td><code>/manifest-workflow/bulk-import</code> (CSV/XLSX)</td></tr>
</table>
<p>NOA includes vessel, ETA, containers, <strong>port_location</strong> (discharge terminal for laden containers), CY info for empties, and consignee. Manifest is linked; workflow moves to <code>noa_generated</code>. SL Staff may declare consignee at <code>/manifest-workflow/{id}/declare-consignee</code>.</p>

<h3>3.3 Step 2 — Generate BL</h3>
<table>
    <tr><th>Item</th><th>Detail</th></tr>
    <tr><td>Actor</td><td>SL Staff</td></tr>
    <tr><td>Route</td><td><code>/manifest-workflow/noa/{id}/generate-manifest</code></td></tr>
    <tr><td>Result</td><td>Manifest/BL PDF generated; workflow → <code>bl_generated</code></td></tr>
</table>

<h3>3.4 Step 3 — Upload BL</h3>
<table>
    <tr><th>Item</th><th>Detail</th></tr>
    <tr><td>Actor</td><td>Broker (assigned manifest / consignee workspace)</td></tr>
    <tr><td>Route</td><td><code>/broker/manifest/{id}/upload-bl</code></td></tr>
    <tr><td>Result</td><td>Signed BL copy uploaded; workflow → <code>bl_uploaded</code></td></tr>
</table>

<h3>3.5 Step 4 — Generate billing</h3>
<table>
    <tr><th>Item</th><th>Detail</th></tr>
    <tr><td>Actor</td><td>Accounting (<code>ROLE_ACCOUNTING</code>)</td></tr>
    <tr><td>Route</td><td><code>/manifest-workflow/{id}/generate-billing</code></td></tr>
    <tr><td>Result</td><td>Charges applied; billing document created; workflow → <code>billing_generated</code></td></tr>
</table>

<h3>3.6 Step 5 — Submit payment</h3>
<table>
    <tr><th>Item</th><th>Detail</th></tr>
    <tr><td>Actor</td><td>Broker (accreditation must be approved)</td></tr>
    <tr><td>Action</td><td>Upload payment receipt matching billing amount</td></tr>
    <tr><td>Result</td><td>Payment → PENDING_VALIDATION; workflow → <code>payment_submitted</code></td></tr>
</table>

<h3>3.7 Step 6 — Validate payment</h3>
<table>
    <tr><th>Item</th><th>Detail</th></tr>
    <tr><td>Actor</td><td>Accounting</td></tr>
    <tr><td>Route</td><td><code>/accounting/payment/final</code></td></tr>
    <tr><td>Result</td><td>On approve: workflow → <code>payment_verified</code>; manifest eligible for eDO</td></tr>
</table>

<h3>3.8 Step 7 — Generate eDO</h3>
<table>
    <tr><th>Item</th><th>Detail</th></tr>
    <tr><td>Actor</td><td>SL Staff</td></tr>
    <tr><td>Route</td><td><code>/sl-staff/edo-generation</code></td></tr>
    <tr><td>Requirements</td><td>Workflow = payment_verified; final payment verified; containers linked</td></tr>
    <tr><td>Result</td><td>eDO(s) per container; workflow → <code>edo_generated</code></td></tr>
</table>

<h3>3.9 Step 8 — Release eDO</h3>
<table>
    <tr><th>Item</th><th>Detail</th></tr>
    <tr><td>Actor</td><td>System Admin (<code>ROLE_SYSTEM_ADMIN</code>)</td></tr>
    <tr><td>Route</td><td><code>/admin/edo-release/queue</code></td></tr>
    <tr><td>Result</td><td>eDO released; workflow → <code>edo_released</code> (complete)</td></tr>
</table>

<div class="page-break"></div>

<h2>4. Role responsibilities</h2>
<table>
    <tr><th>Role</th><th>Responsibilities</th></tr>
    <tr><td>Consignee</td><td>Register, link brokers (referral codes), apply accreditation, view shipments</td></tr>
    <tr><td>Broker</td><td>Register with referral code, apply accreditation, upload BL, submit payment, receive eDO</td></tr>
    <tr><td>Evaluator</td><td>First-line accreditation review and compliance requests</td></tr>
    <tr><td>Shipping Lines Admin</td><td>Final accreditation approval; outbound/repositioning oversight</td></tr>
    <tr><td>SL Staff</td><td>Create NOA, generate BL, generate eDO</td></tr>
    <tr><td>Accounting</td><td>Generate billing, validate final payment</td></tr>
    <tr><td>System Admin</td><td>Release eDO; system-wide administration</td></tr>
</table>

<h2>5. Happy-path checklist</h2>
<ol>
    <li>Consignee registers → verifies email → generates referral code</li>
    <li>Broker registers with code → both apply accreditation for Shipping Line X</li>
    <li>Evaluator approves → Shipping Lines Admin final-approves both accounts</li>
    <li>SL Staff creates or bulk-imports NOA with <code>port_location</code> set</li>
    <li>SL Staff generates BL → Broker uploads signed BL copy</li>
    <li>Accounting generates billing → Broker submits payment → Accounting validates</li>
    <li>SL Staff generates eDO → System Admin releases eDO</li>
    <li>Workflow complete; containers tracked on port/CY dashboards (Inbound → At Port → Outbound)</li>
</ol>

<h3>Related operational modules</h3>
<ul>
    <li><strong>Shipping Admin dashboard</strong> — Port/terminal and CY utilization; outbound repositioning requests</li>
    <li><strong>Outbound requests</strong> — CY → port export/repositioning (<code>/shipping-admin/repositioning</code>)</li>
    <li><strong>Broker workspace</strong> — Broker operates per linked consignee</li>
</ul>

<div class="footer-note">
    OPTIMUS Portal — End-to-End Process Guide. Developed by TNSDS — TRANS-NET SOFTWARE DEVELOPMENT SERVICES.<br>
    This document reflects the application workflow as implemented in the codebase.
    For the latest UI routes, refer to the in-app workflow order banner on <code>/manifest-workflow</code>.
</div>

</body>
</html>
HTML;


$outputDir = dirname(__DIR__) . '/public/guides';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$outputPath = $outputDir . '/OPTIMUS-End-to-End-Process-Guide.pdf';

$dompdf = DompdfFactory::create();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

file_put_contents($outputPath, $dompdf->output());

echo "PDF generated: {$outputPath}\n";
echo "Size: " . number_format(filesize($outputPath) / 1024, 1) . " KB\n";
