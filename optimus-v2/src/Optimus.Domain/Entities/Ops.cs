using Optimus.Domain.Common;
using Optimus.Domain.Enums;

namespace Optimus.Domain.Entities;

public class FormConfiguration : BaseEntity
{
    public string Name { get; set; } = string.Empty;
    public FormConfigType Type { get; set; } = FormConfigType.Broker;
    public int Version { get; set; } = 1;
    public FormConfigStatus Status { get; set; } = FormConfigStatus.Draft;
    public string FieldsJson { get; set; } = """{"fields":[]}""";
    public DateTime? PublishedAt { get; set; }
    public Guid CreatedById { get; set; }
    public User CreatedBy { get; set; } = null!;
}

public class AccreditationSubmission : BaseEntity
{
    public Guid ApplicantId { get; set; }
    public User Applicant { get; set; } = null!;
    public Guid ShippingLineId { get; set; }
    public ShippingLine ShippingLine { get; set; } = null!;
    public Guid FormConfigurationId { get; set; }
    public FormConfiguration FormConfiguration { get; set; } = null!;
    public string SubmittedDataJson { get; set; } = "{}";
    public AccreditationStatus Status { get; set; } = AccreditationStatus.Pending;
    public Guid? EvaluatorId { get; set; }
    public User? Evaluator { get; set; }
    public Guid? FinalApproverId { get; set; }
    public User? FinalApprover { get; set; }
    public DateTime SubmittedAt { get; set; } = DateTime.UtcNow;
    public DateTime? EvaluatedAt { get; set; }
    public DateTime? ApprovedAt { get; set; }
    public string? DenialReason { get; set; }
    public string? ComplianceNotes { get; set; }
    public string? ComplianceFieldIdsJson { get; set; }
}

public class BrokerTransferRequest : BaseEntity
{
    public Guid ManifestId { get; set; }
    public Manifest Manifest { get; set; } = null!;
    public Guid ConsigneeId { get; set; }
    public Consignee Consignee { get; set; } = null!;
    public Guid OldBrokerId { get; set; }
    public Broker OldBroker { get; set; } = null!;
    public Guid NewBrokerId { get; set; }
    public Broker NewBroker { get; set; } = null!;
    public string Reason { get; set; } = string.Empty;
    public string? TransferLetterPath { get; set; }
    public TransferRequestStatus Status { get; set; } = TransferRequestStatus.Pending;
    public Guid RequestedById { get; set; }
    public User RequestedBy { get; set; } = null!;
    public DateTime RequestedAt { get; set; } = DateTime.UtcNow;
    public Guid? ReviewedById { get; set; }
    public User? ReviewedBy { get; set; }
    public DateTime? ReviewedAt { get; set; }
    public string? ReviewNotes { get; set; }
}

public class SuspensionAppeal : BaseEntity
{
    public Guid UserId { get; set; }
    public User User { get; set; } = null!;
    public string AppealLetter { get; set; } = string.Empty;
    public string? AttachmentsJson { get; set; }
    public AppealStatus Status { get; set; } = AppealStatus.Pending;
    public DateTime SubmittedAt { get; set; } = DateTime.UtcNow;
    public Guid? ReviewedById { get; set; }
    public User? ReviewedBy { get; set; }
    public DateTime? ReviewedAt { get; set; }
    public string? ReviewNotes { get; set; }
}

public class RepositioningRequest : BaseEntity
{
    public string RequestNumber { get; set; } = string.Empty;
    public Guid ShippingLineId { get; set; }
    public ShippingLine ShippingLine { get; set; } = null!;
    public RepositioningRequestType RequestType { get; set; } = RepositioningRequestType.Repositioning;
    public Guid SourceTerminalId { get; set; }
    public Terminal SourceTerminal { get; set; } = null!;
    public Guid DestinationTerminalId { get; set; }
    public Terminal DestinationTerminal { get; set; } = null!;
    public string Purpose { get; set; } = string.Empty;
    public string? RequestLetterPath { get; set; }
    public int ContainerCount { get; set; }
    public RepositioningStatus Status { get; set; } = RepositioningStatus.Pending;
    public Guid RequestedById { get; set; }
    public User RequestedBy { get; set; } = null!;
    public DateTime RequestedAt { get; set; } = DateTime.UtcNow;
    public Guid? ReviewedById { get; set; }
    public User? ReviewedBy { get; set; }
    public DateTime? ReviewedAt { get; set; }
    public string? ReviewNotes { get; set; }
    public DateTime? CompletedAt { get; set; }

    public ICollection<RepositioningRequestItem> Items { get; set; } = new List<RepositioningRequestItem>();
}

public class RepositioningRequestItem : BaseEntity
{
    public Guid RepositioningRequestId { get; set; }
    public RepositioningRequest RepositioningRequest { get; set; } = null!;
    public Guid ContainerId { get; set; }
    public Container Container { get; set; } = null!;
    public int DwellTimeDays { get; set; }
    public DateTime? DischargeDate { get; set; }
}

public class WelcomeContent : BaseEntity
{
    public string Audience { get; set; } = "Consignee";
    public string Title { get; set; } = string.Empty;
    public string BodyMarkdown { get; set; } = string.Empty;
    public string StepsJson { get; set; } = "[]";
    public bool IsActive { get; set; } = true;
}
