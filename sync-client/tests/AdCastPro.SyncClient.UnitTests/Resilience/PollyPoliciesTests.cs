using System.Net;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Infrastructure.Resilience;
using FluentAssertions;
using Xunit;

namespace AdCastPro.SyncClient.UnitTests.Resilience;

public class PollyPoliciesTests
{
    [Fact]
    public async Task Pipeline_TransientHata_RetryEder()
    {
        var policy = PollyPolicies.CreatePipeline(new RetryPolicy { MaxAttempts = 3, InitialDelayMs = 10, MaxDelayMs = 100 });
        int attempts = 0;

        var result = await policy.ExecuteAsync(_ =>
        {
            attempts++;
            if (attempts < 3)
                return ValueTask.FromResult(new HttpResponseMessage(HttpStatusCode.ServiceUnavailable));
            return ValueTask.FromResult(new HttpResponseMessage(HttpStatusCode.OK));
        });

        attempts.Should().Be(3);
        result.StatusCode.Should().Be(HttpStatusCode.OK);
    }

    [Fact]
    public async Task Pipeline_NormalCevap_RetryYok()
    {
        var policy = PollyPolicies.CreatePipeline(new RetryPolicy());
        int attempts = 0;

        await policy.ExecuteAsync(_ =>
        {
            attempts++;
            return ValueTask.FromResult(new HttpResponseMessage(HttpStatusCode.OK));
        });

        attempts.Should().Be(1);
    }

    [Fact]
    public async Task Pipeline_4xxClientHatasi_RetryYapmaz()
    {
        var policy = PollyPolicies.CreatePipeline(new RetryPolicy { MaxAttempts = 5, InitialDelayMs = 10 });
        int attempts = 0;

        await policy.ExecuteAsync(_ =>
        {
            attempts++;
            return ValueTask.FromResult(new HttpResponseMessage(HttpStatusCode.NotFound));
        });

        attempts.Should().Be(1); // 404 transient değil
    }
}
