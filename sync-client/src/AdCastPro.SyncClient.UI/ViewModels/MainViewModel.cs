using System.Collections.ObjectModel;
using AdCastPro.SyncClient.App;
using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Models;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using Microsoft.Extensions.Logging;

namespace AdCastPro.SyncClient.UI.ViewModels;

public sealed partial class MainViewModel : ObservableObject
{
    private readonly BroadcastReadinessService _readiness;
    private readonly ILocalCache _cache;
    private readonly ITokenStore _store;
    private readonly ILogger<MainViewModel> _logger;

    [ObservableProperty] private string _radioName = "—";
    [ObservableProperty] private string _userName = "—";
    [ObservableProperty] private string _lastSyncTime = "Henüz sync yapılmadı";
    [ObservableProperty] private string _statusText = "Başlatılıyor...";
    [ObservableProperty] private string _statusColor = "#94A3B8";   // gri
    [ObservableProperty] private bool _isOnline = true;
    public ObservableCollection<DownloadedFile> RecentDownloads { get; } = new();

    public MainViewModel(
        BroadcastReadinessService readiness,
        ILocalCache cache,
        ITokenStore store,
        ILogger<MainViewModel> logger)
    {
        _readiness = readiness;
        _cache = cache;
        _store = store;
        _logger = logger;
        _ = LoadAsync();
    }

    [RelayCommand]
    private async Task RefreshAsync()
    {
        await LoadAsync();
    }

    private async Task LoadAsync()
    {
        try
        {
            var (_, user, radio) = await _store.LoadAsync();
            UserName = user?.Username ?? "—";
            RadioName = radio?.Name ?? "—";

            var report = await _readiness.EvaluateAsync();
            StatusText = report.Message;
            StatusColor = report.Level switch
            {
                BroadcastReadinessService.ReadinessLevel.Green => "#10B981",
                BroadcastReadinessService.ReadinessLevel.Yellow => "#F59E0B",
                BroadcastReadinessService.ReadinessLevel.Red => "#EF4444",
                _ => "#94A3B8",
            };

            var recent = await _cache.ListRecentDownloadsAsync(20);
            RecentDownloads.Clear();
            foreach (var d in recent) RecentDownloads.Add(d);

            var latest = recent.FirstOrDefault();
            LastSyncTime = latest != null
                ? latest.DownloadedAt.ToLocalTime().ToString("dd MMM HH:mm:ss")
                : "Henüz sync yapılmadı";
        }
        catch (Exception ex)
        {
            _logger.LogError(ex, "Main load hatası");
        }
    }
}
