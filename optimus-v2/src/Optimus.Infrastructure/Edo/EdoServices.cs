using System.Security.Cryptography;
using System.Text.Json;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Optimus.Application.Cargo.Interfaces;
using Optimus.Application.Edo.Dtos;
using Optimus.Application.Edo.Interfaces;
using Optimus.Domain.Entities;
using Optimus.Domain.Enums;
using Optimus.Infrastructure.Persistence;
using Optimus.Shared.Constants;
using QRCoder;

namespace Optimus.Infrastructure.Edo;

public class QrCodeService : IQrCodeService
{
    private readonly string _root;

    public QrCodeService()
    {
        _root = Path.Combine(Directory.GetCurrentDirectory(), "wwwroot", "uploads");
        Directory.CreateDirectory(_root);
    }

    public string CreatePngFile(string category, string payload)
    {
        var dir = Path.Combine(_root, category);
        Directory.CreateDirectory(dir);
        using var generator = new QRCodeGenerator();
        using var data = generator.CreateQrCode(payload, QRCodeGenerator.ECCLevel.Q);
        var png = new PngByteQRCode(data);
        var bytes = png.GetGraphic(8);
        var file = $"{DateTime.UtcNow:yyyyMMddHHmmss}_{Guid.NewGuid():N}.png";
        var full = Path.Combine(dir, file);
        File.WriteAllBytes(full, bytes);
        return $"/uploads/{category}/{file}";
    }
}

public class EdoService : IEdoService
{
    private readonly OptimusDbContext _db;
    private readonly IDocumentStore _docs;
    private readonly IQrCodeService _qr;
    private readonly IActivityLogService _activity;
    private readonly IPaymentFeeService _fees;

    public EdoService(
        OptimusDbContext db,
        IDocumentStore docs,
        IQrCodeService qr,
        IActivityLogService activity,
        IPaymentFeeService fees)
    {
        _db = db;
        _docs = docs;
        _qr = qr;
        _activity = activity;
        _fees = fees;
    }

