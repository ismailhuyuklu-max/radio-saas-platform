using System.Windows;
using AdCastPro.SyncClient.UI.ViewModels;

namespace AdCastPro.SyncClient.UI.Views;

public partial class SettingsWindow : Window
{
    public SettingsWindow(SettingsViewModel vm)
    {
        InitializeComponent();
        DataContext = vm;
    }

    private void OnCancel(object sender, RoutedEventArgs e) => Close();
    private void OnSave(object sender, RoutedEventArgs e) => Close();
}
