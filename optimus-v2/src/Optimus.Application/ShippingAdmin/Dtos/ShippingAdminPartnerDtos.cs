namespace Optimus.Application.ShippingAdmin.Dtos;

public record LinkedPartyDto(Guid Id, string Name, string Email);

public record ShippingAdminConsigneeDto(
    Guid Id,
    string BusinessName,
    string FullName,
    string Email,
    string Status,
    bool IsActive,
    int BrokerCount,
    int NoaCount,
    int ManifestCount,
    int ContainerCount,
    IReadOnlyList<LinkedPartyDto> LinkedBrokers);

public record ShippingAdminBrokerDto(
    Guid Id,
    string FullName,
    string Email,
    string Status,
    bool IsActive,
    int ConsigneeCount,
    int ManifestCount,
    int EdoCount,
    IReadOnlyList<LinkedPartyDto> LinkedConsignees);

public record ShippingAdminConsigneeDetailDto(
    ShippingAdminConsigneeDto Consignee,
    int EdoCount,
    IReadOnlyList<RecentManifestDto> RecentManifests,
    PartnerAccreditationDto? Accreditation);

public record ShippingAdminBrokerDetailDto(
    ShippingAdminBrokerDto Broker,
    int ContainerCount,
    IReadOnlyList<RecentManifestDto> RecentManifests,
    PartnerAccreditationDto? Accreditation);

public record PartnerAccreditationDto(
    Guid Id,
    Guid FormConfigurationId,
    string FormName,
    string FormType,
    int FormVersion,
    string FieldsJson,
    string SubmittedDataJson,
    string Status,
    DateTime SubmittedAt,
    DateTime? ApprovedAt,
    DateTime? EvaluatedAt);

public record RecentManifestDto(
    Guid Id,
    string ManifestNumber,
    string WorkflowState,
    DateTime CreatedAt);
