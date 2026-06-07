/**
 * AdCast Pro — k6 Stress Test 1000 VU (Faz CTO-5)
 *
 * 500+ partner radyo / 5000+ kullanıcı senaryosu için.
 *
 * Çalıştırma:
 *   k6 run -e BASE=http://localhost:8080 loadtest/stress-1000.js
 *
 * Önce: bin/seed-load.php ile DB'yi 500 station + 50K plan ile doldurun.
 *
 * Hedefler:
 *   - P95 < 1500 ms (yük altında)
 *   - error rate < %2
 *   - rate limit aşılırsa 429 sayılır (error değil)
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate } from 'k6/metrics';

const BASE = __ENV.BASE || 'http://localhost:8080';
const errorRate = new Rate('non_rate_limit_errors');
const rateLimitHits = new Rate('rate_limit_hits');

export const options = {
  scenarios: {
    stress: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '1m',  target: 100  },  // ramp up
        { duration: '2m',  target: 500  },
        { duration: '3m',  target: 1000 },  // peak
        { duration: '2m',  target: 1000 },  // sustain
        { duration: '1m',  target: 0    },  // ramp down
      ],
      gracefulRampDown: '15s',
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<1500', 'p(99)<3000'],
    non_rate_limit_errors: ['rate<0.02'],
  },
};

// Ziyaretçi davranışı: 80% sadece read, 20% login + browse
export default function () {
  const isAuthFlow = Math.random() < 0.2;

  if (isAuthFlow) {
    // Login flow
    http.post(
      `${BASE}/api/v1/auth/login`,
      JSON.stringify({ username: 'admin', password: '123456' }),
      { headers: { 'Content-Type': 'application/json' }, tags: { name: 'auth' } },
    );
  }

  // Read endpoint mix
  const reads = [
    '/api/v1/regions',
    '/api/v1/stations',
    '/api/v1/media/matrix',
    '/api/v1/traffic/provinces',
    '/api/v1/healthz/deep',
  ];
  const path = reads[Math.floor(Math.random() * reads.length)];
  const r = http.get(`${BASE}${path}`, { tags: { name: path } });

  check(r, {
    'status ok': (x) => x.status >= 200 && x.status < 300,
  });

  if (r.status === 429) {
    rateLimitHits.add(1);
  } else if (r.status >= 500) {
    errorRate.add(1);
  } else {
    errorRate.add(0);
    rateLimitHits.add(0);
  }

  sleep(Math.random() * 2 + 0.5);  // 0.5 — 2.5 sn arası bekle
}

export function handleSummary(data) {
  const m = data.metrics;
  const rateLimited = m.rate_limit_hits ? (m.rate_limit_hits.values.rate * 100).toFixed(2) : '0';
  const realErrors = (m.non_rate_limit_errors?.values.rate * 100).toFixed(2);
  return {
    stdout: `
==== AIRCAST PRO STRESS TEST 1000 VU ====
Süre:           ${data.state.testRunDurationMs}ms
Toplam istek:   ${m.http_reqs.values.count}
Throughput:     ${m.http_reqs.values.rate.toFixed(1)} req/s
P95 latency:    ${m.http_req_duration.values['p(95)'].toFixed(0)}ms
P99 latency:    ${m.http_req_duration.values['p(99)'].toFixed(0)}ms
Rate-limited:   ${rateLimited}%  (429 — beklenir)
Real errors:    ${realErrors}%   (5xx — kritik)
Verdict:        ${realErrors < 2 ? 'PASS' : 'FAIL'}
==========================================
`,
    'stress-1000-summary.json': JSON.stringify(data, null, 2),
  };
}
