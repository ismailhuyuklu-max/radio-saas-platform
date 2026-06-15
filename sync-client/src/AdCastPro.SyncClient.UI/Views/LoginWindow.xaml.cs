using System.ComponentModel;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using AdCastPro.SyncClient.UI.ViewModels;

namespace AdCastPro.SyncClient.UI.Views;

public partial class LoginWindow : Window
{
    private readonly LoginViewModel _vm;

    public LoginWindow(LoginViewModel vm)
    {
        InitializeComponent();
        _vm = vm;
        DataContext = vm;
        vm.PropertyChanged += OnVmPropertyChanged;
        Loaded += (_, _) => UsernameBox.Focus();
    }

    private void OnPasswordChanged(object sender, RoutedEventArgs e)
    {
        if (sender is PasswordBox pb) _vm.Password = pb.Password;
    }

    /// <summary>Baslik cubugundan pencereyi suruklemeyi etkinlestirir.</summary>
    private void OnTitleBarMouseDown(object sender, MouseButtonEventArgs e)
    {
        if (e.ChangedButton == MouseButton.Left) DragMove();
    }

    /// <summary>Kapat: token yoksa App.Closed handler'i uygulamayi sonlandirir.</summary>
    private void OnClose(object sender, RoutedEventArgs e) => Close();

    private void OnVmPropertyChanged(object? sender, PropertyChangedEventArgs e)
    {
        if (e.PropertyName == nameof(LoginViewModel.Success) && _vm.Success)
        {
            Close();
        }
    }
}
