namespace AdCastPro.SyncClient.Core.Models;

/// <summary>
/// Backend'in /api/v1/sync/manifest endpoint'ten dönen dosya kaydı.
/// Yayıncılık garantileri için tüm field'lar zorunlu — checksum eksikse
/// dosya indirilmez.
/// </summary>
public sealed record ManifestFile
{
    public required string FileId { get; init; }
    public required string FileType { get; init; }      // "news" | "ad" | "sponsor" | "media_plan"
    public required string Filename { get; init; }
    public long SizeBytes { get; init; }
    public required string ChecksumSha256 { get; init; }
    public DateTimeOffset ScheduledAirTime { get; init; }
    public DateTimeOffset AvailableFrom { get; init; }
    public DateTimeOffset ExpiresAt { get; init; }
    public string? Region { get; init; }
    public string? City { get; init; }
    public string? PartCode { get; init; }              // news: "haber08", "haber10"...
    public string? Advertiser { get; init; }            // ad
    public string? PlacementType { get; init; }         // sponsor: "intro" | "outro" | "ad"
    public string Version { get; init; } = "1";
    public int Priority { get; init; } = 5;
    public required string DownloadUrl { get; init; }   // /api/v1/sync/download/{fileId}
}

public sealed record Manifest
{
    public DateTimeOffset GeneratedAt { get; init; }
    public DateTimeOffset WindowStart { get; init; }
    public DateTimeOffset WindowEnd { get; init; }
    public required string RadioId { get; init; }
    public int FileCount { get; init; }
    public required IReadOnlyList<ManifestFile> Files { get; init; }
    public int NextPollAfter { get; init; } = 60;
}

/// <summary>POST /api/v1/sync/report — başarılı/başarısız raporu.</summary>
public sealed record SyncReport
{
    public required string FileId { get; init; }
    public required string Status { get; init; }        // "success" | "failed" | "partial"
    public long BytesDownloaded { get; init; }
    public bool ChecksumOk { get; init; }
    public int DurationMs { get; init; }
}

/// <summary>POST /api/v1/sync/heartbeat — periodic durum.</summary>
public sealed record Heartbeat
{
    public required string ClientVersion { get; init; }
    public required string Os { get; init; }
    public int DiskFreeGb { get; init; }
}
