using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using Optimus.Domain.Entities;
using Optimus.Shared.Constants;

namespace Optimus.Infrastructure.Persistence.Configurations;

public class UserConfiguration : IEntityTypeConfiguration<User>
{
    public void Configure(EntityTypeBuilder<User> builder)
    {
        builder.ToTable("users");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Email).HasMaxLength(180).IsRequired();
        builder.HasIndex(x => x.Email).IsUnique();
        builder.Property(x => x.PasswordHash).HasMaxLength(255).IsRequired();
        builder.Property(x => x.FirstName).HasMaxLength(100).IsRequired();
        builder.Property(x => x.LastName).HasMaxLength(100).IsRequired();
        builder.Property(x => x.Role).HasMaxLength(50).IsRequired();
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(30);
        builder.Property(x => x.UserType).HasConversion<string>().HasMaxLength(30);
        builder.Property(x => x.EmailVerificationToken).HasMaxLength(128);
        builder.Property(x => x.PasswordResetOtpHash).HasMaxLength(128);
        builder.Property(x => x.DeactivationReason).HasMaxLength(500);

        builder.HasDiscriminator<string>("type")
            .HasValue<User>("base")
            .HasValue<Broker>("broker")
            .HasValue<Consignee>("consignee")
            .HasValue<StaffUser>("staff")
            .HasValue<TerminalTeamUser>("terminal_team")
            .HasValue<Trucker>("trucker");

        builder.HasOne(x => x.ManagedShippingLine)
            .WithMany()
            .HasForeignKey(x => x.ManagedShippingLineId)
            .OnDelete(DeleteBehavior.SetNull);

        builder.HasOne(x => x.ShippingLineAdmin)
            .WithMany()
            .HasForeignKey(x => x.ShippingLineAdminId)
            .OnDelete(DeleteBehavior.Restrict);
    }
}

public class BrokerConfiguration : IEntityTypeConfiguration<Broker>
{
    public void Configure(EntityTypeBuilder<Broker> builder)
    {
        builder.Property(x => x.BusinessAddress).HasMaxLength(500);
        builder.HasOne(x => x.ActiveWorkspaceConsignee)
            .WithMany()
            .HasForeignKey(x => x.ActiveWorkspaceConsigneeId)
            .OnDelete(DeleteBehavior.SetNull);
    }
}

public class ConsigneeConfiguration : IEntityTypeConfiguration<Consignee>
{
    public void Configure(EntityTypeBuilder<Consignee> builder)
    {
        builder.Property(x => x.BusinessName).HasMaxLength(255).IsRequired();
        builder.Property(x => x.OnboardingCompletedStepsJson).HasColumnType("longtext");
    }
}

public class StaffUserConfiguration : IEntityTypeConfiguration<StaffUser>
{
    public void Configure(EntityTypeBuilder<StaffUser> builder)
    {
        builder.Property(x => x.Department).HasMaxLength(120);
    }
}

public class TerminalTeamUserConfiguration : IEntityTypeConfiguration<TerminalTeamUser>
{
    public void Configure(EntityTypeBuilder<TerminalTeamUser> builder)
    {
        builder.Property(x => x.Department).HasMaxLength(120);
        builder.Property(x => x.TerminalPermissionsJson).HasColumnType("json");
    }
}

public class TruckerConfiguration : IEntityTypeConfiguration<Trucker>
{
    public void Configure(EntityTypeBuilder<Trucker> builder)
    {
        builder.Property(x => x.PhoneNumber).HasMaxLength(40);
        builder.Property(x => x.LicenseNumber).HasMaxLength(80);
        builder.Property(x => x.CompanyName).HasMaxLength(180);
        builder.Property(x => x.TruckPlateNumber).HasMaxLength(40);
        builder.Property(x => x.ApiTokenHash).HasMaxLength(128);
    }
}

public class RefreshTokenConfiguration : IEntityTypeConfiguration<RefreshToken>
{
    public void Configure(EntityTypeBuilder<RefreshToken> builder)
    {
        builder.ToTable("refresh_tokens");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.TokenHash).HasMaxLength(128).IsRequired();
        builder.HasIndex(x => x.TokenHash).IsUnique();
        builder.HasOne(x => x.User)
            .WithMany(x => x.RefreshTokens)
            .HasForeignKey(x => x.UserId)
            .OnDelete(DeleteBehavior.Cascade);
    }
}

