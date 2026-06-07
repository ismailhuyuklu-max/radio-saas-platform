using System.Windows;
using AdCastPro.SyncClient.App;
using AdCastPro.SyncClient.UI.Views;
using Hardcodet.Wpf.TaskbarNotification;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;

namespace AdCastPro.SyncClient.UI.Services;

/// <summary>
/// Sistem tepsisi (tray) icon hosting — uygulama background'da çalışırken
/// kullanıcının erişim noktası. Sağ tık menüsü: Sync Now, Settings, Logs, Quit.
///
/// Icon rengi BroadcastReadinessService durumuna göre 30s'de bir güncellenir:
///   GREEN = hazır, YELLOW = bekleniyor, RED = kritik
/// </summary>
public sealed class TrayIconHost : IDisposable
{
    private TaskbarIcon? _icon;
    private readonly IServiceProvider _services;
    private readonly BroadcastReadinessService _readiness;
    private readonly ILogger<TrayIconHost> _logger;
    private CancellationTokenSource? _cts;
    private Task? _updateTask;

    public TrayIconHost(IServiceProvider services, BroadcastReadinessService readiness, ILogger<TrayIconHost> logger)
    {
        _services = services;
        _readiness = readiness;
        _logger = logger;
    }

    public void Show()
    {
        _icon = new TaskbarIcon
        {
            ToolTipText = "AdCast Pro Sync — başlatılıyor...",
            Visibility = Visibility.Visible,
        };

        // Sağ tık menü
        _icon.ContextMenu = BuildMenu();

        // Çift tık → MainWindow göster
        _icon.TrayMouseDoubleClick += (_, _) => OpenMainWindow();

        _cts = new CancellationTokenSource();
        _updateTask = Task.Run(() => UpdateLoop(_cts.Token));
    }

    private System.Windows.Controls.ContextMenu BuildMenu()
    {
        var menu = new System.Windows.Controls.ContextMenu();

        var openItem = new System.Windows.Controls.MenuItem { Header = "Ana Pencereyi Aç" };
        openItem.Click += (_, _) => OpenMainWindow();
        menu.Items.Add(openItem);

        var settingsItem = new System.Windows.Controls.MenuItem { Header = "Ayarlar" };
        settingsItem.Click += (_, _) => _services.GetRequiredService<SettingsWindow>().Show();
        menu.Items.Add(settingsItem);

        var logsItem = new System.Windows.Controls.MenuItem { Header = "Loglar" };
        logsItem.Click += (_, _) => _services.GetRequiredService<LogsWindow>().Show();
        menu.Items.Add(logsItem);

        menu.Items.Add(new System.Windows.Controls.Separator());

        var quitItem = new System.Windows.Controls.MenuItem { Header = "Çıkış" };
        quitItem.Click += (_, _) => Application.Current.Shutdown();
        menu.Items.Add(quitItem);

        return menu;
    }

    private void OpenMainWindow()
    {
        var win = _services.GetRequiredService<MainWindow>();
        win.Show();
        win.Activate();
    }

    private async Task UpdateLoop(CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            try
            {
                var report = await _readiness.EvaluateAsync(ct);
                Application.Current.Dispatcher.Invoke(() =>
                {
                    if (_icon == null) return;
                    _icon.ToolTipText = $"AdCast Pro Sync — {report.Message}";
                    // İleride: report.Level'e göre ikon rengini değiştir
                });
            }
            catch (OperationCanceledException) { break; }
            catch (Exception ex)
            {
                _logger.LogDebug(ex, "Readiness update hata (devam)");
            }
            try { await Task.Delay(TimeSpan.FromSeconds(30), ct); }
            catch (OperationCanceledException) { break; }
        }
    }

    public void Dispose()
    {
        _cts?.Cancel();
        _updateTask?.Wait(TimeSpan.FromSeconds(2));
        _cts?.Dispose();
        _icon?.Dispose();
    }
}
