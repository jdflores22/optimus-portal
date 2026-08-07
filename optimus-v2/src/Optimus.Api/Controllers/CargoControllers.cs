using System.Security.Claims;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Optimus.Api.Security;
using Optimus.Application.Cargo.Dtos;
using Optimus.Application.Cargo.Interfaces;
using Optimus.Application.Security;
using Optimus.Domain.Enums;

namespace Optimus.Api.Controllers;

[ApiController]
[Route("api/manifests")]
[Authorize]
public class ManifestsController : ControllerBase
{
    private readonly IManifestWorkflowService _manifests;
    private readonly IDocumentStore _docs;
    private readonly IResourceAuthorizationService _access;

    public ManifestsController(IManifestWorkflowService manifests, IDocumentStore docs, IResourceAuthorizationService access)
    {
        _manifests = manifests;
        _docs = docs;
        _access = access;
    }

    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
    private string Role => User.FindFirstValue(ClaimTypes.Role)!;

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<ManifestDto>>> List(
        [FromQuery] Guid? shippingLineId,
        [FromQuery] Guid? brokerId,
        [FromQuery] Guid? consigneeId,
        CancellationToken ct)
    {
        if (Role == "Broker") brokerId ??= UserId;
        if (Role == "Consignee") consigneeId ??= UserId;
        return Ok(await _manifests.ListAsync(shippingLineId, brokerId, consigneeId, ct));
    }

