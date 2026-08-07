using System.Security.Claims;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Optimus.Api.Security;
using Optimus.Application.Auth.Dtos;
using Optimus.Application.Auth.Interfaces;

namespace Optimus.Api.Controllers;

[ApiController]
[Route("api/shipping-lines")]
[Authorize]
public class ShippingLinesController : ControllerBase
{
    private readonly IShippingLineService _service;

    public ShippingLinesController(IShippingLineService service)
    {
        _service = service;
    }

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<ShippingLineDto>>> List(CancellationToken cancellationToken)
        => Ok(await _service.ListAsync(cancellationToken));

    [HttpGet("{id:guid}")]
    public async Task<ActionResult<ShippingLineDto>> Get(Guid id, CancellationToken cancellationToken)
        => Ok(await _service.GetAsync(id, cancellationToken));

    [HttpPost]
    [Authorize(Policy = "SystemAdmin")]
    public async Task<ActionResult<ShippingLineDto>> Create([FromBody] CreateShippingLineRequest request, CancellationToken cancellationToken)
        => Ok(await _service.CreateAsync(request, cancellationToken));

    [HttpPut("{id:guid}")]
    [Authorize(Policy = "SystemAdmin")]
    public async Task<ActionResult<ShippingLineDto>> Update(Guid id, [FromBody] UpdateShippingLineRequest request, CancellationToken cancellationToken)
        => Ok(await _service.UpdateAsync(id, request, cancellationToken));

    [HttpPost("{id:guid}/activate")]
    [Authorize(Policy = "SystemAdmin")]
    public async Task<IActionResult> Activate(Guid id, CancellationToken cancellationToken)
    {
        await _service.SetActiveAsync(id, true, cancellationToken);
        return NoContent();
    }

    [HttpPost("{id:guid}/deactivate")]
    [Authorize(Policy = "SystemAdmin")]
    public async Task<IActionResult> Deactivate(Guid id, CancellationToken cancellationToken)
    {
        await _service.SetActiveAsync(id, false, cancellationToken);
        return NoContent();
    }

    [HttpPost("{id:guid}/assign-admin/{adminUserId:guid}")]
    [Authorize(Policy = "SystemAdmin")]
    public async Task<IActionResult> AssignAdmin(Guid id, Guid adminUserId, CancellationToken cancellationToken)
    {
        await _service.AssignAdminAsync(id, adminUserId, cancellationToken);
        return NoContent();
    }

    [HttpPost("{id:guid}/logo")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<IActionResult> UploadLogo(Guid id, IFormFile file, CancellationToken cancellationToken)
    {
        UploadGuard.Validate(file, ".png", ".jpg", ".jpeg", ".webp", ".gif", ".svg");

        var uploads = Path.Combine(Directory.GetCurrentDirectory(), "wwwroot", "uploads", "logos");
        Directory.CreateDirectory(uploads);
        var fileName = $"{id:N}{Path.GetExtension(file.FileName).ToLowerInvariant()}";
        var fullPath = Path.Combine(uploads, fileName);
        await using (var stream = System.IO.File.Create(fullPath))
        {
            await file.CopyToAsync(stream, cancellationToken);
        }

        var relative = $"/uploads/logos/{fileName}";
        await _service.UploadLogoAsync(id, relative, cancellationToken);
        return Ok(new { logoPath = relative });
    }

    [HttpPost("switch")]
    public async Task<ActionResult<AuthResponse>> Switch([FromBody] SwitchShippingLineRequest request, CancellationToken cancellationToken)
    {
        var userId = Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
        var ip = HttpContext.Connection.RemoteIpAddress?.ToString();
        return Ok(await _service.SwitchShippingLineAsync(userId, request, ip, cancellationToken));
    }

    [HttpGet("{id:guid}/permissions")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<IReadOnlyList<RolePermissionDto>>> Permissions(Guid id, CancellationToken cancellationToken)
        => Ok(await _service.GetPermissionsAsync(id, cancellationToken));

    [HttpPut("{id:guid}/permissions")]
    [Authorize(Policy = "SystemAdmin")]
    public async Task<IActionResult> UpsertPermissions(Guid id, [FromBody] IReadOnlyList<RolePermissionDto> permissions, CancellationToken cancellationToken)
    {
        await _service.UpsertPermissionsAsync(new UpsertRolePermissionsRequest(id, permissions), cancellationToken);
        return NoContent();
    }
}
