using Optimus.Application.Cargo.Dtos;
using Optimus.Application.Platform.Dtos;
using Optimus.Application.Yard.Dtos;

namespace Optimus.Application.Platform.Interfaces;

public interface INotificationHubService
{
    Task NotifyAsync(Guid? userId, string title, string message, string category, string? subjectType, Guid? subjectId, CancellationToken ct = default);
    Task<IReadOnlyList<NotificationDto>> ListForUserAsync(Guid userId, CancellationToken ct = default);
    Task<NotificationDto?> GetForUserAsync(Guid userId, Guid notificationId, CancellationToken ct = default);
    Task MarkReadAsync(Guid userId, Guid? notificationId, CancellationToken ct = default);
    Task<NotificationPreferenceDto> GetPreferencesAsync(Guid userId, CancellationToken ct = default);
    Task<NotificationPreferenceDto> UpsertPreferencesAsync(Guid userId, UpsertNotificationPreferenceRequest request, CancellationToken ct = default);
    Task SubscribePushAsync(Guid userId, PushSubscribeRequest request, CancellationToken ct = default);
    Task UnsubscribePushAsync(Guid userId, string endpoint, CancellationToken ct = default);
    Task<NotificationMetricsDto> GetMetricsAsync(CancellationToken ct = default);
}

public interface IMessageTemplateService
{
    Task<IReadOnlyList<MessageTemplateDto>> ListAsync(string? channel, CancellationToken ct = default);
    Task<MessageTemplateDto> UpsertAsync(UpsertMessageTemplateRequest request, CancellationToken ct = default);
    Task<string> RenderAsync(string key, string channel, IDictionary<string, string> tokens, CancellationToken ct = default);
}

public interface ISmsSender
{
    Task SendAsync(string toPhone, string body, CancellationToken ct = default);
}

public interface IPushSender
{
    Task SendAsync(string endpoint, string p256dh, string auth, string title, string body, CancellationToken ct = default);
}

public interface ISystemSettingsService
{
    Task<IReadOnlyList<SystemSettingDto>> ListAsync(CancellationToken ct = default);
    Task<SystemSettingDto> UpsertAsync(UpsertSystemSettingRequest request, CancellationToken ct = default);
    Task<string?> GetValueAsync(string key, CancellationToken ct = default);
}

public interface IRateLimitAdminService
{
    Task<IReadOnlyList<RateLimitRuleDto>> ListAsync(CancellationToken ct = default);
    Task<RateLimitRuleDto> UpsertAsync(Guid? id, UpsertRateLimitRuleRequest request, CancellationToken ct = default);
}

public interface IDocumentTemplateService
{
    Task<IReadOnlyList<DocumentTemplateDto>> ListAsync(CancellationToken ct = default);
    Task<DocumentTemplateDto> UpsertAsync(UpsertDocumentTemplateRequest request, Guid actorId, CancellationToken ct = default);
    Task<DocumentTemplateDto?> GetActiveAsync(string documentType, CancellationToken ct = default);
}

public interface IScheduledReportService
{
    Task<IReadOnlyList<ScheduledReportDto>> ListAsync(CancellationToken ct = default);
    Task<ScheduledReportDto> UpsertAsync(Guid? id, UpsertScheduledReportRequest request, CancellationToken ct = default);
    Task<int> ProcessDueAsync(CancellationToken ct = default);
}

public interface IReportsService
{
    Task<EdoReleaseMetricsDto> GetEdoReleaseMetricsAsync(CancellationToken ct = default);
    Task<(string Csv, string PdfPath)> ExportEdoReleaseMetricsAsync(CancellationToken ct = default);
}

public interface IAuditTrailService
{
    Task<IReadOnlyList<AuditTrailDto>> GetManifestAuditAsync(Guid manifestId, CancellationToken ct = default);
    Task<IReadOnlyList<AuditTrailDto>> GetEdoAuditAsync(Guid edoId, CancellationToken ct = default);
    Task LogEdoAccessAsync(Guid edoId, Guid userId, string? ip, string result, CancellationToken ct = default);
}

public interface IMaintenanceService
{
    Task<MaintenanceResultDto> RunAsync(CancellationToken ct = default);
}

// Keep yard INotificationService compatible by having hub implement same surface via adapter
public interface IPlatformActivityService
{
    Task<IReadOnlyList<ActivityLogDto>> ListAsync(string? entityType, Guid? entityId, int take = 100, CancellationToken ct = default);
}
