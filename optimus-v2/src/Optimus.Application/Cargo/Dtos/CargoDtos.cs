using Optimus.Domain.Enums;

namespace Optimus.Application.Cargo.Dtos;

public record ManifestDto(
    Guid Id,
    string ManifestNumber,
    Guid ShippingLineId,
    string? ShippingLineName,
    Guid? ConsigneeId,
    string? ConsigneeName,
    Guid? BrokerId,
    string? BrokerName,
    string? VesselName,
    string? VoyageNumber,
    DateTime? ArrivalDate,
    string? BlNumber,
    string? BlFilePath,
    string? BlPdfPath,
    string? ManifestFilePath,
    string WorkflowState,
    Guid? NoaId,
    string? NoaNumber,
    string? NoaPdfPath,
    string? PortLocation,
    Guid? BillingId,
    decimal? BillingTotal,
    string? BillingCurrency,
    string? BillingPdfPath,
    decimal? BillingFreightCharges,
    decimal? BillingThcCharges,
    decimal? BillingAdditionalCharges,
    decimal? BillingExchangeRate,
    decimal? BillingTotalPhp,
    DateTime CreatedAt);

public record GenerateBlRequest(string ManifestBlNumber, DateTime ActualArrivalDate);

public record CreateManifestRequest(
    string ManifestNumber,
    Guid ShippingLineId,
    string? VesselName,
    string? VoyageNumber,
    DateTime? ArrivalDate,
    string? BlNumber,
    Guid? ConsigneeId = null,
    Guid? BrokerId = null,
    string? PortLocation = null);

public record DeclareConsigneeRequest(Guid ConsigneeId, Guid? BrokerId);

public record AssignBrokerRequest(Guid BrokerId);

public record AccreditedConsigneeOptionDto(
    Guid Id,
    string BusinessName,
    string FullName,
    string Email);

public record ConsigneeBrokerOptionDto(
    Guid Id,
    string FullName,
    string Email,
    string? BusinessAddress);

public record BillingChargeLineDto(string Description, decimal Amount);

public record GenerateBillingRequest(
    decimal FreightCharges,
    decimal ThcCharges,
    decimal AdditionalCharges,
    string Currency,
    decimal? ExchangeRate = null,
    IReadOnlyList<BillingChargeLineDto>? AdditionalChargeLines = null);

public record SubmitPaymentRequest(
    PaymentType PaymentType,
    decimal Amount,
    string Currency);

public record ValidatePaymentRequest(bool Approve, string? RejectionReason);

public record PaymentDto(
    Guid Id,
    Guid ManifestId,
    string ManifestNumber,
    string PaymentType,
    decimal Amount,
    string Currency,
    string Status,
    string? ReceiptFilePath,
    string? OfficialReceiptPath,
    string? RejectionReason,
    Guid SubmittedById,
    string SubmittedByName,
    DateTime CreatedAt,
    DateTime? ValidatedAt,
    int Version = 1,
    string? ValidatedByName = null);

public record FinalPaymentListItemDto(
    Guid Id,
    Guid ManifestId,
    string ManifestNumber,
    string? ConsigneeName,
    decimal Amount,
    string Currency,
    decimal? BillingAmount,
    string? BillingCurrency,
    string Status,
    string SubmittedByName,
    string? SubmittedByEmail,
    DateTime CreatedAt,
    DateTime? ValidatedAt,
    string? ValidatedByName,
    int Version);

public record FinalPaymentStatsDto(
    int Pending,
    int Approved,
    int Rejected,
    int Total,
    int Discrepancies);

public record FinalPaymentListResponse(
    IReadOnlyList<FinalPaymentListItemDto> Items,
    FinalPaymentStatsDto Stats,
    int Page,
    int Limit,
    int Total,
    int Pages,
    int Start,
    int End);

public record PaymentFeeDto(Guid Id, string FeeType, decimal Amount, bool IsActive, string? QrCodePath);

public record UpsertPaymentFeeRequest(string FeeType, decimal Amount);

public record BulkImportResultDto(Guid JobId, string Status, int TotalRows, int SuccessCount, int ErrorCount, string? ErrorLog);

public record ExchangeRateDto(string Base, string Quote, decimal Rate, DateTime RetrievedAtUtc, bool FromCache);

public record ActivityLogDto(Guid Id, string Action, string EntityType, Guid? EntityId, string? Details, DateTime CreatedAt, string? ActorName);

public record WorkflowHistoryDto(string FromState, string ToState, string ActorRole, string? Reason, DateTime CreatedAt);
