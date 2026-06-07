/**
 * Aircast Pro — k6 Smoke Load Test (Faz CTO-5)
 *
 * Çalıştırma:
 *   k6 run -e BASE=http://localhost:8080 -e ADMIN_PASS=123456 loadtest/smoke.js
 *
 * Senaryo: 30 sn rampa → 60 sn 100 VU sabit → 30 sn rampa down.
 * Ölçüler: P95 < 500 ms, error rate < %1.
 *
 * 1000+ kullanıcı için load-1000.js kullan.
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
    login_duration_ms: ['p(95)<1000'],
    api_duration_ms: ['p(95)<400'],
    errors: ['rate<0.01'],
  },
};

export default function () {
  let token = null;
  let csrf = null;

  group('login', () => {
    const res = http.post(
      `${BASE}/api/v1/auth/login`,
      JSON.stringify({ username: ADMIN, password: PASS }),
      { headers: { 'Content-Type': 'application/json' } },
    );
    loginTime.add(res.timings.duration);
    const ok = check(res, {
      'login 200': (r) => r.status === 200,
      'login token': (r) => r.json('result.userId') !== undefined,
    });
    errorRate.add(!ok);
    if (ok) {
      // Cookie zaten jar'da; CSRF cookie'sini çıkar.
      const setCookie = res.headers['Set-Cookie'] || '';
      const match = /radio_csrf=([^;]+)/.exec(setCookie);
      csrf = match ? match[1] : null;
    }
  });

  group('read endpoints', () => {
    const endpoints = [
      '/api/v1/regions',
      '/api/v1/stations',
      '/api/v1/sponsors',
      '/api/v1/media/matrix',
      '/api/v1/traffic/provinces',
      '/api/v1/plans',
      '/api/v1/healthz/deep',
    ];
    for (const path of endpoints) {
      const r = http.get(`${BASE}${path}`);
      apiTime.add(r.timings.duration);
      const ok = check(r, { [`${path} 200`]: (x) => x.status === 200 });
      errorRate.add(!ok);
    }
  });

  sleep(1);
}

// Test özet — JSON çıktı + console summary
export function handleSummary(data) {
  const m = data.metrics;
  return {
    stdout: `
==== AIRCAST PRO LOAD TEST SONUÇLARI ====
Süre:           ${data.state.testRunDurationMs}ms
Toplam istek:   ${m.http_reqs.values.count}
Başarısız:      ${(m.http_req_failed.values.rate * 100).toFixed(2)}%
Login P95:      ${m.login_duration_ms?.values['p(95)']?.toFixed(0)}ms
API P95:        ${m.api_duration_ms?.values['p(95)']?.toFixed(0)}ms
HTTP P95:       ${m.http_req_duration.values['p(95)'].toFixed(0)}ms
HTTP P99:       ${m.http_req_duration.values['p(99)'].toFixed(0)}ms
Throughput:     ${(m.http_reqs.values.rate).toFixed(1)} req/s
Verdict:        ${m.http_req_failed.values.rate < 0.01 ? 'PASS' : 'FAIL'}
==========================================
`,
    'loadtest-summary.json': JSON.stringify(data, null, 2),
  };
}
