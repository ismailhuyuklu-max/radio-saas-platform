namespace AdCastPro.SyncClient.Core.Abstractions;

/// <summary>
/// Atomic file write — broadcast-safe.
///
/// Yayıncılık garantisi: yarım dosya hedef klasöre asla düşmez.
///   1. Temp klasörüne stream (.partial uzantısı)
///   2. SHA-256 streaming hesapla
///   3. Beklenen checksum'la karşılaştır
///   4. Eşleşirse File.Move atomic (NTFS rename) → hedef klasör
///   5. Eşleşmezse .partial silinir, exception fırlatılır
/// </summary>
public interface IAtomicFileWriter
{
    /// <summary>
    /// Stream'i temp'e yaz, checksum doğrula, hedefe atomic taşı.
    /// </summary>
    /// <returns>Final destination path. Hata durumunda exception (temp temizlenir).</returns>
    Task<string> WriteAtomicAsync(
        Stream source,
        string targetDirectory,
        string filename,
        string expectedSha256,
        long expectedSizeBytes,
        CancellationToken ct = default
    );

    /// <summary>Range request resume desteği — temp'te partial varsa byte sayısı.</summary>
    long GetPartialByteCount(string targetDirectory, string filename);

    /// <summary>Disk free check — pre-flight, dosya inmeden önce.</summary>
    long GetFreeBytes(string path);
}

/// <summary>
/// SHA-256 streaming — büyük dosyalarda RAM verimli.
/// </summary>
public interface IChecksumService
{
    /// <summary>Stream'i okurken SHA-256 hesaplar. Stream sona kadar okunur.</summary>
    Task<string> ComputeSha256Async(Stream source, CancellationToken ct = default);

    /// <summary>Dosyayı aç + checksum hesapla.</summary>
    Task<string> ComputeFileSha256Async(string filePath, CancellationToken ct = default);
}
