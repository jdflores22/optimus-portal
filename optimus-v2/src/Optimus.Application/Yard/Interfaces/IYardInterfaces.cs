using Optimus.Application.Yard.Dtos;

namespace Optimus.Application.Yard.Interfaces;

public interface ITerminalService
{
    Task<TerminalDto> UpsertAsync(Guid? id, UpsertTerminalRequest request, Guid actorId, CancellationToken ct = default);
    Task<IReadOnlyList<TerminalDto>> ListAsync(bool? activeOnly = true, CancellationToken ct = default);
    Task<TerminalSlotDto> UpsertSlotAsync(UpsertSlotRequest request, Guid actorId, CancellationToken ct = default);
    Task<IReadOnlyList<TerminalSlotDto>> ListSlotsAsync(Guid terminalId, DateOnly? from, DateOnly? to, CancellationToken ct = default);
}

public interface IContainerCatalogService
{
    Task<ContainerTypeDto> UpsertTypeAsync(Guid? id, UpsertContainerTypeRequest request, CancellationToken ct = default);
    Task<IReadOnlyList<ContainerTypeDto>> ListTypesAsync(CancellationToken ct = default);
    Task<ContainerSizeDto> UpsertSizeAsync(Guid? id, UpsertContainerSizeRequest request, CancellationToken ct = default);
    Task<IReadOnlyList<ContainerSizeDto>> ListSizesAsync(CancellationToken ct = default);
}

public interface ICyAllocationService
{
    Task<CyAllocationDto> UpsertAsync(Guid? id, UpsertCyAllocationRequest request, Guid actorId, CancellationToken ct = default);
    Task<IReadOnlyList<CyAllocationDto>> ListAsync(Guid? shippingLineId, Guid? terminalId, CancellationToken ct = default);
}

public interface IContainerInventoryService
{
    Task<ContainerDto> CreateAsync(CreateContainerRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<ContainerDto> GetAsync(Guid id, CancellationToken ct = default);
    Task<ContainerInventoryItemDto> GetInventoryItemAsync(Guid id, CancellationToken ct = default);
    Task<ContainerDetailDto> GetDetailByNumberAsync(string containerNumber, Guid? shippingLineId, CancellationToken ct = default);
    Task<IReadOnlyList<ContainerDto>> ListAsync(Guid? shippingLineId, string? status, string? search, CancellationToken ct = default);
    Task<ContainerInventoryPageDto> InventoryPageAsync(
        Guid? shippingLineId,
        string? depot,
        string? search,
        int page,
        int pageSize,
        CancellationToken ct = default);
    Task<IReadOnlyList<string>> ListInventoryDepotsAsync(Guid? shippingLineId, CancellationToken ct = default);
    Task<IReadOnlyList<ContainerDto>> SearchForReturnAsync(string query, CancellationToken ct = default);
    Task<ContainerDto> AllocateAsync(Guid id, AllocateContainerRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<ContainerDto> ReallocateAsync(Guid id, ReallocateContainerRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<ContainerDto> LockAllocationAsync(Guid id, Guid actorId, CancellationToken ct = default);
    Task<ContainerDto> UpdateStackAsync(Guid id, UpdateStackRequest request, Guid actorId, CancellationToken ct = default);
    Task<ContainerDto> MarkAvailableForReturnAsync(Guid id, Guid actorId, CancellationToken ct = default);
    Task<(string Csv, string PdfPath)> ExportUtilizationAsync(CancellationToken ct = default);
    Task<IReadOnlyList<UtilizationReportDto>> UtilizationReportAsync(CancellationToken ct = default);
}

public interface IDwellService
{
    Task<DwellConfigDto> GetConfigAsync(CancellationToken ct = default);
    Task<DwellConfigDto> UpsertConfigAsync(UpsertDwellConfigRequest request, Guid actorId, CancellationToken ct = default);
    Task<ContainerDto> RecordArrivalAsync(Guid containerId, DateTime? arrivalAt, Guid actorId, CancellationToken ct = default);
    Task<ContainerDto> PauseAsync(Guid containerId, PauseResumeDwellRequest request, Guid actorId, CancellationToken ct = default);
    Task<ContainerDto> ResumeAsync(Guid containerId, PauseResumeDwellRequest request, Guid actorId, CancellationToken ct = default);
    Task<IReadOnlyList<DwellEventDto>> ListEventsAsync(Guid? containerId, CancellationToken ct = default);
    Task<IReadOnlyList<ContainerDto>> MonitorListAsync(CancellationToken ct = default);
    Task<int> ProcessMonitoringAsync(CancellationToken ct = default);
}

public interface IPreAdviceService
{
    Task<PreAdviceDto> SubmitAsync(SubmitPreAdviceRequest request, string? photoPath, Guid truckerId, CancellationToken ct = default);
    Task<PreAdviceDto> VerifyAsync(Guid id, VerifyPreAdviceRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<PreAdviceDto> CompleteAsync(Guid id, CompletePreAdviceRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<PreAdviceDto> CancelAsync(Guid id, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<PreAdviceDto> GetAsync(Guid id, CancellationToken ct = default);
    Task<IReadOnlyList<PreAdviceDto>> ListAsync(string? status, Guid? truckerId, CancellationToken ct = default);
}

public interface ITruckerTokenService
{
    Task<TruckerTokenDto> GenerateAsync(Guid truckerId, CancellationToken ct = default);
    Task<TruckerTokenDto> RefreshAsync(Guid truckerId, CancellationToken ct = default);
    Task RevokeAsync(Guid truckerId, CancellationToken ct = default);
    Task<bool> ValidateAsync(Guid truckerId, string rawToken, CancellationToken ct = default);
}

public interface INotificationService
{
    Task NotifyAsync(Guid? userId, string title, string message, string category, string? subjectType, Guid? subjectId, CancellationToken ct = default);
    Task<IReadOnlyList<NotificationDto>> ListForUserAsync(Guid userId, CancellationToken ct = default);
    Task<NotificationDto?> GetForUserAsync(Guid userId, Guid notificationId, CancellationToken ct = default);
    Task MarkReadAsync(Guid userId, Guid? notificationId, CancellationToken ct = default);
    Task<Optimus.Application.Platform.Dtos.NotificationPreferenceDto> GetPreferencesAsync(Guid userId, CancellationToken ct = default);
    Task<Optimus.Application.Platform.Dtos.NotificationPreferenceDto> UpsertPreferencesAsync(Guid userId, Optimus.Application.Platform.Dtos.UpsertNotificationPreferenceRequest request, CancellationToken ct = default);
    Task SubscribePushAsync(Guid userId, Optimus.Application.Platform.Dtos.PushSubscribeRequest request, CancellationToken ct = default);
    Task UnsubscribePushAsync(Guid userId, string endpoint, CancellationToken ct = default);
    Task<Optimus.Application.Platform.Dtos.NotificationMetricsDto> GetMetricsAsync(CancellationToken ct = default);
}
