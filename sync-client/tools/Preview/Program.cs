using System.IO;
using System.Windows;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using AdCastPro.SyncClient.UI.ViewModels;
using AdCastPro.SyncClient.UI.Views;

namespace AdCastPro.Preview;

/// <summary>
/// Gorsel onizleme: MainWindow'u ornek veriyle (CreateSample) ekran disinda render edip
/// PNG'ye kaydeder. Derleme/CI disi, yalnizca tasarim dogrulamasi icindir.
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

        var vm = MainViewModel.CreateSample();
        var win = new MainWindow(vm, null!)
        {
            WindowStartupLocation = WindowStartupLocation.Manual,
            Left = -10000,
            Top = -10000,
            Width = 1200,
            Height = 760,
        };

        win.Loaded += (_, _) =>
        {
            win.UpdateLayout();
            win.Dispatcher.BeginInvoke(System.Windows.Threading.DispatcherPriority.Background, new Action(() =>
            {
                try
                {
                    int w = (int)Math.Round(win.ActualWidth);
                    int h = (int)Math.Round(win.ActualHeight);
                    if (w <= 0) w = 1360;
                    if (h <= 0) h = 880;
                    var rtb = new RenderTargetBitmap(w, h, 96, 96, PixelFormats.Pbgra32);
                    rtb.Render(win);
                    var enc = new PngBitmapEncoder();
                    enc.Frames.Add(BitmapFrame.Create(rtb));
                    using (var fs = File.Create(outPath)) enc.Save(fs);
                    Console.WriteLine($"PREVIEW_OK {outPath} {w}x{h}");
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
