using System.Windows;
using AdCastPro.SyncClient.UI.ViewModels;

namespace AdCastPro.SyncClient.UI.Views;

public partial class LogsWindow : Window
{
    public LogsWindow(LogsViewModel vm)
    {
        InitializeComponent();
        DataContext = vm;
    }
}
