using System.Security.Cryptography;
using System.Text;
using Microsoft.EntityFrameworkCore;
using Optimus.Application.Auth.Dtos;
using Optimus.Application.Auth.Interfaces;
using Optimus.Domain.Entities;
using Optimus.Domain.Enums;
using Optimus.Infrastructure.Auth;
using Optimus.Infrastructure.Persistence;
using Optimus.Infrastructure.Persistence.Configurations;
using Optimus.Shared.Constants;

namespace Optimus.Infrastructure.Identity;

public class RoleAcceptanceService : IRoleAcceptanceService
{
    private readonly OptimusDbContext _db;
    private readonly IPasswordHasher _passwordHasher;
    private readonly IAuthService _authService;
    private readonly IEmailSender _emailSender;

    public RoleAcceptanceService(
        OptimusDbContext db,
        IPasswordHasher passwordHasher,
        IAuthService authService,
        IEmailSender emailSender)
    {
        _db = db;
        _passwordHasher = passwordHasher;
        _authService = authService;
        _emailSender = emailSender;
    }

    public async Task<PendingUserDto> InviteAsync(InviteUserRequest request, Guid createdByAdminId, CancellationToken cancellationToken = default)
    {
        if (!AppRoles.All.Contains(request.Role))
        {
            throw new InvalidOperationException("Invalid role.");
        }

        var email = request.Email.Trim().ToLowerInvariant();
        if (await _db.Users.AnyAsync(x => x.Email == email, cancellationToken))
        {
            throw new InvalidOperationException("A user with this email already exists.");
        }

        var token = Convert.ToHexString(RandomNumberGenerator.GetBytes(32));
        var pending = new PendingUser
        {
            Email = email,
            FirstName = request.FirstName.Trim(),
            LastName = request.LastName.Trim(),
            Role = request.Role,
            AcceptanceToken = token,
            TokenExpiresAt = DateTime.UtcNow.AddDays(7),
            Status = PendingUserStatus.Pending,
            CreatedByAdminId = createdByAdminId,
            ShippingLineId = request.ShippingLineId,
            ShippingLineAdminId = request.ShippingLineAdminId
        };

        _db.PendingUsers.Add(pending);
        await _db.SaveChangesAsync(cancellationToken);

        await _emailSender.SendAsync(email, "Optimus V2 role invitation",
            $"You were invited as {request.Role}. Acceptance token: {token}", cancellationToken);

        return Map(pending);
    }

    public async Task<PendingUserDto?> GetByTokenAsync(string token, CancellationToken cancellationToken = default)
    {
        var pending = await _db.PendingUsers.AsNoTracking()
            .FirstOrDefaultAsync(x => x.AcceptanceToken == token, cancellationToken);
        return pending is null ? null : Map(pending);
    }

