using System.Globalization;
using System.Security.Cryptography;
using System.Text;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Optimus.Application.Auth.Interfaces;
using Optimus.Application.Cargo.Interfaces;
using Optimus.Application.Yard.Dtos;
using Optimus.Application.Yard.Interfaces;
using Optimus.Domain.Entities;
using Optimus.Domain.Enums;
using Optimus.Infrastructure.Persistence;
using Optimus.Infrastructure.Shipping;
using Optimus.Shared.Constants;

namespace Optimus.Infrastructure.Yard;

public class TerminalService : ITerminalService
{
    private readonly OptimusDbContext _db;
    private readonly IActivityLogService _activity;

    public TerminalService(OptimusDbContext db, IActivityLogService activity)
    {
        _db = db;
        _activity = activity;
    }

    public async Task<TerminalDto> UpsertAsync(Guid? id, UpsertTerminalRequest request, Guid actorId, CancellationToken ct = default)
    {
        Terminal entity;
        if (id.HasValue)
        {
            entity = await _db.Terminals.FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Terminal not found.");
        }
        else
        {
            entity = new Terminal();
            _db.Terminals.Add(entity);
        }

        entity.Name = request.Name.Trim();
        entity.Code = request.Code.Trim().ToUpperInvariant();
        entity.Identity = Enum.Parse<TerminalIdentity>(request.Identity, true);
        entity.Kind = Enum.Parse<TerminalKind>(request.Kind, true);
        entity.Location = request.Location;
        entity.Region = request.Region;
        entity.City = request.City;
        entity.DailyCapacity = Math.Max(1, request.DailyCapacity);
        entity.IsActive = request.IsActive;
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "terminal.upsert", nameof(Terminal), entity.Id, entity.Code, ct);
        return Map(entity);
    }

    public async Task<IReadOnlyList<TerminalDto>> ListAsync(bool? activeOnly = true, CancellationToken ct = default)
    {
        var q = _db.Terminals.AsNoTracking().AsQueryable();
        if (activeOnly == true) q = q.Where(x => x.IsActive);
        var items = await q.OrderBy(x => x.Name).ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    public async Task<TerminalSlotDto> UpsertSlotAsync(UpsertSlotRequest request, Guid actorId, CancellationToken ct = default)
    {
        var terminal = await _db.Terminals.FirstOrDefaultAsync(x => x.Id == request.TerminalId, ct)
                       ?? throw new KeyNotFoundException("Terminal not found.");
        var slot = await _db.TerminalSlots.FirstOrDefaultAsync(x => x.TerminalId == request.TerminalId && x.Date == request.Date, ct);
        if (slot is null)
        {
            slot = new TerminalSlot { TerminalId = request.TerminalId, Date = request.Date };
            _db.TerminalSlots.Add(slot);
        }

        slot.Capacity = Math.Max(1, request.Capacity);
        slot.Status = slot.AssignedCount >= slot.Capacity ? SlotStatus.Full : SlotStatus.Available;
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "terminal.slot_upsert", nameof(TerminalSlot), slot.Id, $"{terminal.Code}:{request.Date}", ct);
        return new TerminalSlotDto(slot.Id, terminal.Id, terminal.Name, slot.Date, slot.Capacity, slot.AssignedCount, slot.Status.ToString());
    }

    public async Task<IReadOnlyList<TerminalSlotDto>> ListSlotsAsync(Guid terminalId, DateOnly? from, DateOnly? to, CancellationToken ct = default)
    {
        var q = _db.TerminalSlots.AsNoTracking().Include(x => x.Terminal).Where(x => x.TerminalId == terminalId);
        if (from.HasValue) q = q.Where(x => x.Date >= from);
        if (to.HasValue) q = q.Where(x => x.Date <= to);
        var items = await q.OrderBy(x => x.Date).ToListAsync(ct);
        return items.Select(x => new TerminalSlotDto(x.Id, x.TerminalId, x.Terminal.Name, x.Date, x.Capacity, x.AssignedCount, x.Status.ToString())).ToList();
    }

    private static TerminalDto Map(Terminal x) =>
        new(x.Id, x.Name, x.Code, x.Identity.ToString(), x.Kind.ToString(), x.Location, x.Region, x.City, x.DailyCapacity, x.IsActive);
}

public class ContainerCatalogService : IContainerCatalogService
{
    private readonly OptimusDbContext _db;

