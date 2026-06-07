using AdCastPro.SyncClient.Core.Models;

namespace AdCastPro.SyncClient.Core.Abstractions;

/// <summary>
/// Backend AdCast Pro API client kontratı. HTTP detayları Infrastructure'da.
/// </summary>
public interface IApiClient
{
    Task<LoginResponse> LoginAsync(LoginRequest request, CancellationToken ct = default);

    Task<AuthTokens> RefreshAsync(RefreshRequest request, CancellationToken ct = default);

    Task<MeResponse> GetMeAsync(CancellationToken ct = default);

    /// <summary>
    /// Manifest getirir. ETag varsa If-None-Match gönderir, 304 dönerse null
    /// (manifest değişmedi, mevcut cache'i kullan).
    /// </summary>
    Task<Manifest?> GetManifestAsync(string? etag = null, DateTimeOffset? since = null, CancellationToken ct = default);

    /// <summary>
    /// Download URL'i çağırır. Backend 302 redirect döner, HttpClient otomatik
    /// follow eder, dönüş dosya stream'i. Range request destekli (resume).
    /// </summary>
    Task<Stream> DownloadAsync(string fileId, long? rangeStart = null, CancellationToken ct = default);

    Task ReportAsync(SyncReport report, CancellationToken ct = default);

    Task SendHeartbeatAsync(Heartbeat heartbeat, CancellationToken ct = default);
}
