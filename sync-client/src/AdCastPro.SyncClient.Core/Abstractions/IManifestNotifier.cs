namespace AdCastPro.SyncClient.Core.Abstractions;

/// <summary>
/// Real-time manifest push notification kontratı.
/// SignalR Hub implementation Infrastructure katmanında.
///
/// Backend dosya yüklediğinde event fire eder; client polling beklemeden
/// hemen yeni manifest çeker. Polling fallback olarak kalır.
/// </summary>
public interface IManifestNotifier
{
    /// <summary>Backend'den manifest changed event geldi.</summary>
    event EventHandler<ManifestChangedArgs>? ManifestChanged;

    /// <summary>Acil yayın dosyası (Emergency file_type) — anında reaksiyon.</summary>
    event EventHandler<EmergencyBroadcastArgs>? EmergencyBroadcast;

    /// <summary>Yeni client sürümü mevcut — auto-updater tetiklenir.</summary>
    event EventHandler<UpdateAvailableArgs>? UpdateAvailable;

    /// <summary>Hub'a bağlan. Token ile authenticate.</summary>
    Task ConnectAsync(string accessToken, CancellationToken ct = default);

    Task DisconnectAsync(CancellationToken ct = default);

    /// <summary>true = hub bağlantısı aktif, false = polling-only modu.</summary>
    bool IsConnected { get; }
}

public sealed record ManifestChangedArgs(string RadioId, DateTimeOffset At, string Reason);
public sealed record EmergencyBroadcastArgs(string FileId, string Filename, string Priority);
public sealed record UpdateAvailableArgs(string LatestVersion, string DownloadUrl, string ReleaseNotes);
