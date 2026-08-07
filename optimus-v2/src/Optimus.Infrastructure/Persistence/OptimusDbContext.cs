using Microsoft.EntityFrameworkCore;
using Optimus.Domain.Entities;

namespace Optimus.Infrastructure.Persistence;

public class OptimusDbContext : DbContext
{
    public OptimusDbContext(DbContextOptions<OptimusDbContext> options) : base(options)
    {
    }

    public DbSet<User> Users => Set<User>();
    public DbSet<Broker> Brokers => Set<Broker>();
    public DbSet<Consignee> Consignees => Set<Consignee>();
    public DbSet<StaffUser> StaffUsers => Set<StaffUser>();
    public DbSet<TerminalTeamUser> TerminalTeamUsers => Set<TerminalTeamUser>();
    public DbSet<Trucker> Truckers => Set<Trucker>();
    public DbSet<RefreshToken> RefreshTokens => Set<RefreshToken>();
    public DbSet<ShippingLine> ShippingLines => Set<ShippingLine>();
    public DbSet<ShippingLineConfiguration> ShippingLineConfigurations => Set<ShippingLineConfiguration>();
    public DbSet<RolePermissionConfiguration> RolePermissionConfigurations => Set<RolePermissionConfiguration>();
    public DbSet<PendingUser> PendingUsers => Set<PendingUser>();
    public DbSet<ReferralCode> ReferralCodes => Set<ReferralCode>();
    public DbSet<ConsigneeBrokerRelationship> ConsigneeBrokerRelationships => Set<ConsigneeBrokerRelationship>();
    public DbSet<UserShippingLinePreference> UserShippingLinePreferences => Set<UserShippingLinePreference>();
    public DbSet<Manifest> Manifests => Set<Manifest>();
    public DbSet<Noa> Noas => Set<Noa>();
    public DbSet<Billing> Billings => Set<Billing>();
    public DbSet<Payment> Payments => Set<Payment>();
    public DbSet<PaymentFeeConfiguration> PaymentFeeConfigurations => Set<PaymentFeeConfiguration>();
    public DbSet<WorkflowStateHistory> WorkflowStateHistories => Set<WorkflowStateHistory>();
    public DbSet<ActivityLog> ActivityLogs => Set<ActivityLog>();
    public DbSet<BulkImportJob> BulkImportJobs => Set<BulkImportJob>();
    public DbSet<ElectronicDeliveryOrder> ElectronicDeliveryOrders => Set<ElectronicDeliveryOrder>();
    public DbSet<EdoVersion> EdoVersions => Set<EdoVersion>();
    public DbSet<EdoPayment> EdoPayments => Set<EdoPayment>();
    public DbSet<EdoRenewalRequest> EdoRenewalRequests => Set<EdoRenewalRequest>();
    public DbSet<EdoReleaseHistory> EdoReleaseHistories => Set<EdoReleaseHistory>();
    public DbSet<EdoAccessLog> EdoAccessLogs => Set<EdoAccessLog>();
    public DbSet<GenerationSession> GenerationSessions => Set<GenerationSession>();
    public DbSet<DocumentVerification> DocumentVerifications => Set<DocumentVerification>();
    public DbSet<Terminal> Terminals => Set<Terminal>();
    public DbSet<TerminalSlot> TerminalSlots => Set<TerminalSlot>();
    public DbSet<ContainerType> ContainerTypes => Set<ContainerType>();
    public DbSet<ContainerSize> ContainerSizes => Set<ContainerSize>();
    public DbSet<ShippingLineTerminalAllocation> ShippingLineTerminalAllocations => Set<ShippingLineTerminalAllocation>();
    public DbSet<Container> Containers => Set<Container>();
    public DbSet<ContainerAllocationAudit> ContainerAllocationAudits => Set<ContainerAllocationAudit>();
    public DbSet<DwellTimeConfiguration> DwellTimeConfigurations => Set<DwellTimeConfiguration>();
    public DbSet<DwellTimeEvent> DwellTimeEvents => Set<DwellTimeEvent>();
    public DbSet<PreAdviceRequest> PreAdviceRequests => Set<PreAdviceRequest>();
    public DbSet<GeotagPhoto> GeotagPhotos => Set<GeotagPhoto>();
    public DbSet<InAppNotification> InAppNotifications => Set<InAppNotification>();
    public DbSet<FormConfiguration> FormConfigurations => Set<FormConfiguration>();
    public DbSet<AccreditationSubmission> AccreditationSubmissions => Set<AccreditationSubmission>();
    public DbSet<BrokerTransferRequest> BrokerTransferRequests => Set<BrokerTransferRequest>();
    public DbSet<SuspensionAppeal> SuspensionAppeals => Set<SuspensionAppeal>();
    public DbSet<RepositioningRequest> RepositioningRequests => Set<RepositioningRequest>();
    public DbSet<RepositioningRequestItem> RepositioningRequestItems => Set<RepositioningRequestItem>();
    public DbSet<WelcomeContent> WelcomeContents => Set<WelcomeContent>();
    public DbSet<NotificationPreference> NotificationPreferences => Set<NotificationPreference>();
    public DbSet<PushSubscription> PushSubscriptions => Set<PushSubscription>();
    public DbSet<MessageTemplate> MessageTemplates => Set<MessageTemplate>();
    public DbSet<NotificationDelivery> NotificationDeliveries => Set<NotificationDelivery>();
    public DbSet<SystemSetting> SystemSettings => Set<SystemSetting>();
    public DbSet<RateLimitRule> RateLimitRules => Set<RateLimitRule>();
    public DbSet<DocumentTemplate> DocumentTemplates => Set<DocumentTemplate>();
    public DbSet<ScheduledReport> ScheduledReports => Set<ScheduledReport>();
    public DbSet<Region> Regions => Set<Region>();
    public DbSet<Province> Provinces => Set<Province>();
    public DbSet<City> Cities => Set<City>();
    public DbSet<Barangay> Barangays => Set<Barangay>();

    protected override void OnModelCreating(ModelBuilder modelBuilder)
    {
        modelBuilder.ApplyConfigurationsFromAssembly(typeof(OptimusDbContext).Assembly);
        base.OnModelCreating(modelBuilder);
    }

    public override Task<int> SaveChangesAsync(CancellationToken cancellationToken = default)
    {
        foreach (var entry in ChangeTracker.Entries<Domain.Common.BaseEntity>())
        {
            if (entry.State == EntityState.Modified)
            {
                entry.Entity.UpdatedAt = DateTime.UtcNow;
            }
        }

        return base.SaveChangesAsync(cancellationToken);
    }
}
