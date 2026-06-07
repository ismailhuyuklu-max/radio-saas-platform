using AdCastPro.SyncClient.App;
using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Core.Models;
using FluentAssertions;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Moq;
using Xunit;

namespace AdCastPro.SyncClient.UnitTests;

/// <summary>
/// FAZ 2.6 + Validation Aşama 5 — Haber kuşağı timing senaryoları.
/// Türkiye'de standart haber kuşakları: 08, 10, 12, 14, 16, 18, 20.
/// Her kuşak için: dosya hazır → YEŞIL, eksik 15+dk → SARI, eksik &lt;15dk → KIRMIZI.
/// </summary>
public class BroadcastTimingScenarios
{
    [Theory]
    [InlineData(8)]
    [InlineData(10)]
    [InlineData(12)]
    [InlineData(14)]
    [InlineData(16)]
    [InlineData(18)]
    [InlineData(20)]
    public async Task HaberKusagi_15dkOnce_DosyaHazirsa_Yesil(int hour)
    {
        var (svc, cache, checksum, baseDir) = BuildFixture();
        var newsDir = Path.Combine(baseDir, "haber");
        var filePath = Path.Combine(newsDir, $"haber{hour:D2}.mp3");
        await File.WriteAllBytesAsync(filePath, new byte[] { 1, 2, 3 });

        var airTime = NextOccurrenceOf(hour);
        cache.Setup(c => c.LoadManifestAsync(It.IsAny<CancellationToken>()))
             .ReturnsAsync((ManifestWith(filePath, airTime), "etag"));
        checksum.Setup(c => c.ComputeFileSha256Async(filePath, It.IsAny<CancellationToken>()))
                .ReturnsAsync("hash");

        var report = await svc.EvaluateAsync();

        // Air time 15dk+ önce ise dosya hazır → YEŞIL
        // Air time 15dk içinde ise + dosya hazır → YEŞIL (zaten ready)
        report.Level.Should().Be(BroadcastReadinessService.ReadinessLevel.Green);
        report.ChecksumVerified.Should().BeTrue();
    }

    [Theory]
    [InlineData(8)]
    [InlineData(10)]
    [InlineData(12)]
    [InlineData(14)]
    [InlineData(16)]
    [InlineData(18)]
    [InlineData(20)]
    public async Task HaberKusagi_5dkKaldi_DosyaYok_Kirmizi(int hour)
    {
        // 5dk kala = warn threshold (15dk) içinde + dosya yok = KRİTİK
        var (svc, cache, _, baseDir) = BuildFixture();
        var nonExistent = Path.Combine(baseDir, "haber", $"haber{hour:D2}.mp3");
        var fakeAirTime = DateTimeOffset.UtcNow.AddMinutes(5);
        cache.Setup(c => c.LoadManifestAsync(It.IsAny<CancellationToken>()))
             .ReturnsAsync((ManifestWith(nonExistent, fakeAirTime), "etag"));

        var report = await svc.EvaluateAsync();
        report.Level.Should().Be(BroadcastReadinessService.ReadinessLevel.Red);
    }

