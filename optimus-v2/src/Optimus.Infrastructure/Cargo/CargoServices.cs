using System.Globalization;
using System.Text;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Caching.Distributed;
using Microsoft.Extensions.Logging;
using Optimus.Application.Cargo.Dtos;
using Optimus.Application.Cargo.Interfaces;
using Optimus.Application.Yard.Interfaces;
using Optimus.Domain.Entities;
using Optimus.Domain.Enums;
using Optimus.Infrastructure.Persistence;
using Optimus.Infrastructure.Shipping;
using Optimus.Shared.Constants;

namespace Optimus.Infrastructure.Cargo;

public class DocumentStore : IDocumentStore
{
    private readonly string _root;

    public DocumentStore()
    {
        _root = Path.Combine(Directory.GetCurrentDirectory(), "wwwroot", "uploads");
        Directory.CreateDirectory(_root);
    }

    public async Task<string> SaveAsync(string category, string fileName, Stream content, CancellationToken ct = default)
    {
        var dir = Path.Combine(_root, category);
        Directory.CreateDirectory(dir);
        var safe = $"{DateTime.UtcNow:yyyyMMddHHmmss}_{Guid.NewGuid():N}_{Path.GetFileName(fileName)}";
        var full = Path.Combine(dir, safe);
        await using var fs = File.Create(full);
        await content.CopyToAsync(fs, ct);
        return $"/uploads/{category}/{safe}";
    }

    public string CreatePlaceholderPdf(string category, string title, string body)
    {
        var dir = Path.Combine(_root, category);
        Directory.CreateDirectory(dir);
        var safe = $"{DateTime.UtcNow:yyyyMMddHHmmss}_{Guid.NewGuid():N}.pdf";
        var full = Path.Combine(dir, safe);
        File.WriteAllBytes(full, MinimalPdfWriter.Build(title, body));
        return $"/uploads/{category}/{safe}";
    }
}

public class ActivityLogService : IActivityLogService
{
    private readonly OptimusDbContext _db;

    public ActivityLogService(OptimusDbContext db) => _db = db;

    public async Task LogAsync(Guid? actorId, string action, string entityType, Guid? entityId, string? details, CancellationToken ct = default)
    {
        _db.ActivityLogs.Add(new ActivityLog
        {
            ActorId = actorId,
            Action = action,
            EntityType = entityType,
            EntityId = entityId,
            Details = details
        });
        await _db.SaveChangesAsync(ct);
    }

    public async Task<IReadOnlyList<ActivityLogDto>> ListRecentAsync(int take = 50, CancellationToken ct = default)
    {
        return await _db.ActivityLogs.AsNoTracking()
            .Include(x => x.Actor)
            .OrderByDescending(x => x.CreatedAt)
            .Take(take)
            .Select(x => new ActivityLogDto(x.Id, x.Action, x.EntityType, x.EntityId, x.Details, x.CreatedAt, x.Actor != null ? x.Actor.FirstName + " " + x.Actor.LastName : null))
            .ToListAsync(ct);
    }
}

public class ExchangeRateService : IExchangeRateService
{
    private readonly IDistributedCache _cache;
    private readonly IHttpClientFactory _httpClientFactory;
    private readonly ILogger<ExchangeRateService> _logger;
    private const string CacheKey = "fx:usd-php";

    public ExchangeRateService(IDistributedCache cache, IHttpClientFactory httpClientFactory, ILogger<ExchangeRateService> logger)
    {
        _cache = cache;
        _httpClientFactory = httpClientFactory;
        _logger = logger;
    }

    public async Task<ExchangeRateDto> GetUsdPhpAsync(CancellationToken ct = default)
    {
        var cached = await _cache.GetStringAsync(CacheKey, ct);
        if (!string.IsNullOrWhiteSpace(cached) && decimal.TryParse(cached, NumberStyles.Any, CultureInfo.InvariantCulture, out var cachedRate))
        {
            return new ExchangeRateDto("USD", "PHP", cachedRate, DateTime.UtcNow, true);
        }

        try
        {
            var client = _httpClientFactory.CreateClient();
            using var response = await client.GetAsync("https://api.frankfurter.app/latest?from=USD&to=PHP", ct);
            response.EnsureSuccessStatusCode();
            var json = await response.Content.ReadAsStringAsync(ct);
            using var doc = System.Text.Json.JsonDocument.Parse(json);
            var rate = doc.RootElement.GetProperty("rates").GetProperty("PHP").GetDecimal();
            await _cache.SetStringAsync(CacheKey, rate.ToString(CultureInfo.InvariantCulture), new DistributedCacheEntryOptions
            {
                AbsoluteExpirationRelativeToNow = TimeSpan.FromHours(6)
            }, ct);
            return new ExchangeRateDto("USD", "PHP", rate, DateTime.UtcNow, false);
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "FX fetch failed; using fallback rate");
            return new ExchangeRateDto("USD", "PHP", 56.50m, DateTime.UtcNow, false);
        }
    }
}

public class PaymentFeeService : IPaymentFeeService
{
    private readonly OptimusDbContext _db;
    private readonly IActivityLogService _activity;

    public PaymentFeeService(OptimusDbContext db, IActivityLogService activity)
    {
        _db = db;
        _activity = activity;
    }

    public async Task<PaymentFeeDto> GetActiveAsync(string feeType, CancellationToken ct = default)
    {
        var fee = await _db.PaymentFeeConfigurations.AsNoTracking()
            .Where(x => x.FeeType == feeType && x.IsActive)
            .OrderByDescending(x => x.CreatedAt)
            .FirstOrDefaultAsync(ct);

        if (fee is null)
        {
            var amount = feeType == "edo" ? 750m : 500m;
            return new PaymentFeeDto(Guid.Empty, feeType, amount, true, null);
        }

        return Map(fee);
    }

    public async Task<IReadOnlyList<PaymentFeeDto>> ListAsync(CancellationToken ct = default)
    {
        var items = await _db.PaymentFeeConfigurations.AsNoTracking()
            .OrderByDescending(x => x.CreatedAt)
            .ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    public async Task<PaymentFeeDto> UpsertAsync(UpsertPaymentFeeRequest request, string? qrPath, Guid actorId, CancellationToken ct = default)
    {
        var active = await _db.PaymentFeeConfigurations
            .Where(x => x.FeeType == request.FeeType && x.IsActive)
            .ToListAsync(ct);
        foreach (var item in active)
        {
            item.IsActive = false;
        }

        var entity = new PaymentFeeConfiguration
        {
            FeeType = request.FeeType,
            Amount = request.Amount,
            PreviousAmount = active.FirstOrDefault()?.Amount,
            ConfiguredById = actorId,
            IsActive = true,
            QrCodePath = qrPath ?? active.FirstOrDefault()?.QrCodePath
        };
        _db.PaymentFeeConfigurations.Add(entity);
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "payment_fee.upsert", nameof(PaymentFeeConfiguration), entity.Id, $"{request.FeeType}={request.Amount}", ct);
        return Map(entity);
    }

