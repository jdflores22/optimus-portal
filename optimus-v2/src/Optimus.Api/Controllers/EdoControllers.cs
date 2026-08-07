using System.Security.Claims;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Optimus.Api.Security;
using Optimus.Application.Cargo.Interfaces;
using Optimus.Application.Edo;
using Optimus.Application.Edo.Dtos;
using Optimus.Application.Edo.Interfaces;
using Optimus.Application.Security;
using Optimus.Domain.Enums;
using Optimus.Shared.Constants;

namespace Optimus.Api.Controllers;

[ApiController]
[Route("api/edo")]
[Authorize]
public class EdoController : ControllerBase
{
    private readonly IEdoService _edo;
    private readonly IEdoPaymentService _payments;
    private readonly IDocumentStore _docs;
    private readonly IResourceAuthorizationService _access;

    public EdoController(IEdoService edo, IEdoPaymentService payments, IDocumentStore docs, IResourceAuthorizationService access)
    {
        _edo = edo;
        _payments = payments;
        _docs = docs;
        _access = access;
    }

    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
    private string Role => User.FindFirstValue(ClaimTypes.Role)!;

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<EdoDto>>> List(
        [FromQuery] Guid? manifestId,
        [FromQuery] string? status,
        CancellationToken ct)
    {
        Guid? brokerId = Role == "Broker" ? UserId : null;
        Guid? consigneeId = Role == "Consignee" ? UserId : null;
        return Ok(await _edo.ListAsync(manifestId, status, brokerId, consigneeId, ct));
    }

    [HttpGet("release-queue")]
    [Authorize(Policy = "EdoRelease")]
    public async Task<ActionResult<EdoReleaseQueueDto>> ReleaseQueue(CancellationToken ct)
    {
        Guid? shippingLineId = null;
        if (Role == AppRoles.SlStaff &&
            Guid.TryParse(User.FindFirstValue("shipping_line_id"), out var claimLineId))
        {
            shippingLineId = claimLineId;
        }

        return Ok(await _edo.ListReleaseQueueAsync(shippingLineId, ct));
    }

    [HttpGet("release-records")]
    [Authorize(Policy = "EdoRelease")]
    public async Task<ActionResult<IReadOnlyList<EdoReleaseRecordDto>>> ReleaseRecords(CancellationToken ct)
    {
        Guid? shippingLineId = null;
        if (Role == AppRoles.SlStaff &&
            Guid.TryParse(User.FindFirstValue("shipping_line_id"), out var claimLineId))
        {
            shippingLineId = claimLineId;
        }

        return Ok(await _edo.ListReleaseRecordsAsync(shippingLineId, ct));
    }

