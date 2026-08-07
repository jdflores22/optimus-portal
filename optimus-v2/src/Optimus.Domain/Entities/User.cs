using Optimus.Domain.Common;
using Optimus.Domain.Enums;

namespace Optimus.Domain.Entities;

public class User : BaseEntity
{
    public string Email { get; set; } = string.Empty;
    public string PasswordHash { get; set; } = string.Empty;
    public string FirstName { get; set; } = string.Empty;
    public string LastName { get; set; } = string.Empty;
    public string Role { get; set; } = string.Empty;
    public UserType UserType { get; set; } = UserType.Staff;
    public AccountStatus Status { get; set; } = AccountStatus.Approved;
    public bool IsActive { get; set; } = true;
    public bool EmailVerified { get; set; }
    public string? EmailVerificationToken { get; set; }
    public DateTime? EmailVerificationExpiresAt { get; set; }
    public DateTime? EmailVerifiedAt { get; set; }
    public string? PasswordResetOtpHash { get; set; }
    public DateTime? PasswordResetOtpExpiresAt { get; set; }
    public int FailedLoginAttempts { get; set; }
    public DateTime? LockoutEnd { get; set; }
    public DateTime? LastLoginAt { get; set; }
    public DateTime? DeactivatedAt { get; set; }
    public string? DeactivationReason { get; set; }

    public Guid? ManagedShippingLineId { get; set; }
    public ShippingLine? ManagedShippingLine { get; set; }
    public Guid? ShippingLineAdminId { get; set; }
    public User? ShippingLineAdmin { get; set; }

    public ICollection<RefreshToken> RefreshTokens { get; set; } = new List<RefreshToken>();
    public UserShippingLinePreference? ShippingLinePreference { get; set; }

    public string FullName => $"{FirstName} {LastName}".Trim();
}

public class Broker : User
{
    public string? BusinessAddress { get; set; }
    public Guid? ActiveWorkspaceConsigneeId { get; set; }
    public Consignee? ActiveWorkspaceConsignee { get; set; }
}

public class Consignee : User
{
    public string BusinessName { get; set; } = string.Empty;
    public string? OnboardingCompletedStepsJson { get; set; }
}

public class StaffUser : User
{
    public string? Department { get; set; }
}

public class TerminalTeamUser : User
{
    public string? Department { get; set; }
    public string? TerminalPermissionsJson { get; set; }
}

public class Trucker : User
{
    public string? PhoneNumber { get; set; }
    public string? LicenseNumber { get; set; }
    public string? CompanyName { get; set; }
    public string? TruckPlateNumber { get; set; }
    public string? ApiTokenHash { get; set; }
    public DateTime? ApiTokenExpiresAt { get; set; }
    public DateTime? LastActivityAt { get; set; }
}
