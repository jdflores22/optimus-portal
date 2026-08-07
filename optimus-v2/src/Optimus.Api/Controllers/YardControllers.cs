using System.Security.Claims;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Optimus.Api.Security;
using Optimus.Application.Cargo.Interfaces;
using Optimus.Application.Yard.Dtos;
using Optimus.Application.Yard.Interfaces;
using Optimus.Application.Security;

namespace Optimus.Api.Controllers;

[ApiController]
[Route("api/terminals")]
[Authorize]
public class TerminalsController : ControllerBase
{
    private readonly ITerminalService _terminals;
    public TerminalsController(ITerminalService terminals) => _terminals = terminals;
    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<TerminalDto>>> List([FromQuery] bool? activeOnly, CancellationToken ct)
        => Ok(await _terminals.ListAsync(activeOnly ?? true, ct));

    [HttpPost]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<TerminalDto>> Create([FromBody] UpsertTerminalRequest request, CancellationToken ct)
        => Ok(await _terminals.UpsertAsync(null, request, UserId, ct));

    [HttpPut("{id:guid}")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<TerminalDto>> Update(Guid id, [FromBody] UpsertTerminalRequest request, CancellationToken ct)
        => Ok(await _terminals.UpsertAsync(id, request, UserId, ct));

    [HttpGet("{id:guid}/slots")]
    public async Task<ActionResult<IReadOnlyList<TerminalSlotDto>>> Slots(Guid id, [FromQuery] DateOnly? from, [FromQuery] DateOnly? to, CancellationToken ct)
        => Ok(await _terminals.ListSlotsAsync(id, from, to, ct));

    [HttpPost("slots")]
    [Authorize(Policy = "YardAdmin")]
    public async Task<ActionResult<TerminalSlotDto>> UpsertSlot([FromBody] UpsertSlotRequest request, CancellationToken ct)
        => Ok(await _terminals.UpsertSlotAsync(request, UserId, ct));
}

[ApiController]
[Route("api/container-catalog")]
[Authorize]
public class ContainerCatalogController : ControllerBase
{
    private readonly IContainerCatalogService _catalog;
    public ContainerCatalogController(IContainerCatalogService catalog) => _catalog = catalog;

    [HttpGet("types")]
    public async Task<ActionResult<IReadOnlyList<ContainerTypeDto>>> Types(CancellationToken ct)
        => Ok(await _catalog.ListTypesAsync(ct));

