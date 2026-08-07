using System.Security.Cryptography;
using System.Text.Json;
using Microsoft.EntityFrameworkCore;
using Optimus.Application.Auth.Interfaces;
using Optimus.Application.Cargo.Interfaces;
using Optimus.Application.Ops.Dtos;
using Optimus.Application.Ops.Interfaces;
using Optimus.Application.Yard.Interfaces;
using Optimus.Domain.Entities;
using Optimus.Domain.Enums;
using Optimus.Infrastructure.Persistence;
using Optimus.Infrastructure.Shipping;
using Optimus.Shared.Constants;

namespace Optimus.Infrastructure.Ops;

public class SuspensionAppealService : ISuspensionAppealService
{
    private readonly OptimusDbContext _db;
    private readonly INotificationService _notifications;
    private readonly IActivityLogService _activity;
    private readonly IEmailSender _email;

    public SuspensionAppealService(
        OptimusDbContext db,
        INotificationService notifications,
        IActivityLogService activity,
        IEmailSender email)
    {
        _db = db;
        _notifications = notifications;
        _activity = activity;
        _email = email;
    }

    public async Task SuspendBrokerAsync(Guid brokerId, SuspendBrokerRequest request, Guid actorId, CancellationToken ct = default)
    {
        var broker = await _db.Brokers.FirstOrDefaultAsync(x => x.Id == brokerId, ct)
                     ?? throw new KeyNotFoundException("Broker not found.");
        broker.Status = AccountStatus.Denied;
        broker.DeactivationReason = request.Reason;
        broker.DeactivatedAt = DateTime.UtcNow;

        var rels = await _db.ConsigneeBrokerRelationships
            .Where(x => x.BrokerId == brokerId && x.Status == RelationshipStatus.Active)
            .ToListAsync(ct);
        foreach (var rel in rels)
        {
            rel.Status = RelationshipStatus.Suspended;
            rel.SuspendedAt = DateTime.UtcNow;
            rel.SuspensionReason = request.Reason;
            await _notifications.NotifyAsync(rel.ConsigneeId, "Broker suspended",
                $"Your broker was suspended: {request.Reason}. You may request a transfer.",
                "suspension", nameof(Broker), brokerId, ct);
        }

        await _db.SaveChangesAsync(ct);
        await _email.SendAsync(broker.Email, "Account suspended", request.Reason, ct);
        await _activity.LogAsync(actorId, "broker.suspend", nameof(Broker), brokerId, request.Reason, ct);
    }

    public async Task<AppealDto> SubmitAsync(CreateAppealRequest request, string? attachmentsJson, Guid userId, CancellationToken ct = default)
    {
        var user = await _db.Users.FirstOrDefaultAsync(x => x.Id == userId, ct)
                   ?? throw new KeyNotFoundException("User not found.");
        if (user.Status != AccountStatus.Denied)
        {
            throw new InvalidOperationException("Only suspended/denied accounts can appeal.");
        }

        if (await _db.SuspensionAppeals.AnyAsync(x => x.UserId == userId && x.Status == AppealStatus.Pending, ct))
        {
            throw new InvalidOperationException("A pending appeal already exists.");
        }

        var entity = new SuspensionAppeal
        {
            UserId = userId,
            AppealLetter = request.AppealLetter,
            AttachmentsJson = attachmentsJson
        };
        _db.SuspensionAppeals.Add(entity);
        await _db.SaveChangesAsync(ct);
        var admins = await _db.Users.AsNoTracking().Where(x => x.Role == AppRoles.SystemAdmin).Select(x => x.Id).Take(3).ToListAsync(ct);
        foreach (var a in admins)
        {
            await _notifications.NotifyAsync(a, "Suspension appeal", $"{user.FullName} submitted an appeal", "appeal",
                nameof(SuspensionAppeal), entity.Id, ct);
        }

        return Map(entity, user.FullName);
    }

