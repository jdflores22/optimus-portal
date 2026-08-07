using System.Security.Claims;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;
using Optimus.Application.Auth.Dtos;
using Optimus.Infrastructure.Auth;
using Optimus.Infrastructure.Persistence;

namespace Optimus.Api.Controllers;

[ApiController]
[Route("api/hello")]
[Authorize]
public class HelloController : ControllerBase
{
    private readonly OptimusDbContext _db;

    public HelloController(OptimusDbContext db)
    {
        _db = db;
    }

    [HttpGet]
    public async Task<ActionResult<HelloResponse>> Get(CancellationToken cancellationToken)
    {
        var userIdValue = User.FindFirstValue(ClaimTypes.NameIdentifier)
                          ?? User.FindFirstValue("sub");

        if (!Guid.TryParse(userIdValue, out var userId))
        {
            return Unauthorized();
        }

        var user = await _db.Users.AsNoTracking().FirstOrDefaultAsync(x => x.Id == userId, cancellationToken);
        if (user is null)
        {
            return Unauthorized();
        }

        return Ok(new HelloResponse(
            $"Welcome to Optimus V2, {user.FullName}. Foundation is online.",
            AuthService.MapUser(user),
            DateTime.UtcNow));
    }
}
