/**
 * AdCast Pro Sync Client API — Load Test
 *
 * Faz 3 Aşama 4 — 100/500/1000 eşzamanlı sync client simülasyonu.
 *
 * Çalıştırma:
 *   k6 run --vus 100  --duration 5m loadtest/sync-client-stress.js
 *   k6 run --vus 500  --duration 5m loadtest/sync-client-stress.js
 *   k6 run --vus 1000 --duration 5m loadtest/sync-client-stress.js
 *
 * Senaryo (her VU bir sync client):
 *   1. POST /api/v1/sync/login (her VU bir kez, oturum baştan)
 *   2. Her 60s'de GET /api/v1/sync/manifest (heartbeat-like)
 *   3. Her 60s'de POST /api/v1/sync/heartbeat
 *   4. Manifest'te yeni dosya varsa GET /api/v1/sync/download/{id} (302 follow yok, just headers)
 *
 * Hedef metrikler:
 *   - p95 manifest < 200ms
 *   - p99 manifest < 500ms
 *   - error rate < %1
 *   - login throughput > 50 RPS
 */

import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Trend, Rate } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://178.210.168.74:8080';
const TEST_RADIO_USERNAME_PREFIX = __ENV.USER_PREFIX || 'partner_test_';
const TEST_PASSWORD = __ENV.TEST_PASSWORD || 'partner_test_password';

const manifestLatency = new Trend('sync_manifest_latency_ms');
const loginLatency = new Trend('sync_login_latency_ms');
const heartbeatLatency = new Trend('sync_heartbeat_latency_ms');
const errors = new Rate('sync_errors');

export const options = {
  stages: [
    { duration: '30s', target: __ENV.VUS ? parseInt(__ENV.VUS) : 100 },
    { duration: '4m', target: __ENV.VUS ? parseInt(__ENV.VUS) : 100 },
    { duration: '30s', target: 0 },
  ],
  thresholds: {
    'sync_manifest_latency_ms': ['p(95)<200', 'p(99)<500'],
    'sync_login_latency_ms': ['p(95)<800'],
    'sync_heartbeat_latency_ms': ['p(95)<150'],
    'sync_errors': ['rate<0.01'],
    'http_req_failed': ['rate<0.01'],
  },
};

// Her VU bir radyo simüle eder
export function setup() {
  console.log(`Load test başlıyor: ${__ENV.VUS || 100} VU, ${BASE_URL}`);
}

export default function () {
  const username = `${TEST_RADIO_USERNAME_PREFIX}${__VU}`;
  let accessToken = null;
  let machineId = `vu-${__VU}-${__ITER}`;

  // ---------- 1. LOGIN (her iter ayrı login değil, sadece ilk iter'de) ----------
  if (__ITER === 0) {
    group('login', () => {
      const t0 = Date.now();
      const res = http.post(`${BASE_URL}/api/v1/sync/login`, JSON.stringify({
        username,
        password: TEST_PASSWORD,
        client_version: '1.0.0',
        machine_id: machineId,
      }), {
        headers: { 'Content-Type': 'application/json' },
        tags: { endpoint: 'login' },
      });
      loginLatency.add(Date.now() - t0);
      const ok = check(res, {
        'login 200': (r) => r.status === 200,
        'access_token var': (r) => {
          try { return JSON.parse(r.body).result?.access_token != null; }
          catch { return false; }
        },
      });
      if (ok) {
        accessToken = JSON.parse(res.body).result.access_token;
      } else {
        errors.add(1);
        console.warn(`VU ${__VU} login fail: ${res.status}`);
        return;
      }
    });
  }

  if (!accessToken) return; // login fail

  const authHeaders = {
    'Authorization': `Bearer ${accessToken}`,
    'Content-Type': 'application/json',
  };

  // ---------- 2. MANIFEST ----------
  group('manifest', () => {
    const t0 = Date.now();
    const res = http.get(`${BASE_URL}/api/v1/sync/manifest`, {
      headers: authHeaders,
      tags: { endpoint: 'manifest' },
    });
    manifestLatency.add(Date.now() - t0);
    const ok = check(res, {
      'manifest 200|304': (r) => r.status === 200 || r.status === 304,
    });
    if (!ok) errors.add(1);
  });

  // ---------- 3. HEARTBEAT ----------
  group('heartbeat', () => {
    const t0 = Date.now();
    const res = http.post(`${BASE_URL}/api/v1/sync/heartbeat`, JSON.stringify({
      client_version: '1.0.0',
      os: 'Windows 11 24H2',
      disk_free_gb: 80,
    }), {
      headers: authHeaders,
      tags: { endpoint: 'heartbeat' },
    });
    heartbeatLatency.add(Date.now() - t0);
    const ok = check(res, {
      'heartbeat 200': (r) => r.status === 200,
    });
    if (!ok) errors.add(1);
  });

  // 60s polling intervali (gerçek client davranışı)
  sleep(60);
}
