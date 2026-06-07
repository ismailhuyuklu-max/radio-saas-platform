using System.Net;
using System.Net.Http.Headers;
using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Models;
using Microsoft.Extensions.Logging;

namespace AdCastPro.SyncClient.Infrastructure.Api;

/// <summary>
/// HttpClient pipeline'a Authorization Bearer header'ı otomatik ekler.
/// 401 dönerse refresh token ile yeniden dener (bir kez — sonsuz loop koruması).
///
/// Login + Refresh endpoint'leri için auth header eklenmez (zaten token üretiyor).
/// </summary>
public sealed class AuthDelegatingHandler : DelegatingHandler
{
    private readonly ITokenStore _tokenStore;
    private readonly ILogger<AuthDelegatingHandler> _logger;
    private static readonly SemaphoreSlim _refreshLock = new(1, 1);

    public AuthDelegatingHandler(ITokenStore tokenStore, ILogger<AuthDelegatingHandler> logger)
    {
        _tokenStore = tokenStore;
        _logger = logger;
    }

    protected override async Task<HttpResponseMessage> SendAsync(
        HttpRequestMessage request,
        CancellationToken cancellationToken)
    {
        var path = request.RequestUri?.AbsolutePath ?? "";

        // Login + refresh endpoint'lerinde auth header yok
        bool skipAuth = path.EndsWith("/sync/login", StringComparison.OrdinalIgnoreCase)
                     || path.EndsWith("/sync/refresh", StringComparison.OrdinalIgnoreCase);

        if (!skipAuth)
        {
            var (tokens, _, _) = await _tokenStore.LoadAsync(cancellationToken);
            if (tokens != null)
            {
                request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", tokens.AccessToken);
            }
        }

        var response = await base.SendAsync(request, cancellationToken);

        // 401 → token expire olmuş olabilir, refresh dene
        if (response.StatusCode == HttpStatusCode.Unauthorized && !skipAuth)
        {
            response.Dispose();
            await _refreshLock.WaitAsync(cancellationToken);
            try
            {
                var refreshed = await TryRefreshAsync(request, cancellationToken);
                if (refreshed)
                {
                    // Yeni access token ile request'i kopyala + yeniden gönder
                    var (newTokens, _, _) = await _tokenStore.LoadAsync(cancellationToken);
                    if (newTokens != null)
                    {
                        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", newTokens.AccessToken);
                        return await base.SendAsync(request, cancellationToken);
                    }
                }
            }
            finally
            {
                _refreshLock.Release();
            }
            // Refresh başarısızsa orijinal 401'i geri döndür
            return new HttpResponseMessage(HttpStatusCode.Unauthorized);
        }

        return response;
    }

    private async Task<bool> TryRefreshAsync(HttpRequestMessage originalRequest, CancellationToken ct)
    {
        try
        {
            var (tokens, user, radio) = await _tokenStore.LoadAsync(ct);
            if (tokens?.RefreshToken == null) return false;

            var baseUri = originalRequest.RequestUri!.GetLeftPart(UriPartial.Authority);
            using var client = new HttpClient { BaseAddress = new Uri(baseUri) };

            var refreshBody = new StringContent(
                System.Text.Json.JsonSerializer.Serialize(new { refresh_token = tokens.RefreshToken }),
                System.Text.Encoding.UTF8,
                "application/json"
            );

            var resp = await client.PostAsync("/api/v1/sync/refresh", refreshBody, ct);
            if (!resp.IsSuccessStatusCode) return false;

            var payload = await resp.Content.ReadAsStringAsync(ct);
            var parsed = System.Text.Json.JsonSerializer.Deserialize<RefreshResponse>(payload, JsonOpts);
            if (parsed?.Result == null) return false;

            var newTokens = new AuthTokens(
                AccessToken: parsed.Result.AccessToken,
                RefreshToken: parsed.Result.RefreshToken,
                ExpiresIn: parsed.Result.ExpiresIn,
                IssuedAt: DateTimeOffset.UtcNow
            );
            await _tokenStore.SaveAsync(newTokens, user ?? new UserInfo("", "", ""), radio, ct);
            _logger.LogInformation("Token başarıyla refresh edildi");
            return true;
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Token refresh başarısız — kullanıcı tekrar login yapmalı");
            return false;
        }
    }

    private static readonly System.Text.Json.JsonSerializerOptions JsonOpts = new()
    {
        PropertyNameCaseInsensitive = true,
        PropertyNamingPolicy = System.Text.Json.JsonNamingPolicy.SnakeCaseLower,
    };

    private sealed record RefreshResponse(int Code, RefreshResult? Result, string Message);
    private sealed record RefreshResult(string AccessToken, string RefreshToken, int ExpiresIn);
}
