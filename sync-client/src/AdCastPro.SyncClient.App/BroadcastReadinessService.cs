using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Core.Models;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace AdCastPro.SyncClient.App;

/// <summary>
/// Yayıncılık readiness — tray icon + UI'a renk besler.
///
/// YEŞIL = Hazır:   bir sonraki kuşak için tüm dosyalar disk'te, checksum ok
/// SARI  = Bekliyor: dosya henüz inmiyor ama 15 dk eşiği gelmedi
/// KIRMIZI = Kritik: haber saatine 15 dk kaldı, dosya hâlâ yok / hatalı
/// </summary>
public sealed class BroadcastReadinessService
{
    public enum ReadinessLevel { Green, Yellow, Red, Unknown }

    public sealed record ReadinessReport(
        ReadinessLevel Level,
        string Message,
        ManifestFile? NextFile,
        TimeSpan? TimeUntilAir,
        bool ChecksumVerified
    );

    private readonly ILocalCache _cache;
    private readonly IChecksumService _checksum;
    private readonly SyncClientOptions _options;
    private readonly ILogger<BroadcastReadinessService> _logger;

    public BroadcastReadinessService(
        ILocalCache cache,
        IChecksumService checksum,
        IOptions<SyncClientOptions> options,
        ILogger<BroadcastReadinessService> logger)
    {
        _cache = cache;
        _checksum = checksum;
        _options = options.Value;
        _logger = logger;
    }

    public async Task<ReadinessReport> EvaluateAsync(CancellationToken ct = default)
    {
        var (manifest, _) = await _cache.LoadManifestAsync(ct);
        if (manifest == null || manifest.Files.Count == 0)
        {
            return new ReadinessReport(ReadinessLevel.Unknown, "Henüz manifest yok", null, null, false);
        }

        var now = DateTimeOffset.UtcNow;
        // En yakın gelecek dosya — şu andan sonra yayınlanacak ilk haber
        var next = manifest.Files
            .Where(f => f.FileType == "news" && f.ScheduledAirTime > now)
            .OrderBy(f => f.ScheduledAirTime)
            .FirstOrDefault();

        if (next == null)
        {
            return new ReadinessReport(ReadinessLevel.Green, "Yaklaşan haber kuşağı yok", null, null, true);
        }

        var timeUntilAir = next.ScheduledAirTime - now;
        var warnThreshold = TimeSpan.FromMinutes(_options.NewsReadyMinutesBefore);

        // Dosya disk'te mi + checksum doğru mu?
        var targetDir = _options.Folders.ResolveForType(next.FileType);
        var localPath = Path.Combine(targetDir, next.Filename);
        bool fileOnDisk = File.Exists(localPath);
        bool checksumOk = false;
        if (fileOnDisk && !string.IsNullOrEmpty(next.ChecksumSha256))
        {
            try
            {
                var actual = await _checksum.ComputeFileSha256Async(localPath, ct);
                checksumOk = actual.Equals(next.ChecksumSha256, StringComparison.OrdinalIgnoreCase);
            }
            catch (Exception ex)
            {
                _logger.LogWarning(ex, "Checksum verify hatası: {Path}", localPath);
            }
        }

        // Karar:
        if (fileOnDisk && checksumOk)
        {
            return new ReadinessReport(
                ReadinessLevel.Green,
                $"HAZIR — {next.Filename} ({timeUntilAir.TotalMinutes:F0} dk sonra)",
                next, timeUntilAir, true);
        }

        if (timeUntilAir > warnThreshold)
        {
            return new ReadinessReport(
                ReadinessLevel.Yellow,
                $"İndirme bekleniyor — {next.Filename} ({timeUntilAir.TotalMinutes:F0} dk sonra)",
                next, timeUntilAir, checksumOk);
        }

        // KIRMIZI — kritik
        var reason = !fileOnDisk
            ? "dosya disk'te yok"
            : "checksum doğrulanamadı";
        return new ReadinessReport(
            ReadinessLevel.Red,
            $"KRİTİK — {next.Filename} ({timeUntilAir.TotalMinutes:F0} dk kaldı, {reason})",
            next, timeUntilAir, checksumOk);
    }
}
