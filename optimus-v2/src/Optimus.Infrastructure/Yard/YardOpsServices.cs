using System.Security.Cryptography;
using System.Text;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Optimus.Application.Cargo.Interfaces;
using Optimus.Application.Edo.Interfaces;
using Optimus.Application.Yard.Dtos;
using Optimus.Application.Yard.Interfaces;
using Optimus.Domain.Entities;
using Optimus.Domain.Enums;
using Optimus.Infrastructure.Persistence;
using Optimus.Shared.Constants;

namespace Optimus.Infrastructure.Yard;

public class DwellService : IDwellService
{
    private readonly OptimusDbContext _db;
    private readonly IContainerInventoryService _containers;
    private readonly INotificationService _notifications;
    private readonly IActivityLogService _activity;

    public DwellService(
        OptimusDbContext db,
        IContainerInventoryService containers,
        INotificationService notifications,
        IActivityLogService activity)
    {
        _db = db;
        _containers = containers;
        _notifications = notifications;
        _activity = activity;
    }

    public async Task<DwellConfigDto> GetConfigAsync(CancellationToken ct = default)
    {
        var cfg = await EnsureConfigAsync(ct);
        return MapConfig(cfg);
    }

    public async Task<DwellConfigDto> UpsertConfigAsync(UpsertDwellConfigRequest request, Guid actorId, CancellationToken ct = default)
    {
        var cfg = await EnsureConfigAsync(ct);
        cfg.NotificationThresholdDays = Math.Max(0, request.NotificationThresholdDays);
        cfg.AutomaticReturnThresholdDays = Math.Max(cfg.NotificationThresholdDays, request.AutomaticReturnThresholdDays);
        cfg.Timezone = string.IsNullOrWhiteSpace(request.Timezone) ? "Asia/Manila" : request.Timezone;
        cfg.EnableAutomaticReturns = request.EnableAutomaticReturns;
        cfg.EnableNotifications = request.EnableNotifications;
        cfg.IsActive = true;
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "dwell.config_upsert", nameof(DwellTimeConfiguration), cfg.Id, null, ct);
        return MapConfig(cfg);
    }

    public async Task<ContainerDto> RecordArrivalAsync(Guid containerId, DateTime? arrivalAt, Guid actorId, CancellationToken ct = default)
    {
        var container = await _db.Containers.FirstOrDefaultAsync(x => x.Id == containerId, ct)
                        ?? throw new KeyNotFoundException("Container not found.");
        container.TerminalArrivalDate = arrivalAt ?? DateTime.UtcNow;
        container.Status = ContainerStatus.AtTerminal;
        container.CurrentDwellDays = 0;
        container.LastDwellCalculationAt = DateTime.UtcNow;
        AddEvent(container, DwellEventType.Arrival, actorId, "Terminal arrival");
        await _db.SaveChangesAsync(ct);
        return await _containers.GetAsync(containerId, ct);
    }

    public async Task<ContainerDto> PauseAsync(Guid containerId, PauseResumeDwellRequest request, Guid actorId, CancellationToken ct = default)
    {
        var container = await _db.Containers.FirstOrDefaultAsync(x => x.Id == containerId, ct)
                        ?? throw new KeyNotFoundException("Container not found.");
        if (container.DwellPausedAt is not null)
        {
            throw new InvalidOperationException("Dwell already paused.");
        }

        Recalc(container);
        container.DwellPausedAt = DateTime.UtcNow;
        AddEvent(container, DwellEventType.Pause, actorId, request.Reason ?? "Paused");
        await _db.SaveChangesAsync(ct);
        return await _containers.GetAsync(containerId, ct);
    }

    public async Task<ContainerDto> ResumeAsync(Guid containerId, PauseResumeDwellRequest request, Guid actorId, CancellationToken ct = default)
    {
        var container = await _db.Containers.FirstOrDefaultAsync(x => x.Id == containerId, ct)
                        ?? throw new KeyNotFoundException("Container not found.");
        if (container.DwellPausedAt is null)
        {
            throw new InvalidOperationException("Dwell is not paused.");
        }

        var pausedDays = Math.Max(0, (int)Math.Floor((DateTime.UtcNow - container.DwellPausedAt.Value).TotalDays));
        container.TotalPausedDays += pausedDays;
        container.DwellPausedAt = null;
        Recalc(container);
        AddEvent(container, DwellEventType.Resume, actorId, request.Reason ?? $"Resumed (+{pausedDays}d paused)");
        await _db.SaveChangesAsync(ct);
        return await _containers.GetAsync(containerId, ct);
    }

    public async Task<IReadOnlyList<DwellEventDto>> ListEventsAsync(Guid? containerId, CancellationToken ct = default)
    {
        var q = _db.DwellTimeEvents.AsNoTracking().Include(x => x.Container).AsQueryable();
        if (containerId.HasValue) q = q.Where(x => x.ContainerId == containerId);
        var items = await q.OrderByDescending(x => x.EventDate).Take(200).ToListAsync(ct);
        return items.Select(x => new DwellEventDto(x.Id, x.ContainerId, x.Container.ContainerNumber, x.EventType.ToString(),
            x.EventDate, x.DwellDaysAtEvent, x.Reason)).ToList();
    }

    public async Task<IReadOnlyList<ContainerDto>> MonitorListAsync(CancellationToken ct = default)
    {
        var items = await _db.Containers.AsNoTracking()
            .Include(x => x.ShippingLine)
            .Include(x => x.ContainerType)
            .Include(x => x.ContainerSize)
            .Include(x => x.CyAllocation)!.ThenInclude(a => a!.Terminal)
            .Where(x => x.TerminalArrivalDate != null && x.Status != ContainerStatus.Returned)
            .OrderByDescending(x => x.CurrentDwellDays)
            .Take(200)
            .ToListAsync(ct);
        return items.Select(x => new ContainerDto(
            x.Id, x.ContainerNumber, x.ShippingLineId, x.ShippingLine.BrandName, x.ManifestId,
            x.ContainerType?.Code, x.ContainerSize?.Code, x.Status.ToString(), x.AllocationStatus.ToString(),
            x.CurrentLocation, x.CyAllocationId, x.CyAllocation?.Terminal?.Name, x.CurrentDwellDays,
            x.TerminalArrivalDate, x.DwellPausedAt, x.StackBay, x.StackRow, x.StackTier, x.CreatedAt)).ToList();
    }

    public async Task<int> ProcessMonitoringAsync(CancellationToken ct = default)
    {
        var cfg = await EnsureConfigAsync(ct);
        if (!cfg.IsActive) return 0;

        var containers = await _db.Containers
            .Where(x => x.TerminalArrivalDate != null && x.Status != ContainerStatus.Returned && x.DwellPausedAt == null)
            .ToListAsync(ct);

        var admins = await _db.Users.AsNoTracking()
            .Where(x => x.Role == AppRoles.ShippingLinesAdmin || x.Role == AppRoles.TerminalTeam || x.Role == AppRoles.SystemAdmin)
            .Select(x => x.Id)
            .ToListAsync(ct);

        var actions = 0;
        foreach (var container in containers)
        {
            Recalc(container);
            if (cfg.EnableNotifications && container.CurrentDwellDays >= cfg.NotificationThresholdDays &&
                container.Status != ContainerStatus.Alert)
            {
                container.Status = ContainerStatus.Alert;
                AddEvent(container, DwellEventType.Notification60Day, null, $"Dwell reached {container.CurrentDwellDays} days");
                foreach (var adminId in admins.Take(5))
                {
                    await _notifications.NotifyAsync(adminId, "Dwell threshold",
                        $"Container {container.ContainerNumber} dwell={container.CurrentDwellDays}d",
                        "dwell", nameof(Container), container.Id, ct);
                }

                actions++;
            }

            if (cfg.EnableAutomaticReturns && container.CurrentDwellDays >= cfg.AutomaticReturnThresholdDays)
            {
                container.Status = ContainerStatus.Returned;
                container.AutomaticReturnDate = DateTime.UtcNow;
                AddEvent(container, DwellEventType.AutomaticReturn, null, "Automatic return");
                actions++;
            }
        }

        if (containers.Count > 0)
        {
            await _db.SaveChangesAsync(ct);
        }

        return actions;
    }

    private async Task<DwellTimeConfiguration> EnsureConfigAsync(CancellationToken ct)
    {
        var cfg = await _db.DwellTimeConfigurations.FirstOrDefaultAsync(x => x.IsActive, ct);
        if (cfg is not null) return cfg;
        cfg = new DwellTimeConfiguration();
        _db.DwellTimeConfigurations.Add(cfg);
        await _db.SaveChangesAsync(ct);
        return cfg;
    }

    private void AddEvent(Container container, DwellEventType type, Guid? actorId, string? reason)
    {
        _db.DwellTimeEvents.Add(new DwellTimeEvent
        {
            ContainerId = container.Id,
            EventType = type,
            DwellDaysAtEvent = container.CurrentDwellDays,
            Reason = reason,
            TriggeredById = actorId
        });
    }

    private static void Recalc(Container container)
    {
        if (container.TerminalArrivalDate is null) return;
        var end = container.DwellPausedAt ?? DateTime.UtcNow;
        var days = (int)Math.Floor((end - container.TerminalArrivalDate.Value).TotalDays) - container.TotalPausedDays;
        container.CurrentDwellDays = Math.Max(0, days);
        container.LastDwellCalculationAt = DateTime.UtcNow;
    }

    private static DwellConfigDto MapConfig(DwellTimeConfiguration x) =>
        new(x.Id, x.NotificationThresholdDays, x.AutomaticReturnThresholdDays, x.Timezone,
            x.EnableAutomaticReturns, x.EnableNotifications, x.IsActive);
}