    public async Task<AppealDto> ReviewAsync(Guid id, ReviewAppealRequest request, Guid actorId, CancellationToken ct = default)
    {
        var entity = await _db.SuspensionAppeals.Include(x => x.User).FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Appeal not found.");
        if (entity.Status != AppealStatus.Pending)
        {
            throw new InvalidOperationException("Appeal is not pending.");
        }

        entity.ReviewedById = actorId;
        entity.ReviewedAt = DateTime.UtcNow;
        entity.ReviewNotes = request.Notes;
        if (request.Approve)
        {
            entity.Status = AppealStatus.Approved;
            entity.User.Status = AccountStatus.Approved;
            entity.User.DeactivationReason = null;
            entity.User.DeactivatedAt = null;
            if (entity.User is Broker)
            {
                var rels = await _db.ConsigneeBrokerRelationships
                    .Where(x => x.BrokerId == entity.UserId && x.Status == RelationshipStatus.Suspended)
                    .ToListAsync(ct);
                foreach (var rel in rels)
                {
                    rel.Status = RelationshipStatus.Active;
                    rel.SuspendedAt = null;
                    rel.SuspensionReason = null;
                }
            }
        }
        else
        {
            entity.Status = AppealStatus.Rejected;
            if (string.IsNullOrWhiteSpace(request.Notes))
            {
                throw new InvalidOperationException("Rejection notes required.");
            }
        }

        await _db.SaveChangesAsync(ct);
        await _notifications.NotifyAsync(entity.UserId, "Appeal reviewed", $"Appeal {entity.Status}", "appeal",
            nameof(SuspensionAppeal), entity.Id, ct);
        return Map(entity, entity.User.FullName);
    }

    public async Task<IReadOnlyList<AppealDto>> ListAsync(string? status, CancellationToken ct = default)
    {
        var q = _db.SuspensionAppeals.AsNoTracking().Include(x => x.User).AsQueryable();
        if (!string.IsNullOrWhiteSpace(status) && Enum.TryParse<AppealStatus>(status, true, out var st))
        {
            q = q.Where(x => x.Status == st);
        }

        var items = await q.OrderByDescending(x => x.SubmittedAt).Take(200).ToListAsync(ct);
        return items.Select(x => Map(x, x.User.FullName)).ToList();
    }

    private static AppealDto Map(SuspensionAppeal x, string name) =>
        new(x.Id, x.UserId, name, x.AppealLetter, x.AttachmentsJson, x.Status.ToString(), x.SubmittedAt, x.ReviewNotes);
}

public class RepositioningService : IRepositioningService
{
    private readonly OptimusDbContext _db;
    private readonly IActivityLogService _activity;
    private readonly INotificationService _notifications;

    public RepositioningService(OptimusDbContext db, IActivityLogService activity, INotificationService notifications)
    {
        _db = db;
        _activity = activity;
        _notifications = notifications;
    }

