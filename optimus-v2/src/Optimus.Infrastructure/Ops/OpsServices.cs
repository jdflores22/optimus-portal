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

public class FormBuilderService : IFormBuilderService
{
    private readonly OptimusDbContext _db;
    private readonly IActivityLogService _activity;

    public FormBuilderService(OptimusDbContext db, IActivityLogService activity)
    {
        _db = db;
        _activity = activity;
    }

    public async Task<FormConfigurationDto> CreateAsync(UpsertFormRequest request, Guid actorId, CancellationToken ct = default)
    {
        var type = Enum.Parse<FormConfigType>(request.Type, true);
        var maxVersion = await _db.FormConfigurations.Where(x => x.Type == type).Select(x => (int?)x.Version).MaxAsync(ct) ?? 0;
        var entity = new FormConfiguration
        {
            Name = request.Name.Trim(),
            Type = type,
            Version = maxVersion + 1,
            Status = FormConfigStatus.Draft,
            FieldsJson = string.IsNullOrWhiteSpace(request.FieldsJson) ? """{"fields":[]}""" : request.FieldsJson,
            CreatedById = actorId
        };
        _db.FormConfigurations.Add(entity);
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "form.create", nameof(FormConfiguration), entity.Id, entity.Name, ct);
        return Map(entity);
    }

    public async Task<FormConfigurationDto> UpdateFieldsAsync(Guid id, FormFieldsUpdateRequest request, Guid actorId, CancellationToken ct = default)
    {
        var entity = await _db.FormConfigurations.FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Form not found.");
        if (entity.Status is FormConfigStatus.Active)
        {
            throw new InvalidOperationException("Cannot edit an active form. Create a new version.");
        }

        entity.FieldsJson = request.FieldsJson;
        await _db.SaveChangesAsync(ct);
        return Map(entity);
    }

    public async Task<FormConfigurationDto> PublishAsync(Guid id, Guid actorId, CancellationToken ct = default)
    {
        var entity = await _db.FormConfigurations.FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Form not found.");
        entity.Status = FormConfigStatus.Published;
        entity.PublishedAt = DateTime.UtcNow;
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "form.publish", nameof(FormConfiguration), id, null, ct);
        return Map(entity);
    }

    public async Task<FormConfigurationDto> ActivateAsync(Guid id, Guid actorId, CancellationToken ct = default)
    {
        var entity = await _db.FormConfigurations.FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Form not found.");
        if (entity.Status is not (FormConfigStatus.Published or FormConfigStatus.Inactive))
        {
            throw new InvalidOperationException("Publish the form before activating.");
        }

        var others = await _db.FormConfigurations
            .Where(x => x.Type == entity.Type && x.Status == FormConfigStatus.Active && x.Id != id)
            .ToListAsync(ct);
        foreach (var other in others)
        {
            other.Status = FormConfigStatus.Inactive;
        }

        entity.Status = FormConfigStatus.Active;
        entity.PublishedAt ??= DateTime.UtcNow;
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "form.activate", nameof(FormConfiguration), id, null, ct);
        return Map(entity);
    }

    public async Task DeleteAsync(Guid id, Guid actorId, CancellationToken ct = default)
    {
        var entity = await _db.FormConfigurations.FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Form not found.");

        if (entity.Status == FormConfigStatus.Active)
        {
            throw new InvalidOperationException("Cannot delete an active form. Activate another version first, or create a new version.");
        }

        var usedBySubmissions = await _db.AccreditationSubmissions.AnyAsync(x => x.FormConfigurationId == id, ct);
        if (usedBySubmissions)
        {
            throw new InvalidOperationException("Cannot delete this form because accreditation submissions reference it.");
        }

        _db.FormConfigurations.Remove(entity);
        await _db.SaveChangesAsync(ct);
        await _activity.LogAsync(actorId, "form.delete", nameof(FormConfiguration), id, entity.Name, ct);
    }

    public async Task<FormConfigurationDto?> GetActiveAsync(string type, CancellationToken ct = default)
    {
        if (!Enum.TryParse<FormConfigType>(type, true, out var t)) return null;
        var entity = await _db.FormConfigurations.AsNoTracking()
            .FirstOrDefaultAsync(x => x.Type == t && x.Status == FormConfigStatus.Active, ct);
        return entity is null ? null : Map(entity);
    }

    public async Task<IReadOnlyList<FormConfigurationDto>> ListAsync(string? type, CancellationToken ct = default)
    {
        var q = _db.FormConfigurations.AsNoTracking().AsQueryable();
        if (!string.IsNullOrWhiteSpace(type) && Enum.TryParse<FormConfigType>(type, true, out var t))
        {
            q = q.Where(x => x.Type == t);
        }

        var items = await q.OrderByDescending(x => x.Version).ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    private static FormConfigurationDto Map(FormConfiguration x) =>
        new(x.Id, x.Name, x.Type.ToString(), x.Version, x.Status.ToString(), x.FieldsJson, x.PublishedAt, x.CreatedAt);
}

