using Microsoft.EntityFrameworkCore;
using Optimus.Infrastructure.Persistence;

namespace Optimus.Infrastructure.Shipping;

/// <summary>
/// OPTIMUS is single-shipping-line. Resolve the sole configured line id.
/// </summary>
public static class SoleShippingLine
{
    public static async Task<Guid> RequireIdAsync(OptimusDbContext db, CancellationToken ct = default)
    {
        var id = await db.ShippingLines.AsNoTracking()
            .Where(x => x.IsActive)
            .OrderBy(x => x.BrandName)
            .Select(x => x.Id)
            .FirstOrDefaultAsync(ct);

        if (id == Guid.Empty)
        {
            id = await db.ShippingLines.AsNoTracking()
                .OrderBy(x => x.BrandName)
                .Select(x => x.Id)
                .FirstOrDefaultAsync(ct);
        }

        if (id == Guid.Empty)
        {
            throw new InvalidOperationException("No shipping line is configured. Seed or create the single shipping line.");
        }

        return id;
    }
}
