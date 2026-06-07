using AdCastPro.SyncClient.Core.Abstractions;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Infrastructure.Api;
using AdCastPro.SyncClient.Infrastructure.Files;
using AdCastPro.SyncClient.Infrastructure.Queue;
using AdCastPro.SyncClient.Infrastructure.Realtime;
using AdCastPro.SyncClient.Infrastructure.Resilience;
using AdCastPro.SyncClient.Infrastructure.Storage;
using AdCastPro.SyncClient.Infrastructure.Update;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Options;
using Polly;

namespace AdCastPro.SyncClient.Infrastructure;

public static class ServiceCollectionExtensions
{
    public static IServiceCollection AddSyncClientInfrastructure(this IServiceCollection services)
    {
        // Token storage
        services.AddSingleton<ITokenStore, DpapiTokenStore>();

        // File ops
        services.AddSingleton<IChecksumService, Sha256ChecksumService>();
        services.AddSingleton<IAtomicFileWriter, AtomicFileWriter>();

        // SQLite — DbContextFactory (Hosted Service'lerden thread-safe erişim)
        services.AddDbContextFactory<AppDbContext>(opts =>
        {
            opts.UseSqlite($"Data Source={AppDbContext.GetDefaultDbPath()}");
        });
        services.AddSingleton<ILocalCache, SqliteCache>();

        // Polly resilience
        services.AddSingleton<ResiliencePipeline<HttpResponseMessage>>(sp =>
        {
            var options = sp.GetRequiredService<IOptions<SyncClientOptions>>().Value;
            return PollyPolicies.CreatePipeline(options.Retry);
        });

        // HttpClient + AuthDelegatingHandler
        services.AddTransient<AuthDelegatingHandler>();
        services.AddHttpClient<IApiClient, ApiClient>((sp, http) =>
        {
            var opts = sp.GetRequiredService<IOptions<SyncClientOptions>>().Value;
            http.BaseAddress = new Uri(opts.ApiBaseUrl.TrimEnd('/') + "/");
            http.Timeout = TimeSpan.FromMinutes(5); // büyük dosya download
        }).AddHttpMessageHandler<AuthDelegatingHandler>();

        // Auto-updater (ayrı HttpClient — MSI indirme, uzun timeout)
        services.AddHttpClient<IAutoUpdater, AutoUpdaterService>((_, http) =>
        {
            http.Timeout = TimeSpan.FromMinutes(15);
        });

        // Priority download queue (Singleton — global state)
        services.AddSingleton<IDownloadQueue, PriorityDownloadQueue>();

        // SignalR client (Singleton — persistent hub connection)
        services.AddSingleton<IManifestNotifier, SignalRManifestNotifier>();

        return services;
    }

    /// <summary>EF Core EnsureCreated — ilk kurulumda DB + tablolar oluşur.</summary>
    public static async Task EnsureDatabaseCreatedAsync(this IServiceProvider provider, CancellationToken ct = default)
    {
        var factory = provider.GetRequiredService<IDbContextFactory<AppDbContext>>();
        await using var db = await factory.CreateDbContextAsync(ct);
        await db.Database.EnsureCreatedAsync(ct);
    }
}