    public async Task<RepositioningDto> CreateAsync(
        CreateRepositioningRequest request,
        string? requestLetterPath,
        Guid actorId,
        string actorRole,
        CancellationToken ct = default)
    {
        if (actorRole is not (AppRoles.SlStaff or AppRoles.ShippingLinesAdmin or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Staff required.");
        }

        if (request.ContainerIds.Count == 0)
        {
            throw new InvalidOperationException("At least one container is required.");
        }

        if (string.IsNullOrWhiteSpace(request.Purpose))
        {
            throw new InvalidOperationException("Purpose is required.");
        }

        var shippingLineId = request.ShippingLineId != Guid.Empty
            ? request.ShippingLineId
            : await SoleShippingLine.RequireIdAsync(_db, ct);

        var source = await _db.Terminals.FirstOrDefaultAsync(x => x.Id == request.SourceTerminalId, ct)
                     ?? throw new KeyNotFoundException("Source terminal not found.");
        var destination = await _db.Terminals.FirstOrDefaultAsync(x => x.Id == request.DestinationTerminalId, ct)
                          ?? throw new KeyNotFoundException("Destination terminal not found.");

        if (source.Identity != TerminalIdentity.ContainerYard)
        {
            throw new InvalidOperationException("Source must be a container yard.");
        }

        if (destination.Identity == TerminalIdentity.ContainerYard)
        {
            throw new InvalidOperationException("Destination must be a port terminal.");
        }

        var eligible = await ListEligibleContainersAsync(shippingLineId, request.SourceTerminalId, null, ct);
        var eligibleIds = eligible.Select(x => x.Id).ToHashSet();
        if (request.ContainerIds.Any(id => !eligibleIds.Contains(id)))
        {
            throw new InvalidOperationException("One or more containers are not eligible for this CY.");
        }

        var containers = await _db.Containers.Where(x => request.ContainerIds.Contains(x.Id)).ToListAsync(ct);
        if (containers.Count != request.ContainerIds.Count)
        {
            throw new KeyNotFoundException("One or more containers not found.");
        }

        var year = DateTime.UtcNow.Year;
        var seq = await _db.RepositioningRequests.CountAsync(x => x.RequestedAt.Year == year, ct) + 1;
        var entity = new RepositioningRequest
        {
            RequestNumber = $"RRP-{year}-{seq:D5}",
            ShippingLineId = shippingLineId,
            RequestType = Enum.Parse<RepositioningRequestType>(request.RequestType, true),
            SourceTerminalId = request.SourceTerminalId,
            DestinationTerminalId = request.DestinationTerminalId,
            Purpose = request.Purpose.Trim(),
            RequestLetterPath = requestLetterPath,
            ContainerCount = containers.Count,
            RequestedById = actorId
        };

        foreach (var c in containers.OrderByDescending(x => x.CurrentDwellDays))
        {
            entity.Items.Add(new RepositioningRequestItem
            {
                ContainerId = c.Id,
                DwellTimeDays = c.CurrentDwellDays,
                DischargeDate = c.TerminalArrivalDate
            });
        }

        _db.RepositioningRequests.Add(entity);
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "reposition.create", nameof(RepositioningRequest), entity.Id, entity.RequestNumber, ct);

        var reviewers = await _db.Users.AsNoTracking()
            .Where(x => x.IsActive && (x.Role == AppRoles.SlStaff || x.Role == AppRoles.TerminalTeam))
            .Select(x => x.Id)
            .Take(20)
            .ToListAsync(ct);
        foreach (var uid in reviewers)
        {
            await _notifications.NotifyAsync(
                uid,
                "Outbound request submitted",
                $"{entity.RequestNumber} awaits review ({entity.ContainerCount} container(s)).",
                "repositioning",
                nameof(RepositioningRequest),
                entity.Id,
                ct);
        }

        return await GetMappedAsync(entity.Id, ct);
    }

    public async Task<RepositioningDto> GetAsync(Guid id, Guid? shippingLineId, CancellationToken ct = default)
    {
        var q = Query().Where(x => x.Id == id);
        if (shippingLineId.HasValue)
        {
            q = q.Where(x => x.ShippingLineId == shippingLineId);
        }

        var entity = await q.FirstOrDefaultAsync(ct)
                     ?? throw new KeyNotFoundException("Request not found or you do not have access.");
        return Map(entity);
    }

    public async Task<RepositioningDto> ReviewAsync(Guid id, ReviewRepositioningRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        // V1: review lives on SL Staff screens; Shipping Admin can still call via role hierarchy.
        if (actorRole is not (AppRoles.SlStaff or AppRoles.ShippingLinesAdmin or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Staff required to review.");
        }

        var entity = await _db.RepositioningRequests
            .Include(x => x.Items).ThenInclude(i => i.Container)
            .Include(x => x.DestinationTerminal)
            .FirstOrDefaultAsync(x => x.Id == id, ct)
            ?? throw new KeyNotFoundException("Request not found.");
        if (entity.Status != RepositioningStatus.Pending)
        {
            throw new InvalidOperationException("Request is not pending.");
        }

        entity.ReviewedById = actorId;
        entity.ReviewedAt = DateTime.UtcNow;
        entity.ReviewNotes = request.Notes;
        if (request.Approve)
        {
            entity.Status = RepositioningStatus.InTransit;
            foreach (var item in entity.Items)
            {
                item.Container.Status = ContainerStatus.InTransit;
                item.Container.CurrentLocation = entity.DestinationTerminal.Code;
            }
        }
        else
        {
            if (string.IsNullOrWhiteSpace(request.Notes))
            {
                throw new InvalidOperationException("Rejection notes required.");
            }

            entity.Status = RepositioningStatus.Rejected;
        }

        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, request.Approve ? "reposition.approve" : "reposition.reject",
            nameof(RepositioningRequest), id, entity.RequestNumber, ct);

        await _notifications.NotifyAsync(
            entity.RequestedById,
            request.Approve ? "Outbound request approved" : "Outbound request rejected",
            request.Approve
                ? $"{entity.RequestNumber} released to port."
                : $"{entity.RequestNumber} rejected: {request.Notes}",
            "repositioning",
            nameof(RepositioningRequest),
            entity.Id,
            ct);

        return await GetMappedAsync(id, ct);
    }

