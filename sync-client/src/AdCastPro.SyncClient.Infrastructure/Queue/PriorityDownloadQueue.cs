using System.Collections.Concurrent;
using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Core.Models;
using Microsoft.Extensions.Logging;

namespace AdCastPro.SyncClient.Infrastructure.Queue;

/// <summary>
/// Priority-aware download queue.
///
/// Per-priority FIFO ConcurrentQueue + SemaphoreSlim wait-for-item.
/// Dequeue: önce P1 (Emergency), sonra P2, ..., P5.
///
/// Aynı file_id tekrar enqueue edilirse skip (HashSet de-dup).
/// Parallel worker'lar aynı queue'dan çekebilir (thread-safe).
/// </summary>
public sealed class PriorityDownloadQueue : IDownloadQueue
{
    // Priority 1-9 → FIFO queue
    private readonly ConcurrentDictionary<int, ConcurrentQueue<ManifestFile>> _buckets = new();
    private readonly ConcurrentDictionary<string, byte> _enqueuedFileIds = new();
    private readonly SemaphoreSlim _signal = new(0, int.MaxValue);
    private readonly ILogger<PriorityDownloadQueue> _logger;

    public int PendingCount
    {
        get
        {
            int total = 0;
            foreach (var bucket in _buckets.Values) total += bucket.Count;
            return total;
        }
    }

    public PriorityDownloadQueue(ILogger<PriorityDownloadQueue> logger)
    {
        _logger = logger;
    }

    public ValueTask EnqueueAsync(ManifestFile file, CancellationToken ct = default)
    {
        // De-dup — aynı file_id zaten queue'daysa skip
        if (!_enqueuedFileIds.TryAdd(file.FileId, 0))
        {
            return ValueTask.CompletedTask;
        }

        var priority = FileTypes.PriorityOf(file.FileType);
        var bucket = _buckets.GetOrAdd(priority, _ => new ConcurrentQueue<ManifestFile>());
        bucket.Enqueue(file);
        _signal.Release();

        _logger.LogDebug("Queue + {File} (type={Type}, p{Pri})", file.Filename, file.FileType, priority);
        return ValueTask.CompletedTask;
    }

    public async ValueTask<ManifestFile?> DequeueAsync(CancellationToken ct = default)
    {
        await _signal.WaitAsync(ct);

        // En düşük priority'den başlayarak (P1 önce)
        foreach (var priority in _buckets.Keys.OrderBy(k => k))
        {
            if (_buckets.TryGetValue(priority, out var bucket) && bucket.TryDequeue(out var file))
            {
                _enqueuedFileIds.TryRemove(file.FileId, out _);
                return file;
            }
        }

        // Queue boş ama signal gelmişti — empty manifest gibi rare race
        return null;
    }

    public IReadOnlyDictionary<int, int> PendingByPriority()
    {
        var result = new Dictionary<int, int>();
        foreach (var (priority, bucket) in _buckets)
        {
            result[priority] = bucket.Count;
        }
        return result;
    }
}