public class ShippingLineConfigurationConfig : IEntityTypeConfiguration<ShippingLine>
{
    public void Configure(EntityTypeBuilder<ShippingLine> builder)
    {
        builder.ToTable("shipping_lines");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.BrandName).HasMaxLength(180).IsRequired();
        builder.HasIndex(x => x.BrandName).IsUnique();
        builder.Property(x => x.LogoPath).HasMaxLength(500);
        builder.Property(x => x.BrandColor).HasMaxLength(20);
        builder.Property(x => x.PortalConfigJson).HasColumnType("json");
        builder.HasOne(x => x.AssignedAdminUser)
            .WithMany()
            .HasForeignKey(x => x.AssignedAdminUserId)
            .OnDelete(DeleteBehavior.SetNull);
    }
}

public class ShippingLineConfigurationEntityConfig : IEntityTypeConfiguration<ShippingLineConfiguration>
{
    public void Configure(EntityTypeBuilder<ShippingLineConfiguration> builder)
    {
        builder.ToTable("shipping_line_configurations");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Key).HasMaxLength(120).IsRequired();
        builder.Property(x => x.Value).HasMaxLength(2000);
        builder.HasIndex(x => new { x.ShippingLineId, x.Key }).IsUnique();
        builder.HasOne(x => x.ShippingLine)
            .WithMany(x => x.Configurations)
            .HasForeignKey(x => x.ShippingLineId)
            .OnDelete(DeleteBehavior.Cascade);
    }
}

public class RolePermissionConfigurationConfig : IEntityTypeConfiguration<RolePermissionConfiguration>
{
    public void Configure(EntityTypeBuilder<RolePermissionConfiguration> builder)
    {
        builder.ToTable("role_permission_configurations");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Role).HasMaxLength(50).IsRequired();
        builder.Property(x => x.PermissionKey).HasMaxLength(120).IsRequired();
        builder.HasIndex(x => new { x.ShippingLineId, x.Role, x.PermissionKey }).IsUnique();
        builder.HasOne(x => x.ShippingLine)
            .WithMany(x => x.RolePermissions)
            .HasForeignKey(x => x.ShippingLineId)
            .OnDelete(DeleteBehavior.Cascade);
    }
}

public class PendingUserConfiguration : IEntityTypeConfiguration<PendingUser>
{
    public void Configure(EntityTypeBuilder<PendingUser> builder)
    {
        builder.ToTable("pending_users");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Email).HasMaxLength(180).IsRequired();
        builder.Property(x => x.FirstName).HasMaxLength(100).IsRequired();
        builder.Property(x => x.LastName).HasMaxLength(100).IsRequired();
        builder.Property(x => x.Role).HasMaxLength(50).IsRequired();
        builder.Property(x => x.AcceptanceToken).HasMaxLength(64).IsRequired();
        builder.HasIndex(x => x.AcceptanceToken).IsUnique();
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(40);
        builder.HasOne(x => x.CreatedByAdmin)
            .WithMany()
            .HasForeignKey(x => x.CreatedByAdminId)
            .OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.ShippingLine)
            .WithMany()
            .HasForeignKey(x => x.ShippingLineId)
            .OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(x => x.ShippingLineAdmin)
            .WithMany()
            .HasForeignKey(x => x.ShippingLineAdminId)
            .OnDelete(DeleteBehavior.Restrict);
    }
}

public class ReferralCodeConfiguration : IEntityTypeConfiguration<ReferralCode>
{
    public void Configure(EntityTypeBuilder<ReferralCode> builder)
    {
        builder.ToTable("referral_codes");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Code).HasMaxLength(50).IsRequired();
        builder.HasIndex(x => x.Code).IsUnique();
        builder.HasOne(x => x.Consignee)
            .WithMany()
            .HasForeignKey(x => x.ConsigneeId)
            .OnDelete(DeleteBehavior.Cascade);
    }
}

public class ConsigneeBrokerRelationshipConfiguration : IEntityTypeConfiguration<ConsigneeBrokerRelationship>
{
    public void Configure(EntityTypeBuilder<ConsigneeBrokerRelationship> builder)
    {
        builder.ToTable("consignee_broker_relationships");
        builder.HasKey(x => x.Id);
        builder.HasIndex(x => new { x.ConsigneeId, x.BrokerId }).IsUnique();
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(30);
        builder.Property(x => x.SuspensionReason).HasMaxLength(500);
        builder.HasOne(x => x.Consignee)
            .WithMany()
            .HasForeignKey(x => x.ConsigneeId)
            .OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.Broker)
            .WithMany()
            .HasForeignKey(x => x.BrokerId)
            .OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.ReferralCode)
            .WithMany()
            .HasForeignKey(x => x.ReferralCodeId)
            .OnDelete(DeleteBehavior.Restrict);
    }
}

