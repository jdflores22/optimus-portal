using Microsoft.EntityFrameworkCore;
using Optimus.Application.ShippingAdmin.Dtos;
using Optimus.Application.ShippingAdmin.Interfaces;
using Optimus.Domain.Enums;
using Optimus.Infrastructure.Persistence;
using Optimus.Shared.Constants;

namespace Optimus.Infrastructure.ShippingAdmin;

public class ShippingAdminPartnerService : IShippingAdminPartnerService
{
    private readonly OptimusDbContext _db;

    public ShippingAdminPartnerService(OptimusDbContext db) => _db = db;

    public async Task<IReadOnlyList<ShippingAdminConsigneeDto>> ListConsigneesAsync(Guid adminUserId, CancellationToken ct = default)
    {
        var lineId = await ResolveLineIdAsync(adminUserId, ct);
        var consigneeIds = await ApprovedApplicantIdsAsync(lineId, AppRoles.Consignee, ct);

        var consignees = await _db.Consignees.AsNoTracking()
            .Where(c => consigneeIds.Contains(c.Id))
            .OrderBy(c => c.BusinessName)
            .ToListAsync(ct);

        var result = new List<ShippingAdminConsigneeDto>();
        foreach (var c in consignees)
        {
            result.Add(await MapConsigneeAsync(c.Id, c.BusinessName, c.FullName, c.Email, c.Status.ToString(), c.IsActive, lineId, ct));
        }

        return result;
    }

    public async Task<ShippingAdminConsigneeDetailDto> GetConsigneeAsync(Guid adminUserId, Guid consigneeId, CancellationToken ct = default)
    {
        var lineId = await ResolveLineIdAsync(adminUserId, ct);
        var approved = await IsApprovedApplicantAsync(consigneeId, lineId, AppRoles.Consignee, ct);
        if (!approved) throw new UnauthorizedAccessException("Consignee is not registered to your shipping line.");

        var c = await _db.Consignees.AsNoTracking().FirstOrDefaultAsync(x => x.Id == consigneeId, ct)
                ?? throw new KeyNotFoundException("Consignee not found.");

        var dto = await MapConsigneeAsync(c.Id, c.BusinessName, c.FullName, c.Email, c.Status.ToString(), c.IsActive, lineId, ct);
        var edoCount = await _db.ElectronicDeliveryOrders.AsNoTracking()
            .CountAsync(e => e.Manifest.ConsigneeId == consigneeId && e.Manifest.ShippingLineId == lineId, ct);
        var recent = await _db.Manifests.AsNoTracking()
            .Where(m => m.ConsigneeId == consigneeId && m.ShippingLineId == lineId)
            .OrderByDescending(m => m.CreatedAt)
            .Take(10)
            .Select(m => new RecentManifestDto(m.Id, m.ManifestNumber, m.WorkflowState.ToString(), m.CreatedAt))
            .ToListAsync(ct);

        return new ShippingAdminConsigneeDetailDto(dto, edoCount, recent, await LoadAccreditationAsync(consigneeId, lineId, ct));
    }

    public async Task<IReadOnlyList<ShippingAdminBrokerDto>> ListBrokersAsync(Guid adminUserId, CancellationToken ct = default)
    {
        var lineId = await ResolveLineIdAsync(adminUserId, ct);
        var brokerIds = await ApprovedApplicantIdsAsync(lineId, AppRoles.Broker, ct);

        var brokers = await _db.Brokers.AsNoTracking()
            .Where(b => brokerIds.Contains(b.Id))
            .OrderBy(b => b.FirstName).ThenBy(b => b.LastName)
            .ToListAsync(ct);

        var result = new List<ShippingAdminBrokerDto>();
        foreach (var b in brokers)
        {
            result.Add(await MapBrokerAsync(b.Id, b.FullName, b.Email, b.Status.ToString(), b.IsActive, lineId, ct));
        }

        return result;
    }

    public async Task<ShippingAdminBrokerDetailDto> GetBrokerAsync(Guid adminUserId, Guid brokerId, CancellationToken ct = default)
    {
        var lineId = await ResolveLineIdAsync(adminUserId, ct);
        var approved = await IsApprovedApplicantAsync(brokerId, lineId, AppRoles.Broker, ct);
        if (!approved) throw new UnauthorizedAccessException("Broker is not registered to your shipping line.");

        var b = await _db.Brokers.AsNoTracking().FirstOrDefaultAsync(x => x.Id == brokerId, ct)
                ?? throw new KeyNotFoundException("Broker not found.");

        var dto = await MapBrokerAsync(b.Id, b.FullName, b.Email, b.Status.ToString(), b.IsActive, lineId, ct);
        var containerCount = await _db.Containers.AsNoTracking()
            .CountAsync(c => c.Manifest != null && c.Manifest.BrokerId == brokerId && c.Manifest.ShippingLineId == lineId, ct);
        var recent = await _db.Manifests.AsNoTracking()
            .Where(m => m.BrokerId == brokerId && m.ShippingLineId == lineId)
            .OrderByDescending(m => m.CreatedAt)
            .Take(10)
            .Select(m => new RecentManifestDto(m.Id, m.ManifestNumber, m.WorkflowState.ToString(), m.CreatedAt))
            .ToListAsync(ct);

        return new ShippingAdminBrokerDetailDto(dto, containerCount, recent, await LoadAccreditationAsync(brokerId, lineId, ct));
    }

