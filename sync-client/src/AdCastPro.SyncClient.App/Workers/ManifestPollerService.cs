using System.Threading.Channels;
using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Core.Models;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace AdCastPro.SyncClient.App.Workers;

/// <summary>
/// Backend'den manifest'i adaptif interval ile çeker.
///
/// Adaptif polling (haber saati yaklaştıkça hızlanır):
///   Normal:                     60 saniye
///   Haber saatine 20 dk kala:   30 saniye
///   Haber saatine 10 dk kala:   15 saniye
///   Haber saatine 5 dk kala:    5  saniye
///
/// Yeni/güncellenmiş dosyaları DownloadChannel'a push eder.
/// </summary>
public sealed class ManifestPollerService : BackgroundService
{
    private readonly IApiClient _api;
    private readonly ILocalCache _cache;
    private readonly ITokenStore _tokens;
    private readonly Channel<ManifestFile> _downloadChannel;
    private readonly SyncClientOptions _options;
    private readonly ILogger<ManifestPollerService> _logger;

    public ManifestPollerService(
        IApiClient api,
        ILocalCache cache,
        ITokenStore tokens,
        Channel<ManifestFile> downloadChannel,
        IOptions<SyncClientOptions> options,
        ILogger<ManifestPollerService> logger)
    {
        _api = api;
        _cache = cache;
        _tokens = tokens;
        _downloadChannel = downloadChannel;
        _options = options.Value;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        _logger.LogInformation("ManifestPollerService başlatıldı");

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                // Login yapılmadıysa bekle
                var (tokens, _, _) = await _tokens.LoadAsync(stoppingToken);
                if (tokens == null)
                {
                    _logger.LogDebug("Token yok, login bekleniyor");
                    await Task.Delay(TimeSpan.FromSeconds(5), stoppingToken);
                    continue;
                }

                await PollOnceAsync(stoppingToken);
            }
            catch (OperationCanceledException) { break; }
            catch (Exception ex)
            {
                _logger.LogError(ex, "Manifest poll hatası — exponential backoff");
            }

            var delay = ComputeNextPollDelay();
            try { await Task.Delay(delay, stoppingToken); }
            catch (OperationCanceledException) { break; }
        }
    }

    private async Task PollOnceAsync(CancellationToken ct)
    {
        var (cachedManifest, cachedEtag) = await _cache.LoadManifestAsync(ct);
        var manifest = await _api.GetManifestAsync(cachedEtag, since: null, ct);

        if (manifest == null)
        {
            // 304 — değişiklik yok. Cache'teki dosyaları yine de queue'ya bas
            // (uygulama yeni başladıysa download'ları tetiklemek için).
            if (cachedManifest != null)
            {
                await EnqueueFiles(cachedManifest, ct);
            }
            return;
        }

        // Manifest cache'e yaz (yeni ETag yoksa fetched timestamp yine güncellenir)
        await _cache.SaveManifestAsync(manifest, etag: "", ct);
        _logger.LogInformation("Yeni manifest: {Count} dosya, pencere {Start} → {End}",
            manifest.FileCount, manifest.WindowStart, manifest.WindowEnd);
        await EnqueueFiles(manifest, ct);
    }

    private async Task EnqueueFiles(Manifest manifest, CancellationToken ct)
    {
        foreach (var file in manifest.Files.OrderBy(f => f.ScheduledAirTime))
        {
            // Zaten indirilmiş + checksum eşleşiyorsa skip
            if (!string.IsNullOrEmpty(file.ChecksumSha256) &&
                await _cache.IsAlreadyDownloadedAsync(file.FileId, file.ChecksumSha256, ct))
            {
                continue;
            }
            await _downloadChannel.Writer.WriteAsync(file, ct);
        }
    }

    /// <summary>
    /// Adaptif polling — yaklaşan haber saatine göre hızlanır.
    /// </summary>
    private TimeSpan ComputeNextPollDelay()
    {
        // Cache'i sync olarak read etmek için ManifestCache repository istemiyoruz,
        // bu yüzden sadece system clock + bilinen haber saatlerini kullanıyoruz.
        // (Doğru manifest poll'dan sonra Polling Loop'un kendisi haberlere bakıp adaptif hızlanır)

        var now = DateTime.Now;
        // Türkiye haber kuşakları (HH:00) — master prompt'tan
        int[] newsHours = { 8, 10, 12, 14, 16, 18, 20 };

        TimeSpan minDistance = TimeSpan.MaxValue;
        foreach (var hour in newsHours)
        {
            var candidate = new DateTime(now.Year, now.Month, now.Day, hour, 0, 0);
            if (candidate < now) candidate = candidate.AddDays(1);
            var dist = candidate - now;
            if (dist < minDistance) minDistance = dist;
        }

        if (minDistance.TotalMinutes <= 5) return TimeSpan.FromSeconds(5);
        if (minDistance.TotalMinutes <= 10) return TimeSpan.FromSeconds(15);
        if (minDistance.TotalMinutes <= 20) return TimeSpan.FromSeconds(30);
        return TimeSpan.FromSeconds(_options.PollIntervalSeconds);
    }
}
