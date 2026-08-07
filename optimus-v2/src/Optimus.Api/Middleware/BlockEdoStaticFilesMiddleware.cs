namespace Optimus.Api.Middleware;

/// <summary>
/// eDO/CRO PDFs and QR images must be served through secured API endpoints
/// (<c>/api/edo/{id}/download</c> and <c>/api/edo/{id}/qr</c>) so download policy is enforced.
/// </summary>
public class BlockEdoStaticFilesMiddleware
{
    private static readonly string[] BlockedPrefixes =
    {
        "/uploads/edo/",
        "/uploads/edo-qr/",
    };

    private readonly RequestDelegate _next;

    public BlockEdoStaticFilesMiddleware(RequestDelegate next) => _next = next;

    public async Task InvokeAsync(HttpContext context)
    {
        var path = context.Request.Path.Value ?? string.Empty;
        if (BlockedPrefixes.Any(prefix => path.StartsWith(prefix, StringComparison.OrdinalIgnoreCase)))
        {
            context.Response.StatusCode = StatusCodes.Status404NotFound;
            return;
        }

        await _next(context);
    }
}