public class PreAdviceService : IPreAdviceService
{
    private readonly OptimusDbContext _db;
    private readonly IDocumentStore _docs;
    private readonly IQrCodeService _qr;
    private readonly INotificationService _notifications;
    private readonly IActivityLogService _activity;

    public PreAdviceService(
        OptimusDbContext db,
        IDocumentStore docs,
        IQrCodeService qr,
        INotificationService notifications,
        IActivityLogService activity)
    {
        _db = db;
        _docs = docs;
        _qr = qr;
        _notifications = notifications;
        _activity = activity;
    }

    public async Task<PreAdviceDto> SubmitAsync(SubmitPreAdviceRequest request, string? photoPath, Guid truckerId, CancellationToken ct = default)
    {
        var trucker = await _db.Truckers.FirstOrDefaultAsync(x => x.Id == truckerId, ct)
                      ?? throw new UnauthorizedAccessException("Trucker account required.");
        var container = await _db.Containers.FirstOrDefaultAsync(x => x.Id == request.ContainerId, ct)
                        ?? throw new KeyNotFoundException("Container not found.");
        if (container.Status != ContainerStatus.AvailableForReturn)
        {
            throw new InvalidOperationException("Container is not available for return.");
        }

        var terminal = await _db.Terminals.FirstOrDefaultAsync(x => x.Id == request.TerminalId && x.IsActive, ct)
                       ?? throw new KeyNotFoundException("Terminal not found or inactive.");

        TerminalSlot? slot = null;
        if (request.SlotId.HasValue)
        {
            slot = await _db.TerminalSlots.FirstOrDefaultAsync(x => x.Id == request.SlotId && x.TerminalId == terminal.Id, ct)
                   ?? throw new KeyNotFoundException("Slot not found.");
            if (slot.Status == SlotStatus.Full || slot.AssignedCount >= slot.Capacity)
            {
                throw new InvalidOperationException("Selected slot is full.");
            }
        }

        if (string.IsNullOrWhiteSpace(photoPath) || request.Latitude is null || request.Longitude is null)
        {
            throw new InvalidOperationException("Geotagged photo with latitude/longitude is required.");
        }

        var entity = new PreAdviceRequest
        {
            TruckerId = trucker.Id,
            ContainerId = container.Id,
            TerminalId = terminal.Id,
            AssignedSlotId = slot?.Id,
            ShippingLineId = container.ShippingLineId,
            Status = PreAdviceStatus.Pending,
            PaymentReference = request.PaymentReference,
            VerificationToken = Convert.ToHexString(RandomNumberGenerator.GetBytes(16))
        };
        entity.GeotagPhotos.Add(new GeotagPhoto
        {
            FilePath = photoPath,
            OriginalName = Path.GetFileName(photoPath),
            Latitude = request.Latitude,
            Longitude = request.Longitude,
            CapturedAt = DateTime.UtcNow
        });

        if (slot is not null)
        {
            slot.AssignedCount++;
            if (slot.AssignedCount >= slot.Capacity) slot.Status = SlotStatus.Full;
        }

        _db.PreAdviceRequests.Add(entity);
        await _db.SaveChangesAsync(ct);

        var terminals = await _db.Users.AsNoTracking().Where(x => x.Role == AppRoles.TerminalTeam).Select(x => x.Id).Take(5).ToListAsync(ct);
        foreach (var tid in terminals)
        {
            await _notifications.NotifyAsync(tid, "New pre-advice",
                $"Pre-advice for {container.ContainerNumber} at {terminal.Name}",
                "pre_advice", nameof(PreAdviceRequest), entity.Id, ct);
        }

        await _activity.LogAsync(truckerId, "preadvice.submit", nameof(PreAdviceRequest), entity.Id, container.ContainerNumber, ct);
        return await GetAsync(entity.Id, ct);
    }

