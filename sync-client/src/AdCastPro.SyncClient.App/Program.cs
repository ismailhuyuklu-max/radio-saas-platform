using System.Diagnostics;
using System.Threading.Channels;
using AdCastPro.SyncClient.App;
using AdCastPro.SyncClient.App.Workers;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Core.Models;
using AdCastPro.SyncClient.Infrastructure;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Hosting.WindowsServices;
using Microsoft.Extensions.Logging.EventLog;
using Serilog;
using Serilog.Events;

// =============================================================================
// AdCast Pro Sync Client — Background Worker Host
// =============================================================================
// Bu binary "AdCastProSyncService" adıyla Windows Service olarak kurulur.
// Aynı binary, --console flag ile foreground'da geliştirme modunda da çalışır.
//
// Logging:
//   - File: %ProgramData%\AdCastPro\Logs\sync-{Date}.log (Serilog rolling)
//   - Console: development sırasında
//   - Windows Event Log: Application kaynak "AdCastProSync" (production)
//
// Service davranışı:
//   - SCM (Service Control Manager) tarafından başlatılır
//   - LocalSystem hesabıyla çalışır
//   - Crash sonrası otomatik restart (60s — WiX recovery policy)
//   - Network bağımlılıkları: Tcpip + Dnscache
// =============================================================================

var isWindowsService = WindowsServiceHelpers.IsWindowsService();
var isConsoleMode = args.Contains("--console");

// ProgramData log dizini (servis modunda LocalSystem yazabilir; user profile yok)
var logDir = isWindowsService
    ? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "AdCastPro", "Logs")
    : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AdCastPro", "Logs");

try
{
    Directory.CreateDirectory(logDir);
}
catch (Exception ex)
{
    // Servis erken hata: Event Log'a yaz
    TryWriteEventLog("AdCast Pro Sync Service log dizini oluşturulamadı: " + ex.Message, EventLogEntryType.Error);
    return 1;
}

// Serilog — file + console + (production'da) event log
var serilogConfig = new LoggerConfiguration()
    .MinimumLevel.Debug()
    .MinimumLevel.Override("Microsoft.Hosting.Lifetime", LogEventLevel.Information)
    .MinimumLevel.Override("Microsoft.EntityFrameworkCore", LogEventLevel.Warning)
    .Enrich.WithProperty("Source", "AdCastPro.SyncClient")
    .Enrich.WithProperty("Host", Environment.MachineName)
    .Enrich.WithProperty("ServiceMode", isWindowsService ? "service" : "console")
    .WriteTo.File(
        path: Path.Combine(logDir, "sync-.log"),
        rollingInterval: RollingInterval.Day,
        retainedFileCountLimit: 14,
        fileSizeLimitBytes: 50_000_000,
        rollOnFileSizeLimit: true,
        outputTemplate: "[{Timestamp:yyyy-MM-dd HH:mm:ss.fff} {Level:u3}] {Message:lj} {Properties:j}{NewLine}{Exception}"
    );

if (!isWindowsService || isConsoleMode)
{
    serilogConfig = serilogConfig.WriteTo.Console();
}

Log.Logger = serilogConfig.CreateLogger();

try
{
    Log.Information("AdCast Pro Sync Client başlatılıyor — mode={Mode}, version={Version}",
        isWindowsService ? "Windows Service" : "Console",
        ThisAssembly.Version);

    var builder = Host.CreateApplicationBuilder(args);

    // Service modunda systemd benzeri Windows Service host kullan
    if (isWindowsService)
    {
        builder.Services.AddWindowsService(options =>
        {
            options.ServiceName = "AdCastProSyncService";
        });

        // Event Log sink — production'da kritik hatalar Event Viewer'da görünür
        builder.Logging.AddEventLog(new EventLogSettings
        {
            SourceName = "AdCastProSync",
            LogName = "Application",
        });
    }

    builder.Logging.ClearProviders();
    builder.Logging.AddSerilog();
    if (isWindowsService)
    {
        builder.Logging.AddEventLog(new EventLogSettings
        {
            SourceName = "AdCastProSync",
            LogName = "Application",
        });
    }

    // Options binding
    builder.Services.Configure<SyncClientOptions>(builder.Configuration.GetSection(SyncClientOptions.SectionName));

    // Infrastructure (IApiClient, ITokenStore, ILocalCache, IAtomicFileWriter,
    //                IChecksumService, IDownloadQueue, IManifestNotifier, IAutoUpdater)
    builder.Services.AddSyncClientInfrastructure();

    // Legacy unbounded channel — geri uyumluluk (DownloadWorker eski API)
    builder.Services.AddSingleton(Channel.CreateBounded<ManifestFile>(new BoundedChannelOptions(1000)
    {
        SingleReader = true,
        SingleWriter = false,
        FullMode = BoundedChannelFullMode.Wait,
    }));

    // Hosted services — sırayla çalışırlar
    builder.Services.AddHostedService<ManifestPollerService>();
    builder.Services.AddHostedService<DownloadWorker>();
    builder.Services.AddHostedService<HeartbeatService>();

    // Broadcast readiness
    builder.Services.AddSingleton<BroadcastReadinessService>();

    var host = builder.Build();

    // İlk açılışta DB + tablolar (Service modunda CommonAppData içinde DB)
    await host.Services.EnsureDatabaseCreatedAsync();

    Log.Information("AdCast Pro Sync Service hazır, host.RunAsync() başlatılıyor");

    TryWriteEventLog("AdCast Pro Sync Service başlatıldı", EventLogEntryType.Information);

    await host.RunAsync();

    Log.Information("AdCast Pro Sync Service düzgün şekilde durduruldu");
    TryWriteEventLog("AdCast Pro Sync Service durduruldu", EventLogEntryType.Information);
    return 0;
}
catch (Exception ex)
{
    Log.Fatal(ex, "AdCast Pro Sync Service beklenmedik şekilde sonlandı");
    TryWriteEventLog($"AdCast Pro Sync Service KRİTİK HATA: {ex.GetType().Name} — {ex.Message}", EventLogEntryType.Error);
    return 1;
}
finally
{
    await Log.CloseAndFlushAsync();
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/// <summary>
/// Windows Event Log'a yaz. Service modunda Event Viewer'dan görüntülenir;
/// WiX installer EventLogCmp ile "AdCastProSync" kaynağını önceden register etmiş.
/// Hata oluşursa sessizce devam (Event Log API başarısızlığı uygulamayı çökertmemeli).
/// </summary>
static void TryWriteEventLog(string message, EventLogEntryType type)
{
    if (!OperatingSystem.IsWindows()) return;
    try
    {
        if (!EventLog.SourceExists("AdCastProSync"))
        {
            // İlk çalıştırma — admin olmadan event source create edilmez,
            // WiX installer zaten yapmış olmalı. Yine de try-create.
            EventLog.CreateEventSource("AdCastProSync", "Application");
        }
        EventLog.WriteEntry("AdCastProSync", message, type);
    }
    catch
    {
        // Event Log yazma hatası — devam et
    }
}

// Generated by Source Generator — basit fallback:
internal static class ThisAssembly
{
    public static string Version => typeof(ThisAssembly).Assembly.GetName().Version?.ToString() ?? "1.0.0.0";
}
