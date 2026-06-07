using System.ComponentModel;
using System.Windows;
using System.Windows.Controls;
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

    private void OnVmPropertyChanged(object? sender, PropertyChangedEventArgs e)
    {
        if (e.PropertyName == nameof(LoginViewModel.Success) && _vm.Success)
        {
            Close();
        }
    }
}
