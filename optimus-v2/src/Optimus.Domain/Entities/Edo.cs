using Optimus.Domain.Common;
using Optimus.Domain.Enums;

namespace Optimus.Domain.Entities;

/// <summary>
/// Electronic Delivery Order / Container Release Order (eDO/CRO) — single document type.
/// </summary>
public class ElectronicDeliveryOrder : BaseEntity
{
    public string EdoNumber { get; set; } = string.Empty;
    public Guid ManifestId { get; set; }
    public Manifest Manifest { get; set; } = null!;
    public Guid ShippingLineId { get; set; }
    public ShippingLine ShippingLine { get; set; } = null!;
    public string? ContainerNumber { get; set; }
    public decimal? FeeAmount { get; set; }
    public string? PdfPath { get; set; }
    public string? QrPayload { get; set; }
    public string? QrImagePath { get; set; }
    public EdoStatus Status { get; set; } = EdoStatus.PendingRelease;
    public Guid GeneratedById { get; set; }
    public User GeneratedBy { get; set; } = null!;
    public DateTime GeneratedAt { get; set; } = DateTime.UtcNow;
    public Guid? ReleasedById { get; set; }
    public User? ReleasedBy { get; set; }
    public DateTime? ReleasedAt { get; set; }
    public DateTime? ExpiresAt { get; set; }
    public int? ExpiredDays { get; set; }
    public string? CyLocation { get; set; }
    public string? AdditionalNotes { get; set; }
    public string? RejectionReason { get; set; }
    public int Version { get; set; } = 1;
    public Guid? PreviousVersionId { get; set; }
    public ElectronicDeliveryOrder? PreviousVersion { get; set; }
    public string? VerificationToken { get; set; }

    public ICollection<EdoVersion> Versions { get; set; } = new List<EdoVersion>();
    public ICollection<EdoReleaseHistory> ReleaseHistory { get; set; } = new List<EdoReleaseHistory>();
    public ICollection<EdoAccessLog> AccessLogs { get; set; } = new List<EdoAccessLog>();
    public ICollection<EdoPayment> Payments { get; set; } = new List<EdoPayment>();
}

public class EdoVersion : BaseEntity
{
    public Guid EdoId { get; set; }
    public ElectronicDeliveryOrder Edo { get; set; } = null!;
    public int VersionNumber { get; set; }
    public string? PdfPath { get; set; }
    public string EdoNumber { get; set; } = string.Empty;
    public EdoStatus Status { get; set; }
    public Guid? CreatedById { get; set; }
    public User? CreatedBy { get; set; }
    public DateTime? ExpiresAt { get; set; }
    public string? CyLocation { get; set; }
    public string? Notes { get; set; }
    public bool IsCurrent { get; set; }
}

public class EdoPayment : BaseEntity
{
    public Guid ManifestId { get; set; }
    public Manifest Manifest { get; set; } = null!;
    public Guid? EdoId { get; set; }
    public ElectronicDeliveryOrder? Edo { get; set; }
    public Guid ShippingLineId { get; set; }
    public ShippingLine ShippingLine { get; set; } = null!;
    public decimal Amount { get; set; }
    public string Currency { get; set; } = "PHP";
    public string? ReceiptFilePath { get; set; }
    public string? OfficialReceiptPath { get; set; }
    public PaymentStatus Status { get; set; } = PaymentStatus.PendingValidation;
    public Guid SubmittedById { get; set; }
    public User SubmittedBy { get; set; } = null!;
    public Guid? ValidatedById { get; set; }
    public User? ValidatedBy { get; set; }
    public DateTime? ValidatedAt { get; set; }
    public string? RejectionReason { get; set; }
    public int Version { get; set; } = 1;
}

public class EdoRenewalRequest : BaseEntity
{
    public Guid ExpiredEdoId { get; set; }
    public ElectronicDeliveryOrder ExpiredEdo { get; set; } = null!;
    public Guid? NewEdoId { get; set; }
    public ElectronicDeliveryOrder? NewEdo { get; set; }
    public Guid RequestedById { get; set; }
    public User RequestedBy { get; set; } = null!;
    public DateTime RequestedAt { get; set; } = DateTime.UtcNow;
    public DateTime EmptyContainerReturnDate { get; set; }
    public int OverdueDays { get; set; }
    public decimal DetentionChargeAmount { get; set; }
    public RenewalRequestStatus Status { get; set; } = RenewalRequestStatus.PendingReview;
    public Guid? DetentionBillingId { get; set; }
    public Billing? DetentionBilling { get; set; }
    public bool PaymentVerified { get; set; }
    public DateTime? PaymentVerifiedAt { get; set; }
    public Guid? PaymentVerifiedById { get; set; }
    public User? PaymentVerifiedBy { get; set; }
    public string? AdditionalNotes { get; set; }
    public DateTime? CompletedAt { get; set; }
}

public class EdoReleaseHistory : BaseEntity
{
    public Guid EdoId { get; set; }
    public ElectronicDeliveryOrder Edo { get; set; } = null!;
    public EdoStatus FromStatus { get; set; }
    public EdoStatus ToStatus { get; set; }
    public Guid ActorId { get; set; }
    public User Actor { get; set; } = null!;
    public string? RejectionReason { get; set; }
}

public class EdoAccessLog : BaseEntity
{
    public Guid EdoId { get; set; }
    public ElectronicDeliveryOrder Edo { get; set; } = null!;
    public Guid AccessedById { get; set; }
    public User AccessedBy { get; set; } = null!;
    public DateTime AccessedAt { get; set; } = DateTime.UtcNow;
    public string? IpAddress { get; set; }
    public string AccessResult { get; set; } = "granted";
}

public class GenerationSession : BaseEntity
{
    public string SessionId { get; set; } = string.Empty;
    public Guid ManifestId { get; set; }
    public Manifest Manifest { get; set; } = null!;
    public Guid InitiatedById { get; set; }
    public User InitiatedBy { get; set; } = null!;
    public GenerationSessionStatus Status { get; set; } = GenerationSessionStatus.InProgress;
    public int TotalItems { get; set; }
    public int CompletedItems { get; set; }
    public int FailedItems { get; set; }
    public string? CurrentItem { get; set; }
    public DateTime? ExpirationDate { get; set; }
    public string? FailuresJson { get; set; }
    public DateTime StartedAt { get; set; } = DateTime.UtcNow;
    public DateTime? CompletedAt { get; set; }
}

public class DocumentVerification : BaseEntity
{
    public string VerificationToken { get; set; } = string.Empty;
    public string DocumentType { get; set; } = "EDO";
    public string SubjectType { get; set; } = "ElectronicDeliveryOrder";
    public Guid SubjectId { get; set; }
    public string DocumentNumber { get; set; } = string.Empty;
    public string? SummaryJson { get; set; }
}
