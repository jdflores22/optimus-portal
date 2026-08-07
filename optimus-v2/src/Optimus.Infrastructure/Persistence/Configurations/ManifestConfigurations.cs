using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using Optimus.Domain.Entities;

namespace Optimus.Infrastructure.Persistence.Configurations;

public class ManifestConfiguration : IEntityTypeConfiguration<Manifest>
{
    public void Configure(EntityTypeBuilder<Manifest> builder)
    {
        builder.ToTable("manifests");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.ManifestNumber).HasMaxLength(80).IsRequired();
        builder.HasIndex(x => x.ManifestNumber).IsUnique();
        builder.Property(x => x.VesselName).HasMaxLength(180);
        builder.Property(x => x.VoyageNumber).HasMaxLength(80);
        builder.Property(x => x.BlNumber).HasMaxLength(80);
        builder.Property(x => x.BlFilePath).HasMaxLength(500);
        builder.Property(x => x.BlPdfPath).HasMaxLength(500);
        builder.Property(x => x.ManifestFilePath).HasMaxLength(500);
        builder.Property(x => x.WorkflowState).HasConversion<string>().HasMaxLength(40);
        builder.HasIndex(x => x.WorkflowState);
        builder.HasIndex(x => new { x.ShippingLineId, x.WorkflowState });
        builder.HasOne(x => x.ShippingLine).WithMany().HasForeignKey(x => x.ShippingLineId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.Consignee).WithMany().HasForeignKey(x => x.ConsigneeId).OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(x => x.Broker).WithMany().HasForeignKey(x => x.BrokerId).OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(x => x.CreatedBy).WithMany().HasForeignKey(x => x.CreatedById).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.Noa).WithOne(x => x.Manifest).HasForeignKey<Noa>(x => x.ManifestId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.Billing).WithOne(x => x.Manifest).HasForeignKey<Billing>(x => x.ManifestId).OnDelete(DeleteBehavior.Cascade);
    }
}

public class NoaConfiguration : IEntityTypeConfiguration<Noa>
{
    public void Configure(EntityTypeBuilder<Noa> builder)
    {
        builder.ToTable("noas");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.NoaNumber).HasMaxLength(80).IsRequired();
        builder.HasIndex(x => x.NoaNumber).IsUnique();
        builder.Property(x => x.BlNumber).HasMaxLength(80);
        builder.Property(x => x.VesselName).HasMaxLength(180);
        builder.Property(x => x.PortLocation).HasMaxLength(180);
        builder.Property(x => x.PdfPath).HasMaxLength(500);
        builder.HasOne(x => x.Consignee).WithMany().HasForeignKey(x => x.ConsigneeId).OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(x => x.CreatedBy).WithMany().HasForeignKey(x => x.CreatedById).OnDelete(DeleteBehavior.Restrict);
    }
}

public class BillingConfiguration : IEntityTypeConfiguration<Billing>
{
    public void Configure(EntityTypeBuilder<Billing> builder)
    {
        builder.ToTable("billings");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.BillingType).HasMaxLength(40);
        builder.Property(x => x.FreightCharges).HasPrecision(18, 2);
        builder.Property(x => x.ThcCharges).HasPrecision(18, 2);
        builder.Property(x => x.AdditionalCharges).HasPrecision(18, 2);
        builder.Property(x => x.TotalAmount).HasPrecision(18, 2);
        builder.Property(x => x.TotalAmountPhp).HasPrecision(18, 2);
        builder.Property(x => x.ExchangeRate).HasPrecision(18, 6);
        builder.Property(x => x.Currency).HasMaxLength(10);
        builder.Property(x => x.PdfPath).HasMaxLength(500);
        builder.HasOne(x => x.GeneratedBy).WithMany().HasForeignKey(x => x.GeneratedById).OnDelete(DeleteBehavior.Restrict);
    }
}