    public async Task<PreAdviceDto> VerifyAsync(Guid id, VerifyPreAdviceRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        if (actorRole is not (AppRoles.TerminalTeam or AppRoles.SystemAdmin or AppRoles.ShippingLinesAdmin))
        {
            throw new UnauthorizedAccessException("Terminal team required.");
        }

        var entity = await _db.PreAdviceRequests
            .Include(x => x.Container)
            .Include(x => x.AssignedSlot)
            .Include(x => x.GeotagPhotos)
            .FirstOrDefaultAsync(x => x.Id == id, ct)
            ?? throw new KeyNotFoundException("Pre-advice not found.");
        if (entity.Status != PreAdviceStatus.Pending)
        {
            throw new InvalidOperationException("Only pending pre-advice can be verified.");
        }

        if (request.Approve)
        {
            if (request.SlotId.HasValue && entity.AssignedSlotId != request.SlotId)
            {
                var slot = await _db.TerminalSlots.FirstOrDefaultAsync(x => x.Id == request.SlotId && x.TerminalId == entity.TerminalId, ct)
                           ?? throw new KeyNotFoundException("Slot not found.");
                if (slot.AssignedCount >= slot.Capacity) throw new InvalidOperationException("Slot full.");
                if (entity.AssignedSlot is not null)
                {
                    entity.AssignedSlot.AssignedCount = Math.Max(0, entity.AssignedSlot.AssignedCount - 1);
                    entity.AssignedSlot.Status = SlotStatus.Available;
                }

                slot.AssignedCount++;
                if (slot.AssignedCount >= slot.Capacity) slot.Status = SlotStatus.Full;
                entity.AssignedSlotId = slot.Id;
            }

            foreach (var photo in entity.GeotagPhotos)
            {
                photo.IsVerified = photo.Latitude is not null && photo.Longitude is not null;
                photo.VerificationNotes = request.Notes;
            }

            entity.Status = PreAdviceStatus.Verified;
            entity.VerifiedAt = DateTime.UtcNow;
            entity.VerifiedById = actorId;
            entity.Container.Status = ContainerStatus.PaApproved;
        }
        else
        {
            if (string.IsNullOrWhiteSpace(request.RejectionReason))
            {
                throw new InvalidOperationException("Rejection reason required.");
            }

            entity.Status = PreAdviceStatus.Rejected;
            entity.RejectionReason = request.RejectionReason;
            entity.VerifiedAt = DateTime.UtcNow;
            entity.VerifiedById = actorId;
            if (entity.AssignedSlot is not null)
            {
                entity.AssignedSlot.AssignedCount = Math.Max(0, entity.AssignedSlot.AssignedCount - 1);
                entity.AssignedSlot.Status = SlotStatus.Available;
            }
        }

        await _db.SaveChangesAsync(ct);
        await _notifications.NotifyAsync(entity.TruckerId, request.Approve ? "Pre-advice verified" : "Pre-advice rejected",
            request.Approve ? "Your pre-advice was verified." : request.RejectionReason!,
            "pre_advice", nameof(PreAdviceRequest), entity.Id, ct);
        return await GetAsync(id, ct);
    }

