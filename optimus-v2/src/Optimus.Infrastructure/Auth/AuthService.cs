using System.Security.Cryptography;
using System.Text;
using FluentValidation;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Optimus.Application.Auth.Dtos;
using Optimus.Application.Auth.Interfaces;
using Optimus.Domain.Entities;
using Optimus.Domain.Enums;
using Optimus.Infrastructure.Persistence;
using Optimus.Infrastructure.Shipping;
using Optimus.Shared.Constants;

namespace Optimus.Infrastructure.Auth;

public class AuthService : IAuthService
{
    private readonly OptimusDbContext _db;
    private readonly IJwtTokenService _jwt;
    private readonly IPasswordHasher _passwordHasher;
    private readonly IValidator<LoginRequest> _loginValidator;
    private readonly IEmailSender _emailSender;
    private readonly JwtSettings _jwtSettings;
    private readonly ILogger<AuthService> _logger;

    public AuthService(
        OptimusDbContext db,
        IJwtTokenService jwt,
        IPasswordHasher passwordHasher,
        IValidator<LoginRequest> loginValidator,
        IEmailSender emailSender,
        IOptions<JwtSettings> jwtSettings,
        ILogger<AuthService> logger)
    {
        _db = db;
        _jwt = jwt;
        _passwordHasher = passwordHasher;
        _loginValidator = loginValidator;
        _emailSender = emailSender;
        _jwtSettings = jwtSettings.Value;
        _logger = logger;
    }

    public async Task<AuthResponse> LoginAsync(LoginRequest request, string? ipAddress, CancellationToken cancellationToken = default)
    {
        await _loginValidator.ValidateAndThrowAsync(request, cancellationToken);

        var email = request.Email.Trim().ToLowerInvariant();
        var user = await _db.Users
                       .Include(x => x.ShippingLinePreference)
                       .FirstOrDefaultAsync(x => x.Email == email, cancellationToken)
                   ?? throw new UnauthorizedAccessException("Invalid email or password.");

        if (!user.IsActive || user.Status == AccountStatus.Locked || (user.LockoutEnd.HasValue && user.LockoutEnd > DateTime.UtcNow))
        {
            throw new UnauthorizedAccessException("Account is locked or inactive.");
        }

        if (user.Status is AccountStatus.Denied or AccountStatus.Pending)
        {
            throw new UnauthorizedAccessException($"Account status does not allow login: {user.Status}.");
        }

        if (user.Status == AccountStatus.EmailUnverified || !user.EmailVerified)
        {
            throw new UnauthorizedAccessException("Please verify your email before logging in.");
        }

        if (!_passwordHasher.Verify(request.Password, user.PasswordHash))
        {
            user.FailedLoginAttempts += 1;
            if (user.FailedLoginAttempts >= 5)
            {
                user.Status = AccountStatus.Locked;
                user.LockoutEnd = DateTime.UtcNow.AddMinutes(15);
            }

            await _db.SaveChangesAsync(cancellationToken);
            throw new UnauthorizedAccessException("Invalid email or password.");
        }

        user.FailedLoginAttempts = 0;
        user.LockoutEnd = null;
        user.Status = AccountStatus.Approved;
        user.LastLoginAt = DateTime.UtcNow;

        return await IssueTokensAsync(user, ipAddress, cancellationToken);
    }

    public async Task<AuthResponse> RefreshAsync(RefreshTokenRequest request, string? ipAddress, CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(request.RefreshToken))
        {
            throw new UnauthorizedAccessException("Refresh token is required.");
        }

        var hash = _jwt.HashToken(request.RefreshToken);
        var existing = await _db.RefreshTokens
            .Include(x => x.User).ThenInclude(u => u.ShippingLinePreference)
            .FirstOrDefaultAsync(x => x.TokenHash == hash, cancellationToken)
            ?? throw new UnauthorizedAccessException("Invalid refresh token.");

        if (!existing.IsActive)
        {
            // Refresh-token reuse: revoke entire active family for this user.
            var family = await _db.RefreshTokens
                .Where(x => x.UserId == existing.UserId && x.RevokedAt == null)
                .ToListAsync(cancellationToken);
            foreach (var t in family)
            {
                t.RevokedAt = DateTime.UtcNow;
            }

            await _db.SaveChangesAsync(cancellationToken);
            throw new UnauthorizedAccessException("Refresh token reuse detected. All sessions revoked.");
        }

