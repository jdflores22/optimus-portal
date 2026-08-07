namespace Optimus.Application.Ops.Dtos;

public record FormFieldDto(string Id, string Label, string Type, bool Required, int Order, string? OptionsJson);
public record FormConfigurationDto(
    Guid Id,
    string Name,
    string Type,
    int Version,
    string Status,
    string FieldsJson,
    DateTime? PublishedAt,
    DateTime CreatedAt);

public record UpsertFormRequest(string Name, string Type, string FieldsJson);
public record FormFieldsUpdateRequest(string FieldsJson);

public record AccreditationDto(
    Guid Id,
    Guid ApplicantId,
    string ApplicantName,
    string ApplicantRole,
    Guid ShippingLineId,
    string ShippingLineName,
    Guid FormConfigurationId,
    string Status,
    string SubmittedDataJson,
    string? DenialReason,
    string? ComplianceNotes,
    string? ComplianceFieldIdsJson,
    DateTime SubmittedAt,
    DateTime? EvaluatedAt,
    DateTime? ApprovedAt);

public record SubmitAccreditationRequest(string SubmittedDataJson, Guid? ShippingLineId = null);
public record EvaluatorActionRequest(string Action, string? Notes, string? ComplianceFieldIdsJson);
public record FinalApprovalRequest(bool Approve, string? Notes);

public record TransferDto(
    Guid Id,
    Guid ManifestId,
    string ManifestNumber,
    Guid ConsigneeId,
    string ConsigneeName,
    Guid OldBrokerId,
    string OldBrokerName,
    Guid NewBrokerId,
    string NewBrokerName,
    string Reason,
    string Status,
    string? TransferLetterPath,
    DateTime RequestedAt,
    string? ReviewNotes);

public record CreateTransferRequest(Guid ManifestId, Guid NewBrokerId, string Reason);
public record ReviewTransferRequest(bool Approve, string? Notes);

public record AppealDto(
    Guid Id,
    Guid UserId,
    string UserName,
    string AppealLetter,
    string? AttachmentsJson,
    string Status,
    DateTime SubmittedAt,
    string? ReviewNotes);

public record CreateAppealRequest(string AppealLetter);
public record ReviewAppealRequest(bool Approve, string? Notes);

public record RepositioningDto(
    Guid Id,
    string RequestNumber,
    Guid ShippingLineId,
    string ShippingLineName,
    string RequestType,
    Guid SourceTerminalId,
    string SourceTerminalName,
    string? SourceTerminalCode,
    Guid DestinationTerminalId,
    string DestinationTerminalName,
    string? DestinationTerminalCode,
    string Purpose,
    string? RequestLetterPath,
    int ContainerCount,
    string Status,
    DateTime RequestedAt,
    string? RequestedByEmail,
    DateTime? ReviewedAt,
    DateTime? CompletedAt,
    string? ReviewNotes,
    IReadOnlyList<RepositioningItemDto> Items);

public record RepositioningItemDto(
    Guid ContainerId,
    string ContainerNumber,
    int DwellTimeDays,
    DateTime? DischargeDate,
    string CurrentStatus);

public record RepositioningEligibleContainerDto(
    Guid Id,
    string ContainerNumber,
    string? SizeCode,
    string? TypeCode,
    string DepotName,
    int CurrentDwellDays,
    DateTime? DischargeDate);

public record CreateRepositioningRequest(
    Guid ShippingLineId,
    string RequestType,
    Guid SourceTerminalId,
    Guid DestinationTerminalId,
    string Purpose,
    IReadOnlyList<Guid> ContainerIds);
public record ReviewRepositioningRequest(bool Approve, string? Notes);

public record ReferralCodeDto(
    Guid Id,
    Guid ConsigneeId,
    string ConsigneeName,
    string Code,
    bool IsActive,
    int? MaxUses,
    int CurrentUses,
    DateTime? ExpiresAt);

public record GenerateReferralRequest(int? MaxUses, DateTime? ExpiresAt);
public record ApplyReferralRequest(string Code);

public record RelationshipDto(
    Guid Id,
    Guid ConsigneeId,
    string ConsigneeName,
    Guid BrokerId,
    string BrokerName,
    string Status,
    DateTime? SuspendedAt,
    string? SuspensionReason,
    string? BrokerEmail = null,
    string? BrokerBusinessAddress = null,
    DateTime? LinkedAt = null,
    bool BrokerIsActive = true);

public record SuspendBrokerRequest(string Reason);

public record WelcomeContentDto(
    Guid Id,
    string Audience,
    string Title,
    string BodyMarkdown,
    string StepsJson,
    IReadOnlyList<string> CompletedSteps);

public record UpsertWelcomeContentRequest(string Audience, string Title, string BodyMarkdown, string StepsJson);
public record CompleteOnboardingStepRequest(string StepId);

public record LocationItemDto(Guid Id, string Name, string? Code = null);
