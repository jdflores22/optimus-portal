using System.Text.Json;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;
using Optimus.Application.Auth.Interfaces;
using Optimus.Application.Platform.Dtos;
using Optimus.Application.Platform.Interfaces;
using Optimus.Application.Yard.Dtos;
using Optimus.Application.Yard.Interfaces;
using Optimus.Domain.Entities;
using Optimus.Infrastructure.Persistence;

namespace Optimus.Infrastructure.Platform;

public class LoggingSmsSender : ISmsSender
{
    private readonly ILogger<LoggingSmsSender> _logger;
    public LoggingSmsSender(ILogger<LoggingSmsSender> logger) => _logger = logger;
    public Task SendAsync(string toPhone, string body, CancellationToken ct = default)
    {
        _logger.LogInformation("SMS to {Phone}: {Body}", toPhone, body);
        return Task.CompletedTask;
    }
}

public class LoggingPushSender : IPushSender
{
    private readonly ILogger<LoggingPushSender> _logger;
    public LoggingPushSender(ILogger<LoggingPushSender> logger) => _logger = logger;
    public Task SendAsync(string endpoint, string p256dh, string auth, string title, string body, CancellationToken ct = default)
    {
        _logger.LogInformation("WebPush to {Endpoint}: {Title} — {Body}", endpoint, title, body);
        return Task.CompletedTask;
    }
}

public class EnhancedNotificationService : INotificationService
{
    private readonly OptimusDbContext _db;
    private readonly IEmailSender _email;
    private readonly ISmsSender _sms;
    private readonly IPushSender _push;
    private readonly IMessageTemplateService _templates;

    public EnhancedNotificationService(
        OptimusDbContext db,
        IEmailSender email,
        ISmsSender sms,
        IPushSender push,
        IMessageTemplateService templates)
    {
        _db = db;
        _email = email;
        _sms = sms;
        _push = push;
        _templates = templates;
    }

    public async Task NotifyAsync(Guid? userId, string title, string message, string category, string? subjectType, Guid? subjectId, CancellationToken ct = default)
    {
        NotificationPreference? prefs = null;
        if (userId.HasValue)
        {
            prefs = await _db.NotificationPreferences.AsNoTracking().FirstOrDefaultAsync(x => x.UserId == userId, ct);
            if (prefs is not null)
            {
                var muted = JsonSerializer.Deserialize<List<string>>(prefs.MutedCategoriesJson) ?? new List<string>();
                if (muted.Contains(category, StringComparer.OrdinalIgnoreCase))
                {
                    await LogDelivery(userId, "inapp", category, title, "skipped", "muted category", null, ct);
                    return;
                }
            }
        }

        Guid? notifId = null;
        if (prefs?.InAppEnabled != false)
        {
            var n = new InAppNotification
            {
                UserId = userId,
                Title = title,
                Message = message,
                Category = category,
                SubjectType = subjectType,
                SubjectId = subjectId
            };
            _db.InAppNotifications.Add(n);
            await _db.SaveChangesAsync(ct);
            notifId = n.Id;
            await LogDelivery(userId, "inapp", category, title, "sent", null, notifId, ct);
        }

        if (!userId.HasValue) return;
        var user = await _db.Users.AsNoTracking().FirstOrDefaultAsync(x => x.Id == userId, ct);
        if (user is null) return;

        if (prefs?.EmailEnabled != false)
        {
            try
            {
                var body = await _templates.RenderAsync($"notify.{category}", "email",
                    new Dictionary<string, string> { ["title"] = title, ["message"] = message, ["name"] = user.FullName }, ct);
                await _email.SendAsync(user.Email, title, string.IsNullOrWhiteSpace(body) ? message : body, ct);
                await LogDelivery(userId, "email", category, title, "sent", null, notifId, ct);
            }
            catch (Exception ex)
            {
                await LogDelivery(userId, "email", category, title, "failed", ex.Message, notifId, ct);
            }
        }

        if (prefs?.SmsEnabled == true)
        {
            try
            {
                var phone = await _db.Set<Trucker>().AsNoTracking()
                    .Where(x => x.Id == userId).Select(x => x.PhoneNumber).FirstOrDefaultAsync(ct);
                if (!string.IsNullOrWhiteSpace(phone))
                {
                    var smsBody = await _templates.RenderAsync($"notify.{category}", "sms",
                        new Dictionary<string, string> { ["title"] = title, ["message"] = message }, ct);
                    await _sms.SendAsync(phone!, string.IsNullOrWhiteSpace(smsBody) ? $"{title}: {message}" : smsBody, ct);
                    await LogDelivery(userId, "sms", category, title, "sent", null, notifId, ct);
                }
                else
                {
                    await LogDelivery(userId, "sms", category, title, "skipped", "no phone", notifId, ct);
                }
            }
            catch (Exception ex)
            {
                await LogDelivery(userId, "sms", category, title, "failed", ex.Message, notifId, ct);
            }
        }

        if (prefs?.PushEnabled != false)
        {
            var subs = await _db.PushSubscriptions.Where(x => x.UserId == userId).ToListAsync(ct);
            foreach (var sub in subs)
            {
                try
                {
                    await _push.SendAsync(sub.Endpoint, sub.P256dh, sub.Auth, title, message, ct);
                    sub.LastUsedAt = DateTime.UtcNow;
                    await LogDelivery(userId, "push", category, title, "sent", null, notifId, ct);
                }
                catch (Exception ex)
                {
                    await LogDelivery(userId, "push", category, title, "failed", ex.Message, notifId, ct);
                }
            }

            if (subs.Count > 0) await _db.SaveChangesAsync(ct);
        }
    }

