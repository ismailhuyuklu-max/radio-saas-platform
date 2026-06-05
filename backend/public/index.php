<?php

declare(strict_types=1);

use RadioSaaS\Controller\FeedController;
use RadioSaaS\Controller\AuthController;
use RadioSaaS\Controller\AccessController;
use RadioSaaS\Controller\MatrixController;
use RadioSaaS\Controller\MediaController;
use RadioSaaS\Controller\PlanningController;
use RadioSaaS\Controller\StationController;
use RadioSaaS\Infrastructure\MinioStorage;
use RadioSaaS\Infrastructure\PdoFactory;
use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\AdminSessionRepository;
use RadioSaaS\Repository\ApiTokenRepository;
use RadioSaaS\Repository\ContentPlanRepository;
use RadioSaaS\Repository\JobRepository;
use RadioSaaS\Repository\MediaContentRepository;
use RadioSaaS\Repository\MatrixRepository;
use RadioSaaS\Repository\RegionRepository;
use RadioSaaS\Repository\SponsorAdRepository;
use RadioSaaS\Repository\StationRepository;
use RadioSaaS\Repository\UserRepository;
use RadioSaaS\Service\AdminAuthenticator;
use RadioSaaS\Service\MediaFeedService;
use RadioSaaS\Service\RenderQueueService;
use RadioSaaS\Service\TokenAuthenticator;

$appEnv = getenv('APP_ENV') ?: 'local';
// Demo mode bypasses all authentication and must NEVER be active in production,
// even if LOCAL_DEMO_MODE=1 is left set by mistake.
$demoMode = $appEnv !== 'production'
    && filter_var(getenv('LOCAL_DEMO_MODE') ?: '0', FILTER_VALIDATE_BOOL);
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';

