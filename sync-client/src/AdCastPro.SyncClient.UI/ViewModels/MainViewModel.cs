using System.Collections.ObjectModel;
using System.Globalization;
using System.IO;
using System.Windows;
using System.Windows.Media;
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
    private readonly SupportViewModel _supportVm;
    private readonly DispatcherTimer _timer;
    private readonly DispatcherTimer _clockTimer;

    /// <summary>Ayarlar bolumu (inline) — popup yerine sayfada gosterilir.</summary>
    public SettingsViewModel SettingsVm => _settingsVm;
    /// <summary>Raporlar/loglar bolumu (inline).</summary>
    public LogsViewModel LogsVm => _logsVm;
    /// <summary>Destek formu bolumu (inline).</summary>
    public SupportViewModel SupportVm => _supportVm;

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

    // ==================== Raporlar (operasyonel ozet) ====================
    [ObservableProperty] private string _reportTotalFiles = "0";
    [ObservableProperty] private string _reportTotalSize = "0 B";
    [ObservableProperty] private string _reportTodayFiles = "0";
    [ObservableProperty] private string _reportTodaySize = "0 B";
    [ObservableProperty] private string _reportSlotsReady = "0 / 0";
    [ObservableProperty] private string _reportLastSync = "Henuz yok";

    // ==================== Servis Durumu (izleme paneli) ====================
    [ObservableProperty] private double _cpuPercent;
    [ObservableProperty] private double _memoryPercent;
    [ObservableProperty] private double _diskUsedPercent;
    [ObservableProperty] private string _cpuColor = "#10B981";
    [ObservableProperty] private string _memoryColor = "#10B981";
    [ObservableProperty] private string _diskColor = "#10B981";
    [ObservableProperty] private string _diskUsedText = "—";
    [ObservableProperty] private string _uptimeText = "—";
    [ObservableProperty] private string _heartbeatText = "—";
    [ObservableProperty] private string _pollIntervalText = "—";
    [ObservableProperty] private PointCollection _cpuPoints = new();
    [ObservableProperty] private PointCollection _memoryPoints = new();
    [ObservableProperty] private PointCollection _cpuAreaPoints = new();
    [ObservableProperty] private PointCollection _memoryAreaPoints = new();
    [ObservableProperty] private string _serviceHealthText = "Tüm sistemler nominal";

    // Sparkline gecmis tamponlari (son 40 ornek) ve uygulama baslangic zamani.
    private const int SparkPoints = 40;
    private const double SparkW = 300, SparkH = 70;
    private readonly Queue<double> _cpuHist = new();
    private readonly Queue<double> _memHist = new();
    private readonly DateTime _startedAt = DateTime.Now;

    // ----- Yayina Hazir -----
    [ObservableProperty] private bool _readyToAir = true;
    [ObservableProperty] private string _readyToAirText = "YAYINA HAZIR";

    // ----- Sidebar teknolojik saat -----
    [ObservableProperty] private string _clockTime = "--:--:--";
    [ObservableProperty] private string _clockDate = "—";
    [ObservableProperty] private string _clockDay = "—";
    private static readonly CultureInfo TrCulture = new("tr-TR");

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
        SupportViewModel supportVm,
        ILogger<MainViewModel> logger)
    {
        _readiness = readiness;
        _cache = cache;
        _store = store;
        _api = api;
        _options = options.Value;
        _settingsVm = settingsVm;
        _logsVm = logsVm;
        _supportVm = supportVm;
        _logger = logger;

        ServerAddress = StripScheme(_options.ApiBaseUrl);

        // 6 yayin hazirlik kontrolu
        Checks.Add(new CheckRow { Label = "Dosya mevcut" });
        Checks.Add(new CheckRow { Label = "Dosya boyutu doğru" });
        Checks.Add(new CheckRow { Label = "Güvenlik doğrulaması" });
        Checks.Add(new CheckRow { Label = "Ses formatı doğru" });
        Checks.Add(new CheckRow { Label = "Yayın kuşağı eşleşmesi" });
        Checks.Add(new CheckRow { Label = "Güvenli dosya değişimi" });

        AppendLog("Uygulama baslatildi.");

        _timer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(3) };
        _timer.Tick += (_, __) => UpdateMetrics();
        _timer.Start();

        _clockTimer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(1) };
        _clockTimer.Tick += (_, __) => UpdateClock();
        _clockTimer.Start();
        UpdateClock();

        UpdateMetrics();
        _ = LoadAsync();
    }

    /// <summary>Sidebar saat + tarih (gun ay yil) — saniyede bir guncellenir.</summary>
    private void UpdateClock()
    {
        var now = DateTime.Now;
        ClockTime = now.ToString("HH:mm:ss");
        ClockDate = now.ToString("dd MMMM yyyy", TrCulture);
        ClockDay = now.ToString("dddd", TrCulture);
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
        _supportVm = new SupportViewModel(null!);
        _logger = null!;
        _timer = new DispatcherTimer();
        _clockTimer = new DispatcherTimer();
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
            ReportTotalFiles = "25",
            ReportTotalSize = "512.4 MB",
            ReportTodayFiles = "6",
            ReportTodaySize = "66.6 MB",
            ReportSlotsReady = "7 / 7",
            ReportLastSync = "8.06.2025 10:24:30",
            ClockTime = "21:42:07",
            ClockDate = "15 Haziran 2026",
            ClockDay = "Pazar",
            CpuPercent = 12,
            MemoryPercent = 38,
            DiskUsedPercent = 63,
            CpuColor = "#10B981",
            MemoryColor = "#10B981",
            DiskColor = "#F59E0B",
            DiskUsedText = "210 / 336 GB kullanildi",
            UptimeText = "5 sa 42 dk",
            HeartbeatText = "60 sn'de bir",
            PollIntervalText = "60 sn",
            ServiceHealthText = "Tüm sistemler nominal durumda",
        };
        vm.CpuPoints = SampleSpark(new double[] { 8, 14, 10, 22, 17, 12, 9, 15, 28, 19, 11, 13, 10, 18, 24, 14, 9, 12, 16, 11 });
        vm.CpuAreaPoints = BuildArea(vm.CpuPoints);
        vm.MemoryPoints = SampleSpark(new double[] { 36, 37, 38, 37, 39, 41, 40, 38, 37, 38, 39, 40, 38, 37, 38, 39, 38, 37, 38, 38 });
        vm.MemoryAreaPoints = BuildArea(vm.MemoryPoints);
        vm.SupportVm.SetRadioInfo("Meşk FM", "95.5", "Akdeniz", "Adana");
        foreach (var (time, name) in StandardSlots)
            vm.Slots.Add(new SlotRow { Time = time, Name = name, StatusText = "HAZIR", StatusColor = "#10B981" });
        vm.Checks.Add(new CheckRow { Label = "Dosya mevcut" });
        vm.Checks.Add(new CheckRow { Label = "Dosya boyutu doğru" });
        vm.Checks.Add(new CheckRow { Label = "Güvenlik doğrulaması" });
        vm.Checks.Add(new CheckRow { Label = "Ses formatı doğru" });
        vm.Checks.Add(new CheckRow { Label = "Yayın kuşağı eşleşmesi" });
        vm.Checks.Add(new CheckRow { Label = "Güvenli dosya değişimi" });
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

            // Destek formu otomatik radyo bilgileri
            _supportVm?.SetRadioInfo(radio?.Name, radio?.Frequency, radio?.Region, radio?.Province);

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
                StatusColor = ready ? "#10B981" : "#F59E0B",
            });
        }
    }

    private async Task LoadDownloadsAsync()
    {
        var recent = await _cache.ListRecentDownloadsAsync(200);
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

        // ---- Raporlar: operasyonel ozet istatistikleri ----
        var todayLocal = DateTime.Now.Date;
        var todays = recent.Where(d => d.DownloadedAt.ToLocalTime().Date == todayLocal).ToList();
        ReportTotalFiles = recent.Count.ToString();
        ReportTotalSize = FormatSize(totalBytes);
        ReportTodayFiles = todays.Count.ToString();
        ReportTodaySize = FormatSize(todays.Sum(d => d.SizeBytes));
        int slotsReady = Slots.Count(s => s.StatusText == "HAZIR");
        ReportSlotsReady = $"{slotsReady} / {Slots.Count}";
        ReportLastSync = LastSyncTime;
    }

    // ==================== Canli metrikler ====================

    private void UpdateMetrics()
    {
        try
        {
            int cpu = ReadCpu();
            int mem = SystemMetrics.MemoryLoadPercent();
            CpuPercent = cpu;
            MemoryPercent = mem;
            CpuText = $"%{cpu}";
            MemoryText = $"%{mem}";
            CpuColor = ColorForLoad(cpu);
            MemoryColor = ColorForLoad(mem);

            var freeGb = SystemMetrics.FreeDiskGb(_options.Folders.News);
            var totalGb = SystemMetrics.TotalDiskGb(_options.Folders.News);
            var usedGb = Math.Max(0, totalGb - freeGb);
            DiskUsedPercent = totalGb > 0 ? Math.Round(usedGb * 100.0 / totalGb) : 0;
            DiskUsedText = totalGb > 0 ? $"{usedGb:0} / {totalGb:0} GB kullanildi" : "—";
            DiskColor = ColorForLoad(DiskUsedPercent);
            DiskText = $"{freeGb:0.0} GB";
            FreeSpaceText = $"{freeGb:0.0} GB";

            UptimeText = FormatUptime(DateTime.Now - _startedAt);
            PollIntervalText = $"{_options.PollIntervalSeconds} sn";
            HeartbeatText = $"{_options.HeartbeatIntervalSeconds} sn'de bir";

            CpuPoints = BuildSpark(_cpuHist, cpu);
            CpuAreaPoints = BuildArea(CpuPoints);
            MemoryPoints = BuildSpark(_memHist, mem);
            MemoryAreaPoints = BuildArea(MemoryPoints);
        }
        catch (Exception ex)
        {
            _logger.LogDebug(ex, "Metrik guncelleme hatasi");
        }
    }

    private static int ReadCpu() => SystemMetrics.CpuPercent();

    /// <summary>Yuk yuzdesine gore renk: yesil &lt;60, sari &lt;85, kirmizi ustu.</summary>
    private static string ColorForLoad(double pct)
        => pct < 60 ? "#10B981" : pct < 85 ? "#F59E0B" : "#EF4444";

    private static string FormatUptime(TimeSpan up)
    {
        if (up.TotalDays >= 1) return $"{(int)up.TotalDays} g {up.Hours} sa {up.Minutes} dk";
        if (up.TotalHours >= 1) return $"{up.Hours} sa {up.Minutes} dk";
        if (up.TotalMinutes >= 1) return $"{up.Minutes} dk {up.Seconds} sn";
        return $"{up.Seconds} sn";
    }

    /// <summary>Yeni ornegi tampona ekler ve son N orneği sparkline noktalarina cevirir.</summary>
    private static PointCollection BuildSpark(Queue<double> hist, double value)
    {
        hist.Enqueue(Math.Max(0, Math.Min(100, value)));
        while (hist.Count > SparkPoints) hist.Dequeue();
        var arr = hist.ToArray();
        var pts = new PointCollection();
        int n = arr.Length;
        for (int i = 0; i < n; i++)
        {
            double x = n <= 1 ? 0 : i / (double)(n - 1) * SparkW;
            double y = SparkH - arr[i] / 100.0 * SparkH;
            pts.Add(new Point(x, y));
        }
        pts.Freeze();
        return pts;
    }

    /// <summary>Sparkline cizgisini, taban kosesinden kapatip dolgulu alan poligonuna cevirir.</summary>
    private static PointCollection BuildArea(PointCollection line)
    {
        var area = new PointCollection();
        foreach (var p in line) area.Add(p);
        if (line.Count > 0)
        {
            area.Add(new Point(SparkW, SparkH));
            area.Add(new Point(0, SparkH));
        }
        area.Freeze();
        return area;
    }

    /// <summary>Onizleme/ornek icin sentetik seriyi sparkline noktalarina cevirir.</summary>
    private static PointCollection SampleSpark(double[] vals)
    {
        var pts = new PointCollection();
        int n = vals.Length;
        for (int i = 0; i < n; i++)
        {
            double x = n <= 1 ? 0 : i / (double)(n - 1) * SparkW;
            double y = SparkH - Math.Max(0, Math.Min(100, vals[i])) / 100.0 * SparkH;
            pts.Add(new Point(x, y));
        }
        pts.Freeze();
        return pts;
    }

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
