using System.IO;
using System.Windows;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.UI.ViewModels;
using AdCastPro.SyncClient.UI.Views;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;

namespace AdCastPro.Preview;

/// <summary>
/// Gorsel onizleme: MainWindow'u (veya PREVIEW_SECTION=login ise LoginWindow'u) ornek
/// veriyle ekran disinda render edip PNG'ye kaydeder. Derleme/CI disi, tasarim dogrulamasi icindir.
/// </summary>
internal static class Program
{
    [STAThread]
    private static void Main()
    {
        var app = new Application();
        app.Resources.MergedDictionaries.Add(new ResourceDictionary
        {
            Source = new Uri("/AdCastPro.SyncClient;component/Themes/AppTheme.xaml", UriKind.Relative)
        });

        var outPath = Environment.GetEnvironmentVariable("PREVIEW_OUT") ?? "preview.png";
        var section = Environment.GetEnvironmentVariable("PREVIEW_SECTION") ?? "ozet";

        Window win;
        double w, h;
        if (section == "login")
        {
            var lvm = new LoginViewModel(null!, null!, Options.Create(new SyncClientOptions()),
                NullLogger<LoginViewModel>.Instance);
            win = new LoginWindow(lvm);
            w = 440; h = 568;
        }
        else
        {
            var vm = MainViewModel.CreateSample(section);
            win = new MainWindow(vm, null!);
            w = 1200; h = 760;
        }

        if (int.TryParse(Environment.GetEnvironmentVariable("PREVIEW_W"), out var ew) && ew > 0) w = ew;
        if (int.TryParse(Environment.GetEnvironmentVariable("PREVIEW_H"), out var eh) && eh > 0) h = eh;

        win.WindowStartupLocation = WindowStartupLocation.Manual;
        win.Left = -10000;
        win.Top = -10000;
        win.Width = w;
        win.Height = h;

        win.Loaded += (_, _) =>
        {
            win.UpdateLayout();
            win.Dispatcher.BeginInvoke(System.Windows.Threading.DispatcherPriority.Background, new Action(() =>
            {
                try
                {
                    int rw = (int)Math.Round(win.ActualWidth);
                    int rh = (int)Math.Round(win.ActualHeight);
                    if (rw <= 0) rw = (int)w;
                    if (rh <= 0) rh = (int)h;
                    var rtb = new RenderTargetBitmap(rw, rh, 96, 96, PixelFormats.Pbgra32);
                    rtb.Render(win);
                    var enc = new PngBitmapEncoder();
                    enc.Frames.Add(BitmapFrame.Create(rtb));
                    using (var fs = File.Create(outPath)) enc.Save(fs);
                    Console.WriteLine($"PREVIEW_OK {outPath} {rw}x{rh}");
                }
                catch (Exception ex)
                {
                    Console.WriteLine($"PREVIEW_ERR {ex.Message}");
                }
                finally
                {
                    app.Shutdown();
                }
            }));
        };

        app.Run(win);
    }
}