    public async Task<PreAdviceDto> CompleteAsync(Guid id, CompletePreAdviceRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        if (actorRole is not (AppRoles.TerminalTeam or AppRoles.SystemAdmin or AppRoles.ShippingLinesAdmin))
        {
            throw new UnauthorizedAccessException("Terminal team required.");
        }

        var entity = await _db.PreAdviceRequests.Include(x => x.Container).Include(x => x.Terminal)
            .FirstOrDefaultAsync(x => x.Id == id, ct)
            ?? throw new KeyNotFoundException("Pre-advice not found.");
        if (entity.Status != PreAdviceStatus.Verified)
        {
            throw new InvalidOperationException("Only verified pre-advice can be completed.");
        }

        entity.PaymentVerified = true;
        entity.EdoNumber = string.IsNullOrWhiteSpace(request.EdoNumber)
            ? $"PA-{entity.Container.ContainerNumber}-{DateTime.UtcNow:yyyyMMdd}"
            : request.EdoNumber;
        entity.VerificationToken ??= Convert.ToHexString(RandomNumberGenerator.GetBytes(16));
        var verifyUrl = $"/verify/document/{entity.VerificationToken}";
        entity.QrCodePath = _qr.CreatePngFile("preadvice-qr", verifyUrl);
        entity.PackagePdfPath = _docs.CreatePlaceholderPdf("preadvice", $"Pre-Advice {entity.EdoNumber}",
            $"Container={entity.Container.ContainerNumber}\nTerminal={entity.Terminal.Name}\nVerify={verifyUrl}");
        entity.Status = PreAdviceStatus.Completed;
        entity.Container.Status = ContainerStatus.AtTerminal;
        entity.Container.TerminalArrivalDate ??= DateTime.UtcNow;
        await _db.SaveChangesAsync(ct);
        return await GetAsync(id, ct);
    }

