using System.ComponentModel;
using System.Windows;
using AdCastPro.SyncClient.UI.Services;
using AdCastPro.SyncClient.UI.ViewModels;

namespace AdCastPro.SyncClient.UI.Views;

public partial class MainWindow : Window
{
    private readonly NavigationService _nav;

    public MainWindow(MainViewModel vm, NavigationService nav)
    {
        InitializeComponent();
        DataContext = vm;
        _nav = nav;
    }

    private void OnSettings(object sender, RoutedEventArgs e)
    {
        var win = _nav.Resolve<SettingsWindow>();
        win.Owner = this;
        win.ShowDialog();
    }

    private void OnLogs(object sender, RoutedEventArgs e)
    {
        var win = _nav.Resolve<LogsWindow>();
        win.Owner = this;
        win.Show();
    }

    private void OnClosing(object? sender, CancelEventArgs e)
    {
        // Pencereyi kapatma — sadece gizle (tray'de çalışmaya devam)
        e.Cancel = true;
        Hide();
    }
}
