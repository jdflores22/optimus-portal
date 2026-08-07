using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using Optimus.Domain.Entities;

namespace Optimus.Infrastructure.Persistence.Configurations;

public class FormConfigurationConfiguration : IEntityTypeConfiguration<FormConfiguration>
{
    public void Configure(EntityTypeBuilder<FormConfiguration> builder)
    {
        builder.ToTable("form_configurations");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Name).HasMaxLength(120).IsRequired();
        builder.Property(x => x.Type).HasConversion<string>().HasMaxLength(30);
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(30);
        builder.Property(x => x.FieldsJson).HasColumnType("longtext");
        builder.HasIndex(x => new { x.Type, x.Version });
        builder.HasOne(x => x.CreatedBy).WithMany().HasForeignKey(x => x.CreatedById).OnDelete(DeleteBehavior.Restrict);
    }
}

public class AccreditationSubmissionConfiguration : IEntityTypeConfiguration<AccreditationSubmission>
{
    public void Configure(EntityTypeBuilder<AccreditationSubmission> builder)
    {
        builder.ToTable("accreditation_submissions");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(40);
        builder.Property(x => x.SubmittedDataJson).HasColumnType("longtext");
        builder.Property(x => x.DenialReason).HasMaxLength(1000);
        builder.Property(x => x.ComplianceNotes).HasMaxLength(2000);
        builder.Property(x => x.ComplianceFieldIdsJson).HasColumnType("longtext");
        builder.HasIndex(x => new { x.ApplicantId, x.ShippingLineId }).IsUnique();
        builder.HasOne(x => x.Applicant).WithMany().HasForeignKey(x => x.ApplicantId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.ShippingLine).WithMany().HasForeignKey(x => x.ShippingLineId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.FormConfiguration).WithMany().HasForeignKey(x => x.FormConfigurationId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.Evaluator).WithMany().HasForeignKey(x => x.EvaluatorId).OnDelete(DeleteBehavior.SetNull);
        builder.HasOne(x => x.FinalApprover).WithMany().HasForeignKey(x => x.FinalApproverId).OnDelete(DeleteBehavior.SetNull);
    }
}

public class BrokerTransferRequestConfiguration : IEntityTypeConfiguration<BrokerTransferRequest>
{
    public void Configure(EntityTypeBuilder<BrokerTransferRequest> builder)
    {
        builder.ToTable("broker_transfer_requests");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(30);
        builder.Property(x => x.Reason).HasMaxLength(2000);
        builder.Property(x => x.TransferLetterPath).HasMaxLength(500);
        builder.Property(x => x.ReviewNotes).HasMaxLength(1000);
        builder.HasIndex(x => x.ManifestId);
        builder.HasOne(x => x.Manifest).WithMany().HasForeignKey(x => x.ManifestId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.Consignee).WithMany().HasForeignKey(x => x.ConsigneeId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.OldBroker).WithMany().HasForeignKey(x => x.OldBrokerId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.NewBroker).WithMany().HasForeignKey(x => x.NewBrokerId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.RequestedBy).WithMany().HasForeignKey(x => x.RequestedById).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.ReviewedBy).WithMany().HasForeignKey(x => x.ReviewedById).OnDelete(DeleteBehavior.SetNull);
    }
}

public class SuspensionAppealConfiguration : IEntityTypeConfiguration<SuspensionAppeal>
{
    public void Configure(EntityTypeBuilder<SuspensionAppeal> builder)
    {
        builder.ToTable("suspension_appeals");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(30);
        builder.Property(x => x.AppealLetter).HasColumnType("longtext");
        builder.Property(x => x.AttachmentsJson).HasColumnType("longtext");
        builder.Property(x => x.ReviewNotes).HasMaxLength(1000);
        builder.HasOne(x => x.User).WithMany().HasForeignKey(x => x.UserId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.ReviewedBy).WithMany().HasForeignKey(x => x.ReviewedById).OnDelete(DeleteBehavior.SetNull);
    }
}

public class RepositioningRequestConfiguration : IEntityTypeConfiguration<RepositioningRequest>
{
    public void Configure(EntityTypeBuilder<RepositioningRequest> builder)
    {
        builder.ToTable("repositioning_requests");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.RequestNumber).HasMaxLength(40).IsRequired();
        builder.HasIndex(x => x.RequestNumber).IsUnique();
        builder.Property(x => x.RequestType).HasConversion<string>().HasMaxLength(30);
        builder.Property(x => x.Status).HasConversion<string>().HasMaxLength(30);
        builder.Property(x => x.Purpose).HasMaxLength(2000);
        builder.Property(x => x.RequestLetterPath).HasMaxLength(500);
        builder.Property(x => x.ReviewNotes).HasMaxLength(1000);
        builder.HasOne(x => x.ShippingLine).WithMany().HasForeignKey(x => x.ShippingLineId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.SourceTerminal).WithMany().HasForeignKey(x => x.SourceTerminalId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.DestinationTerminal).WithMany().HasForeignKey(x => x.DestinationTerminalId).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.RequestedBy).WithMany().HasForeignKey(x => x.RequestedById).OnDelete(DeleteBehavior.Restrict);
        builder.HasOne(x => x.ReviewedBy).WithMany().HasForeignKey(x => x.ReviewedById).OnDelete(DeleteBehavior.SetNull);
    }
}

public class RepositioningRequestItemConfiguration : IEntityTypeConfiguration<RepositioningRequestItem>
{
    public void Configure(EntityTypeBuilder<RepositioningRequestItem> builder)
    {
        builder.ToTable("repositioning_request_items");
        builder.HasKey(x => x.Id);
        builder.HasIndex(x => new { x.RepositioningRequestId, x.ContainerId }).IsUnique();
        builder.HasOne(x => x.RepositioningRequest).WithMany(x => x.Items).HasForeignKey(x => x.RepositioningRequestId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.Container).WithMany().HasForeignKey(x => x.ContainerId).OnDelete(DeleteBehavior.Restrict);
    }
}

public class WelcomeContentConfiguration : IEntityTypeConfiguration<WelcomeContent>
{
    public void Configure(EntityTypeBuilder<WelcomeContent> builder)
    {
        builder.ToTable("welcome_contents");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Audience).HasMaxLength(40);
        builder.Property(x => x.Title).HasMaxLength(200);
        builder.Property(x => x.BodyMarkdown).HasColumnType("longtext");
        builder.Property(x => x.StepsJson).HasColumnType("longtext");
        builder.HasIndex(x => new { x.Audience, x.IsActive });
    }
}
