using Optimus.Domain.Common;

namespace Optimus.Domain.Entities;

public class NotificationPreference : BaseEntity
{
    public Guid UserId { get; set; }
    public User User { get; set; } = null!;
    public bool InAppEnabled { get; set; } = true;
    public bool EmailEnabled { get; set; } = true;
    public bool SmsEnabled { get; set; } = false;
    public bool PushEnabled { get; set; } = true;
    public string MutedCategoriesJson { get; set; } = "[]";
}

public class PushSubscription : BaseEntity
{
    public Guid UserId { get; set; }
    public User User { get; set; } = null!;
    public string Endpoint { get; set; } = string.Empty;
    public string P256dh { get; set; } = string.Empty;
    public string Auth { get; set; } = string.Empty;
    public string? UserAgent { get; set; }
    public DateTime? LastUsedAt { get; set; }
}

public class MessageTemplate : BaseEntity
{
    public string Key { get; set; } = string.Empty;
    public string Channel { get; set; } = "email"; // email | sms
    public string Name { get; set; } = string.Empty;
    public string? Subject { get; set; }
    public string Body { get; set; } = string.Empty;
    public bool IsActive { get; set; } = true;
}

public class NotificationDelivery : BaseEntity
{
    public Guid? UserId { get; set; }
    public User? User { get; set; }
    public string Channel { get; set; } = "email";
    public string Category { get; set; } = "general";
    public string Title { get; set; } = string.Empty;
    public string Status { get; set; } = "sent"; // sent | failed | skipped
    public string? Error { get; set; }
    public Guid? NotificationId { get; set; }
}

public class SystemSetting : BaseEntity
{
    public string Key { get; set; } = string.Empty;
    public string Value { get; set; } = string.Empty;
    public string? Description { get; set; }
}

public class RateLimitRule : BaseEntity
{
    public string Name { get; set; } = string.Empty;
    public string PathPrefix { get; set; } = "/api";
    public string? Role { get; set; }
    public int PermitLimit { get; set; } = 100;
    public int WindowSeconds { get; set; } = 60;
    public bool IsActive { get; set; } = true;
}

public class DocumentTemplate : BaseEntity
{
    public string DocumentType { get; set; } = string.Empty; // NOA, EDO, BL, Billing, OR, Certificate
    public string Name { get; set; } = string.Empty;
    public int Version { get; set; } = 1;
    public string BodyHtml { get; set; } = string.Empty;
    public bool IsActive { get; set; } = true;
    public Guid? UpdatedById { get; set; }
    public User? UpdatedBy { get; set; }
}

public class ScheduledReport : BaseEntity
{
    public string ReportType { get; set; } = string.Empty; // edo_release_metrics | utilization | activity
    public string CronExpression { get; set; } = "0 2 * * *";
    public string RecipientsJson { get; set; } = "[]";
    public bool IsActive { get; set; } = true;
    public DateTime? LastRunAt { get; set; }
    public string? LastResultPath { get; set; }
    public string? LastError { get; set; }
}