    private async Task<PartnerAccreditationDto?> LoadAccreditationAsync(Guid applicantId, Guid lineId, CancellationToken ct)
    {
        var submission = await _db.AccreditationSubmissions.AsNoTracking()
            .Include(a => a.FormConfiguration)
            .Where(a =>
                a.ApplicantId == applicantId
                && a.ShippingLineId == lineId
                && a.Status == AccreditationStatus.Approved)
            .OrderByDescending(a => a.ApprovedAt ?? a.SubmittedAt)
            .FirstOrDefaultAsync(ct);

        if (submission is null) return null;

        return new PartnerAccreditationDto(
            submission.Id,
            submission.FormConfigurationId,
            submission.FormConfiguration.Name,
            submission.FormConfiguration.Type.ToString(),
            submission.FormConfiguration.Version,
            submission.FormConfiguration.FieldsJson,
            submission.SubmittedDataJson,
            submission.Status.ToString(),
            submission.SubmittedAt,
            submission.ApprovedAt,
            submission.EvaluatedAt);
    }

    private async Task<Guid> ResolveLineIdAsync(Guid adminUserId, CancellationToken ct)
    {
        var admin = await _db.Users.AsNoTracking().FirstOrDefaultAsync(u => u.Id == adminUserId, ct)
                    ?? throw new UnauthorizedAccessException("User not found.");
        if (admin.Role != AppRoles.ShippingLinesAdmin)
        {
            throw new UnauthorizedAccessException("Shipping Lines Admin required.");
        }

        if (admin.ManagedShippingLineId.HasValue) return admin.ManagedShippingLineId.Value;

        var pref = await _db.UserShippingLinePreferences.AsNoTracking()
            .FirstOrDefaultAsync(p => p.UserId == adminUserId, ct);
        if (pref?.LastSelectedShippingLineId is Guid lineId) return lineId;

        throw new InvalidOperationException("No shipping line assigned to your account.");
    }

    private async Task<HashSet<Guid>> ApprovedApplicantIdsAsync(Guid lineId, string role, CancellationToken ct)
    {
        var ids = await _db.AccreditationSubmissions.AsNoTracking()
            .Where(a =>
                a.ShippingLineId == lineId
                && a.Status == AccreditationStatus.Approved
                && a.Applicant.Role == role)
            .Select(a => a.ApplicantId)
            .Distinct()
            .ToListAsync(ct);
        return ids.ToHashSet();
    }

    private Task<bool> IsApprovedApplicantAsync(Guid applicantId, Guid lineId, string role, CancellationToken ct)
        => _db.AccreditationSubmissions.AsNoTracking().AnyAsync(a =>
            a.ApplicantId == applicantId
            && a.ShippingLineId == lineId
            && a.Status == AccreditationStatus.Approved
            && a.Applicant.Role == role, ct);

    private async Task<ShippingAdminConsigneeDto> MapConsigneeAsync(
        Guid id, string businessName, string fullName, string email, string status, bool isActive, Guid lineId, CancellationToken ct)
    {
        var accreditedBrokerIds = await ApprovedApplicantIdsAsync(lineId, AppRoles.Broker, ct);
        var linked = await _db.ConsigneeBrokerRelationships.AsNoTracking()
            .Include(r => r.Broker)
            .Where(r =>
                r.ConsigneeId == id
                && r.Status == RelationshipStatus.Active
                && accreditedBrokerIds.Contains(r.BrokerId))
            .Select(r => new LinkedPartyDto(r.BrokerId, r.Broker.FullName, r.Broker.Email))
            .ToListAsync(ct);

        var manifestCount = await _db.Manifests.AsNoTracking()
            .CountAsync(m => m.ConsigneeId == id && m.ShippingLineId == lineId, ct);
        var noaCount = await _db.Noas.AsNoTracking()
            .CountAsync(n => n.ConsigneeId == id && n.Manifest.ShippingLineId == lineId, ct);
        var containerCount = await _db.Containers.AsNoTracking()
            .CountAsync(c => c.Manifest != null && c.Manifest.ConsigneeId == id && c.Manifest.ShippingLineId == lineId, ct);

        return new ShippingAdminConsigneeDto(
            id, businessName, fullName, email, status, isActive,
            linked.Count, noaCount, manifestCount, containerCount, linked);
    }

    private async Task<ShippingAdminBrokerDto> MapBrokerAsync(
        Guid id, string fullName, string email, string status, bool isActive, Guid lineId, CancellationToken ct)
    {
        var accreditedConsigneeIds = await ApprovedApplicantIdsAsync(lineId, AppRoles.Consignee, ct);
        var linked = await _db.ConsigneeBrokerRelationships.AsNoTracking()
            .Include(r => r.Consignee)
            .Where(r =>
                r.BrokerId == id
                && r.Status == RelationshipStatus.Active
                && accreditedConsigneeIds.Contains(r.ConsigneeId))
            .Select(r => new LinkedPartyDto(
                r.ConsigneeId,
                string.IsNullOrWhiteSpace(r.Consignee.BusinessName) ? r.Consignee.FullName : r.Consignee.BusinessName,
                r.Consignee.Email))
            .ToListAsync(ct);

        var manifestCount = await _db.Manifests.AsNoTracking()
            .CountAsync(m => m.BrokerId == id && m.ShippingLineId == lineId, ct);
        var edoCount = await _db.ElectronicDeliveryOrders.AsNoTracking()
            .CountAsync(e => e.Manifest.BrokerId == id && e.Manifest.ShippingLineId == lineId, ct);

        return new ShippingAdminBrokerDto(
            id, fullName, email, status, isActive,
            linked.Count, manifestCount, edoCount, linked);
    }
}
