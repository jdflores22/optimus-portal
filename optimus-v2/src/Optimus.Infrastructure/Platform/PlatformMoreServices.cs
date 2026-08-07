using System.Text;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Optimus.Application.Cargo.Dtos;
using Optimus.Application.Cargo.Interfaces;
using Optimus.Application.Platform.Dtos;
using Optimus.Application.Platform.Interfaces;
using Optimus.Domain.Entities;
using Optimus.Domain.Enums;
using Optimus.Infrastructure.Persistence;

namespace Optimus.Infrastructure.Platform;

public class SystemSettingsService : ISystemSettingsService
{
    private readonly OptimusDbContext _db;
    public SystemSettingsService(OptimusDbContext db) => _db = db;

    public async Task<IReadOnlyList<SystemSettingDto>> ListAsync(CancellationToken ct = default)
    {
        var items = await _db.SystemSettings.AsNoTracking().OrderBy(x => x.Key).ToListAsync(ct);
        return items.Select(x => new SystemSettingDto(x.Id, x.Key, x.Value, x.Description)).ToList();
    }

    public async Task<SystemSettingDto> UpsertAsync(UpsertSystemSettingRequest request, CancellationToken ct = default)
    {
        var entity = await _db.SystemSettings.FirstOrDefaultAsync(x => x.Key == request.Key, ct);
        if (entity is null)
        {
            entity = new SystemSetting { Key = request.Key };
            _db.SystemSettings.Add(entity);
        }

        entity.Value = request.Value;
        entity.Description = request.Description;
        await _db.SaveChangesAsync(ct);
        return new SystemSettingDto(entity.Id, entity.Key, entity.Value, entity.Description);
    }

    public async Task<string?> GetValueAsync(string key, CancellationToken ct = default)
        => await _db.SystemSettings.AsNoTracking().Where(x => x.Key == key).Select(x => x.Value).FirstOrDefaultAsync(ct);
}

public class RateLimitAdminService : IRateLimitAdminService
{
    private readonly OptimusDbContext _db;
    public RateLimitAdminService(OptimusDbContext db) => _db = db;

    public async Task<IReadOnlyList<RateLimitRuleDto>> ListAsync(CancellationToken ct = default)
    {
        var items = await _db.RateLimitRules.AsNoTracking().OrderBy(x => x.Name).ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    public async Task<RateLimitRuleDto> UpsertAsync(Guid? id, UpsertRateLimitRuleRequest request, CancellationToken ct = default)
    {
        RateLimitRule entity;
        if (id.HasValue)
        {
            entity = await _db.RateLimitRules.FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Rule not found.");
        }
        else
        {
            entity = new RateLimitRule();
            _db.RateLimitRules.Add(entity);
        }

        entity.Name = request.Name;
        entity.PathPrefix = request.PathPrefix;
        entity.Role = request.Role;
        entity.PermitLimit = Math.Max(1, request.PermitLimit);
        entity.WindowSeconds = Math.Max(1, request.WindowSeconds);
        entity.IsActive = request.IsActive;
        await _db.SaveChangesAsync(ct);
        return Map(entity);
    }

    private static RateLimitRuleDto Map(RateLimitRule x) =>
        new(x.Id, x.Name, x.PathPrefix, x.Role, x.PermitLimit, x.WindowSeconds, x.IsActive);
}

public class DocumentTemplateService : IDocumentTemplateService
{
    private readonly OptimusDbContext _db;
    public DocumentTemplateService(OptimusDbContext db) => _db = db;

    public async Task<IReadOnlyList<DocumentTemplateDto>> ListAsync(CancellationToken ct = default)
    {
        var items = await _db.DocumentTemplates.AsNoTracking().OrderBy(x => x.DocumentType).ThenByDescending(x => x.Version).ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    public async Task<DocumentTemplateDto> UpsertAsync(UpsertDocumentTemplateRequest request, Guid actorId, CancellationToken ct = default)
    {
        var max = await _db.DocumentTemplates.Where(x => x.DocumentType == request.DocumentType).Select(x => (int?)x.Version).MaxAsync(ct) ?? 0;
        if (request.IsActive)
        {
            var actives = await _db.DocumentTemplates.Where(x => x.DocumentType == request.DocumentType && x.IsActive).ToListAsync(ct);
            foreach (var a in actives) a.IsActive = false;
        }

        var entity = new DocumentTemplate
        {
            DocumentType = request.DocumentType,
            Name = request.Name,
            BodyHtml = request.BodyHtml,
            Version = max + 1,
            IsActive = request.IsActive,
            UpdatedById = actorId
        };
        _db.DocumentTemplates.Add(entity);
        await _db.SaveChangesAsync(ct);
        return Map(entity);
    }

    public async Task<DocumentTemplateDto?> GetActiveAsync(string documentType, CancellationToken ct = default)
    {
        var entity = await _db.DocumentTemplates.AsNoTracking()
            .FirstOrDefaultAsync(x => x.DocumentType == documentType && x.IsActive, ct);
        return entity is null ? null : Map(entity);
    }

    private static DocumentTemplateDto Map(DocumentTemplate x) =>
        new(x.Id, x.DocumentType, x.Name, x.Version, x.BodyHtml, x.IsActive);
}

public class ScheduledReportService : IScheduledReportService
{
    private readonly OptimusDbContext _db;
    private readonly IReportsService _reports;
    private readonly IDocumentStore _docs;

