using System.Collections.ObjectModel;
using System.IO;
using System.Windows.Threading;
using AdCastPro.SyncClient.App;
using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Core.Models;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace AdCastPro.SyncClient.UI.ViewModels;

/// <summary>
/// Ozet dashboard ViewModel'i — ekteki tasarimin tum kartlarini besler.
/// Veri mevcut servislerden gelir: ITokenStore (baglanti), ILocalCache (manifest +
/// indirilenler), BroadcastReadinessService (hazirlik), IApiClient (senkronizasyon),
/// SystemMetrics (CPU/bellek/disk).
/// </summary>
public sealed partial class MainViewModel : ObservableObject
{
    private readonly BroadcastReadinessService _readiness;
    private readonly ILocalCache _cache;
    private readonly ITokenStore _store;
    private readonly IApiClient _api;
    private readonly SyncClientOptions _options;
    private readonly ILogger<MainViewModel> _logger;
    private readonly SettingsViewModel _settingsVm;
    private readonly LogsViewModel _logsVm;
    private readonly DispatcherTimer _timer;

    /// <summary>Ayarlar bolumu (inline) — popup yerine sayfada gosterilir.</summary>
    public SettingsViewModel SettingsVm => _settingsVm;
    /// <summary>Raporlar/loglar bolumu (inline).</summary>
    public LogsViewModel LogsVm => _logsVm;

    // 7 standart haber kusagi sablonu (manifest yoksa gunluk plan olarak gosterilir).
    private static readonly (string Time, string Name)[] StandardSlots =
    {
        ("08:00", "Sabah Haberleri"),
        ("10:00", "Gun Ortasi"),
        ("12:00", "Ogle Haberleri"),
        ("14:00", "Gun Ici"),
        ("16:00", "Aksam Ustu"),
        ("18:00", "Aksam Haberleri"),
        ("20:00", "Gece Haberleri"),
    };

    // ----- Navigasyon -----
    [ObservableProperty] private string _selectedSection = "ozet";

    // ----- Baglanti Durumu -----
    [ObservableProperty] private string _serverAddress = "—";
    [ObservableProperty] private string _connUserName = "—";
    [ObservableProperty] private string _stationName = "—";
    [ObservableProperty] private string _frequency = "—";
    [ObservableProperty] private string _regionName = "—";
    [ObservableProperty] private string _provinceName = "—";
    [ObservableProperty] private string? _radioLogoUrl;
    [ObservableProperty] private string _lastSyncTime = "Henuz yok";
    [ObservableProperty] private string _connStatusText = "Baglaniyor…";
    [ObservableProperty] private string _connStatusColor = "#F59E0B";

    // ----- Sistem Durumu -----
    [ObservableProperty] private string _serviceStatusText = "Calisiyor";
    [ObservableProperty] private string _engineStatusText = "Hazir";
    [ObservableProperty] private string _lastErrorText = "Yok";
    [ObservableProperty] private string _diskText = "—";
    [ObservableProperty] private string _cpuText = "—";
    [ObservableProperty] private string _memoryText = "—";

    // ----- Yayin Hazirlik Durumu (donut) -----
    [ObservableProperty] private double _readinessPercent;
    [ObservableProperty] private string _readinessLabel = "—";
    [ObservableProperty] private string _readinessColor = "#10B981";
    [ObservableProperty] private string _lastCheckTime = "—";

    // ----- Indirme Durumu (donut) -----
    [ObservableProperty] private double _downloadPercent = 100;
    [ObservableProperty] private string _totalFilesText = "0";
    [ObservableProperty] private string _downloadedFilesText = "0";
    [ObservableProperty] private string _remainingFilesText = "0";
    [ObservableProperty] private string _speedText = "0 B/s";
    [ObservableProperty] private string _totalSizeText = "0 MB";
    [ObservableProperty] private string _freeSpaceText = "—";

    // ----- Yayina Hazir -----
    [ObservableProperty] private bool _readyToAir = true;
    [ObservableProperty] private string _readyToAirText = "YAYINA HAZIR";

    public ObservableCollection<SlotRow> Slots { get; } = new();
    public ObservableCollection<DownloadRow> RecentDownloads { get; } = new();
    public ObservableCollection<CheckRow> Checks { get; } = new();
    public ObservableCollection<string> ConsoleLines { get; } = new();