    public async Task<IReadOnlyList<NotificationDto>> ListForUserAsync(Guid userId, CancellationToken ct = default)
    {
        var items = await _db.InAppNotifications.AsNoTracking()
            .Where(x => x.UserId == userId)
            .OrderByDescending(x => x.CreatedAt)
            .Take(100)
            .ToListAsync(ct);
        return items.Select(MapNotification).ToList();
    }

    public async Task<NotificationDto?> GetForUserAsync(Guid userId, Guid notificationId, CancellationToken ct = default)
    {
        var item = await _db.InAppNotifications.AsNoTracking()
            .FirstOrDefaultAsync(x => x.UserId == userId && x.Id == notificationId, ct);
        return item is null ? null : MapNotification(item);
    }

    private static NotificationDto MapNotification(InAppNotification x) =>
        new(x.Id, x.Title, x.Message, x.Category, x.IsRead, x.CreatedAt, x.SubjectType, x.SubjectId);

    public async Task MarkReadAsync(Guid userId, Guid? notificationId, CancellationToken ct = default)
    {
        var q = _db.InAppNotifications.Where(x => x.UserId == userId && !x.IsRead);
        if (notificationId.HasValue) q = q.Where(x => x.Id == notificationId);
        var items = await q.ToListAsync(ct);
        foreach (var n in items) n.IsRead = true;
        await _db.SaveChangesAsync(ct);
    }

    public async Task<NotificationPreferenceDto> GetPreferencesAsync(Guid userId, CancellationToken ct = default)
    {
        var prefs = await EnsurePrefsAsync(userId, ct);
        return MapPrefs(prefs);
    }

    public async Task<NotificationPreferenceDto> UpsertPreferencesAsync(Guid userId, UpsertNotificationPreferenceRequest request, CancellationToken ct = default)
    {
        var prefs = await EnsurePrefsAsync(userId, ct);
        prefs.InAppEnabled = request.InAppEnabled;
        prefs.EmailEnabled = request.EmailEnabled;
        prefs.SmsEnabled = request.SmsEnabled;
        prefs.PushEnabled = request.PushEnabled;
        prefs.MutedCategoriesJson = string.IsNullOrWhiteSpace(request.MutedCategoriesJson) ? "[]" : request.MutedCategoriesJson!;
        await _db.SaveChangesAsync(ct);
        return MapPrefs(prefs);
    }

    public async Task SubscribePushAsync(Guid userId, PushSubscribeRequest request, CancellationToken ct = default)
    {
        var existing = await _db.PushSubscriptions.FirstOrDefaultAsync(x => x.Endpoint == request.Endpoint, ct);
        if (existing is null)
        {
            _db.PushSubscriptions.Add(new PushSubscription
            {
                UserId = userId,
                Endpoint = request.Endpoint,
                P256dh = request.P256dh,
                Auth = request.Auth,
                UserAgent = request.UserAgent,
                LastUsedAt = DateTime.UtcNow
            });
        }
        else
        {
            existing.UserId = userId;
            existing.P256dh = request.P256dh;
            existing.Auth = request.Auth;
            existing.UserAgent = request.UserAgent;
            existing.LastUsedAt = DateTime.UtcNow;
        }

        await _db.SaveChangesAsync(ct);
    }

