using System.Security.Cryptography;
using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Configuration;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace AdCastPro.SyncClient.Infrastructure.Files;

/// <summary>
/// YAYINCILIK KALİTESİNDE ATOMIC FILE WRITER
///
/// Akış: Download → Temp → Checksum → Atomic Move → Final Folder
///
/// Garantiler:
///   ✓ Yarım dosya hedef klasöre asla düşmez
///   ✓ Checksum başarısızsa dosya silinir (temp + final)
///   ✓ File.Move NTFS rename = atomic (aynı volume)
///   ✓ Aynı isimde önceki dosya varsa overwrite (yeni version kabul)
///   ✓ Path traversal koruması (filename normalization)
///   ✓ File extension whitelist (mp3, wav, aac, m3u, pls, xml, json)
/// </summary>
public sealed class AtomicFileWriter : IAtomicFileWriter
{
    private static readonly HashSet<string> AllowedExtensions = new(StringComparer.OrdinalIgnoreCase)
    {
        ".mp3", ".wav", ".aac", ".m4a", ".ogg", ".flac",
        ".m3u", ".m3u8", ".pls",
        ".xml", ".json", ".txt",
    };

    private readonly SyncClientOptions _options;
    private readonly ILogger<AtomicFileWriter> _logger;
    private const int BufferSize = 65_536;

    public AtomicFileWriter(IOptions<SyncClientOptions> options, ILogger<AtomicFileWriter> logger)
    {
        _options = options.Value;
        _logger = logger;
    }

    public async Task<string> WriteAtomicAsync(
        Stream source,
        string targetDirectory,
        string filename,
        string expectedSha256,
        long expectedSizeBytes,
        CancellationToken ct = default)
    {
        // 1. Filename validation — path traversal koruması
        var safeName = SanitizeFilename(filename);
        ValidateExtension(safeName);

        // 2. Klasörleri hazırla
        Directory.CreateDirectory(_options.Folders.Temp);
        Directory.CreateDirectory(targetDirectory);

        var tempPath = Path.Combine(_options.Folders.Temp, safeName + ".partial");
        var finalPath = Path.Combine(targetDirectory, safeName);

        // 3. Pre-flight: disk space check (size × 1.5 buffer)
        var freeBytes = GetFreeBytes(targetDirectory);
        var needed = expectedSizeBytes * 3 / 2;
        if (freeBytes < needed)
        {
            throw new IOException(
                $"Yetersiz disk alanı: {freeBytes / (1024 * 1024)} MB kullanılabilir, " +
                $"{needed / (1024 * 1024)} MB gerekli ({safeName})");
        }

        // 4. Stream'i temp'e yaz + checksum streaming hesapla
        using var sha = SHA256.Create();
        long totalBytes = 0;
        await using (var fs = new FileStream(tempPath, FileMode.Create, FileAccess.Write, FileShare.None, BufferSize, useAsync: true))
        {
            var buffer = new byte[BufferSize];
            int read;
            while ((read = await source.ReadAsync(buffer.AsMemory(0, BufferSize), ct)) > 0)
            {
                await fs.WriteAsync(buffer.AsMemory(0, read), ct);
                sha.TransformBlock(buffer, 0, read, null, 0);
                totalBytes += read;
            }
            sha.TransformFinalBlock(Array.Empty<byte>(), 0, 0);
            await fs.FlushAsync(ct);
        }

        // 5. Size validation
        if (expectedSizeBytes > 0 && totalBytes != expectedSizeBytes)
        {
            SafeDelete(tempPath);
            throw new InvalidDataException(
                $"Dosya boyutu uyumsuz: beklenen {expectedSizeBytes}, alınan {totalBytes} ({safeName})");
        }

        // 6. Checksum validation
        // .NET 8: ToHexString döner UPPER hex; lower case ToLowerInvariant ile
        var actualSha = Convert.ToHexString(sha.Hash ?? throw new InvalidOperationException("Hash null")).ToLowerInvariant();
        if (!string.IsNullOrEmpty(expectedSha256) &&
            !actualSha.Equals(expectedSha256, StringComparison.OrdinalIgnoreCase))
        {
            SafeDelete(tempPath);
            throw new InvalidDataException(
                $"Checksum uyumsuz ({safeName}): beklenen {expectedSha256}, hesaplanan {actualSha}");
        }

        // 7. Atomic move — temp → final
        try
        {
            // File.Move overwrite (aynı volume = atomic NTFS rename)
            File.Move(tempPath, finalPath, overwrite: true);
            _logger.LogInformation(
                "Dosya hazır: {Path} ({Bytes} bytes, sha256={Sha})",
                finalPath, totalBytes, actualSha[..16] + "..."
            );
            return finalPath;
        }
        catch (Exception ex)
        {
            _logger.LogError(ex, "Atomic move başarısız: {Temp} → {Final}", tempPath, finalPath);
            SafeDelete(tempPath);
            throw;
        }
    }