public class UserShippingLinePreferenceConfiguration : IEntityTypeConfiguration<UserShippingLinePreference>
{
    public void Configure(EntityTypeBuilder<UserShippingLinePreference> builder)
    {
        builder.ToTable("user_shipping_line_preferences");
        builder.HasKey(x => x.Id);
        builder.HasIndex(x => x.UserId).IsUnique();
        builder.HasOne(x => x.User)
            .WithOne(x => x.ShippingLinePreference)
            .HasForeignKey<UserShippingLinePreference>(x => x.UserId)
            .OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.LastSelectedShippingLine)
            .WithMany()
            .HasForeignKey(x => x.LastSelectedShippingLineId)
            .OnDelete(DeleteBehavior.SetNull);
    }
}

public class RegionConfiguration : IEntityTypeConfiguration<Region>
{
    public void Configure(EntityTypeBuilder<Region> builder)
    {
        builder.ToTable("regions");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Code).HasMaxLength(20).IsRequired();
        builder.Property(x => x.Name).HasMaxLength(150).IsRequired();
        builder.HasIndex(x => x.Code).IsUnique();
    }
}

public class ProvinceConfiguration : IEntityTypeConfiguration<Province>
{
    public void Configure(EntityTypeBuilder<Province> builder)
    {
        builder.ToTable("provinces");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Code).HasMaxLength(20).IsRequired();
        builder.Property(x => x.Name).HasMaxLength(150).IsRequired();
        builder.HasIndex(x => x.Code).IsUnique();
        builder.HasOne(x => x.Region)
            .WithMany(x => x.Provinces)
            .HasForeignKey(x => x.RegionId)
            .OnDelete(DeleteBehavior.Restrict);
    }
}

public class CityConfiguration : IEntityTypeConfiguration<City>
{
    public void Configure(EntityTypeBuilder<City> builder)
    {
        builder.ToTable("cities");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Code).HasMaxLength(20).IsRequired();
        builder.Property(x => x.Name).HasMaxLength(150).IsRequired();
        builder.HasIndex(x => x.Code).IsUnique();
        builder.HasOne(x => x.Province)
            .WithMany(x => x.Cities)
            .HasForeignKey(x => x.ProvinceId)
            .OnDelete(DeleteBehavior.Restrict);
    }
}

public class BarangayConfiguration : IEntityTypeConfiguration<Barangay>
{
    public void Configure(EntityTypeBuilder<Barangay> builder)
    {
        builder.ToTable("barangays");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Code).HasMaxLength(20).IsRequired();
        builder.Property(x => x.Name).HasMaxLength(150).IsRequired();
        builder.HasIndex(x => x.Code).IsUnique();
        builder.HasOne(x => x.City)
            .WithMany(x => x.Barangays)
            .HasForeignKey(x => x.CityId)
            .OnDelete(DeleteBehavior.Restrict);
    }
}

public static class DefaultPermissionKeys
{
    public static readonly string[] All =
    {
        "user.manage",
        "shipping_line.manage",
        "data.view",
        "reports.view",
        "config.manage",
        "terminal.manage",
        "container.manage",
        "finance.manage",
        "evaluation.manage",
        "api.access"
    };

    public static IEnumerable<(string Role, string Permission, bool Allowed)> DefaultsFor(Guid _)
    {
        foreach (var role in AppRoles.All)
        {
            foreach (var key in All)
            {
                var allowed = role is AppRoles.SystemAdmin or AppRoles.ShippingLinesAdmin
                              || (role == AppRoles.Accounting && key.StartsWith("finance"))
                              || (role == AppRoles.Evaluator && key.StartsWith("evaluation"))
                              || (role is AppRoles.Broker or AppRoles.Consignee && key is "data.view" or "api.access")
                              || (role == AppRoles.SlStaff && key is "data.view" or "container.manage" or "api.access")
                              || (role == AppRoles.TerminalTeam && key is "data.view" or "terminal.manage" or "api.access")
                              || (role == AppRoles.Trucker && key is "data.view" or "api.access");
                yield return (role, key, allowed);
            }
        }
    }
}