    public ContainerCatalogService(OptimusDbContext db) => _db = db;

    public async Task<ContainerTypeDto> UpsertTypeAsync(Guid? id, UpsertContainerTypeRequest request, CancellationToken ct = default)
    {
        ContainerType entity;
        if (id.HasValue)
        {
            entity = await _db.ContainerTypes.FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Container type not found.");
        }
        else
        {
            entity = new ContainerType();
            _db.ContainerTypes.Add(entity);
        }

        entity.Name = request.Name.Trim();
        entity.Code = request.Code.Trim().ToUpperInvariant();
        entity.Description = request.Description;
        entity.IsActive = request.IsActive;
        await _db.SaveChangesAsync(ct);
        return new ContainerTypeDto(entity.Id, entity.Name, entity.Code, entity.Description, entity.IsActive);
    }

    public async Task<IReadOnlyList<ContainerTypeDto>> ListTypesAsync(CancellationToken ct = default)
    {
        var items = await _db.ContainerTypes.AsNoTracking().OrderBy(x => x.Name).ToListAsync(ct);
        return items.Select(x => new ContainerTypeDto(x.Id, x.Name, x.Code, x.Description, x.IsActive)).ToList();
    }

    public async Task<ContainerSizeDto> UpsertSizeAsync(Guid? id, UpsertContainerSizeRequest request, CancellationToken ct = default)
    {
        ContainerSize entity;
        if (id.HasValue)
        {
            entity = await _db.ContainerSizes.FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Container size not found.");
        }
        else
        {
            entity = new ContainerSize();
            _db.ContainerSizes.Add(entity);
        }

        entity.Name = request.Name.Trim();
        entity.Code = request.Code.Trim().ToUpperInvariant();
        entity.TeuValue = request.TeuValue <= 0 ? 1 : request.TeuValue;
        entity.Description = request.Description;
        entity.IsActive = request.IsActive;
        await _db.SaveChangesAsync(ct);
        return new ContainerSizeDto(entity.Id, entity.Name, entity.Code, entity.TeuValue, entity.Description, entity.IsActive);
    }

    public async Task<IReadOnlyList<ContainerSizeDto>> ListSizesAsync(CancellationToken ct = default)
    {
        var items = await _db.ContainerSizes.AsNoTracking().OrderBy(x => x.TeuValue).ToListAsync(ct);
        return items.Select(x => new ContainerSizeDto(x.Id, x.Name, x.Code, x.TeuValue, x.Description, x.IsActive)).ToList();
    }
}

public class CyAllocationService : ICyAllocationService
{
    private readonly OptimusDbContext _db;
    private readonly IActivityLogService _activity;

    public CyAllocationService(OptimusDbContext db, IActivityLogService activity)
    {
        _db = db;
        _activity = activity;
    }

    public async Task<CyAllocationDto> UpsertAsync(Guid? id, UpsertCyAllocationRequest request, Guid actorId, CancellationToken ct = default)
    {
        ShippingLineTerminalAllocation entity;
        if (id.HasValue)
        {
            entity = await _db.ShippingLineTerminalAllocations.FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("CY allocation not found.");
        }
        else
        {
            entity = new ShippingLineTerminalAllocation();
            _db.ShippingLineTerminalAllocations.Add(entity);
        }

        entity.ShippingLineId = await SoleShippingLine.RequireIdAsync(_db, ct);
        entity.TerminalId = request.TerminalId;
        entity.StaffUserId = request.StaffUserId;
        entity.AllocatedCapacityTeu = Math.Max(0, request.AllocatedCapacityTeu);
        entity.Capacity20Ft = Math.Max(0, request.Capacity20Ft);
        entity.Capacity40Ft = Math.Max(0, request.Capacity40Ft);
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "cy_allocation.upsert", nameof(ShippingLineTerminalAllocation), entity.Id, null, ct);
        return (await ListAsync(entity.ShippingLineId, request.TerminalId, ct)).First(x => x.Id == entity.Id);
    }

    public async Task<IReadOnlyList<CyAllocationDto>> ListAsync(Guid? shippingLineId, Guid? terminalId, CancellationToken ct = default)
    {
        var q = _db.ShippingLineTerminalAllocations.AsNoTracking()
            .Include(x => x.ShippingLine)
            .Include(x => x.Terminal)
            .Include(x => x.Containers).ThenInclude(c => c.ContainerSize)
            .AsQueryable();
        if (shippingLineId.HasValue) q = q.Where(x => x.ShippingLineId == shippingLineId);
        if (terminalId.HasValue) q = q.Where(x => x.TerminalId == terminalId);
        var items = await q.ToListAsync(ct);
        return items.Select(x =>
        {
            var used = x.Containers
                .Where(c => c.AllocationStatus is AllocationStatus.PreForecast or AllocationStatus.Allocated)
                .Sum(c => c.ContainerSize?.TeuValue ?? 1m);
            return new CyAllocationDto(x.Id, x.ShippingLineId, x.ShippingLine.BrandName, x.TerminalId, x.Terminal.Name,
                x.StaffUserId, x.AllocatedCapacityTeu, x.Capacity20Ft, x.Capacity40Ft, (int)Math.Ceiling(used));
        }).ToList();
    }
}