    public long GetPartialByteCount(string targetDirectory, string filename)
    {
        var safeName = SanitizeFilename(filename);
        var tempPath = Path.Combine(_options.Folders.Temp, safeName + ".partial");
        if (!File.Exists(tempPath)) return 0;
        return new FileInfo(tempPath).Length;
    }

    public long GetFreeBytes(string path)
    {
        var root = Path.GetPathRoot(Path.GetFullPath(path));
        if (string.IsNullOrEmpty(root)) return long.MaxValue;
        var drive = new DriveInfo(root);
        return drive.IsReady ? drive.AvailableFreeSpace : 0;
    }

    // ---------- Helpers ----------

    /// <summary>
    /// Path traversal koruması:
    ///   - "../" "..\\" "/" "\\" karakterleri reddedilir
    ///   - Sadece basename alınır (klasör component'leri yok)
    ///   - Windows reserved name'ler reddedilir (CON, PRN, NUL, AUX, COMx, LPTx)
    /// </summary>
    private static string SanitizeFilename(string filename)
    {
        if (string.IsNullOrWhiteSpace(filename))
            throw new ArgumentException("Dosya adı boş olamaz", nameof(filename));

        // KRITIK: Önce RAW filename üzerinde dangerous karakter kontrol (platform-agnostic).
        // Path.GetFileName Linux'ta "/" karakterini çıkarır, Windows'ta "\\" — bu yüzden
        // raw input üzerinde kontrol yapılmalı, basename çıkardıktan sonra DEĞİL.
        if (filename.Contains("..") || filename.Contains('/') || filename.Contains('\\') ||
            filename.Contains(':') || filename.Contains('|') || filename.Contains('?') ||
            filename.Contains('*') || filename.Contains('<') || filename.Contains('>'))
        {
            throw new ArgumentException($"Dosya adı geçersiz karakter içeriyor: {filename}");
        }

        // Sadece basename (Path.GetFileName) — bu noktada zaten dangerous char yok
        var baseName = Path.GetFileName(filename);
        if (string.IsNullOrWhiteSpace(baseName))
            throw new ArgumentException($"Geçersiz dosya adı: {filename}");

        // Windows reserved names
        var stem = Path.GetFileNameWithoutExtension(baseName).ToUpperInvariant();
        var reserved = new[] { "CON", "PRN", "NUL", "AUX",
            "COM1","COM2","COM3","COM4","COM5","COM6","COM7","COM8","COM9",
            "LPT1","LPT2","LPT3","LPT4","LPT5","LPT6","LPT7","LPT8","LPT9" };
        if (reserved.Contains(stem))
            throw new ArgumentException($"Windows reserved dosya adı: {filename}");

        return baseName;
    }

    private static void ValidateExtension(string filename)
    {
        var ext = Path.GetExtension(filename);
        if (string.IsNullOrEmpty(ext) || !AllowedExtensions.Contains(ext))
            throw new ArgumentException($"İzin verilmeyen dosya uzantısı: {ext} ({filename})");
    }

    private static void SafeDelete(string path)
    {
        try { if (File.Exists(path)) File.Delete(path); }
        catch { /* ignore */ }
    }
}
