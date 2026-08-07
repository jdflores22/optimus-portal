using Optimus.Application.Cargo.Dtos;
using Optimus.Domain.Enums;

namespace Optimus.Application.Cargo.Interfaces;

public interface IManifestWorkflowService
{
    Task<ManifestDto> CreateAsync(CreateManifestRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<ManifestDto> GetAsync(Guid id, CancellationToken ct = default);
    Task<IReadOnlyList<ManifestDto>> ListAsync(Guid? shippingLineId, Guid? brokerId, Guid? consigneeId, CancellationToken ct = default);
    Task<IReadOnlyList<AccreditedConsigneeOptionDto>> ListAccreditedConsigneesAsync(Guid actorId, string actorRole, CancellationToken ct = default);
    Task<IReadOnlyList<ConsigneeBrokerOptionDto>> ListBrokersForConsigneeAsync(Guid consigneeId, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<ManifestDto> DeclareConsigneeAsync(Guid manifestId, DeclareConsigneeRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<ManifestDto> AssignBrokerAsync(Guid manifestId, AssignBrokerRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<ManifestDto> GenerateNoaAsync(Guid manifestId, Guid actorId, string actorRole, CancellationToken ct = default, string? portLocation = null);
    Task<ManifestDto> GenerateBlAsync(Guid manifestId, GenerateBlRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<ManifestDto> UploadBlAsync(Guid manifestId, string blFilePath, string? blNumber, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<ManifestDto> GenerateBillingAsync(Guid manifestId, GenerateBillingRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<BulkImportResultDto> BulkImportAsync(Guid shippingLineId, string fileName, Stream csvStream, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<BulkImportResultDto?> GetBulkImportAsync(Guid jobId, CancellationToken ct = default);
    Task<IReadOnlyList<WorkflowHistoryDto>> GetHistoryAsync(Guid manifestId, CancellationToken ct = default);
}

public interface IPaymentService
{
    Task<PaymentDto> SubmitAsync(Guid manifestId, SubmitPaymentRequest request, string? receiptPath, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<PaymentDto> ValidateAsync(Guid paymentId, ValidatePaymentRequest request, Guid actorId, string actorRole, CancellationToken ct = default);
    Task<PaymentDto> GetAsync(Guid paymentId, CancellationToken ct = default);
    Task<PaymentDto> AttachOfficialReceiptAsync(Guid paymentId, string path, CancellationToken ct = default);
    Task<IReadOnlyList<PaymentDto>> ListPendingAsync(PaymentType? type, CancellationToken ct = default);
    Task<IReadOnlyList<PaymentDto>> ListByManifestAsync(Guid manifestId, CancellationToken ct = default);
    Task<FinalPaymentListResponse> ListFinalPaymentsAsync(string? statusFilter, int page, int limit, CancellationToken ct = default);
}

public interface IPaymentFeeService
{
    Task<PaymentFeeDto> GetActiveAsync(string feeType, CancellationToken ct = default);
    Task<IReadOnlyList<PaymentFeeDto>> ListAsync(CancellationToken ct = default);
    Task<PaymentFeeDto> UpsertAsync(UpsertPaymentFeeRequest request, string? qrPath, Guid actorId, CancellationToken ct = default);
}

public interface IExchangeRateService
{
    Task<ExchangeRateDto> GetUsdPhpAsync(CancellationToken ct = default);
}

public interface IActivityLogService
{
    Task LogAsync(Guid? actorId, string action, string entityType, Guid? entityId, string? details, CancellationToken ct = default);
    Task<IReadOnlyList<ActivityLogDto>> ListRecentAsync(int take = 50, CancellationToken ct = default);
}

public interface IDocumentStore
{
    Task<string> SaveAsync(string category, string fileName, Stream content, CancellationToken ct = default);
    string CreatePlaceholderPdf(string category, string title, string body);
}