    public async Task<AuthResponse> AcceptAsync(RoleAcceptanceRequest request, string? ipAddress, CancellationToken cancellationToken = default)
    {
        var pending = await _db.PendingUsers
            .FirstOrDefaultAsync(x => x.AcceptanceToken == request.Token, cancellationToken)
            ?? throw new InvalidOperationException("Invitation not found.");

        if (pending.Status != PendingUserStatus.Pending)
        {
            throw new InvalidOperationException($"Invitation is {pending.Status}.");
        }

        if (pending.TokenExpiresAt < DateTime.UtcNow)
        {
            pending.Status = PendingUserStatus.Expired;
            await _db.SaveChangesAsync(cancellationToken);
            throw new InvalidOperationException("Invitation expired.");
        }

        User user = pending.Role switch
        {
            AppRoles.Broker => new Broker { UserType = UserType.Broker },
            AppRoles.Consignee => new Consignee { UserType = UserType.Consignee, BusinessName = $"{pending.FirstName} {pending.LastName}" },
            AppRoles.TerminalTeam => new TerminalTeamUser { UserType = UserType.TerminalTeam },
            AppRoles.Trucker => new Trucker { UserType = UserType.Trucker },
            AppRoles.SystemAdmin => new User { UserType = UserType.SystemAdmin },
            _ => new StaffUser { UserType = UserType.Staff }
        };

        user.Email = pending.Email;
        user.FirstName = pending.FirstName;
        user.LastName = pending.LastName;
        user.Role = pending.Role;
        user.PasswordHash = _passwordHasher.Hash(request.Password);
        user.Status = AccountStatus.Approved;
        user.EmailVerified = true;
        user.EmailVerifiedAt = DateTime.UtcNow;
        user.ManagedShippingLineId = pending.Role == AppRoles.ShippingLinesAdmin ? pending.ShippingLineId : null;
        user.ShippingLineAdminId = pending.ShippingLineAdminId;

        _db.Users.Add(user);

        if (pending.ShippingLineId.HasValue)
        {
            _db.UserShippingLinePreferences.Add(new UserShippingLinePreference
            {
                User = user,
                LastSelectedShippingLineId = pending.ShippingLineId
            });

            if (pending.Role == AppRoles.ShippingLinesAdmin)
            {
                var sl = await _db.ShippingLines.FirstOrDefaultAsync(x => x.Id == pending.ShippingLineId, cancellationToken);
                if (sl is not null)
                {
                    sl.AssignedAdminUser = user;
                }
            }
        }

        pending.Status = PendingUserStatus.Accepted;
        await _db.SaveChangesAsync(cancellationToken);

        return await _authService.IssueTokensAsync(user, ipAddress, cancellationToken);
    }

    public async Task DeclineAsync(RoleDeclineRequest request, CancellationToken cancellationToken = default)
    {
        var pending = await _db.PendingUsers
            .FirstOrDefaultAsync(x => x.AcceptanceToken == request.Token, cancellationToken)
            ?? throw new InvalidOperationException("Invitation not found.");

        if (pending.Status != PendingUserStatus.Pending)
        {
            throw new InvalidOperationException($"Invitation is {pending.Status}.");
        }

        pending.Status = PendingUserStatus.Declined;
        await _db.SaveChangesAsync(cancellationToken);
    }

    public async Task<IReadOnlyList<PendingUserDto>> ListPendingAsync(CancellationToken cancellationToken = default)
    {
        var items = await _db.PendingUsers.AsNoTracking()
            .OrderByDescending(x => x.CreatedAt)
            .ToListAsync(cancellationToken);
        return items.Select(Map).ToList();
    }

    private static PendingUserDto Map(PendingUser x) =>
        new(x.Id, x.Email, x.FirstName, x.LastName, x.Role, x.Status.ToString(), x.TokenExpiresAt, x.ShippingLineId, x.AcceptanceToken);
}

public class ShippingLineService : IShippingLineService
{
    private readonly OptimusDbContext _db;
    private readonly IAuthService _authService;
    private static readonly string[] LogoAllowed = { ".png", ".jpg", ".jpeg", ".webp" };

    public ShippingLineService(OptimusDbContext db, IAuthService authService)
    {
        _db = db;
        _authService = authService;
    }

    public async Task<IReadOnlyList<ShippingLineDto>> ListAsync(CancellationToken cancellationToken = default)
    {
        var items = await _db.ShippingLines.AsNoTracking().OrderBy(x => x.BrandName).ToListAsync(cancellationToken);
        return items.Select(Map).ToList();
    }

    public async Task<ShippingLineDto> GetAsync(Guid id, CancellationToken cancellationToken = default)
    {
        var item = await _db.ShippingLines.AsNoTracking().FirstOrDefaultAsync(x => x.Id == id, cancellationToken)
                   ?? throw new KeyNotFoundException("Shipping line not found.");
        return Map(item);
    }