    public ScheduledReportService(OptimusDbContext db, IReportsService reports, IDocumentStore docs)
    {
        _db = db;
        _reports = reports;
        _docs = docs;
    }

    public async Task<IReadOnlyList<ScheduledReportDto>> ListAsync(CancellationToken ct = default)
    {
        var items = await _db.ScheduledReports.AsNoTracking().OrderBy(x => x.ReportType).ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    public async Task<ScheduledReportDto> UpsertAsync(Guid? id, UpsertScheduledReportRequest request, CancellationToken ct = default)
    {
        ScheduledReport entity;
        if (id.HasValue)
        {
            entity = await _db.ScheduledReports.FirstOrDefaultAsync(x => x.Id == id, ct)
                     ?? throw new KeyNotFoundException("Scheduled report not found.");
        }
        else
        {
            entity = new ScheduledReport();
            _db.ScheduledReports.Add(entity);
        }

        entity.ReportType = request.ReportType;
        entity.CronExpression = request.CronExpression;
        entity.RecipientsJson = request.RecipientsJson;
        entity.IsActive = request.IsActive;
        await _db.SaveChangesAsync(ct);
        return Map(entity);
    }

    public async Task<int> ProcessDueAsync(CancellationToken ct = default)
    {
        // Simplified: run any active report not run in last 23 hours (daily cron approximation).
        var cutoff = DateTime.UtcNow.AddHours(-23);
        var due = await _db.ScheduledReports
            .Where(x => x.IsActive && (x.LastRunAt == null || x.LastRunAt < cutoff))
            .ToListAsync(ct);

        foreach (var report in due)
        {
            try
            {
                if (report.ReportType is "edo_release_metrics" or "edo-release")
                {
                    var (_, pdf) = await _reports.ExportEdoReleaseMetricsAsync(ct);
                    report.LastResultPath = pdf;
                }
                else
                {
                    report.LastResultPath = _docs.CreatePlaceholderPdf("reports", report.ReportType, $"Scheduled at {DateTime.UtcNow:o}");
                }

                report.LastRunAt = DateTime.UtcNow;
                report.LastError = null;
            }
            catch (Exception ex)
            {
                report.LastError = ex.Message;
                report.LastRunAt = DateTime.UtcNow;
            }
        }

        if (due.Count > 0) await _db.SaveChangesAsync(ct);
        return due.Count;
    }

    private static ScheduledReportDto Map(ScheduledReport x) =>
        new(x.Id, x.ReportType, x.CronExpression, x.RecipientsJson, x.IsActive, x.LastRunAt, x.LastResultPath, x.LastError);
}

public class ReportsService : IReportsService
{
    private readonly OptimusDbContext _db;
    private readonly IDocumentStore _docs;

    public ReportsService(OptimusDbContext db, IDocumentStore docs)
    {
        _db = db;
        _docs = docs;
    }

    public async Task<EdoReleaseMetricsDto> GetEdoReleaseMetricsAsync(CancellationToken ct = default)
    {
        var now = DateTime.UtcNow;
        var q = _db.ElectronicDeliveryOrders.AsNoTracking();
        var totalGenerated = await q.CountAsync(ct);
        var totalReleased = await q.CountAsync(x => x.Status == EdoStatus.Released || x.ReleasedAt != null, ct);
        var totalRejected = await q.CountAsync(x => x.Status == EdoStatus.Rejected, ct);
        var totalExpired = await q.CountAsync(x => x.Status == EdoStatus.Expired, ct);
        var released7 = await q.CountAsync(x => x.ReleasedAt >= now.AddDays(-7), ct);
        var released30 = await q.CountAsync(x => x.ReleasedAt >= now.AddDays(-30), ct);
        var avgSamples = await q.Where(x => x.ReleasedAt != null)
            .Select(x => new { x.ReleasedAt, x.GeneratedAt })
            .ToListAsync(ct);
        var avg = avgSamples.Count == 0
            ? 0
            : avgSamples.Average(x => (x.ReleasedAt!.Value - x.GeneratedAt).TotalHours);
        return new EdoReleaseMetricsDto(totalGenerated, totalReleased, totalRejected, totalExpired, released7, released30, Math.Round(avg, 2));
    }

