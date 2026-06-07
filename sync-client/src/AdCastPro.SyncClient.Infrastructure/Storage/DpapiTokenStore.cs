using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Models;
using Microsoft.Extensions.Logging;

namespace AdCastPro.SyncClient.Infrastructure.Storage;

/// <summary>
/// Windows DPAPI ile şifrelenmiş token storage.
/// Dosya: %LOCALAPPDATA%\AdCastPro\tokens.dpapi
///
/// CurrentUser scope — sadece aynı Windows user oturumunda decrypt edilebilir.
/// Plain text token disk'e ASLA yazılmaz.
/// </summary>
public sealed class DpapiTokenStore : ITokenStore
{
    private readonly string _filePath;
    private readonly ILogger<DpapiTokenStore> _logger;
    private static readonly byte[] Entropy = Encoding.UTF8.GetBytes("AdCastPro.SyncClient.v1");
    private readonly SemaphoreSlim _lock = new(1, 1);

    public DpapiTokenStore(ILogger<DpapiTokenStore> logger)
    {
        _logger = logger;
        var baseDir = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "AdCastPro");
        Directory.CreateDirectory(baseDir);
        _filePath = Path.Combine(baseDir, "tokens.dpapi");
    }

    public async Task SaveAsync(AuthTokens tokens, UserInfo user, RadioInfo? radio, CancellationToken ct = default)
    {
        await _lock.WaitAsync(ct);
        try
        {
            var bundle = new StoredBundle(tokens, user, radio, DateTimeOffset.UtcNow);
            var json = JsonSerializer.Serialize(bundle);
            var plainBytes = Encoding.UTF8.GetBytes(json);

            byte[] encrypted = ProtectedData.Protect(plainBytes, Entropy, DataProtectionScope.CurrentUser);
            await File.WriteAllBytesAsync(_filePath, encrypted, ct);
            _logger.LogDebug("Tokens DPAPI ile şifreli olarak kaydedildi: {Path}", _filePath);
        }
        finally
        {
            _lock.Release();
        }
    }

    public async Task<(AuthTokens? Tokens, UserInfo? User, RadioInfo? Radio)> LoadAsync(CancellationToken ct = default)
    {
        if (!File.Exists(_filePath))
            return (null, null, null);

        await _lock.WaitAsync(ct);
        try
        {
            byte[] encrypted = await File.ReadAllBytesAsync(_filePath, ct);
            byte[] plain;
            try
            {
                plain = ProtectedData.Unprotect(encrypted, Entropy, DataProtectionScope.CurrentUser);
            }
            catch (CryptographicException ex)
            {
                _logger.LogWarning(ex, "DPAPI decrypt başarısız — büyük olasılıkla farklı user/machine, dosya siliniyor");
                File.Delete(_filePath);
                return (null, null, null);
            }

            var json = Encoding.UTF8.GetString(plain);
            var bundle = JsonSerializer.Deserialize<StoredBundle>(json);
            return (bundle?.Tokens, bundle?.User, bundle?.Radio);
        }
        finally
        {
            _lock.Release();
        }
    }

    public async Task ClearAsync(CancellationToken ct = default)
    {
        await _lock.WaitAsync(ct);
        try
        {
            if (File.Exists(_filePath))
            {
                File.Delete(_filePath);
                _logger.LogInformation("Tokens silindi (logout)");
            }
        }
        finally
        {
            _lock.Release();
        }
    }

    private sealed record StoredBundle(AuthTokens Tokens, UserInfo User, RadioInfo? Radio, DateTimeOffset SavedAt);
}
