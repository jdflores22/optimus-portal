using Microsoft.AspNetCore.Http;

namespace Optimus.Api.Security;

public static class UploadGuard
{
    public const long MaxBytes = 10 * 1024 * 1024; // 10 MB

    private static readonly HashSet<string> DefaultAllowed = new(StringComparer.OrdinalIgnoreCase)
    {
        ".pdf", ".png", ".jpg", ".jpeg", ".gif", ".webp",
        ".csv", ".txt", ".xlsx", ".xls", ".doc", ".docx"
    };

    public static void Validate(IFormFile? file, params string[] allowedExtensions)
    {
        if (file is null || file.Length == 0)
        {
            throw new InvalidOperationException("A non-empty file is required.");
        }

        if (file.Length > MaxBytes)
        {
            throw new InvalidOperationException($"File exceeds maximum size of {MaxBytes / (1024 * 1024)} MB.");
        }

        var ext = Path.GetExtension(file.FileName);
        if (string.IsNullOrWhiteSpace(ext))
        {
            throw new InvalidOperationException("File extension is required.");
        }

        var allowed = allowedExtensions.Length > 0
            ? new HashSet<string>(allowedExtensions, StringComparer.OrdinalIgnoreCase)
            : DefaultAllowed;

        if (!allowed.Contains(ext))
        {
            throw new InvalidOperationException($"File type '{ext}' is not allowed.");
        }

        // Block path traversal / weird names
        var name = Path.GetFileName(file.FileName);
        if (string.IsNullOrWhiteSpace(name) || name.Contains("..", StringComparison.Ordinal))
        {
            throw new InvalidOperationException("Invalid file name.");
        }
    }
}
