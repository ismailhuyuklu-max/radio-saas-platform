using System.Collections.ObjectModel;
using System.IO;
using System.Linq;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;

namespace AdCastPro.SyncClient.UI.ViewModels;

public sealed partial class LogsViewModel : ObservableObject
{
    public ObservableCollection<string> Lines { get; } = new();
    [ObservableProperty] private string _filter = "";

    public LogsViewModel() => Refresh();

    [RelayCommand]
    public void Refresh()
    {
        Lines.Clear();
        try
        {
            var logDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AdCastPro", "Logs");
            if (!Directory.Exists(logDir)) return;

            var latest = Directory.GetFiles(logDir, "*.log")
                .OrderByDescending(File.GetLastWriteTime)
                .FirstOrDefault();
            if (latest == null) return;

            // Son 1000 satır oku
            using var fs = new FileStream(latest, FileMode.Open, FileAccess.Read, FileShare.ReadWrite);
            using var reader = new StreamReader(fs);
            var all = reader.ReadToEnd().Split('\n');
            var tail = all.TakeLast(1000);
            foreach (var line in tail)
            {
                if (string.IsNullOrWhiteSpace(Filter) || line.Contains(Filter, StringComparison.OrdinalIgnoreCase))
                {
                    Lines.Add(line);
                }
            }
        }
        catch (Exception ex)
        {
            Lines.Add($"[Log okuma hatası] {ex.Message}");
        }
    }

    partial void OnFilterChanged(string value) => Refresh();
}