    public async Task<PreAdviceDto> CancelAsync(Guid id, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        var entity = await _db.PreAdviceRequests.Include(x => x.Container).Include(x => x.AssignedSlot)
            .FirstOrDefaultAsync(x => x.Id == id, ct)
            ?? throw new KeyNotFoundException("Pre-advice not found.");
        if (entity.Status is PreAdviceStatus.Completed or PreAdviceStatus.Cancelled)
        {
            throw new InvalidOperationException("Cannot cancel in current status.");
        }

        if (actorRole == AppRoles.Trucker && entity.TruckerId != actorId)
        {
            throw new UnauthorizedAccessException("Not your pre-advice.");
        }

        entity.Status = PreAdviceStatus.Cancelled;
        if (entity.Container.Status == ContainerStatus.PaApproved)
        {
            entity.Container.Status = ContainerStatus.AvailableForReturn;
        }

        if (entity.AssignedSlot is not null)
        {
            entity.AssignedSlot.AssignedCount = Math.Max(0, entity.AssignedSlot.AssignedCount - 1);
            entity.AssignedSlot.Status = SlotStatus.Available;
        }

        await _db.SaveChangesAsync(ct);
        return await GetAsync(id, ct);
    }

    public async Task<PreAdviceDto> GetAsync(Guid id, CancellationToken ct = default)
    {
        var entity = await Query().FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Pre-advice not found.");
        return Map(entity);
    }

    public async Task<IReadOnlyList<PreAdviceDto>> ListAsync(string? status, Guid? truckerId, CancellationToken ct = default)
    {
        var q = Query();
        if (truckerId.HasValue) q = q.Where(x => x.TruckerId == truckerId);
        if (!string.IsNullOrWhiteSpace(status) && Enum.TryParse<PreAdviceStatus>(status, true, out var st))
        {
            q = q.Where(x => x.Status == st);
        }

        var items = await q.OrderByDescending(x => x.CreatedAt).Take(200).ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    private IQueryable<PreAdviceRequest> Query() =>
        _db.PreAdviceRequests.AsNoTracking()
            .Include(x => x.Container)
            .Include(x => x.Terminal)
            .Include(x => x.Trucker)
            .Include(x => x.GeotagPhotos);

    private static PreAdviceDto Map(PreAdviceRequest x) =>
        new(x.Id, x.ContainerId, x.Container.ContainerNumber, x.TerminalId, x.Terminal.Name, x.TruckerId,
            x.Trucker.FullName, x.Status.ToString(), x.AssignedSlotId, x.PaymentReference, x.PaymentVerified,
            x.RejectionReason, x.QrCodePath, x.PackagePdfPath, x.EdoNumber, x.VerificationToken, x.CreatedAt,
            x.GeotagPhotos.Select(p => new GeotagPhotoDto(p.Id, p.FilePath, p.OriginalName, p.Latitude, p.Longitude,
                p.CapturedAt, p.IsVerified)).ToList());
}

public class TruckerTokenService : ITruckerTokenService
{
    private readonly OptimusDbContext _db;

