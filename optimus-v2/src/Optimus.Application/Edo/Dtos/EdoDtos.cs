using Optimus.Domain.Enums;

namespace Optimus.Application.Edo.Dtos;

public record EdoDto(
    Guid Id,
    string EdoNumber,
    Guid ManifestId,
    string ManifestNumber,
    Guid ShippingLineId,
    string? ContainerNumber,
    string Status,
    decimal? FeeAmount,
    string? PdfPath,
    string? QrImagePath,
    string? VerificationToken,
    DateTime GeneratedAt,
    DateTime? ReleasedAt,
    DateTime? ExpiresAt,
    string? CyLocation,
    string? RejectionReason,
    int Version,
    string? CurrentPaymentStatus = null,
    DateTime? PaymentSubmittedAt = null,
    string? ReleasedByName = null,
    DateTime? PaymentValidatedAt = null,
    string? PaymentValidatedByName = null);

public record GenerateEdoRequest(
    Guid ManifestId,
    string? ContainerNumber,
    DateTime? ExpiresAt,
    string? CyLocation,
    string? AdditionalNotes,
    bool RequirePayment = true);

public record BatchGenerateEdoRequest(
    Guid ManifestId,
    IReadOnlyList<string> ContainerNumbers,
    DateTime? ExpiresAt,
    string? CyLocation);

public record GenerationSessionDto(
    Guid Id,
    string SessionId,
    Guid ManifestId,
    string Status,
    int TotalItems,
    int CompletedItems,
    int FailedItems,
    string? CurrentItem,
    DateTime StartedAt,
    DateTime? CompletedAt);

public record ReleaseEdoRequest(bool Approve, string? RejectionReason);

public record SubmitEdoPaymentRequest(decimal Amount, string Currency);

public record EdoPaymentDto(
    Guid Id,
    Guid ManifestId,
    Guid? EdoId,
    string? EdoNumber,
    decimal Amount,
    string Currency,
    string Status,
    string? ReceiptFilePath,
    string? OfficialReceiptPath,
    string? RejectionReason,
    DateTime CreatedAt,
    string? ManifestNumber = null,
    string? ContainerNumber = null,
    string? EdoStatus = null,
    string? SubmittedByName = null,
    DateTime? ValidatedAt = null,
    string? ValidatedByName = null);

public record EdoReleaseQueueItemDto(
    Guid EdoId,
    string EdoNumber,
    string Status,
    Guid ManifestId,
    string ManifestNumber,
    string? ContainerNumber,
    string? BrokerName,
    string? ConsigneeName,
    DateTime GeneratedAt,
    Guid? PaymentId,
    string? PaymentStatus,
    decimal? PaymentAmount,
    string? PaymentCurrency,
    DateTime? PaymentSubmittedAt,
    string? SubmittedByName);

public record EdoReleaseQueueDto(
    IReadOnlyList<EdoReleaseQueueItemDto> Items,
    int Total,
    int PendingValidation,
    int ReadyToRelease,
    int AwaitingPayment);

public record EdoReleaseRecordDto(
    Guid EdoId,
    string EdoNumber,
    string Status,
    Guid ManifestId,
    string ManifestNumber,
    string? ContainerNumber,
    string? BrokerName,
    string? ConsigneeName,
    DateTime? ReleasedAt,
    string? ReleasedByName,
    decimal? PaymentAmount,
    string? PaymentCurrency,
    DateTime? PaymentValidatedAt,
    string? PaymentValidatedByName);

public record ValidateEdoPaymentRequest(bool Approve, string? RejectionReason);

public record CreateRenewalRequest(
    Guid ExpiredEdoId,
    DateTime EmptyContainerReturnDate,
    string? AdditionalNotes);

public record RenewalDto(
    Guid Id,
    Guid ExpiredEdoId,
    string ExpiredEdoNumber,
    Guid? NewEdoId,
    string Status,
    int OverdueDays,
    decimal DetentionChargeAmount,
    bool PaymentVerified,
    DateTime RequestedAt,
    DateTime? CompletedAt);

public record ReviewRenewalRequest(bool Approve, string? Notes);

public record DocumentVerifyDto(
    bool Valid,
    string? DocumentType,
    string? DocumentNumber,
    string? Status,
    string? ManifestNumber,
    DateTime? GeneratedAt,
    DateTime? ExpiresAt,
    string? Message);

public record UnlockEdoRequest(DateTime? NewExpiresAt, string? Notes);

public record EdoGenerationContainerDto(
    Guid? ContainerId,
    string ContainerNumber,
    string? ContainerSize,
    string? ContainerType,
    Guid ManifestId,
    string ManifestNumber,
    string? BrokerName,
    string? ConsigneeName,
    string? ShippingLineName,
    DateTime? PaymentVerifiedAt,
    bool HasEdo,
    Guid? EdoId,
    string? EdoNumber,
    string? EdoStatus,
    DateTime? EdoGeneratedAt,
    DateTime? EdoExpiresAt);

public record EdoGenerationGroupDto(
    Guid ManifestId,
    string ManifestNumber,
    string BrokerName,
    string ConsigneeName,
    string? ShippingLineName,
    int PendingCount,
    int EdoCountInManifest,
    int TotalInManifest,
    IReadOnlyList<EdoGenerationContainerDto> PendingContainers);

public record EdoGenerationQueueDto(
    IReadOnlyList<EdoGenerationGroupDto> PendingGroups,
    IReadOnlyList<EdoGenerationContainerDto> Generated,
    int TotalEligible,
    int PendingCount,
    int GeneratedCount);
