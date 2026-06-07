using System.ComponentModel.DataAnnotations;

namespace AdCastPro.SyncClient.Infrastructure.Storage;

/// <summary>Key-value config storage — UI settings, runtime flags.</summary>
public sealed class SettingEntity
{
    [Key]
    [MaxLength(64)]
    public required string Key { get; set; }
    public required string Value { get; set; }
    public DateTimeOffset UpdatedAt { get; set; }
}

/// <summary>
/// Manifest cache — backend ETag ile birlikte. 304 dönerse local'den oku.
/// Tek bir aktif manifest var (radyo başına 1 client).
/// </summary>
public sealed class ManifestCacheEntity
{
    [Key] public int Id { get; set; }
    public required string ManifestJson { get; set; }      // serialize edilmiş Manifest
    public required string Etag { get; set; }
    public DateTimeOffset FetchedAt { get; set; }
    public DateTimeOffset GeneratedAt { get; set; }
}

/// <summary>
/// İndirilen dosya kaydı — re-download'ı önler.
/// Checksum eşleşiyorsa file_id + checksum kombinasyonu unique.
/// </summary>
public sealed class DownloadedFileEntity
{
    [Key] public int Id { get; set; }
    [MaxLength(64)] public required string FileId { get; set; }
    [MaxLength(64)] public required string FileType { get; set; }
    [MaxLength(255)] public required string Filename { get; set; }
    public required string TargetPath { get; set; }
    [MaxLength(64)] public required string ChecksumSha256 { get; set; }
    public long SizeBytes { get; set; }
    [MaxLength(32)] public required string Version { get; set; }
    public DateTimeOffset DownloadedAt { get; set; }
    public DateTimeOffset ScheduledAirTime { get; set; }
}

/// <summary>
/// Sync döngüsü tarihçesi — her manifest poll + sonuç.
/// NOC ekranı için kullanışlı + offline diagnostik.
/// </summary>
public sealed class SyncHistoryEntity
{
    [Key] public int Id { get; set; }
    public DateTimeOffset StartedAt { get; set; }
    public DateTimeOffset? CompletedAt { get; set; }
    public int ManifestFileCount { get; set; }
    public int DownloadedCount { get; set; }
    public int SkippedCount { get; set; }
    public int FailedCount { get; set; }
    public bool Success { get; set; }
    public string? Notes { get; set; }
}

/// <summary>Lokal hata log — Serilog file sink yedeği.</summary>
public sealed class ErrorLogEntity
{
    [Key] public int Id { get; set; }
    [MaxLength(32)] public required string Severity { get; set; }    // info / warn / error / critical
    [MaxLength(64)] public required string Category { get; set; }    // auth / download / disk / network / checksum
    public required string Message { get; set; }
    public string? StackTrace { get; set; }
    public string? Context { get; set; }                              // JSON: file_id, path, etc
    public DateTimeOffset OccurredAt { get; set; }
}