    private static PaymentFeeDto Map(PaymentFeeConfiguration x) =>
        new(x.Id, x.FeeType, x.Amount, x.IsActive, x.QrCodePath);
}

public class ManifestWorkflowService : IManifestWorkflowService
{
    private readonly OptimusDbContext _db;
    private readonly IDocumentStore _docs;
    private readonly IActivityLogService _activity;
    private readonly IExchangeRateService _fx;
    private readonly INotificationService _notifications;

    public ManifestWorkflowService(
        OptimusDbContext db,
        IDocumentStore docs,
        IActivityLogService activity,
        IExchangeRateService fx,
        INotificationService notifications)
    {
        _db = db;
        _docs = docs;
        _activity = activity;
        _fx = fx;
        _notifications = notifications;
    }

    public async Task<ManifestDto> CreateAsync(CreateManifestRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        EnsureRole(actorRole, AppRoles.SlStaff, AppRoles.ShippingLinesAdmin, AppRoles.SystemAdmin);
        if (await _db.Manifests.AnyAsync(x => x.ManifestNumber == request.ManifestNumber.Trim(), ct))
        {
            throw new InvalidOperationException("Manifest number already exists.");
        }

        var soleLineId = await SoleShippingLine.RequireIdAsync(_db, ct);
        var entity = new Manifest
        {
            ManifestNumber = request.ManifestNumber.Trim(),
            ShippingLineId = soleLineId,
            VesselName = request.VesselName,
            VoyageNumber = request.VoyageNumber,
            ArrivalDate = request.ArrivalDate,
            BlNumber = request.BlNumber,
            CreatedById = actorId,
            WorkflowState = WorkflowState.ManifestUploaded
        };
        _db.Manifests.Add(entity);
        await TransitionAsync(entity, WorkflowState.ManifestUploaded, actorId, actorRole, "Created", initial: true, ct);
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "manifest.create", nameof(Manifest), entity.Id, entity.ManifestNumber, ct);

        if (request.ConsigneeId is Guid consigneeId)
        {
            await DeclareConsigneeAsync(
                entity.Id,
                new DeclareConsigneeRequest(consigneeId, request.BrokerId),
                actorId,
                actorRole,
                ct);
            return await GenerateNoaAsync(entity.Id, actorId, actorRole, ct, request.PortLocation);
        }

