using Optimus.Domain.Common;
using Optimus.Domain.Enums;

namespace Optimus.Domain.Entities;

public class Terminal : BaseEntity
{
    public string Name { get; set; } = string.Empty;
    public string Code { get; set; } = string.Empty;
    public TerminalIdentity Identity { get; set; } = TerminalIdentity.ContainerYard;
    public TerminalKind Kind { get; set; } = TerminalKind.Cy;
    public string? Location { get; set; }
    public string? Region { get; set; }
    public string? City { get; set; }
    public int DailyCapacity { get; set; } = 100;
    public bool IsActive { get; set; } = true;

    public ICollection<TerminalSlot> Slots { get; set; } = new List<TerminalSlot>();
    public ICollection<ShippingLineTerminalAllocation> Allocations { get; set; } = new List<ShippingLineTerminalAllocation>();
}

public class TerminalSlot : BaseEntity
{
    public Guid TerminalId { get; set; }
    public Terminal Terminal { get; set; } = null!;
    public DateOnly Date { get; set; }
    public int Capacity { get; set; }
    public int AssignedCount { get; set; }
    public SlotStatus Status { get; set; } = SlotStatus.Available;
}

public class ContainerType : BaseEntity
{
    public string Name { get; set; } = string.Empty;
    public string Code { get; set; } = string.Empty;
    public string? Description { get; set; }
    public bool IsActive { get; set; } = true;
}

public class ContainerSize : BaseEntity
{
    public string Name { get; set; } = string.Empty;
    public string Code { get; set; } = string.Empty;
    public decimal TeuValue { get; set; } = 1m;
    public string? Description { get; set; }
    public bool IsActive { get; set; } = true;
}

public class ShippingLineTerminalAllocation : BaseEntity
{
    public Guid ShippingLineId { get; set; }
    public ShippingLine ShippingLine { get; set; } = null!;
    public Guid TerminalId { get; set; }
    public Terminal Terminal { get; set; } = null!;
    public Guid? StaffUserId { get; set; }
    public User? StaffUser { get; set; }
    public int AllocatedCapacityTeu { get; set; }
    public int Capacity20Ft { get; set; }
    public int Capacity40Ft { get; set; }

    public ICollection<Container> Containers { get; set; } = new List<Container>();
}

public class Container : BaseEntity
{
    public string ContainerNumber { get; set; } = string.Empty;
    public Guid ShippingLineId { get; set; }
    public ShippingLine ShippingLine { get; set; } = null!;
    public Guid? ManifestId { get; set; }
    public Manifest? Manifest { get; set; }
    public Guid? ContainerTypeId { get; set; }
    public ContainerType? ContainerType { get; set; }
    public Guid? ContainerSizeId { get; set; }
    public ContainerSize? ContainerSize { get; set; }
    public ContainerStatus Status { get; set; } = ContainerStatus.Pending;
    public string? CurrentLocation { get; set; }
    public DateTime? ExpectedReturnDate { get; set; }

    public Guid? CyAllocationId { get; set; }
    public ShippingLineTerminalAllocation? CyAllocation { get; set; }
    public AllocationStatus AllocationStatus { get; set; } = AllocationStatus.None;
    public DateTime? AllocatedAt { get; set; }
    public DateTime? AllocationLockedAt { get; set; }

    public DateTime? TerminalArrivalDate { get; set; }
    public int CurrentDwellDays { get; set; }
    public DateTime? LastDwellCalculationAt { get; set; }
    public DateTime? DwellPausedAt { get; set; }
    public int TotalPausedDays { get; set; }
    public DateTime? NextNotificationDate { get; set; }
    public DateTime? AutomaticReturnDate { get; set; }
    public string? StackBay { get; set; }
    public string? StackRow { get; set; }
    public string? StackTier { get; set; }

    public ICollection<ContainerAllocationAudit> AllocationAudits { get; set; } = new List<ContainerAllocationAudit>();
    public ICollection<DwellTimeEvent> DwellEvents { get; set; } = new List<DwellTimeEvent>();
    public ICollection<PreAdviceRequest> PreAdviceRequests { get; set; } = new List<PreAdviceRequest>();
}

public class ContainerAllocationAudit : BaseEntity
{
    public Guid ContainerId { get; set; }
    public Container Container { get; set; } = null!;
    public Guid? PreviousAllocationId { get; set; }
    public Guid? NewAllocationId { get; set; }
    public Guid ChangedById { get; set; }
    public User ChangedBy { get; set; } = null!;
    public DateTime ChangedAt { get; set; } = DateTime.UtcNow;
    public string ChangeType { get; set; } = "initial";
    public string? Reason { get; set; }
    public string? MetadataJson { get; set; }
}

public class DwellTimeConfiguration : BaseEntity
{
    public int NotificationThresholdDays { get; set; } = 60;
    public int AutomaticReturnThresholdDays { get; set; } = 90;
    public string Timezone { get; set; } = "Asia/Manila";
    public bool EnableAutomaticReturns { get; set; } = true;
    public bool EnableNotifications { get; set; } = true;
    public bool IsActive { get; set; } = true;
}

public class DwellTimeEvent : BaseEntity
{
    public Guid ContainerId { get; set; }
    public Container Container { get; set; } = null!;
    public DwellEventType EventType { get; set; }
    public DateTime EventDate { get; set; } = DateTime.UtcNow;
    public int DwellDaysAtEvent { get; set; }
    public string? Reason { get; set; }
    public string? MetadataJson { get; set; }
    public Guid? TriggeredById { get; set; }
    public User? TriggeredBy { get; set; }
}

public class PreAdviceRequest : BaseEntity
{
    public Guid TruckerId { get; set; }
    public Trucker Trucker { get; set; } = null!;
    public Guid ContainerId { get; set; }
    public Container Container { get; set; } = null!;
    public Guid TerminalId { get; set; }
    public Terminal Terminal { get; set; } = null!;
    public Guid? AssignedSlotId { get; set; }
    public TerminalSlot? AssignedSlot { get; set; }
    public Guid? ShippingLineId { get; set; }
    public ShippingLine? ShippingLine { get; set; }
    public PreAdviceStatus Status { get; set; } = PreAdviceStatus.Pending;
    public Guid? VerifiedById { get; set; }
    public User? VerifiedBy { get; set; }
    public DateTime? VerifiedAt { get; set; }
    public string? RejectionReason { get; set; }
    public string? PaymentReference { get; set; }
    public bool PaymentVerified { get; set; }
    public string? QrCodePath { get; set; }
    public string? PackagePdfPath { get; set; }
    public string? EdoNumber { get; set; }
    public string? VerificationToken { get; set; }

    public ICollection<GeotagPhoto> GeotagPhotos { get; set; } = new List<GeotagPhoto>();
}

public class GeotagPhoto : BaseEntity
{
    public Guid PreAdviceRequestId { get; set; }
    public PreAdviceRequest PreAdviceRequest { get; set; } = null!;
    public string FilePath { get; set; } = string.Empty;
    public string? OriginalName { get; set; }
    public double? Latitude { get; set; }
    public double? Longitude { get; set; }
    public DateTime CapturedAt { get; set; } = DateTime.UtcNow;
    public bool IsVerified { get; set; }
    public string? VerificationNotes { get; set; }
}

public class InAppNotification : BaseEntity
{
    public Guid? UserId { get; set; }
    public User? User { get; set; }
    public string Title { get; set; } = string.Empty;
    public string Message { get; set; } = string.Empty;
    public string Category { get; set; } = "general";
    public string? SubjectType { get; set; }
    public Guid? SubjectId { get; set; }
    public bool IsRead { get; set; }
}