    public async Task<EdoDto> GenerateAsync(GenerateEdoRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        EnsureRole(actorRole, AppRoles.SlStaff, AppRoles.ShippingLinesAdmin, AppRoles.SystemAdmin);
        var manifest = await _db.Manifests.FirstOrDefaultAsync(x => x.Id == request.ManifestId, ct)
                       ?? throw new KeyNotFoundException("Manifest not found.");

        if (manifest.WorkflowState is not (WorkflowState.PaymentVerified or WorkflowState.EdoGenerated or WorkflowState.EdoReleased))
        {
            throw new InvalidOperationException("Manifest must be PaymentVerified before eDO/CRO generation.");
        }

        var edo = await BuildEdoAsync(manifest, request.ContainerNumber, request.ExpiresAt, request.CyLocation, request.AdditionalNotes, actorId, request.RequirePayment, ct);
        _db.ElectronicDeliveryOrders.Add(edo);

        if (manifest.WorkflowState == WorkflowState.PaymentVerified)
        {
            var from = manifest.WorkflowState;
            manifest.WorkflowState = WorkflowState.EdoGenerated;
            _db.WorkflowStateHistories.Add(new WorkflowStateHistory
            {
                ManifestId = manifest.Id,
                FromState = from,
                ToState = WorkflowState.EdoGenerated,
                ActorId = actorId,
                ActorRole = actorRole,
                TransitionReason = "eDO/CRO generated"
            });
        }

        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "edo.generate", nameof(ElectronicDeliveryOrder), edo.Id, edo.EdoNumber, ct);
        return Map(edo, manifest.ManifestNumber);
    }

    public async Task<GenerationSessionDto> BatchGenerateAsync(BatchGenerateEdoRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        EnsureRole(actorRole, AppRoles.SlStaff, AppRoles.ShippingLinesAdmin, AppRoles.SystemAdmin);
        var manifest = await _db.Manifests.FirstOrDefaultAsync(x => x.Id == request.ManifestId, ct)
                       ?? throw new KeyNotFoundException("Manifest not found.");
        if (manifest.WorkflowState is not (WorkflowState.PaymentVerified or WorkflowState.EdoGenerated or WorkflowState.EdoReleased))
        {
            throw new InvalidOperationException("Manifest must be PaymentVerified before batch eDO/CRO generation.");
        }

        var containers = request.ContainerNumbers.Where(x => !string.IsNullOrWhiteSpace(x)).Select(x => x.Trim()).Distinct().ToList();
        if (containers.Count == 0)
        {
            containers.Add($"CTR-{manifest.ManifestNumber}");
        }

        var session = new GenerationSession
        {
            SessionId = Guid.NewGuid().ToString("N"),
            ManifestId = manifest.Id,
            InitiatedById = actorId,
            Status = GenerationSessionStatus.InProgress,
            TotalItems = containers.Count,
            ExpirationDate = request.ExpiresAt
        };
        _db.GenerationSessions.Add(session);
        await _db.SaveChangesAsync(ct);

        var failures = new List<string>();
        foreach (var container in containers)
        {
            session.CurrentItem = container;
            try
            {
                var edo = await BuildEdoAsync(manifest, container, request.ExpiresAt, request.CyLocation, null, actorId, true, ct);
                _db.ElectronicDeliveryOrders.Add(edo);
                session.CompletedItems++;
            }
            catch (Exception ex)
            {
                session.FailedItems++;
                failures.Add($"{container}:{ex.Message}");
            }

            await _db.SaveChangesAsync(ct);
        }

        if (manifest.WorkflowState == WorkflowState.PaymentVerified && session.CompletedItems > 0)
        {
            var from = manifest.WorkflowState;
            manifest.WorkflowState = WorkflowState.EdoGenerated;
            _db.WorkflowStateHistories.Add(new WorkflowStateHistory
            {
                ManifestId = manifest.Id,
                FromState = from,
                ToState = WorkflowState.EdoGenerated,
                ActorId = actorId,
                ActorRole = actorRole,
                TransitionReason = "Batch eDO/CRO generated"
            });
        }

        session.Status = session.FailedItems == 0 ? GenerationSessionStatus.Completed :
            session.CompletedItems == 0 ? GenerationSessionStatus.Failed : GenerationSessionStatus.Completed;
        session.CompletedAt = DateTime.UtcNow;
        session.CurrentItem = null;
        session.FailuresJson = failures.Count == 0 ? null : JsonSerializer.Serialize(failures);
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "edo.batch_generate", nameof(GenerationSession), session.Id, session.SessionId, ct);
        return MapSession(session);
    }

    public async Task<GenerationSessionDto?> GetSessionAsync(Guid sessionId, CancellationToken ct = default)
    {
        var session = await _db.GenerationSessions.AsNoTracking().FirstOrDefaultAsync(x => x.Id == sessionId, ct);
        return session is null ? null : MapSession(session);
    }

    public async Task<EdoDto> GetAsync(Guid id, CancellationToken ct = default)
    {
        var edo = await _db.ElectronicDeliveryOrders.AsNoTracking()
            .Include(x => x.Manifest)
            .Include(x => x.ReleasedBy)
            .FirstOrDefaultAsync(x => x.Id == id, ct)
            ?? throw new KeyNotFoundException("eDO/CRO not found.");
        var payment = await _db.EdoPayments.AsNoTracking()
            .Include(p => p.ValidatedBy)
            .Where(p => p.EdoId == edo.Id)
            .OrderByDescending(p => p.CreatedAt)
            .FirstOrDefaultAsync(ct);
        return Map(edo, edo.Manifest.ManifestNumber, payment);
    }

    public async Task<IReadOnlyList<EdoDto>> ListAsync(Guid? manifestId, string? status, Guid? brokerId = null, Guid? consigneeId = null, CancellationToken ct = default)
    {
        var q = _db.ElectronicDeliveryOrders.AsNoTracking().Include(x => x.Manifest).AsQueryable();
        if (manifestId.HasValue) q = q.Where(x => x.ManifestId == manifestId);
        if (brokerId.HasValue) q = q.Where(x => x.Manifest.BrokerId == brokerId);
        if (consigneeId.HasValue) q = q.Where(x => x.Manifest.ConsigneeId == consigneeId);
        if (!string.IsNullOrWhiteSpace(status) && Enum.TryParse<EdoStatus>(status, true, out var st))
        {
            q = q.Where(x => x.Status == st);
        }

        var items = await q.OrderByDescending(x => x.GeneratedAt).ToListAsync(ct);
        var payments = await LoadLatestPaymentsAsync(items.Select(x => x.Id).ToList(), ct);
        return items.Select(x => Map(x, x.Manifest.ManifestNumber, payments.GetValueOrDefault(x.Id))).ToList();
    }

    public async Task<EdoReleaseQueueDto> ListReleaseQueueAsync(Guid? shippingLineId = null, CancellationToken ct = default)
    {
        var q = _db.ElectronicDeliveryOrders.AsNoTracking()
            .Include(x => x.Manifest).ThenInclude(m => m!.Broker)
            .Include(x => x.Manifest).ThenInclude(m => m!.Consignee)
            .Where(x => x.Status == EdoStatus.PendingRelease || x.Status == EdoStatus.PendingValidation);

        if (shippingLineId.HasValue)
        {
            q = q.Where(x => x.ShippingLineId == shippingLineId.Value);
        }

        var items = await q.OrderByDescending(x => x.GeneratedAt).ToListAsync(ct);

        var edoIds = items.Select(x => x.Id).ToList();
        var payments = await _db.EdoPayments.AsNoTracking()
            .Include(x => x.SubmittedBy)
            .Where(p => p.EdoId != null && edoIds.Contains(p.EdoId.Value))
            .OrderByDescending(p => p.CreatedAt)
            .ToListAsync(ct);

        var latestPaymentByEdo = payments
            .Where(p => p.EdoId.HasValue)
            .GroupBy(p => p.EdoId!.Value)
            .ToDictionary(g => g.Key, g => g.First());

        var queueItems = items.Select(edo =>
        {
            latestPaymentByEdo.TryGetValue(edo.Id, out var payment);
            var manifest = edo.Manifest;
            return new EdoReleaseQueueItemDto(
                edo.Id,
                edo.EdoNumber,
                edo.Status.ToString(),
                manifest.Id,
                manifest.ManifestNumber,
                edo.ContainerNumber,
                manifest.Broker?.FullName,
                manifest.Consignee is Consignee consignee ? consignee.BusinessName : manifest.Consignee?.FullName,
                edo.GeneratedAt,
                payment?.Id,
                payment?.Status.ToString(),
                payment?.Amount,
                payment?.Currency,
                payment?.CreatedAt,
                payment?.SubmittedBy?.FullName);
        }).ToList();

        var pendingValidation = queueItems.Count(x =>
            x.PaymentStatus == PaymentStatus.PendingValidation.ToString());
        var readyToRelease = queueItems.Count(x => x.Status == EdoStatus.PendingRelease.ToString());
        var awaitingPayment = queueItems.Count(x => x.PaymentId is null);

        return new EdoReleaseQueueDto(
            queueItems,
            queueItems.Count,
            pendingValidation,
            readyToRelease,
            awaitingPayment);
    }

    public async Task<IReadOnlyList<EdoReleaseRecordDto>> ListReleaseRecordsAsync(Guid? shippingLineId = null, CancellationToken ct = default)
    {
        var releasedStatuses = new[] { EdoStatus.Released, EdoStatus.Active };
        var q = _db.ElectronicDeliveryOrders.AsNoTracking()
            .Include(x => x.Manifest).ThenInclude(m => m!.Broker)
            .Include(x => x.Manifest).ThenInclude(m => m!.Consignee)
            .Include(x => x.ReleasedBy)
            .Where(x => releasedStatuses.Contains(x.Status) && x.ReleasedAt != null);

        if (shippingLineId.HasValue)
        {
            q = q.Where(x => x.ShippingLineId == shippingLineId.Value);
        }

        var items = await q.OrderByDescending(x => x.ReleasedAt).Take(200).ToListAsync(ct);

        var edoIds = items.Select(x => x.Id).ToList();
        var payments = await _db.EdoPayments.AsNoTracking()
            .Include(x => x.ValidatedBy)
            .Where(p => p.EdoId != null && edoIds.Contains(p.EdoId.Value) && p.Status == PaymentStatus.Verified)
            .OrderByDescending(p => p.ValidatedAt)
            .ToListAsync(ct);

        var verifiedPaymentByEdo = payments
            .Where(p => p.EdoId.HasValue)
            .GroupBy(p => p.EdoId!.Value)
            .ToDictionary(g => g.Key, g => g.First());

        return items.Select(edo =>
        {
            verifiedPaymentByEdo.TryGetValue(edo.Id, out var payment);
            var manifest = edo.Manifest;
            return new EdoReleaseRecordDto(
                edo.Id,
                edo.EdoNumber,
                edo.Status.ToString(),
                manifest.Id,
                manifest.ManifestNumber,
                edo.ContainerNumber,
                manifest.Broker?.FullName,
                manifest.Consignee is Consignee consignee ? consignee.BusinessName : manifest.Consignee?.FullName,
                edo.ReleasedAt,
                edo.ReleasedBy?.FullName,
                payment?.Amount,
                payment?.Currency,
                payment?.ValidatedAt,
                payment?.ValidatedBy?.FullName);
        }).ToList();
    }

    public async Task<EdoGenerationQueueDto> ListGenerationQueueAsync(Guid? shippingLineId, CancellationToken ct = default)
    {
        var eligibleStates = new[]
        {
            WorkflowState.PaymentVerified,
            WorkflowState.EdoGenerated,
            WorkflowState.EdoReleased
        };

        var generatedEdoStatuses = new[]
        {
            EdoStatus.PendingValidation,
            EdoStatus.PendingRelease,
            EdoStatus.Released,
            EdoStatus.Active
        };

        var verifiedManifestIds = await _db.Payments.AsNoTracking()
            .Where(p => p.PaymentType == PaymentType.FinalPayment && p.Status == PaymentStatus.Verified)
            .Select(p => p.ManifestId)
            .Distinct()
            .ToListAsync(ct);

        if (verifiedManifestIds.Count == 0)
        {
            return new EdoGenerationQueueDto(Array.Empty<EdoGenerationGroupDto>(), Array.Empty<EdoGenerationContainerDto>(), 0, 0, 0);
        }

        var containersQuery = _db.Containers.AsNoTracking()
            .Include(c => c.ContainerSize)
            .Include(c => c.ContainerType)
            .Include(c => c.Manifest!).ThenInclude(m => m.Broker)
            .Include(c => c.Manifest!).ThenInclude(m => m.Consignee)
            .Include(c => c.Manifest!).ThenInclude(m => m.ShippingLine)
            .Where(c => c.ManifestId != null
                        && eligibleStates.Contains(c.Manifest!.WorkflowState)
                        && verifiedManifestIds.Contains(c.ManifestId.Value));

        if (shippingLineId.HasValue)
        {
            containersQuery = containersQuery.Where(c => c.Manifest!.ShippingLineId == shippingLineId.Value);
        }

        var containers = await containersQuery
            .OrderBy(c => c.Manifest!.ManifestNumber)
            .ThenBy(c => c.ContainerNumber)
            .ToListAsync(ct);

        if (containers.Count == 0)
        {
            return new EdoGenerationQueueDto(Array.Empty<EdoGenerationGroupDto>(), Array.Empty<EdoGenerationContainerDto>(), 0, 0, 0);
        }

        var manifestIds = containers.Select(c => c.ManifestId!.Value).Distinct().ToList();

        var paymentVerifiedAt = await _db.Payments.AsNoTracking()
            .Where(p => manifestIds.Contains(p.ManifestId)
                        && p.PaymentType == PaymentType.FinalPayment
                        && p.Status == PaymentStatus.Verified)
            .GroupBy(p => p.ManifestId)
            .Select(g => new { ManifestId = g.Key, VerifiedAt = g.Max(p => p.ValidatedAt) })
            .ToDictionaryAsync(x => x.ManifestId, x => x.VerifiedAt, ct);

        var edos = await _db.ElectronicDeliveryOrders.AsNoTracking()
            .Where(e => manifestIds.Contains(e.ManifestId) && generatedEdoStatuses.Contains(e.Status))
            .ToListAsync(ct);

        var edoByManifestContainer = edos
            .Where(e => !string.IsNullOrWhiteSpace(e.ContainerNumber))
            .GroupBy(e => (e.ManifestId, Container: e.ContainerNumber!.Trim().ToUpperInvariant()))
            .ToDictionary(g => g.Key, g => g.OrderByDescending(x => x.GeneratedAt).First());

        var manifestContainerCounts = containers
            .GroupBy(c => c.ManifestId!.Value)
            .ToDictionary(g => g.Key, g => g.Count());

        var manifestEdoCounts = edos
            .Where(e => !string.IsNullOrWhiteSpace(e.ContainerNumber))
            .GroupBy(e => e.ManifestId)
            .ToDictionary(g => g.Key, g => g.Select(x => x.ContainerNumber!.Trim().ToUpperInvariant()).Distinct().Count());

        var pendingRows = new List<EdoGenerationContainerDto>();
        var generatedRows = new List<EdoGenerationContainerDto>();

        foreach (var container in containers)
        {
            var manifest = container.Manifest!;
            var key = (manifest.Id, container.ContainerNumber.Trim().ToUpperInvariant());
            edoByManifestContainer.TryGetValue(key, out var existingEdo);
            paymentVerifiedAt.TryGetValue(manifest.Id, out var verifiedAt);

            var row = new EdoGenerationContainerDto(
                container.Id,
                container.ContainerNumber,
                container.ContainerSize?.Name,
                container.ContainerType?.Name,
                manifest.Id,
                manifest.ManifestNumber,
                manifest.Broker?.FullName,
                manifest.Consignee is Consignee consignee ? consignee.BusinessName : manifest.Consignee?.FullName,
                manifest.ShippingLine?.BrandName,
                verifiedAt,
                existingEdo is not null,
                existingEdo?.Id,
                existingEdo?.EdoNumber,
                existingEdo?.Status.ToString(),
                existingEdo?.GeneratedAt,
                existingEdo?.ExpiresAt);

            if (existingEdo is null)
            {
                pendingRows.Add(row);
            }
            else
            {
                generatedRows.Add(row);
            }
        }

        var pendingGroups = pendingRows
            .GroupBy(r => r.ManifestId)
            .Select(g =>
            {
                var first = g.First();
                var manifestId = g.Key;
                manifestContainerCounts.TryGetValue(manifestId, out var totalInManifest);
                manifestEdoCounts.TryGetValue(manifestId, out var edoCount);
                return new EdoGenerationGroupDto(
                    manifestId,
                    first.ManifestNumber,
                    first.BrokerName ?? "N/A",
                    first.ConsigneeName ?? "N/A",
                    first.ShippingLineName,
                    g.Count(),
                    edoCount,
                    totalInManifest,
                    g.OrderBy(x => x.ContainerNumber).ToList());
            })
            .OrderBy(g => g.ManifestNumber)
            .ToList();

        generatedRows = generatedRows
            .OrderByDescending(r => r.EdoGeneratedAt)
            .ThenBy(r => r.ContainerNumber)
            .ToList();

        var totalEligible = pendingRows.Count + generatedRows.Count;
        return new EdoGenerationQueueDto(
            pendingGroups,
            generatedRows,
            totalEligible,
            pendingRows.Count,
            generatedRows.Count);
    }

    public async Task<EdoDto> ReleaseAsync(Guid id, ReleaseEdoRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        EnsureRole(actorRole, AppRoles.SlStaff, AppRoles.SystemAdmin, AppRoles.ShippingLinesAdmin, AppRoles.TerminalTeam);
        var edo = await _db.ElectronicDeliveryOrders.Include(x => x.Manifest).FirstOrDefaultAsync(x => x.Id == id, ct)
                  ?? throw new KeyNotFoundException("eDO/CRO not found.");

        if (actorRole == AppRoles.SlStaff)
        {
            var staffLineId = await _db.UserShippingLinePreferences.AsNoTracking()
                .Where(x => x.UserId == actorId)
                .Select(x => x.LastSelectedShippingLineId)
                .FirstOrDefaultAsync(ct);
            if (staffLineId.HasValue && edo.ShippingLineId != staffLineId.Value)
            {
                throw new UnauthorizedAccessException("This eDO belongs to another shipping line.");
            }
        }

        if (edo.Status == EdoStatus.PendingValidation)
        {
            var paid = await _db.EdoPayments.AnyAsync(x => x.EdoId == edo.Id && x.Status == PaymentStatus.Verified, ct);
            if (!paid && request.Approve)
            {
                throw new InvalidOperationException("eDO/CRO payment must be verified before release.");
            }
        }

        if (edo.Status is not (EdoStatus.PendingRelease or EdoStatus.PendingValidation))
        {
            throw new InvalidOperationException($"Cannot release from status {edo.Status}.");
        }

        var from = edo.Status;
        if (request.Approve)
        {
            edo.Status = EdoStatus.Released;
            edo.ReleasedAt = DateTime.UtcNow;
            edo.ReleasedById = actorId;

            var version = await _db.EdoVersions.FirstOrDefaultAsync(x => x.EdoId == edo.Id && x.IsCurrent, ct);
            if (version is not null) version.Status = EdoStatus.Released;

            if (edo.Manifest.WorkflowState == WorkflowState.EdoGenerated)
            {
                var mf = edo.Manifest.WorkflowState;
                edo.Manifest.WorkflowState = WorkflowState.EdoReleased;
                _db.WorkflowStateHistories.Add(new WorkflowStateHistory
                {
                    ManifestId = edo.ManifestId,
                    FromState = mf,
                    ToState = WorkflowState.EdoReleased,
                    ActorId = actorId,
                    ActorRole = actorRole,
                    TransitionReason = "eDO/CRO released"
                });
            }
        }
        else
        {
            if (string.IsNullOrWhiteSpace(request.RejectionReason))
            {
                throw new InvalidOperationException("Rejection reason is required.");
            }

            edo.Status = EdoStatus.Rejected;
            edo.RejectionReason = request.RejectionReason;
        }

        _db.EdoReleaseHistories.Add(new EdoReleaseHistory
        {
            EdoId = edo.Id,
            FromStatus = from,
            ToStatus = edo.Status,
            ActorId = actorId,
            RejectionReason = request.RejectionReason
        });

        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, request.Approve ? "edo.release" : "edo.reject", nameof(ElectronicDeliveryOrder), edo.Id, edo.EdoNumber, ct);
        return Map(edo, edo.Manifest.ManifestNumber);
    }

    public async Task<EdoDto> UnlockAsync(Guid id, UnlockEdoRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        EnsureRole(actorRole, AppRoles.SystemAdmin, AppRoles.ShippingLinesAdmin);
        var edo = await _db.ElectronicDeliveryOrders.Include(x => x.Manifest).FirstOrDefaultAsync(x => x.Id == id, ct)
                  ?? throw new KeyNotFoundException("eDO/CRO not found.");
        if (edo.Status is not (EdoStatus.Locked or EdoStatus.Expired))
        {
            throw new InvalidOperationException("Only locked/expired eDO/CRO can be unlocked.");
        }

        var from = edo.Status;
        edo.Status = EdoStatus.Active;
        edo.ExpiresAt = request.NewExpiresAt ?? DateTime.UtcNow.AddDays(7);
        edo.AdditionalNotes = string.IsNullOrWhiteSpace(request.Notes)
            ? edo.AdditionalNotes
            : $"{edo.AdditionalNotes}\nUnlock: {request.Notes}".Trim();

        _db.EdoReleaseHistories.Add(new EdoReleaseHistory
        {
            EdoId = edo.Id,
            FromStatus = from,
            ToStatus = EdoStatus.Active,
            ActorId = actorId,
            RejectionReason = request.Notes
        });

        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "edo.unlock", nameof(ElectronicDeliveryOrder), edo.Id, edo.EdoNumber, ct);
        return Map(edo, edo.Manifest.ManifestNumber);
    }

    public async Task<int> ProcessExpirationsAsync(CancellationToken ct = default)
    {
        var now = DateTime.UtcNow;
        var items = await _db.ElectronicDeliveryOrders
            .Where(x => x.ExpiresAt != null && x.ExpiresAt < now &&
                        (x.Status == EdoStatus.Released || x.Status == EdoStatus.Active || x.Status == EdoStatus.PendingRelease))
            .ToListAsync(ct);

        foreach (var edo in items)
        {
            var from = edo.Status;
            edo.Status = EdoStatus.Expired;
            _db.EdoReleaseHistories.Add(new EdoReleaseHistory
            {
                EdoId = edo.Id,
                FromStatus = from,
                ToStatus = EdoStatus.Expired,
                ActorId = edo.GeneratedById,
                RejectionReason = "Automatic expiration"
            });
        }

        if (items.Count > 0)
        {
            await _db.SaveChangesAsync(ct);
        }

        return items.Count;
    }

    private async Task<ElectronicDeliveryOrder> BuildEdoAsync(
        Manifest manifest,
        string? containerNumber,
        DateTime? expiresAt,
        string? cyLocation,
        string? notes,
        Guid actorId,
        bool requirePayment,
        CancellationToken ct)
    {
        var fee = await _fees.GetActiveAsync("edo", ct);
        var token = Convert.ToHexString(RandomNumberGenerator.GetBytes(32));
        var container = string.IsNullOrWhiteSpace(containerNumber) ? "GENERAL" : containerNumber.Trim();
        var edoNumber = $"EDO-{DateTime.UtcNow:yyyyMMdd}-{container}-{Random.Shared.Next(100, 999)}";
        var verifyUrl = $"/verify/document/{token}";
        var qrPath = _qr.CreatePngFile("edo-qr", verifyUrl);
        var pdf = _docs.CreatePlaceholderPdf("edo", $"eDO/CRO {edoNumber}",
            $"Manifest={manifest.ManifestNumber}\nContainer={container}\nVerify={verifyUrl}\nExpires={expiresAt}");

        var edo = new ElectronicDeliveryOrder
        {
            EdoNumber = edoNumber,
            ManifestId = manifest.Id,
            ShippingLineId = manifest.ShippingLineId,
            ContainerNumber = container,
            FeeAmount = fee.Amount,
            PdfPath = pdf,
            QrPayload = verifyUrl,
            QrImagePath = qrPath,
            Status = requirePayment ? EdoStatus.PendingValidation : EdoStatus.PendingRelease,
            GeneratedById = actorId,
            GeneratedAt = DateTime.UtcNow,
            ExpiresAt = expiresAt ?? DateTime.UtcNow.AddDays(14),
            CyLocation = cyLocation,
            AdditionalNotes = notes,
            VerificationToken = token,
            Version = 1
        };

        edo.Versions.Add(new EdoVersion
        {
            VersionNumber = 1,
            PdfPath = pdf,
            EdoNumber = edoNumber,
            Status = edo.Status,
            CreatedById = actorId,
            ExpiresAt = edo.ExpiresAt,
            CyLocation = cyLocation,
            IsCurrent = true
        });

        _db.DocumentVerifications.Add(new DocumentVerification
        {
            VerificationToken = token,
            DocumentType = "EDO",
            SubjectType = nameof(ElectronicDeliveryOrder),
            SubjectId = edo.Id,
            DocumentNumber = edoNumber,
            SummaryJson = JsonSerializer.Serialize(new
            {
                edo.EdoNumber,
                manifest.ManifestNumber,
                container,
                Status = edo.Status.ToString(),
                edo.ExpiresAt
            })
        });

        return edo;
    }

    private static void EnsureRole(string actual, params string[] allowed)
    {
        if (!allowed.Contains(actual))
        {
            throw new UnauthorizedAccessException($"Role {actual} cannot perform this action.");
        }
    }

    private static EdoDto Map(ElectronicDeliveryOrder x, string manifestNumber, EdoPayment? latestPayment = null) =>
        new(x.Id, x.EdoNumber, x.ManifestId, manifestNumber, x.ShippingLineId, x.ContainerNumber, x.Status.ToString(),
            x.FeeAmount, x.PdfPath, x.QrImagePath, x.VerificationToken, x.GeneratedAt, x.ReleasedAt, x.ExpiresAt,
            x.CyLocation, x.RejectionReason, x.Version,
            latestPayment?.Status.ToString(),
            latestPayment?.CreatedAt,
            x.ReleasedBy?.FullName,
            latestPayment?.ValidatedAt,
            latestPayment?.ValidatedBy?.FullName);

    private async Task<EdoPayment?> GetLatestPaymentAsync(Guid edoId, CancellationToken ct) =>
        await _db.EdoPayments.AsNoTracking()
            .Where(p => p.EdoId == edoId)
            .OrderByDescending(p => p.CreatedAt)
            .FirstOrDefaultAsync(ct);

    private async Task<Dictionary<Guid, EdoPayment>> LoadLatestPaymentsAsync(IReadOnlyList<Guid> edoIds, CancellationToken ct)
    {
        if (edoIds.Count == 0)
        {
            return new Dictionary<Guid, EdoPayment>();
        }

        var payments = await _db.EdoPayments.AsNoTracking()
            .Where(p => p.EdoId != null && edoIds.Contains(p.EdoId.Value))
            .OrderByDescending(p => p.CreatedAt)
            .ToListAsync(ct);

        return payments
            .Where(p => p.EdoId.HasValue)
            .GroupBy(p => p.EdoId!.Value)
            .ToDictionary(g => g.Key, g => g.First());
    }

    private static GenerationSessionDto MapSession(GenerationSession x) =>
        new(x.Id, x.SessionId, x.ManifestId, x.Status.ToString(), x.TotalItems, x.CompletedItems, x.FailedItems,
            x.CurrentItem, x.StartedAt, x.CompletedAt);
}

