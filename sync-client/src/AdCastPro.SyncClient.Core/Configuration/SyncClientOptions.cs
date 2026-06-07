namespace AdCastPro.SyncClient.Core.Configuration;

/// <summary>
/// Kullanıcı yapılandırması — appsettings.json + Settings UI üzerinden override.
/// </summary>
public sealed class SyncClientOptions
{
    public const string SectionName = "SyncClient";

    /// <summary>API base URL — production: https://adcastpro.com</summary>
    public string ApiBaseUrl { get; set; } = "https://adcastpro.com";

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
}

public sealed class FolderPaths
{
    public string News { get; set; } = @"C:\AdCastPro\Haberler";
    public string Ads { get; set; } = @"C:\AdCastPro\Reklamlar";
    public string MediaPlans { get; set; } = @"C:\AdCastPro\MedyaPlanlari";
    public string Sponsors { get; set; } = @"C:\AdCastPro\Sponsorlar";
    public string Archive { get; set; } = @"C:\AdCastPro\Archive";
    public string Temp { get; set; } = @"C:\AdCastPro\Temp";
    public string Logs { get; set; } = @"C:\AdCastPro\Logs";

    /// <summary>file_type → klasör mapping</summary>
    public string ResolveForType(string fileType) => fileType switch
    {
        "news" => News,
        "ad" => Ads,
        "media_plan" => MediaPlans,
        "sponsor" => Sponsors,
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
