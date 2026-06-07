using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Core.Models;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace AdCastPro.SyncClient.App.Workers;

/// <summary>
/// Periyodik backend heartbeat — client online/offline durumunu
/// /api/v1/sync/heartbeat ile bildirir. Admin panelinde sync-admin
/// sayfası 30s'de bir bu veriyi okur.
/// </summary>
public sealed class HeartbeatService : BackgroundService
{
    private readonly IApiClient _api;
    private readonly ITokenStore _tokens;
    private readonly SyncClientOptions _options;
    private readonly ILogger<HeartbeatService> _logger;

    public HeartbeatService(
        IApiClient api,
        ITokenStore tokens,
        IOptions<SyncClientOptions> options,
        ILogger<HeartbeatService> logger)
    {
        _api = api;
        _tokens = tokens;
        _options = options.Value;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var interval = TimeSpan.FromSeconds(_options.HeartbeatIntervalSeconds);
        _logger.LogInformation("HeartbeatService başlatıldı, interval {Seconds}s", interval.TotalSeconds);

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                var (tokens, _, _) = await _tokens.LoadAsync(stoppingToken);
                if (tokens != null)
                {
                    await _api.SendHeartbeatAsync(new Heartbeat
                    {
                        ClientVersion = _options.ClientVersion,
                        Os = GetOsDescription(),
                        DiskFreeGb = (int)(GetSystemDriveFreeBytes() / 1_073_741_824),
                    }, stoppingToken);
                }
            }
            catch (OperationCanceledException) { break; }
            catch (Exception ex)
            {
                _logger.LogDebug(ex, "Heartbeat başarısız (network olabilir, devam)");
            }

            try { await Task.Delay(interval, stoppingToken); }
            catch (OperationCanceledException) { break; }
        }
    }

    private static string GetOsDescription()
    {
        try { return $"{Environment.OSVersion.VersionString}"; }
        catch { return "Windows"; }
    }

    private static long GetSystemDriveFreeBytes()
    {
        try
        {
            var drive = new DriveInfo(Path.GetPathRoot(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData)) ?? "C:\\");
            return drive.IsReady ? drive.AvailableFreeSpace : 0;
        }
        catch { return 0; }
    }
}