public class EdoPaymentService : IEdoPaymentService
{
    private readonly OptimusDbContext _db;
    private readonly IDocumentStore _docs;
    private readonly IActivityLogService _activity;
    private readonly IPaymentFeeService _fees;

    public EdoPaymentService(OptimusDbContext db, IDocumentStore docs, IActivityLogService activity, IPaymentFeeService fees)
    {
        _db = db;
        _docs = docs;
        _activity = activity;
        _fees = fees;
    }

    public async Task<EdoPaymentDto> SubmitAsync(Guid edoId, SubmitEdoPaymentRequest request, string? receiptPath, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        if (actorRole is not (AppRoles.Broker or AppRoles.Consignee or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Only broker/consignee can submit eDO payments.");
        }

        var edo = await _db.ElectronicDeliveryOrders.Include(x => x.Manifest).FirstOrDefaultAsync(x => x.Id == edoId, ct)
                  ?? throw new KeyNotFoundException("eDO/CRO not found.");

        if (actorRole == AppRoles.Broker && edo.Manifest.BrokerId != actorId)
        {
            throw new UnauthorizedAccessException("Broker is not assigned to this eDO/CRO.");
        }

        if (actorRole == AppRoles.Consignee && edo.Manifest.ConsigneeId != actorId)
        {
            throw new UnauthorizedAccessException("Consignee is not assigned to this eDO/CRO.");
        }

        if (edo.Status is not (EdoStatus.PendingValidation or EdoStatus.PendingRelease))
        {
            throw new InvalidOperationException("eDO/CRO is not awaiting payment.");
        }

        var hasPendingPayment = await _db.EdoPayments.AnyAsync(
            x => x.EdoId == edo.Id && x.Status == PaymentStatus.PendingValidation,
            ct);
        if (hasPendingPayment)
        {
            throw new InvalidOperationException("Payment has already been submitted and is awaiting validation.");
        }

        var fee = await _fees.GetActiveAsync("edo", ct);
        var amount = request.Amount > 0 ? request.Amount : fee.Amount;
        var payment = new EdoPayment
        {
            ManifestId = edo.ManifestId,
            EdoId = edo.Id,
            ShippingLineId = edo.ShippingLineId,
            Amount = amount,
            Currency = string.IsNullOrWhiteSpace(request.Currency) ? "PHP" : request.Currency.ToUpperInvariant(),
            ReceiptFilePath = receiptPath,
            Status = PaymentStatus.PendingValidation,
            SubmittedById = actorId
        };
        edo.Status = EdoStatus.PendingValidation;
        _db.EdoPayments.Add(payment);
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "edo_payment.submit", nameof(EdoPayment), payment.Id, $"{amount}", ct);
        return Map(payment, edo.EdoNumber);
    }

