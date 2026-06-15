using System.IO;
using System.Linq;
using System.Threading;
using System.Threading.Channels;
using System.Windows;
using AdCastPro.SyncClient.App;
using AdCastPro.SyncClient.App.Workers;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Core.Models;
using AdCastPro.SyncClient.Infrastructure;
using AdCastPro.SyncClient.UI.Services;
using AdCastPro.SyncClient.UI.ViewModels;
using AdCastPro.SyncClient.UI.Views;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Serilog;

namespace AdCastPro.SyncClient.UI;

public partial class App : Application
{
    public static IHost? Host { get; private set; }
    public static IServiceProvider Services => Host?.Services
        ?? throw new InvalidOperationException("Host henüz başlatılmadı");

    private TrayIconHost? _trayHost;
    private MainWindow? _mainWindow;

    // Tek örnek (single-instance) — ikinci açılış engellenir, mevcut pencere öne gelir.
    private Mutex? _singleInstanceMutex;
    private EventWaitHandle? _activateEvent;
    private const string SingleInstanceMutexName = @"Local\AdCastProSyncClient.SingleInstance.v1";
    private const string ActivateEventName = @"Local\AdCastProSyncClient.Activate.v1";

    private async void OnStartup(object sender, StartupEventArgs e)
    {
        // ---- Tek örnek kilidi: yazılım ikinci kez açılamaz ----
        _singleInstanceMutex = new Mutex(initiallyOwned: true, SingleInstanceMutexName, out bool createdNew);
        if (!createdNew)
        {
            // Zaten çalışan bir örnek var → onu öne getir ve bu örneği sessizce kapat.
            try
            {
                if (EventWaitHandle.TryOpenExisting(ActivateEventName, out var existing))
                {
                    existing.Set();
                    existing.Dispose();
                }
            }
            catch { /* yoksay */ }
            Shutdown();
            return;
        }

        // İlk örnek: sonraki açılış denemelerini dinle → pencereyi öne getir.
        _activateEvent = new EventWaitHandle(false, EventResetMode.AutoReset, ActivateEventName);
        ThreadPool.RegisterWaitForSingleObject(
            _activateEvent,
            (_, __) => Dispatcher.BeginInvoke(new Action(BringExistingWindowToFront)),
            state: null,
            millisecondsTimeOutInterval: Timeout.Infinite,
            executeOnlyOnce: false);

        // Log dir
        var logDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AdCastPro", "Logs");
        Directory.CreateDirectory(logDir);

        Log.Logger = new LoggerConfiguration()
            .MinimumLevel.Debug()
            .Enrich.WithProperty("Source", "AdCastPro.SyncClient.UI")
            .WriteTo.File(
                path: Path.Combine(logDir, "ui-.log"),
                rollingInterval: RollingInterval.Day,
                retainedFileCountLimit: 14)
            .CreateLogger();

        // Global hata yakalayicilar — sessiz cokmeleri tam stack ile logla.
        AppDomain.CurrentDomain.UnhandledException += (_, ev) =>
            Log.Fatal(ev.ExceptionObject as Exception, "UNHANDLED AppDomain exception");
        DispatcherUnhandledException += (_, ev) =>
        {
            Log.Fatal(ev.Exception, "UNHANDLED Dispatcher exception");
            ev.Handled = true;
        };
        System.Threading.Tasks.TaskScheduler.UnobservedTaskException += (_, ev) =>
        {
            Log.Fatal(ev.Exception, "UNOBSERVED Task exception");
            ev.SetObserved();
        };

        // Content root = exe dizini (CWD degil) — appsettings.json her zaman exe
        // yanindan okunur, nereden baslatildigindan bagimsiz.
        var builder = Microsoft.Extensions.Hosting.Host.CreateApplicationBuilder(new HostApplicationBuilderSettings
        {
            ContentRootPath = AppContext.BaseDirectory,
        });
        builder.Logging.ClearProviders();
        builder.Logging.AddSerilog();

        builder.Services.Configure<SyncClientOptions>(builder.Configuration.GetSection(SyncClientOptions.SectionName));
        builder.Services.AddSyncClientInfrastructure();
        builder.Services.AddSingleton(Channel.CreateBounded<ManifestFile>(new BoundedChannelOptions(1000)));
        builder.Services.AddSingleton<BroadcastReadinessService>();

        // Hosted services (UI versiyonda da arka planda çalışır)
        builder.Services.AddHostedService<ManifestPollerService>();
        builder.Services.AddHostedService<DownloadWorker>();
        builder.Services.AddHostedService<HeartbeatService>();

        // UI servisleri + ViewModels + Windows
        builder.Services.AddSingleton<TrayIconHost>();   // DI'a kayitliydi degildi -> StartTrayMode patliyordu
        builder.Services.AddSingleton<NavigationService>();
        builder.Services.AddSingleton<LoginViewModel>();
        builder.Services.AddSingleton<MainViewModel>();
        builder.Services.AddSingleton<SettingsViewModel>();
        builder.Services.AddSingleton<LogsViewModel>();
        builder.Services.AddSingleton<SupportViewModel>();
        builder.Services.AddTransient<LoginWindow>();
        builder.Services.AddSingleton<MainWindow>();
        builder.Services.AddTransient<SettingsWindow>();
        builder.Services.AddTransient<LogsWindow>();

        Host = builder.Build();
        await Host.Services.EnsureDatabaseCreatedAsync();
        await Host.StartAsync();

        // Token var mı? Varsa direkt MainWindow + tray, yoksa Login
        var tokenStore = Host.Services.GetRequiredService<Core.Abstractions.ITokenStore>();
        var (tokens, _, _) = await tokenStore.LoadAsync();
        if (tokens != null)
        {
            StartTrayMode();
        }
        else
        {
            var login = Host.Services.GetRequiredService<LoginWindow>();
            login.Closed += async (_, __) =>
            {
                // UI thread'i bloke etme (GetResult deadlock yapiyordu) — await ile.
                var (t, _, _) = await tokenStore.LoadAsync();
                if (t != null) StartTrayMode();
                else Shutdown();
            };
            login.Show();
        }
    }

    private void StartTrayMode()
    {
        _trayHost = Services.GetRequiredService<TrayIconHost>();
        _trayHost.Show();
        // Ilk acilista Ozet dashboard'unu da goster (kapatinca tray'de calismaya devam eder).
        _mainWindow = Services.GetRequiredService<MainWindow>();
        _mainWindow.Show();
        _mainWindow.Activate();
    }

    /// <summary>İkinci açılış denemesinde çalışan örneğin penceresini öne getirir.</summary>
    private void BringExistingWindowToFront()
    {
        try
        {
            var win = _mainWindow ?? Windows.OfType<Window>().FirstOrDefault();
            if (win == null) return;
            if (!win.IsVisible) win.Show();
            if (win.WindowState == WindowState.Minimized) win.WindowState = WindowState.Normal;
            win.Activate();
            win.Topmost = true;
            win.Topmost = false;   // öne getirip topmost'u geri al (flash)
            win.Focus();
        }
        catch { /* yoksay */ }
    }

    private async void OnExit(object sender, ExitEventArgs e)
    {
        _activateEvent?.Dispose();
        _singleInstanceMutex?.Dispose();
        _trayHost?.Dispose();
        if (Host != null)
        {
            await Host.StopAsync(TimeSpan.FromSeconds(5));
            Host.Dispose();
        }
        await Log.CloseAndFlushAsync();
    }
}
