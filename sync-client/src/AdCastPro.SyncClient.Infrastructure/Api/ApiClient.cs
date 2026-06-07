using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Core.Models;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Polly;

namespace AdCastPro.SyncClient.Infrastructure.Api;

/// <summary>
/// AdCast Pro backend client. HttpClient + Polly resilience pipeline.
/// Auth header AuthDelegatingHandler tarafından eklenir; bu sınıf temiz API çağrılarına odaklanır.
/// </summary>
public sealed class ApiClient : IApiClient
{
    private readonly HttpClient _http;
    private readonly ResiliencePipeline<HttpResponseMessage> _pipeline;
    private readonly ILogger<ApiClient> _logger;

    private static readonly JsonSerializerOptions JsonOpts = new()
    {
        PropertyNameCaseInsensitive = true,
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
    };

    public ApiClient(
        HttpClient http,
        ResiliencePipeline<HttpResponseMessage> pipeline,
        IOptions<SyncClientOptions> options,
        ILogger<ApiClient> logger)
    {
        _http = http;
        _pipeline = pipeline;
        _logger = logger;
        _http.BaseAddress ??= new Uri(options.Value.ApiBaseUrl.TrimEnd('/') + "/");
        _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        _http.DefaultRequestHeaders.UserAgent.ParseAdd($"AdCastPro.SyncClient/{options.Value.ClientVersion} (.NET 8.0; Windows)");
    }

    public async Task<LoginResponse> LoginAsync(LoginRequest request, CancellationToken ct = default)
    {
        var payload = new
        {
            username = request.Username,
            password = request.Password,
            client_version = request.ClientVersion,
            machine_id = request.MachineId,
        };

        var response = await PostAsync("api/v1/sync/login", payload, ct);
        var envelope = await ReadEnvelope<LoginRaw>(response, ct);
        if (envelope.Code != 0 || envelope.Result == null)
            throw new InvalidOperationException(envelope.Message ?? "Login başarısız");

        return MapLoginResponse(envelope.Result);
    }

    public async Task<AuthTokens> RefreshAsync(RefreshRequest request, CancellationToken ct = default)
    {
        var response = await PostAsync("api/v1/sync/refresh", new { refresh_token = request.RefreshToken }, ct);
        var envelope = await ReadEnvelope<RefreshRaw>(response, ct);
        if (envelope.Code != 0 || envelope.Result == null)
            throw new InvalidOperationException(envelope.Message ?? "Refresh başarısız");

        return new AuthTokens(envelope.Result.AccessToken, envelope.Result.RefreshToken, envelope.Result.ExpiresIn, DateTimeOffset.UtcNow);
    }

    public async Task<MeResponse> GetMeAsync(CancellationToken ct = default)
    {
        var response = await GetAsync("api/v1/sync/me", null, ct);
        var envelope = await ReadEnvelope<MeRaw>(response, ct);
        if (envelope.Code != 0 || envelope.Result == null)
            throw new InvalidOperationException(envelope.Message ?? "Me sorgulanamadı");

        return new MeResponse(
            User: new UserInfo(envelope.Result.User.Id, envelope.Result.User.Username, envelope.Result.User.Role),
            Radio: MapRadio(envelope.Result.Radio),
            Permissions: new Permissions(
                envelope.Result.Permissions?.News ?? true,
                envelope.Result.Permissions?.Ads ?? false,
                envelope.Result.Permissions?.MediaPlan ?? false,
                envelope.Result.Permissions?.Sponsor ?? false
            ),
            MinClientVersion: envelope.Result.MinClientVersion ?? "1.0.0"
        );
    }

    public async Task<Manifest?> GetManifestAsync(string? etag = null, DateTimeOffset? since = null, CancellationToken ct = default)
    {
        var path = since.HasValue
            ? $"api/v1/sync/manifest?since={Uri.EscapeDataString(since.Value.ToString("O"))}"
            : "api/v1/sync/manifest";

        var response = await GetAsync(path, etag, ct);
        if (response.StatusCode == HttpStatusCode.NotModified)
        {
            _logger.LogDebug("Manifest 304 Not Modified — cache geçerli");
            return null;
        }

        var envelope = await ReadEnvelope<ManifestRaw>(response, ct);
        if (envelope.Code != 0 || envelope.Result == null)
            throw new InvalidOperationException(envelope.Message ?? "Manifest alınamadı");

        return MapManifest(envelope.Result);
    }

    public async Task<Stream> DownloadAsync(string fileId, long? rangeStart = null, CancellationToken ct = default)
    {
        var req = new HttpRequestMessage(HttpMethod.Get, $"api/v1/sync/download/{Uri.EscapeDataString(fileId)}");
        if (rangeStart is { } start && start > 0)
        {
            req.Headers.Range = new RangeHeaderValue(start, null);
        }

        // Download için Polly retry DEĞIL (büyük dosyalar; 1-shot, resume ile devam et).
        var response = await _http.SendAsync(req, HttpCompletionOption.ResponseHeadersRead, ct);
        if (!response.IsSuccessStatusCode && response.StatusCode != HttpStatusCode.PartialContent)
        {
            var body = await response.Content.ReadAsStringAsync(ct);
            throw new HttpRequestException($"Download {fileId} failed: {response.StatusCode} — {body}");
        }

        return await response.Content.ReadAsStreamAsync(ct);
    }

    public async Task ReportAsync(SyncReport report, CancellationToken ct = default)
    {
        var payload = new
        {
            file_id = report.FileId,
            status = report.Status,
            bytes = report.BytesDownloaded,
            checksum_ok = report.ChecksumOk,
            duration_ms = report.DurationMs,
        };
        var response = await PostAsync("api/v1/sync/report", payload, ct);
        response.EnsureSuccessStatusCode();
    }

