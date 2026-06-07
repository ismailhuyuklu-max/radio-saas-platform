using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Core.Models;
using AdCastPro.SyncClient.Infrastructure.Api;
using AdCastPro.SyncClient.Infrastructure.Resilience;
using AdCastPro.SyncClient.Infrastructure.Storage;
using FluentAssertions;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Xunit;

namespace AdCastPro.SyncClient.IntegrationTests;

/// <summary>
/// FAZ 3 AŞAMA 3 — Gerçek API ile integration testleri.
///
/// Çalıştırma için ENV var'ları zorunlu:
///   ADCAST_API_BASE_URL=https://adcastpro.com
///   ADCAST_TEST_USERNAME=integration_test_user
///   ADCAST_TEST_PASSWORD=integration_test_password
///
/// Bu testler [Trait("Category","Integration")] olduğu için CI'da varsayılan
/// olarak SKIP edilir (gerçek API gerek). Yerel'de:
///   dotnet test --filter "Category=Integration"
/// </summary>
[Trait("Category", "Integration")]
public class ApiIntegrationTests
{
    private static (ApiClient client, DpapiTokenStore store) BuildClient()
    {
        var baseUrl = Environment.GetEnvironmentVariable("ADCAST_API_BASE_URL")
            ?? throw new InvalidOperationException("ADCAST_API_BASE_URL gerekli");
        var options = Options.Create(new SyncClientOptions
        {
            ApiBaseUrl = baseUrl,
            ClientVersion = "1.0.0-test",
        });
        var store = new DpapiTokenStore(NullLogger<DpapiTokenStore>.Instance);
        var pipeline = PollyPolicies.CreatePipeline(new RetryPolicy());
        var http = new HttpClient
        {
            Timeout = TimeSpan.FromSeconds(30),
        };
        return (new ApiClient(http, pipeline, options, NullLogger<ApiClient>.Instance), store);
    }

    [SkippableFact]
    public async Task Login_GecerliKullanici_AccessTokenDoner()
    {
        var username = Environment.GetEnvironmentVariable("ADCAST_TEST_USERNAME");
        var password = Environment.GetEnvironmentVariable("ADCAST_TEST_PASSWORD");
        Skip.If(username == null || password == null, "Test credentials yok");

        var (client, _) = BuildClient();
        var response = await client.LoginAsync(new LoginRequest(username!, password!, "1.0.0-test", "integration-machine"));

        response.Tokens.AccessToken.Should().NotBeNullOrEmpty();
        response.Tokens.RefreshToken.Should().NotBeNullOrEmpty();
        response.User.Username.Should().Be(username);
    }

    [SkippableFact]
    public async Task Login_YanlisSifre_Unauthorized()
    {
        var username = Environment.GetEnvironmentVariable("ADCAST_TEST_USERNAME");
        Skip.If(username == null, "Test username yok");

        var (client, _) = BuildClient();
        var act = async () => await client.LoginAsync(new LoginRequest(username!, "wrong_password_xxx", "1.0.0-test", "test"));
        await act.Should().ThrowAsync<HttpRequestException>();
    }

    [SkippableFact]
    public async Task RefreshFlow_RotateBaşarılı()
    {
        var username = Environment.GetEnvironmentVariable("ADCAST_TEST_USERNAME");
        var password = Environment.GetEnvironmentVariable("ADCAST_TEST_PASSWORD");
        Skip.If(username == null || password == null, "Test credentials yok");

        var (client, _) = BuildClient();
        var login = await client.LoginAsync(new LoginRequest(username!, password!, "1.0.0-test", "test"));

        var refresh = await client.RefreshAsync(new RefreshRequest(login.Tokens.RefreshToken));
        refresh.AccessToken.Should().NotBeNullOrEmpty();
        refresh.AccessToken.Should().NotBe(login.Tokens.AccessToken);  // rotate happened
    }

    [SkippableFact]
    public async Task GetMe_ValidToken_RadioInfo()
    {
        var username = Environment.GetEnvironmentVariable("ADCAST_TEST_USERNAME");
        var password = Environment.GetEnvironmentVariable("ADCAST_TEST_PASSWORD");
        Skip.If(username == null || password == null, "Test credentials yok");

        var (client, store) = BuildClient();
        var login = await client.LoginAsync(new LoginRequest(username!, password!, "1.0.0-test", "test"));
        await store.SaveAsync(login.Tokens, login.User, login.Radio);

        var me = await client.GetMeAsync();
        me.User.Username.Should().Be(username);
    }

    [SkippableFact]
    public async Task GetManifest_ValidToken_DosyaListesi()
    {
        var username = Environment.GetEnvironmentVariable("ADCAST_TEST_USERNAME");
        var password = Environment.GetEnvironmentVariable("ADCAST_TEST_PASSWORD");
        Skip.If(username == null || password == null, "Test credentials yok");

        var (client, store) = BuildClient();
        var login = await client.LoginAsync(new LoginRequest(username!, password!, "1.0.0-test", "test"));
        await store.SaveAsync(login.Tokens, login.User, login.Radio);

        var manifest = await client.GetManifestAsync();
        manifest.Should().NotBeNull();
        manifest!.RadioId.Should().NotBeNullOrEmpty();
        manifest.WindowStart.Should().BeBefore(manifest.WindowEnd);
    }

    [SkippableFact]
    public async Task Heartbeat_Basarili()
    {
        var username = Environment.GetEnvironmentVariable("ADCAST_TEST_USERNAME");
        var password = Environment.GetEnvironmentVariable("ADCAST_TEST_PASSWORD");
        Skip.If(username == null || password == null, "Test credentials yok");

        var (client, store) = BuildClient();
        var login = await client.LoginAsync(new LoginRequest(username!, password!, "1.0.0-test", "test"));
        await store.SaveAsync(login.Tokens, login.User, login.Radio);

        var act = async () => await client.SendHeartbeatAsync(new Heartbeat
        {
            ClientVersion = "1.0.0-test",
            Os = "Windows 11 Integration",
            DiskFreeGb = 100,
        });
        await act.Should().NotThrowAsync();
    }
}