    public async Task<EdoPaymentDto> GetAsync(Guid paymentId, CancellationToken ct = default)
    {
        var payment = await _db.EdoPayments.AsNoTracking()
            .Include(x => x.Edo)
            .Include(x => x.Manifest)
            .Include(x => x.SubmittedBy)
            .Include(x => x.ValidatedBy)
            .FirstOrDefaultAsync(x => x.Id == paymentId, ct)
            ?? throw new KeyNotFoundException("eDO payment not found.");
        return MapDetailed(payment);
    }

    public async Task<EdoPaymentDto> ValidateAsync(Guid paymentId, ValidateEdoPaymentRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        if (actorRole is not AppRoles.SystemAdmin)
        {
            throw new UnauthorizedAccessException("Only the platform admin can validate eDO payments.");
        }

        var payment = await _db.EdoPayments
            .Include(x => x.Edo)
            .Include(x => x.Manifest)
            .Include(x => x.SubmittedBy)
            .FirstOrDefaultAsync(x => x.Id == paymentId, ct)
                      ?? throw new KeyNotFoundException("eDO payment not found.");
        if (payment.Status != PaymentStatus.PendingValidation)
        {
            throw new InvalidOperationException("Payment is not pending.");
        }

        if (request.Approve)
        {
            payment.Status = PaymentStatus.Verified;
            payment.ValidatedAt = DateTime.UtcNow;
            payment.ValidatedById = actorId;
            payment.OfficialReceiptPath = _docs.CreatePlaceholderPdf("receipts", $"EDO-OR {payment.Id:N}",
                $"Amount={payment.Amount} {payment.Currency}");
            if (payment.Edo is not null)
            {
                payment.Edo.Status = EdoStatus.PendingRelease;
            }
        }
        else
        {
            if (string.IsNullOrWhiteSpace(request.RejectionReason))
            {
                throw new InvalidOperationException("Rejection reason required.");
            }

            payment.Status = PaymentStatus.Rejected;
            payment.RejectionReason = request.RejectionReason;
            payment.ValidatedAt = DateTime.UtcNow;
            payment.ValidatedById = actorId;
        }

        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, request.Approve ? "edo_payment.verify" : "edo_payment.reject", nameof(EdoPayment), payment.Id, request.RejectionReason, ct);
        return MapDetailed(payment);
    }

    public async Task<IReadOnlyList<EdoPaymentDto>> ListPendingAsync(CancellationToken ct = default)
    {
        var items = await _db.EdoPayments.AsNoTracking()
            .Include(x => x.Edo)
            .Include(x => x.Manifest)
            .Include(x => x.SubmittedBy)
            .Where(x => x.Status == PaymentStatus.PendingValidation)
            .OrderBy(x => x.CreatedAt)
            .ToListAsync(ct);
        return items.Select(MapDetailed).ToList();
    }

    public async Task<IReadOnlyList<EdoPaymentDto>> ListReviewedAsync(CancellationToken ct = default)
    {
        var reviewed = new[] { PaymentStatus.Verified, PaymentStatus.Rejected };
        var items = await _db.EdoPayments.AsNoTracking()
            .Include(x => x.Edo)
            .Include(x => x.Manifest)
            .Include(x => x.SubmittedBy)
            .Include(x => x.ValidatedBy)
            .Where(x => reviewed.Contains(x.Status) && x.ValidatedAt != null)
            .OrderByDescending(x => x.ValidatedAt)
            .Take(200)
            .ToListAsync(ct);
        return items.Select(MapDetailed).ToList();
    }

    private static EdoPaymentDto MapDetailed(EdoPayment x) =>
        new(
            x.Id,
            x.ManifestId,
            x.EdoId,
            x.Edo?.EdoNumber,
            x.Amount,
            x.Currency,
            x.Status.ToString(),
            x.ReceiptFilePath,
            x.OfficialReceiptPath,
            x.RejectionReason,
            x.CreatedAt,
            x.Manifest?.ManifestNumber,
            x.Edo?.ContainerNumber,
            x.Edo?.Status.ToString(),
            x.SubmittedBy?.FullName,
            x.ValidatedAt,
            x.ValidatedBy?.FullName);

    private static EdoPaymentDto Map(EdoPayment x, string? edoNumber) =>
        new(x.Id, x.ManifestId, x.EdoId, edoNumber, x.Amount, x.Currency, x.Status.ToString(),
            x.ReceiptFilePath, x.OfficialReceiptPath, x.RejectionReason, x.CreatedAt);
}