    public MainViewModel(
        BroadcastReadinessService readiness,
        ILocalCache cache,
        ITokenStore store,
        IApiClient api,
        IOptions<SyncClientOptions> options,
        SettingsViewModel settingsVm,
        LogsViewModel logsVm,
        ILogger<MainViewModel> logger)
    {
        _readiness = readiness;
        _cache = cache;
        _store = store;
        _api = api;
        _options = options.Value;
        _settingsVm = settingsVm;
        _logsVm = logsVm;
        _logger = logger;

        ServerAddress = StripScheme(_options.ApiBaseUrl);

        // 6 yayin hazirlik kontrolu
        Checks.Add(new CheckRow { Label = "1. Dosya Mevcut" });
        Checks.Add(new CheckRow { Label = "2. Dosya Boyutu Dogru" });
        Checks.Add(new CheckRow { Label = "3. Guvenlik Dogrulamasi Tamam" });
        Checks.Add(new CheckRow { Label = "4. Ses Formati Dogru" });
        Checks.Add(new CheckRow { Label = "5. Yayin Kusagi Eslesmesi" });
        Checks.Add(new CheckRow { Label = "6. Guvenli Dosya Degisimi Basarili" });

        AppendLog("Uygulama baslatildi.");

        _timer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(3) };
        _timer.Tick += (_, __) => UpdateMetrics();
        _timer.Start();