    public TruckerTokenService(OptimusDbContext db) => _db = db;

    public async Task<TruckerTokenDto> GenerateAsync(Guid truckerId, CancellationToken ct = default)
        => await IssueAsync(truckerId, ct);

    public async Task<TruckerTokenDto> RefreshAsync(Guid truckerId, CancellationToken ct = default)
        => await IssueAsync(truckerId, ct);

    public async Task RevokeAsync(Guid truckerId, CancellationToken ct = default)
    {
        var trucker = await _db.Truckers.FirstOrDefaultAsync(x => x.Id == truckerId, ct)
                      ?? throw new KeyNotFoundException("Trucker not found.");
        trucker.ApiTokenHash = null;
        trucker.ApiTokenExpiresAt = null;
        await _db.SaveChangesAsync(ct);
    }

    public async Task<bool> ValidateAsync(Guid truckerId, string rawToken, CancellationToken ct = default)
    {
        var trucker = await _db.Truckers.AsNoTracking().FirstOrDefaultAsync(x => x.Id == truckerId, ct);
        if (trucker?.ApiTokenHash is null || trucker.ApiTokenExpiresAt is null || trucker.ApiTokenExpiresAt < DateTime.UtcNow)
        {
            return false;
        }

        return FixedTimeEquals(trucker.ApiTokenHash, Hash(rawToken));
    }

    private async Task<TruckerTokenDto> IssueAsync(Guid truckerId, CancellationToken ct)
    {
        var trucker = await _db.Truckers.FirstOrDefaultAsync(x => x.Id == truckerId, ct)
                      ?? throw new KeyNotFoundException("Trucker not found.");
        var raw = Convert.ToBase64String(RandomNumberGenerator.GetBytes(32));
        trucker.ApiTokenHash = Hash(raw);
        trucker.ApiTokenExpiresAt = DateTime.UtcNow.AddDays(30);
        trucker.LastActivityAt = DateTime.UtcNow;
        await _db.SaveChangesAsync(ct);
        return new TruckerTokenDto(raw, trucker.ApiTokenExpiresAt.Value);
    }

    private static string Hash(string raw)
    {
        var bytes = SHA256.HashData(Encoding.UTF8.GetBytes(raw));
        return Convert.ToHexString(bytes);
    }

    private static bool FixedTimeEquals(string a, string b)
    {
        var ba = Encoding.UTF8.GetBytes(a);
        var bb = Encoding.UTF8.GetBytes(b);
        return ba.Length == bb.Length && CryptographicOperations.FixedTimeEquals(ba, bb);
    }
}

public class DwellMonitoringHostedService : BackgroundService
{
    private readonly IServiceScopeFactory _scopeFactory;
    private readonly ILogger<DwellMonitoringHostedService> _logger;

    public DwellMonitoringHostedService(IServiceScopeFactory scopeFactory, ILogger<DwellMonitoringHostedService> logger)
    {
        _scopeFactory = scopeFactory;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                using var scope = _scopeFactory.CreateScope();
                var dwell = scope.ServiceProvider.GetRequiredService<IDwellService>();
                var count = await dwell.ProcessMonitoringAsync(stoppingToken);
                if (count > 0)
                {
                    _logger.LogInformation("Dwell monitoring actions: {Count}", count);
                }
            }
            catch (Exception ex)
            {
                _logger.LogError(ex, "Dwell monitoring job failed");
            }

            await Task.Delay(TimeSpan.FromMinutes(5), stoppingToken);
        }
    }
}
