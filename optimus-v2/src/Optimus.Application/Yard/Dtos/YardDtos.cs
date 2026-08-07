namespace Optimus.Application.Yard.Dtos;

public record TerminalDto(
    Guid Id,
    string Name,
    string Code,
    string Identity,
    string Kind,
    string? Location,
    string? Region,
    string? City,
    int DailyCapacity,
    bool IsActive);

public record UpsertTerminalRequest(
    string Name,
    string Code,
    string Identity,
    string Kind,
    string? Location,
    string? Region,
    string? City,
    int DailyCapacity,
    bool IsActive = true);

public record TerminalSlotDto(
    Guid Id,
    Guid TerminalId,
    string TerminalName,
    DateOnly Date,
    int Capacity,
    int AssignedCount,
    string Status);

public record UpsertSlotRequest(Guid TerminalId, DateOnly Date, int Capacity);

public record ContainerTypeDto(Guid Id, string Name, string Code, string? Description, bool IsActive);
public record UpsertContainerTypeRequest(string Name, string Code, string? Description, bool IsActive = true);

public record ContainerSizeDto(Guid Id, string Name, string Code, decimal TeuValue, string? Description, bool IsActive);
public record UpsertContainerSizeRequest(string Name, string Code, decimal TeuValue, string? Description, bool IsActive = true);

public record CyAllocationDto(
    Guid Id,
    Guid ShippingLineId,
    string ShippingLineName,
    Guid TerminalId,
    string TerminalName,
    Guid? StaffUserId,
    int AllocatedCapacityTeu,
    int Capacity20Ft,
    int Capacity40Ft,
    int UsedTeu);

public record UpsertCyAllocationRequest(
    Guid ShippingLineId,
    Guid TerminalId,
    Guid? StaffUserId,
    int AllocatedCapacityTeu,
    int Capacity20Ft,
    int Capacity40Ft);

public record ContainerDto(
    Guid Id,
    string ContainerNumber,
    Guid ShippingLineId,
    string ShippingLineName,
    Guid? ManifestId,
    string? TypeCode,
    string? SizeCode,
    string Status,
    string AllocationStatus,
    string? CurrentLocation,
    Guid? CyAllocationId,
    string? CyTerminalName,
    int CurrentDwellDays,
    DateTime? TerminalArrivalDate,
    DateTime? DwellPausedAt,
    string? StackBay,
    string? StackRow,
    string? StackTier,
    DateTime CreatedAt);

public record CreateContainerRequest(
    string ContainerNumber,
    Guid ShippingLineId,
    Guid? ManifestId,
    Guid? ContainerTypeId,
    Guid? ContainerSizeId,
    string? CurrentLocation,
    string? StackBay,
    string? StackRow,
    string? StackTier);

public record AllocateContainerRequest(Guid CyAllocationId, string? Reason);
public record ReallocateContainerRequest(Guid NewCyAllocationId, string? Reason);
public record UpdateStackRequest(string? StackBay, string? StackRow, string? StackTier, string? CurrentLocation);

public record DwellConfigDto(
    Guid Id,
    int NotificationThresholdDays,
    int AutomaticReturnThresholdDays,
    string Timezone,
    bool EnableAutomaticReturns,
    bool EnableNotifications,
    bool IsActive);

public record UpsertDwellConfigRequest(
    int NotificationThresholdDays,
    int AutomaticReturnThresholdDays,
    string Timezone,
    bool EnableAutomaticReturns,
    bool EnableNotifications);

public record DwellEventDto(
    Guid Id,
    Guid ContainerId,
    string ContainerNumber,
    string EventType,
    DateTime EventDate,
    int DwellDaysAtEvent,
    string? Reason);

public record PauseResumeDwellRequest(string? Reason);

public record PreAdviceDto(
    Guid Id,
    Guid ContainerId,
    string ContainerNumber,
    Guid TerminalId,
    string TerminalName,
    Guid TruckerId,
    string TruckerName,
    string Status,
    Guid? AssignedSlotId,
    string? PaymentReference,
    bool PaymentVerified,
    string? RejectionReason,
    string? QrCodePath,
    string? PackagePdfPath,
    string? EdoNumber,
    string? VerificationToken,
    DateTime CreatedAt,
    IReadOnlyList<GeotagPhotoDto> Photos);