public class ContainerInventoryService : IContainerInventoryService
{
    private readonly OptimusDbContext _db;
    private readonly IActivityLogService _activity;
    private readonly IDocumentStore _docs;

    public ContainerInventoryService(OptimusDbContext db, IActivityLogService activity, IDocumentStore docs)
    {
        _db = db;
        _activity = activity;
        _docs = docs;
    }

    public async Task<ContainerDto> CreateAsync(CreateContainerRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        EnsureStaff(actorRole);
        if (await _db.Containers.AnyAsync(x => x.ContainerNumber == request.ContainerNumber.Trim().ToUpperInvariant(), ct))
        {
            throw new InvalidOperationException("Container number already exists.");
        }

        var entity = new Container
        {
            ContainerNumber = request.ContainerNumber.Trim().ToUpperInvariant(),
            ShippingLineId = await SoleShippingLine.RequireIdAsync(_db, ct),
            ManifestId = request.ManifestId,
            ContainerTypeId = request.ContainerTypeId,
            ContainerSizeId = request.ContainerSizeId,
            CurrentLocation = request.CurrentLocation,
            StackBay = request.StackBay,
            StackRow = request.StackRow,
            StackTier = request.StackTier,
            Status = ContainerStatus.Pending
        };
        _db.Containers.Add(entity);
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "container.create", nameof(Container), entity.Id, entity.ContainerNumber, ct);
        return await GetAsync(entity.Id, ct);
    }

    public async Task<ContainerDto> GetAsync(Guid id, CancellationToken ct = default)
    {
        var entity = await Query().FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Container not found.");
        return Map(entity);
    }

    public async Task<ContainerInventoryItemDto> GetInventoryItemAsync(Guid id, CancellationToken ct = default)
    {
        var entity = await Query().FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Container not found.");
        return MapInventoryItem(entity);
    }

    public async Task<ContainerDetailDto> GetDetailByNumberAsync(string containerNumber, Guid? shippingLineId, CancellationToken ct = default)
    {
        var number = containerNumber.Trim().ToUpperInvariant();
        var q = _db.Containers.AsNoTracking()
            .Include(x => x.ShippingLine)
            .Include(x => x.ContainerType)
            .Include(x => x.ContainerSize)
            .Include(x => x.CyAllocation)!.ThenInclude(a => a!.Terminal)
            .Include(x => x.Manifest)
            .Include(x => x.AllocationAudits).ThenInclude(a => a.ChangedBy)
            .Include(x => x.DwellEvents)
            .Where(x => x.ContainerNumber == number);

        if (shippingLineId.HasValue)
        {
            q = q.Where(x => x.ShippingLineId == shippingLineId);
        }

        var entity = await q.FirstOrDefaultAsync(ct)
                     ?? throw new KeyNotFoundException("Container not found or you do not have access to view this container.");

        return MapDetail(entity);
    }

    public async Task<IReadOnlyList<ContainerDto>> ListAsync(Guid? shippingLineId, string? status, string? search, CancellationToken ct = default)
    {
        var q = Query();
        if (shippingLineId.HasValue) q = q.Where(x => x.ShippingLineId == shippingLineId);
        if (!string.IsNullOrWhiteSpace(status) && Enum.TryParse<ContainerStatus>(status, true, out var st))
        {
            q = q.Where(x => x.Status == st);
        }

        if (!string.IsNullOrWhiteSpace(search))
        {
            var s = search.Trim();
            q = q.Where(x => x.ContainerNumber.Contains(s));
        }

        var items = await q.OrderByDescending(x => x.CreatedAt).Take(200).ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    public async Task<ContainerInventoryPageDto> InventoryPageAsync(
        Guid? shippingLineId,
        string? depot,
        string? search,
        int page,
        int pageSize,
        CancellationToken ct = default)
    {
        page = Math.Max(1, page);
        pageSize = pageSize <= 0 ? 50 : Math.Clamp(pageSize, 1, 100);

        var q = InventoryQuery(shippingLineId, depot, search);
        var totalCount = await q.CountAsync(ct);
        var totalPages = Math.Max(1, (int)Math.Ceiling(totalCount / (double)pageSize));
        page = Math.Min(page, totalPages);

        var items = await q
            .OrderBy(x => x.AllocationStatus)
            .ThenByDescending(x => x.CurrentDwellDays)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync(ct);

        var allForStats = await InventoryQuery(shippingLineId, depot, search)
            .Include(x => x.ContainerSize)
            .Include(x => x.CyAllocation)!.ThenInclude(a => a!.Terminal)
            .ToListAsync(ct);

        var stats = await BuildInventoryStatsAsync(shippingLineId, allForStats, ct);
        var shippingLineName = shippingLineId.HasValue
            ? await _db.ShippingLines.AsNoTracking()
                .Where(x => x.Id == shippingLineId)
                .Select(x => x.BrandName)
                .FirstOrDefaultAsync(ct) ?? "Shipping line"
            : "All Shipping Lines";

        return new ContainerInventoryPageDto(
            items.Select(MapInventoryItem).ToList(),
            totalCount,
            page,
            pageSize,
            totalPages,
            shippingLineName,
            stats);
    }

    public async Task<IReadOnlyList<string>> ListInventoryDepotsAsync(Guid? shippingLineId, CancellationToken ct = default)
    {
        var q = _db.Containers.AsNoTracking()
            .Where(x => x.AllocationStatus == AllocationStatus.Allocated || x.AllocationStatus == AllocationStatus.PreForecast)
            .Where(x => x.CyAllocation != null);

        if (shippingLineId.HasValue)
        {
            q = q.Where(x => x.ShippingLineId == shippingLineId);
        }

        return await q
            .Select(x => x.CyAllocation!.Terminal.Name)
            .Distinct()
            .OrderBy(x => x)
            .ToListAsync(ct);
    }

    public async Task<IReadOnlyList<ContainerDto>> SearchForReturnAsync(string query, CancellationToken ct = default)
    {
        var q = query.Trim().ToUpperInvariant();
        var items = await Query()
            .Where(x => x.Status == ContainerStatus.AvailableForReturn && x.ContainerNumber.Contains(q))
            .OrderBy(x => x.ContainerNumber)
            .Take(50)
            .ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    public async Task<ContainerDto> AllocateAsync(Guid id, AllocateContainerRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        EnsureStaff(actorRole);
        var container = await _db.Containers.Include(x => x.ContainerSize).FirstOrDefaultAsync(x => x.Id == id, ct)
                        ?? throw new KeyNotFoundException("Container not found.");
        if (container.AllocationStatus is AllocationStatus.Allocated or AllocationStatus.Released)
        {
            throw new InvalidOperationException("Allocation is locked.");
        }

        var allocation = await _db.ShippingLineTerminalAllocations
            .Include(x => x.Containers).ThenInclude(c => c.ContainerSize)
            .FirstOrDefaultAsync(x => x.Id == request.CyAllocationId, ct)
            ?? throw new KeyNotFoundException("CY allocation not found.");

        if (allocation.ShippingLineId != container.ShippingLineId)
        {
            throw new InvalidOperationException("CY allocation shipping line mismatch.");
        }

        var teu = container.ContainerSize?.TeuValue ?? 1m;
        var used = allocation.Containers
            .Where(c => c.Id != container.Id && c.AllocationStatus is AllocationStatus.PreForecast or AllocationStatus.Allocated)
            .Sum(c => c.ContainerSize?.TeuValue ?? 1m);
        if (used + teu > allocation.AllocatedCapacityTeu)
        {
            throw new InvalidOperationException("CY allocation capacity exceeded.");
        }

        var previous = container.CyAllocationId;
        container.CyAllocationId = allocation.Id;
        container.AllocationStatus = AllocationStatus.PreForecast;
        container.AllocatedAt = DateTime.UtcNow;
        container.CurrentLocation = "CY";
        if (container.Status == ContainerStatus.Pending)
        {
            container.Status = ContainerStatus.AvailableForReturn;
        }

        _db.ContainerAllocationAudits.Add(new ContainerAllocationAudit
        {
            ContainerId = container.Id,
            PreviousAllocationId = previous,
            NewAllocationId = allocation.Id,
            ChangedById = actorId,
            ChangeType = previous is null ? "initial" : "reassignment",
            Reason = request.Reason
        });
        await _db.SaveChangesAsync(ct);
        return await GetAsync(id, ct);
    }

    public async Task<ContainerDto> ReallocateAsync(Guid id, ReallocateContainerRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        var container = await _db.Containers.FirstOrDefaultAsync(x => x.Id == id, ct)
                        ?? throw new KeyNotFoundException("Container not found.");
        if (container.AllocationStatus != AllocationStatus.PreForecast)
        {
            throw new InvalidOperationException("Only pre_forecast allocations can be reassigned.");
        }

        return await AllocateAsync(id, new AllocateContainerRequest(request.NewCyAllocationId, request.Reason), actorId, actorRole, ct);
    }

    public async Task<ContainerDto> LockAllocationAsync(Guid id, Guid actorId, CancellationToken ct = default)
    {
        var container = await _db.Containers.FirstOrDefaultAsync(x => x.Id == id, ct)
                        ?? throw new KeyNotFoundException("Container not found.");
        if (container.AllocationStatus != AllocationStatus.PreForecast)
        {
            throw new InvalidOperationException("Only pre_forecast can be locked.");
        }

        container.AllocationStatus = AllocationStatus.Allocated;
        container.AllocationLockedAt = DateTime.UtcNow;
        _db.ContainerAllocationAudits.Add(new ContainerAllocationAudit
        {
            ContainerId = container.Id,
            PreviousAllocationId = container.CyAllocationId,
            NewAllocationId = container.CyAllocationId,
            ChangedById = actorId,
            ChangeType = "locked",
            Reason = "Allocation locked"
        });
        await _db.SaveChangesAsync(ct);
        return await GetAsync(id, ct);
    }

    public async Task<ContainerDto> UpdateStackAsync(Guid id, UpdateStackRequest request, Guid actorId, CancellationToken ct = default)
    {
        var container = await _db.Containers.FirstOrDefaultAsync(x => x.Id == id, ct)
                        ?? throw new KeyNotFoundException("Container not found.");
        container.StackBay = request.StackBay;
        container.StackRow = request.StackRow;
        container.StackTier = request.StackTier;
        if (!string.IsNullOrWhiteSpace(request.CurrentLocation))
        {
            container.CurrentLocation = request.CurrentLocation;
        }

        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "container.stack_update", nameof(Container), id, $"{request.StackBay}/{request.StackRow}/{request.StackTier}", ct);
        return await GetAsync(id, ct);
    }

    public async Task<ContainerDto> MarkAvailableForReturnAsync(Guid id, Guid actorId, CancellationToken ct = default)
    {
        var container = await _db.Containers.FirstOrDefaultAsync(x => x.Id == id, ct)
                        ?? throw new KeyNotFoundException("Container not found.");
        container.Status = ContainerStatus.AvailableForReturn;
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "container.available_for_return", nameof(Container), id, null, ct);
        return await GetAsync(id, ct);
    }

    public async Task<IReadOnlyList<UtilizationReportDto>> UtilizationReportAsync(CancellationToken ct = default)
    {
        var allocations = await _db.ShippingLineTerminalAllocations.AsNoTracking()
            .Include(x => x.Terminal)
            .Include(x => x.Containers).ThenInclude(c => c.ContainerSize)
            .ToListAsync(ct);
        var pendingPa = await _db.PreAdviceRequests.AsNoTracking()
            .Where(x => x.Status == PreAdviceStatus.Pending)
            .GroupBy(x => x.TerminalId)
            .Select(g => new { TerminalId = g.Key, Count = g.Count() })
            .ToDictionaryAsync(x => x.TerminalId, x => x.Count, ct);

        return allocations
            .GroupBy(x => new { x.TerminalId, x.Terminal.Name })
            .Select(g =>
            {
                var allocated = g.Sum(x => x.AllocatedCapacityTeu);
                var used = (int)Math.Ceiling(g.SelectMany(x => x.Containers)
                    .Where(c => c.AllocationStatus is AllocationStatus.PreForecast or AllocationStatus.Allocated)
                    .Sum(c => c.ContainerSize?.TeuValue ?? 1m));
                var available = g.SelectMany(x => x.Containers).Count(c => c.Status == ContainerStatus.AvailableForReturn);
                var atTerminal = g.SelectMany(x => x.Containers).Count(c => c.Status == ContainerStatus.AtTerminal);
                var pct = allocated == 0 ? 0 : Math.Round((decimal)used / allocated * 100m, 1);
                pendingPa.TryGetValue(g.Key.TerminalId, out var pending);
                return new UtilizationReportDto(g.Key.TerminalId, g.Key.Name, allocated, used, pct, available, atTerminal, pending);
            })
            .OrderBy(x => x.TerminalName)
            .ToList();
    }

    public async Task<(string Csv, string PdfPath)> ExportUtilizationAsync(CancellationToken ct = default)
    {
        var rows = await UtilizationReportAsync(ct);
        var sb = new StringBuilder();
        sb.AppendLine("Terminal,AllocatedTEU,UsedTEU,Utilization%,AvailableForReturn,AtTerminal,PendingPreAdvice");
        foreach (var r in rows)
        {
            sb.AppendLine($"{r.TerminalName},{r.AllocatedTeu},{r.UsedTeu},{r.UtilizationPercent},{r.AvailableForReturn},{r.AtTerminal},{r.PendingPreAdvice}");
        }

        var pdf = _docs.CreatePlaceholderPdf("reports", "CY Utilization", sb.ToString());
        return (sb.ToString(), pdf);
    }

    private IQueryable<Container> Query() =>
        _db.Containers.AsNoTracking()
            .Include(x => x.ShippingLine)
            .Include(x => x.ContainerType)
            .Include(x => x.ContainerSize)
            .Include(x => x.CyAllocation)!.ThenInclude(a => a!.Terminal);

    private IQueryable<Container> InventoryQuery(Guid? shippingLineId, string? depot, string? search)
    {
        var q = Query()
            .Where(x => x.AllocationStatus == AllocationStatus.Allocated || x.AllocationStatus == AllocationStatus.PreForecast);

        if (shippingLineId.HasValue)
        {
            q = q.Where(x => x.ShippingLineId == shippingLineId);
        }

        if (!string.IsNullOrWhiteSpace(depot))
        {
            var d = depot.Trim();
            q = q.Where(x => x.CyAllocation != null && x.CyAllocation.Terminal.Name.Contains(d));
        }

        if (!string.IsNullOrWhiteSpace(search))
        {
            var s = search.Trim().ToUpperInvariant();
            q = q.Where(x => x.ContainerNumber == s);
        }

        return q;
    }

    private async Task<ContainerInventoryStatsDto> BuildInventoryStatsAsync(
        Guid? shippingLineId,
        IReadOnlyList<Container> containers,
        CancellationToken ct)
    {
        var total20 = 0;
        var total40 = 0;
        var terminalCount = 0;
        var yardCount = 0;

        foreach (var c in containers)
        {
            var code = c.ContainerSize?.Code ?? string.Empty;
            if (code.Contains("20", StringComparison.OrdinalIgnoreCase)) total20++;
            else total40++;

            var identity = c.CyAllocation?.Terminal?.Identity;
            if (identity == TerminalIdentity.Terminal) terminalCount++;
            else if (identity == TerminalIdentity.ContainerYard) yardCount++;
        }

        var allocationsQ = _db.ShippingLineTerminalAllocations.AsNoTracking().Include(x => x.Terminal).AsQueryable();
        if (shippingLineId.HasValue)
        {
            allocationsQ = allocationsQ.Where(x => x.ShippingLineId == shippingLineId);
        }

        var allocations = await allocationsQ.ToListAsync(ct);
        var term20 = 0;
        var term40 = 0;
        var yard20 = 0;
        var yard40 = 0;
        foreach (var a in allocations)
        {
            if (a.Terminal.Identity == TerminalIdentity.Terminal)
            {
                term20 += a.Capacity20Ft;
                term40 += a.Capacity40Ft;
            }
            else
            {
                yard20 += a.Capacity20Ft;
                yard40 += a.Capacity40Ft;
            }
        }

        var termTeu = term20 + term40 * 2;
        var yardTeu = yard20 + yard40 * 2;

        return new ContainerInventoryStatsDto(
            TotalContainers: total20 + total40,
            TotalTeus: total20 + total40 * 2,
            Total20Ft: total20,
            Total40Ft: total40,
            OverallCapacityTeu: termTeu + yardTeu,
            OverallCapacity20Ft: term20 + yard20,
            OverallCapacity40Ft: term40 + yard40,
            TerminalCount: terminalCount,
            TerminalCapacityTeu: termTeu,
            TerminalCapacity20Ft: term20,
            TerminalCapacity40Ft: term40,
            YardCount: yardCount,
            YardCapacityTeu: yardTeu,
            YardCapacity20Ft: yard20,
            YardCapacity40Ft: yard40);
    }

    private static ContainerDto Map(Container x) =>
        new(x.Id, x.ContainerNumber, x.ShippingLineId, x.ShippingLine.BrandName, x.ManifestId,
            x.ContainerType?.Code, x.ContainerSize?.Code, x.Status.ToString(), x.AllocationStatus.ToString(),
            x.CurrentLocation, x.CyAllocationId, x.CyAllocation?.Terminal?.Name, x.CurrentDwellDays,
            x.TerminalArrivalDate, x.DwellPausedAt, x.StackBay, x.StackRow, x.StackTier, x.CreatedAt);

    private static ContainerInventoryItemDto MapInventoryItem(Container x)
    {
        var size = x.ContainerSize?.Code ?? "—";
        var type = x.ContainerType?.Code;
        var sizeType = string.IsNullOrWhiteSpace(type) ? size : $"{size} / {type}";
        var gateIn = x.TerminalArrivalDate ?? x.CreatedAt;
        var dwell = x.CurrentDwellDays;
        if (dwell <= 0 && x.TerminalArrivalDate.HasValue)
        {
            dwell = Math.Max(0, (int)(DateTime.UtcNow.Date - x.TerminalArrivalDate.Value.Date).TotalDays - x.TotalPausedDays);
        }

        return new ContainerInventoryItemDto(
            x.Id,
            x.ContainerNumber,
            x.ShippingLineId,
            x.ShippingLine.BrandName,
            x.ContainerType?.Code,
            x.ContainerSize?.Code,
            sizeType,
            x.CyAllocation?.Terminal?.Name ?? x.CurrentLocation ?? "—",
            gateIn,
            dwell,
            x.TotalPausedDays,
            x.DwellPausedAt != null,
            DisplayStatus(x.AllocationStatus),
            Condition(x.Status),
            x.AllocationStatus.ToString(),
            x.Status.ToString(),
            x.ContainerSize?.TeuValue ?? 1m,
            x.StackBay,
            x.StackRow,
            x.StackTier,
            x.CurrentLocation,
            x.CreatedAt);
    }

    private static ContainerDetailDto MapDetail(Container x)
    {
        var sizeCode = x.ContainerSize?.Code ?? string.Empty;
        var typeCode = x.ContainerType?.Code ?? string.Empty;
        var is20 = sizeCode.Contains("20", StringComparison.OrdinalIgnoreCase);
        var location = x.CyAllocation?.Terminal?.Name ?? x.CurrentLocation ?? "Unknown";
        var gateIn = x.TerminalArrivalDate ?? x.CreatedAt;
        var dwell = x.CurrentDwellDays;
        if (dwell <= 0 && x.TerminalArrivalDate.HasValue)
        {
            dwell = Math.Max(0, (int)(DateTime.UtcNow.Date - x.TerminalArrivalDate.Value.Date).TotalDays - x.TotalPausedDays);
        }

        var sizeType = string.IsNullOrWhiteSpace(typeCode)
            ? sizeCode
            : $"{(is20 ? "20ft" : "40ft")} {typeCode}";
        if (string.IsNullOrWhiteSpace(sizeType)) sizeType = "—";

        var stack = string.Join('/', new[] { x.StackBay, x.StackRow, x.StackTier }.Where(s => !string.IsNullOrWhiteSpace(s)));
        var basic = new ContainerDetailBasicInfoDto(
            x.ContainerNumber,
            sizeType,
            x.ContainerSize?.TeuValue ?? (is20 ? 1m : 2m),
            location,
            gateIn,
            dwell,
            Condition(x.Status),
            DisplayStatus(x.AllocationStatus),
            x.ShippingLine.BrandName,
            string.IsNullOrWhiteSpace(stack) ? null : stack,
            x.Status.ToString());

        var specs = new ContainerDetailSpecificationsDto(
            Manufacturer: "N/A",
            YearBuilt: "N/A",
            IsoCode: string.IsNullOrWhiteSpace(sizeCode) && string.IsNullOrWhiteSpace(typeCode)
                ? "N/A"
                : $"{sizeCode}{typeCode}",
            CscPlate: "Valid",
            MaxGrossWeight: "N/A",
            TareWeight: "N/A",
            MaxPayload: "N/A",
            Length: is20 ? "20ft" : "40ft",
            Width: "8ft",
            Height: "8.6ft");

        var movement = new ContainerDetailMovementDto(
            LastMovement: gateIn.ToString("MMM d, yyyy HH:mm"),
            MovementType: "Gate In",
            FromLocation: "Port",
            ToLocation: location,
            Operator: "N/A",
            Equipment: "N/A",
            Remarks: "Container received at terminal");

        var documentation = new ContainerDetailDocumentationDto(
            BillOfLading: string.IsNullOrWhiteSpace(x.Manifest?.BlNumber) ? "N/A" : x.Manifest!.BlNumber!,
            Manifest: string.IsNullOrWhiteSpace(x.Manifest?.ManifestNumber) ? "N/A" : x.Manifest!.ManifestNumber,
            CustomsDeclaration: "N/A",
            DeliveryOrder: null,
            GatePass: "N/A");

        var charges = new ContainerDetailChargesDto(0, 0, 0);

        var history = new List<ContainerDetailHistoryItemDto>
        {
            new(
                gateIn.ToString("MMM d, yyyy HH:mm"),
                "Gate In",
                "Port",
                location,
                "System",
                "N/A",
                "Container received at terminal")
        };

        foreach (var audit in x.AllocationAudits.OrderBy(a => a.ChangedAt))
        {
            history.Add(new ContainerDetailHistoryItemDto(
                audit.ChangedAt.ToString("MMM d, yyyy HH:mm"),
                audit.ChangeType switch
                {
                    "initial" => "CY Allocation",
                    "locked" => "Allocation Locked",
                    "reassignment" => "CY Reallocation",
                    _ => audit.ChangeType
                },
                location,
                location,
                audit.ChangedBy?.FullName ?? "Staff",
                "N/A",
                audit.Reason ?? "Allocation update"));
        }

        foreach (var evt in x.DwellEvents.OrderBy(e => e.EventDate))
        {
            history.Add(new ContainerDetailHistoryItemDto(
                evt.EventDate.ToString("MMM d, yyyy HH:mm"),
                evt.EventType.ToString(),
                location,
                location,
                "System",
                "N/A",
                evt.Reason ?? $"Dwell event ({evt.DwellDaysAtEvent}d)"));
        }

        var condition = Condition(x.Status);
        var inspections = new List<ContainerDetailInspectionDto>
        {
            new(
                gateIn.ToString("MMM d, yyyy"),
                "Visual Inspection",
                "Terminal Staff",
                condition == "Damaged" ? "Fail" : condition == "Fair" ? "Pass with Minor Issues" : "Pass",
                "0",
                condition == "Damaged" ? "Damage noted — maintenance required" :
                condition == "Fair" ? "Minor wear observed" : "No visible damage")
        };

        return new ContainerDetailDto(x.Id, basic, specs, movement, documentation, charges, history, inspections);
    }

    private static string DisplayStatus(AllocationStatus status) => status switch
    {
        AllocationStatus.Allocated => "Available",
        AllocationStatus.PreForecast => "Pre-Forecast",
        AllocationStatus.Released => "Released",
        _ => status.ToString()
    };

    private static string Condition(ContainerStatus status) => status switch
    {
        ContainerStatus.Alert => "Fair",
        ContainerStatus.Maintenance => "Damaged",
        _ => "Good"
    };

    private static void EnsureStaff(string role)
    {
        if (role is not (AppRoles.SlStaff or AppRoles.ShippingLinesAdmin or AppRoles.SystemAdmin or AppRoles.TerminalTeam))
        {
            throw new UnauthorizedAccessException("Staff/Terminal role required.");
        }
    }
}