    public async Task<ShippingLineDto> CreateAsync(CreateShippingLineRequest request, CancellationToken cancellationToken = default)
    {
        if (await _db.ShippingLines.AnyAsync(cancellationToken))
        {
            throw new InvalidOperationException(
                "Only one shipping line is supported. Update the existing line instead of creating another.");
        }

        if (await _db.ShippingLines.AnyAsync(x => x.BrandName == request.BrandName.Trim(), cancellationToken))
        {
            throw new InvalidOperationException("Brand name already exists.");
        }

        var entity = new ShippingLine
        {
            BrandName = request.BrandName.Trim(),
            BrandColor = request.BrandColor,
            AssignedAdminUserId = request.AssignedAdminUserId,
            IsActive = true
        };

        _db.ShippingLines.Add(entity);
        await _db.SaveChangesAsync(cancellationToken);

        foreach (var (role, permission, allowed) in DefaultPermissionKeys.DefaultsFor(entity.Id))
        {
            _db.RolePermissionConfigurations.Add(new RolePermissionConfiguration
            {
                ShippingLineId = entity.Id,
                Role = role,
                PermissionKey = permission,
                IsAllowed = allowed
            });
        }

        await _db.SaveChangesAsync(cancellationToken);
        return Map(entity);
    }

    public async Task<ShippingLineDto> UpdateAsync(Guid id, UpdateShippingLineRequest request, CancellationToken cancellationToken = default)
    {
        var entity = await _db.ShippingLines.FirstOrDefaultAsync(x => x.Id == id, cancellationToken)
                     ?? throw new KeyNotFoundException("Shipping line not found.");

        entity.BrandName = request.BrandName.Trim();
        entity.BrandColor = request.BrandColor;
        entity.IsActive = request.IsActive;
        entity.AssignedAdminUserId = request.AssignedAdminUserId;
        await _db.SaveChangesAsync(cancellationToken);
        return Map(entity);
    }

    public async Task SetActiveAsync(Guid id, bool isActive, CancellationToken cancellationToken = default)
    {
        var entity = await _db.ShippingLines.FirstOrDefaultAsync(x => x.Id == id, cancellationToken)
                     ?? throw new KeyNotFoundException("Shipping line not found.");
        entity.IsActive = isActive;
        await _db.SaveChangesAsync(cancellationToken);
    }

    public async Task AssignAdminAsync(Guid id, Guid adminUserId, CancellationToken cancellationToken = default)
    {
        var entity = await _db.ShippingLines.FirstOrDefaultAsync(x => x.Id == id, cancellationToken)
                     ?? throw new KeyNotFoundException("Shipping line not found.");
        var admin = await _db.Users.FirstOrDefaultAsync(x => x.Id == adminUserId, cancellationToken)
                    ?? throw new KeyNotFoundException("Admin user not found.");

        entity.AssignedAdminUserId = admin.Id;
        admin.ManagedShippingLineId = entity.Id;
        admin.Role = AppRoles.ShippingLinesAdmin;
        await _db.SaveChangesAsync(cancellationToken);
    }

    public async Task UploadLogoAsync(Guid id, string relativePath, CancellationToken cancellationToken = default)
    {
        var ext = Path.GetExtension(relativePath).ToLowerInvariant();
        if (!LogoAllowed.Contains(ext))
        {
            throw new InvalidOperationException("Unsupported logo format.");
        }

        var entity = await _db.ShippingLines.FirstOrDefaultAsync(x => x.Id == id, cancellationToken)
                     ?? throw new KeyNotFoundException("Shipping line not found.");
        entity.LogoPath = relativePath;
        await _db.SaveChangesAsync(cancellationToken);
    }

