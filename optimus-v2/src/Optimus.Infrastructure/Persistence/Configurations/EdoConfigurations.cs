using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using Optimus.Domain.Entities;

namespace Optimus.Infrastructure.Persistence.Configurations;

public class ElectronicDeliveryOrderConfiguration : IEntityTypeConfiguration<ElectronicDeliveryOrder>
{
    public void Configure(EntityTypeBuilder<ElectronicDeliveryOrder> builder)
    {
        builder.ToTable("electronic_delivery_orders");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.EdoNumber).HasMaxLength(100).IsRequired();
        builder.HasIndex(x => x.EdoNumber).IsUnique();
        builder.Property(x => x.ContainerNumber).HasMaxLength(50);
        builder.Property(x => x.FeeAmount).HasPrecision(18, 2);
        builder.Property(x => x.PdfPath).HasMaxLength(500);
        builder.Property(x => x.QrPayload).HasMaxLength(500);
        builder.Property(x => x.QrImagePath).HasMaxLength(500);
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(40);
        builder.HasIndex(x => x.Status);
        builder.HasIndex(x => new { x.Status, x.ReleasedAt });
        builder.Property(x => x.CyLocation).HasMaxLength(100);
        builder.Property(x => x.AdditionalNotes).HasMaxLength(2000);
        builder.Property(x => x.RejectionReason).HasMaxLength(1000);
        builder.Property(x => x.VerificationToken).HasMaxLength(64);
        builder.HasIndex(x => x.VerificationToken);
        builder.HasOne(x => x.Manifest).WithMany().HasForeignKey(x => x.ManifestId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.ShippingLine).WithMany().HasForeignKey(x => x.ShippingLineId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.GeneratedBy).WithMany().HasForeignKey(x => x.GeneratedById).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.ReleasedBy).WithMany().HasForeignKey(x => x.ReleasedById).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.PreviousVersion).WithMany().HasForeignKey(x => x.PreviousVersionId).OnDelete(DeleteBehavior.SetNull);
    }
}

public class EdoVersionConfiguration : IEntityTypeConfiguration<EdoVersion>
{
    public void Configure(EntityTypeBuilder<EdoVersion> builder)
    {
        builder.ToTable("edo_versions");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.EdoNumber).HasMaxLength(100);
        builder.Property(x => x.PdfPath).HasMaxLength(500);
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(40);
        builder.Property(x => x.CyLocation).HasMaxLength(100);
        builder.Property(x => x.Notes).HasMaxLength(2000);
        builder.HasIndex(x => new { x.EdoId, x.VersionNumber }).IsUnique();
        builder.HasOne(x => x.Edo).WithMany(x => x.Versions).HasForeignKey(x => x.EdoId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.CreatedBy).WithMany().HasForeignKey(x => x.CreatedById).OnDelete(DeleteBehavior.SetNull);
    }
}

public class EdoPaymentConfiguration : IEntityTypeConfiguration<EdoPayment>
{
    public void Configure(EntityTypeBuilder<EdoPayment> builder)
    {
        builder.ToTable("edo_payments");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Amount).HasPrecision(18, 2);
        builder.Property(x => x.Currency).HasMaxLength(10);
        builder.Property(x => x.ReceiptFilePath).HasMaxLength(500);
        builder.Property(x => x.OfficialReceiptPath).HasMaxLength(500);
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(40);
        builder.Property(x => x.RejectionReason).HasMaxLength(500);
        builder.HasOne(x => x.Manifest).WithMany().HasForeignKey(x => x.ManifestId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.Edo).WithMany(x => x.Payments).HasForeignKey(x => x.EdoId).OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(x => x.ShippingLine).WithMany().HasForeignKey(x => x.ShippingLineId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.SubmittedBy).WithMany().HasForeignKey(x => x.SubmittedById).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.ValidatedBy).WithMany().HasForeignKey(x => x.ValidatedById).OnDelete(DeleteBehavior.Restrict);
    }
}

