using Optimus.Application.Ops.Dtos;

namespace Optimus.Application.Ops.Interfaces;

public interface IFormBuilderService
{
    Task<FormConfigurationDto> CreateAsync(UpsertFormRequest request, Guid actorId, CancellationToken ct = default);
    Task<FormConfigurationDto> UpdateFieldsAsync(Guid id, FormFieldsUpdateRequest request, Guid actorId, CancellationToken ct = default);
    Task<FormConfigurationDto> PublishAsync(Guid id, Guid actorId, CancellationToken ct = default);
    Task<FormConfigurationDto> ActivateAsync(Guid id, Guid actorId, CancellationToken ct = default);
    Task DeleteAsync(Guid id, Guid actorId, CancellationToken ct = default);
    Task<FormConfigurationDto?> GetActiveAsync(string type, CancellationToken ct = default);
    Task<IReadOnlyList<FormConfigurationDto>> ListAsync(string? type, CancellationToken ct = default);
}

public interface IAccreditationService
{
    Task<AccreditationDto> SubmitAsync(SubmitAccreditationRequest request, Guid applicantId, string applicantRole, CancellationToken ct = default);
    Task<AccreditationDto> EvaluatorActionAsync(Guid id, EvaluatorActionRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<AccreditationDto> FinalDecisionAsync(Guid id, FinalApprovalRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<AccreditationDto> GetAsync(Guid id, CancellationToken ct = default);
    Task<IReadOnlyList<AccreditationDto>> ListAsync(string? status, Guid? applicantId, CancellationToken ct = default);
}

public interface IBrokerTransferService
{
    Task<TransferDto> CreateAsync(CreateTransferRequest request, string? letterPath, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<TransferDto> ReviewAsync(Guid id, ReviewTransferRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<IReadOnlyList<TransferDto>> ListAsync(string? status, CancellationToken ct = default);
}

public interface ISuspensionAppealService
{
    Task SuspendBrokerAsync(Guid brokerId, SuspendBrokerRequest request, Guid actorId, CancellationToken ct = default);
    Task<AppealDto> SubmitAsync(CreateAppealRequest request, string? attachmentsJson, Guid userId, CancellationToken ct = default);
    Task<AppealDto> ReviewAsync(Guid id, ReviewAppealRequest request, Guid actorId, CancellationToken ct = default);
    Task<IReadOnlyList<AppealDto>> ListAsync(string? status, CancellationToken ct = default);
}

public interface IRepositioningService
{
    Task<RepositioningDto> CreateAsync(CreateRepositioningRequest request, string? requestLetterPath, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<RepositioningDto> GetAsync(Guid id, Guid? shippingLineId, CancellationToken ct = default);
    Task<RepositioningDto> ReviewAsync(Guid id, ReviewRepositioningRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<RepositioningDto> CompleteAsync(Guid id, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<RepositioningDto> CancelAsync(Guid id, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<IReadOnlyList<RepositioningDto>> ListAsync(string? status, Guid? shippingLineId, CancellationToken ct = default);
    Task<IReadOnlyList<RepositioningEligibleContainerDto>> ListEligibleContainersAsync(
        Guid? shippingLineId,
        Guid? sourceTerminalId,
        string? search,
        CancellationToken ct = default);
}

public interface IReferralService
{
    Task<ReferralCodeDto> GenerateAsync(GenerateReferralRequest request, Guid consigneeId, CancellationToken ct = default);
    Task<RelationshipDto> ApplyAsync(ApplyReferralRequest request, Guid brokerId, CancellationToken ct = default);
    Task<IReadOnlyList<ReferralCodeDto>> ListForConsigneeAsync(Guid consigneeId, CancellationToken ct = default);
    Task<IReadOnlyList<RelationshipDto>> ListRelationshipsAsync(Guid? consigneeId, Guid? brokerId, CancellationToken ct = default);
    Task DeactivateAsync(Guid codeId, Guid consigneeId, CancellationToken ct = default);
}

public interface IOnboardingService
{
    Task<WelcomeContentDto> GetWelcomeAsync(string audience, Guid? userId, CancellationToken ct = default);
    Task<WelcomeContentDto> UpsertWelcomeAsync(UpsertWelcomeContentRequest request, Guid actorId, CancellationToken ct = default);
    Task<WelcomeContentDto> CompleteStepAsync(CompleteOnboardingStepRequest request, Guid consigneeId, CancellationToken ct = default);
}

public interface ILocationService
{
    Task<IReadOnlyList<LocationItemDto>> GetRegionsAsync(CancellationToken ct = default);
    Task<IReadOnlyList<LocationItemDto>> GetProvincesByRegionAsync(Guid regionId, CancellationToken ct = default);
    Task<IReadOnlyList<LocationItemDto>> GetCitiesByProvinceAsync(Guid provinceId, CancellationToken ct = default);
    Task<IReadOnlyList<LocationItemDto>> GetBarangaysByCityAsync(Guid cityId, CancellationToken ct = default);
}