        existing.RevokedAt = DateTime.UtcNow;
        var response = await IssueTokensAsync(existing.User, ipAddress, cancellationToken);
        existing.ReplacedByTokenHash = _jwt.HashToken(response.RefreshToken);
        await _db.SaveChangesAsync(cancellationToken);
        return response;
    }

    public async Task LogoutAsync(string refreshToken, CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(refreshToken))
        {
            return;
        }

        var hash = _jwt.HashToken(refreshToken);
        var existing = await _db.RefreshTokens.FirstOrDefaultAsync(x => x.TokenHash == hash, cancellationToken);
        if (existing is null || existing.RevokedAt is not null)
        {
            return;
        }

        existing.RevokedAt = DateTime.UtcNow;
        await _db.SaveChangesAsync(cancellationToken);
    }

    public async Task RegisterBrokerAsync(RegisterBrokerRequest request, CancellationToken cancellationToken = default)
    {
        await EnsureEmailAvailableAsync(request.Email, cancellationToken);

        var broker = new Broker
        {
            Email = request.Email.Trim().ToLowerInvariant(),
            PasswordHash = _passwordHasher.Hash(request.Password),
            FirstName = request.FirstName.Trim(),
            LastName = request.LastName.Trim(),
            BusinessAddress = request.BusinessAddress,
            Role = AppRoles.Broker,
            UserType = UserType.Broker,
            Status = AccountStatus.EmailUnverified,
            EmailVerified = false
        };

        SetEmailVerification(broker);
        _db.Brokers.Add(broker);

        if (!string.IsNullOrWhiteSpace(request.ReferralCode))
        {
            var code = await _db.ReferralCodes
                .Include(x => x.Consignee)
                .FirstOrDefaultAsync(x => x.Code == request.ReferralCode.Trim().ToUpperInvariant() && x.IsActive, cancellationToken)
                ?? throw new InvalidOperationException("Invalid referral code.");

            if (code.ExpiresAt.HasValue && code.ExpiresAt < DateTime.UtcNow)
            {
                throw new InvalidOperationException("Referral code expired.");
            }

            if (code.MaxUses.HasValue && code.CurrentUses >= code.MaxUses)
            {
                throw new InvalidOperationException("Referral code usage limit reached.");
            }

            code.CurrentUses += 1;
            _db.ConsigneeBrokerRelationships.Add(new ConsigneeBrokerRelationship
            {
                ConsigneeId = code.ConsigneeId,
                Broker = broker,
                ReferralCodeId = code.Id,
                Status = RelationshipStatus.Active
            });
        }

        await _db.SaveChangesAsync(cancellationToken);
        await _emailSender.SendAsync(broker.Email, "Verify your Optimus V2 email",
            $"Your verification token: {broker.EmailVerificationToken}", cancellationToken);
    }

    public async Task RegisterConsigneeAsync(RegisterConsigneeRequest request, CancellationToken cancellationToken = default)
    {
        await EnsureEmailAvailableAsync(request.Email, cancellationToken);

        var consignee = new Consignee
        {
            Email = request.Email.Trim().ToLowerInvariant(),
            PasswordHash = _passwordHasher.Hash(request.Password),
            FirstName = request.FirstName.Trim(),
            LastName = request.LastName.Trim(),
            BusinessName = request.BusinessName.Trim(),
            Role = AppRoles.Consignee,
            UserType = UserType.Consignee,
            Status = AccountStatus.EmailUnverified,
            EmailVerified = false
        };

        SetEmailVerification(consignee);
        _db.Consignees.Add(consignee);
        await _db.SaveChangesAsync(cancellationToken);

        await _emailSender.SendAsync(consignee.Email, "Verify your Optimus V2 email",
            $"Your verification token: {consignee.EmailVerificationToken}", cancellationToken);
    }

    public async Task RequestPasswordResetAsync(RequestPasswordResetRequest request, CancellationToken cancellationToken = default)
    {
        var email = request.Email.Trim().ToLowerInvariant();
        var user = await _db.Users.FirstOrDefaultAsync(x => x.Email == email, cancellationToken);
        if (user is null)
        {
            return;
        }

        var otp = RandomNumberGenerator.GetInt32(100000, 999999).ToString();
        user.PasswordResetOtpHash = _jwt.HashToken(otp);
        user.PasswordResetOtpExpiresAt = DateTime.UtcNow.AddMinutes(15);
        await _db.SaveChangesAsync(cancellationToken);

        await _emailSender.SendAsync(user.Email, "Optimus V2 password reset OTP",
            $"Your OTP is {otp}. It expires in 15 minutes.", cancellationToken);
        _logger.LogInformation("Password reset OTP generated for {Email}", user.Email);
    }

    public async Task ResetPasswordWithOtpAsync(VerifyOtpResetRequest request, CancellationToken cancellationToken = default)
    {
        var email = request.Email.Trim().ToLowerInvariant();
        var user = await _db.Users.FirstOrDefaultAsync(x => x.Email == email, cancellationToken)
                   ?? throw new InvalidOperationException("Invalid OTP or email.");

        if (user.PasswordResetOtpExpiresAt is null || user.PasswordResetOtpExpiresAt < DateTime.UtcNow)
        {
            throw new InvalidOperationException("OTP expired.");
        }

        if (user.PasswordResetOtpHash != _jwt.HashToken(request.Otp))
        {
            throw new InvalidOperationException("Invalid OTP or email.");
        }

        user.PasswordHash = _passwordHasher.Hash(request.NewPassword);
        user.PasswordResetOtpHash = null;
        user.PasswordResetOtpExpiresAt = null;
        user.FailedLoginAttempts = 0;
        user.LockoutEnd = null;
        if (user.Status == AccountStatus.Locked)
        {
            user.Status = AccountStatus.Approved;
        }

        await _db.SaveChangesAsync(cancellationToken);
    }

    public async Task VerifyEmailAsync(VerifyEmailRequest request, CancellationToken cancellationToken = default)
    {
        var user = await _db.Users.FirstOrDefaultAsync(x => x.EmailVerificationToken == request.Token, cancellationToken)
                   ?? throw new InvalidOperationException("Invalid verification token.");

        if (user.EmailVerificationExpiresAt is null || user.EmailVerificationExpiresAt < DateTime.UtcNow)
        {
            throw new InvalidOperationException("Verification token expired.");
        }

        user.EmailVerified = true;
        user.EmailVerifiedAt = DateTime.UtcNow;
        user.EmailVerificationToken = null;
        user.EmailVerificationExpiresAt = null;
        user.Status = AccountStatus.Approved;
        await _db.SaveChangesAsync(cancellationToken);
    }

    public async Task<AuthResponse> IssueTokensAsync(User user, string? ipAddress, CancellationToken cancellationToken)
    {
        Guid? workspaceId = null;
        if (user is Broker broker)
        {
            workspaceId = broker.ActiveWorkspaceConsigneeId;
        }
        else
        {
            var trackedBroker = await _db.Brokers.AsNoTracking().FirstOrDefaultAsync(x => x.Id == user.Id, cancellationToken);
            workspaceId = trackedBroker?.ActiveWorkspaceConsigneeId;
        }

        var activeShippingLineId = user.ShippingLinePreference?.LastSelectedShippingLineId
                                   ?? user.ManagedShippingLineId
                                   ?? await SoleShippingLine.RequireIdAsync(_db, cancellationToken);

        var pref = await _db.UserShippingLinePreferences.FirstOrDefaultAsync(x => x.UserId == user.Id, cancellationToken);
        if (pref is null)
        {
            _db.UserShippingLinePreferences.Add(new UserShippingLinePreference
            {
                UserId = user.Id,
                LastSelectedShippingLineId = activeShippingLineId
            });
        }
        else if (pref.LastSelectedShippingLineId != activeShippingLineId)
        {
            pref.LastSelectedShippingLineId = activeShippingLineId;
        }

        var (accessToken, expiresAt) = _jwt.CreateAccessToken(user, activeShippingLineId, workspaceId);
        var refreshToken = _jwt.CreateRefreshToken();

        _db.RefreshTokens.Add(new RefreshToken
        {
            UserId = user.Id,
            TokenHash = _jwt.HashToken(refreshToken),
            ExpiresAt = DateTime.UtcNow.AddDays(_jwtSettings.RefreshTokenDays),
            CreatedByIp = ipAddress
        });

        await _db.SaveChangesAsync(cancellationToken);

        return new AuthResponse(
            accessToken,
            refreshToken,
            expiresAt,
            MapUser(user, activeShippingLineId, workspaceId));
    }

    public static UserDto MapUser(User user, Guid? activeShippingLineId = null, Guid? workspaceId = null)
    {
        string? businessName = user is Consignee c ? c.BusinessName : null;
        return new UserDto(
            user.Id,
            user.Email,
            user.FirstName,
            user.LastName,
            user.FullName,
            user.Role,
            user.Status.ToString(),
            user.UserType.ToString(),
            user.ManagedShippingLineId,
            activeShippingLineId ?? user.ShippingLinePreference?.LastSelectedShippingLineId ?? user.ManagedShippingLineId,
            workspaceId,
            businessName);
    }

    private async Task EnsureEmailAvailableAsync(string email, CancellationToken cancellationToken)
    {
        var normalized = email.Trim().ToLowerInvariant();
        if (await _db.Users.AnyAsync(x => x.Email == normalized, cancellationToken))
        {
            throw new InvalidOperationException("Email is already registered.");
        }
    }

    private static void SetEmailVerification(User user)
    {
        user.EmailVerificationToken = Convert.ToHexString(RandomNumberGenerator.GetBytes(32));
        user.EmailVerificationExpiresAt = DateTime.UtcNow.AddDays(2);
    }
}