public class PaymentConfiguration : IEntityTypeConfiguration<Payment>
{
    public void Configure(EntityTypeBuilder<Payment> builder)
    {
        builder.ToTable("payments");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.PaymentType).HasConversion<string>().HasMaxLength(40);
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(40);
        builder.HasIndex(x => x.Status);
        builder.HasIndex(x => new { x.ManifestId, x.Status });
        builder.Property(x => x.Amount).HasPrecision(18, 2);
        builder.Property(x => x.Currency).HasMaxLength(10);
        builder.Property(x => x.ReceiptFilePath).HasMaxLength(500);
        builder.Property(x => x.OfficialReceiptPath).HasMaxLength(500);
        builder.Property(x => x.RejectionReason).HasMaxLength(500);
        builder.HasOne(x => x.Manifest).WithMany(x => x.Payments).HasForeignKey(x => x.ManifestId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.ShippingLine).WithMany().HasForeignKey(x => x.ShippingLineId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.SubmittedBy).WithMany().HasForeignKey(x => x.SubmittedById).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.ValidatedBy).WithMany().HasForeignKey(x => x.ValidatedById).OnDelete(DeleteBehavior.Restrict);
    }
}

public class PaymentFeeConfigurationConfig : IEntityTypeConfiguration<PaymentFeeConfiguration>
{
    public void Configure(EntityTypeBuilder<PaymentFeeConfiguration> builder)
    {
        builder.ToTable("payment_fee_configurations");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.FeeType).HasMaxLength(40).IsRequired();
        builder.Property(x => x.Amount).HasPrecision(18, 2);
        builder.Property(x => x.PreviousAmount).HasPrecision(18, 2);
        builder.Property(x => x.QrCodePath).HasMaxLength(500);
        builder.HasIndex(x => new { x.FeeType, x.IsActive });
        builder.HasOne(x => x.ConfiguredBy).WithMany().HasForeignKey(x => x.ConfiguredById).OnDelete(DeleteBehavior.Restrict);
    }
}

public class WorkflowStateHistoryConfiguration : IEntityTypeConfiguration<WorkflowStateHistory>
{
    public void Configure(EntityTypeBuilder<WorkflowStateHistory> builder)
    {
        builder.ToTable("workflow_state_histories");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.FromState).HasConversion<string>().HasMaxLength(40);
        builder.Property(x => x.ToState).HasConversion<string>().HasMaxLength(40);
        builder.Property(x => x.ActorRole).HasMaxLength(50);
        builder.Property(x => x.TransitionReason).HasMaxLength(500);
        builder.HasOne(x => x.Manifest).WithMany(x => x.StateHistory).HasForeignKey(x => x.ManifestId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.Actor).WithMany().HasForeignKey(x => x.ActorId).OnDelete(DeleteBehavior.Restrict);
    }
}

public class ActivityLogConfiguration : IEntityTypeConfiguration<ActivityLog>
{
    public void Configure(EntityTypeBuilder<ActivityLog> builder)
    {
        builder.ToTable("activity_logs");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Action).HasMaxLength(120).IsRequired();
        builder.Property(x => x.EntityType).HasMaxLength(80).IsRequired();
        builder.Property(x => x.Details).HasMaxLength(2000);
        builder.HasOne(x => x.Actor).WithMany().HasForeignKey(x => x.ActorId).OnDelete(DeleteBehavior.SetNull);
    }
}

public class BulkImportJobConfiguration : IEntityTypeConfiguration<BulkImportJob>
{
    public void Configure(EntityTypeBuilder<BulkImportJob> builder)
    {
        builder.ToTable("bulk_import_jobs");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.FileName).HasMaxLength(255);
        builder.Property(x => x.Status).HasMaxLength(40);
        builder.Property(x => x.ErrorLog).HasColumnType("longtext");
        builder.HasOne(x => x.CreatedBy).WithMany().HasForeignKey(x => x.CreatedById).OnDelete(DeleteBehavior.Restrict);
    }
}