    public async Task<AuthResponse> SwitchShippingLineAsync(Guid userId, SwitchShippingLineRequest request, string? ipAddress, CancellationToken cancellationToken = default)
    {
        var user = await _db.Users.Include(x => x.ShippingLinePreference)
            .FirstOrDefaultAsync(x => x.Id == userId, cancellationToken)
            ?? throw new UnauthorizedAccessException("User not found.");

        var sl = await _db.ShippingLines.AsNoTracking()
            .FirstOrDefaultAsync(x => x.Id == request.ShippingLineId && x.IsActive, cancellationToken)
            ?? throw new InvalidOperationException("Shipping line not found or inactive.");

        if (user.ShippingLinePreference is null)
        {
            user.ShippingLinePreference = new UserShippingLinePreference { UserId = user.Id };
            _db.UserShippingLinePreferences.Add(user.ShippingLinePreference);
        }

        user.ShippingLinePreference.LastSelectedShippingLineId = sl.Id;
        await _db.SaveChangesAsync(cancellationToken);
        return await _authService.IssueTokensAsync(user, ipAddress, cancellationToken);
    }

    public async Task<IReadOnlyList<RolePermissionDto>> GetPermissionsAsync(Guid shippingLineId, CancellationToken cancellationToken = default)
    {
        var items = await _db.RolePermissionConfigurations.AsNoTracking()
            .Where(x => x.ShippingLineId == shippingLineId)
            .OrderBy(x => x.Role).ThenBy(x => x.PermissionKey)
            .ToListAsync(cancellationToken);
        return items.Select(x => new RolePermissionDto(x.Role, x.PermissionKey, x.IsAllowed)).ToList();
    }

    public async Task UpsertPermissionsAsync(UpsertRolePermissionsRequest request, CancellationToken cancellationToken = default)
    {
        var existing = await _db.RolePermissionConfigurations
            .Where(x => x.ShippingLineId == request.ShippingLineId)
            .ToListAsync(cancellationToken);

        foreach (var item in request.Permissions)
        {
            var row = existing.FirstOrDefault(x => x.Role == item.Role && x.PermissionKey == item.PermissionKey);
            if (row is null)
            {
                _db.RolePermissionConfigurations.Add(new RolePermissionConfiguration
                {
                    ShippingLineId = request.ShippingLineId,
                    Role = item.Role,
                    PermissionKey = item.PermissionKey,
                    IsAllowed = item.IsAllowed
                });
            }
            else
            {
                row.IsAllowed = item.IsAllowed;
            }
        }

        await _db.SaveChangesAsync(cancellationToken);
    }

    private static ShippingLineDto Map(ShippingLine x) =>
        new(x.Id, x.BrandName, x.LogoPath, x.BrandColor, x.IsActive, x.AssignedAdminUserId);
}

public class WorkspaceService : IWorkspaceService
{
    private readonly OptimusDbContext _db;
    private readonly IAuthService _authService;

    public WorkspaceService(OptimusDbContext db, IAuthService authService)
    {
        _db = db;
        _authService = authService;
    }

    public async Task<AuthResponse> SwitchWorkspaceAsync(Guid brokerUserId, SwitchWorkspaceRequest request, string? ipAddress, CancellationToken cancellationToken = default)
    {
        var broker = await _db.Brokers
            .Include(x => x.ShippingLinePreference)
            .FirstOrDefaultAsync(x => x.Id == brokerUserId, cancellationToken)
            ?? throw new UnauthorizedAccessException("Broker not found.");

        var linked = await _db.ConsigneeBrokerRelationships.AsNoTracking().AnyAsync(x =>
            x.BrokerId == brokerUserId &&
            x.ConsigneeId == request.ConsigneeId &&
            x.Status == RelationshipStatus.Active, cancellationToken);

        if (!linked)
        {
            throw new InvalidOperationException("Consignee is not linked to this broker.");
        }

        broker.ActiveWorkspaceConsigneeId = request.ConsigneeId;
        await _db.SaveChangesAsync(cancellationToken);
        return await _authService.IssueTokensAsync(broker, ipAddress, cancellationToken);
    }

