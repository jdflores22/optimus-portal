using System.Security.Claims;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Optimus.Application.Auth.Dtos;
using Optimus.Application.Auth.Interfaces;
using Optimus.Shared.Constants;

namespace Optimus.Api.Controllers;

[ApiController]
[Route("api/hierarchy")]
[Authorize(Policy = "StaffHierarchy")]
public class HierarchyController : ControllerBase
{
    private static readonly string[] ShippingAdminInviteRoles =
    {
        AppRoles.SlStaff,
        AppRoles.Evaluator,
        AppRoles.Accounting,
        AppRoles.TerminalTeam,
    };

    private readonly IHierarchyService _hierarchyService;
    private readonly IRoleAcceptanceService _roleAcceptanceService;

    public HierarchyController(IHierarchyService hierarchyService, IRoleAcceptanceService roleAcceptanceService)
    {
        _hierarchyService = hierarchyService;
        _roleAcceptanceService = roleAcceptanceService;
    }

    private Guid UserId => Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
    private string Role => User.FindFirstValue(ClaimTypes.Role) ?? string.Empty;

    [HttpGet("users")]
    public async Task<ActionResult<IReadOnlyList<UserDto>>> ListUsers(CancellationToken cancellationToken)
        => Ok(await _hierarchyService.ListUsersAsync(UserId, Role, cancellationToken));

    [HttpPost("users/{id:guid}/unlock")]
    [Authorize(Policy = "SystemAdmin")]
    public async Task<IActionResult> Unlock(Guid id, CancellationToken cancellationToken)
    {
        await _hierarchyService.UnlockUserAsync(id, cancellationToken);
        return NoContent();
    }

    [HttpGet("users/export")]
    [Authorize(Policy = "SystemAdmin")]
    public async Task<IActionResult> Export(CancellationToken cancellationToken)
    {
        var bytes = await _hierarchyService.ExportUsersCsvAsync(cancellationToken);
        return File(bytes, "text/csv", "optimus-users.csv");
    }

    [HttpGet("invitations")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<IReadOnlyList<PendingUserDto>>> Invitations(CancellationToken cancellationToken)
        => Ok(await _roleAcceptanceService.ListPendingAsync(cancellationToken));

    [HttpPost("invitations")]
    [Authorize(Policy = "ShippingAdmin")]
    public async Task<ActionResult<PendingUserDto>> Invite([FromBody] InviteUserRequest request, CancellationToken cancellationToken)
    {
        var invite = request;
        if (Role == AppRoles.ShippingLinesAdmin)
        {
            if (!ShippingAdminInviteRoles.Contains(request.Role))
            {
                return BadRequest(new { message = "Shipping Lines Admin can only invite SlStaff, Evaluator, Accounting, or TerminalTeam." });
            }

            var me = await _hierarchyService.ListUsersAsync(UserId, Role, cancellationToken);
            var self = me.FirstOrDefault(u => u.Id == UserId);
            invite = request with
            {
                ShippingLineId = self?.ManagedShippingLineId ?? request.ShippingLineId,
                ShippingLineAdminId = UserId,
            };
        }

        return Ok(await _roleAcceptanceService.InviteAsync(invite, UserId, cancellationToken));
    }
}

[ApiController]
[Route("api/workspace")]
[Authorize(Policy = "Broker")]
public class WorkspaceController : ControllerBase
{
    private readonly IWorkspaceService _workspaceService;

    public WorkspaceController(IWorkspaceService workspaceService)
    {
        _workspaceService = workspaceService;
    }

    [HttpGet]
    public async Task<ActionResult<IReadOnlyList<WorkspaceDto>>> List(CancellationToken cancellationToken)
    {
        var userId = Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
        return Ok(await _workspaceService.ListBrokerWorkspacesAsync(userId, cancellationToken));
    }

    [HttpPost("switch")]
    public async Task<ActionResult<AuthResponse>> Switch([FromBody] SwitchWorkspaceRequest request, CancellationToken cancellationToken)
    {
        var userId = Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
        var ip = HttpContext.Connection.RemoteIpAddress?.ToString();
        return Ok(await _workspaceService.SwitchWorkspaceAsync(userId, request, ip, cancellationToken));
    }
}

[ApiController]
[Route("api/me")]
[Authorize]
public class MeController : ControllerBase
{
    private readonly IHierarchyService _hierarchyService;

    public MeController(IHierarchyService hierarchyService)
    {
        _hierarchyService = hierarchyService;
    }

    [HttpGet]
    public async Task<ActionResult<UserDto>> Me(CancellationToken cancellationToken)
    {
        var userId = Guid.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier)!);
        var users = await _hierarchyService.ListUsersAsync(cancellationToken: cancellationToken);
        var me = users.FirstOrDefault(u => u.Id == userId);
        return me is null ? NotFound() : Ok(me);
    }
}