    public async Task<(string Csv, string PdfPath)> ExportEdoReleaseMetricsAsync(CancellationToken ct = default)
    {
        var m = await GetEdoReleaseMetricsAsync(ct);
        var sb = new StringBuilder();
        sb.AppendLine("Metric,Value");
        sb.AppendLine($"TotalGenerated,{m.TotalGenerated}");
        sb.AppendLine($"TotalReleased,{m.TotalReleased}");
        sb.AppendLine($"TotalRejected,{m.TotalRejected}");
        sb.AppendLine($"TotalExpired,{m.TotalExpired}");
        sb.AppendLine($"ReleasedLast7Days,{m.ReleasedLast7Days}");
        sb.AppendLine($"ReleasedLast30Days,{m.ReleasedLast30Days}");
        sb.AppendLine($"AvgHoursToRelease,{m.AvgHoursToRelease}");
        var pdf = _docs.CreatePlaceholderPdf("reports", "eDO Release Metrics", sb.ToString());
        return (sb.ToString(), pdf);
    }
}

public class AuditTrailService : IAuditTrailService
{
    private readonly OptimusDbContext _db;
    public AuditTrailService(OptimusDbContext db) => _db = db;

    public async Task<IReadOnlyList<AuditTrailDto>> GetManifestAuditAsync(Guid manifestId, CancellationToken ct = default)
    {
        var history = await _db.WorkflowStateHistories.AsNoTracking()
            .Where(x => x.ManifestId == manifestId)
            .OrderBy(x => x.CreatedAt)
            .ToListAsync(ct);
        var activity = await _db.ActivityLogs.AsNoTracking()
            .Include(x => x.Actor)
            .Where(x => x.EntityType == nameof(Manifest) && x.EntityId == manifestId)
            .OrderBy(x => x.CreatedAt)
            .ToListAsync(ct);

        var items = history.Select(x => new AuditTrailDto("workflow", "state_change", x.FromState.ToString(), x.ToState.ToString(),
            x.ActorRole, x.CreatedAt, x.TransitionReason)).ToList();
        items.AddRange(activity.Select(x => new AuditTrailDto("activity", x.Action, null, null,
            x.Actor != null ? x.Actor.FullName : null, x.CreatedAt, x.Details)));
        return items.OrderBy(x => x.At).ToList();
    }

    public async Task<IReadOnlyList<AuditTrailDto>> GetEdoAuditAsync(Guid edoId, CancellationToken ct = default)
    {
        var releases = await _db.EdoReleaseHistories.AsNoTracking()
            .Include(x => x.Actor)
            .Where(x => x.EdoId == edoId)
            .OrderBy(x => x.CreatedAt)
            .ToListAsync(ct);
        var access = await _db.EdoAccessLogs.AsNoTracking()
            .Include(x => x.AccessedBy)
            .Where(x => x.EdoId == edoId)
            .OrderBy(x => x.AccessedAt)
            .ToListAsync(ct);
        var activity = await _db.ActivityLogs.AsNoTracking()
            .Include(x => x.Actor)
            .Where(x => x.EntityType == nameof(ElectronicDeliveryOrder) && x.EntityId == edoId)
            .ToListAsync(ct);

        var items = releases.Select(x => new AuditTrailDto("release", "status_change", x.FromStatus.ToString(), x.ToStatus.ToString(),
            x.Actor.FullName, x.CreatedAt, x.RejectionReason)).ToList();
        items.AddRange(access.Select(x => new AuditTrailDto("access", x.AccessResult, null, null, x.AccessedBy.FullName, x.AccessedAt, x.IpAddress)));
        items.AddRange(activity.Select(x => new AuditTrailDto("activity", x.Action, null, null,
            x.Actor != null ? x.Actor.FullName : null, x.CreatedAt, x.Details)));
        return items.OrderBy(x => x.At).ToList();
    }

