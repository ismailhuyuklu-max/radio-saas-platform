using Microsoft.EntityFrameworkCore;

namespace AdCastPro.SyncClient.Infrastructure.Storage;

/// <summary>
/// SQLite veritabanı — EF Core Code-First. Migration EnsureCreated() ile.
/// Dosya: %LOCALAPPDATA%\AdCastPro\sync.db
/// </summary>
public sealed class AppDbContext : DbContext
{
    public DbSet<SettingEntity> Settings => Set<SettingEntity>();
    public DbSet<ManifestCacheEntity> ManifestCache => Set<ManifestCacheEntity>();
    public DbSet<DownloadedFileEntity> DownloadedFiles => Set<DownloadedFileEntity>();
    public DbSet<SyncHistoryEntity> SyncHistory => Set<SyncHistoryEntity>();
    public DbSet<ErrorLogEntity> ErrorLogs => Set<ErrorLogEntity>();

    public AppDbContext(DbContextOptions<AppDbContext> options) : base(options) { }

    protected override void OnModelCreating(ModelBuilder modelBuilder)
    {
        modelBuilder.Entity<DownloadedFileEntity>(b =>
        {
            b.HasIndex(x => x.FileId);
            b.HasIndex(x => new { x.FileId, x.ChecksumSha256 }).IsUnique();
            b.HasIndex(x => x.DownloadedAt);
            b.HasIndex(x => x.ScheduledAirTime);
        });

        modelBuilder.Entity<SyncHistoryEntity>(b =>
        {
            b.HasIndex(x => x.StartedAt);
        });

        modelBuilder.Entity<ErrorLogEntity>(b =>
        {
            b.HasIndex(x => x.OccurredAt);
            b.HasIndex(x => x.Severity);
        });

        modelBuilder.Entity<ManifestCacheEntity>(b =>
        {
            b.HasIndex(x => x.FetchedAt);
        });
    }

    public static string GetDefaultDbPath()
    {
        var baseDir = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "AdCastPro");
        Directory.CreateDirectory(baseDir);
        return Path.Combine(baseDir, "sync.db");
    }
}
