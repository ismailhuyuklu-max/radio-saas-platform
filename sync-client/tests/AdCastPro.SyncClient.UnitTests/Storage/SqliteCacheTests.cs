using AdCastPro.SyncClient.Core.Models;
using AdCastPro.SyncClient.Infrastructure.Storage;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging.Abstractions;
using Xunit;

namespace AdCastPro.SyncClient.UnitTests.Storage;

public class SqliteCacheTests : IAsyncLifetime
{
    private IDbContextFactory<AppDbContext> _factory = null!;
    private SqliteCache _cache = null!;
    private string _dbPath = null!;

    public async Task InitializeAsync()
    {
        _dbPath = Path.Combine(Path.GetTempPath(), $"adcasttest_{Guid.NewGuid():N}.db");
        var opts = new DbContextOptionsBuilder<AppDbContext>()
            .UseSqlite($"Data Source={_dbPath}")
            .Options;
        _factory = new TestDbContextFactory(opts);
        await using var db = await _factory.CreateDbContextAsync();
        await db.Database.EnsureCreatedAsync();
        _cache = new SqliteCache(_factory, NullLogger<SqliteCache>.Instance);
    }

    public Task DisposeAsync()
    {
        try { File.Delete(_dbPath); } catch { }
        return Task.CompletedTask;
    }

    [Fact]
    public async Task SaveAndLoadManifest_RoundTrip_AyniVeri()
    {
        var manifest = new Manifest
        {
            GeneratedAt = DateTimeOffset.UtcNow,
            WindowStart = DateTimeOffset.UtcNow,
            WindowEnd = DateTimeOffset.UtcNow.AddHours(24),
            RadioId = "radio-1",
            FileCount = 1,
            Files = new List<ManifestFile>
            {
                new()
                {
                    FileId = "f1",
                    FileType = "news",
                    Filename = "h08.mp3",
                    SizeBytes = 1000,
                    ChecksumSha256 = "abc",
                    DownloadUrl = "/x",
                }
            }
        };

        await _cache.SaveManifestAsync(manifest, etag: "\"v1\"");
        var (loaded, etag) = await _cache.LoadManifestAsync();

        loaded.Should().NotBeNull();
        loaded!.RadioId.Should().Be("radio-1");
        loaded.Files.Should().HaveCount(1);
        etag.Should().Be("\"v1\"");
    }

    [Fact]
    public async Task RecordAndIsAlreadyDownloaded_AyniChecksum_True()
    {
        var tempFile = Path.GetTempFileName();
        try
        {
            await _cache.RecordDownloadAsync("f1", "h08.mp3", tempFile, "checksum-abc", 1000);

            (await _cache.IsAlreadyDownloadedAsync("f1", "checksum-abc")).Should().BeTrue();
            (await _cache.IsAlreadyDownloadedAsync("f1", "different-checksum")).Should().BeFalse();
            (await _cache.IsAlreadyDownloadedAsync("f2", "checksum-abc")).Should().BeFalse();
        }
        finally
        {
            File.Delete(tempFile);
        }
    }

    [Fact]
    public async Task IsAlreadyDownloaded_DiskteDosyaYok_FalseDoner()
    {
        var nonExistent = Path.Combine(Path.GetTempPath(), $"yok_{Guid.NewGuid():N}.mp3");
        await _cache.RecordDownloadAsync("f1", "h08.mp3", nonExistent, "abc", 1000);
        (await _cache.IsAlreadyDownloadedAsync("f1", "abc")).Should().BeFalse();
    }

    [Fact]
    public async Task ListRecentDownloads_Limit_DogruSayi()
    {
        for (int i = 0; i < 30; i++)
        {
            var temp = Path.GetTempFileName();
            await _cache.RecordDownloadAsync($"f{i}", $"h{i}.mp3", temp, $"chk{i}", 100);
        }
        var recent = await _cache.ListRecentDownloadsAsync(10);
        recent.Should().HaveCount(10);
    }

    private sealed class TestDbContextFactory : IDbContextFactory<AppDbContext>
    {
        private readonly DbContextOptions<AppDbContext> _opts;
        public TestDbContextFactory(DbContextOptions<AppDbContext> opts) => _opts = opts;
        public AppDbContext CreateDbContext() => new(_opts);
    }
}