    public async Task UnsubscribePushAsync(Guid userId, string endpoint, CancellationToken ct = default)
    {
        var items = await _db.PushSubscriptions.Where(x => x.UserId == userId && x.Endpoint == endpoint).ToListAsync(ct);
        _db.PushSubscriptions.RemoveRange(items);
        await _db.SaveChangesAsync(ct);
    }

    public async Task<NotificationMetricsDto> GetMetricsAsync(CancellationToken ct = default)
    {
        var recent = await _db.NotificationDeliveries.AsNoTracking()
            .OrderByDescending(x => x.CreatedAt).Take(50).ToListAsync(ct);
        var sent = await _db.NotificationDeliveries.CountAsync(x => x.Status == "sent", ct);
        var failed = await _db.NotificationDeliveries.CountAsync(x => x.Status == "failed", ct);
        var skipped = await _db.NotificationDeliveries.CountAsync(x => x.Status == "skipped", ct);
        var unread = await _db.InAppNotifications.CountAsync(x => !x.IsRead, ct);
        return new NotificationMetricsDto(sent, failed, skipped, unread,
            recent.Select(x => new NotificationDeliveryDto(x.Id, x.UserId, x.Channel, x.Category, x.Title, x.Status, x.Error, x.CreatedAt)).ToList());
    }

    private async Task<NotificationPreference> EnsurePrefsAsync(Guid userId, CancellationToken ct)
    {
        var prefs = await _db.NotificationPreferences.FirstOrDefaultAsync(x => x.UserId == userId, ct);
        if (prefs is not null) return prefs;
        prefs = new NotificationPreference { UserId = userId };
        _db.NotificationPreferences.Add(prefs);
        await _db.SaveChangesAsync(ct);
        return prefs;
    }

    private async Task LogDelivery(Guid? userId, string channel, string category, string title, string status, string? error, Guid? notifId, CancellationToken ct)
    {
        _db.NotificationDeliveries.Add(new NotificationDelivery
        {
            UserId = userId,
            Channel = channel,
            Category = category,
            Title = title,
            Status = status,
            Error = error,
            NotificationId = notifId
        });
        await _db.SaveChangesAsync(ct);
    }

    private static NotificationPreferenceDto MapPrefs(NotificationPreference x) =>
        new(x.Id, x.InAppEnabled, x.EmailEnabled, x.SmsEnabled, x.PushEnabled, x.MutedCategoriesJson);
}

public class MessageTemplateService : IMessageTemplateService
{
    private readonly OptimusDbContext _db;
    public MessageTemplateService(OptimusDbContext db) => _db = db;

    public async Task<IReadOnlyList<MessageTemplateDto>> ListAsync(string? channel, CancellationToken ct = default)
    {
        var q = _db.MessageTemplates.AsNoTracking().AsQueryable();
        if (!string.IsNullOrWhiteSpace(channel)) q = q.Where(x => x.Channel == channel);
        var items = await q.OrderBy(x => x.Key).ToListAsync(ct);
        return items.Select(Map).ToList();
    }

    public async Task<MessageTemplateDto> UpsertAsync(UpsertMessageTemplateRequest request, CancellationToken ct = default)
    {
        var entity = await _db.MessageTemplates.FirstOrDefaultAsync(x => x.Key == request.Key && x.Channel == request.Channel, ct);
        if (entity is null)
        {
            entity = new MessageTemplate { Key = request.Key, Channel = request.Channel };
            _db.MessageTemplates.Add(entity);
        }

        entity.Name = request.Name;
        entity.Subject = request.Subject;
        entity.Body = request.Body;
        entity.IsActive = request.IsActive;
        await _db.SaveChangesAsync(ct);
        return Map(entity);
    }

    public async Task<string> RenderAsync(string key, string channel, IDictionary<string, string> tokens, CancellationToken ct = default)
    {
        var tpl = await _db.MessageTemplates.AsNoTracking()
            .FirstOrDefaultAsync(x => x.Key == key && x.Channel == channel && x.IsActive, ct);
        if (tpl is null) return string.Empty;
        var body = tpl.Body;
        foreach (var kv in tokens)
        {
            body = body.Replace("{{" + kv.Key + "}}", kv.Value, StringComparison.OrdinalIgnoreCase);
        }

        return body;
    }

    private static MessageTemplateDto Map(MessageTemplate x) =>
        new(x.Id, x.Key, x.Channel, x.Name, x.Subject, x.Body, x.IsActive);
}
