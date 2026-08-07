using Optimus.Application.ShippingAdmin.Dtos;

namespace Optimus.Application.ShippingAdmin.Interfaces;

public interface IShippingAdminPartnerService
{
    Task<IReadOnlyList<ShippingAdminConsigneeDto>> ListConsigneesAsync(Guid adminUserId, CancellationToken ct = default);
    Task<ShippingAdminConsigneeDetailDto> GetConsigneeAsync(Guid adminUserId, Guid consigneeId, CancellationToken ct = default);
    Task<IReadOnlyList<ShippingAdminBrokerDto>> ListBrokersAsync(Guid adminUserId, CancellationToken ct = default);
    Task<ShippingAdminBrokerDetailDto> GetBrokerAsync(Guid adminUserId, Guid brokerId, CancellationToken ct = default);
}
