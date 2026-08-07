using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Optimus.Application.Auth.Dtos;
using Optimus.Application.Auth.Interfaces;

namespace Optimus.Api.Controllers;

[ApiController]
[Route("api/auth")]
public class AuthController : ControllerBase
{
    private readonly IAuthService _authService;
    private readonly IRoleAcceptanceService _roleAcceptanceService;

    public AuthController(IAuthService authService, IRoleAcceptanceService roleAcceptanceService)
    {
        _authService = authService;
        _roleAcceptanceService = roleAcceptanceService;
    }

    [HttpPost("login")]
    [AllowAnonymous]
    public async Task<ActionResult<AuthResponse>> Login([FromBody] LoginRequest request, CancellationToken cancellationToken)
    {
        var ip = HttpContext.Connection.RemoteIpAddress?.ToString();
        return Ok(await _authService.LoginAsync(request, ip, cancellationToken));
    }

    [HttpPost("refresh")]
    [AllowAnonymous]
    public async Task<ActionResult<AuthResponse>> Refresh([FromBody] RefreshTokenRequest request, CancellationToken cancellationToken)
    {
        var ip = HttpContext.Connection.RemoteIpAddress?.ToString();
        return Ok(await _authService.RefreshAsync(request, ip, cancellationToken));
    }

    [HttpPost("logout")]
    [Authorize]
    public async Task<IActionResult> Logout([FromBody] RefreshTokenRequest request, CancellationToken cancellationToken)
    {
        await _authService.LogoutAsync(request.RefreshToken, cancellationToken);
        return NoContent();
    }

    [HttpPost("register/broker")]
    [AllowAnonymous]
    public async Task<IActionResult> RegisterBroker([FromBody] RegisterBrokerRequest request, CancellationToken cancellationToken)
    {
        await _authService.RegisterBrokerAsync(request, cancellationToken);
        return Accepted(new { message = "Broker registered. Check email/logs for verification token." });
    }

    [HttpPost("register/consignee")]
    [AllowAnonymous]
    public async Task<IActionResult> RegisterConsignee([FromBody] RegisterConsigneeRequest request, CancellationToken cancellationToken)
    {
        await _authService.RegisterConsigneeAsync(request, cancellationToken);
        return Accepted(new { message = "Consignee registered. Check email/logs for verification token." });
    }

    [HttpPost("password/request-otp")]
    [AllowAnonymous]
    public async Task<IActionResult> RequestOtp([FromBody] RequestPasswordResetRequest request, CancellationToken cancellationToken)
    {
        await _authService.RequestPasswordResetAsync(request, cancellationToken);
        return Accepted(new { message = "If the account exists, an OTP was sent." });
    }

    [HttpPost("password/reset")]
    [AllowAnonymous]
    public async Task<IActionResult> ResetPassword([FromBody] VerifyOtpResetRequest request, CancellationToken cancellationToken)
    {
        await _authService.ResetPasswordWithOtpAsync(request, cancellationToken);
        return Ok(new { message = "Password updated." });
    }

    [HttpPost("verify-email")]
    [AllowAnonymous]
    public async Task<IActionResult> VerifyEmail([FromBody] VerifyEmailRequest request, CancellationToken cancellationToken)
    {
        await _authService.VerifyEmailAsync(request, cancellationToken);
        return Ok(new { message = "Email verified." });
    }

    [HttpGet("role-acceptance/{token}")]
    [AllowAnonymous]
    public async Task<ActionResult<PendingUserDto>> GetInvitation(string token, CancellationToken cancellationToken)
    {
        var pending = await _roleAcceptanceService.GetByTokenAsync(token, cancellationToken);
        return pending is null ? NotFound() : Ok(pending);
    }

    [HttpPost("role-acceptance/accept")]
    [AllowAnonymous]
    public async Task<ActionResult<AuthResponse>> AcceptInvitation([FromBody] RoleAcceptanceRequest request, CancellationToken cancellationToken)
    {
        var ip = HttpContext.Connection.RemoteIpAddress?.ToString();
        return Ok(await _roleAcceptanceService.AcceptAsync(request, ip, cancellationToken));
    }

    [HttpPost("role-acceptance/decline")]
    [AllowAnonymous]
    public async Task<IActionResult> DeclineInvitation([FromBody] RoleDeclineRequest request, CancellationToken cancellationToken)
    {
        await _roleAcceptanceService.DeclineAsync(request, cancellationToken);
        return Ok(new { message = "Invitation declined." });
    }
}
