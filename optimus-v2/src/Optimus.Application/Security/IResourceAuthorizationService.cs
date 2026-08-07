namespace Optimus.Application.Security;

public interface IResourceAuthorizationService
{
    Task EnsureManifestAccessAsync(Guid manifestId, Guid userId, string role, CancellationToken ct = default);
    Task EnsureEdoAccessAsync(Guid edoId, Guid userId, string role, CancellationToken ct = default);
    Task EnsurePreAdviceAccessAsync(Guid preAdviceId, Guid userId, string role, CancellationToken ct = default);
}