public class JwtTokenService : IJwtTokenService
{
    private readonly JwtSettings _settings;

    public JwtTokenService(IOptions<JwtSettings> settings)
    {
        _settings = settings.Value;
    }

    public (string Token, DateTime ExpiresAt) CreateAccessToken(User user, Guid? activeShippingLineId = null, Guid? workspaceConsigneeId = null)
    {
        var expiresAt = DateTime.UtcNow.AddMinutes(_settings.AccessTokenMinutes);
        var claims = new List<System.Security.Claims.Claim>
        {
            new(System.IdentityModel.Tokens.Jwt.JwtRegisteredClaimNames.Sub, user.Id.ToString()),
            new(System.IdentityModel.Tokens.Jwt.JwtRegisteredClaimNames.Email, user.Email),
            new(System.Security.Claims.ClaimTypes.NameIdentifier, user.Id.ToString()),
            new(System.Security.Claims.ClaimTypes.Email, user.Email),
            new(System.Security.Claims.ClaimTypes.Role, user.Role),
            new("name", user.FullName),
            new("user_type", user.UserType.ToString())
        };

        if (activeShippingLineId.HasValue)
        {
            claims.Add(new("shipping_line_id", activeShippingLineId.Value.ToString()));
        }

        if (workspaceConsigneeId.HasValue)
        {
            claims.Add(new("workspace_consignee_id", workspaceConsigneeId.Value.ToString()));
        }

        var key = new Microsoft.IdentityModel.Tokens.SymmetricSecurityKey(Encoding.UTF8.GetBytes(_settings.Key));
        var credentials = new Microsoft.IdentityModel.Tokens.SigningCredentials(key, Microsoft.IdentityModel.Tokens.SecurityAlgorithms.HmacSha256);
        var token = new System.IdentityModel.Tokens.Jwt.JwtSecurityToken(
            issuer: _settings.Issuer,
            audience: _settings.Audience,
            claims: claims,
            expires: expiresAt,
            signingCredentials: credentials);

        return (new System.IdentityModel.Tokens.Jwt.JwtSecurityTokenHandler().WriteToken(token), expiresAt);
    }

    public string CreateRefreshToken()
    {
        var bytes = RandomNumberGenerator.GetBytes(64);
        return Convert.ToBase64String(bytes);
    }

    public string HashToken(string token)
    {
        var hash = SHA256.HashData(Encoding.UTF8.GetBytes(token));
        return Convert.ToHexString(hash);
    }
}

public class BcryptPasswordHasher : IPasswordHasher
{
    public string Hash(string password) => BCrypt.Net.BCrypt.HashPassword(password);

    public bool Verify(string password, string passwordHash) =>
        BCrypt.Net.BCrypt.Verify(password, passwordHash);
}

public class LoggingEmailSender : IEmailSender
{
    private readonly ILogger<LoggingEmailSender> _logger;

    public LoggingEmailSender(ILogger<LoggingEmailSender> logger)
    {
        _logger = logger;
    }

    public Task SendAsync(string toEmail, string subject, string body, CancellationToken cancellationToken = default)
    {
        _logger.LogInformation("EMAIL to={To} subject={Subject} body={Body}", toEmail, subject, body);
        return Task.CompletedTask;
    }
}