    [HttpGet("accredited-consignees")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<IReadOnlyList<AccreditedConsigneeOptionDto>>> AccreditedConsignees(CancellationToken ct)
        => Ok(await _manifests.ListAccreditedConsigneesAsync(UserId, Role, ct));

    [HttpGet("consignees/{consigneeId:guid}/brokers")]
    [Authorize]
    public async Task<ActionResult<IReadOnlyList<ConsigneeBrokerOptionDto>>> ConsigneeBrokers(Guid consigneeId, CancellationToken ct)
        => Ok(await _manifests.ListBrokersForConsigneeAsync(consigneeId, UserId, Role, ct));

    [HttpGet("{id:guid}")]
    public async Task<ActionResult<ManifestDto>> Get(Guid id, CancellationToken ct)
    {
        await _access.EnsureManifestAccessAsync(id, UserId, Role, ct);
        return Ok(await _manifests.GetAsync(id, ct));
    }

    [HttpGet("{id:guid}/history")]
    public async Task<ActionResult<IReadOnlyList<WorkflowHistoryDto>>> History(Guid id, CancellationToken ct)
    {
        await _access.EnsureManifestAccessAsync(id, UserId, Role, ct);
        return Ok(await _manifests.GetHistoryAsync(id, ct));
    }

    [HttpPost]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<ManifestDto>> Create([FromBody] CreateManifestRequest request, CancellationToken ct)
        => Ok(await _manifests.CreateAsync(request, UserId, Role, ct));

    [HttpPost("{id:guid}/declare-consignee")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<ManifestDto>> DeclareConsignee(Guid id, [FromBody] DeclareConsigneeRequest request, CancellationToken ct)
        => Ok(await _manifests.DeclareConsigneeAsync(id, request, UserId, Role, ct));

    [HttpPost("{id:guid}/assign-broker")]
    [Authorize(Policy = "Consignee")]
    public async Task<ActionResult<ManifestDto>> AssignBroker(Guid id, [FromBody] AssignBrokerRequest request, CancellationToken ct)
    {
        await _access.EnsureManifestAccessAsync(id, UserId, Role, ct);
        return Ok(await _manifests.AssignBrokerAsync(id, request, UserId, Role, ct));
    }

    [HttpPost("{id:guid}/noa")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<ManifestDto>> GenerateNoa(Guid id, CancellationToken ct)
        => Ok(await _manifests.GenerateNoaAsync(id, UserId, Role, ct));

    [HttpPost("{id:guid}/bl/generate")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<ManifestDto>> GenerateBl(Guid id, [FromBody] GenerateBlRequest request, CancellationToken ct)
        => Ok(await _manifests.GenerateBlAsync(id, request, UserId, Role, ct));

    [HttpPost("{id:guid}/bl/upload")]
    [Authorize]
    public async Task<ActionResult<ManifestDto>> UploadBl(Guid id, IFormFile file, [FromForm] string? blNumber, CancellationToken ct)
    {
        await _access.EnsureManifestAccessAsync(id, UserId, Role, ct);
        UploadGuard.Validate(file, ".pdf", ".png", ".jpg", ".jpeg");
        await using var stream = file.OpenReadStream();
        var path = await _docs.SaveAsync("bl-uploads", file.FileName, stream, ct);
        return Ok(await _manifests.UploadBlAsync(id, path, blNumber, UserId, Role, ct));
    }

    [HttpPost("{id:guid}/billing")]
    [Authorize(Policy = "Accounting")]
    public async Task<ActionResult<ManifestDto>> GenerateBilling(Guid id, [FromBody] GenerateBillingRequest request, CancellationToken ct)
        => Ok(await _manifests.GenerateBillingAsync(id, request, UserId, Role, ct));

    [HttpPost("bulk-import")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<BulkImportResultDto>> BulkImport([FromForm] Guid shippingLineId, IFormFile file, CancellationToken ct)
    {
        UploadGuard.Validate(file, ".csv", ".txt");
        await using var stream = file.OpenReadStream();
        return Ok(await _manifests.BulkImportAsync(shippingLineId, file.FileName, stream, UserId, Role, ct));
    }

    [HttpGet("bulk-import/{jobId:guid}")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<BulkImportResultDto>> BulkImportStatus(Guid jobId, CancellationToken ct)
    {
        var job = await _manifests.GetBulkImportAsync(jobId, ct);
        return job is null ? NotFound() : Ok(job);
    }
}

[ApiController]
[Route("api/payments")]
[Authorize]
public class PaymentsController : ControllerBase
{
    private readonly IPaymentService _payments;
    private readonly IDocumentStore _docs;
    private readonly IResourceAuthorizationService _access;

    public PaymentsController(IPaymentService payments, IDocumentStore docs, IResourceAuthorizationService access)
    {
        _payments = payments;
        _docs = docs;
        _access = access;
    }

    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
    private string Role => User.FindFirstValue(ClaimTypes.Role)!;

    [HttpGet("pending")]
    [Authorize(Policy = "Accounting")]
    public async Task<ActionResult<IReadOnlyList<PaymentDto>>> Pending([FromQuery] PaymentType? type, CancellationToken ct)
        => Ok(await _payments.ListPendingAsync(type, ct));

    [HttpGet("final")]
    [Authorize(Policy = "Accounting")]
    public async Task<ActionResult<FinalPaymentListResponse>> ListFinal(
        [FromQuery] string? status,
        [FromQuery] int page = 1,
        [FromQuery] int limit = 20,
        CancellationToken ct = default)
        => Ok(await _payments.ListFinalPaymentsAsync(status, page, limit, ct));

    [HttpGet("{id:guid}")]
    [Authorize(Policy = "Accounting")]
    public async Task<ActionResult<PaymentDto>> Get(Guid id, CancellationToken ct)
        => Ok(await _payments.GetAsync(id, ct));

    [HttpGet("by-manifest/{manifestId:guid}")]
    public async Task<ActionResult<IReadOnlyList<PaymentDto>>> ByManifest(Guid manifestId, CancellationToken ct)
    {
        await _access.EnsureManifestAccessAsync(manifestId, UserId, Role, ct);
        return Ok(await _payments.ListByManifestAsync(manifestId, ct));
    }

    [HttpPost("manifest/{manifestId:guid}")]
    [Authorize(Policy = "BrokerOrConsignee")]
    public async Task<ActionResult<PaymentDto>> Submit(Guid manifestId, [FromForm] PaymentType paymentType, [FromForm] decimal amount, [FromForm] string currency, IFormFile? receipt, CancellationToken ct)
    {
        await _access.EnsureManifestAccessAsync(manifestId, UserId, Role, ct);
        string? receiptPath = null;
        if (receipt is not null)
        {
            if (paymentType == PaymentType.FinalPayment)
            {
                UploadGuard.Validate(receipt, ".pdf");
            }
            else
            {
                UploadGuard.Validate(receipt, ".pdf", ".png", ".jpg", ".jpeg");
            }

            await using var stream = receipt.OpenReadStream();
            receiptPath = await _docs.SaveAsync("payment-receipts", receipt.FileName, stream, ct);
        }

        return Ok(await _payments.SubmitAsync(manifestId, new SubmitPaymentRequest(paymentType, amount, currency), receiptPath, UserId, Role, ct));
    }

    [HttpPost("{id:guid}/validate")]
    [Authorize(Policy = "Accounting")]
    public async Task<ActionResult<PaymentDto>> Validate(Guid id, [FromBody] ValidatePaymentRequest request, CancellationToken ct)
        => Ok(await _payments.ValidateAsync(id, request, UserId, Role, ct));
}

[ApiController]
[Route("api/payment-fees")]
[Authorize]
public class PaymentFeesController : ControllerBase
{
    private readonly IPaymentFeeService _fees;
    private readonly IDocumentStore _docs;

    public PaymentFeesController(IPaymentFeeService fees, IDocumentStore docs)
    {
        _fees = fees;
        _docs = docs;
    }

    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<PaymentFeeDto>>> List(CancellationToken ct)
        => Ok(await _fees.ListAsync(ct));

    [HttpGet("active/{feeType}")]
    public async Task<ActionResult<PaymentFeeDto>> Active(string feeType, CancellationToken ct)
        => Ok(await _fees.GetActiveAsync(feeType, ct));

    [HttpPost]
    [Authorize(Policy = "SystemAdmin")]
    public async Task<ActionResult<PaymentFeeDto>> Upsert([FromForm] string feeType, [FromForm] decimal amount, IFormFile? qrCode, CancellationToken ct)
    {
        string? qrPath = null;
        if (qrCode is not null)
        {
            UploadGuard.Validate(qrCode, ".png", ".jpg", ".jpeg", ".webp", ".gif");
            await using var stream = qrCode.OpenReadStream();
            qrPath = await _docs.SaveAsync("fee-qr", qrCode.FileName, stream, ct);
        }

        return Ok(await _fees.UpsertAsync(new UpsertPaymentFeeRequest(feeType, amount), qrPath, UserId, ct));
    }
}

[ApiController]
[Route("api/exchange-rate")]
public class ExchangeRateController : ControllerBase
{
    private readonly IExchangeRateService _fx;

    public ExchangeRateController(IExchangeRateService fx) => _fx = fx;

    [HttpGet("usd-php")]
    [AllowAnonymous]
    public async Task<ActionResult<ExchangeRateDto>> UsdPhp(CancellationToken ct)
        => Ok(await _fx.GetUsdPhpAsync(ct));
}

[ApiController]
[Route("api/activity-logs")]
[Authorize(Policy = "StaffHierarchy")]
public class ActivityLogsController : ControllerBase
{
    private readonly IActivityLogService _activity;

    public ActivityLogsController(IActivityLogService activity) => _activity = activity;

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<ActivityLogDto>>> List([FromQuery] int take = 50, CancellationToken ct = default)
        => Ok(await _activity.ListRecentAsync(take, ct));
}