public class EdoRenewalService : IEdoRenewalService
{
    private readonly OptimusDbContext _db;
    private readonly IEdoService _edoService;
    private readonly IActivityLogService _activity;
    private readonly IDocumentStore _docs;

    public EdoRenewalService(OptimusDbContext db, IEdoService edoService, IActivityLogService activity, IDocumentStore docs)
    {
        _db = db;
        _edoService = edoService;
        _activity = activity;
        _docs = docs;
    }

    public async Task<RenewalDto> RequestAsync(CreateRenewalRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        if (actorRole is not (AppRoles.Broker or AppRoles.Consignee or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Broker/Consignee required.");
        }

        var expired = await _db.ElectronicDeliveryOrders.FirstOrDefaultAsync(x => x.Id == request.ExpiredEdoId, ct)
                      ?? throw new KeyNotFoundException("eDO/CRO not found.");
        if (expired.Status is not (EdoStatus.Expired or EdoStatus.Locked or EdoStatus.Released or EdoStatus.Active))
        {
            throw new InvalidOperationException("eDO/CRO cannot be renewed in current status.");
        }

        var freeDays = 7;
        var overdue = Math.Max(0, (int)Math.Ceiling((DateTime.UtcNow.Date - request.EmptyContainerReturnDate.Date).TotalDays) - freeDays);
        var detention = overdue * 150m; // simplified detention rate

        var renewal = new EdoRenewalRequest
        {
            ExpiredEdoId = expired.Id,
            RequestedById = actorId,
            EmptyContainerReturnDate = request.EmptyContainerReturnDate,
            OverdueDays = overdue,
            DetentionChargeAmount = detention,
            Status = detention > 0 ? RenewalRequestStatus.AwaitingPayment : RenewalRequestStatus.PendingReview,
            AdditionalNotes = request.AdditionalNotes
        };

        if (detention > 0)
        {
            renewal.AdditionalNotes = string.IsNullOrWhiteSpace(request.AdditionalNotes)
                ? $"Detention billing PDF: {_docs.CreatePlaceholderPdf("billing", $"Detention {expired.EdoNumber}", $"OverdueDays={overdue}; Amount={detention}")}"
                : $"{request.AdditionalNotes}\nDetention={detention} overdue={overdue}";
            // Detention is tracked on the renewal (Billing remains 1:1 with manifest for cargo invoice).
        }

        _db.EdoRenewalRequests.Add(renewal);
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "edo.renewal_request", nameof(EdoRenewalRequest), renewal.Id, expired.EdoNumber, ct);
        return Map(renewal, expired.EdoNumber);
    }