public class EdoRenewalRequestConfiguration : IEntityTypeConfiguration<EdoRenewalRequest>
{
    public void Configure(EntityTypeBuilder<EdoRenewalRequest> builder)
    {
        builder.ToTable("edo_renewal_requests");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.DetentionChargeAmount).HasPrecision(18, 2);
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(40);
        builder.Property(x => x.AdditionalNotes).HasMaxLength(2000);
        builder.HasOne(x => x.ExpiredEdo).WithMany().HasForeignKey(x => x.ExpiredEdoId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.NewEdo).WithMany().HasForeignKey(x => x.NewEdoId).OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(x => x.RequestedBy).WithMany().HasForeignKey(x => x.RequestedById).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.DetentionBilling).WithMany().HasForeignKey(x => x.DetentionBillingId).OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(x => x.PaymentVerifiedBy).WithMany().HasForeignKey(x => x.PaymentVerifiedById).OnDelete(DeleteBehavior.Restrict);
    }
}

public class EdoReleaseHistoryConfiguration : IEntityTypeConfiguration<EdoReleaseHistory>
{
    public void Configure(EntityTypeBuilder<EdoReleaseHistory> builder)
    {
        builder.ToTable("edo_release_history");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.FromStatus).HasConversion<string>().HasMaxLength(40);
        builder.Property(x => x.ToStatus).HasConversion<string>().HasMaxLength(40);
        builder.Property(x => x.RejectionReason).HasMaxLength(1000);
        builder.HasOne(x => x.Edo).WithMany(x => x.ReleaseHistory).HasForeignKey(x => x.EdoId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.Actor).WithMany().HasForeignKey(x => x.ActorId).OnDelete(DeleteBehavior.Restrict);
    }
}

public class EdoAccessLogConfiguration : IEntityTypeConfiguration<EdoAccessLog>
{
    public void Configure(EntityTypeBuilder<EdoAccessLog> builder)
    {
        builder.ToTable("edo_access_logs");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.IpAddress).HasMaxLength(45);
        builder.Property(x => x.AccessResult).HasMaxLength(20);
        builder.HasOne(x => x.Edo).WithMany(x => x.AccessLogs).HasForeignKey(x => x.EdoId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.AccessedBy).WithMany().HasForeignKey(x => x.AccessedById).OnDelete(DeleteBehavior.Restrict);
    }
}

public class GenerationSessionConfiguration : IEntityTypeConfiguration<GenerationSession>
{
    public void Configure(EntityTypeBuilder<GenerationSession> builder)
    {
        builder.ToTable("edo_generation_sessions");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.SessionId).HasMaxLength(36).IsRequired();
        builder.HasIndex(x => x.SessionId).IsUnique();
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(30);
        builder.Property(x => x.CurrentItem).HasMaxLength(80);
        builder.Property(x => x.FailuresJson).HasColumnType("longtext");
        builder.HasOne(x => x.Manifest).WithMany().HasForeignKey(x => x.ManifestId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.InitiatedBy).WithMany().HasForeignKey(x => x.InitiatedById).OnDelete(DeleteBehavior.Restrict);
    }
}

public class DocumentVerificationConfiguration : IEntityTypeConfiguration<DocumentVerification>
{
    public void Configure(EntityTypeBuilder<DocumentVerification> builder)
    {
        builder.ToTable("document_verifications");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.VerificationToken).HasMaxLength(64).IsRequired();
        builder.HasIndex(x => x.VerificationToken).IsUnique();
        builder.Property(x => x.DocumentType).HasMaxLength(40);
        builder.Property(x => x.SubjectType).HasMaxLength(64);
        builder.Property(x => x.DocumentNumber).HasMaxLength(100);
        builder.Property(x => x.SummaryJson).HasColumnType("json");
        builder.HasIndex(x => new { x.DocumentType, x.SubjectType, x.SubjectId }).IsUnique();
    }
}
