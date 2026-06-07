using AdCastPro.SyncClient.Core.Models;

namespace AdCastPro.SyncClient.Core.Abstractions;

/// <summary>
/// Priority download queue contract.
///
/// Önceliklendirme (FileTypes.PriorityOf):
///   P1 = Emergency
///   P2 = News
///   P3 = Advertisement
///   P4 = Sponsor / RegionalContent / NationalContent
///   P5 = MediaPlan / Promo / Jingle
///
/// Parallel worker count: SyncClientOptions.ParallelDownloadWorkers (1-8)
/// Aynı priority'de FIFO; düşük priority önce alınır (P1 > P5).
/// </summary>
public interface IDownloadQueue
{
    /// <summary>Dosyayı kuyruğa ekle (priority otomatik FileTypes.PriorityOf).</summary>
    ValueTask EnqueueAsync(ManifestFile file, CancellationToken ct = default);

    /// <summary>Sıradaki en yüksek-priority dosyayı çek. Worker'lar çağırır.</summary>
    ValueTask<ManifestFile?> DequeueAsync(CancellationToken ct = default);

    /// <summary>Bekleyen toplam dosya sayısı.</summary>
    int PendingCount { get; }

    /// <summary>Priority başına dağılım — UI dashboard için.</summary>
    IReadOnlyDictionary<int, int> PendingByPriority();
}
