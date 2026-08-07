using Optimus.Application.Auth.Dtos;
using Optimus.Domain.Entities;

namespace Optimus.Application.Auth.Interfaces;

public interface IAuthService
{
    Task<AuthResponse> LoginAsync(LoginRequest request, string? ipAddress, CancellationToken cancellationToken = default);
    Task<AuthResponse> RefreshAsync(RefreshTokenRequest request, string? ipAddress, CancellationToken cancellationToken = default);
    Task LogoutAsync(string refreshToken, CancellationToken cancellationToken = default);
    Task RegisterBrokerAsync(RegisterBrokerRequest request, CancellationToken cancellationToken = default);
    Task RegisterConsigneeAsync(RegisterConsigneeRequest request, CancellationToken cancellationToken = default);
    Task RequestPasswordResetAsync(RequestPasswordResetRequest request, CancellationToken cancellationToken = default);
    Task ResetPasswordWithOtpAsync(VerifyOtpResetRequest request, CancellationToken cancellationToken = default);
    Task VerifyEmailAsync(VerifyEmailRequest request, CancellationToken cancellationToken = default);
    Task<AuthResponse> IssueTokensAsync(User user, string? ipAddress, CancellationToken cancellationToken = default);
}

public interface IJwtTokenService
{
    (string Token, DateTime ExpiresAt) CreateAccessToken(User user, Guid? activeShippingLineId = null, Guid? workspaceConsigneeId = null);
    string CreateRefreshToken();
    string HashToken(string token);
}

public interface IPasswordHasher
{
    string Hash(string password);
    bool Verify(string password, string passwordHash);
}

public interface IEmailSender
{
    Task SendAsync(string toEmail, string subject, string body, CancellationToken cancellationToken = default);
}

public interface IRoleAcceptanceService
{
    Task<PendingUserDto> InviteAsync(InviteUserRequest request, Guid createdByAdminId, CancellationToken cancellationToken = default);
    Task<PendingUserDto?> GetByTokenAsync(string token, CancellationToken cancellationToken = default);
    Task<AuthResponse> AcceptAsync(RoleAcceptanceRequest request, string? ipAddress, CancellationToken cancellationToken = default);
    Task DeclineAsync(RoleDeclineRequest request, CancellationToken cancellationToken = default);
    Task<IReadOnlyList<PendingUserDto>> ListPendingAsync(CancellationToken cancellationToken = default);
}

public interface IShippingLineService
{
    Task<IReadOnlyList<ShippingLineDto>> ListAsync(CancellationToken cancellationToken = default);
    Task<ShippingLineDto> GetAsync(Guid id, CancellationToken cancellationToken = default);
    Task<ShippingLineDto> CreateAsync(CreateShippingLineRequest request, CancellationToken cancellationToken = default);
    Task<ShippingLineDto> UpdateAsync(Guid id, UpdateShippingLineRequest request, CancellationToken cancellationToken = default);
    Task SetActiveAsync(Guid id, bool isActive, CancellationToken cancellationToken = default);
    Task AssignAdminAsync(Guid id, Guid adminUserId, CancellationToken cancellationToken = default);
    Task UploadLogoAsync(Guid id, string relativePath, CancellationToken cancellationToken = default);
    Task<AuthResponse> SwitchShippingLineAsync(Guid userId, SwitchShippingLineRequest request, string? ipAddress, CancellationToken cancellationToken = default);
    Task<IReadOnlyList<RolePermissionDto>> GetPermissionsAsync(Guid shippingLineId, CancellationToken cancellationToken = default);
    Task UpsertPermissionsAsync(UpsertRolePermissionsRequest request, CancellationToken cancellationToken = default);
}

public interface IWorkspaceService
{
    Task<AuthResponse> SwitchWorkspaceAsync(Guid brokerUserId, SwitchWorkspaceRequest request, string? ipAddress, CancellationToken cancellationToken = default);
    Task<IReadOnlyList<WorkspaceDto>> ListBrokerWorkspacesAsync(Guid brokerUserId, CancellationToken cancellationToken = default);
}

public interface IHierarchyService
{
    Task<IReadOnlyList<UserDto>> ListUsersAsync(Guid? actorId = null, string? actorRole = null, CancellationToken cancellationToken = default);
    Task UnlockUserAsync(Guid userId, CancellationToken cancellationToken = default);
    Task<byte[]> ExportUsersCsvAsync(CancellationToken cancellationToken = default);
}