    [Fact]
    public async Task TumKusaklarHazir_Yesil()
    {
        // 7 kuşak için 7 dosya yarat, hepsi çek
        var (svc, cache, checksum, baseDir) = BuildFixture();
        var newsDir = Path.Combine(baseDir, "haber");
        var files = new List<ManifestFile>();

        foreach (var h in new[] { 8, 10, 12, 14, 16, 18, 20 })
        {
            var path = Path.Combine(newsDir, $"haber{h:D2}.mp3");
            await File.WriteAllBytesAsync(path, new byte[] { 1 });
            files.Add(new ManifestFile
            {
                FileId = $"f{h}",
                FileType = "news",
                Filename = $"haber{h:D2}.mp3",
                ChecksumSha256 = "hash",
                ScheduledAirTime = NextOccurrenceOf(h),
                AvailableFrom = DateTimeOffset.UtcNow,
                ExpiresAt = NextOccurrenceOf(h).AddHours(1),
                DownloadUrl = "/x",
            });
            checksum.Setup(c => c.ComputeFileSha256Async(path, It.IsAny<CancellationToken>()))
                    .ReturnsAsync("hash");
        }

        cache.Setup(c => c.LoadManifestAsync(It.IsAny<CancellationToken>()))
             .ReturnsAsync((new Manifest
             {
                 GeneratedAt = DateTimeOffset.UtcNow,
                 WindowStart = DateTimeOffset.UtcNow,
                 WindowEnd = DateTimeOffset.UtcNow.AddHours(24),
                 RadioId = "r1",
                 FileCount = files.Count,
                 Files = files,
             }, "etag"));

        var report = await svc.EvaluateAsync();
        report.Level.Should().Be(BroadcastReadinessService.ReadinessLevel.Green);
    }

    [Fact]
    public async Task GelecekteKusakYok_Yesil()
    {
        // Manifest var ama gelecekteki dosya yok → "Yaklaşan haber kuşağı yok" YEŞIL
        var (svc, cache, _, _) = BuildFixture();
        cache.Setup(c => c.LoadManifestAsync(It.IsAny<CancellationToken>()))
             .ReturnsAsync((new Manifest
             {
                 GeneratedAt = DateTimeOffset.UtcNow,
                 WindowStart = DateTimeOffset.UtcNow.AddDays(-1),
                 WindowEnd = DateTimeOffset.UtcNow.AddMinutes(-1),
                 RadioId = "r1",
                 FileCount = 0,
                 Files = new List<ManifestFile>(),
             }, "etag"));

        var report = await svc.EvaluateAsync();
        report.Level.Should().Be(BroadcastReadinessService.ReadinessLevel.Green);
    }

    private static (BroadcastReadinessService svc, Mock<ILocalCache> cache,
        Mock<IChecksumService> checksum, string baseDir) BuildFixture()
    {
        var baseDir = Path.Combine(Path.GetTempPath(), $"adcast_brd_{Guid.NewGuid():N}");
        Directory.CreateDirectory(Path.Combine(baseDir, "haber"));

        var cache = new Mock<ILocalCache>();
        var checksum = new Mock<IChecksumService>();
        var options = Options.Create(new SyncClientOptions
        {
            NewsReadyMinutesBefore = 15,
            Folders = new FolderPaths { News = Path.Combine(baseDir, "haber") }
        });
        var svc = new BroadcastReadinessService(cache.Object, checksum.Object, options, NullLogger<BroadcastReadinessService>.Instance);
        return (svc, cache, checksum, baseDir);
    }

    private static DateTimeOffset NextOccurrenceOf(int hour)
    {
        var now = DateTimeOffset.UtcNow;
        var candidate = new DateTimeOffset(now.Year, now.Month, now.Day, hour, 0, 0, now.Offset);
        if (candidate < now.AddMinutes(20)) candidate = candidate.AddDays(1);
        return candidate;
    }

    private static Manifest ManifestWith(string filePath, DateTimeOffset airTime)
    {
        return new Manifest
        {
            GeneratedAt = DateTimeOffset.UtcNow,
            WindowStart = DateTimeOffset.UtcNow,
            WindowEnd = DateTimeOffset.UtcNow.AddHours(24),
            RadioId = "r1",
            FileCount = 1,
            Files = new List<ManifestFile>
            {
                new()
                {
                    FileId = "f1",
                    FileType = "news",
                    Filename = Path.GetFileName(filePath),
                    ChecksumSha256 = "hash",
                    ScheduledAirTime = airTime,
                    AvailableFrom = DateTimeOffset.UtcNow,
                    ExpiresAt = airTime.AddHours(1),
                    DownloadUrl = "/x",
                }
            }
        };
    }
}
