using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Core.Models;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace AdCastPro.SyncClient.UI.ViewModels;

public sealed partial class LoginViewModel : ObservableObject
{
    private readonly IApiClient _api;
    private readonly ITokenStore _store;
    private readonly SyncClientOptions _options;
    private readonly ILogger<LoginViewModel> _logger;

    [ObservableProperty] private string _username = "";
    [ObservableProperty] private string _password = "";
    [ObservableProperty] private bool _isLoading;
    [ObservableProperty] private string? _errorMessage;
    [ObservableProperty] private bool _success;

    public LoginViewModel(IApiClient api, ITokenStore store, IOptions<SyncClientOptions> options, ILogger<LoginViewModel> logger)
    {
        _api = api;
        _store = store;
        _options = options.Value;
        _logger = logger;
    }

    [RelayCommand(CanExecute = nameof(CanLogin))]
    private async Task LoginAsync()
    {
        IsLoading = true;
        ErrorMessage = null;
        try
        {
            var machineId = MachineIdProvider.GetOrCreate();
            var request = new LoginRequest(
                Username: Username.Trim(),
                Password: Password,
                ClientVersion: _options.ClientVersion,
                MachineId: machineId
            );
            var response = await _api.LoginAsync(request);
            await _store.SaveAsync(response.Tokens, response.User, response.Radio);
            _logger.LogInformation("Login başarılı: {User} → radio {Radio}", response.User.Username, response.Radio?.Name);
            Success = true;
        }
        catch (HttpRequestException ex)
        {
            ErrorMessage = "Sunucuya erişilemiyor. İnternet bağlantınızı kontrol edin.";
            _logger.LogWarning(ex, "Login network hatası");
        }
        catch (Exception ex)
        {
            ErrorMessage = "Kullanıcı adı veya şifre hatalı.";
            _logger.LogWarning(ex, "Login başarısız");
        }
        finally
        {
            IsLoading = false;
        }
    }

    private bool CanLogin() => !IsLoading && !string.IsNullOrWhiteSpace(Username) && !string.IsNullOrEmpty(Password);

    partial void OnUsernameChanged(string value) => LoginCommand.NotifyCanExecuteChanged();
    partial void OnPasswordChanged(string value) => LoginCommand.NotifyCanExecuteChanged();
    partial void OnIsLoadingChanged(bool value) => LoginCommand.NotifyCanExecuteChanged();
}
