using Optimus.Domain.Common;
using Optimus.Domain.Enums;

namespace Optimus.Domain.Entities;

public class ShippingLine : BaseEntity
{
    public string BrandName { get; set; } = string.Empty;
    public string? LogoPath { get; set; }
    public string? BrandColor { get; set; }
    public string? PortalConfigJson { get; set; }
    public bool IsActive { get; set; } = true;
    public Guid? AssignedAdminUserId { get; set; }
    public User? AssignedAdminUser { get; set; }

    public ICollection<ShippingLineConfiguration> Configurations { get; set; } = new List<ShippingLineConfiguration>();
    public ICollection<RolePermissionConfiguration> RolePermissions { get; set; } = new List<RolePermissionConfiguration>();
}

public class ShippingLineConfiguration : BaseEntity
{
    public Guid ShippingLineId { get; set; }
    public ShippingLine ShippingLine { get; set; } = null!;
    public string Key { get; set; } = string.Empty;
    public string? Value { get; set; }
}

public class RolePermissionConfiguration : BaseEntity
{
    public Guid ShippingLineId { get; set; }
    public ShippingLine ShippingLine { get; set; } = null!;
    public string Role { get; set; } = string.Empty;
    public string PermissionKey { get; set; } = string.Empty;
    public bool IsAllowed { get; set; } = true;
}

public class PendingUser : BaseEntity
{
    public string Email { get; set; } = string.Empty;
    public string FirstName { get; set; } = string.Empty;
    public string LastName { get; set; } = string.Empty;
    public string Role { get; set; } = string.Empty;
    public string AcceptanceToken { get; set; } = string.Empty;
    public DateTime TokenExpiresAt { get; set; }
    public PendingUserStatus Status { get; set; } = PendingUserStatus.Pending;
    public DateTime? DisabledUntil { get; set; }
    public Guid CreatedByAdminId { get; set; }
    public User CreatedByAdmin { get; set; } = null!;
    public Guid? ShippingLineId { get; set; }
    public ShippingLine? ShippingLine { get; set; }
    public Guid? ShippingLineAdminId { get; set; }
    public User? ShippingLineAdmin { get; set; }
}

public class ConsigneeBrokerRelationship : BaseEntity
{
    public Guid ConsigneeId { get; set; }
    public Consignee Consignee { get; set; } = null!;
    public Guid BrokerId { get; set; }
    public Broker Broker { get; set; } = null!;
    public Guid ReferralCodeId { get; set; }
    public ReferralCode ReferralCode { get; set; } = null!;
    public RelationshipStatus Status { get; set; } = RelationshipStatus.Active;
    public DateTime? SuspendedAt { get; set; }
    public string? SuspensionReason { get; set; }
}

public class ReferralCode : BaseEntity
{
    public Guid ConsigneeId { get; set; }
    public Consignee Consignee { get; set; } = null!;
    public string Code { get; set; } = string.Empty;
    public bool IsActive { get; set; } = true;
    public int? MaxUses { get; set; }
    public int CurrentUses { get; set; }
    public DateTime? ExpiresAt { get; set; }
    public Guid CreatedByUserId { get; set; }
    public DateTime? DeactivatedAt { get; set; }
}

public class UserShippingLinePreference : BaseEntity
{
    public Guid UserId { get; set; }
    public User User { get; set; } = null!;
    public Guid? LastSelectedShippingLineId { get; set; }
    public ShippingLine? LastSelectedShippingLine { get; set; }
}
