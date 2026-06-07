using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Configuration;
using Microsoft.AspNetCore.SignalR.Client;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace AdCastPro.SyncClient.Infrastructure.Realtime;

/// <summary>
/// SignalR Hub client — backend "manifest changed" / "emergency" / "update" event'lerini dinler.
/// Connection lost → exponential backoff ile auto-reconnect (HubConnection built-in).
/// </summary>
public sealed class SignalRManifestNotifier : IManifestNotifier, IAsyncDisposable
{
    public event EventHandler<ManifestChangedArgs>? ManifestChanged;
    public event EventHandler<EmergencyBroadcastArgs>? EmergencyBroadcast;
    public event EventHandler<UpdateAvailableArgs>? UpdateAvailable;

    private readonly SyncClientOptions _options;
    private readonly ILogger<SignalRManifestNotifier> _logger;
    private HubConnection? _hub;

    public bool IsConnected => _hub?.State == HubConnectionState.Connected;

    public SignalRManifestNotifier(IOptions<SyncClientOptions> options, ILogger<SignalRManifestNotifier> logger)
    {
        _options = options.Value;
        _logger = logger;
    }

    public async Task ConnectAsync(string accessToken, CancellationToken ct = default)
    {
        if (_hub != null)
            await DisconnectAsync(ct);

        _hub = new HubConnectionBuilder()
            .WithUrl(_options.SignalRHubUrl, options =>
            {
                options.AccessTokenProvider = () => Task.FromResult<string?>(accessToken);
                options.Headers.Add("User-Agent", $"AdCastPro.SyncClient/{_options.ClientVersion}");
            })
            .WithAutomaticReconnect(new[]
            {
                TimeSpan.Zero,
                TimeSpan.FromSeconds(2),
                TimeSpan.FromSeconds(5),
                TimeSpan.FromSeconds(15),
                TimeSpan.FromSeconds(30),
                TimeSpan.FromSeconds(60),
            })
            .Build();

        _hub.On<ManifestChangedArgs>("ManifestChanged", args =>
        {
            _logger.LogInformation("SignalR: ManifestChanged — radio {Radio} reason {Reason}", args.RadioId, args.Reason);
            ManifestChanged?.Invoke(this, args);
        });

        _hub.On<EmergencyBroadcastArgs>("EmergencyBroadcast", args =>
        {
            _logger.LogWarning("SignalR: EmergencyBroadcast — {Filename} ({Priority})", args.Filename, args.Priority);
            EmergencyBroadcast?.Invoke(this, args);
        });

        _hub.On<UpdateAvailableArgs>("UpdateAvailable", args =>
        {
            _logger.LogInformation("SignalR: UpdateAvailable v{Version}", args.LatestVersion);
            UpdateAvailable?.Invoke(this, args);
        });

        _hub.Closed += async (error) =>
        {
            _logger.LogWarning(error, "SignalR connection kapandı, otomatik reconnect bekleniyor");
            await Task.CompletedTask;
        };

        _hub.Reconnected += async (connectionId) =>
        {
            _logger.LogInformation("SignalR yeniden bağlandı: {Id}", connectionId);
            await Task.CompletedTask;
        };

        try
        {
            await _hub.StartAsync(ct);
            _logger.LogInformation("SignalR Hub bağlandı: {Url}", _options.SignalRHubUrl);
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "SignalR Hub bağlantısı kurulamadı, polling fallback'e devam");
            // Throw etmiyoruz — polling fallback olarak çalışır
        }
    }

    public async Task DisconnectAsync(CancellationToken ct = default)
    {
        if (_hub == null) return;
        try { await _hub.StopAsync(ct); } catch { /* ignore */ }
        await _hub.DisposeAsync();
        _hub = null;
    }

    public async ValueTask DisposeAsync()
    {
        await DisconnectAsync(CancellationToken.None);
    }
}
