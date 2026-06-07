namespace AdCastPro.SyncClient.Core.Configuration;

/// <summary>
/// Kullanıcı yapılandırması — appsettings.json + Settings UI üzerinden override.
/// </summary>
public sealed class SyncClientOptions
{
    public const string SectionName = "SyncClient";

    /// <summary>
    /// REST API endpoint (auth + manifest + reports + heartbeat).
    /// Production: https://api.adcastpro.com
    /// </summary>
    public string ApiBaseUrl { get; set; } = "https://api.adcastpro.com";

    /// <summary>
    /// Dosya dağıtım CDN/origin URL — signed-URL redirect hedefi.
    /// Production: https://files.adcastpro.com
    /// </summary>
    public string FilesBaseUrl { get; set; } = "https://files.adcastpro.com";

    /// <summary>
    /// SignalR Hub URL — real-time manifest push notification.
    /// Production: https://sync.adcastpro.com/hubs/manifest
    /// </summary>
    public string SignalRHubUrl { get; set; } = "https://sync.adcastpro.com/hubs/manifest";

    /// <summary>Bu client'in unique versiyonu — auto-update kontrolü.</summary>
    public string ClientVersion { get; set; } = "1.0.0";

    /// <summary>Manifest polling intervali (saniye). Default 60s, min 30s, max 300s.</summary>
    public int PollIntervalSeconds { get; set; } = 60;

    /// <summary>Heartbeat intervali (saniye). Default 60s.</summary>
    public int HeartbeatIntervalSeconds { get; set; } = 60;

    /// <summary>Klasör konfigürasyonu — kullanıcı Settings'ten seçer.</summary>
    public FolderPaths Folders { get; set; } = new();

    /// <summary>Network resilience — Polly retry policy parametreleri.</summary>
    public RetryPolicy Retry { get; set; } = new();

    /// <summary>Haber saatinden kaç dakika önce ready olmalı (uyarı eşiği).</summary>
    public int NewsReadyMinutesBefore { get; set; } = 15;

    /// <summary>Otomatik Windows başlangıcında çalış.</summary>
    public bool AutoStartWithWindows { get; set; } = true;

    /// <summary>Auto-update kontrolü interval (saat). 0 = kapalı.</summary>
    public int AutoUpdateCheckIntervalHours { get; set; } = 6;

    /// <summary>Paralel download worker sayısı. 1-8 önerilir.</summary>
    public int ParallelDownloadWorkers { get; set; } = 3;

    /// <summary>SignalR push notification etkin mi? Polling fallback her zaman çalışır.</summary>
    public bool SignalREnabled { get; set; } = true;
}

/// <summary>
/// Yayıncılık dosya tipleri — extensible enum pattern.
/// Hardcoded switch-case YASAK. Yeni file_type eklemek için sadece sabit ekle.
/// </summary>
public static class FileTypes
{
    public const string News = "news";
    public const string Advertisement = "ad";
    public const string Sponsor = "sponsor";
    public const string MediaPlan = "media_plan";
    public const string Emergency = "emergency";
    public const string Promo = "promo";
    public const string Jingle = "jingle";
    public const string RegionalContent = "regional";
    public const string NationalContent = "national";

    public static readonly string[] All =
    {
        News, Advertisement, Sponsor, MediaPlan,
        Emergency, Promo, Jingle, RegionalContent, NationalContent
    };

    /// <summary>Priority queue ağırlığı — kritik dosya önce iner.</summary>
    public static int PriorityOf(string fileType) => fileType switch
    {
        Emergency => 1,         // P1 — kritik, hemen
        News => 2,              // P2 — haber kuşağı
        Advertisement => 3,     // P3 — reklam
        Sponsor => 4,           // P4 — sponsor
        MediaPlan => 5,         // P5 — medya planı
        Promo or Jingle => 5,
        RegionalContent or NationalContent => 4,
        _ => 9                  // bilinmeyen → en sonda
    };
}

public sealed class FolderPaths
{
    public string News { get; set; } = @"D:\AdCastPro\News";
    public string Ads { get; set; } = @"D:\AdCastPro\Ads";
    public string MediaPlans { get; set; } = @"D:\AdCastPro\MediaPlan";
    public string Sponsors { get; set; } = @"D:\AdCastPro\Sponsors";
    public string Emergency { get; set; } = @"D:\AdCastPro\Emergency";
    public string Promo { get; set; } = @"D:\AdCastPro\Promo";
    public string Jingle { get; set; } = @"D:\AdCastPro\Jingle";
    public string Regional { get; set; } = @"D:\AdCastPro\Regional";
    public string National { get; set; } = @"D:\AdCastPro\National";
    public string Archive { get; set; } = @"D:\AdCastPro\Archive";
    public string Temp { get; set; } = @"D:\AdCastPro\Temp";
    public string Logs { get; set; } = @"D:\AdCastPro\Logs";

    /// <summary>
    /// file_type → klasör mapping. FileTypes sınıfında 9 tip; her birinin
    /// bağımsız klasörü. Yeni tip eklemek için FolderPaths'a alan + bu switch'e
    /// yön ekle.
    /// </summary>
    public string ResolveForType(string fileType) => fileType switch
    {
        FileTypes.News => News,
        FileTypes.Advertisement => Ads,
        FileTypes.MediaPlan => MediaPlans,
        FileTypes.Sponsor => Sponsors,
        FileTypes.Emergency => Emergency,
        FileTypes.Promo => Promo,
        FileTypes.Jingle => Jingle,
        FileTypes.RegionalContent => Regional,
        FileTypes.NationalContent => National,
        _ => Archive,
    };
}

public sealed class RetryPolicy
{
    public int MaxAttempts { get; set; } = 5;
    public int InitialDelayMs { get; set; } = 1000;
    public double BackoffMultiplier { get; set; } = 2.0;
    public int MaxDelayMs { get; set; } = 300_000; // 5 dk cap
}
