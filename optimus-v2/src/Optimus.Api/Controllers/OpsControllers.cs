using System.Security.Claims;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Optimus.Api.Security;
using Optimus.Application.Cargo.Interfaces;
using Optimus.Application.Ops.Dtos;
using Optimus.Application.Ops.Interfaces;

namespace Optimus.Api.Controllers;

[ApiController]
[Route("api/forms")]
[Authorize]
public class FormsController : ControllerBase
{
    private readonly IFormBuilderService _forms;
    public FormsController(IFormBuilderService forms) => _forms = forms;
    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<FormConfigurationDto>>> List([FromQuery] string? type, CancellationToken ct)
        => Ok(await _forms.ListAsync(type, ct));

    [HttpGet("active/{type}")]
    public async Task<ActionResult<FormConfigurationDto>> Active(string type, CancellationToken ct)
    {
        var form = await _forms.GetActiveAsync(type, ct);
        return form is null ? NotFound() : Ok(form);
    }

    [HttpPost]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<FormConfigurationDto>> Create([FromBody] UpsertFormRequest request, CancellationToken ct)
        => Ok(await _forms.CreateAsync(request, UserId, ct));

    [HttpPut("{id:guid}/fields")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<FormConfigurationDto>> UpdateFields(Guid id, [FromBody] FormFieldsUpdateRequest request, CancellationToken ct)
        => Ok(await _forms.UpdateFieldsAsync(id, request, UserId, ct));

    [HttpPost("{id:guid}/publish")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<FormConfigurationDto>> Publish(Guid id, CancellationToken ct)
        => Ok(await _forms.PublishAsync(id, UserId, ct));

    [HttpPost("{id:guid}/activate")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<FormConfigurationDto>> Activate(Guid id, CancellationToken ct)
        => Ok(await _forms.ActivateAsync(id, UserId, ct));

    [HttpDelete("{id:guid}")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<IActionResult> Delete(Guid id, CancellationToken ct)
    {
        await _forms.DeleteAsync(id, UserId, ct);
        return Ok(new { message = "Form deleted." });
    }
}

[ApiController]
[Route("api/accreditation")]
[Authorize]
public class AccreditationController : ControllerBase
{
    private readonly IAccreditationService _sas;
    public AccreditationController(IAccreditationService sas) => _sas = sas;
    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
    private string Role => User.FindFirstValue(ClaimTypes.Role)!;

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<AccreditationDto>>> List([FromQuery] string? status, CancellationToken ct)
    {
        Guid? applicantId = Role is "Broker" or "Consignee" ? UserId : null;
        return Ok(await _sas.ListAsync(status, applicantId, ct));
    }

    [HttpGet("{id:guid}")]
    public async Task<ActionResult<AccreditationDto>> Get(Guid id, CancellationToken ct)
        => Ok(await _sas.GetAsync(id, ct));

    [HttpPost]
    [Authorize(Policy = "BrokerOrConsignee")]
    public async Task<ActionResult<AccreditationDto>> Submit([FromBody] SubmitAccreditationRequest request, CancellationToken ct)
        => Ok(await _sas.SubmitAsync(request, UserId, Role, ct));

    [HttpPost("{id:guid}/evaluator")]
    [Authorize(Policy = "Evaluator")]
    public async Task<ActionResult<AccreditationDto>> Evaluator(Guid id, [FromBody] EvaluatorActionRequest request, CancellationToken ct)
        => Ok(await _sas.EvaluatorActionAsync(id, request, UserId, Role, ct));

    [HttpPost("{id:guid}/final")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<AccreditationDto>> Final(Guid id, [FromBody] FinalApprovalRequest request, CancellationToken ct)
        => Ok(await _sas.FinalDecisionAsync(id, request, UserId, Role, ct));
}

[ApiController]
[Route("api/transfers")]
[Authorize]
public class TransfersController : ControllerBase
{
    private readonly IBrokerTransferService _transfers;
    private readonly IDocumentStore _docs;

    public TransfersController(IBrokerTransferService transfers, IDocumentStore docs)
    {
        _transfers = transfers;
        _docs = docs;
    }

    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
    private string Role => User.FindFirstValue(ClaimTypes.Role)!;

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<TransferDto>>> List([FromQuery] string? status, CancellationToken ct)
        => Ok(await _transfers.ListAsync(status, ct));

    [HttpPost]
    [Authorize(Policy = "Consignee")]
    public async Task<ActionResult<TransferDto>> Create(
        [FromForm] Guid manifestId,
        [FromForm] Guid newBrokerId,
        [FromForm] string reason,
        IFormFile? letter,
        CancellationToken ct)
    {
        string? path = null;
        if (letter is not null)
        {
            UploadGuard.Validate(letter, ".pdf", ".png", ".jpg", ".jpeg", ".doc", ".docx");
            await using var stream = letter.OpenReadStream();
            path = await _docs.SaveAsync("transfer-letters", letter.FileName, stream, ct);
        }

        return Ok(await _transfers.CreateAsync(new CreateTransferRequest(manifestId, newBrokerId, reason), path, UserId, Role, ct));
    }

    [HttpPost("{id:guid}/review")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<TransferDto>> Review(Guid id, [FromBody] ReviewTransferRequest request, CancellationToken ct)
        => Ok(await _transfers.ReviewAsync(id, request, UserId, Role, ct));
}

[ApiController]
[Route("api/appeals")]
[Authorize]
public class AppealsController : ControllerBase
{
    private readonly ISuspensionAppealService _appeals;
    private readonly IDocumentStore _docs;

    public AppealsController(ISuspensionAppealService appeals, IDocumentStore docs)
    {
        _appeals = appeals;
        _docs = docs;
    }

    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<AppealDto>>> List([FromQuery] string? status, CancellationToken ct)
        => Ok(await _appeals.ListAsync(status, ct));

    [HttpPost("suspend/{brokerId:guid}")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<IActionResult> Suspend(Guid brokerId, [FromBody] SuspendBrokerRequest request, CancellationToken ct)
    {
        await _appeals.SuspendBrokerAsync(brokerId, request, UserId, ct);
        return Ok(new { message = "Broker suspended." });
    }

    [HttpPost]
    [Authorize(Policy = "Broker")]
    public async Task<ActionResult<AppealDto>> Submit([FromForm] string appealLetter, IFormFile? attachment, CancellationToken ct)
    {
        string? attachments = null;
        if (attachment is not null)
        {
            UploadGuard.Validate(attachment, ".pdf", ".png", ".jpg", ".jpeg", ".doc", ".docx");
            await using var stream = attachment.OpenReadStream();
            var path = await _docs.SaveAsync("appeals", attachment.FileName, stream, ct);
            attachments = System.Text.Json.JsonSerializer.Serialize(new[] { path });
        }

        return Ok(await _appeals.SubmitAsync(new CreateAppealRequest(appealLetter), attachments, UserId, ct));
    }

    [HttpPost("{id:guid}/review")]
    [Authorize(Policy = "SystemAdmin")]
    public async Task<ActionResult<AppealDto>> Review(Guid id, [FromBody] ReviewAppealRequest request, CancellationToken ct)
        => Ok(await _appeals.ReviewAsync(id, request, UserId, ct));
}

[ApiController]
[Route("api/repositioning")]
[Authorize]
public class RepositioningController : ControllerBase
{
    private readonly IRepositioningService _repo;
    private readonly IDocumentStore _docs;

    public RepositioningController(IRepositioningService repo, IDocumentStore docs)
    {
        _repo = repo;
        _docs = docs;
    }

    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
    private string Role => User.FindFirstValue(ClaimTypes.Role)!;
    private Guid? ActiveShippingLineId =>
        Guid.TryParse(User.FindFirstValue("shipping_line_id"), out var id) ? id : null;

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<RepositioningDto>>> List([FromQuery] string? status, CancellationToken ct)
        => Ok(await _repo.ListAsync(status, ActiveShippingLineId, ct));

    [HttpGet("eligible-containers")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<IReadOnlyList<RepositioningEligibleContainerDto>>> Eligible(
        [FromQuery] Guid? sourceTerminalId,
        [FromQuery] string? search,
        CancellationToken ct)
        => Ok(await _repo.ListEligibleContainersAsync(ActiveShippingLineId, sourceTerminalId, search, ct));

    [HttpGet("{id:guid}")]
    public async Task<ActionResult<RepositioningDto>> Get(Guid id, CancellationToken ct)
        => Ok(await _repo.GetAsync(id, ActiveShippingLineId, ct));

    [HttpPost]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<RepositioningDto>> Create(
        [FromForm] Guid shippingLineId,
        [FromForm] string requestType,
        [FromForm] Guid sourceTerminalId,
        [FromForm] Guid destinationTerminalId,
        [FromForm] string purpose,
        [FromForm] List<Guid> containerIds,
        IFormFile? letter,
        CancellationToken ct)
    {
        string? path = null;
        if (letter is not null)
        {
            UploadGuard.Validate(letter, ".pdf", ".png", ".jpg", ".jpeg");
            await using var stream = letter.OpenReadStream();
            path = await _docs.SaveAsync("repositioning-letters", letter.FileName, stream, ct);
        }

        return Ok(await _repo.CreateAsync(
            new CreateRepositioningRequest(
                shippingLineId,
                requestType,
                sourceTerminalId,
                destinationTerminalId,
                purpose,
                containerIds),
            path,
            UserId,
            Role,
            ct));
    }

    [HttpPost("{id:guid}/review")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<RepositioningDto>> Review(Guid id, [FromBody] ReviewRepositioningRequest request, CancellationToken ct)
        => Ok(await _repo.ReviewAsync(id, request, UserId, Role, ct));

    [HttpPost("{id:guid}/complete")]
    [Authorize(Policy = "YardAdmin")]
    public async Task<ActionResult<RepositioningDto>> Complete(Guid id, CancellationToken ct)
        => Ok(await _repo.CompleteAsync(id, UserId, Role, ct));

    [HttpPost("{id:guid}/cancel")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<RepositioningDto>> Cancel(Guid id, CancellationToken ct)
        => Ok(await _repo.CancelAsync(id, UserId, Role, ct));
}

[ApiController]
[Route("api/referrals")]
[Authorize]
public class ReferralsController : ControllerBase
{
    private readonly IReferralService _referrals;
    public ReferralsController(IReferralService referrals) => _referrals = referrals;
    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
    private string Role => User.FindFirstValue(ClaimTypes.Role)!;

    [HttpGet]
    [Authorize(Policy = "Consignee")]
    public async Task<ActionResult<IReadOnlyList<ReferralCodeDto>>> List(CancellationToken ct)
        => Ok(await _referrals.ListForConsigneeAsync(UserId, ct));

    [HttpPost]
    [Authorize(Policy = "Consignee")]
    public async Task<ActionResult<ReferralCodeDto>> Generate([FromBody] GenerateReferralRequest request, CancellationToken ct)
        => Ok(await _referrals.GenerateAsync(request, UserId, ct));

    [HttpPost("apply")]
    [Authorize(Policy = "Broker")]
    public async Task<ActionResult<RelationshipDto>> Apply([FromBody] ApplyReferralRequest request, CancellationToken ct)
        => Ok(await _referrals.ApplyAsync(request, UserId, ct));

    [HttpPost("{id:guid}/deactivate")]
    [Authorize(Policy = "Consignee")]
    public async Task<IActionResult> Deactivate(Guid id, CancellationToken ct)
    {
        await _referrals.DeactivateAsync(id, UserId, ct);
        return Ok(new { message = "Deactivated." });
    }

    [HttpGet("relationships")]
    public async Task<ActionResult<IReadOnlyList<RelationshipDto>>> Relationships(CancellationToken ct)
    {
        Guid? consigneeId = Role == "Consignee" ? UserId : null;
        Guid? brokerId = Role == "Broker" ? UserId : null;
        return Ok(await _referrals.ListRelationshipsAsync(consigneeId, brokerId, ct));
    }
}

[ApiController]
[Route("api/onboarding")]
[Authorize]
public class OnboardingController : ControllerBase
{
    private readonly IOnboardingService _onboarding;
    public OnboardingController(IOnboardingService onboarding) => _onboarding = onboarding;
    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
    private string Role => User.FindFirstValue(ClaimTypes.Role)!;

    [HttpGet("welcome")]
    public async Task<ActionResult<WelcomeContentDto>> Welcome([FromQuery] string? audience, CancellationToken ct)
    {
        var aud = audience ?? (Role == "Consignee" ? "Consignee" : "Broker");
        Guid? userId = Role == "Consignee" ? UserId : null;
        return Ok(await _onboarding.GetWelcomeAsync(aud, userId, ct));
    }

    [HttpPut("welcome")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<WelcomeContentDto>> Upsert([FromBody] UpsertWelcomeContentRequest request, CancellationToken ct)
        => Ok(await _onboarding.UpsertWelcomeAsync(request, UserId, ct));

    [HttpPost("complete-step")]
    [Authorize(Policy = "Consignee")]
    public async Task<ActionResult<WelcomeContentDto>> Complete([FromBody] CompleteOnboardingStepRequest request, CancellationToken ct)
        => Ok(await _onboarding.CompleteStepAsync(request, UserId, ct));
}

[ApiController]
[Route("api/uploads")]
[Authorize]
public class UploadsController : ControllerBase
{
    private readonly IDocumentStore _docs;

    public UploadsController(IDocumentStore docs) => _docs = docs;

    /// <summary>Generic authenticated file upload for dynamic form fields (accreditation docs, images, etc.).</summary>
    [HttpPost]
    [RequestSizeLimit(UploadGuard.MaxBytes)]
    public async Task<IActionResult> Upload(
        IFormFile file,
        [FromForm] string? category,
        [FromForm] string? allowedTypes,
        CancellationToken ct)
    {
        var cat = string.IsNullOrWhiteSpace(category) ? "accreditation" : category.Trim().ToLowerInvariant();
        if (cat.Contains("..", StringComparison.Ordinal) || cat.Contains('/') || cat.Contains('\\'))
        {
            return BadRequest(new { message = "Invalid upload category." });
        }

        string[]? allowed = null;
        if (!string.IsNullOrWhiteSpace(allowedTypes))
        {
            allowed = allowedTypes
                .Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
                .Select(t => t.StartsWith('.') ? t : $".{t}")
                .ToArray();
        }

        try
        {
            UploadGuard.Validate(file, allowed ?? Array.Empty<string>());
        }
        catch (InvalidOperationException ex)
        {
            return BadRequest(new { message = ex.Message });
        }

        await using var stream = file.OpenReadStream();
        var path = await _docs.SaveAsync(cat, file.FileName, stream, ct);
        return Ok(new
        {
            path,
            fileName = Path.GetFileName(file.FileName),
            size = file.Length,
            contentType = file.ContentType
        });
    }
}

[ApiController]
[Route("api/locations")]
[Authorize]
public class LocationsController : ControllerBase
{
    private readonly ILocationService _locations;

    public LocationsController(ILocationService locations) => _locations = locations;

    [HttpGet("regions")]
    public async Task<IActionResult> GetRegions(CancellationToken ct)
        => Ok(new { success = true, regions = await _locations.GetRegionsAsync(ct) });

    [HttpGet("provinces/{regionId:guid}")]
    public async Task<IActionResult> GetProvinces(Guid regionId, CancellationToken ct)
        => Ok(new { success = true, provinces = await _locations.GetProvincesByRegionAsync(regionId, ct) });

    [HttpGet("cities/by-province/{provinceId:guid}")]
    public async Task<IActionResult> GetCitiesByProvince(Guid provinceId, CancellationToken ct)
        => Ok(new { success = true, cities = await _locations.GetCitiesByProvinceAsync(provinceId, ct) });

    [HttpGet("barangays/{cityId:guid}")]
    public async Task<IActionResult> GetBarangays(Guid cityId, CancellationToken ct)
        => Ok(new { success = true, barangays = await _locations.GetBarangaysByCityAsync(cityId, ct) });
}