if (!$demoMode && !is_file($vendorAutoload)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Backend dependencies are not installed.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

if (!$demoMode) {
    require $vendorAutoload;
}

// Production safety net: surface insecure default secrets in the server log.
if ($appEnv === 'production') {
    $weakDefaults = [];
    if ((getenv('MINIO_SECRET_KEY') ?: 'minioadmin123') === 'minioadmin123') {
        $weakDefaults[] = 'MINIO_SECRET_KEY';
    }
    if ((getenv('DB_PASSWORD') ?: 'radio_saas_password') === 'radio_saas_password') {
        $weakDefaults[] = 'DB_PASSWORD';
    }
    if ($weakDefaults !== []) {
        error_log('[SECURITY] Production ortaminda varsayilan sirlar kullaniliyor: ' . implode(', ', $weakDefaults));
    }
}

if ($demoMode) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    $demoUser = [
        'token' => 'LOCAL_MOCK_JWT_TOKEN_XYZ',
        'userId' => '1',
        'username' => 'admin',
        'realName' => 'İsmail Hüyüklü',
        'roles' => ['super'],
    ];

    if ($path === '/healthz') {
        header('Content-Type: text/plain');
        echo "ok\n";
        return;
    }

    if ($method === 'GET' && $path === '/api/v1/auth/login') {
        header('Content-Type: text/html; charset=utf-8');
        echo <<<'HTML'
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Radio SaaS Login</title>
  <style>
    body { font-family: Arial, sans-serif; background: #0f172a; color: #e2e8f0; display: grid; place-items: center; min-height: 100vh; margin: 0; }
    .card { width: min(92vw, 420px); background: #111827; border: 1px solid #334155; border-radius: 18px; padding: 24px; box-shadow: 0 20px 60px rgba(0,0,0,.35); }
    input, button { width: 100%; padding: 12px 14px; border-radius: 12px; border: 1px solid #334155; background: #0b1220; color: #e2e8f0; font-size: 14px; box-sizing: border-box; }
    .field { margin: 12px 0; }
    button { background: #2563eb; border-color: #2563eb; cursor: pointer; font-weight: 700; }
    .hint { font-size: 13px; color: #94a3b8; line-height: 1.5; }
    .status { margin-top: 14px; white-space: pre-wrap; font-size: 13px; color: #cbd5e1; }
    .row { display: grid; gap: 12px; }
  </style>
</head>
<body>
  <main class="card">
    <h1>Radio SaaS Login</h1>
    <p class="hint">Bu sayfa tarayıcıdan açıldığında form gösterir. Vben paneli aynı endpoint'e POST isteği gönderir.</p>
    <form id="loginForm" class="row">
      <div class="field"><input name="username" value="admin" placeholder="Kullanıcı adı" autocomplete="username"></div>
      <div class="field"><input name="password" type="password" value="123456" placeholder="Şifre" autocomplete="current-password"></div>
      <button type="submit">Giriş Yap</button>
    </form>
    <div id="status" class="status"></div>
  </main>
  <script>
    const form = document.getElementById('loginForm');
    const status = document.getElementById('status');

    const setStatus = (message) => {
      status.textContent = message;
    };

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      setStatus('Giriş deneniyor...');

      try {
        const data = Object.fromEntries(new FormData(form).entries());
        const response = await fetch('/api/v1/auth/login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify(data),
        });

        const body = await response.json();
        if (!response.ok || !body || body.code !== 0 || !body.result || !body.result.token) {
          throw new Error((body && body.message) ? body.message : 'Giriş başarısız');
        }

        localStorage.setItem('accessToken', body.result.token);
        localStorage.setItem('token', body.result.token);
        localStorage.setItem('userInfo', JSON.stringify(body.result));

        setStatus('Giriş başarılı. Frontend paneline yönlendiriliyorsunuz...');
        window.setTimeout(() => {
          window.location.href = 'http://localhost:3000';
        }, 900);
      } catch (error) {
        const message = error && error.message ? error.message : 'Bilinmeyen hata';
        setStatus('Giriş sırasında hata oluştu: ' + message);
      }
    });
  </script>
</body>
</html>
HTML;
        return;
    }

    if ($method === 'POST' && $path === '/api/v1/auth/login') {
        $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $username = (string) ($payload['username'] ?? $payload['account'] ?? '');
        $password = (string) ($payload['password'] ?? '');

        if ($username === 'admin' && $password === '123456') {
            header('Content-Type: application/json');
            echo json_encode([
                'code' => 0,
                'result' => $demoUser,
                'message' => 'Success',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'code' => 1,
            'result' => null,
            'message' => 'Invalid credentials',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($path === '/api/v1/auth/login') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode([
            'code' => 1,
            'result' => null,
            'message' => 'Method Not Allowed. Use POST for /api/v1/auth/login.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($method === 'GET' && ($path === '/api/v1/user/info' || $path === '/api/v1/auth/user')) {
        header('Content-Type: application/json');
        echo json_encode([
            'code' => 0,
            'result' => $demoUser,
            'message' => 'Success',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($method === 'GET' && $path === '/api/v1/stations') {
        header('Content-Type: application/json');
        echo json_encode([
            [
                'id' => 'station-1',
                'name' => 'Adana FM',
                'slug' => 'adana-fm',
                'region_code' => 'akdeniz',
                'region_name' => 'Akdeniz',
                'status' => 'active',
                'station_token' => 'seed-token-adana',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($method === 'GET' && $path === '/api/v1/media/matrix') {
        header('Content-Type: application/json');
        echo json_encode([
            'akdeniz' => [
                'news' => ['status' => 'success', 'updated_at' => '2026-05-29 17:30'],
                'sports' => ['status' => 'warning', 'updated_at' => '2026-05-29 12:00'],
                'economy' => ['status' => 'danger', 'updated_at' => null],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($method === 'GET' && preg_match('#^/api/v1/feeds/([^/]+)/([^/.]+)\.(json|xml|m3u)$#', $path, $matches)) {
        header('Content-Type: application/json');
        echo json_encode([
            'station' => [
                'id' => 'station-1',
                'name' => 'Adana FM',
                'slug' => $matches[1],
                'region_code' => 'akdeniz',
                'region_name' => 'Akdeniz',
            ],
            'media' => [
                'id' => 'media-1',
                'part_code' => $matches[2],
                'title' => 'Demo feed',
                'render_state' => 'rendered',
            ],
            'sponsor' => null,
            'stream' => [
                'bucket' => 'radio-media',
                'key' => 'demo/feed.mp3',
                'mime' => 'audio/mpeg',
                'download_url' => 'http://localhost:9000/radio-media/demo/feed.mp3',
                'public_url' => 'http://localhost:9000/radio-media/demo/feed.mp3',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($method === 'GET' && preg_match('#^/api/v1/streams/([^/]+)/([^/]+)$#', $path, $matches)) {
        header('Location: http://localhost:9000/radio-media/demo/feed.mp3', true, 302);
        return;
    }

    if ($method === 'POST' && $path === '/api/v1/media/upload') {
        header('Content-Type: application/json');
        echo json_encode([
            'accepted' => true,
            'media_content_id' => 'media-demo',
            'job_id' => 'job-demo',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($method === 'POST' && $path === '/api/v1/sponsors/assign') {
        $payload = $_POST;
        if (isset($payload['target_regions']) && is_string($payload['target_regions'])) {
            $payload['target_regions'] = json_decode($payload['target_regions'], true) ?: [];
        }
        if (isset($payload['target_parts']) && is_string($payload['target_parts'])) {
            $payload['target_parts'] = json_decode($payload['target_parts'], true) ?: [];
        }

        header('Content-Type: application/json');
        http_response_code(201);
        echo json_encode([
            'sponsor_id' => 'sponsor-demo-' . substr(md5((string) ($payload['sponsor_name'] ?? $payload['name'] ?? 'demo')), 0, 8),
            'sponsor_name' => (string) ($payload['sponsor_name'] ?? $payload['name'] ?? 'Demo Sponsor'),
            'placement_type' => (string) ($payload['placement_type'] ?? 'intro'),
            'is_global' => filter_var($payload['is_global'] ?? 'false', FILTER_VALIDATE_BOOL),
            'content_type' => (string) ($payload['content_type'] ?? 'news'),
            'status' => 'active',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($path === '/api/v1/plans' && $method === 'GET') {
        header('Content-Type: application/json');
        echo json_encode([
            'plans' => [
                [
                    'id' => 'plan-demo-1',
                    'region_code' => 'akdeniz',
                    'region_name' => 'Akdeniz',
                    'station_name' => 'Adana FM',
                    'station_city_name' => 'Adana',
                    'part_code' => 'news',
                    'slot_time' => '08:00',
                    'plan_date' => date('Y-m-d'),
                    'content_title' => 'Sabah Haber Bülteni',
                    'status' => 'published',
                    'is_global' => false,
                    'notes' => 'Demo plan',
                ],
            ],
            'calendar' => [
                [
                    'slot_time' => '08:00',
                    'status' => 'success',
                    'items' => [
                        [
                            'id' => 'plan-demo-1',
                            'region_code' => 'akdeniz',
                            'region_name' => 'Akdeniz',
                            'station_name' => 'Adana FM',
                            'part_code' => 'news',
                            'content_title' => 'Sabah Haber Bülteni',
                            'status' => 'published',
                        ],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($path === '/api/v1/plans' && $method === 'POST') {
        header('Content-Type: application/json');
        http_response_code(201);
        echo json_encode([
            'code' => 0,
            'result' => [
                'id' => 'plan-demo-' . substr(md5((string) microtime(true)), 0, 8),
            ],
            'message' => 'Success',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($path === '/api/v1/plans' && $method === 'POST') {
        header('Content-Type: application/json');
        http_response_code(201);
        echo json_encode([
            'code' => 0,
            'result' => ['id' => 'plan-demo-' . substr(md5((string) microtime(true)), 0, 8)],
            'message' => 'Success',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if (preg_match('#^/api/v1/plans/([^/]+)$#', $path, $matches) && $method === 'PATCH') {
        header('Content-Type: application/json');
        echo json_encode([
            'code' => 0,
            'result' => ['id' => $matches[1], 'updated' => true],
            'message' => 'Success',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($path === '/api/v1/users' && $method === 'GET') {
        header('Content-Type: application/json');
        echo json_encode([
            [
                'id' => 'user-demo-1',
                'username' => 'admin',
                'real_name' => 'Admin',
                'roles' => ['super'],
                'is_active' => true,
                'last_login_at' => date('c'),
            ],
            [
                'id' => 'user-demo-2',
                'username' => 'editor',
                'real_name' => 'Editor',
                'roles' => ['radio_manager'],
                'is_active' => true,
                'last_login_at' => null,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if (preg_match('#^/api/v1/users/([^/]+)/roles$#', $path, $matches) && $method === 'PATCH') {
        $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
        header('Content-Type: application/json');
        echo json_encode([
            'code' => 0,
            'result' => [
                'id' => $matches[1],
                'roles' => $payload['roles'] ?? ['super'],
            ],
            'message' => 'Success',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if (preg_match('#^/api/v1/users/([^/]+)/active$#', $path, $matches) && $method === 'PATCH') {
        $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
        header('Content-Type: application/json');
        echo json_encode([
            'code' => 0,
            'result' => [
                'id' => $matches[1],
                'is_active' => filter_var($payload['is_active'] ?? true, FILTER_VALIDATE_BOOL),
            ],
            'message' => 'Success',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($path === '/api/v1/audit/logs' && $method === 'GET') {
        if (isset($_GET['export']) && (string) $_GET['export'] === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="audit-logs.csv"');
            echo "created_at,actor_username,action,entity_type,entity_id,correlation_id,payload\n";
            echo '"' . date('c') . '","admin","login","user","user-demo-1","corr-demo-1","{\"roles\":[\"super\"]}"' . "\n";
            return;
        }

        header('Content-Type: application/json');
        echo json_encode([
            [
                'actor_username' => 'admin',
                'action' => 'login',
                'entity_type' => 'user',
                'entity_id' => 'user-demo-1',
                'payload' => ['roles' => ['super']],
                'created_at' => date('c'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'Not Found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$pdo = PdoFactory::fromEnv();
$storage = MinioStorage::fromEnv();

$stationRepository = new StationRepository($pdo);
$tokenRepository = new ApiTokenRepository($pdo);
$mediaRepository = new MediaContentRepository($pdo);
$sponsorRepository = new SponsorAdRepository($pdo);
$jobRepository = new JobRepository($pdo);
$regionRepository = new RegionRepository($pdo);
$planRepository = new ContentPlanRepository($pdo);
$auditLogRepository = new AuditLogRepository($pdo);
$userRepository = new UserRepository($pdo);
$adminSessionRepository = new AdminSessionRepository($pdo);
$matrixRepository = new MatrixRepository($pdo, $mediaRepository, $sponsorRepository);

$adminAuthenticator = new AdminAuthenticator($adminSessionRepository);
$authenticator = new TokenAuthenticator($tokenRepository, $stationRepository);
$feedService = new MediaFeedService($stationRepository, $mediaRepository, $sponsorRepository, $storage);
$renderQueue = new RenderQueueService($jobRepository);

$authController = new AuthController($userRepository, $adminSessionRepository, $auditLogRepository);
$feedController = new FeedController($authenticator, $feedService, $auditLogRepository);
$mediaController = new MediaController($adminAuthenticator, $mediaRepository, $renderQueue, $storage, $regionRepository, $auditLogRepository);
$matrixController = new MatrixController($adminAuthenticator, $matrixRepository, $regionRepository, $stationRepository, $feedService, $auditLogRepository);
$stationController = new StationController($adminAuthenticator, $stationRepository, $tokenRepository, $regionRepository, $auditLogRepository);
$planningController = new PlanningController($adminAuthenticator, $planRepository, $auditLogRepository, $regionRepository);
$accessController = new AccessController($adminAuthenticator, $userRepository, $auditLogRepository);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Allow HttpOnly cookie auth: promote the session cookie to a Bearer header so
// every endpoint that reads Authorization works without the token living in JS.
if (empty($_SERVER['HTTP_AUTHORIZATION']) && !empty($_COOKIE['radio_session'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $_COOKIE['radio_session'];
}

try {
    if ($method === 'POST' && $path === '/api/v1/media/upload') {
        $mediaController->upload();
        return;
    }

    if ($method === 'POST' && $path === '/api/v1/auth/logout') {
        $authController->logout();
        return;
    }

    if ($method === 'POST' && $path === '/api/v1/sponsors/upload') {
        $mediaController->uploadSponsorAsset();
        return;
    }

    if ($method === 'POST' && $path === '/api/v1/auth/login') {
        $authController->login();
        return;
    }

    if ($method === 'GET' && ($path === '/api/v1/user/info' || $path === '/api/v1/auth/user')) {
        $authController->userInfo();
        return;
    }

    if ($method === 'GET' && $path === '/api/v1/regions') {
        $matrixController->regions();
        return;
    }

    if ($method === 'GET' && $path === '/api/v1/plans') {
        $planningController->index();
        return;
    }

    if ($method === 'POST' && $path === '/api/v1/plans') {
        $planningController->store();
        return;
    }

    if ($method === 'PATCH' && preg_match('#^/api/v1/plans/([^/]+)$#', $path, $matches)) {
        $planningController->update($matches[1]);
        return;
    }

    if ($method === 'GET' && $path === '/api/v1/stations') {
        $stationController->index();
        return;
    }

    if ($method === 'POST' && $path === '/api/v1/stations') {
        $stationController->store();
        return;
    }

    if ($method === 'PATCH' && preg_match('#^/api/v1/stations/([^/]+)$#', $path, $matches)) {
        $stationController->update($matches[1]);
        return;
    }

    if ($method === 'DELETE' && preg_match('#^/api/v1/stations/([^/]+)$#', $path, $matches)) {
        $stationController->destroy($matches[1]);
        return;
    }

    if ($method === 'PATCH' && preg_match('#^/api/v1/stations/([^/]+)/toggle$#', $path, $matches)) {
        $stationController->toggle($matches[1]);
        return;
    }

    if ($method === 'GET' && $path === '/api/v1/media/matrix') {
        $matrixController->matrix();
        return;
    }

    if ($method === 'GET' && $path === '/api/v1/media/matrix/live') {
        $matrixController->live();
        return;
    }

    if ($method === 'GET' && $path === '/api/v1/sponsors') {
        $matrixController->sponsors();
        return;
    }

    if ($method === 'POST' && $path === '/api/v1/sponsors/assign') {
        $matrixController->assignSponsor();
        return;
    }

    if ($method === 'DELETE' && preg_match('#^/api/v1/sponsors/([^/]+)$#', $path, $matches)) {
        $matrixController->deleteSponsor($matches[1]);
        return;
    }

    if ($method === 'POST' && $path === '/api/v1/media/matrix/refresh') {
        $matrixController->refresh();
        return;
    }

    if ($method === 'POST' && preg_match('#^/api/v1/stations/([^/]+)/token$#', $path, $matches)) {
        $stationController->generateToken($matches[1]);
        return;
    }

    if ($method === 'GET' && $path === '/api/v1/users') {
        $accessController->users();
        return;
    }

    if ($method === 'PATCH' && preg_match('#^/api/v1/users/([^/]+)/roles$#', $path, $matches)) {
        $accessController->updateRoles($matches[1]);
        return;
    }

    if ($method === 'PATCH' && preg_match('#^/api/v1/users/([^/]+)/active$#', $path, $matches)) {
        $accessController->toggleActive($matches[1]);
        return;
    }

    if ($method === 'GET' && $path === '/api/v1/audit/logs') {
        $accessController->auditLogs();
        return;
    }

    if ($method === 'GET' && preg_match('#^/api/v1/feeds/([^/]+)/([^/.]+)\.(json|xml|m3u)$#', $path, $matches)) {
        $feedController->show($matches[1], $matches[2], $matches[3]);
        return;
    }

    if ($method === 'GET' && preg_match('#^/api/v1/streams/([^/]+)/([^/]+)$#', $path, $matches)) {
        $feedController->stream($matches[1], $matches[2]);
        return;
    }

    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not Found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    $debug = filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOL);
    $status = 500;

    if ($exception instanceof RuntimeException) {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'token')) {
            $status = 401;
        } elseif (str_contains($message, 'authorized') || str_contains($message, 'active')) {
            $status = 403;
        } elseif (str_contains($message, 'not found')) {
            $status = 404;
        } elseif (str_contains($message, 'çakış') || str_contains($message, 'conflict')) {
            $status = 409;
        } elseif (str_contains($message, 'required') || str_contains($message, 'gecersiz')) {
            $status = 400;
        }
    }

    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => $status === 500 ? 'Internal Server Error' : $exception->getMessage(),
        'message' => $debug ? $exception->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