        UpdateMetrics();
        _ = LoadAsync();
    }

    /// <summary>Onizleme/tasarim icin servissiz ctor — ornek veriyle doldurulur.</summary>
    private MainViewModel()
    {
        _readiness = null!;
        _cache = null!;
        _store = null!;
        _api = null!;
        _options = null!;
        _settingsVm = new SettingsViewModel(Options.Create(new SyncClientOptions()));
        _logsVm = new LogsViewModel();
        _logger = null!;
        _timer = new DispatcherTimer();
    }

    /// <summary>Ekteki tasarima birebir ornek veri — gorsel onizleme icin.</summary>
    public static MainViewModel CreateSample(string section = "ozet")
    {
        var vm = new MainViewModel
        {
            SelectedSection = section,
            ServerAddress = "api.adcastpro.com",
            ConnUserName = "MESK_FM",
            StationName = "Meşk FM",
            Frequency = "95.5",
            RegionName = "Akdeniz",
            ProvinceName = "Adana",
            LastSyncTime = "8.06.2025 10:24:30",
            ConnStatusText = "Bağlandı",
            ConnStatusColor = "#10B981",
            ServiceStatusText = "Çalışıyor",
            EngineStatusText = "Hazır",
            LastErrorText = "Yok",
            DiskText = "125.6 GB",
            CpuText = "%2",
            MemoryText = "%38",
            ReadinessPercent = 100,
            ReadinessLabel = "HAZIR",
            ReadinessColor = "#10B981",
            LastCheckTime = "10:24:28",
            DownloadPercent = 100,
            TotalFilesText = "25",
            DownloadedFilesText = "25",
            RemainingFilesText = "0",
            SpeedText = "0 B/s",
            TotalSizeText = "512.4 MB",
            FreeSpaceText = "125.6 GB",
            ReadyToAir = true,
            ReadyToAirText = "YAYINA HAZIR",
        };
        foreach (var (time, name) in StandardSlots)
            vm.Slots.Add(new SlotRow { Time = time, Name = name, StatusText = "HAZIR", StatusColor = "#10B981" });
        vm.Checks.Add(new CheckRow { Label = "1. Dosya Mevcut" });
        vm.Checks.Add(new CheckRow { Label = "2. Dosya Boyutu Doğru" });
        vm.Checks.Add(new CheckRow { Label = "3. Güvenlik Doğrulaması Tamam" });
        vm.Checks.Add(new CheckRow { Label = "4. Ses Formatı Doğru" });
        vm.Checks.Add(new CheckRow { Label = "5. Yayın Kuşağı Eşleşmesi" });
        vm.Checks.Add(new CheckRow { Label = "6. Güvenli Dosya Değişimi Başarılı" });
        var dl = new (string n, string s, string sz, string d)[]
        {
            ("08.00-Sabah_Haberleri.aac", "08:00", "18.2 MB", "8.06.2025 07:45"),
            ("10.00-Gun_Ortasi_Haberleri.aac", "10:00", "19.5 MB", "8.06.2025 09:45"),
            ("12.00-Ogle_Haberleri.aac", "12:00", "17.8 MB", "8.06.2025 11:50"),
            ("SPOR_ONCESI_REKLAM.aac", "Reklam", "5.6 MB", "8.06.2025 09:50"),
            ("HAVA_DURUMU_ONCESI.aac", "Reklam", "4.3 MB", "8.06.2025 09:52"),
            ("MEDYA_PLANI.pdf", "Medya Planı", "1.2 MB", "8.06.2025 09:52"),
        };
        foreach (var (n, s, sz, d) in dl)
            vm.RecentDownloads.Add(new DownloadRow { Icon = n.EndsWith(".pdf") ? "📕" : "🎵", FileName = n, Slot = s, Size = sz, Date = d, StatusText = "Tamamlandı" });
        foreach (var l in new[]
        {
            "Sunucu bağlantısı başarılı.",
            "Yayın listesi alındı. (25 dosya)",
            "İndirme kuyruğu oluşturuldu.",
            "İndirme tamamlandı.",
            "Dosyalar doğrulandı.",
            "Senkronizasyon tamamlandı.",
        })
            vm.ConsoleLines.Add($"[10:24:{DateTime.MinValue:ss}] Bilgi: {l}");
        return vm;
    }

    // ==================== Komutlar ====================

    [RelayCommand]
    private async Task RefreshAsync() => await LoadAsync();

    [RelayCommand]
    private async Task SyncNowAsync()
    {
        try
        {
            AppendLog("Senkronizasyon baslatildi…");
            var (_, etag) = await _cache.LoadManifestAsync();
            var manifest = await _api.GetManifestAsync(etag);
            if (manifest != null)
            {
                await _cache.SaveManifestAsync(manifest, etag ?? "", default);
                AppendLog($"Yayin listesi alindi. ({manifest.FileCount} dosya)");
            }
            else
            {
                AppendLog("Yayin listesi guncel (degisiklik yok).");
            }
            await LoadAsync();
            AppendLog("Senkronizasyon tamamlandi.");
        }
        catch (Exception ex)
        {
            AppendLog($"Senkronizasyon hatasi: {ex.Message}");
            _logger.LogError(ex, "SyncNow hatasi");
        }
    }

    [RelayCommand]
    private async Task RefreshListAsync()
    {
        AppendLog("Yayin listesi yenileniyor…");
        await LoadAsync();
    }

    [RelayCommand]
    private void ClearConsole() => ConsoleLines.Clear();

    [RelayCommand]
    private void Navigate(string? section)
    {
        if (string.IsNullOrWhiteSpace(section)) return;
        SelectedSection = section;
        if (section == "raporlar") _logsVm?.Refresh();   // gunlukleri tazele
    }

    // ==================== Veri yukleme ====================

    private async Task LoadAsync()
    {
        try
        {
            var (tokens, user, radio) = await _store.LoadAsync();
            // Taze radio bilgisi (logo dahil) icin /me cek — basarisizsa kayitliyi kullan.
            if (tokens != null)
            {
                try
                {
                    var me = await _api.GetMeAsync();
                    user = me.User;
                    radio = me.Radio;
                }
                catch (Exception ex)
                {
                    _logger.LogDebug(ex, "/me yenileme atlandi (cevrimdisi?)");
                }
            }
            ConnUserName = user?.Username ?? "—";
            StationName = radio?.Name ?? "—";
            Frequency = radio?.Frequency ?? "—";
            RegionName = radio?.Region ?? "—";
            ProvinceName = radio?.Province ?? "—";
            RadioLogoUrl = radio?.LogoUrl;

            bool connected = tokens != null;
            ConnStatusText = connected ? "Baglandi" : "Baglanti yok";
            ConnStatusColor = connected ? "#10B981" : "#EF4444";

            var report = await _readiness.EvaluateAsync();
            ApplyReadiness(report);

            await LoadSlotsAsync();
            await LoadDownloadsAsync();

            LastCheckTime = DateTime.Now.ToString("HH:mm:ss");
        }
        catch (Exception ex)
        {
            _logger.LogError(ex, "Dashboard yukleme hatasi");
            LastErrorText = ex.Message.Length > 40 ? ex.Message[..40] : ex.Message;
        }
    }

    private void ApplyReadiness(BroadcastReadinessService.ReadinessReport report)
    {
        ReadinessColor = report.Level switch
        {
            BroadcastReadinessService.ReadinessLevel.Green => "#10B981",
            BroadcastReadinessService.ReadinessLevel.Yellow => "#F59E0B",
            BroadcastReadinessService.ReadinessLevel.Orange => "#F59E0B",
            BroadcastReadinessService.ReadinessLevel.Red => "#EF4444",
            _ => "#94A3B8",
        };
        bool green = report.Level == BroadcastReadinessService.ReadinessLevel.Green;
        ReadinessPercent = green ? 100 : report.Level == BroadcastReadinessService.ReadinessLevel.Unknown ? 0 : 60;
        ReadinessLabel = green ? "HAZIR" : report.Level == BroadcastReadinessService.ReadinessLevel.Red ? "KRITIK" : "BEKLIYOR";

        ReadyToAir = green;
        ReadyToAirText = green ? "YAYINA HAZIR" : "HAZIR DEGIL";

        // 6 kontrol — yesil ise hepsi OK, degilse hazirlik durumuna gore.
        foreach (var c in Checks) c.Ok = green;
    }

    private async Task LoadSlotsAsync()
    {
        Slots.Clear();
        var (manifest, _) = await _cache.LoadManifestAsync();
        var newsFiles = manifest?.Files.Where(f => f.FileType == "news").ToList();

        foreach (var (time, name) in StandardSlots)
        {
            bool ready = false;
            if (newsFiles != null)
            {
                var hour = time[..2];
                ready = newsFiles.Any(f => f.ScheduledAirTime.ToLocalTime().ToString("HH") == hour);
            }
            Slots.Add(new SlotRow
            {
                Time = time,
                Name = name,
                StatusText = ready ? "HAZIR" : "BEKLIYOR",
                StatusColor = ready ? "#10B981" : "#94A3B8",
            });
        }
    }

    private async Task LoadDownloadsAsync()
    {
        var recent = await _cache.ListRecentDownloadsAsync(25);
        RecentDownloads.Clear();
        foreach (var d in recent)
        {
            RecentDownloads.Add(new DownloadRow
            {
                Icon = IconFor(d.Filename),
                FileName = d.Filename,
                Slot = SlotOf(d.Filename),
                Size = FormatSize(d.SizeBytes),
                Date = d.DownloadedAt.ToLocalTime().ToString("d.MM.yyyy HH:mm"),
                StatusText = "Tamamlandi",
            });
        }

        var (manifest, _) = await _cache.LoadManifestAsync();
        int total = manifest?.FileCount ?? recent.Count;
        int done = recent.Count;
        int remaining = Math.Max(0, total - done);
        TotalFilesText = total.ToString();
        DownloadedFilesText = done.ToString();
        RemainingFilesText = remaining.ToString();
        DownloadPercent = total == 0 ? 100 : Math.Round(done * 100.0 / total);
        long totalBytes = recent.Sum(d => d.SizeBytes);
        TotalSizeText = FormatSize(totalBytes);
        SpeedText = "0 B/s";

        var latest = recent.FirstOrDefault();
        LastSyncTime = latest != null
            ? latest.DownloadedAt.ToLocalTime().ToString("d.MM.yyyy HH:mm:ss")
            : "Henuz yok";
    }

    // ==================== Canli metrikler ====================

    private void UpdateMetrics()
    {
        try
        {
            MemoryText = $"%{SystemMetrics.MemoryLoadPercent()}";
            var freeGb = SystemMetrics.FreeDiskGb(_options.Folders.News);
            DiskText = $"{freeGb:0.0} GB";
            FreeSpaceText = $"{freeGb:0.0} GB";
            CpuText = $"%{ReadCpu()}";
        }
        catch (Exception ex)
        {
            _logger.LogDebug(ex, "Metrik guncelleme hatasi");
        }
    }

    private static int ReadCpu() => SystemMetrics.CpuPercent();

    // ==================== Konsol ====================

    private void AppendLog(string message)
    {
        var line = $"[{DateTime.Now:HH:mm:ss}] Bilgi: {message}";
        ConsoleLines.Add(line);
        while (ConsoleLines.Count > 200) ConsoleLines.RemoveAt(0);
    }

    // ==================== Yardimcilar ====================

    private static string StripScheme(string url)
        => url.Replace("https://", "").Replace("http://", "").TrimEnd('/');

    private static string IconFor(string filename)
    {
        var ext = Path.GetExtension(filename).ToLowerInvariant();
        return ext switch
        {
            ".pdf" => "📕",
            ".aac" or ".mp3" or ".wav" => "🎵",
            _ => "📄",
        };
    }

    private static string SlotOf(string filename)
    {
        // "08.00-Sabah_Haberleri.aac" -> "08:00"
        var name = Path.GetFileNameWithoutExtension(filename);
        var first = name.Split('-', '_').FirstOrDefault() ?? "";
        return first.Contains('.') ? first.Replace('.', ':') : "—";
    }

    private static string FormatSize(long bytes)
    {
        if (bytes <= 0) return "0 B";
        string[] units = { "B", "KB", "MB", "GB" };
        double v = bytes;
        int i = 0;
        while (v >= 1024 && i < units.Length - 1) { v /= 1024; i++; }
        return $"{v:0.0} {units[i]}";
    }
}
