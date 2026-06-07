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

public class BroadcastReadinessServiceTests
{
    private static (BroadcastReadinessService svc, Mock<ILocalCache> cache, Mock<IChecksumService> checksum, string baseDir) Build()
    {
        var baseDir = Path.Combine(Path.GetTempPath(), $"adcast_ready_{Guid.NewGuid():N}");
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

    [Fact]
    public async Task ManifestYok_Unknown()
    {
        var (svc, cache, _, _) = Build();
        cache.Setup(c => c.LoadManifestAsync(It.IsAny<CancellationToken>()))
             .ReturnsAsync(((Manifest?)null, (string?)null));

        var report = await svc.EvaluateAsync();
        report.Level.Should().Be(BroadcastReadinessService.ReadinessLevel.Unknown);
    }

    [Fact]
    public async Task DosyaVeChecksumOk_30dkSonra_Yesil()
    {
        var (svc, cache, checksum, baseDir) = Build();
        var newsDir = Path.Combine(baseDir, "haber");
        var filePath = Path.Combine(newsDir, "h.mp3");
        await File.WriteAllBytesAsync(filePath, new byte[] { 1, 2, 3 });

        cache.Setup(c => c.LoadManifestAsync(It.IsAny<CancellationToken>()))
             .ReturnsAsync((Mk(filePath, DateTimeOffset.UtcNow.AddMinutes(30)), "etag"));
        checksum.Setup(c => c.ComputeFileSha256Async(filePath, It.IsAny<CancellationToken>()))
                .ReturnsAsync("expected-hash");

        var report = await svc.EvaluateAsync();
        report.Level.Should().Be(BroadcastReadinessService.ReadinessLevel.Green);
    }

    [Fact]
    public async Task DosyaYok_35dkSonra_Sari()
    {
        // 35dk = warn threshold (15dk) × 2 üstü → YELLOW (bekleniyor, normal)
        var (svc, cache, _, baseDir) = Build();
        var newsDir = Path.Combine(baseDir, "haber");
        var missingPath = Path.Combine(newsDir, "yok.mp3");

        cache.Setup(c => c.LoadManifestAsync(It.IsAny<CancellationToken>()))
             .ReturnsAsync((Mk(missingPath, DateTimeOffset.UtcNow.AddMinutes(35)), "etag"));

        var report = await svc.EvaluateAsync();
        report.Level.Should().Be(BroadcastReadinessService.ReadinessLevel.Yellow);
    }

    [Fact]
    public async Task DosyaYok_10dkKaldi_Turuncu()
    {
        // 4-level: 5-30dk arası + dosya yok → ORANGE uyarı
        var (svc, cache, _, baseDir) = Build();
        var newsDir = Path.Combine(baseDir, "haber");
        var missingPath = Path.Combine(newsDir, "yok.mp3");

        cache.Setup(c => c.LoadManifestAsync(It.IsAny<CancellationToken>()))
             .ReturnsAsync((Mk(missingPath, DateTimeOffset.UtcNow.AddMinutes(10)), "etag"));

        var report = await svc.EvaluateAsync();
        report.Level.Should().Be(BroadcastReadinessService.ReadinessLevel.Orange);
    }

    [Fact]
    public async Task DosyaYok_3dkKaldi_Kirmizi()
    {
        // < 5dk + dosya yok → RED (kritik, yayın riski)
        var (svc, cache, _, baseDir) = Build();
        var newsDir = Path.Combine(baseDir, "haber");
        var missingPath = Path.Combine(newsDir, "yok.mp3");

        cache.Setup(c => c.LoadManifestAsync(It.IsAny<CancellationToken>()))
             .ReturnsAsync((Mk(missingPath, DateTimeOffset.UtcNow.AddMinutes(3)), "etag"));

        var report = await svc.EvaluateAsync();
        report.Level.Should().Be(BroadcastReadinessService.ReadinessLevel.Red);
    }

    private static Manifest Mk(string path, DateTimeOffset airTime)
    {
        var filename = Path.GetFileName(path);
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
                    Filename = filename,
                    ChecksumSha256 = "expected-hash",
                    ScheduledAirTime = airTime,
                    AvailableFrom = DateTimeOffset.UtcNow,
                    ExpiresAt = airTime.AddHours(1),
                    DownloadUrl = "/x",
                }
            }
        };
    }
}
