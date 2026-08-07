namespace Optimus.Application.Auth.Dtos;

public record LoginRequest(string Email, string Password);

public record AuthResponse(
    string AccessToken,
    string RefreshToken,
    DateTime AccessTokenExpiresAt,
    UserDto User);

public record RefreshTokenRequest(string RefreshToken);

public record UserDto(
    Guid Id,
    string Email,
    string FirstName,
    string LastName,
    string FullName,
    string Role,
    string Status,
    string UserType,
    Guid? ManagedShippingLineId,
    Guid? ActiveShippingLineId,
    Guid? ActiveWorkspaceConsigneeId,
    string? BusinessName);

public record HelloResponse(string Message, UserDto User, DateTime ServerTimeUtc);

public record RegisterBrokerRequest(
    string Email,
    string Password,
    string FirstName,
    string LastName,
    string? BusinessAddress,
    string? ReferralCode);

public record RegisterConsigneeRequest(
    string Email,
    string Password,
    string FirstName,
    string LastName,
    string BusinessName);

public record RequestPasswordResetRequest(string Email);
public record VerifyOtpResetRequest(string Email, string Otp, string NewPassword);
public record VerifyEmailRequest(string Token);

public record RoleAcceptanceRequest(string Token, string Password);
public record RoleDeclineRequest(string Token);

public record InviteUserRequest(
    string Email,
    string FirstName,
    string LastName,
    string Role,
    Guid? ShippingLineId,
    Guid? ShippingLineAdminId);

public record PendingUserDto(
    Guid Id,
    string Email,
    string FirstName,
    string LastName,
    string Role,
    string Status,
    DateTime TokenExpiresAt,
    Guid? ShippingLineId,
    string AcceptanceToken);

public record ShippingLineDto(
    Guid Id,
    string BrandName,
    string? LogoPath,
    string? BrandColor,
    bool IsActive,
    Guid? AssignedAdminUserId);

public record CreateShippingLineRequest(
    string BrandName,
    string? BrandColor,
    Guid? AssignedAdminUserId);

public record UpdateShippingLineRequest(
    string BrandName,
    string? BrandColor,
    bool IsActive,
    Guid? AssignedAdminUserId);

public record SwitchShippingLineRequest(Guid ShippingLineId);
public record SwitchWorkspaceRequest(Guid ConsigneeId);

public record WorkspaceDto(
    Guid Id,
    string Email,
    string FullName,
    string? BusinessName,
    int ManifestCount,
    string Source);

public record RolePermissionDto(string Role, string PermissionKey, bool IsAllowed);
public record UpsertRolePermissionsRequest(Guid ShippingLineId, IReadOnlyList<RolePermissionDto> Permissions);
