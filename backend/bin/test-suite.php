<?php

declare(strict_types=1);

use Aws\Exception\AwsException;
use RadioSaaS\Infrastructure\MinioStorage;
use RadioSaaS\Infrastructure\PdoFactory;

require __DIR__ . '/../vendor/autoload.php';

final class IntegrationFailure extends RuntimeException
{
}

function succeed(string $message): void
{
    echo "[OK] {$message}\n";
}

function fail(string $message, ?Throwable $previous = null): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    if ($previous !== null) {
        fwrite(STDERR, $previous->getMessage() . "\n");
    }

    exit(1);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new IntegrationFailure($message);
    }
}

function httpJsonRequest(string $method, string $url, array $payload = [], array $headers = []): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new IntegrationFailure('JSON payload could not be encoded.');
    }

    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($body),
                ...$headers,
            ]),
            'content' => $body,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    // $http_response_header file_get_contents sonrası PHP tarafından otomatik
    // tanımlanır; ?? gereksiz ama PHPStan görmüyor diye explicit isset.
    $responseHeaders = isset($http_response_header) ? $http_response_header : [];
    $statusLine = $responseHeaders[0] ?? 'HTTP/1.1 000 Unknown';

    if ($responseBody === false) {
        throw new IntegrationFailure(sprintf('HTTP request failed for %s %s', $method, $url));
    }

    if (!preg_match('#HTTP/\d(?:\.\d)?\s+(\d{3})#', $statusLine, $matches)) {
        throw new IntegrationFailure(sprintf('HTTP status could not be parsed for %s', $url));
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        throw new IntegrationFailure(sprintf('Response is not valid JSON for %s', $url));
    }

    return [
        'status' => (int) $matches[1],
        'body' => $decoded,
        'headers' => $responseHeaders,
    ];
}

function extractCookie(array $response, string $name): string
{
    foreach ($response['headers'] ?? [] as $header) {
        if (preg_match('/^Set-Cookie:\s*' . preg_quote($name, '/') . '=([^;]+)/i', $header, $m)) {
            return trim($m[1]);
        }
    }

    return '';
}

function extractSessionCookie(array $response): string
{
    return extractCookie($response, 'radio_session');
}