    [HttpPost("types")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<ContainerTypeDto>> UpsertType([FromBody] UpsertContainerTypeRequest request, [FromQuery] Guid? id, CancellationToken ct)
        => Ok(await _catalog.UpsertTypeAsync(id, request, ct));

    [HttpGet("sizes")]
    public async Task<ActionResult<IReadOnlyList<ContainerSizeDto>>> Sizes(CancellationToken ct)
        => Ok(await _catalog.ListSizesAsync(ct));

    [HttpPost("sizes")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<ContainerSizeDto>> UpsertSize([FromBody] UpsertContainerSizeRequest request, [FromQuery] Guid? id, CancellationToken ct)
        => Ok(await _catalog.UpsertSizeAsync(id, request, ct));
}

[ApiController]
[Route("api/cy-allocations")]
[Authorize]
public class CyAllocationsController : ControllerBase
{
    private readonly ICyAllocationService _cy;
    public CyAllocationsController(ICyAllocationService cy) => _cy = cy;
    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<CyAllocationDto>>> List([FromQuery] Guid? shippingLineId, [FromQuery] Guid? terminalId, CancellationToken ct)
        => Ok(await _cy.ListAsync(shippingLineId, terminalId, ct));

    [HttpPost]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<CyAllocationDto>> Create([FromBody] UpsertCyAllocationRequest request, CancellationToken ct)
        => Ok(await _cy.UpsertAsync(null, request, UserId, ct));

    [HttpPut("{id:guid}")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<CyAllocationDto>> Update(Guid id, [FromBody] UpsertCyAllocationRequest request, CancellationToken ct)
        => Ok(await _cy.UpsertAsync(id, request, UserId, ct));
}

[ApiController]
[Route("api/containers")]
[Authorize]
public class ContainersController : ControllerBase
{
    private readonly IContainerInventoryService _containers;
    public ContainersController(IContainerInventoryService containers) => _containers = containers;
    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
    private string Role => User.FindFirstValue(ClaimTypes.Role)!;
    private Guid? ActiveShippingLineId =>
        Guid.TryParse(User.FindFirstValue("shipping_line_id"), out var id) ? id : null;

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<ContainerDto>>> List(
        [FromQuery] Guid? shippingLineId, [FromQuery] string? status, [FromQuery] string? search, CancellationToken ct)
        => Ok(await _containers.ListAsync(shippingLineId ?? ActiveShippingLineId, status, search, ct));

    [HttpGet("inventory")]
    [Authorize(Policy = "ContainerInventory")]
    public async Task<ActionResult<ContainerInventoryPageDto>> Inventory(
        [FromQuery] string? depot,
        [FromQuery] string? search,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 50,
        [FromQuery] Guid? shippingLineId = null,
        CancellationToken ct = default)
        => Ok(await _containers.InventoryPageAsync(
            shippingLineId ?? ActiveShippingLineId,
            depot,
            search,
            page,
            pageSize,
            ct));

    [HttpGet("inventory/depots")]
    [Authorize(Policy = "ContainerInventory")]
    public async Task<ActionResult<IReadOnlyList<string>>> InventoryDepots(
        [FromQuery] Guid? shippingLineId = null,
        CancellationToken ct = default)
        => Ok(await _containers.ListInventoryDepotsAsync(shippingLineId ?? ActiveShippingLineId, ct));

    [HttpGet("inventory/{id:guid}")]
    [Authorize(Policy = "ContainerInventory")]
    public async Task<ActionResult<ContainerInventoryItemDto>> InventoryItem(Guid id, CancellationToken ct)
        => Ok(await _containers.GetInventoryItemAsync(id, ct));

    [HttpGet("by-number/{containerNumber}/details")]
    [Authorize(Policy = "ContainerInventory")]
    public async Task<ActionResult<ContainerDetailDto>> DetailsByNumber(string containerNumber, CancellationToken ct)
        => Ok(await _containers.GetDetailByNumberAsync(containerNumber, ActiveShippingLineId, ct));

    [HttpGet("search-return")]
    public async Task<ActionResult<IReadOnlyList<ContainerDto>>> SearchReturn([FromQuery] string q, CancellationToken ct)
        => Ok(await _containers.SearchForReturnAsync(q ?? string.Empty, ct));

    [HttpGet("utilization")]
    [Authorize(Policy = "YardAdmin")]
    public async Task<ActionResult<IReadOnlyList<UtilizationReportDto>>> Utilization(CancellationToken ct)
        => Ok(await _containers.UtilizationReportAsync(ct));

    [HttpGet("utilization/export")]
    [Authorize(Policy = "YardAdmin")]
    public async Task<ActionResult<object>> Export(CancellationToken ct)
    {
        var (csv, pdf) = await _containers.ExportUtilizationAsync(ct);
        return Ok(new { csv, pdfPath = pdf });
    }

    [HttpGet("{id:guid}")]
    public async Task<ActionResult<ContainerDto>> Get(Guid id, CancellationToken ct)
        => Ok(await _containers.GetAsync(id, ct));

    [HttpPost]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<ContainerDto>> Create([FromBody] CreateContainerRequest request, CancellationToken ct)
        => Ok(await _containers.CreateAsync(request, UserId, Role, ct));

    [HttpPost("{id:guid}/allocate")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<ContainerDto>> Allocate(Guid id, [FromBody] AllocateContainerRequest request, CancellationToken ct)
        => Ok(await _containers.AllocateAsync(id, request, UserId, Role, ct));

    [HttpPut("{id:guid}/reallocate")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<ContainerDto>> Reallocate(Guid id, [FromBody] ReallocateContainerRequest request, CancellationToken ct)
        => Ok(await _containers.ReallocateAsync(id, request, UserId, Role, ct));

    [HttpPost("{id:guid}/lock-allocation")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<ContainerDto>> Lock(Guid id, CancellationToken ct)
        => Ok(await _containers.LockAllocationAsync(id, UserId, ct));

    [HttpPut("{id:guid}/stack")]
    [Authorize(Policy = "YardAdmin")]
    public async Task<ActionResult<ContainerDto>> Stack(Guid id, [FromBody] UpdateStackRequest request, CancellationToken ct)
        => Ok(await _containers.UpdateStackAsync(id, request, UserId, ct));

    [HttpPost("{id:guid}/available-for-return")]
    [Authorize(Policy = "SlStaff")]
    public async Task<ActionResult<ContainerDto>> Available(Guid id, CancellationToken ct)
        => Ok(await _containers.MarkAvailableForReturnAsync(id, UserId, ct));
}

[ApiController]
[Route("api/dwell")]
[Authorize]
public class DwellController : ControllerBase
{
    private readonly IDwellService _dwell;
    public DwellController(IDwellService dwell) => _dwell = dwell;
    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);

    [HttpGet("config")]
    public async Task<ActionResult<DwellConfigDto>> Config(CancellationToken ct)
        => Ok(await _dwell.GetConfigAsync(ct));

    [HttpPut("config")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<DwellConfigDto>> UpsertConfig([FromBody] UpsertDwellConfigRequest request, CancellationToken ct)
        => Ok(await _dwell.UpsertConfigAsync(request, UserId, ct));

    [HttpGet("monitor")]
    [Authorize(Policy = "YardAdmin")]
    public async Task<ActionResult<IReadOnlyList<ContainerDto>>> Monitor(CancellationToken ct)
        => Ok(await _dwell.MonitorListAsync(ct));

    [HttpGet("events")]
    [Authorize(Policy = "YardAdmin")]
    public async Task<ActionResult<IReadOnlyList<DwellEventDto>>> Events([FromQuery] Guid? containerId, CancellationToken ct)
        => Ok(await _dwell.ListEventsAsync(containerId, ct));

    [HttpPost("containers/{id:guid}/arrival")]
    [Authorize(Policy = "YardAdmin")]
    public async Task<ActionResult<ContainerDto>> Arrival(Guid id, CancellationToken ct)
        => Ok(await _dwell.RecordArrivalAsync(id, null, UserId, ct));

    [HttpPost("containers/{id:guid}/pause")]
    [Authorize(Policy = "YardAdmin")]
    public async Task<ActionResult<ContainerDto>> Pause(Guid id, [FromBody] PauseResumeDwellRequest request, CancellationToken ct)
        => Ok(await _dwell.PauseAsync(id, request, UserId, ct));

    [HttpPost("containers/{id:guid}/resume")]
    [Authorize(Policy = "YardAdmin")]
    public async Task<ActionResult<ContainerDto>> Resume(Guid id, [FromBody] PauseResumeDwellRequest request, CancellationToken ct)
        => Ok(await _dwell.ResumeAsync(id, request, UserId, ct));

    [HttpPost("process")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<object>> Process(CancellationToken ct)
        => Ok(new { actions = await _dwell.ProcessMonitoringAsync(ct) });
}

[ApiController]
[Route("api/v1/pre-advice")]
[Authorize]
public class PreAdviceController : ControllerBase
{
    private readonly IPreAdviceService _preadvice;
    private readonly IDocumentStore _docs;
    private readonly IResourceAuthorizationService _access;

    public PreAdviceController(IPreAdviceService preadvice, IDocumentStore docs, IResourceAuthorizationService access)
    {
        _preadvice = preadvice;
        _docs = docs;
        _access = access;
    }

    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
    private string Role => User.FindFirstValue(ClaimTypes.Role)!;

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<PreAdviceDto>>> List([FromQuery] string? status, CancellationToken ct)
    {
        Guid? truckerId = Role == "Trucker" ? UserId : null;
        return Ok(await _preadvice.ListAsync(status, truckerId, ct));
    }

    [HttpGet("{id:guid}")]
    public async Task<ActionResult<PreAdviceDto>> Get(Guid id, CancellationToken ct)
    {
        await _access.EnsurePreAdviceAccessAsync(id, UserId, Role, ct);
        return Ok(await _preadvice.GetAsync(id, ct));
    }

    [HttpPost]
    [Authorize(Policy = "Trucker")]
    public async Task<ActionResult<PreAdviceDto>> Submit(
        [FromForm] Guid containerId,
        [FromForm] Guid terminalId,
        [FromForm] Guid? slotId,
        [FromForm] string? paymentReference,
        [FromForm] double latitude,
        [FromForm] double longitude,
        IFormFile photo,
        CancellationToken ct)
    {
        UploadGuard.Validate(photo, ".png", ".jpg", ".jpeg", ".webp");
        await using var stream = photo.OpenReadStream();
        var path = await _docs.SaveAsync("geotag", photo.FileName, stream, ct);
        return Ok(await _preadvice.SubmitAsync(
            new SubmitPreAdviceRequest(containerId, terminalId, slotId, paymentReference, latitude, longitude),
            path, UserId, ct));
    }

    [HttpPost("{id:guid}/verify")]
    [Authorize(Policy = "TerminalTeam")]
    public async Task<ActionResult<PreAdviceDto>> Verify(Guid id, [FromBody] VerifyPreAdviceRequest request, CancellationToken ct)
        => Ok(await _preadvice.VerifyAsync(id, request, UserId, Role, ct));

    [HttpPost("{id:guid}/complete")]
    [Authorize(Policy = "TerminalTeam")]
    public async Task<ActionResult<PreAdviceDto>> Complete(Guid id, [FromBody] CompletePreAdviceRequest request, CancellationToken ct)
        => Ok(await _preadvice.CompleteAsync(id, request, UserId, Role, ct));

    [HttpPost("{id:guid}/cancel")]
    public async Task<ActionResult<PreAdviceDto>> Cancel(Guid id, CancellationToken ct)
        => Ok(await _preadvice.CancelAsync(id, UserId, Role, ct));
}

[ApiController]
[Route("api/v1/token")]
[Authorize(Policy = "Trucker")]
public class TruckerTokenController : ControllerBase
{
    private readonly ITruckerTokenService _tokens;
    public TruckerTokenController(ITruckerTokenService tokens) => _tokens = tokens;
    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);

    [HttpPost("generate")]
    public async Task<ActionResult<TruckerTokenDto>> Generate(CancellationToken ct)
        => Ok(await _tokens.GenerateAsync(UserId, ct));

    [HttpPost("refresh")]
    public async Task<ActionResult<TruckerTokenDto>> Refresh(CancellationToken ct)
        => Ok(await _tokens.RefreshAsync(UserId, ct));

    [HttpPost("revoke")]
    public async Task<IActionResult> Revoke(CancellationToken ct)
    {
        await _tokens.RevokeAsync(UserId, ct);
        return Ok(new { message = "API token revoked." });
    }
}