    public async Task<IReadOnlyList<WorkspaceDto>> ListBrokerWorkspacesAsync(Guid brokerUserId, CancellationToken cancellationToken = default)
    {
        var links = await _db.ConsigneeBrokerRelationships.AsNoTracking()
            .Where(x => x.BrokerId == brokerUserId && x.Status == RelationshipStatus.Active)
            .Select(x => new { x.ConsigneeId, x.ReferralCodeId })
            .ToListAsync(cancellationToken);

        if (links.Count == 0)
        {
            return Array.Empty<WorkspaceDto>();
        }

        var consigneeIds = links.Select(x => x.ConsigneeId).Distinct().ToList();

        var consignees = await _db.Consignees.AsNoTracking()
            .Where(x => consigneeIds.Contains(x.Id))
            .ToListAsync(cancellationToken);

        var manifestCounts = await _db.Manifests.AsNoTracking()
            .Where(x => x.ConsigneeId != null && consigneeIds.Contains(x.ConsigneeId.Value))
            .GroupBy(x => x.ConsigneeId!.Value)
            .Select(g => new { ConsigneeId = g.Key, Count = g.Count() })
            .ToDictionaryAsync(x => x.ConsigneeId, x => x.Count, cancellationToken);

        return consignees
            .OrderBy(c => c.BusinessName ?? c.FullName)
            .Select(c =>
            {
                var link = links.First(l => l.ConsigneeId == c.Id);
                var source = link.ReferralCodeId != Guid.Empty ? "Referral" : "Legacy";
                manifestCounts.TryGetValue(c.Id, out var count);
                return new WorkspaceDto(
                    c.Id,
                    c.Email,
                    c.FullName,
                    c.BusinessName,
                    count,
                    source);
            })
            .ToList();
    }
}

public class HierarchyService : IHierarchyService
{
    private static readonly string[] TeamRoles =
    {
        AppRoles.ShippingLinesAdmin,
        AppRoles.SlStaff,
        AppRoles.Evaluator,
        AppRoles.Accounting,
        AppRoles.TerminalTeam,
    };

    private readonly OptimusDbContext _db;

    public HierarchyService(OptimusDbContext db)
    {
        _db = db;
    }

    public async Task<IReadOnlyList<UserDto>> ListUsersAsync(Guid? actorId = null, string? actorRole = null, CancellationToken cancellationToken = default)
    {
        var query = _db.Users.AsNoTracking()
            .Include(x => x.ShippingLinePreference)
            .AsQueryable();

        if (actorRole == AppRoles.ShippingLinesAdmin && actorId.HasValue)
        {
            query = query.Where(u =>
                TeamRoles.Contains(u.Role)
                && (u.Id == actorId || u.ShippingLineAdminId == actorId));
        }
        else if (actorRole is AppRoles.SlStaff or AppRoles.Evaluator or AppRoles.Accounting)
        {
            query = query.Where(u => TeamRoles.Contains(u.Role));
        }

        var users = await query
            .OrderBy(x => x.Role).ThenBy(x => x.Email)
            .ToListAsync(cancellationToken);
        return users.Select(u => Auth.AuthService.MapUser(u)).ToList();
    }

    public async Task UnlockUserAsync(Guid userId, CancellationToken cancellationToken = default)
    {
        var user = await _db.Users.FirstOrDefaultAsync(x => x.Id == userId, cancellationToken)
                   ?? throw new KeyNotFoundException("User not found.");
        user.Status = AccountStatus.Approved;
        user.LockoutEnd = null;
        user.FailedLoginAttempts = 0;
        user.IsActive = true;
        await _db.SaveChangesAsync(cancellationToken);
    }

    public async Task<byte[]> ExportUsersCsvAsync(CancellationToken cancellationToken = default)
    {
        var users = await ListUsersAsync(cancellationToken: cancellationToken);
        var sb = new StringBuilder();
        sb.AppendLine("Id,Email,FullName,Role,Status,UserType,ManagedShippingLineId");
        foreach (var u in users)
        {
            sb.AppendLine($"{u.Id},{u.Email},\"{u.FullName}\",{u.Role},{u.Status},{u.UserType},{u.ManagedShippingLineId}");
        }

        return Encoding.UTF8.GetBytes(sb.ToString());
    }
}
