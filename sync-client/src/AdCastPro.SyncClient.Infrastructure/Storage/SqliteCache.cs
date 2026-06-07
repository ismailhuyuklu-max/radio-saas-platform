using System.Text.Json;
using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Models;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;

namespace AdCastPro.SyncClient.Infrastructure.Storage;

/// <summary>
/// ILocalCache impl — EF Core + SQLite.
/// Migration EnsureCreatedAsync ile (küçük client, formal migration history gerek değil).
/// </summary>
public sealed class SqliteCache : ILocalCache
{
    private readonly IDbContextFactory<AppDbContext> _factory;
    private readonly ILogger<SqliteCache> _logger;

    public SqliteCache(IDbContextFactory<AppDbContext> factory, ILogger<SqliteCache> logger)
    {
        _factory = factory;
        _logger = logger;
    }

    public async Task SaveManifestAsync(Manifest manifest, string etag, CancellationToken ct = default)
    {
        await using var db = await _factory.CreateDbContextAsync(ct);
        var existing = await db.ManifestCache.FirstOrDefaultAsync(ct);
        var json = JsonSerializer.Serialize(manifest);

        if (existing == null)
        {
            db.ManifestCache.Add(new ManifestCacheEntity
            {
                ManifestJson = json,
                Etag = etag,
                FetchedAt = DateTimeOffset.UtcNow,
                GeneratedAt = manifest.GeneratedAt,
            });
        }
        else
        {
            existing.ManifestJson = json;
            existing.Etag = etag;
            existing.FetchedAt = DateTimeOffset.UtcNow;
            existing.GeneratedAt = manifest.GeneratedAt;
        }
        await db.SaveChangesAsync(ct);
        _logger.LogDebug("Manifest cache güncellendi: {Count} dosya, ETag {Etag}", manifest.FileCount, etag);
    }

    public async Task<(Manifest? Manifest, string? Etag)> LoadManifestAsync(CancellationToken ct = default)
    {
        await using var db = await _factory.CreateDbContextAsync(ct);
        var row = await db.ManifestCache.FirstOrDefaultAsync(ct);
        if (row == null) return (null, null);

        try
        {
            var manifest = JsonSerializer.Deserialize<Manifest>(row.ManifestJson);
            return (manifest, row.Etag);
        }
        catch (JsonException ex)
        {
            _logger.LogWarning(ex, "Manifest cache corrupted, sıfırlanıyor");
            db.ManifestCache.Remove(row);
            await db.SaveChangesAsync(ct);
            return (null, null);
        }
    }

    public async Task RecordDownloadAsync(string fileId, string filename, string targetPath, string checksum, long sizeBytes, CancellationToken ct = default)
    {
        await using var db = await _factory.CreateDbContextAsync(ct);
        // Aynı (file_id, checksum) kombinasyonu varsa skip (unique constraint zaten korur)
        var exists = await db.DownloadedFiles
            .AnyAsync(x => x.FileId == fileId && x.ChecksumSha256 == checksum, ct);
        if (exists) return;

        db.DownloadedFiles.Add(new DownloadedFileEntity
        {
            FileId = fileId,
            FileType = "news", // caller override edebilir; şimdilik default
            Filename = filename,
            TargetPath = targetPath,
            ChecksumSha256 = checksum,
            SizeBytes = sizeBytes,
            Version = "1",
            DownloadedAt = DateTimeOffset.UtcNow,
            ScheduledAirTime = DateTimeOffset.UtcNow,
        });
        await db.SaveChangesAsync(ct);
    }

    public async Task<bool> IsAlreadyDownloadedAsync(string fileId, string expectedChecksum, CancellationToken ct = default)
    {
        await using var db = await _factory.CreateDbContextAsync(ct);
        var row = await db.DownloadedFiles
            .FirstOrDefaultAsync(x => x.FileId == fileId && x.ChecksumSha256 == expectedChecksum, ct);
        if (row == null) return false;

        // Disk'te de var mı? Disk silindiyse re-download gerek.
        if (!File.Exists(row.TargetPath))
        {
            _logger.LogInformation("Disk'te dosya yok ({Path}), re-download gerekli", row.TargetPath);
            db.DownloadedFiles.Remove(row);
            await db.SaveChangesAsync(ct);
            return false;
        }
        return true;
    }

    public async Task<IReadOnlyList<DownloadedFile>> ListRecentDownloadsAsync(int limit = 50, CancellationToken ct = default)
    {
        await using var db = await _factory.CreateDbContextAsync(ct);
        // SQLite: DateTimeOffset ORDER BY native desteklenmiyor → AsAsyncEnumerable ile
        // client-side sort. Id descending (auto-increment) DownloadedAt ile aynı sıra.
        var rows = await db.DownloadedFiles
            .OrderByDescending(x => x.Id)
            .Take(limit)
            .ToListAsync(ct);
        return rows.Select(r => new DownloadedFile(r.FileId, r.Filename, r.TargetPath, r.ChecksumSha256, r.SizeBytes, r.DownloadedAt)).ToList();
    }
}