public class AccreditationService : IAccreditationService
{
    private readonly OptimusDbContext _db;
    private readonly INotificationService _notifications;
    private readonly IActivityLogService _activity;
    private readonly IEmailSender _email;

    public AccreditationService(
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

    public async Task<AccreditationDto> SubmitAsync(SubmitAccreditationRequest request, Guid applicantId, string applicantRole, CancellationToken ct = default)
    {
        if (applicantRole is not (AppRoles.Broker or AppRoles.Consignee or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Broker/Consignee required.");
        }

        var formType = applicantRole == AppRoles.Consignee ? FormConfigType.Consignee : FormConfigType.Broker;
        if (applicantRole == AppRoles.SystemAdmin) formType = FormConfigType.Broker;

        var form = await _db.FormConfigurations.FirstOrDefaultAsync(x => x.Type == formType && x.Status == FormConfigStatus.Active, ct)
                   ?? throw new InvalidOperationException($"No active {formType} form configured.");

        ValidateSubmissionAgainstForm(form.FieldsJson, request.SubmittedDataJson);

        if (applicantRole == AppRoles.Consignee)
        {
            var hasBroker = await _db.ConsigneeBrokerRelationships.AnyAsync(
                x => x.ConsigneeId == applicantId && x.Status == RelationshipStatus.Active, ct);
            if (!hasBroker)
            {
                throw new InvalidOperationException("Consignee must have an active broker relationship before SAS.");
            }
        }

        var soleLineId = await SoleShippingLine.RequireIdAsync(_db, ct);
        var existing = await _db.AccreditationSubmissions
            .FirstOrDefaultAsync(x => x.ApplicantId == applicantId && x.ShippingLineId == soleLineId, ct);

        if (existing is null)
        {
            existing = new AccreditationSubmission
            {
                ApplicantId = applicantId,
                ShippingLineId = soleLineId,
                FormConfigurationId = form.Id
            };
            _db.AccreditationSubmissions.Add(existing);
        }
        else if (existing.Status is not (AccreditationStatus.ComplianceRequired or AccreditationStatus.Denied or AccreditationStatus.Rejected))
        {
            throw new InvalidOperationException($"Cannot resubmit while status is {existing.Status}.");
        }

        existing.FormConfigurationId = form.Id;
        existing.SubmittedDataJson = request.SubmittedDataJson;
        existing.Status = AccreditationStatus.Pending;
        existing.SubmittedAt = DateTime.UtcNow;
        existing.DenialReason = null;
        existing.ComplianceNotes = null;
        existing.ComplianceFieldIdsJson = null;
        existing.EvaluatedAt = null;
        existing.ApprovedAt = null;
        await _db.SaveChangesAsync(ct);

        var evaluators = await _db.Users.AsNoTracking().Where(x => x.Role == AppRoles.Evaluator).Select(x => x.Id).Take(5).ToListAsync(ct);
        foreach (var eid in evaluators)
        {
            await _notifications.NotifyAsync(eid, "SAS submission", "New accreditation pending review", "sas",
                nameof(AccreditationSubmission), existing.Id, ct);
        }

        await _activity.LogAsync(applicantId, "sas.submit", nameof(AccreditationSubmission), existing.Id, null, ct);
        return await GetAsync(existing.Id, ct);
    }

    private static void ValidateSubmissionAgainstForm(string fieldsJson, string submittedDataJson)
    {
        using var fieldsDoc = JsonDocument.Parse(string.IsNullOrWhiteSpace(fieldsJson) ? "{\"fields\":[]}" : fieldsJson);
        if (!fieldsDoc.RootElement.TryGetProperty("fields", out var fieldsEl) || fieldsEl.ValueKind != JsonValueKind.Array)
        {
            throw new InvalidOperationException("Active form has no fields configured.");
        }

        Dictionary<string, JsonElement> submitted;
        try
        {
            submitted = JsonSerializer.Deserialize<Dictionary<string, JsonElement>>(
                             string.IsNullOrWhiteSpace(submittedDataJson) ? "{}" : submittedDataJson)
                         ?? new Dictionary<string, JsonElement>();
        }
        catch
        {
            throw new InvalidOperationException("Submitted data must be valid JSON.");
        }

        foreach (var field in fieldsEl.EnumerateArray())
        {
            var id = field.TryGetProperty("id", out var idEl) ? idEl.GetString() : null;
            var label = field.TryGetProperty("label", out var labelEl) ? labelEl.GetString() : id;
            var required = field.TryGetProperty("required", out var reqEl) && reqEl.ValueKind == JsonValueKind.True;
            var type = field.TryGetProperty("type", out var typeEl) ? typeEl.GetString() ?? "text" : "text";
            if (string.IsNullOrWhiteSpace(id)) continue;
            if (type is "section_heading" or "divider") continue;

            // Honor showWhen: skip required checks when the controlling field does not match
            if (field.TryGetProperty("validation", out var validationEl)
                && validationEl.ValueKind == JsonValueKind.Object
                && validationEl.TryGetProperty("showWhen", out var showWhenEl)
                && showWhenEl.ValueKind == JsonValueKind.Object
                && showWhenEl.TryGetProperty("field", out var whenFieldEl))
            {
                var whenField = whenFieldEl.GetString();
                var whenValue = showWhenEl.TryGetProperty("value", out var whenValueEl)
                    ? whenValueEl.GetString() ?? ""
                    : "";
                if (!string.IsNullOrWhiteSpace(whenField))
                {
                    var actual = submitted.TryGetValue(whenField, out var ctrl)
                        ? (ctrl.ValueKind == JsonValueKind.Array
                            ? string.Join(",", ctrl.EnumerateArray().Select(x => x.ToString()))
                            : ctrl.ToString().Trim('"'))
                        : "";
                    if (!string.Equals(actual, whenValue, StringComparison.Ordinal))
                    {
                        continue;
                    }
                }
            }

            if (!required) continue;

            if (!submitted.TryGetValue(id, out var value))
            {
                throw new InvalidOperationException($"{label ?? id} is required.");
            }

            if (type is "checkbox" or "toggle" or "terms")
            {
                var ok = value.ValueKind == JsonValueKind.True
                         || (value.ValueKind == JsonValueKind.String
                             && bool.TryParse(value.GetString(), out var b) && b);
                if (!ok) throw new InvalidOperationException($"{label ?? id} is required.");
                continue;
            }

            if (type == "address")
            {
                if (!TryParseAddressValue(value, out var addr)
                    || string.IsNullOrWhiteSpace(addr.RegionId)
                    || string.IsNullOrWhiteSpace(addr.ProvinceId)
                    || string.IsNullOrWhiteSpace(addr.CityId)
                    || string.IsNullOrWhiteSpace(addr.BarangayId))
                {
                    throw new InvalidOperationException($"{label ?? id} requires region, province, city, and barangay.");
                }
                continue;
            }

            if (value.ValueKind == JsonValueKind.Array)
            {
                if (!value.EnumerateArray().Any())
                {
                    throw new InvalidOperationException($"{label ?? id} is required.");
                }
                continue;
            }

            var text = value.ValueKind switch
            {
                JsonValueKind.String => value.GetString(),
                JsonValueKind.Number => value.GetRawText(),
                JsonValueKind.True => "true",
                JsonValueKind.False => "false",
                _ => value.GetRawText()
            };
            if (string.IsNullOrWhiteSpace(text))
            {
                throw new InvalidOperationException($"{label ?? id} is required.");
            }
        }
    }

    private static bool TryParseAddressValue(JsonElement value, out (string? RegionId, string? ProvinceId, string? CityId, string? BarangayId) addr)
    {
        addr = default;
        JsonElement obj = value;
        if (value.ValueKind == JsonValueKind.String)
        {
            var raw = value.GetString();
            if (string.IsNullOrWhiteSpace(raw)) return false;
            try
            {
                using var doc = JsonDocument.Parse(raw);
                obj = doc.RootElement.Clone();
            }
            catch
            {
                return false;
            }
        }

        if (obj.ValueKind != JsonValueKind.Object) return false;

        static string? GetId(JsonElement o, string idKey, string? altNameKey = null)
        {
            if (o.TryGetProperty(idKey, out var idEl))
            {
                var s = idEl.ValueKind == JsonValueKind.String ? idEl.GetString() : idEl.ToString();
                if (!string.IsNullOrWhiteSpace(s)) return s;
            }
            if (altNameKey != null
                && o.TryGetProperty(altNameKey, out var nameEl)
                && nameEl.ValueKind == JsonValueKind.String
                && !string.IsNullOrWhiteSpace(nameEl.GetString()))
            {
                return nameEl.GetString();
            }
            return null;
        }

        addr = (
            GetId(obj, "region_id"),
            GetId(obj, "province_id", "province"),
            GetId(obj, "city_id"),
            GetId(obj, "barangay_id", "barangay"));
        return true;
    }

    public async Task<AccreditationDto> EvaluatorActionAsync(Guid id, EvaluatorActionRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        if (actorRole is not (AppRoles.Evaluator or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Evaluator required.");
        }

        var entity = await _db.AccreditationSubmissions.Include(x => x.Applicant).FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Submission not found.");
        if (entity.Status is not (AccreditationStatus.Pending or AccreditationStatus.ComplianceRequired))
        {
            throw new InvalidOperationException("Submission is not awaiting evaluator action.");
        }

        var action = request.Action.Trim().ToLowerInvariant();
        entity.EvaluatorId = actorId;
        entity.EvaluatedAt = DateTime.UtcNow;
        entity.DenialReason = null;
        entity.ComplianceNotes = null;

        switch (action)
        {
            case "approve":
                entity.Status = AccreditationStatus.AwaitingFinalApproval;
                break;
            case "deny":
                entity.Status = AccreditationStatus.Denied;
                entity.DenialReason = request.Notes ?? "Denied by evaluator";
                entity.Applicant.Status = AccountStatus.Denied;
                break;
            case "reject":
                entity.Status = AccreditationStatus.Rejected;
                entity.DenialReason = request.Notes ?? "Rejected by evaluator";
                break;
            case "compliance":
                entity.Status = AccreditationStatus.ComplianceRequired;
                entity.ComplianceNotes = request.Notes;
                entity.ComplianceFieldIdsJson = request.ComplianceFieldIdsJson;
                break;
            default:
                throw new InvalidOperationException("Action must be approve|deny|reject|compliance.");
        }

        await _db.SaveChangesAsync(ct);
        await _notifications.NotifyAsync(entity.ApplicantId, "SAS update", $"Status: {entity.Status}", "sas",
            nameof(AccreditationSubmission), entity.Id, ct);
        await _email.SendAsync(entity.Applicant.Email, "SAS accreditation update", $"Your submission is now {entity.Status}.", ct);

        if (entity.Status == AccreditationStatus.AwaitingFinalApproval)
        {
            var shippingAdminIds = await _db.Users.AsNoTracking()
                .Where(u =>
                    u.Role == AppRoles.ShippingLinesAdmin
                    && u.IsActive
                    && (u.ManagedShippingLineId == null || u.ManagedShippingLineId == entity.ShippingLineId))
                .Select(u => u.Id)
                .ToListAsync(ct);

            foreach (var adminId in shippingAdminIds)
            {
                await _notifications.NotifyAsync(
                    adminId,
                    "Final approval required",
                    $"{entity.Applicant.FullName} accreditation was forwarded for final approval.",
                    "sas",
                    nameof(AccreditationSubmission),
                    entity.Id,
                    ct);
            }
        }

        return await GetAsync(id, ct);
    }

    public async Task<AccreditationDto> FinalDecisionAsync(Guid id, FinalApprovalRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        if (actorRole is not (AppRoles.ShippingLinesAdmin or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Shipping admin required.");
        }

        var entity = await _db.AccreditationSubmissions.Include(x => x.Applicant).FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Submission not found.");
        if (entity.Status != AccreditationStatus.AwaitingFinalApproval)
        {
            throw new InvalidOperationException("Submission is not awaiting final approval.");
        }

        entity.FinalApproverId = actorId;
        entity.ApprovedAt = DateTime.UtcNow;
        if (request.Approve)
        {
            entity.Status = AccreditationStatus.Approved;
            entity.Applicant.Status = AccountStatus.Approved;
        }
        else
        {
            entity.Status = AccreditationStatus.Denied;
            entity.DenialReason = request.Notes ?? "Denied by shipping admin";
            entity.Applicant.Status = AccountStatus.Denied;
        }

        await _db.SaveChangesAsync(ct);
        await _notifications.NotifyAsync(entity.ApplicantId, "SAS final decision",
            request.Approve ? "Accreditation approved." : entity.DenialReason!,
            "sas", nameof(AccreditationSubmission), entity.Id, ct);
        return await GetAsync(id, ct);
    }

    public async Task<AccreditationDto> GetAsync(Guid id, CancellationToken ct = default)
    {
        var entity = await Query().FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Submission not found.");
        return Map(entity);
    }

    public async Task<IReadOnlyList<AccreditationDto>> ListAsync(string? status, Guid? applicantId, CancellationToken ct = default)
    {
        var q = Query();
        if (applicantId.HasValue) q = q.Where(x => x.ApplicantId == applicantId);
        if (!string.IsNullOrWhiteSpace(status) && Enum.TryParse<AccreditationStatus>(status, true, out var st))
        {
            q = q.Where(x => x.Status == st);
        }

        var items = await q.OrderByDescending(x => x.SubmittedAt).Take(200).ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    private IQueryable<AccreditationSubmission> Query() =>
        _db.AccreditationSubmissions.AsNoTracking()
            .Include(x => x.Applicant)
            .Include(x => x.ShippingLine);

    private static AccreditationDto Map(AccreditationSubmission x) =>
        new(x.Id, x.ApplicantId, x.Applicant.FullName, x.Applicant.Role, x.ShippingLineId, x.ShippingLine.BrandName,
            x.FormConfigurationId, x.Status.ToString(), x.SubmittedDataJson, x.DenialReason, x.ComplianceNotes,
            x.ComplianceFieldIdsJson, x.SubmittedAt, x.EvaluatedAt, x.ApprovedAt);
}

public class BrokerTransferService : IBrokerTransferService
{
    private readonly OptimusDbContext _db;
    private readonly INotificationService _notifications;
    private readonly IActivityLogService _activity;
    private readonly IEmailSender _email;

    public BrokerTransferService(
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

    public async Task<TransferDto> CreateAsync(CreateTransferRequest request, string? letterPath, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        if (actorRole is not (AppRoles.Consignee or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Consignee required.");
        }

        var manifest = await _db.Manifests.Include(x => x.Consignee).Include(x => x.Broker)
            .FirstOrDefaultAsync(x => x.Id == request.ManifestId, ct)
            ?? throw new KeyNotFoundException("Manifest not found.");
        if (manifest.BrokerId is null || manifest.ConsigneeId is null)
        {
            throw new InvalidOperationException("Manifest must have broker and consignee.");
        }

        if (actorRole == AppRoles.Consignee && manifest.ConsigneeId != actorId)
        {
            throw new UnauthorizedAccessException("Not your manifest.");
        }

        if (await _db.BrokerTransferRequests.AnyAsync(x => x.ManifestId == manifest.Id && x.Status == TransferRequestStatus.Pending, ct))
        {
            throw new InvalidOperationException("A pending transfer already exists for this manifest.");
        }

        var newBroker = await _db.Brokers.FirstOrDefaultAsync(x => x.Id == request.NewBrokerId, ct)
                        ?? throw new KeyNotFoundException("New broker not found.");

        // Suspension impact: if old broker denied/suspended, transfer is encouraged/allowed
        var oldBroker = await _db.Brokers.FirstAsync(x => x.Id == manifest.BrokerId, ct);
        var rel = await _db.ConsigneeBrokerRelationships
            .FirstOrDefaultAsync(x => x.ConsigneeId == manifest.ConsigneeId && x.BrokerId == manifest.BrokerId, ct);
        if (oldBroker.Status == AccountStatus.Denied || rel?.Status == RelationshipStatus.Suspended)
        {
            // allowed — suspension impact rule
        }

        var entity = new BrokerTransferRequest
        {
            ManifestId = manifest.Id,
            ConsigneeId = manifest.ConsigneeId.Value,
            OldBrokerId = manifest.BrokerId.Value,
            NewBrokerId = newBroker.Id,
            Reason = request.Reason,
            TransferLetterPath = letterPath,
            RequestedById = actorId
        };
        _db.BrokerTransferRequests.Add(entity);
        await _db.SaveChangesAsync(ct);

        await _notifications.NotifyAsync(newBroker.Id, "Broker transfer request",
            $"Transfer requested for manifest {manifest.ManifestNumber}", "transfer", nameof(BrokerTransferRequest), entity.Id, ct);
        await _email.SendAsync(newBroker.Email, "Broker transfer request",
            $"You were selected as new broker for {manifest.ManifestNumber}. Reason: {request.Reason}", ct);
        await _activity.LogAsync(actorId, "transfer.create", nameof(BrokerTransferRequest), entity.Id, manifest.ManifestNumber, ct);
        return await MapAsync(entity.Id, ct);
    }

    public async Task<TransferDto> ReviewAsync(Guid id, ReviewTransferRequest request, Guid actorId, string actorRole, CancellationToken ct = default)
    {
        if (actorRole is not (AppRoles.SlStaff or AppRoles.ShippingLinesAdmin or AppRoles.SystemAdmin))
        {
            throw new UnauthorizedAccessException("Staff required.");
        }

        var entity = await _db.BrokerTransferRequests.Include(x => x.Manifest).FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Transfer not found.");
        if (entity.Status != TransferRequestStatus.Pending)
        {
            throw new InvalidOperationException("Transfer is not pending.");
        }

        entity.ReviewedById = actorId;
        entity.ReviewedAt = DateTime.UtcNow;
        entity.ReviewNotes = request.Notes;
        if (request.Approve)
        {
            entity.Status = TransferRequestStatus.Approved;
            entity.Manifest.BrokerId = entity.NewBrokerId;
        }
        else
        {
            entity.Status = TransferRequestStatus.Rejected;
            if (string.IsNullOrWhiteSpace(request.Notes))
            {
                throw new InvalidOperationException("Rejection notes required.");
            }
        }

        await _db.SaveChangesAsync(ct);
        await _notifications.NotifyAsync(entity.ConsigneeId, "Transfer reviewed",
            $"Transfer {entity.Status}", "transfer", nameof(BrokerTransferRequest), entity.Id, ct);
        return await MapAsync(id, ct);
    }

    public async Task<IReadOnlyList<TransferDto>> ListAsync(string? status, CancellationToken ct = default)
    {
        var q = _db.BrokerTransferRequests.AsNoTracking()
            .Include(x => x.Manifest)
            .Include(x => x.Consignee)
            .Include(x => x.OldBroker)
            .Include(x => x.NewBroker)
            .AsQueryable();
        if (!string.IsNullOrWhiteSpace(status) && Enum.TryParse<TransferRequestStatus>(status, true, out var st))
        {
            q = q.Where(x => x.Status == st);
        }

        var items = await q.OrderByDescending(x => x.RequestedAt).Take(200).ToListAsync(ct);
        return items.Select(x => new TransferDto(x.Id, x.ManifestId, x.Manifest.ManifestNumber, x.ConsigneeId, x.Consignee.FullName,
            x.OldBrokerId, x.OldBroker.FullName, x.NewBrokerId, x.NewBroker.FullName, x.Reason, x.Status.ToString(),
            x.TransferLetterPath, x.RequestedAt, x.ReviewNotes)).ToList();
    }

    private async Task<TransferDto> MapAsync(Guid id, CancellationToken ct)
    {
        var list = await ListAsync(null, ct);
        return list.First(x => x.Id == id);
    }
}

public class LocationService : ILocationService
{
    private readonly OptimusDbContext _db;

    public LocationService(OptimusDbContext db) => _db = db;

    public async Task<IReadOnlyList<LocationItemDto>> GetRegionsAsync(CancellationToken ct = default)
        => await _db.Regions.AsNoTracking()
            .OrderBy(x => x.Name)
            .Select(x => new LocationItemDto(x.Id, x.Name, x.Code))
            .ToListAsync(ct);

    public async Task<IReadOnlyList<LocationItemDto>> GetProvincesByRegionAsync(Guid regionId, CancellationToken ct = default)
        => await _db.Provinces.AsNoTracking()
            .Where(x => x.RegionId == regionId)
            .OrderBy(x => x.Name)
            .Select(x => new LocationItemDto(x.Id, x.Name, x.Code))
            .ToListAsync(ct);

    public async Task<IReadOnlyList<LocationItemDto>> GetCitiesByProvinceAsync(Guid provinceId, CancellationToken ct = default)
        => await _db.Cities.AsNoTracking()
            .Where(x => x.ProvinceId == provinceId)
            .OrderBy(x => x.Name)
            .Select(x => new LocationItemDto(x.Id, x.Name, x.Code))
            .ToListAsync(ct);

    public async Task<IReadOnlyList<LocationItemDto>> GetBarangaysByCityAsync(Guid cityId, CancellationToken ct = default)
        => await _db.Barangays.AsNoTracking()
            .Where(x => x.CityId == cityId)
            .OrderBy(x => x.Name)
            .Select(x => new LocationItemDto(x.Id, x.Name, x.Code))
            .ToListAsync(ct);
}
