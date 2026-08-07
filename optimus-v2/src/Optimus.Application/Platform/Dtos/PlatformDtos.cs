namespace Optimus.Application.Platform.Dtos;

public record NotificationPreferenceDto(
    Guid Id,
    bool InAppEnabled,
    bool EmailEnabled,
    bool SmsEnabled,
    bool PushEnabled,
    string MutedCategoriesJson);

public record UpsertNotificationPreferenceRequest(
    bool InAppEnabled,
    bool EmailEnabled,
    bool SmsEnabled,
    bool PushEnabled,
    string? MutedCategoriesJson);

public record PushSubscribeRequest(string Endpoint, string P256dh, string Auth, string? UserAgent);

public record MessageTemplateDto(Guid Id, string Key, string Channel, string Name, string? Subject, string Body, bool IsActive);
public record UpsertMessageTemplateRequest(string Key, string Channel, string Name, string? Subject, string Body, bool IsActive = true);

public record NotificationDeliveryDto(
    Guid Id,
    Guid? UserId,
    string Channel,
    string Category,
    string Title,
    string Status,
    string? Error,
    DateTime CreatedAt);

public record NotificationMetricsDto(int Sent, int Failed, int Skipped, int InAppUnread, IReadOnlyList<NotificationDeliveryDto> Recent);

public record SystemSettingDto(Guid Id, string Key, string Value, string? Description);
public record UpsertSystemSettingRequest(string Key, string Value, string? Description);

public record RateLimitRuleDto(Guid Id, string Name, string PathPrefix, string? Role, int PermitLimit, int WindowSeconds, bool IsActive);
public record UpsertRateLimitRuleRequest(string Name, string PathPrefix, string? Role, int PermitLimit, int WindowSeconds, bool IsActive = true);

public record DocumentTemplateDto(Guid Id, string DocumentType, string Name, int Version, string BodyHtml, bool IsActive);
public record UpsertDocumentTemplateRequest(string DocumentType, string Name, string BodyHtml, bool IsActive = true);

public record ScheduledReportDto(
    Guid Id,
    string ReportType,
    string CronExpression,
    string RecipientsJson,
    bool IsActive,
    DateTime? LastRunAt,
    string? LastResultPath,
    string? LastError);

public record UpsertScheduledReportRequest(string ReportType, string CronExpression, string RecipientsJson, bool IsActive = true);

public record EdoReleaseMetricsDto(
    int TotalGenerated,
    int TotalReleased,
    int TotalRejected,
    int TotalExpired,
    int ReleasedLast7Days,
    int ReleasedLast30Days,
    double AvgHoursToRelease);

public record AuditTrailDto(string Source, string Event, string? From, string? To, string? Actor, DateTime At, string? Notes);

public record MaintenanceResultDto(int RefreshTokensRemoved, int NotificationsPurged, int DeliveriesPurged, int OrphanFilesRemoved);