    [HttpGet("generation-queue")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<EdoGenerationQueueDto>> GenerationQueue(CancellationToken ct)
    {
        Guid? shippingLineId = null;
        if (Guid.TryParse(User.FindFirstValue("shipping_line_id"), out var claimLineId))
        {
            shippingLineId = claimLineId;
        }

        return Ok(await _edo.ListGenerationQueueAsync(shippingLineId, ct));
    }

    [HttpGet("{id:guid}")]
    public async Task<ActionResult<EdoDto>> Get(Guid id, CancellationToken ct)
    {
        await _access.EnsureEdoAccessAsync(id, UserId, Role, ct);
        return Ok(await _edo.GetAsync(id, ct));
    }

    [HttpGet("{id:guid}/download")]
    public async Task<IActionResult> DownloadPdf(Guid id, CancellationToken ct)
    {
        await _access.EnsureEdoAccessAsync(id, UserId, Role, ct);
        var edo = await _edo.GetAsync(id, ct);
        EdoDownloadPolicy.EnsureCanDownload(Role, Enum.Parse<EdoStatus>(edo.Status, true));
        return ServeUpload(edo.PdfPath, $"{edo.EdoNumber}.pdf", "application/pdf");
    }

    [HttpGet("{id:guid}/qr")]
    public async Task<IActionResult> DownloadQr(Guid id, CancellationToken ct)
    {
        await _access.EnsureEdoAccessAsync(id, UserId, Role, ct);
        var edo = await _edo.GetAsync(id, ct);
        EdoDownloadPolicy.EnsureCanDownload(Role, Enum.Parse<EdoStatus>(edo.Status, true));
        return ServeUpload(edo.QrImagePath, $"{edo.EdoNumber}-qr.png", "image/png");
    }

    private IActionResult ServeUpload(string? webPath, string downloadName, string contentType)
    {
        var fullPath = ResolveUploadPath(webPath);
        if (fullPath is null || !System.IO.File.Exists(fullPath))
        {
            return NotFound(new { message = "File not found." });
        }

        return PhysicalFile(fullPath, contentType, downloadName);
    }

    private static string? ResolveUploadPath(string? webPath)
    {
        if (string.IsNullOrWhiteSpace(webPath)) return null;

        var normalized = webPath.Trim().Replace('/', Path.DirectorySeparatorChar).TrimStart(Path.DirectorySeparatorChar);
        var candidates = new[]
        {
            Path.Combine(Directory.GetCurrentDirectory(), "wwwroot", normalized),
            Path.Combine(Directory.GetCurrentDirectory(), normalized),
        };

        return candidates.FirstOrDefault(System.IO.File.Exists);
    }

    [HttpPost("generate")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<EdoDto>> Generate([FromBody] GenerateEdoRequest request, CancellationToken ct)
        => Ok(await _edo.GenerateAsync(request, UserId, Role, ct));

    [HttpPost("batch")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<GenerationSessionDto>> Batch([FromBody] BatchGenerateEdoRequest request, CancellationToken ct)
        => Ok(await _edo.BatchGenerateAsync(request, UserId, Role, ct));

    [HttpGet("sessions/{sessionId:guid}")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<GenerationSessionDto>> Session(Guid sessionId, CancellationToken ct)
    {
        var session = await _edo.GetSessionAsync(sessionId, ct);
        return session is null ? NotFound() : Ok(session);
    }

    [HttpPost("{id:guid}/release")]
    [Authorize(Policy = "EdoRelease")]
    public async Task<ActionResult<EdoDto>> Release(Guid id, [FromBody] ReleaseEdoRequest request, CancellationToken ct)
        => Ok(await _edo.ReleaseAsync(id, request, UserId, Role, ct));

    [HttpPost("{id:guid}/unlock")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<EdoDto>> Unlock(Guid id, [FromBody] UnlockEdoRequest request, CancellationToken ct)
        => Ok(await _edo.UnlockAsync(id, request, UserId, Role, ct));

    [HttpPost("process-expirations")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<object>> ProcessExpirations(CancellationToken ct)
    {
        var count = await _edo.ProcessExpirationsAsync(ct);
        return Ok(new { expiredCount = count });
    }

    [HttpPost("{id:guid}/payments")]
    [Authorize(Policy = "BrokerOrConsignee")]
    public async Task<ActionResult<EdoPaymentDto>> SubmitPayment(
        Guid id,
        [FromForm] decimal amount,
        [FromForm] string currency,
        IFormFile? receipt,
        CancellationToken ct)
    {
        string? path = null;
        if (receipt is not null)
        {
            UploadGuard.Validate(receipt, ".pdf", ".png", ".jpg", ".jpeg");
            await using var stream = receipt.OpenReadStream();
            path = await _docs.SaveAsync("edo-receipts", receipt.FileName, stream, ct);
        }

        await _access.EnsureEdoAccessAsync(id, UserId, Role, ct);
        return Ok(await _payments.SubmitAsync(id, new SubmitEdoPaymentRequest(amount, currency), path, UserId, Role, ct));
    }
}

[ApiController]
[Route("api/edo-payments")]
[Authorize]
public class EdoPaymentsController : ControllerBase
{
    private readonly IEdoPaymentService _payments;

    public EdoPaymentsController(IEdoPaymentService payments) => _payments = payments;

    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
    private string Role => User.FindFirstValue(ClaimTypes.Role)!;

    [HttpGet("pending")]
    [Authorize(Policy = "EdoPaymentAdmin")]
    public async Task<ActionResult<IReadOnlyList<EdoPaymentDto>>> Pending(CancellationToken ct)
        => Ok(await _payments.ListPendingAsync(ct));

    [HttpGet("reviewed")]
    [Authorize(Policy = "EdoPaymentAdmin")]
    public async Task<ActionResult<IReadOnlyList<EdoPaymentDto>>> Reviewed(CancellationToken ct)
        => Ok(await _payments.ListReviewedAsync(ct));

    [HttpGet("{id:guid}")]
    [Authorize(Policy = "EdoPaymentAdmin")]
    public async Task<ActionResult<EdoPaymentDto>> Get(Guid id, CancellationToken ct)
        => Ok(await _payments.GetAsync(id, ct));

    [HttpGet("{id:guid}/receipt")]
    [Authorize(Policy = "EdoPaymentAdmin")]
    public async Task<IActionResult> Receipt(Guid id, CancellationToken ct)
    {
        var payment = await _payments.GetAsync(id, ct);
        if (string.IsNullOrWhiteSpace(payment.ReceiptFilePath))
        {
            return NotFound(new { message = "Receipt not found." });
        }

        var fullPath = ResolveUploadPath(payment.ReceiptFilePath);
        if (fullPath is null || !System.IO.File.Exists(fullPath))
        {
            return NotFound(new { message = "Receipt file not found." });
        }

        var ext = Path.GetExtension(fullPath).ToLowerInvariant();
        var contentType = ext switch
        {
            ".pdf" => "application/pdf",
            ".png" => "image/png",
            ".jpg" or ".jpeg" => "image/jpeg",
            _ => "application/octet-stream"
        };

        return PhysicalFile(fullPath, contentType);
    }

    [HttpPost("{id:guid}/validate")]
    [Authorize(Policy = "EdoPaymentAdmin")]
    public async Task<ActionResult<EdoPaymentDto>> Validate(Guid id, [FromBody] ValidateEdoPaymentRequest request, CancellationToken ct)
        => Ok(await _payments.ValidateAsync(id, request, UserId, Role, ct));

    private static string? ResolveUploadPath(string? webPath)
    {
        if (string.IsNullOrWhiteSpace(webPath)) return null;

        var normalized = webPath.Trim().Replace('/', Path.DirectorySeparatorChar).TrimStart(Path.DirectorySeparatorChar);
        var candidates = new[]
        {
            Path.Combine(Directory.GetCurrentDirectory(), "wwwroot", normalized),
            Path.Combine(Directory.GetCurrentDirectory(), normalized),
        };

        return candidates.FirstOrDefault(System.IO.File.Exists);
    }
}

[ApiController]
[Route("api/edo-renewals")]
[Authorize]
public class EdoRenewalsController : ControllerBase
{
    private readonly IEdoRenewalService _renewals;

    public EdoRenewalsController(IEdoRenewalService renewals) => _renewals = renewals;

    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
    private string Role => User.FindFirstValue(ClaimTypes.Role)!;

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<RenewalDto>>> List(CancellationToken ct)
        => Ok(await _renewals.ListAsync(ct));

    [HttpPost]
    [Authorize(Policy = "BrokerOrConsignee")]
    public async Task<ActionResult<RenewalDto>> Create([FromBody] CreateRenewalRequest request, CancellationToken ct)
        => Ok(await _renewals.RequestAsync(request, UserId, Role, ct));

    [HttpPost("{id:guid}/review")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<RenewalDto>> Review(Guid id, [FromBody] ReviewRenewalRequest request, CancellationToken ct)
        => Ok(await _renewals.ReviewAsync(id, request, UserId, Role, ct));

    [HttpPost("{id:guid}/verify-payment")]
    [Authorize(Policy = "Accounting")]
    public async Task<ActionResult<RenewalDto>> VerifyPayment(Guid id, CancellationToken ct)
        => Ok(await _renewals.MarkPaymentVerifiedAsync(id, UserId, Role, ct));

    [HttpPost("{id:guid}/generate")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<EdoDto>> Generate(Guid id, CancellationToken ct)
        => Ok(await _renewals.GenerateRenewedAsync(id, UserId, Role, ct));
}

[ApiController]
[Route("api/verify")]
[AllowAnonymous]
public class DocumentVerifyController : ControllerBase
{
    private readonly IDocumentVerificationService _verify;

    public DocumentVerifyController(IDocumentVerificationService verify) => _verify = verify;

    [HttpGet("document/{token}")]
    public async Task<ActionResult<DocumentVerifyDto>> Verify(string token, CancellationToken ct)
        => Ok(await _verify.VerifyAsync(token, ct));
}
