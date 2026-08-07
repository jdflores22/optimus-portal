using Microsoft.EntityFrameworkCore;
using Optimus.Application.Security;
using Optimus.Infrastructure.Persistence;
using Optimus.Shared.Constants;

namespace Optimus.Infrastructure.Security;

public class ResourceAuthorizationService : IResourceAuthorizationService
{
    private readonly OptimusDbContext _db;

    public ResourceAuthorizationService(OptimusDbContext db) => _db = db;

    public async Task EnsureManifestAccessAsync(Guid manifestId, Guid userId, string role, CancellationToken ct = default)
    {
        if (IsStaff(role) || role is AppRoles.Accounting or AppRoles.TerminalTeam) return;

        var m = await _db.Manifests.AsNoTracking()
            .Where(x => x.Id == manifestId)
            .Select(x => new { x.Id, x.BrokerId, x.ConsigneeId })
            .FirstOrDefaultAsync(ct)
            ?? throw new KeyNotFoundException("Manifest not found.");

        if (role == AppRoles.Broker && m.BrokerId == userId) return;
        if (role == AppRoles.Consignee && m.ConsigneeId == userId) return;

        throw new UnauthorizedAccessException("You do not have access to this manifest.");
    }

    public async Task EnsureEdoAccessAsync(Guid edoId, Guid userId, string role, CancellationToken ct = default)
    {
        if (IsStaff(role) || role is AppRoles.TerminalTeam or AppRoles.Accounting) return;

        var edo = await _db.ElectronicDeliveryOrders.AsNoTracking()
            .Where(x => x.Id == edoId)
            .Select(x => new { x.Id, x.Manifest.BrokerId, x.Manifest.ConsigneeId })
            .FirstOrDefaultAsync(ct)
            ?? throw new KeyNotFoundException("eDO/CRO not found.");

        if (role == AppRoles.Broker && edo.BrokerId == userId) return;
        if (role == AppRoles.Consignee && edo.ConsigneeId == userId) return;

        throw new UnauthorizedAccessException("You do not have access to this eDO/CRO.");
    }

    public async Task EnsurePreAdviceAccessAsync(Guid preAdviceId, Guid userId, string role, CancellationToken ct = default)
    {
        if (IsStaff(role) || role == AppRoles.TerminalTeam) return;

        var pa = await _db.PreAdviceRequests.AsNoTracking()
            .Where(x => x.Id == preAdviceId)
            .Select(x => new { x.Id, x.TruckerId })
            .FirstOrDefaultAsync(ct)
            ?? throw new KeyNotFoundException("Pre-advice not found.");

        if (role == AppRoles.Trucker && pa.TruckerId == userId) return;

        throw new UnauthorizedAccessException("You do not have access to this pre-advice.");
    }

    private static bool IsStaff(string role) =>
        role is AppRoles.SystemAdmin or AppRoles.ShippingLinesAdmin or AppRoles.SlStaff
            or AppRoles.Evaluator;
}
