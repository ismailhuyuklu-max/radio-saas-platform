using System.Diagnostics;
using System.Threading.Channels;
using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Core.Models;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace AdCastPro.SyncClient.App.Workers;

/// <summary>
/// Channel'dan dosya alır, AtomicFileWriter ile indirir, checksum doğrular,
/// hedef klasöre atomic taşır, backend'e report POST atar.
///
/// Yayıncılık garantileri:
///   ✓ Yarım dosya hedef klasöre düşmez (AtomicFileWriter)
///   ✓ Checksum eşleşmezse temp silinir + failed report
///   ✓ Aynı checksum varsa skip (cache hit)
///   ✓ Disk dolu pre-flight check
/// </summary>
public sealed class DownloadWorker : BackgroundService
{
    private readonly IApiClient _api;
    private readonly IAtomicFileWriter _writer;
    private readonly ILocalCache _cache;
    private readonly Channel<ManifestFile> _channel;
    private readonly SyncClientOptions _options;
    private readonly ILogger<DownloadWorker> _logger;

    public DownloadWorker(
        IApiClient api,
        IAtomicFileWriter writer,
        ILocalCache cache,
        Channel<ManifestFile> channel,
        IOptions<SyncClientOptions> options,
        ILogger<DownloadWorker> logger)
    {
        _api = api;
        _writer = writer;
        _cache = cache;
        _channel = channel;
        _options = options.Value;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        _logger.LogInformation("DownloadWorker başlatıldı");
        await foreach (var file in _channel.Reader.ReadAllAsync(stoppingToken))
        {
            try
            {
                await DownloadOneAsync(file, stoppingToken);
            }
            catch (OperationCanceledException) { break; }
            catch (Exception ex)
            {
                _logger.LogError(ex, "Download başarısız: {FileId} {Filename}", file.FileId, file.Filename);
                await SafeReport(file.FileId, "failed", 0, false, 0, stoppingToken);
            }
        }
    }

    private async Task DownloadOneAsync(ManifestFile file, CancellationToken ct)
    {
        // 1. Cache check
        if (!string.IsNullOrEmpty(file.ChecksumSha256) &&
            await _cache.IsAlreadyDownloadedAsync(file.FileId, file.ChecksumSha256, ct))
        {
            _logger.LogDebug("Skip — zaten disk'te: {Filename}", file.Filename);
            return;
        }

        // 2. Hedef klasör
        var targetDir = _options.Folders.ResolveForType(file.FileType);

        // 3. Disk free pre-flight
        var freeBytes = _writer.GetFreeBytes(targetDir);
        if (freeBytes < file.SizeBytes * 3 / 2)
        {
            _logger.LogError(
                "Yetersiz disk alanı ({Free:F1} GB) — {File} indirilemiyor",
                freeBytes / 1_073_741_824.0, file.Filename);
            await SafeReport(file.FileId, "failed", 0, false, 0, ct);
            return;
        }

        // 4. Resume support — partial dosya varsa byte sayısı
        var partial = _writer.GetPartialByteCount(targetDir, file.Filename);
        long? rangeStart = partial > 0 && partial < file.SizeBytes ? partial : null;

        // 5. Download
        var sw = Stopwatch.StartNew();
        _logger.LogInformation("Download başlıyor: {Filename} ({Size:F1} MB){Resume}",
            file.Filename, file.SizeBytes / 1_048_576.0,
            rangeStart.HasValue ? $" — resume @{rangeStart} byte" : "");

        await using var stream = await _api.DownloadAsync(file.FileId, rangeStart, ct);

        // 6. Atomic write + checksum
        var finalPath = await _writer.WriteAtomicAsync(
            source: stream,
            targetDirectory: targetDir,
            filename: file.Filename,
            expectedSha256: file.ChecksumSha256,
            expectedSizeBytes: file.SizeBytes,
            ct: ct
        );
        sw.Stop();

        // 7. Cache + backend report
        await _cache.RecordDownloadAsync(
            fileId: file.FileId,
            filename: file.Filename,
            targetPath: finalPath,
            checksum: file.ChecksumSha256,
            sizeBytes: file.SizeBytes,
            ct: ct
        );

        await SafeReport(file.FileId, "success", file.SizeBytes, true, (int)sw.ElapsedMilliseconds, ct);
        _logger.LogInformation(
            "Download tamam: {Filename} {Ms} ms ({MBps:F1} MB/s)",
            file.Filename, sw.ElapsedMilliseconds,
            file.SizeBytes / 1_048_576.0 / sw.Elapsed.TotalSeconds);
    }

    private async Task SafeReport(string fileId, string status, long bytes, bool checksumOk, int durationMs, CancellationToken ct)
    {
        try
        {
            await _api.ReportAsync(new SyncReport
            {
                FileId = fileId,
                Status = status,
                BytesDownloaded = bytes,
                ChecksumOk = checksumOk,
                DurationMs = durationMs,
            }, ct);
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Report POST başarısız (sync devam ediyor): {FileId}", fileId);
        }
    }
}