    public async Task LogEdoAccessAsync(Guid edoId, Guid userId, string? ip, string result, CancellationToken ct = default)
    {
        _db.EdoAccessLogs.Add(new EdoAccessLog
        {
            EdoId = edoId,
            AccessedById = userId,
            IpAddress = ip,
            AccessResult = result
        });
        await _db.SaveChangesAsync(ct);
    }
}

public class PlatformActivityService : IPlatformActivityService
{
    private readonly OptimusDbContext _db;
    public PlatformActivityService(OptimusDbContext db) => _db = db;

    public async Task<IReadOnlyList<ActivityLogDto>> ListAsync(string? entityType, Guid? entityId, int take = 100, CancellationToken ct = default)
    {
        var q = _db.ActivityLogs.AsNoTracking().Include(x => x.Actor).AsQueryable();
        if (!string.IsNullOrWhiteSpace(entityType)) q = q.Where(x => x.EntityType == entityType);
        if (entityId.HasValue) q = q.Where(x => x.EntityId == entityId);
        return await q.OrderByDescending(x => x.CreatedAt).Take(Math.Clamp(take, 1, 500))
            .Select(x => new ActivityLogDto(x.Id, x.Action, x.EntityType, x.EntityId, x.Details, x.CreatedAt,
                x.Actor != null ? x.Actor.FirstName + " " + x.Actor.LastName : null))
            .ToListAsync(ct);
    }
}

public class MaintenanceService : IMaintenanceService
{
    private readonly OptimusDbContext _db;
    private readonly ILogger<MaintenanceService> _logger;

    public MaintenanceService(OptimusDbContext db, ILogger<MaintenanceService> logger)
    {
        _db = db;
        _logger = logger;
    }

    public async Task<MaintenanceResultDto> RunAsync(CancellationToken ct = default)
    {
        var now = DateTime.UtcNow;
        var tokens = await _db.RefreshTokens.Where(x => x.ExpiresAt < now || x.RevokedAt != null).ToListAsync(ct);
        _db.RefreshTokens.RemoveRange(tokens);

        var oldNotifs = await _db.InAppNotifications.Where(x => x.CreatedAt < now.AddDays(-90) && x.IsRead).ToListAsync(ct);
        _db.InAppNotifications.RemoveRange(oldNotifs);

        var oldDel = await _db.NotificationDeliveries.Where(x => x.CreatedAt < now.AddDays(-90)).ToListAsync(ct);
        _db.NotificationDeliveries.RemoveRange(oldDel);

        await _db.SaveChangesAsync(ct);

        var orphanFiles = 0;
        var uploads = Path.Combine(Directory.GetCurrentDirectory(), "wwwroot", "uploads");
        if (Directory.Exists(uploads))
        {
            foreach (var file in Directory.EnumerateFiles(uploads, "*", SearchOption.AllDirectories))
            {
                try
                {
                    if (File.GetLastWriteTimeUtc(file) < now.AddDays(-180))
                    {
                        File.Delete(file);
                        orphanFiles++;
                    }
                }
                catch (Exception ex)
                {
                    _logger.LogWarning(ex, "Could not delete {File}", file);
                }
            }
        }

        return new MaintenanceResultDto(tokens.Count, oldNotifs.Count, oldDel.Count, orphanFiles);
    }
}

public class PlatformHostedService : BackgroundService
{
    private readonly IServiceScopeFactory _scopeFactory;
    private readonly ILogger<PlatformHostedService> _logger;

    public PlatformHostedService(IServiceScopeFactory scopeFactory, ILogger<PlatformHostedService> logger)
    {
        _scopeFactory = scopeFactory;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                using var scope = _scopeFactory.CreateScope();
                var reports = scope.ServiceProvider.GetRequiredService<IScheduledReportService>();
                var maintenance = scope.ServiceProvider.GetRequiredService<IMaintenanceService>();
                var reportCount = await reports.ProcessDueAsync(stoppingToken);
                var maint = await maintenance.RunAsync(stoppingToken);
                if (reportCount > 0 || maint.RefreshTokensRemoved > 0)
                {
                    _logger.LogInformation("Platform job reports={Reports} tokens={Tokens} notifs={Notifs}",
                        reportCount, maint.RefreshTokensRemoved, maint.NotificationsPurged);
                }
            }
            catch (Exception ex)
            {
                _logger.LogError(ex, "Platform hosted service failed");
            }

            await Task.Delay(TimeSpan.FromHours(1), stoppingToken);
        }
    }
}
