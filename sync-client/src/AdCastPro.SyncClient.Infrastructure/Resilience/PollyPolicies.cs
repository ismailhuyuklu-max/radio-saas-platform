using System.Net;
using AdCastPro.SyncClient.Core.Configuration;
using Polly;
using Polly.CircuitBreaker;
using Polly.Retry;
// Type alias — Core.Configuration.RetryPolicy ile Polly.Retry.RetryPolicy çakışması çözümü
using RetryPolicyOptions = AdCastPro.SyncClient.Core.Configuration.RetryPolicy;

namespace AdCastPro.SyncClient.Infrastructure.Resilience;

/// <summary>
/// Polly v8 ResiliencePipeline factory — retry + circuit breaker.
///
/// Yayıncılık için önemli: aşırı retry yapma (haber saati öncesi rate-limit
/// tükenirse hiçbir dosya inmez). Backoff exponential ama max 5dk cap.
/// </summary>
public static class PollyPolicies
{
    private static readonly HttpStatusCode[] TransientStatusCodes =
    {
        HttpStatusCode.RequestTimeout,           // 408
        HttpStatusCode.TooManyRequests,          // 429
        HttpStatusCode.InternalServerError,      // 500
        HttpStatusCode.BadGateway,               // 502
        HttpStatusCode.ServiceUnavailable,       // 503
        HttpStatusCode.GatewayTimeout,           // 504
    };

    public static ResiliencePipeline<HttpResponseMessage> CreatePipeline(RetryPolicyOptions options)
    {
        return new ResiliencePipelineBuilder<HttpResponseMessage>()
            .AddRetry(new RetryStrategyOptions<HttpResponseMessage>
            {
                MaxRetryAttempts = options.MaxAttempts,
                BackoffType = DelayBackoffType.Exponential,
                UseJitter = true,
                Delay = TimeSpan.FromMilliseconds(options.InitialDelayMs),
                MaxDelay = TimeSpan.FromMilliseconds(options.MaxDelayMs),
                ShouldHandle = args => ValueTask.FromResult(
                    args.Outcome.Exception is HttpRequestException ||
                    args.Outcome.Exception is TaskCanceledException ||
                    (args.Outcome.Result is { } response && TransientStatusCodes.Contains(response.StatusCode))
                ),
            })
            .AddCircuitBreaker(new CircuitBreakerStrategyOptions<HttpResponseMessage>
            {
                FailureRatio = 0.5,                      // %50 hata → open
                MinimumThroughput = 10,                  // En az 10 istek olmalı
                SamplingDuration = TimeSpan.FromSeconds(30),
                BreakDuration = TimeSpan.FromSeconds(30),
                ShouldHandle = args => ValueTask.FromResult(
                    args.Outcome.Exception is HttpRequestException ||
                    (args.Outcome.Result is { } response && TransientStatusCodes.Contains(response.StatusCode))
                ),
            })
            .Build();
    }
}