        return await GetAsync(entity.Id, ct);
    }

    public async Task<ManifestDto> GetAsync(Guid id, CancellationToken ct = default)
    {
        var entity = await Query().FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Manifest not found.");
        return Map(entity);
    }

    public async Task<IReadOnlyList<ManifestDto>> ListAsync(Guid? shippingLineId, Guid? brokerId, Guid? consigneeId, CancellationToken ct = default)
    {
        var q = Query();
        if (shippingLineId.HasValue) q = q.Where(x => x.ShippingLineId == shippingLineId);
        if (brokerId.HasValue) q = q.Where(x => x.BrokerId == brokerId);
        if (consigneeId.HasValue) q = q.Where(x => x.ConsigneeId == consigneeId);
        var items = await q.OrderByDescending(x => x.CreatedAt).ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    public async Task<IReadOnlyList<AccreditedConsigneeOptionDto>> ListAccreditedConsigneesAsync(
        Guid actorId, string actorRole, CancellationToken ct = default)
    {
        EnsureRole(actorRole, AppRoles.SlStaff, AppRoles.ShippingLinesAdmin, AppRoles.SystemAdmin);
        var lineId = await ResolveActorShippingLineIdAsync(actorId, actorRole, ct);

        var applicantIds = await _db.AccreditationSubmissions.AsNoTracking()
            .Where(a =>
                a.ShippingLineId == lineId
                && a.Status == AccreditationStatus.Approved
                && a.Applicant.Role == AppRoles.Consignee)
            .Select(a => a.ApplicantId)
            .Distinct()
            .ToListAsync(ct);

        var consignees = await _db.Consignees.AsNoTracking()
            .Where(c => applicantIds.Contains(c.Id))
            .OrderBy(c => c.BusinessName)
            .ThenBy(c => c.Email)
            .ToListAsync(ct);

        return consignees
            .Select(c => new AccreditedConsigneeOptionDto(c.Id, c.BusinessName, c.FullName, c.Email))
            .ToList();
    }

    public async Task<IReadOnlyList<ConsigneeBrokerOptionDto>> ListBrokersForConsigneeAsync(
        Guid consigneeId, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        var allowedStaff = actorRole is AppRoles.SlStaff or AppRoles.ShippingLinesAdmin or AppRoles.SystemAdmin;
        var allowedConsignee = actorRole == AppRoles.Consignee && actorId == consigneeId;
        if (!allowedStaff && !allowedConsignee)
        {
            throw new UnauthorizedAccessException("Not allowed to list brokers for this consignee.");
        }

        if (!await _db.Consignees.AnyAsync(c => c.Id == consigneeId, ct))
        {
            throw new KeyNotFoundException("Consignee not found.");
        }

        var brokers = await _db.ConsigneeBrokerRelationships.AsNoTracking()
            .Include(r => r.Broker)
            .Where(r => r.ConsigneeId == consigneeId && r.Status == RelationshipStatus.Active)
            .OrderBy(r => r.Broker.LastName)
            .ThenBy(r => r.Broker.FirstName)
            .ToListAsync(ct);

        return brokers
            .Select(r => new ConsigneeBrokerOptionDto(
                r.BrokerId,
                r.Broker.FullName,
                r.Broker.Email,
                r.Broker.BusinessAddress))
            .ToList();
    }

    public async Task<ManifestDto> DeclareConsigneeAsync(Guid manifestId, DeclareConsigneeRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        EnsureRole(actorRole, AppRoles.SlStaff, AppRoles.ShippingLinesAdmin, AppRoles.SystemAdmin);
        var entity = await _db.Manifests.FirstOrDefaultAsync(x => x.Id == manifestId, ct)
                     ?? throw new KeyNotFoundException("Manifest not found.");
        if (!await _db.Consignees.AnyAsync(x => x.Id == request.ConsigneeId, ct))
        {
            throw new InvalidOperationException("Consignee not found.");
        }

        var accredited = await _db.AccreditationSubmissions.AsNoTracking().AnyAsync(a =>
            a.ApplicantId == request.ConsigneeId
            && a.ShippingLineId == entity.ShippingLineId
            && a.Status == AccreditationStatus.Approved
            && a.Applicant.Role == AppRoles.Consignee, ct);
        if (!accredited)
        {
            throw new InvalidOperationException("Consignee is not accredited to this shipping line.");
        }

        // Broker is assigned later by the consignee at NOA Generated — do not set here.
        entity.ConsigneeId = request.ConsigneeId;
        entity.BrokerId = null;
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(
            actorId,
            "manifest.declare_consignee",
            nameof(Manifest),
            entity.Id,
            $"consignee={request.ConsigneeId}",
            ct);
        return await GetAsync(entity.Id, ct);
    }

    public async Task<ManifestDto> AssignBrokerAsync(
        Guid manifestId,
        AssignBrokerRequest request,
        Guid actorId,
        string actorRole,
        CancellationToken ct = default)
    {
        EnsureRole(actorRole, AppRoles.Consignee, AppRoles.SystemAdmin);
        var entity = await _db.Manifests.FirstOrDefaultAsync(x => x.Id == manifestId, ct)
                     ?? throw new KeyNotFoundException("Manifest not found.");

        if (entity.ConsigneeId is null)
        {
            throw new InvalidOperationException("Manifest has no consignee.");
        }

        if (actorRole == AppRoles.Consignee && entity.ConsigneeId != actorId)
        {
            throw new UnauthorizedAccessException("You can only assign a broker on your own manifests.");
        }

        if (entity.WorkflowState != WorkflowState.NoaGenerated)
        {
            throw new InvalidOperationException(
                "Broker can only be assigned when the manifest is in NOA Generated status.");
        }

        if (entity.BrokerId is not null)
        {
            throw new InvalidOperationException(
                "A broker is already assigned. Use broker transfer to change brokers.");
        }

        var hasActiveLink = await _db.ConsigneeBrokerRelationships.AsNoTracking().AnyAsync(r =>
            r.ConsigneeId == entity.ConsigneeId
            && r.BrokerId == request.BrokerId
            && r.Status == RelationshipStatus.Active, ct);
        if (!hasActiveLink)
        {
            throw new InvalidOperationException(
                "Selected broker is not actively connected to this consignee. Use a referral link first.");
        }

        if (!await _db.Brokers.AnyAsync(b => b.Id == request.BrokerId, ct))
        {
            throw new InvalidOperationException("Broker not found.");
        }

        entity.BrokerId = request.BrokerId;
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(
            actorId,
            "manifest.broker_assigned",
            nameof(Manifest),
            entity.Id,
            $"broker={request.BrokerId}",
            ct);

        await _notifications.NotifyAsync(
            request.BrokerId,
            "New Manifest Assigned",
            $"You have been assigned to manifest {entity.ManifestNumber}. Please review and process the shipment.",
            "manifest",
            nameof(Manifest),
            entity.Id,
            ct);

        return await GetAsync(entity.Id, ct);
    }

    public async Task<ManifestDto> GenerateNoaAsync(
        Guid manifestId,
        Guid actorId,
        string actorRole,
        CancellationToken ct = default,
        string? portLocation = null)
    {
        EnsureRole(actorRole, AppRoles.SlStaff, AppRoles.ShippingLinesAdmin, AppRoles.SystemAdmin);
        var entity = await _db.Manifests
                         .Include(x => x.Consignee)
                         .Include(x => x.ShippingLine)
                         .FirstOrDefaultAsync(x => x.Id == manifestId, ct)
                     ?? throw new KeyNotFoundException("Manifest not found.");
        if (entity.ConsigneeId is null)
        {
            throw new InvalidOperationException("Declare consignee before generating NOA.");
        }

        if (await _db.Noas.AnyAsync(x => x.ManifestId == entity.Id, ct))
        {
            throw new InvalidOperationException("NOA already exists for this manifest.");
        }

        EnsureCanTransition(entity.WorkflowState, WorkflowState.NoaGenerated);

        var noaNumber = $"NOA-{DateTime.UtcNow:yyyyMMdd}-{Random.Shared.Next(1000, 9999)}";
        var port = string.IsNullOrWhiteSpace(portLocation) ? "Manila" : portLocation.Trim();
        var consigneeName = entity.Consignee is null
            ? "—"
            : string.IsNullOrWhiteSpace(entity.Consignee.BusinessName)
                ? entity.Consignee.FullName
                : entity.Consignee.BusinessName;
        var lineName = entity.ShippingLine?.BrandName ?? "—";
        var eta = entity.ArrivalDate?.ToString("yyyy-MM-dd") ?? "—";

        var pdfBody = new StringBuilder()
            .AppendLine($"NOA Number: {noaNumber}")
            .AppendLine($"Shipping Line: {lineName}")
            .AppendLine($"Manifest #: {entity.ManifestNumber}")
            .AppendLine($"BL Number: {entity.BlNumber ?? "—"}")
            .AppendLine($"Vessel: {entity.VesselName ?? "—"}")
            .AppendLine($"Voyage #: {entity.VoyageNumber ?? "—"}")
            .AppendLine($"ETA: {eta}")
            .AppendLine($"Port Location: {port}")
            .AppendLine($"Consignee: {consigneeName}")
            .AppendLine($"Generated (UTC): {DateTime.UtcNow:yyyy-MM-dd HH:mm}")
            .ToString();

        var pdf = _docs.CreatePlaceholderPdf("noa", $"Notice of Arrival — {noaNumber}", pdfBody);

        var noa = new Noa
        {
            NoaNumber = noaNumber,
            BlNumber = entity.BlNumber,
            VesselName = entity.VesselName,
            Eta = entity.ArrivalDate,
            PortLocation = port,
            ConsigneeId = entity.ConsigneeId,
            CreatedById = actorId,
            PdfPath = pdf,
            ManifestId = entity.Id
        };
        _db.Noas.Add(noa);

        var from = entity.WorkflowState;
        entity.WorkflowState = WorkflowState.NoaGenerated;
        _db.WorkflowStateHistories.Add(new WorkflowStateHistory
        {
            ManifestId = entity.Id,
            FromState = from,
            ToState = WorkflowState.NoaGenerated,
            ActorId = actorId,
            ActorRole = actorRole,
            TransitionReason = "NOA generated"
        });

        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "noa.generate", nameof(Noa), noa.Id, noaNumber, ct);

        var notifyMessage = $"NOA {noaNumber} created for manifest {entity.ManifestNumber}.";
        if (entity.ConsigneeId is Guid consigneeId)
        {
            await _notifications.NotifyAsync(
                consigneeId,
                "NOA created",
                $"{notifyMessage} Assign a connected broker to process this transaction.",
                "noa",
                nameof(Manifest),
                entity.Id,
                ct);
        }

        if (entity.BrokerId is Guid brokerId)
        {
            await _notifications.NotifyAsync(
                brokerId,
                "NOA assigned",
                $"{notifyMessage} You are assigned to process this transaction.",
                "noa",
                nameof(Manifest),
                entity.Id,
                ct);
        }

        return await GetAsync(entity.Id, ct);
    }

    public async Task<ManifestDto> GenerateBlAsync(
        Guid manifestId,
        GenerateBlRequest request,
        Guid actorId,
        string actorRole,
        CancellationToken ct = default)
    {
        EnsureRole(actorRole, AppRoles.SlStaff, AppRoles.ShippingLinesAdmin, AppRoles.SystemAdmin);
        var entity = await _db.Manifests
                         .Include(x => x.Consignee)
                         .Include(x => x.ShippingLine)
                         .Include(x => x.Noa)
                         .FirstOrDefaultAsync(x => x.Id == manifestId, ct)
                     ?? throw new KeyNotFoundException("Manifest not found.");

        if (!string.IsNullOrWhiteSpace(entity.BlPdfPath) || !string.IsNullOrWhiteSpace(entity.ManifestFilePath))
        {
            throw new InvalidOperationException("Manifest/BL has already been generated for this NOA.");
        }

        if (entity.BrokerId is null)
        {
            throw new InvalidOperationException(
                "Cannot generate Manifest/BL until the consignee assigns a broker. Wait for broker assignment while the status is NOA Generated.");
        }

        EnsureCanTransition(entity.WorkflowState, WorkflowState.BlGenerated);

        var manifestBlNumber = (request.ManifestBlNumber ?? string.Empty).Trim();
        if (string.IsNullOrWhiteSpace(manifestBlNumber))
        {
            throw new InvalidOperationException("Manifest/BL Number is required.");
        }

        if (!string.Equals(entity.ManifestNumber, manifestBlNumber, StringComparison.OrdinalIgnoreCase)
            && await _db.Manifests.AnyAsync(x => x.ManifestNumber == manifestBlNumber && x.Id != entity.Id, ct))
        {
            throw new InvalidOperationException("Manifest/BL Number already exists. Please use a unique number.");
        }

        entity.ManifestNumber = manifestBlNumber;
        entity.BlNumber = manifestBlNumber;
        entity.ArrivalDate = request.ActualArrivalDate.ToUniversalTime();
        if (entity.Noa is not null)
        {
            entity.Noa.Eta = entity.ArrivalDate;
        }

        var containers = await _db.Containers.AsNoTracking()
            .Include(c => c.ContainerType)
            .Include(c => c.ContainerSize)
            .Where(c => c.ManifestId == entity.Id)
            .OrderBy(c => c.ContainerNumber)
            .ToListAsync(ct);

        var consigneeName = entity.Consignee is null
            ? "—"
            : string.IsNullOrWhiteSpace(entity.Consignee.BusinessName)
                ? $"{entity.Consignee.FirstName} {entity.Consignee.LastName}".Trim()
                : entity.Consignee.BusinessName;
        var lineName = entity.ShippingLine?.BrandName ?? "—";
        var arrival = entity.ArrivalDate?.ToString("yyyy-MM-dd HH:mm") ?? "—";
        var port = entity.Noa?.PortLocation ?? "—";

        var pdfBody = new StringBuilder()
            .AppendLine($"Manifest/BL Number: {manifestBlNumber}")
            .AppendLine($"NOA Number: {entity.Noa?.NoaNumber ?? "—"}")
            .AppendLine($"B/L Number: {entity.BlNumber ?? entity.Noa?.BlNumber ?? "—"}")
            .AppendLine($"Shipping Line: {lineName}")
            .AppendLine($"Vessel: {entity.VesselName ?? entity.Noa?.VesselName ?? "—"}")
            .AppendLine($"Voyage #: {entity.VoyageNumber ?? "—"}")
            .AppendLine($"Actual Arrival: {arrival}")
            .AppendLine($"Port Location: {port}")
            .AppendLine($"Consignee: {consigneeName}")
            .AppendLine($"Containers: {containers.Count}")
            .AppendLine("")
            .AppendLine("Container Summary");

        decimal totalTeu = 0;
        for (var i = 0; i < containers.Count; i++)
        {
            var c = containers[i];
            var teu = c.ContainerSize?.TeuValue ?? 1m;
            totalTeu += teu;
            pdfBody.AppendLine(
                $"{i + 1}. {c.ContainerNumber} | {c.ContainerType?.Name ?? c.ContainerType?.Code ?? "—"} | {c.ContainerSize?.Name ?? c.ContainerSize?.Code ?? "—"} | {teu} TEU");
        }

        if (containers.Count == 0)
        {
            pdfBody.AppendLine("(No containers linked to this manifest yet)");
        }
        else
        {
            pdfBody.AppendLine($"Total TEU: {totalTeu}");
        }

        pdfBody.AppendLine($"Generated (UTC): {DateTime.UtcNow:yyyy-MM-dd HH:mm}");

        var pdfPath = _docs.CreatePlaceholderPdf(
            "manifest-bl",
            $"Manifest / Bill of Lading — {manifestBlNumber}",
            pdfBody.ToString());

        entity.BlPdfPath = pdfPath;
        entity.ManifestFilePath = pdfPath;

        await TransitionAsync(entity, WorkflowState.BlGenerated, actorId, actorRole, "Manifest/BL PDF generated", ct: ct);
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "bl.generate", nameof(Manifest), entity.Id, manifestBlNumber, ct);
        return await GetAsync(entity.Id, ct);
    }

    public async Task<ManifestDto> UploadBlAsync(Guid manifestId, string blFilePath, string? blNumber, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        EnsureRole(actorRole, AppRoles.Broker, AppRoles.SlStaff, AppRoles.SystemAdmin);
        var entity = await _db.Manifests.FirstOrDefaultAsync(x => x.Id == manifestId, ct)
                     ?? throw new KeyNotFoundException("Manifest not found.");

        if (actorRole == AppRoles.Broker && entity.BrokerId != actorId)
        {
            throw new UnauthorizedAccessException("Broker is not assigned to this manifest.");
        }

        if (entity.WorkflowState != WorkflowState.BlGenerated)
        {
            throw new InvalidOperationException(
                "BL can only be uploaded when the manifest is in BL Generated status.");
        }

        if (!string.IsNullOrWhiteSpace(entity.BlFilePath))
        {
            throw new InvalidOperationException("A BL document has already been uploaded for this manifest.");
        }

        var normalizedBl = (blNumber ?? string.Empty).Trim().ToUpperInvariant();
        if (string.IsNullOrWhiteSpace(normalizedBl))
        {
            throw new InvalidOperationException("BL Number is required.");
        }

        if (!string.IsNullOrWhiteSpace(entity.BlNumber)
            && !string.Equals(entity.BlNumber.Trim(), normalizedBl, StringComparison.OrdinalIgnoreCase)
            && !string.Equals(entity.ManifestNumber.Trim(), normalizedBl, StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidOperationException(
                $"BL Number must match the shipping-line issued number ({entity.BlNumber}).");
        }

        EnsureCanTransition(entity.WorkflowState, WorkflowState.BlUploaded);
        entity.BlFilePath = blFilePath;
        entity.BlNumber = normalizedBl;

        await TransitionAsync(entity, WorkflowState.BlUploaded, actorId, actorRole, "BL uploaded", ct: ct);
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "bl.upload", nameof(Manifest), entity.Id, blFilePath, ct);

        await _notifications.NotifyAsync(
            entity.CreatedById,
            "BL uploaded",
            $"Broker uploaded BL {normalizedBl} for manifest {entity.ManifestNumber}. Accounting can generate billing.",
            "bl",
            nameof(Manifest),
            entity.Id,
            ct);

        var adminIds = await _db.Users.AsNoTracking()
            .Where(u =>
                u.Role == AppRoles.ShippingLinesAdmin
                && u.ManagedShippingLineId == entity.ShippingLineId
                && u.Id != entity.CreatedById)
            .Select(u => u.Id)
            .ToListAsync(ct);
        foreach (var adminId in adminIds)
        {
            await _notifications.NotifyAsync(
                adminId,
                "BL uploaded",
                $"Broker uploaded BL {normalizedBl} for manifest {entity.ManifestNumber}.",
                "bl",
                nameof(Manifest),
                entity.Id,
                ct);
        }

        return await GetAsync(entity.Id, ct);
    }

    public async Task<ManifestDto> GenerateBillingAsync(Guid manifestId, GenerateBillingRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        EnsureRole(actorRole, AppRoles.Accounting, AppRoles.SystemAdmin);
        var entity = await _db.Manifests.FirstOrDefaultAsync(x => x.Id == manifestId, ct)
                     ?? throw new KeyNotFoundException("Manifest not found.");
        if (await _db.Billings.AnyAsync(x => x.ManifestId == entity.Id, ct))
        {
            throw new InvalidOperationException("Billing already exists for this manifest.");
        }

        if (entity.WorkflowState != WorkflowState.BlUploaded)
        {
            throw new InvalidOperationException(
                "Billing can only be generated after the broker uploads the BL (BL Uploaded status).");
        }

        EnsureCanTransition(entity.WorkflowState, WorkflowState.BillingGenerated);

        var currency = (request.Currency ?? "USD").Trim().ToUpperInvariant();
        if (currency is not ("USD" or "PHP"))
        {
            throw new InvalidOperationException("Currency must be USD or PHP.");
        }

        var freight = Math.Round(request.FreightCharges, 2);
        var thc = Math.Round(request.ThcCharges, 2);
        var lines = (request.AdditionalChargeLines ?? Array.Empty<BillingChargeLineDto>())
            .Where(x => !string.IsNullOrWhiteSpace(x.Description) && x.Amount > 0)
            .Select(x => new BillingChargeLineDto(x.Description.Trim(), Math.Round(x.Amount, 2)))
            .ToList();

        var additional = lines.Count > 0
            ? lines.Sum(x => x.Amount)
            : Math.Round(request.AdditionalCharges, 2);

        if (freight < 0 || thc < 0 || additional < 0)
        {
            throw new InvalidOperationException("Charge amounts cannot be negative.");
        }

        var total = freight + thc + additional;
        if (total <= 0)
        {
            throw new InvalidOperationException("Enter at least one charge amount greater than zero.");
        }

        decimal? rate = null;
        decimal? php = null;
        if (currency == "USD")
        {
            if (request.ExchangeRate is > 0)
            {
                rate = request.ExchangeRate;
            }
            else
            {
                var fx = await _fx.GetUsdPhpAsync(ct);
                rate = fx.Rate;
            }

            php = Math.Round(total * rate.Value, 2);
        }
        else
        {
            php = total;
        }

        var lineText = lines.Count == 0
            ? $"Additional={additional}"
            : string.Join("\n", lines.Select(l => $"  - {l.Description}: {l.Amount:0.00}"));

        var pdfBody = new StringBuilder()
            .AppendLine($"Manifest: {entity.ManifestNumber}")
            .AppendLine($"BL: {entity.BlNumber ?? "—"}")
            .AppendLine($"Currency: {currency}")
            .AppendLine(rate is null ? "Exchange rate: n/a" : $"Exchange rate: 1 USD = {rate:0.0000} PHP")
            .AppendLine($"Freight: {freight:0.00}")
            .AppendLine($"THC: {thc:0.00}")
            .AppendLine("Additional charges:")
            .AppendLine(lineText)
            .AppendLine($"Total: {total:0.00} {currency}")
            .Append(php is null ? "" : $"Total PHP: {php:0.00}")
            .ToString();

        var pdf = _docs.CreatePlaceholderPdf("billing", $"Billing {entity.ManifestNumber}", pdfBody);

        var billing = new Billing
        {
            ManifestId = entity.Id,
            FreightCharges = freight,
            ThcCharges = thc,
            AdditionalCharges = additional,
            TotalAmount = total,
            Currency = currency,
            ExchangeRate = rate,
            TotalAmountPhp = php,
            PdfPath = pdf,
            GeneratedById = actorId
        };
        _db.Billings.Add(billing);

        var from = entity.WorkflowState;
        entity.WorkflowState = WorkflowState.BillingGenerated;
        _db.WorkflowStateHistories.Add(new WorkflowStateHistory
        {
            ManifestId = entity.Id,
            FromState = from,
            ToState = WorkflowState.BillingGenerated,
            ActorId = actorId,
            ActorRole = actorRole,
            TransitionReason = "Billing generated"
        });

        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "billing.generate", nameof(Billing), billing.Id, $"{total} {currency}", ct);

        var billingMsg = $"Billing generated for manifest {entity.ManifestNumber}: {total} {currency}.";
        if (entity.BrokerId is Guid brokerId)
        {
            await _notifications.NotifyAsync(brokerId, "Billing generated", billingMsg, "billing", nameof(Manifest), entity.Id, ct);
        }
        if (entity.ConsigneeId is Guid consigneeId)
        {
            await _notifications.NotifyAsync(consigneeId, "Billing generated", billingMsg, "billing", nameof(Manifest), entity.Id, ct);
        }

        return await GetAsync(entity.Id, ct);
    }

    public async Task<BulkImportResultDto> BulkImportAsync(Guid shippingLineId, string fileName, Stream csvStream, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        EnsureRole(actorRole, AppRoles.SlStaff, AppRoles.ShippingLinesAdmin, AppRoles.SystemAdmin);
        shippingLineId = await SoleShippingLine.RequireIdAsync(_db, ct);
        using var reader = new StreamReader(csvStream);
        var content = await reader.ReadToEndAsync(ct);
        var lines = content.Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .Where(l => !l.StartsWith("ManifestNumber", StringComparison.OrdinalIgnoreCase))
            .ToList();

        var job = new BulkImportJob
        {
            FileName = fileName,
            Status = "processing",
            TotalRows = lines.Count,
            CreatedById = actorId,
            ShippingLineId = shippingLineId
        };
        _db.BulkImportJobs.Add(job);
        await _db.SaveChangesAsync(ct);

        var errors = new StringBuilder();
        foreach (var line in lines)
        {
            job.ProcessedRows++;
            try
            {
                var parts = line.Split(',');
                if (parts.Length < 1 || string.IsNullOrWhiteSpace(parts[0]))
                {
                    throw new InvalidOperationException("ManifestNumber required");
                }

                await CreateAsync(new CreateManifestRequest(
                    parts[0].Trim(),
                    shippingLineId,
                    parts.ElementAtOrDefault(1)?.Trim(),
                    parts.ElementAtOrDefault(2)?.Trim(),
                    DateTime.TryParse(parts.ElementAtOrDefault(3), out var eta) ? eta : null,
                    parts.ElementAtOrDefault(4)?.Trim()), actorId, actorRole, ct);
                job.SuccessCount++;
            }
            catch (Exception ex)
            {
                job.ErrorCount++;
                errors.AppendLine($"{line} => {ex.Message}");
            }
        }

        job.Status = "completed";
        job.ErrorLog = errors.Length == 0 ? null : errors.ToString();
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "manifest.bulk_import", nameof(BulkImportJob), job.Id, $"success={job.SuccessCount}; errors={job.ErrorCount}", ct);
        return new BulkImportResultDto(job.Id, job.Status, job.TotalRows, job.SuccessCount, job.ErrorCount, job.ErrorLog);
    }

    public async Task<BulkImportResultDto?> GetBulkImportAsync(Guid jobId, CancellationToken ct = default)
    {
        var job = await _db.BulkImportJobs.AsNoTracking().FirstOrDefaultAsync(x => x.Id == jobId, ct);
        return job is null ? null : new BulkImportResultDto(job.Id, job.Status, job.TotalRows, job.SuccessCount, job.ErrorCount, job.ErrorLog);
    }

    public async Task<IReadOnlyList<WorkflowHistoryDto>> GetHistoryAsync(Guid manifestId, CancellationToken ct = default)
    {
        return await _db.WorkflowStateHistories.AsNoTracking()
            .Where(x => x.ManifestId == manifestId)
            .OrderBy(x => x.CreatedAt)
            .Select(x => new WorkflowHistoryDto(x.FromState.ToString(), x.ToState.ToString(), x.ActorRole, x.TransitionReason, x.CreatedAt))
            .ToListAsync(ct);
    }

    private IQueryable<Manifest> Query() =>
        _db.Manifests.AsNoTracking()
            .Include(x => x.ShippingLine)
            .Include(x => x.Consignee)
            .Include(x => x.Broker)
            .Include(x => x.Noa)
            .Include(x => x.Billing);

    private async Task TransitionAsync(
        Manifest entity,
        WorkflowState to,
        Guid actorId,
        string actorRole,
        string reason,
        bool initial = false,
        CancellationToken ct = default)
    {
        var from = entity.WorkflowState;
        if (!initial)
        {
            EnsureCanTransition(from, to);
            entity.WorkflowState = to;
        }

        _db.WorkflowStateHistories.Add(new WorkflowStateHistory
        {
            ManifestId = entity.Id,
            FromState = initial ? to : from,
            ToState = to,
            ActorId = actorId,
            ActorRole = actorRole,
            TransitionReason = reason
        });
        await Task.CompletedTask;
    }

    private static void EnsureCanTransition(WorkflowState from, WorkflowState to)
    {
        if (!WorkflowTransitions.CanTransition(from, to))
        {
            throw new InvalidOperationException($"Invalid workflow transition {from} → {to}.");
        }
    }

    private static void EnsureRole(string actual, params string[] allowed)
    {
        if (!allowed.Contains(actual))
        {
            throw new UnauthorizedAccessException($"Role {actual} cannot perform this action.");
        }
    }

    private async Task<Guid> ResolveActorShippingLineIdAsync(Guid actorId, string actorRole, CancellationToken ct)
    {
        if (actorRole == AppRoles.SystemAdmin)
        {
            return await SoleShippingLine.RequireIdAsync(_db, ct);
        }

        var actor = await _db.Users.AsNoTracking().FirstOrDefaultAsync(u => u.Id == actorId, ct)
                    ?? throw new UnauthorizedAccessException("User not found.");

        if (actor.ManagedShippingLineId.HasValue)
        {
            return actor.ManagedShippingLineId.Value;
        }

        if (actor.ShippingLineAdminId.HasValue)
        {
            var adminLine = await _db.Users.AsNoTracking()
                .Where(u => u.Id == actor.ShippingLineAdminId)
                .Select(u => u.ManagedShippingLineId)
                .FirstOrDefaultAsync(ct);
            if (adminLine.HasValue) return adminLine.Value;
        }

        var pref = await _db.UserShippingLinePreferences.AsNoTracking()
            .FirstOrDefaultAsync(p => p.UserId == actorId, ct);
        if (pref?.LastSelectedShippingLineId is Guid lineId) return lineId;

        return await SoleShippingLine.RequireIdAsync(_db, ct);
    }

    private static ManifestDto Map(Manifest x) =>
        new(
            x.Id,
            x.ManifestNumber,
            x.ShippingLineId,
            x.ShippingLine?.BrandName,
            x.ConsigneeId,
            x.Consignee is null
                ? null
                : string.IsNullOrWhiteSpace(x.Consignee.BusinessName)
                    ? $"{x.Consignee.FirstName} {x.Consignee.LastName}".Trim()
                    : x.Consignee.BusinessName,
            x.BrokerId,
            x.Broker is null ? null : $"{x.Broker.FirstName} {x.Broker.LastName}",
            x.VesselName,
            x.VoyageNumber,
            x.ArrivalDate,
            x.BlNumber,
            x.BlFilePath,
            x.BlPdfPath,
            x.ManifestFilePath,
            x.WorkflowState.ToString(),
            x.Noa?.Id,
            x.Noa?.NoaNumber,
            x.Noa?.PdfPath,
            x.Noa?.PortLocation,
            x.Billing?.Id,
            x.Billing?.TotalAmount,
            x.Billing?.Currency,
            x.Billing?.PdfPath,
            x.Billing?.FreightCharges,
            x.Billing?.ThcCharges,
            x.Billing?.AdditionalCharges,
            x.Billing?.ExchangeRate,
            x.Billing?.TotalAmountPhp,
            x.CreatedAt);
}

