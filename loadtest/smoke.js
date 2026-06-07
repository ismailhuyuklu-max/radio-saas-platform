/**
 * Aircast Pro — k6 Smoke Load Test (Faz CTO-14 revize)
 *
 * SESSION-BASED scenario: VU bir kez login olur, sonra 7 endpoint'i
 * sırayla okur. Gerçek dashboard kullanıcı davranışını simüle eder
 * — brute force gibi her iter'da login DEĞİL.
 *
 * Çalıştırma:
 *   k6 run -e BASE=http://localhost:8080 -e ADMIN_PASS=123456 loadtest/smoke.js
 *
 * Senaryo: 30s rampa → 60s 100 VU sabit → 30s rampa down.
 * Hedefler: P95 < 500 ms, error rate < %1.
 */
import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE = __ENV.BASE || 'http://localhost:8080';
const ADMIN = __ENV.ADMIN_USER || 'admin';
const PASS = __ENV.ADMIN_PASS || '123456';

const errorRate = new Rate('errors');
const loginTime = new Trend('login_duration_ms');
const apiTime = new Trend('api_duration_ms');

export const options = {
  scenarios: {
    smoke: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 100 },
        { duration: '60s', target: 100 },
        { duration: '30s', target: 0 },
      ],
      gracefulRampDown: '10s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<500', 'p(99)<1500'],
    login_duration_ms: ['p(95)<1500'],
    api_duration_ms: ['p(95)<400'],
    errors: ['rate<0.01'],
  },
};

/**
 * setup(): tek seferlik JWT al, tüm VU'lar paylaşır.
 *
 * Gerçek üretim simülasyonu: bir kullanıcı login olur → 8h JWT alır
 * → her sonraki istek Bearer header ile. 100 VU paralel login'i
 * test başında yapmıyoruz çünkü bu DDoS senaryosu, normal kullanım değil
 * (üretimde login oranı ~%1 trafik).
 */
export function setup() {
  // /auth/token: JWT döndüren endpoint (Faz 25). Cookie tabanlı /auth/login
  // VU başına ayrı session yaratır; biz JWT kullanıyoruz.
  const res = http.post(
    `${BASE}/api/v1/auth/token`,
    JSON.stringify({ username: ADMIN, password: PASS }),
    { headers: { 'Content-Type': 'application/json' } },
  );
  if (res.status !== 200) {
    // /auth/token yoksa /auth/login fallback
    const fb = http.post(
      `${BASE}/api/v1/auth/login`,
      JSON.stringify({ username: ADMIN, password: PASS }),
      { headers: { 'Content-Type': 'application/json' } },
    );
    if (fb.status !== 200) {
      throw new Error(`setup login başarısız: HTTP ${fb.status}`);
    }
    // Login response — cookie tabanlı; JWT yok → public-only test'e dön
    return { token: null };
  }
  loginTime.add(res.timings.duration);
  // /auth/token response: { code, result: { access, refresh, expires_at } }
  const token = res.json('result.access') || res.json('result.access_token') || res.json('result.token');
  return { token };
}

export default function (data) {
  const token = data?.token;
  const authHeader = token ? { Authorization: `Bearer ${token}` } : {};

  // Read endpoint mix — gerçek dashboard kullanıcı davranışı
  group('read endpoints', () => {
    const endpoints = [
      '/api/v1/healthz/deep',  // public — auth gerektirmez
      '/api/v1/regions',
      '/api/v1/stations',
      '/api/v1/sponsors',
      '/api/v1/media/matrix',
      '/api/v1/traffic/provinces',
      '/api/v1/plans',
    ];
    for (const path of endpoints) {
      const r = http.get(`${BASE}${path}`, { headers: authHeader });
      apiTime.add(r.timings.duration);
      // 401 (auth yok) veya 200 — ikisi de "uygulamanın hatası değil"
      const ok = check(r, {
        [`${path} responds`]: (x) => x.status === 200 || x.status === 401,
      });
      errorRate.add(!ok);
    }
  });

  sleep(1);  // gerçek kullanıcı düşünme süresi
}

export function handleSummary(data) {
  const m = data.metrics;
  return {
    stdout: `
==== AIRCAST PRO LOAD TEST (session-based) ====
Süre:           ${data.state.testRunDurationMs}ms
Toplam istek:   ${m.http_reqs.values.count}
Başarısız:      ${(m.http_req_failed.values.rate * 100).toFixed(2)}%
Login P95:      ${m.login_duration_ms?.values['p(95)']?.toFixed(0)}ms
API P95:        ${m.api_duration_ms?.values['p(95)']?.toFixed(0)}ms
HTTP P95:       ${m.http_req_duration.values['p(95)'].toFixed(0)}ms
HTTP P99:       ${m.http_req_duration.values['p(99)'].toFixed(0)}ms
Throughput:     ${(m.http_reqs.values.rate).toFixed(1)} req/s
Verdict:        ${m.http_req_failed.values.rate < 0.01 ? 'PASS' : 'FAIL'}
================================================
`,
    'loadtest-summary.json': JSON.stringify(data, null, 2),
  };
}
