using System.Text;

namespace Optimus.Infrastructure.Cargo;

/// <summary>
/// Minimal single-page PDF writer (no external PDF library required).
/// Suitable for operational placeholders like NOA / BL / billing until full templates land.
/// </summary>
internal static class MinimalPdfWriter
{
    public static byte[] Build(string title, string body)
    {
        var lines = new List<string> { title, "" };
        foreach (var raw in body.Replace("\r\n", "\n").Replace('\r', '\n').Split('\n'))
        {
            lines.AddRange(Wrap(raw, 90));
        }

        var content = new StringBuilder();
        content.AppendLine("BT");
        content.AppendLine("/F1 16 Tf");
        content.AppendLine("50 780 Td");
        content.AppendLine($"{PdfString(title)} Tj");
        content.AppendLine("/F1 10 Tf");
        content.AppendLine("0 -28 Td");

        for (var i = 1; i < lines.Count; i++)
        {
            var line = lines[i];
            if (i > 1) content.AppendLine("0 -14 Td");
            content.AppendLine($"{PdfString(string.IsNullOrEmpty(line) ? " " : line)} Tj");
        }

        content.AppendLine("ET");
        var stream = content.ToString();
        var streamBytes = Encoding.ASCII.GetBytes(stream);

        var objects = new List<string>
        {
            "1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n",
            "2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n",
            "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources<< /Font<< /F1 5 0 R >> >> >>endobj\n",
            $"4 0 obj<< /Length {streamBytes.Length} >>stream\n{stream}endstream\nendobj\n",
            "5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj\n",
        };

        var pdf = new StringBuilder();
        pdf.Append("%PDF-1.4\n");
        var offsets = new List<int> { 0 };
        foreach (var obj in objects)
        {
            offsets.Add(Encoding.ASCII.GetByteCount(pdf.ToString()));
            pdf.Append(obj);
        }

        var xrefPos = Encoding.ASCII.GetByteCount(pdf.ToString());
        pdf.Append($"xref\n0 {objects.Count + 1}\n");
        pdf.Append("0000000000 65535 f \n");
        for (var i = 1; i < offsets.Count; i++)
        {
            pdf.Append($"{offsets[i]:D10} 00000 n \n");
        }

        pdf.Append($"trailer<< /Size {objects.Count + 1} /Root 1 0 R >>\n");
        pdf.Append("startxref\n");
        pdf.Append(xrefPos);
        pdf.Append("\n%%EOF\n");
        return Encoding.ASCII.GetBytes(pdf.ToString());
    }

    private static IEnumerable<string> Wrap(string text, int width)
    {
        if (string.IsNullOrEmpty(text))
        {
            yield return "";
            yield break;
        }

        var remaining = text;
        while (remaining.Length > width)
        {
            var slice = remaining[..width];
            var space = slice.LastIndexOf(' ');
            var take = space > 40 ? space : width;
            yield return remaining[..take].TrimEnd();
            remaining = remaining[take..].TrimStart();
        }

        yield return remaining;
    }

    private static string PdfString(string value)
    {
        var escaped = value
            .Replace("\\", "\\\\")
            .Replace("(", "\\(")
            .Replace(")", "\\)")
            .Replace("\t", " ");
        // Keep Latin-1-ish printable; strip unsupported glyphs for Helvetica Type1.
        var cleaned = new StringBuilder(escaped.Length);
        foreach (var ch in escaped)
        {
            cleaned.Append(ch is >= (char)32 and <= (char)126 ? ch : '?');
        }

        return $"({cleaned})";
    }
}