    public async Task<RenewalDto> ReviewAsync(Guid id, ReviewRenewalRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        EnsureStaff(actorRole);
        var renewal = await _db.EdoRenewalRequests.Include(x => x.ExpiredEdo).FirstOrDefaultAsync(x => x.Id == id, ct)
                      ?? throw new KeyNotFoundException("Renewal not found.");

        if (request.Approve)
        {
            renewal.Status = renewal.DetentionChargeAmount > 0 && !renewal.PaymentVerified
                ? RenewalRequestStatus.AwaitingPayment
                : RenewalRequestStatus.ReadyForGeneration;
        }
        else
        {
            renewal.Status = RenewalRequestStatus.Cancelled;
            renewal.AdditionalNotes = $"{renewal.AdditionalNotes}\nRejected: {request.Notes}".Trim();
        }

        await _db.SaveChangesAsync(ct);
        return Map(renewal, renewal.ExpiredEdo.EdoNumber);
    }

    public async Task<RenewalDto> MarkPaymentVerifiedAsync(Guid id, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        if (actorRole is not (AppRoles.Accounting or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Accounting required.");
        }

        var renewal = await _db.EdoRenewalRequests.Include(x => x.ExpiredEdo).FirstOrDefaultAsync(x => x.Id == id, ct)
                      ?? throw new KeyNotFoundException("Renewal not found.");
        renewal.PaymentVerified = true;
        renewal.PaymentVerifiedAt = DateTime.UtcNow;
        renewal.PaymentVerifiedById = actorId;
        renewal.Status = RenewalRequestStatus.ReadyForGeneration;
        await _db.SaveChangesAsync(ct);
        return Map(renewal, renewal.ExpiredEdo.EdoNumber);
    }

    public async Task<EdoDto> GenerateRenewedAsync(Guid renewalId, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        EnsureStaff(actorRole);
        var renewal = await _db.EdoRenewalRequests.Include(x => x.ExpiredEdo).FirstOrDefaultAsync(x => x.Id == renewalId, ct)
                      ?? throw new KeyNotFoundException("Renewal not found.");
        if (renewal.Status != RenewalRequestStatus.ReadyForGeneration)
        {
            throw new InvalidOperationException("Renewal is not ready for generation.");
        }

        if (renewal.DetentionChargeAmount > 0 && !renewal.PaymentVerified)
        {
            throw new InvalidOperationException("Detention payment not verified.");
        }

        renewal.ExpiredEdo.Status = EdoStatus.Superseded;
        var created = await _edoService.GenerateAsync(new GenerateEdoRequest(
            renewal.ExpiredEdo.ManifestId,
            renewal.ExpiredEdo.ContainerNumber,
            DateTime.UtcNow.AddDays(14),
            renewal.ExpiredEdo.CyLocation,
            $"Renewed from {renewal.ExpiredEdo.EdoNumber}",
            RequirePayment: false), actorId, actorRole, ct);

        renewal.NewEdoId = created.Id;
        renewal.Status = RenewalRequestStatus.Completed;
        renewal.CompletedAt = DateTime.UtcNow;
        await _db.SaveChangesAsync(ct);
        return created;
    }

    public async Task<IReadOnlyList<RenewalDto>> ListAsync(CancellationToken ct = default)
    {
        var items = await _db.EdoRenewalRequests.AsNoTracking()
            .Include(x => x.ExpiredEdo)
            .OrderByDescending(x => x.RequestedAt)
            .ToListAsync(ct);
        return items.Select(x => Map(x, x.ExpiredEdo.EdoNumber)).ToList();
    }

    private static void EnsureStaff(string role)
    {
        if (role is not (AppRoles.SlStaff or AppRoles.ShippingLinesAdmin or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Staff role required.");
        }
    }

    private static RenewalDto Map(EdoRenewalRequest x, string expiredNumber) =>
        new(x.Id, x.ExpiredEdoId, expiredNumber, x.NewEdoId, x.Status.ToString(), x.OverdueDays,
            x.DetentionChargeAmount, x.PaymentVerified, x.RequestedAt, x.CompletedAt);
}

public class DocumentVerificationService : IDocumentVerificationService
{
    private readonly OptimusDbContext _db;

    public DocumentVerificationService(OptimusDbContext db) => _db = db;

    public async Task<DocumentVerifyDto> VerifyAsync(string token, CancellationToken ct = default)
    {
        var row = await _db.DocumentVerifications.AsNoTracking()
            .FirstOrDefaultAsync(x => x.VerificationToken == token, ct);
        if (row is null)
        {
            return new DocumentVerifyDto(false, null, null, null, null, null, null, "Invalid verification token.");
        }

        var edo = await _db.ElectronicDeliveryOrders.AsNoTracking()
            .Include(x => x.Manifest)
            .FirstOrDefaultAsync(x => x.Id == row.SubjectId || x.VerificationToken == token, ct);

        if (edo is null)
        {
            return new DocumentVerifyDto(true, row.DocumentType, row.DocumentNumber, null, null, null, null, "Document record found without live eDO.");
        }

        return new DocumentVerifyDto(true, "EDO", edo.EdoNumber, edo.Status.ToString(), edo.Manifest.ManifestNumber,
            edo.GeneratedAt, edo.ExpiresAt, "Document verified.");
    }
}

public class EdoExpirationHostedService : BackgroundService
{
    private readonly IServiceScopeFactory _scopeFactory;
    private readonly ILogger<EdoExpirationHostedService> _logger;

    public EdoExpirationHostedService(IServiceScopeFactory scopeFactory, ILogger<EdoExpirationHostedService> logger)
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
                var edo = scope.ServiceProvider.GetRequiredService<IEdoService>();
                var count = await edo.ProcessExpirationsAsync(stoppingToken);
                if (count > 0)
                {
                    _logger.LogInformation("Expired {Count} eDO/CRO documents", count);
                }
            }
            catch (Exception ex)
            {
                _logger.LogError(ex, "eDO expiration job failed");
            }

            await Task.Delay(TimeSpan.FromMinutes(5), stoppingToken);
        }
    }
}
