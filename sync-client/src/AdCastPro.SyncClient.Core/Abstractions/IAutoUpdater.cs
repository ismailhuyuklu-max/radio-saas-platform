namespace AdCastPro.SyncClient.Core.Abstractions;

/// <summary>
/// Auto-updater contract.
///
/// Akış:
///   1. CheckForUpdateAsync — GET api/sync/update?current_version=X
///   2. Yeni versiyon varsa MSI'ı indir → temp
///   3. SHA-256 doğrula
///   4. Authenticode imza doğrula
///   5. Rollback noktası kaydet (current version backup)
///   6. msiexec /i installer.msi /quiet
///   7. Servis restart
///
/// Hata olursa rollback: önceki MSI'ı reinstall.
/// </summary>
public interface IAutoUpdater
{
    /// <summary>Backend'den son versiyon bilgisini sorgular.</summary>
    Task<UpdateInfo?> CheckForUpdateAsync(string currentVersion, CancellationToken ct = default);

    /// <summary>MSI'ı indirir + doğrular + kurar. Restart sonrası yeni versiyon çalışır.</summary>
    /// <returns>true = update başarılı, false = rollback yapıldı</returns>
    Task<bool> ApplyUpdateAsync(UpdateInfo info, CancellationToken ct = default);

    /// <summary>Son başarılı versiyona dön (manual rollback).</summary>
    Task<bool> RollbackAsync(CancellationToken ct = default);
}

public sealed record UpdateInfo(
    string LatestVersion,
    string DownloadUrl,
    string Sha256,
    bool Mandatory,
    string ReleaseNotes,
    DateTimeOffset ReleasedAt
);
