using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using Optimus.Domain.Entities;

namespace Optimus.Infrastructure.Persistence.Configurations;

public class TerminalConfiguration : IEntityTypeConfiguration<Terminal>
{
    public void Configure(EntityTypeBuilder<Terminal> builder)
    {
        builder.ToTable("terminals");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Name).HasMaxLength(120).IsRequired();
        builder.Property(x => x.Code).HasMaxLength(40).IsRequired();
        builder.HasIndex(x => x.Code).IsUnique();
        builder.Property(x => x.Identity).HasConversion<string>().HasMaxLength(30);
        builder.Property(x => x.Kind).HasConversion<string>().HasMaxLength(20);
        builder.Property(x => x.Location).HasMaxLength(200);
        builder.Property(x => x.Region).HasMaxLength(80);
        builder.Property(x => x.City).HasMaxLength(80);
    }
}

public class TerminalSlotConfiguration : IEntityTypeConfiguration<TerminalSlot>
{
    public void Configure(EntityTypeBuilder<TerminalSlot> builder)
    {
        builder.ToTable("terminal_slots");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(20);
        builder.HasIndex(x => new { x.TerminalId, x.Date }).IsUnique();
        builder.HasOne(x => x.Terminal).WithMany(x => x.Slots).HasForeignKey(x => x.TerminalId).OnDelete(DeleteBehavior.Cascade);
    }
}

public class ContainerTypeConfiguration : IEntityTypeConfiguration<ContainerType>
{
    public void Configure(EntityTypeBuilder<ContainerType> builder)
    {
        builder.ToTable("container_types");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Name).HasMaxLength(80).IsRequired();
        builder.Property(x => x.Code).HasMaxLength(20).IsRequired();
        builder.HasIndex(x => x.Code).IsUnique();
        builder.Property(x => x.Description).HasMaxLength(500);
    }
}

public class ContainerSizeConfiguration : IEntityTypeConfiguration<ContainerSize>
{
    public void Configure(EntityTypeBuilder<ContainerSize> builder)
    {
        builder.ToTable("container_sizes");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Name).HasMaxLength(80).IsRequired();
        builder.Property(x => x.Code).HasMaxLength(20).IsRequired();
        builder.HasIndex(x => x.Code).IsUnique();
        builder.Property(x => x.TeuValue).HasPrecision(8, 2);
        builder.Property(x => x.Description).HasMaxLength(500);
    }
}

public class ShippingLineTerminalAllocationConfiguration : IEntityTypeConfiguration<ShippingLineTerminalAllocation>
{
    public void Configure(EntityTypeBuilder<ShippingLineTerminalAllocation> builder)
    {
        builder.ToTable("shipping_line_terminal_allocations");
        builder.HasKey(x => x.Id);
        builder.HasIndex(x => new { x.ShippingLineId, x.TerminalId, x.StaffUserId }).IsUnique();
        builder.HasOne(x => x.ShippingLine).WithMany().HasForeignKey(x => x.ShippingLineId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.Terminal).WithMany(x => x.Allocations).HasForeignKey(x => x.TerminalId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.StaffUser).WithMany().HasForeignKey(x => x.StaffUserId).OnDelete(DeleteBehavior.SetNull);
    }
}

public class ContainerConfiguration : IEntityTypeConfiguration<Container>
{
    public void Configure(EntityTypeBuilder<Container> builder)
    {
        builder.ToTable("containers");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.ContainerNumber).HasMaxLength(30).IsRequired();
        builder.HasIndex(x => x.ContainerNumber).IsUnique();
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(40);
        builder.Property(x => x.AllocationStatus).HasConversion<string>().HasMaxLength(30);
        builder.Property(x => x.CurrentLocation).HasMaxLength(120);
        builder.Property(x => x.StackBay).HasMaxLength(20);
        builder.Property(x => x.StackRow).HasMaxLength(20);
        builder.Property(x => x.StackTier).HasMaxLength(20);
        builder.HasOne(x => x.ShippingLine).WithMany().HasForeignKey(x => x.ShippingLineId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.Manifest).WithMany().HasForeignKey(x => x.ManifestId).OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(x => x.ContainerType).WithMany().HasForeignKey(x => x.ContainerTypeId).OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(x => x.ContainerSize).WithMany().HasForeignKey(x => x.ContainerSizeId).OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(x => x.CyAllocation).WithMany(x => x.Containers).HasForeignKey(x => x.CyAllocationId).OnDelete(DeleteBehavior.SetNull);
    }
}