    public async Task<RepositioningDto> CompleteAsync(Guid id, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        if (actorRole is not (AppRoles.TerminalTeam or AppRoles.SlStaff or AppRoles.ShippingLinesAdmin or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Staff/Terminal required.");
        }

        var entity = await _db.RepositioningRequests
            .Include(x => x.Items).ThenInclude(i => i.Container)
            .Include(x => x.DestinationTerminal)
            .FirstOrDefaultAsync(x => x.Id == id, ct)
            ?? throw new KeyNotFoundException("Request not found.");
        if (entity.Status != RepositioningStatus.InTransit)
        {
            throw new InvalidOperationException("Request is not in transit.");
        }

        entity.Status = RepositioningStatus.Completed;
        entity.CompletedAt = DateTime.UtcNow;
        foreach (var item in entity.Items)
        {
            item.Container.Status = ContainerStatus.AtTerminal;
            item.Container.TerminalArrivalDate ??= DateTime.UtcNow;
            item.Container.CurrentLocation = entity.DestinationTerminal.Name;
        }

        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "reposition.complete", nameof(RepositioningRequest), id, entity.RequestNumber, ct);
        return await GetMappedAsync(id, ct);
    }

    public async Task<RepositioningDto> CancelAsync(Guid id, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        if (actorRole is not (AppRoles.ShippingLinesAdmin or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Shipping Lines Admin required to cancel.");
        }

        var entity = await _db.RepositioningRequests.FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Request not found.");
        if (entity.Status != RepositioningStatus.Pending)
        {
            throw new InvalidOperationException("Only pending requests can be cancelled.");
        }

        entity.Status = RepositioningStatus.Cancelled;
        entity.ReviewedById = actorId;
        entity.ReviewedAt = DateTime.UtcNow;
        entity.ReviewNotes = "Cancelled by shipping lines admin";
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "reposition.cancel", nameof(RepositioningRequest), id, entity.RequestNumber, ct);
        return await GetMappedAsync(id, ct);
    }