public class PaymentService : IPaymentService
{
    private readonly OptimusDbContext _db;
    private readonly IDocumentStore _docs;
    private readonly IActivityLogService _activity;
    private readonly IPaymentFeeService _fees;

    public PaymentService(
        OptimusDbContext db,
        IDocumentStore docs,
        IActivityLogService activity,
        IPaymentFeeService fees)
    {
        _db = db;
        _docs = docs;
        _activity = activity;
        _fees = fees;
    }

    public async Task<PaymentDto> SubmitAsync(Guid manifestId, SubmitPaymentRequest request, string? receiptPath, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        if (actorRole is not (AppRoles.Broker or AppRoles.Consignee or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Only broker/consignee can submit payments.");
        }

        var manifest = await _db.Manifests.Include(x => x.Billing).FirstOrDefaultAsync(x => x.Id == manifestId, ct)
                       ?? throw new KeyNotFoundException("Manifest not found.");

        if (actorRole == AppRoles.Broker && manifest.BrokerId != actorId)
        {
            throw new UnauthorizedAccessException("Broker is not assigned to this manifest.");
        }

        if (actorRole == AppRoles.Consignee && manifest.ConsigneeId != actorId)
        {
            throw new UnauthorizedAccessException("Consignee is not assigned to this manifest.");
        }

        if (request.PaymentType == PaymentType.FinalPayment)
        {
            if (manifest.WorkflowState != WorkflowState.BillingGenerated)
            {
                throw new InvalidOperationException("Final payment requires billing_generated state.");
            }

            if (manifest.Billing is null)
            {
                throw new InvalidOperationException("Billing is missing.");
            }

            if (string.IsNullOrWhiteSpace(receiptPath))
            {
                throw new InvalidOperationException("A PDF payment receipt is required.");
            }

            // Lock amount/currency to the generated billing statement (V1 parity).
            request = request with
            {
                Amount = manifest.Billing.TotalAmount,
                Currency = manifest.Billing.Currency
            };
        }

        if (request.PaymentType == PaymentType.ManifestAccess)
        {
            var fee = await _fees.GetActiveAsync("manifest_access", ct);
            if (request.Amount <= 0)
            {
                request = request with { Amount = fee.Amount };
            }
        }

        var nextVersion = 1;
        if (request.PaymentType == PaymentType.FinalPayment)
        {
            var priorCount = await _db.Payments.CountAsync(
                x => x.ManifestId == manifestId && x.PaymentType == PaymentType.FinalPayment,
                ct);
            nextVersion = priorCount + 1;
        }

        var payment = new Payment
        {
            ManifestId = manifest.Id,
            ShippingLineId = manifest.ShippingLineId,
            PaymentType = request.PaymentType,
            Amount = request.Amount,
            Currency = request.Currency.ToUpperInvariant(),
            ReceiptFilePath = receiptPath,
            Status = PaymentStatus.PendingValidation,
            SubmittedById = actorId,
            Version = nextVersion
        };
        _db.Payments.Add(payment);

        if (request.PaymentType == PaymentType.FinalPayment)
        {
            if (!WorkflowTransitions.CanTransition(manifest.WorkflowState, WorkflowState.PaymentSubmitted))
            {
                throw new InvalidOperationException("Cannot submit final payment in current state.");
            }

            var from = manifest.WorkflowState;
            manifest.WorkflowState = WorkflowState.PaymentSubmitted;
            _db.WorkflowStateHistories.Add(new WorkflowStateHistory
            {
                ManifestId = manifest.Id,
                FromState = from,
                ToState = WorkflowState.PaymentSubmitted,
                ActorId = actorId,
                ActorRole = actorRole,
                TransitionReason = "Final payment submitted"
            });
        }

        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "payment.submit", nameof(Payment), payment.Id, $"{request.PaymentType}:{request.Amount}", ct);
        return await GetDto(payment.Id, ct);
    }