public class ContainerAllocationAuditConfiguration : IEntityTypeConfiguration<ContainerAllocationAudit>
{
    public void Configure(EntityTypeBuilder<ContainerAllocationAudit> builder)
    {
        builder.ToTable("container_allocation_audits");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.ChangeType).HasMaxLength(30);
        builder.Property(x => x.Reason).HasMaxLength(500);
        builder.Property(x => x.MetadataJson).HasColumnType("longtext");
        builder.HasOne(x => x.Container).WithMany(x => x.AllocationAudits).HasForeignKey(x => x.ContainerId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.ChangedBy).WithMany().HasForeignKey(x => x.ChangedById).OnDelete(DeleteBehavior.Restrict);
    }
}

public class DwellTimeConfigurationConfig : IEntityTypeConfiguration<DwellTimeConfiguration>
{
    public void Configure(EntityTypeBuilder<DwellTimeConfiguration> builder)
    {
        builder.ToTable("dwell_time_configurations");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Timezone).HasMaxLength(80);
    }
}

public class DwellTimeEventConfiguration : IEntityTypeConfiguration<DwellTimeEvent>
{
    public void Configure(EntityTypeBuilder<DwellTimeEvent> builder)
    {
        builder.ToTable("dwell_time_events");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.EventType).HasConversion<string>().HasMaxLength(40);
        builder.Property(x => x.Reason).HasMaxLength(500);
        builder.Property(x => x.MetadataJson).HasColumnType("longtext");
        builder.HasOne(x => x.Container).WithMany(x => x.DwellEvents).HasForeignKey(x => x.ContainerId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.TriggeredBy).WithMany().HasForeignKey(x => x.TriggeredById).OnDelete(DeleteBehavior.SetNull);
    }
}

public class PreAdviceRequestConfiguration : IEntityTypeConfiguration<PreAdviceRequest>
{
    public void Configure(EntityTypeBuilder<PreAdviceRequest> builder)
    {
        builder.ToTable("pre_advice_requests");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(30);
        builder.HasIndex(x => x.Status);
        builder.Property(x => x.RejectionReason).HasMaxLength(1000);
        builder.Property(x => x.PaymentReference).HasMaxLength(100);
        builder.Property(x => x.QrCodePath).HasMaxLength(500);
        builder.Property(x => x.PackagePdfPath).HasMaxLength(500);
        builder.Property(x => x.EdoNumber).HasMaxLength(100);
        builder.Property(x => x.VerificationToken).HasMaxLength(64);
        builder.HasOne(x => x.Trucker).WithMany().HasForeignKey(x => x.TruckerId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.Container).WithMany(x => x.PreAdviceRequests).HasForeignKey(x => x.ContainerId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.Terminal).WithMany().HasForeignKey(x => x.TerminalId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.AssignedSlot).WithMany().HasForeignKey(x => x.AssignedSlotId).OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(x => x.ShippingLine).WithMany().HasForeignKey(x => x.ShippingLineId).OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(x => x.VerifiedBy).WithMany().HasForeignKey(x => x.VerifiedById).OnDelete(DeleteBehavior.SetNull);
    }
}

public class GeotagPhotoConfiguration : IEntityTypeConfiguration<GeotagPhoto>
{
    public void Configure(EntityTypeBuilder<GeotagPhoto> builder)
    {
        builder.ToTable("geotag_photos");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.FilePath).HasMaxLength(500).IsRequired();
        builder.Property(x => x.OriginalName).HasMaxLength(255);
        builder.Property(x => x.VerificationNotes).HasMaxLength(500);
        builder.HasOne(x => x.PreAdviceRequest).WithMany(x => x.GeotagPhotos).HasForeignKey(x => x.PreAdviceRequestId).OnDelete(DeleteBehavior.Cascade);
    }
}

public class InAppNotificationConfiguration : IEntityTypeConfiguration<InAppNotification>
{
    public void Configure(EntityTypeBuilder<InAppNotification> builder)
    {
        builder.ToTable("in_app_notifications");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Title).HasMaxLength(200);
        builder.Property(x => x.Message).HasMaxLength(2000);
        builder.Property(x => x.Category).HasMaxLength(40);
        builder.Property(x => x.SubjectType).HasMaxLength(80);
        builder.HasOne(x => x.User).WithMany().HasForeignKey(x => x.UserId).OnDelete(DeleteBehavior.Cascade);
    }
}
