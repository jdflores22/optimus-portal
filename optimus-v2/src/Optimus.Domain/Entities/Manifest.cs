using Optimus.Domain.Common;
using Optimus.Domain.Enums;

namespace Optimus.Domain.Entities;

public class Manifest : BaseEntity
{
    public string ManifestNumber { get; set; } = string.Empty;
    public Guid ShippingLineId { get; set; }
    public ShippingLine ShippingLine { get; set; } = null!;
    public Guid? ConsigneeId { get; set; }
    public Consignee? Consignee { get; set; }
    public Guid? BrokerId { get; set; }
    public Broker? Broker { get; set; }
    public string? VesselName { get; set; }
    public string? VoyageNumber { get; set; }
    public DateTime? ArrivalDate { get; set; }
    public string? BlNumber { get; set; }
    public string? BlFilePath { get; set; }
    public string? BlPdfPath { get; set; }
    public string? ManifestFilePath { get; set; }
    public WorkflowState WorkflowState { get; set; } = WorkflowState.ManifestUploaded;
    public Guid CreatedById { get; set; }
    public User CreatedBy { get; set; } = null!;

    public Noa? Noa { get; set; }
    public Billing? Billing { get; set; }
    public ICollection<Payment> Payments { get; set; } = new List<Payment>();
    public ICollection<WorkflowStateHistory> StateHistory { get; set; } = new List<WorkflowStateHistory>();
}

public class Noa : BaseEntity
{
    public string NoaNumber { get; set; } = string.Empty;
    public string? BlNumber { get; set; }
    public string? VesselName { get; set; }
    public DateTime? Eta { get; set; }
    public string? PortLocation { get; set; }
    public Guid? ConsigneeId { get; set; }
    public Consignee? Consignee { get; set; }
    public Guid CreatedById { get; set; }
    public User CreatedBy { get; set; } = null!;
    public string? PdfPath { get; set; }
    public Guid ManifestId { get; set; }
    public Manifest Manifest { get; set; } = null!;
}

public class Billing : BaseEntity
{
    public Guid ManifestId { get; set; }
    public Manifest Manifest { get; set; } = null!;
    public string BillingType { get; set; } = "manifest";
    public decimal FreightCharges { get; set; }
    public decimal ThcCharges { get; set; }
    public decimal AdditionalCharges { get; set; }
    public decimal TotalAmount { get; set; }
    public string Currency { get; set; } = "USD";
    public decimal? ExchangeRate { get; set; }
    public decimal? TotalAmountPhp { get; set; }
    public string? PdfPath { get; set; }
    public Guid GeneratedById { get; set; }
    public User GeneratedBy { get; set; } = null!;
    public int Version { get; set; } = 1;
}

public class Payment : BaseEntity
{
    public Guid ManifestId { get; set; }
    public Manifest Manifest { get; set; } = null!;
    public Guid ShippingLineId { get; set; }
    public ShippingLine ShippingLine { get; set; } = null!;
    public PaymentType PaymentType { get; set; }
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

public class PaymentFeeConfiguration : BaseEntity
{
    public string FeeType { get; set; } = "manifest_access";
    public decimal Amount { get; set; }
    public Guid ConfiguredById { get; set; }
    public User ConfiguredBy { get; set; } = null!;
    public decimal? PreviousAmount { get; set; }
    public bool IsActive { get; set; } = true;
    public string? QrCodePath { get; set; }
}

public class WorkflowStateHistory : BaseEntity
{
    public Guid ManifestId { get; set; }
    public Manifest Manifest { get; set; } = null!;
    public WorkflowState FromState { get; set; }
    public WorkflowState ToState { get; set; }
    public Guid ActorId { get; set; }
    public User Actor { get; set; } = null!;
    public string ActorRole { get; set; } = string.Empty;
    public string? TransitionReason { get; set; }
}

public class ActivityLog : BaseEntity
{
    public Guid? ActorId { get; set; }
    public User? Actor { get; set; }
    public string Action { get; set; } = string.Empty;
    public string EntityType { get; set; } = string.Empty;
    public Guid? EntityId { get; set; }
    public string? Details { get; set; }
}

public class BulkImportJob : BaseEntity
{
    public string FileName { get; set; } = string.Empty;
    public string Status { get; set; } = "pending";
    public int TotalRows { get; set; }
    public int ProcessedRows { get; set; }
    public int SuccessCount { get; set; }
    public int ErrorCount { get; set; }
    public string? ErrorLog { get; set; }
    public Guid CreatedById { get; set; }
    public User CreatedBy { get; set; } = null!;
    public Guid ShippingLineId { get; set; }
}