    public async Task<IReadOnlyList<RepositioningDto>> ListAsync(string? status, Guid? shippingLineId, CancellationToken ct = default)
    {
        var q = Query();
        if (shippingLineId.HasValue)
        {
            q = q.Where(x => x.ShippingLineId == shippingLineId);
        }

        if (!string.IsNullOrWhiteSpace(status) && Enum.TryParse<RepositioningStatus>(status, true, out var st))
        {
            q = q.Where(x => x.Status == st);
        }

        var items = await q.OrderByDescending(x => x.RequestedAt).Take(100).ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    public async Task<IReadOnlyList<RepositioningEligibleContainerDto>> ListEligibleContainersAsync(
        Guid? shippingLineId,
        Guid? sourceTerminalId,
        string? search,
        CancellationToken ct = default)
    {
        var lineId = shippingLineId ?? await SoleShippingLine.RequireIdAsync(_db, ct);
        var activeStatuses = new[]
        {
            RepositioningStatus.Pending,
            RepositioningStatus.Approved,
            RepositioningStatus.InTransit
        };
        var blockedIds = await _db.RepositioningRequestItems.AsNoTracking()
            .Where(i => activeStatuses.Contains(i.RepositioningRequest.Status))
            .Select(i => i.ContainerId)
            .Distinct()
            .ToListAsync(ct);

        var q = _db.Containers.AsNoTracking()
            .Include(x => x.ContainerType)
            .Include(x => x.ContainerSize)
            .Include(x => x.CyAllocation)!.ThenInclude(a => a!.Terminal)
            .Where(x => x.ShippingLineId == lineId)
            .Where(x => x.Status == ContainerStatus.AvailableForReturn || x.Status == ContainerStatus.AtTerminal)
            .Where(x => x.CyAllocation != null && x.CyAllocation.Terminal.Identity == TerminalIdentity.ContainerYard)
            .Where(x => !blockedIds.Contains(x.Id));

        if (sourceTerminalId.HasValue)
        {
            q = q.Where(x => x.CyAllocation!.TerminalId == sourceTerminalId);
        }

        if (!string.IsNullOrWhiteSpace(search))
        {
            var s = search.Trim().ToUpperInvariant();
            q = q.Where(x => x.ContainerNumber.Contains(s));
        }

        var items = await q.OrderByDescending(x => x.CurrentDwellDays).Take(200).ToListAsync(ct);
        return items.Select(x => new RepositioningEligibleContainerDto(
            x.Id,
            x.ContainerNumber,
            x.ContainerSize?.Code,
            x.ContainerType?.Code,
            x.CyAllocation?.Terminal?.Name ?? x.CurrentLocation ?? "—",
            x.CurrentDwellDays,
            x.TerminalArrivalDate)).ToList();
    }

    private async Task<RepositioningDto> GetMappedAsync(Guid id, CancellationToken ct)
    {
        var entity = await Query().FirstAsync(x => x.Id == id, ct);
        return Map(entity);
    }

    private IQueryable<RepositioningRequest> Query() =>
        _db.RepositioningRequests.AsNoTracking()
            .Include(x => x.ShippingLine)
            .Include(x => x.SourceTerminal)
            .Include(x => x.DestinationTerminal)
            .Include(x => x.RequestedBy)
            .Include(x => x.Items).ThenInclude(i => i.Container);

    private static RepositioningDto Map(RepositioningRequest x) =>
        new(x.Id, x.RequestNumber, x.ShippingLineId, x.ShippingLine.BrandName, x.RequestType.ToString(),
            x.SourceTerminalId, x.SourceTerminal.Name, x.SourceTerminal.Code,
            x.DestinationTerminalId, x.DestinationTerminal.Name, x.DestinationTerminal.Code,
            x.Purpose, x.RequestLetterPath, x.ContainerCount, x.Status.ToString(), x.RequestedAt,
            x.RequestedBy?.Email, x.ReviewedAt, x.CompletedAt, x.ReviewNotes,
            x.Items.OrderByDescending(i => i.DwellTimeDays)
                .Select(i => new RepositioningItemDto(
                    i.ContainerId,
                    i.Container.ContainerNumber,
                    i.DwellTimeDays,
                    i.DischargeDate,
                    i.Container.Status.ToString()))
                .ToList());
}

public class ReferralService : IReferralService
{
    private readonly OptimusDbContext _db;
    private readonly IActivityLogService _activity;

    public ReferralService(OptimusDbContext db, IActivityLogService activity)
    {
        _db = db;
        _activity = activity;
    }

    public async Task<ReferralCodeDto> GenerateAsync(GenerateReferralRequest request, Guid consigneeId, CancellationToken ct = default)
    {
        var consignee = await _db.Consignees.FirstOrDefaultAsync(x => x.Id == consigneeId, ct)
                        ?? throw new UnauthorizedAccessException("Consignee required.");
        var code = $"REF{RandomNumberGenerator.GetInt32(100000, 999999)}";
        var entity = new ReferralCode
        {
            ConsigneeId = consignee.Id,
            Code = code,
            IsActive = true,
            MaxUses = request.MaxUses ?? 50,
            ExpiresAt = request.ExpiresAt,
            CreatedByUserId = consigneeId
        };
        _db.ReferralCodes.Add(entity);
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(consigneeId, "referral.generate", nameof(ReferralCode), entity.Id, code, ct);
        return new ReferralCodeDto(entity.Id, consignee.Id, consignee.FullName, entity.Code, entity.IsActive,
            entity.MaxUses, entity.CurrentUses, entity.ExpiresAt);
    }

