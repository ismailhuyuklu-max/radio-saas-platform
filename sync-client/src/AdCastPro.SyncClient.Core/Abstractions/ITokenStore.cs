using AdCastPro.SyncClient.Core.Models;

namespace AdCastPro.SyncClient.Core.Abstractions;

/// <summary>
/// Token persist contract. Windows'ta DPAPI ile şifreli saklanır
/// (ProtectedData.Protect, LocalMachine scope). Plain text disk'e yazılmaz.
/// </summary>
public interface ITokenStore
{
    /// <summary>Token + radio bilgisini güvenli alana yaz (overwrite).</summary>
    Task SaveAsync(AuthTokens tokens, UserInfo user, RadioInfo? radio, CancellationToken ct = default);

    /// <summary>Stored token + user. Yoksa null.</summary>
    Task<(AuthTokens? Tokens, UserInfo? User, RadioInfo? Radio)> LoadAsync(CancellationToken ct = default);

    /// <summary>Logout — tüm token + user verilerini sil.</summary>
    Task ClearAsync(CancellationToken ct = default);
}

/// <summary>
/// Local manifest + dosya cache contract. SQLite + EF Core ile.
/// </summary>
public interface ILocalCache
{
    Task SaveManifestAsync(Manifest manifest, string etag, CancellationToken ct = default);
    Task<(Manifest? Manifest, string? Etag)> LoadManifestAsync(CancellationToken ct = default);

    Task RecordDownloadAsync(string fileId, string filename, string targetPath, string checksum, long sizeBytes, CancellationToken ct = default);
    Task<bool> IsAlreadyDownloadedAsync(string fileId, string expectedChecksum, CancellationToken ct = default);
    Task<IReadOnlyList<DownloadedFile>> ListRecentDownloadsAsync(int limit = 50, CancellationToken ct = default);
}

public sealed record DownloadedFile(
    string FileId,
    string Filename,
    string TargetPath,
    string Checksum,
    long SizeBytes,
    DateTimeOffset DownloadedAt
);
