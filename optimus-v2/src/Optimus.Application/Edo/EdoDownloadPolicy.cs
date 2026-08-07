using Optimus.Domain.Enums;
using Optimus.Shared.Constants;

namespace Optimus.Application.Edo;

public static class EdoDownloadPolicy
{
    public static void EnsureCanDownload(string role, EdoStatus status)
    {
        if (role == AppRoles.SystemAdmin)
        {
            if (status is EdoStatus.Rejected or EdoStatus.Superseded)
            {
                throw new InvalidOperationException("This eDO document is not available for download.");
            }

            return;
        }

        if (status is not (EdoStatus.Released or EdoStatus.Expired))
        {
            throw new UnauthorizedAccessException(
                status switch
                {
                    EdoStatus.PendingValidation =>
                        "Pay the eDO access fee before this document can be downloaded.",
                    EdoStatus.PendingRelease =>
                        "Payment verified. Download will be available once the eDO is released.",
                    _ => "eDO must be released before it can be downloaded."
                });
        }
    }
}