    public async Task<PaymentDto> ValidateAsync(Guid paymentId, ValidatePaymentRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        var payment = await _db.Payments.Include(x => x.Manifest).FirstOrDefaultAsync(x => x.Id == paymentId, ct)
                      ?? throw new KeyNotFoundException("Payment not found.");

        if (payment.PaymentType == PaymentType.FinalPayment && actorRole is not (AppRoles.Accounting or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Accounting required to validate final payment.");
        }

        if (payment.PaymentType == PaymentType.ManifestAccess && actorRole is not (AppRoles.SystemAdmin or AppRoles.Accounting))
        {
            throw new UnauthorizedAccessException("Admin/Accounting required to validate access fee.");
        }

        if (payment.Status != PaymentStatus.PendingValidation)
        {
            throw new InvalidOperationException("Payment is not pending validation.");
        }

        if (request.Approve)
        {
            payment.Status = PaymentStatus.Verified;
            payment.ValidatedById = actorId;
            payment.ValidatedAt = DateTime.UtcNow;
            payment.OfficialReceiptPath = _docs.CreatePlaceholderPdf("receipts",
                $"OR {payment.Id:N}",
                $"Manifest={payment.Manifest.ManifestNumber}\nAmount={payment.Amount} {payment.Currency}");

            if (payment.PaymentType == PaymentType.FinalPayment)
            {
                var from = payment.Manifest.WorkflowState;
                if (!WorkflowTransitions.CanTransition(from, WorkflowState.PaymentVerified))
                {
                    throw new InvalidOperationException("Cannot verify payment in current workflow state.");
                }

                payment.Manifest.WorkflowState = WorkflowState.PaymentVerified;
                _db.WorkflowStateHistories.Add(new WorkflowStateHistory
                {
                    ManifestId = payment.ManifestId,
                    FromState = from,
                    ToState = WorkflowState.PaymentVerified,
                    ActorId = actorId,
                    ActorRole = actorRole,
                    TransitionReason = "Final payment verified"
                });
            }
        }
        else
        {
            if (string.IsNullOrWhiteSpace(request.RejectionReason))
            {
                throw new InvalidOperationException("Rejection reason is required.");
            }

            payment.Status = PaymentStatus.Rejected;
            payment.RejectionReason = request.RejectionReason;
            payment.ValidatedById = actorId;
            payment.ValidatedAt = DateTime.UtcNow;

            if (payment.PaymentType == PaymentType.FinalPayment)
            {
                var from = payment.Manifest.WorkflowState;
                payment.Manifest.WorkflowState = WorkflowState.BillingGenerated;
                _db.WorkflowStateHistories.Add(new WorkflowStateHistory
                {
                    ManifestId = payment.ManifestId,
                    FromState = from,
                    ToState = WorkflowState.BillingGenerated,
                    ActorId = actorId,
                    ActorRole = actorRole,
                    TransitionReason = $"Payment rejected: {request.RejectionReason}"
                });
            }
        }

        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, request.Approve ? "payment.verify" : "payment.reject", nameof(Payment), payment.Id, request.RejectionReason, ct);
        return await GetDto(payment.Id, ct);
    }

