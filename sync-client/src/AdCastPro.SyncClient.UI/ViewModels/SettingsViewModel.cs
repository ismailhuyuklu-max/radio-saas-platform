using System.IO;
using AdCastPro.SyncClient.Core.Configuration;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using Microsoft.Extensions.Options;
using Microsoft.Win32;
using System.Windows.Forms;

namespace AdCastPro.SyncClient.UI.ViewModels;

public sealed partial class SettingsViewModel : ObservableObject
{
    private readonly SyncClientOptions _options;

    [ObservableProperty] private string _newsFolder = "";
    [ObservableProperty] private string _adsFolder = "";
    [ObservableProperty] private string _mediaPlansFolder = "";
    [ObservableProperty] private string _sponsorsFolder = "";
    [ObservableProperty] private string _tempFolder = "";
    [ObservableProperty] private int _pollIntervalSeconds = 60;
    [ObservableProperty] private bool _autoStartWithWindows = true;

    public SettingsViewModel(IOptions<SyncClientOptions> options)
    {
        _options = options.Value;
        NewsFolder = _options.Folders.News;
        AdsFolder = _options.Folders.Ads;
        MediaPlansFolder = _options.Folders.MediaPlans;
        SponsorsFolder = _options.Folders.Sponsors;
        TempFolder = _options.Folders.Temp;
        PollIntervalSeconds = _options.PollIntervalSeconds;
        AutoStartWithWindows = _options.AutoStartWithWindows;
    }

    [RelayCommand]
    private void BrowseNews() => NewsFolder = BrowseFolder(NewsFolder) ?? NewsFolder;
    [RelayCommand]
    private void BrowseAds() => AdsFolder = BrowseFolder(AdsFolder) ?? AdsFolder;
    [RelayCommand]
    private void BrowseMediaPlans() => MediaPlansFolder = BrowseFolder(MediaPlansFolder) ?? MediaPlansFolder;
    [RelayCommand]
    private void BrowseSponsors() => SponsorsFolder = BrowseFolder(SponsorsFolder) ?? SponsorsFolder;

    [RelayCommand]
    private void Save()
    {
        _options.Folders.News = NewsFolder;
        _options.Folders.Ads = AdsFolder;
        _options.Folders.MediaPlans = MediaPlansFolder;
        _options.Folders.Sponsors = SponsorsFolder;
        _options.Folders.Temp = TempFolder;
        _options.PollIntervalSeconds = Math.Clamp(PollIntervalSeconds, 30, 300);
        _options.AutoStartWithWindows = AutoStartWithWindows;

        // Windows startup registry kaydı
        ApplyAutoStart(AutoStartWithWindows);

        // Klasörleri oluştur
        EnsureDir(NewsFolder);
        EnsureDir(AdsFolder);
        EnsureDir(MediaPlansFolder);
        EnsureDir(SponsorsFolder);
        EnsureDir(TempFolder);
    }

    private static string? BrowseFolder(string initial)
    {
        using var dlg = new FolderBrowserDialog
        {
            SelectedPath = Directory.Exists(initial) ? initial : Environment.GetFolderPath(Environment.SpecialFolder.UserProfile),
            UseDescriptionForTitle = true,
            Description = "Klasör seçin",
        };
        return dlg.ShowDialog() == DialogResult.OK ? dlg.SelectedPath : null;
    }

    private static void EnsureDir(string path)
    {
        try { if (!string.IsNullOrWhiteSpace(path)) Directory.CreateDirectory(path); }
        catch { /* yetkisiz veya geçersiz path */ }
    }

    private static void ApplyAutoStart(bool enabled)
    {
        try
        {
            using var key = Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows\CurrentVersion\Run", writable: true);
            if (key == null) return;
            var exePath = System.Reflection.Assembly.GetEntryAssembly()?.Location;
            if (string.IsNullOrEmpty(exePath)) return;

            if (enabled)
                key.SetValue("AdCastProSyncClient", $"\"{exePath}\"");
            else
                key.DeleteValue("AdCastProSyncClient", throwOnMissingValue: false);
        }
        catch { /* yetkisiz */ }
    }
}