public record GeotagPhotoDto(
    Guid Id,
    string FilePath,
    string? OriginalName,
    double? Latitude,
    double? Longitude,
    DateTime CapturedAt,
    bool IsVerified);

public record SubmitPreAdviceRequest(
    Guid ContainerId,
    Guid TerminalId,
    Guid? SlotId,
    string? PaymentReference,
    double? Latitude,
    double? Longitude);

public record VerifyPreAdviceRequest(bool Approve, Guid? SlotId, string? RejectionReason, string? Notes);
public record CompletePreAdviceRequest(string? EdoNumber);

public record TruckerTokenDto(string ApiToken, DateTime ExpiresAt);
public record UtilizationReportDto(
    Guid TerminalId,
    string TerminalName,
    int AllocatedTeu,
    int UsedTeu,
    decimal UtilizationPercent,
    int AvailableForReturn,
    int AtTerminal,
    int PendingPreAdvice);

public record ContainerInventoryItemDto(
    Guid Id,
    string ContainerNumber,
    Guid ShippingLineId,
    string ShippingLineName,
    string? TypeCode,
    string? SizeCode,
    string SizeTypeLabel,
    string DepotName,
    DateTime? GateInDate,
    int CurrentDwellDays,
    int TotalPausedDays,
    bool IsDwellPaused,
    string DisplayStatus,
    string Condition,
    string AllocationStatus,
    string Status,
    decimal TeuValue,
    string? StackBay,
    string? StackRow,
    string? StackTier,
    string? CurrentLocation,
    DateTime CreatedAt);

public record ContainerInventoryStatsDto(
    int TotalContainers,
    int TotalTeus,
    int Total20Ft,
    int Total40Ft,
    int OverallCapacityTeu,
    int OverallCapacity20Ft,
    int OverallCapacity40Ft,
    int TerminalCount,
    int TerminalCapacityTeu,
    int TerminalCapacity20Ft,
    int TerminalCapacity40Ft,
    int YardCount,
    int YardCapacityTeu,
    int YardCapacity20Ft,
    int YardCapacity40Ft);

public record ContainerInventoryPageDto(
    IReadOnlyList<ContainerInventoryItemDto> Items,
    int TotalCount,
    int Page,
    int PageSize,
    int TotalPages,
    string ShippingLineName,
    ContainerInventoryStatsDto Stats);

public record ContainerDetailBasicInfoDto(
    string ContainerNumber,
    string SizeType,
    decimal TeuCount,
    string Location,
    DateTime? GateInDate,
    int DwellTime,
    string Condition,
    string Status,
    string ShippingLineName,
    string? StackPosition,
    string? OperationalStatus);

public record ContainerDetailSpecificationsDto(
    string Manufacturer,
    string YearBuilt,
    string IsoCode,
    string CscPlate,
    string MaxGrossWeight,
    string TareWeight,
    string MaxPayload,
    string Length,
    string Width,
    string Height);

public record ContainerDetailMovementDto(
    string LastMovement,
    string MovementType,
    string FromLocation,
    string ToLocation,
    string Operator,
    string Equipment,
    string Remarks);

public record ContainerDetailDocumentationDto(
    string BillOfLading,
    string Manifest,
    string CustomsDeclaration,
    string? DeliveryOrder,
    string GatePass);

public record ContainerDetailChargesDto(
    decimal StorageCharges,
    decimal HandlingCharges,
    decimal DocumentationFee);

public record ContainerDetailHistoryItemDto(
    string Date,
    string Type,
    string FromLocation,
    string ToLocation,
    string Operator,
    string Equipment,
    string Remarks);

public record ContainerDetailInspectionDto(
    string Date,
    string Type,
    string Inspector,
    string Result,
    string Photos,
    string Remarks);

public record ContainerDetailDto(
    Guid Id,
    ContainerDetailBasicInfoDto BasicInfo,
    ContainerDetailSpecificationsDto Specifications,
    ContainerDetailMovementDto Movement,
    ContainerDetailDocumentationDto Documentation,
    ContainerDetailChargesDto Charges,
    IReadOnlyList<ContainerDetailHistoryItemDto> History,
    IReadOnlyList<ContainerDetailInspectionDto> Inspections);

public record NotificationDto(
    Guid Id,
    string Title,
    string Message,
    string Category,
    bool IsRead,
    DateTime CreatedAt,
    string? SubjectType = null,
    Guid? SubjectId = null);
