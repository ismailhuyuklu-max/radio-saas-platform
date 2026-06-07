using System.Threading.Channels;
using AdCastPro.SyncClient.App;
using AdCastPro.SyncClient.App.Workers;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Core.Models;
using AdCastPro.SyncClient.Infrastructure;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Serilog;

var builder = Host.CreateApplicationBuilder(args);

// Serilog — file + console
var logDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AdCastPro", "Logs");
Directory.CreateDirectory(logDir);

Log.Logger = new LoggerConfiguration()
    .ReadFrom.Configuration(builder.Configuration)
    .MinimumLevel.Debug()
    .Enrich.WithProperty("Source", "AdCastPro.SyncClient")
    .WriteTo.Console()
    .WriteTo.File(
        path: Path.Combine(logDir, "sync-.log"),
        rollingInterval: RollingInterval.Day,
        retainedFileCountLimit: 14,
        outputTemplate: "[{Timestamp:yyyy-MM-dd HH:mm:ss.fff} {Level:u3}] {Message:lj} {Properties:j}{NewLine}{Exception}"
    )
    .CreateLogger();

builder.Logging.ClearProviders();
builder.Logging.AddSerilog();

// Options
builder.Services.Configure<SyncClientOptions>(builder.Configuration.GetSection(SyncClientOptions.SectionName));

// Infrastructure (IApiClient, ITokenStore, ILocalCache, IAtomicFileWriter, IChecksumService, ResiliencePipeline)
builder.Services.AddSyncClientInfrastructure();

// Download channel — bounded (geri basınç: 1000 dosya cap)
builder.Services.AddSingleton(Channel.CreateBounded<ManifestFile>(new BoundedChannelOptions(1000)
{
    SingleReader = true,
    SingleWriter = false,
    FullMode = BoundedChannelFullMode.Wait,
}));

// Hosted services
builder.Services.AddHostedService<ManifestPollerService>();
builder.Services.AddHostedService<DownloadWorker>();
builder.Services.AddHostedService<HeartbeatService>();

// Broadcast readiness (UI'da kullanılır + log)
builder.Services.AddSingleton<BroadcastReadinessService>();

// Windows Service modu (eğer registered as service ise)
builder.Services.AddWindowsService(o => o.ServiceName = "AdCastPro.SyncClient");

var host = builder.Build();

// İlk açılışta DB + tablolar oluştur
await host.Services.EnsureDatabaseCreatedAsync();

Log.Information("AdCast Pro Sync Client başlatılıyor — v{Version}",
    builder.Configuration["SyncClient:ClientVersion"] ?? "1.0.0");

try
{
    await host.RunAsync();
}
catch (Exception ex)
{
    Log.Fatal(ex, "Uygulama beklenmedik şekilde sonlandı");
}
finally
{
    await Log.CloseAndFlushAsync();
}