    public async Task SendHeartbeatAsync(Heartbeat heartbeat, CancellationToken ct = default)
    {
        var payload = new
        {
            client_version = heartbeat.ClientVersion,
            os = heartbeat.Os,
            disk_free_gb = heartbeat.DiskFreeGb,
        };
        var response = await PostAsync("api/v1/sync/heartbeat", payload, ct);
        response.EnsureSuccessStatusCode();
    }

    // ---------- Helpers ----------

    private Task<HttpResponseMessage> GetAsync(string path, string? etag, CancellationToken ct)
    {
        return _pipeline.ExecuteAsync(async token =>
        {
            var req = new HttpRequestMessage(HttpMethod.Get, path);
            if (!string.IsNullOrWhiteSpace(etag))
            {
                req.Headers.IfNoneMatch.ParseAdd(etag);
            }
            return await _http.SendAsync(req, token);
        }, ct).AsTask();
    }

    private Task<HttpResponseMessage> PostAsync<T>(string path, T body, CancellationToken ct)
    {
        return _pipeline.ExecuteAsync(async token =>
        {
            var content = JsonContent.Create(body, options: JsonOpts);
            return await _http.PostAsync(path, content, token);
        }, ct).AsTask();
    }

    private static async Task<Envelope<T>> ReadEnvelope<T>(HttpResponseMessage response, CancellationToken ct) where T : class
    {
        var raw = await response.Content.ReadAsStringAsync(ct);
        if (!response.IsSuccessStatusCode)
        {
            var error = SafeDeserialize<Envelope<T>>(raw);
            var msg = error?.Message ?? $"HTTP {(int)response.StatusCode} {response.StatusCode}";
            throw new HttpRequestException(msg, null, response.StatusCode);
        }
        var envelope = SafeDeserialize<Envelope<T>>(raw);
        return envelope ?? new Envelope<T>(1, null, "Boş yanıt");
    }

    private static U? SafeDeserialize<U>(string raw) where U : class
    {
        try { return JsonSerializer.Deserialize<U>(raw, JsonOpts); }
        catch { return null; }
    }

    private static LoginResponse MapLoginResponse(LoginRaw raw)
    {
        return new LoginResponse(
            Tokens: new AuthTokens(raw.AccessToken, raw.RefreshToken, raw.ExpiresIn, DateTimeOffset.UtcNow),
            User: new UserInfo(raw.User.Id, raw.User.Username, raw.User.Role),
            Radio: MapRadio(raw.Radio),
            MinClientVersion: raw.MinClientVersion ?? "1.0.0",
            NeedsUpdate: raw.NeedsUpdate
        );
    }

    private static RadioInfo? MapRadio(RadioRaw? raw)
    {
        if (raw == null) return null;
        return new RadioInfo(raw.Id, raw.Name, raw.Frequency, raw.Region, raw.Province, raw.NationalAccess);
    }

    private static Manifest MapManifest(ManifestRaw raw)
    {
        return new Manifest
        {
            GeneratedAt = raw.GeneratedAt,
            WindowStart = raw.WindowStart,
            WindowEnd = raw.WindowEnd,
            RadioId = raw.RadioId,
            FileCount = raw.FileCount,
            NextPollAfter = raw.NextPollAfter,
            Files = raw.Files.Select(f => new ManifestFile
            {
                FileId = f.FileId,
                FileType = f.FileType,
                Filename = f.Filename,
                SizeBytes = f.SizeBytes,
                ChecksumSha256 = f.ChecksumSha256 ?? "",
                ScheduledAirTime = f.ScheduledAirTime,
                AvailableFrom = f.AvailableFrom,
                ExpiresAt = f.ExpiresAt,
                Region = f.Region,
                City = f.City,
                PartCode = f.PartCode,
                Advertiser = f.Advertiser,
                PlacementType = f.PlacementType,
                Version = f.Version ?? "1",
                Priority = f.Priority,
                DownloadUrl = f.DownloadUrl,
            }).ToList(),
        };
    }

    // ---------- Wire DTO'ları (backend snake_case) ----------

    private sealed record Envelope<T>(int Code, T? Result, string? Message) where T : class;

    private sealed record LoginRaw(
        string AccessToken,
        string RefreshToken,
        int ExpiresIn,
        UserRaw User,
        RadioRaw? Radio,
        string? MinClientVersion,
        bool NeedsUpdate);

    private sealed record RefreshRaw(string AccessToken, string RefreshToken, int ExpiresIn);

    private sealed record MeRaw(UserRaw User, RadioRaw? Radio, PermsRaw? Permissions, string? MinClientVersion);

    private sealed record UserRaw(string Id, string Username, string Role);

    private sealed record RadioRaw(string Id, string Name, string? Frequency, string? Region, string? Province, bool NationalAccess);

    private sealed record PermsRaw(bool News, bool Ads, bool MediaPlan, bool Sponsor);

    private sealed record ManifestRaw(
        DateTimeOffset GeneratedAt,
        DateTimeOffset WindowStart,
        DateTimeOffset WindowEnd,
        string RadioId,
        int FileCount,
        int NextPollAfter,
        List<ManifestFileRaw> Files);

    private sealed record ManifestFileRaw(
        string FileId,
        string FileType,
        string Filename,
        long SizeBytes,
        string? ChecksumSha256,
        DateTimeOffset ScheduledAirTime,
        DateTimeOffset AvailableFrom,
        DateTimeOffset ExpiresAt,
        string? Region,
        string? City,
        string? PartCode,
        string? Advertiser,
        string? PlacementType,
        string? Version,
        int Priority,
        string DownloadUrl);
}
