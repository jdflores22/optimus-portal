using System.Security.Claims;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Optimus.Application.ShippingAdmin.Dtos;
using Optimus.Application.ShippingAdmin.Interfaces;
using Optimus.Shared.Constants;

namespace Optimus.Api.Controllers;

[ApiController]
[Route("api/shipping-admin")]
[Authorize(Roles = AppRoles.ShippingLinesAdmin)]
public class ShippingAdminPartnersController : ControllerBase
{
    private readonly IShippingAdminPartnerService _partners;

    public ShippingAdminPartnersController(IShippingAdminPartnerService partners) => _partners = partners;

    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);

    [HttpGet("consignees")]
    public async Task<ActionResult<IReadOnlyList<ShippingAdminConsigneeDto>>> ListConsignees(CancellationToken ct)
        => Ok(await _partners.ListConsigneesAsync(UserId, ct));

    [HttpGet("consignees/{id:guid}")]
    public async Task<ActionResult<ShippingAdminConsigneeDetailDto>> GetConsignee(Guid id, CancellationToken ct)
        => Ok(await _partners.GetConsigneeAsync(UserId, id, ct));

    [HttpGet("brokers")]
    public async Task<ActionResult<IReadOnlyList<ShippingAdminBrokerDto>>> ListBrokers(CancellationToken ct)
        => Ok(await _partners.ListBrokersAsync(UserId, ct));

    [HttpGet("brokers/{id:guid}")]
    public async Task<ActionResult<ShippingAdminBrokerDetailDto>> GetBroker(Guid id, CancellationToken ct)
        => Ok(await _partners.GetBrokerAsync(UserId, id, ct));
}