    public async Task<RelationshipDto> ApplyAsync(ApplyReferralRequest request, Guid brokerId, CancellationToken ct = default)
    {
        var broker = await _db.Brokers.FirstOrDefaultAsync(x => x.Id == brokerId, ct)
                     ?? throw new UnauthorizedAccessException("Broker required.");
        var code = await _db.ReferralCodes.Include(x => x.Consignee)
            .FirstOrDefaultAsync(x => x.Code == request.Code.Trim().ToUpperInvariant() && x.IsActive, ct)
            ?? throw new InvalidOperationException("Invalid referral code.");
        if (code.ExpiresAt is not null && code.ExpiresAt < DateTime.UtcNow)
        {
            throw new InvalidOperationException("Referral code expired.");
        }

        if (code.MaxUses is not null && code.CurrentUses >= code.MaxUses)
        {
            code.IsActive = false;
            code.DeactivatedAt = DateTime.UtcNow;
            await _db.SaveChangesAsync(ct);
            throw new InvalidOperationException("Referral code max uses reached.");
        }

        var existing = await _db.ConsigneeBrokerRelationships
            .FirstOrDefaultAsync(x => x.ConsigneeId == code.ConsigneeId && x.BrokerId == brokerId, ct);
        if (existing is not null)
        {
            existing.Status = RelationshipStatus.Active;
            existing.SuspendedAt = null;
            existing.SuspensionReason = null;
        }
        else
        {
            existing = new ConsigneeBrokerRelationship
            {
                ConsigneeId = code.ConsigneeId,
                BrokerId = brokerId,
                ReferralCodeId = code.Id,
                Status = RelationshipStatus.Active
            };
            _db.ConsigneeBrokerRelationships.Add(existing);
        }

        code.CurrentUses++;
        if (code.MaxUses is not null && code.CurrentUses >= code.MaxUses)
        {
            code.IsActive = false;
            code.DeactivatedAt = DateTime.UtcNow;
        }

        await _db.SaveChangesAsync(ct);
        return new RelationshipDto(existing.Id, code.ConsigneeId, code.Consignee.FullName, brokerId, broker.FullName,
            existing.Status.ToString(), existing.SuspendedAt, existing.SuspensionReason,
            broker.Email, broker.BusinessAddress, existing.CreatedAt, broker.IsActive);
    }

    public async Task<IReadOnlyList<ReferralCodeDto>> ListForConsigneeAsync(Guid consigneeId, CancellationToken ct = default)
    {
        var items = await _db.ReferralCodes.AsNoTracking().Include(x => x.Consignee)
            .Where(x => x.ConsigneeId == consigneeId)
            .OrderByDescending(x => x.CreatedAt)
            .ToListAsync(ct);
        return items.Select(x => new ReferralCodeDto(x.Id, x.ConsigneeId, x.Consignee.FullName, x.Code, x.IsActive,
            x.MaxUses, x.CurrentUses, x.ExpiresAt)).ToList();
    }

    public async Task<IReadOnlyList<RelationshipDto>> ListRelationshipsAsync(Guid? consigneeId, Guid? brokerId, CancellationToken ct = default)
    {
        var q = _db.ConsigneeBrokerRelationships.AsNoTracking()
            .Include(x => x.Consignee)
            .Include(x => x.Broker)
            .AsQueryable();
        if (consigneeId.HasValue) q = q.Where(x => x.ConsigneeId == consigneeId);
        if (brokerId.HasValue) q = q.Where(x => x.BrokerId == brokerId);
        var items = await q.OrderByDescending(x => x.CreatedAt).ToListAsync(ct);
        return items.Select(x => new RelationshipDto(
            x.Id,
            x.ConsigneeId,
            x.Consignee.FullName,
            x.BrokerId,
            x.Broker.FullName,
            x.Status.ToString(),
            x.SuspendedAt,
            x.SuspensionReason,
            x.Broker.Email,
            x.Broker.BusinessAddress,
            x.CreatedAt,
            x.Broker.IsActive)).ToList();
    }