try {
    echo "Starting integration smoke test...\n";

    $pdo = PdoFactory::fromEnv();
    assertTrue((int) $pdo->query('SELECT 1')->fetchColumn() === 1, 'Database health query failed.');
    succeed('Database connection is healthy.');

    $adminRow = $pdo->query("SELECT id, username, password_hash, is_active FROM users WHERE username = 'admin' LIMIT 1")->fetch();
    assertTrue(is_array($adminRow), 'Seeded admin user not found.');
    assertTrue((bool) ($adminRow['is_active'] ?? false), 'Seeded admin user is not active.');
    assertTrue(is_string($adminRow['password_hash'] ?? null) && password_verify('123456', (string) $adminRow['password_hash']), 'Admin password hash does not match 123456.');
    succeed('Seeded admin user exists and password hash is valid.');

    $storage = MinioStorage::fromEnv();
    $client = $storage->client();
    $bucketName = getenv('MINIO_BUCKET_PUBLIC') ?: 'radio-media';
    $bucketList = $client->listBuckets();
    $buckets = array_map(
        static fn (array $bucket): string => (string) ($bucket['Name'] ?? ''),
        $bucketList['Buckets'] ?? []
    );
    assertTrue(in_array($bucketName, $buckets, true), sprintf('Bucket "%s" not found in MinIO.', $bucketName));
    $client->headBucket(['Bucket' => $bucketName]);
    succeed(sprintf('MinIO bucket "%s" is reachable.', $bucketName));

    $gatewayBase = rtrim(getenv('API_GATEWAY_URL') ?: 'http://nginx/api/v1', '/');
    $loginResponse = httpJsonRequest('POST', $gatewayBase . '/auth/login', [
        'username' => 'admin',
        'password' => '123456',
    ]);
    assertTrue($loginResponse['status'] === 200, 'Login endpoint did not return HTTP 200.');
    assertTrue(($loginResponse['body']['code'] ?? null) === 0, 'Login response code is not 0.');
    assertTrue(is_array($loginResponse['body']['result'] ?? null), 'Login result payload is missing.');
    assertTrue(!empty($loginResponse['body']['result']['username'] ?? null), 'Login profile (username) is missing.');
    assertTrue(empty($loginResponse['body']['result']['token'] ?? null), 'Login response must NOT leak the raw token in the body.');
    succeed('Auth login endpoint returned a valid Vben-compatible payload.');

    // The session token now lives only in the HttpOnly cookie; authenticate
    // subsequent requests with it (accepted via the cookie->Bearer promotion).
    $sessionToken = extractSessionCookie($loginResponse);
    assertTrue($sessionToken !== '', 'Login did not set the HttpOnly session cookie.');
    // Cookie-auth mutations now require the CSRF double-submit token.
    $csrfToken = extractCookie($loginResponse, 'radio_csrf');
    assertTrue($csrfToken !== '', 'Login did not set the CSRF cookie.');
    $authorizationHeaders = [
        'Cookie: radio_session=' . $sessionToken . '; radio_csrf=' . $csrfToken,
        'X-CSRF-Token: ' . $csrfToken,
    ];
    $stationResponse = httpJsonRequest('POST', $gatewayBase . '/stations', [
        'name' => 'Smoke Test Station',
        'slug' => 'smoke-station-' . bin2hex(random_bytes(4)),
        'region_code' => 'marmara',
        'city_name' => 'Istanbul',
    ], $authorizationHeaders);
    // Faz 18: create response artık {result:{station,partner,tokens}} zarflıyor
    // (auto-provision özelliği). İstasyon nesnesi result.station altında.
    $station = $stationResponse['body']['result']['station'] ?? null;
    assertTrue($stationResponse['status'] === 201, 'Station creation endpoint did not return HTTP 201.');
    assertTrue(is_array($station), 'Station creation payload is missing.');
    assertTrue(!empty($station['id'] ?? null), 'Created station id is missing.');
    assertTrue(!empty($station['station_code'] ?? null), 'Created station code is missing.');
    succeed('Station creation endpoint returned a complete station payload.');

    $sponsorResponse = httpJsonRequest('POST', $gatewayBase . '/sponsors/assign', [
        'name' => 'Smoke Test Sponsor',
        'placement' => 'pre_roll',
        'placement_type' => 'intro',
        'is_global' => false,
        'target_regions' => ['marmara', 'ege'],
        'target_parts' => ['news', 'sports'],
        'asset_bucket' => $bucketName,
        'asset_key' => 'smoke-tests/sponsor.mp3',
        'asset_mime' => 'audio/mpeg',
    ], $authorizationHeaders);
    $sponsorIds = $sponsorResponse['body']['result']['sponsor_ids'] ?? $sponsorResponse['body']['sponsor_ids'] ?? null;
    assertTrue($sponsorResponse['status'] === 201, 'Sponsor assignment endpoint did not return HTTP 201.');
    assertTrue(is_array($sponsorIds) && count($sponsorIds) === 4, 'Sponsor assignment endpoint did not create four assignments.');
    succeed('Sponsor assignment endpoint created the expected region and part combinations.');

    $invalidTokenResponse = httpJsonRequest('GET', $gatewayBase . '/stations', [], [
        'Authorization: Bearer invalid-smoke-token',
    ]);
    assertTrue($invalidTokenResponse['status'] === 401, 'Invalid admin token was not rejected with HTTP 401.');
    succeed('Invalid admin token is rejected with HTTP 401.');

    $stationDeleteResponse = httpJsonRequest('DELETE', $gatewayBase . '/stations/' . $station['id'], [], $authorizationHeaders);
    assertTrue($stationDeleteResponse['status'] === 200, 'Smoke station cleanup failed.');

    $placeholders = implode(', ', array_fill(0, count($sponsorIds), '?'));
    $cleanupStatement = $pdo->prepare(sprintf('DELETE FROM sponsors_ads WHERE id IN (%s)', $placeholders));
    $cleanupStatement->execute($sponsorIds);
    succeed('Smoke test records were cleaned up.');

    echo "[SUCCESS] Integration smoke test completed.\n";
    exit(0);
} catch (AwsException $exception) {
    fail('MinIO health check failed.', $exception);
} catch (Throwable $exception) {
    fail($exception->getMessage(), $exception);
}
