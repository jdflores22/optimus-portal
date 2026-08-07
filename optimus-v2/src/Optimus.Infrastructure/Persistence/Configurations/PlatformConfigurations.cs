using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using Optimus.Domain.Entities;

namespace Optimus.Infrastructure.Persistence.Configurations;

public class NotificationPreferenceConfiguration : IEntityTypeConfiguration<NotificationPreference>
{
    public void Configure(EntityTypeBuilder<NotificationPreference> builder)
    {
        builder.ToTable("notification_preferences");
        builder.HasKey(x => x.Id);
        builder.HasIndex(x => x.UserId).IsUnique();
        builder.Property(x => x.MutedCategoriesJson).HasColumnType("longtext");
        builder.HasOne(x => x.User).WithMany().HasForeignKey(x => x.UserId).OnDelete(DeleteBehavior.Cascade);
    }
}

public class PushSubscriptionConfiguration : IEntityTypeConfiguration<PushSubscription>
{
    public void Configure(EntityTypeBuilder<PushSubscription> builder)
    {
        builder.ToTable("push_subscriptions");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Endpoint).HasMaxLength(1000).IsRequired();
        builder.HasIndex(x => x.Endpoint).IsUnique();
        builder.Property(x => x.P256dh).HasMaxLength(200);
        builder.Property(x => x.Auth).HasMaxLength(200);
        builder.Property(x => x.UserAgent).HasMaxLength(300);
        builder.HasOne(x => x.User).WithMany().HasForeignKey(x => x.UserId).OnDelete(DeleteBehavior.Cascade);
    }
}

public class MessageTemplateConfiguration : IEntityTypeConfiguration<MessageTemplate>
{
    public void Configure(EntityTypeBuilder<MessageTemplate> builder)
    {
        builder.ToTable("message_templates");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Key).HasMaxLength(80).IsRequired();
        builder.Property(x => x.Channel).HasMaxLength(20);
        builder.Property(x => x.Name).HasMaxLength(120);
        builder.Property(x => x.Subject).HasMaxLength(200);
        builder.Property(x => x.Body).HasColumnType("longtext");
        builder.HasIndex(x => new { x.Key, x.Channel }).IsUnique();
    }
}

public class NotificationDeliveryConfiguration : IEntityTypeConfiguration<NotificationDelivery>
{
    public void Configure(EntityTypeBuilder<NotificationDelivery> builder)
    {
        builder.ToTable("notification_deliveries");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Channel).HasMaxLength(20);
        builder.Property(x => x.Category).HasMaxLength(40);
        builder.Property(x => x.Title).HasMaxLength(200);
        builder.Property(x => x.Status).HasMaxLength(20);
        builder.Property(x => x.Error).HasMaxLength(1000);
        builder.HasOne(x => x.User).WithMany().HasForeignKey(x => x.UserId).OnDelete(DeleteBehavior.SetNull);
    }
}

public class SystemSettingConfiguration : IEntityTypeConfiguration<SystemSetting>
{
    public void Configure(EntityTypeBuilder<SystemSetting> builder)
    {
        builder.ToTable("system_settings");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Key).HasMaxLength(100).IsRequired();
        builder.HasIndex(x => x.Key).IsUnique();
        builder.Property(x => x.Value).HasMaxLength(2000);
        builder.Property(x => x.Description).HasMaxLength(500);
    }
}

public class RateLimitRuleConfiguration : IEntityTypeConfiguration<RateLimitRule>
{
    public void Configure(EntityTypeBuilder<RateLimitRule> builder)
    {
        builder.ToTable("rate_limit_rules");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Name).HasMaxLength(100);
        builder.Property(x => x.PathPrefix).HasMaxLength(200);
        builder.Property(x => x.Role).HasMaxLength(40);
        builder.HasIndex(x => new { x.IsActive, x.PathPrefix });
    }
}

public class DocumentTemplateConfigurationEntity : IEntityTypeConfiguration<DocumentTemplate>
{
    public void Configure(EntityTypeBuilder<DocumentTemplate> builder)
    {
        builder.ToTable("document_templates");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.DocumentType).HasMaxLength(40);
        builder.Property(x => x.Name).HasMaxLength(120);
        builder.Property(x => x.BodyHtml).HasColumnType("longtext");
        builder.HasIndex(x => new { x.DocumentType, x.Version });
        builder.HasOne(x => x.UpdatedBy).WithMany().HasForeignKey(x => x.UpdatedById).OnDelete(DeleteBehavior.SetNull);
    }
}

public class ScheduledReportConfiguration : IEntityTypeConfiguration<ScheduledReport>
{
    public void Configure(EntityTypeBuilder<ScheduledReport> builder)
    {
        builder.ToTable("scheduled_reports");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.ReportType).HasMaxLength(60);
        builder.Property(x => x.CronExpression).HasMaxLength(80);
        builder.Property(x => x.RecipientsJson).HasColumnType("longtext");
        builder.Property(x => x.LastResultPath).HasMaxLength(500);
        builder.Property(x => x.LastError).HasMaxLength(1000);
    }
}