    public async Task<PaymentDto> AttachOfficialReceiptAsync(Guid paymentId, string path, CancellationToken ct = default)
    {
        var payment = await _db.Payments.FirstOrDefaultAsync(x => x.Id == paymentId, ct)
                      ?? throw new KeyNotFoundException("Payment not found.");
        payment.OfficialReceiptPath = path;
        await _db.SaveChangesAsync(ct);
        return await GetDto(payment.Id, ct);
    }

    public Task<PaymentDto> GetAsync(Guid paymentId, CancellationToken ct = default)
        => GetDto(paymentId, ct);

    public async Task<IReadOnlyList<PaymentDto>> ListPendingAsync(PaymentType? type, CancellationToken ct = default)
    {
        var q = _db.Payments.AsNoTracking()
            .Include(x => x.Manifest)
            .Include(x => x.SubmittedBy)
            .Include(x => x.ValidatedBy)
            .Where(x => x.Status == PaymentStatus.PendingValidation);
        if (type.HasValue) q = q.Where(x => x.PaymentType == type);
        var items = await q.OrderBy(x => x.CreatedAt).ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    public async Task<IReadOnlyList<PaymentDto>> ListByManifestAsync(Guid manifestId, CancellationToken ct = default)
    {
        var items = await _db.Payments.AsNoTracking()
            .Include(x => x.Manifest)
            .Include(x => x.SubmittedBy)
            .Include(x => x.ValidatedBy)
            .Where(x => x.ManifestId == manifestId)
            .OrderBy(x => x.Version)
            .ThenBy(x => x.CreatedAt)
            .ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    public async Task<FinalPaymentListResponse> ListFinalPaymentsAsync(
        string? statusFilter,
        int page,
        int limit,
        CancellationToken ct = default)
    {
        var allowedLimits = new[] { 10, 20, 50 };
        if (!allowedLimits.Contains(limit))
        {
            limit = 20;
        }

        page = Math.Max(1, page);
        statusFilter = string.IsNullOrWhiteSpace(statusFilter) ? "pending_validation" : statusFilter.Trim().ToLowerInvariant();

        var stats = await GetFinalPaymentStatsAsync(ct);

        var q = _db.Payments.AsNoTracking()
            .Include(x => x.Manifest).ThenInclude(m => m.Billing)
            .Include(x => x.Manifest).ThenInclude(m => m.Consignee)
            .Include(x => x.SubmittedBy)
            .Include(x => x.ValidatedBy)
            .Where(x => x.PaymentType == PaymentType.FinalPayment);

        if (statusFilter != "all")
        {
            var status = ParseFinalPaymentStatusFilter(statusFilter);
            if (status.HasValue)
            {
                q = q.Where(x => x.Status == status.Value);
            }
        }

        var total = await q.CountAsync(ct);
        var totalPages = Math.Max(1, (int)Math.Ceiling(total / (double)limit));
        page = Math.Min(page, totalPages);

        var items = await q
            .OrderByDescending(x => x.CreatedAt)
            .Skip((page - 1) * limit)
            .Take(limit)
            .ToListAsync(ct);

        var start = total > 0 ? ((page - 1) * limit) + 1 : 0;
        var end = Math.Min(page * limit, total);

        return new FinalPaymentListResponse(
            items.Select(MapFinalListItem).ToList(),
            stats,
            page,
            limit,
            total,
            totalPages,
            start,
            end);
    }

    private async Task<FinalPaymentStatsDto> GetFinalPaymentStatsAsync(CancellationToken ct)
    {
        var baseQ = _db.Payments.AsNoTracking().Where(x => x.PaymentType == PaymentType.FinalPayment);

        var pending = await baseQ.CountAsync(x => x.Status == PaymentStatus.PendingValidation, ct);
        var approved = await baseQ.CountAsync(x => x.Status == PaymentStatus.Verified, ct);
        var rejected = await baseQ.CountAsync(x => x.Status == PaymentStatus.Rejected, ct);
        var total = await baseQ.CountAsync(ct);

        var discrepancies = await _db.Payments.AsNoTracking()
            .Include(x => x.Manifest).ThenInclude(m => m.Billing)
            .Where(x => x.PaymentType == PaymentType.FinalPayment)
            .Where(x => x.Status == PaymentStatus.PendingValidation)
            .Where(x => x.Manifest.Billing != null)
            .Where(x =>
                x.Amount - x.Manifest.Billing!.TotalAmount > 0.01m ||
                x.Manifest.Billing!.TotalAmount - x.Amount > 0.01m)
            .CountAsync(ct);

        return new FinalPaymentStatsDto(pending, approved, rejected, total, discrepancies);
    }

    private static PaymentStatus? ParseFinalPaymentStatusFilter(string statusFilter) =>
        statusFilter switch
        {
            "pending_validation" or "pending" or "pendingvalidation" => PaymentStatus.PendingValidation,
            "verified" or "approved" => PaymentStatus.Verified,
            "rejected" => PaymentStatus.Rejected,
            _ => null
        };

    private static FinalPaymentListItemDto MapFinalListItem(Payment x)
    {
        var billing = x.Manifest.Billing;
        return new FinalPaymentListItemDto(
            x.Id,
            x.ManifestId,
            x.Manifest.ManifestNumber,
            x.Manifest.Consignee?.BusinessName,
            x.Amount,
            x.Currency,
            billing?.TotalAmount,
            billing?.Currency,
            x.Status.ToString(),
            $"{x.SubmittedBy.FirstName} {x.SubmittedBy.LastName}",
            x.SubmittedBy.Email,
            x.CreatedAt,
            x.ValidatedAt,
            x.ValidatedBy is null ? null : $"{x.ValidatedBy.FirstName} {x.ValidatedBy.LastName}",
            x.Version);
    }

    private async Task<PaymentDto> GetDto(Guid id, CancellationToken ct)
    {
        var payment = await _db.Payments.AsNoTracking()
            .Include(x => x.Manifest)
            .Include(x => x.SubmittedBy)
            .Include(x => x.ValidatedBy)
            .FirstAsync(x => x.Id == id, ct);
        return Map(payment);
    }

    private static PaymentDto Map(Payment x) =>
        new(
            x.Id,
            x.ManifestId,
            x.Manifest.ManifestNumber,
            x.PaymentType.ToString(),
            x.Amount,
            x.Currency,
            x.Status.ToString(),
            x.ReceiptFilePath,
            x.OfficialReceiptPath,
            x.RejectionReason,
            x.SubmittedById,
            $"{x.SubmittedBy.FirstName} {x.SubmittedBy.LastName}",
            x.CreatedAt,
            x.ValidatedAt,
            x.Version,
            x.ValidatedBy is null ? null : $"{x.ValidatedBy.FirstName} {x.ValidatedBy.LastName}");
}
