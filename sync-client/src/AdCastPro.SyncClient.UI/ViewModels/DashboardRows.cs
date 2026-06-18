using System.IO;
using System.Runtime.InteropServices;
using CommunityToolkit.Mvvm.ComponentModel;

namespace AdCastPro.SyncClient.UI.ViewModels;

/// <summary>Yayin kusagi satiri (08:00 · Sabah Haberleri · HAZIR).</summary>
public sealed partial class SlotRow : ObservableObject
{
    [ObservableProperty] private string _time = "";
    [ObservableProperty] private string _name = "";
    [ObservableProperty] private string _statusText = "BEKLIYOR";
    [ObservableProperty] private string _statusColor = "#F59E0B";
}

/// <summary>Son indirilen dosya satiri.</summary>
public sealed partial class DownloadRow : ObservableObject
{
    [ObservableProperty] private string _icon = "📄";   // 📄
    [ObservableProperty] private string _fileName = "";
    [ObservableProperty] private string _slot = "";
    [ObservableProperty] private string _size = "";
    [ObservableProperty] private string _date = "";
    [ObservableProperty] private string _statusText = "Tamamlandi";
}

/// <summary>Yayin hazirlik kontrol satiri (1. Dosya Mevcut ... OK).</summary>
public sealed partial class CheckRow : ObservableObject
{
    [ObservableProperty] private string _label = "";
    [ObservableProperty] private bool _ok = true;
    public string Mark => Ok ? "OK" : "—";
    public string Color => Ok ? "#10B981" : "#EF4444";
    partial void OnOkChanged(bool value)
    {
        OnPropertyChanged(nameof(Mark));
        OnPropertyChanged(nameof(Color));
    }
}

/// <summary>Güncelleme geçmişi satırı (v1.0.0.0 yüklendi · zaman · OK).</summary>
public sealed partial class UpdateHistoryRow : ObservableObject
{
    [ObservableProperty] private string _title = "";
    [ObservableProperty] private string _detail = "";
    [ObservableProperty] private string _time = "";
    [ObservableProperty] private bool _ok = true;
    public string Mark => Ok ? "OK" : "—";
    public string Color => Ok ? "#10B981" : "#EF4444";
    partial void OnOkChanged(bool value)
    {
        OnPropertyChanged(nameof(Mark));
        OnPropertyChanged(nameof(Color));
    }
}

/// <summary>Sürüm notu satırı (başlık + açıklama).</summary>
public sealed partial class ReleaseNoteRow : ObservableObject
{
    [ObservableProperty] private string _title = "";
    [ObservableProperty] private string _detail = "";
}

/// <summary>Sistem metrikleri — CPU / bellek / disk. P/Invoke + DriveInfo.</summary>
internal static class SystemMetrics
{
    [StructLayout(LayoutKind.Sequential)]
    private struct MEMORYSTATUSEX
    {
        public uint dwLength;
        public uint dwMemoryLoad;
        public ulong ullTotalPhys;
        public ulong ullAvailPhys;
        public ulong ullTotalPageFile;
        public ulong ullAvailPageFile;
        public ulong ullTotalVirtual;
        public ulong ullAvailVirtual;
        public ulong ullAvailExtendedVirtual;
    }

    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GlobalMemoryStatusEx(ref MEMORYSTATUSEX lpBuffer);

    /// <summary>Kullanilan fiziksel bellek yuzdesi (0-100).</summary>
    public static int MemoryLoadPercent()
    {
        try
        {
            var s = new MEMORYSTATUSEX { dwLength = (uint)Marshal.SizeOf<MEMORYSTATUSEX>() };
            return GlobalMemoryStatusEx(ref s) ? (int)s.dwMemoryLoad : 0;
        }
        catch { return 0; }
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct FILETIME { public uint Low; public uint High; }

    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetSystemTimes(out FILETIME idle, out FILETIME kernel, out FILETIME user);

    private static ulong _prevIdle, _prevKernel, _prevUser;

    /// <summary>Sistem geneli CPU kullanim yuzdesi (GetSystemTimes delta — paketsiz).</summary>
    public static int CpuPercent()
    {
        try
        {
            if (!GetSystemTimes(out var idle, out var kernel, out var user)) return 0;
            ulong i = ToU(idle), k = ToU(kernel), u = ToU(user);
            if (_prevKernel == 0 && _prevUser == 0) { _prevIdle = i; _prevKernel = k; _prevUser = u; return 0; }
            ulong idleDelta = i - _prevIdle, kernelDelta = k - _prevKernel, userDelta = u - _prevUser;
            _prevIdle = i; _prevKernel = k; _prevUser = u;
            ulong total = kernelDelta + userDelta;   // kernel idle'i de icerir
            if (total == 0) return 0;
            double busy = (double)(total - idleDelta) / total;
            return (int)Math.Round(Math.Clamp(busy, 0, 1) * 100);
        }
        catch { return 0; }
    }

    private static ulong ToU(FILETIME ft) => ((ulong)ft.High << 32) | ft.Low;

    /// <summary>Verilen yol icin surucudeki bos alan (GB).</summary>
    public static double FreeDiskGb(string path)
    {
        try
        {
            var root = Path.GetPathRoot(Path.GetFullPath(path));
            if (string.IsNullOrEmpty(root)) return 0;
            var di = new DriveInfo(root);
            return di.IsReady ? Math.Round(di.AvailableFreeSpace / 1024d / 1024d / 1024d, 1) : 0;
        }
        catch { return 0; }
    }

    /// <summary>Verilen yol icin surucunun toplam kapasitesi (GB).</summary>
    public static double TotalDiskGb(string path)
    {
        try
        {
            var root = Path.GetPathRoot(Path.GetFullPath(path));
            if (string.IsNullOrEmpty(root)) return 0;
            var di = new DriveInfo(root);
            return di.IsReady ? Math.Round(di.TotalSize / 1024d / 1024d / 1024d, 1) : 0;
        }
        catch { return 0; }
    }
}