    public async Task DeactivateAsync(Guid codeId, Guid consigneeId, CancellationToken ct = default)
    {
        var code = await _db.ReferralCodes.FirstOrDefaultAsync(x => x.Id == codeId && x.ConsigneeId == consigneeId, ct)
                   ?? throw new KeyNotFoundException("Referral code not found.");
        code.IsActive = false;
        code.DeactivatedAt = DateTime.UtcNow;
        await _db.SaveChangesAsync(ct);
    }
}

public class OnboardingService : IOnboardingService
{
    private readonly OptimusDbContext _db;

    public OnboardingService(OptimusDbContext db) => _db = db;

    public async Task<WelcomeContentDto> GetWelcomeAsync(string audience, Guid? userId, CancellationToken ct = default)
    {
        var content = await _db.WelcomeContents.AsNoTracking()
            .FirstOrDefaultAsync(x => x.Audience == audience && x.IsActive, ct);
        if (content is null)
        {
            content = DefaultWelcome(audience);
        }

        var completed = new List<string>();
        if (userId.HasValue)
        {
            var consignee = await _db.Consignees.AsNoTracking().FirstOrDefaultAsync(x => x.Id == userId, ct);
            if (!string.IsNullOrWhiteSpace(consignee?.OnboardingCompletedStepsJson))
            {
                completed = JsonSerializer.Deserialize<List<string>>(consignee.OnboardingCompletedStepsJson) ?? new List<string>();
            }
        }

        return new WelcomeContentDto(content.Id, content.Audience, content.Title, content.BodyMarkdown, content.StepsJson, completed);
    }

    public async Task<WelcomeContentDto> UpsertWelcomeAsync(UpsertWelcomeContentRequest request, Guid actorId, CancellationToken ct = default)
    {
        var existing = await _db.WelcomeContents.Where(x => x.Audience == request.Audience && x.IsActive).ToListAsync(ct);
        foreach (var e in existing) e.IsActive = false;
        var entity = new WelcomeContent
        {
            Audience = request.Audience,
            Title = request.Title,
            BodyMarkdown = request.BodyMarkdown,
            StepsJson = request.StepsJson,
            IsActive = true
        };
        _db.WelcomeContents.Add(entity);
        await _db.SaveChangesAsync(ct);
        return new WelcomeContentDto(entity.Id, entity.Audience, entity.Title, entity.BodyMarkdown, entity.StepsJson, Array.Empty<string>());
    }

    public async Task<WelcomeContentDto> CompleteStepAsync(CompleteOnboardingStepRequest request, Guid consigneeId, CancellationToken ct = default)
    {
        var consignee = await _db.Consignees.FirstOrDefaultAsync(x => x.Id == consigneeId, ct)
                        ?? throw new UnauthorizedAccessException("Consignee required.");
        var steps = string.IsNullOrWhiteSpace(consignee.OnboardingCompletedStepsJson)
            ? new List<string>()
            : JsonSerializer.Deserialize<List<string>>(consignee.OnboardingCompletedStepsJson) ?? new List<string>();
        if (!steps.Contains(request.StepId, StringComparer.OrdinalIgnoreCase))
        {
            steps.Add(request.StepId);
        }

        consignee.OnboardingCompletedStepsJson = JsonSerializer.Serialize(steps);
        await _db.SaveChangesAsync(ct);
        return await GetWelcomeAsync("Consignee", consigneeId, ct);
    }

    private static WelcomeContent DefaultWelcome(string audience) =>
        new()
        {
            Id = Guid.Empty,
            Audience = audience,
            Title = "Welcome to Optimus",
            BodyMarkdown = "Complete these steps to get started with OPTIMUS.",
            StepsJson = """["submit_accreditation","link_brokers","generate_referral_code"]""",
            IsActive = true
        };
}
