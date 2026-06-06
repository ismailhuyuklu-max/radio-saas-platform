import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('#/api/modules/radioMedia', async (importOriginal) => {
  const actual = await importOriginal<typeof import('#/api/modules/radioMedia')>();
  return {
    ...actual,
    getHealth: vi.fn().mockResolvedValue({
      overall: 'up',
      checked_at: '2026-06-05T12:00:00+03:00',
      services: [
        { key: 'database', label: 'PostgreSQL', status: 'up', detail: '1 ms', latency_ms: 1 },
        { key: 'storage', label: 'MinIO', status: 'up', detail: '2 ms', latency_ms: 2 },
        { key: 'queue', label: 'Kuyruk', status: 'degraded', detail: '1 hatalı', latency_ms: null },
        { key: 'worker', label: 'Worker', status: 'up', detail: 'Boşta', latency_ms: null },
      ],
    }),
    getMetrics: vi.fn().mockResolvedValue({
      cpu: { usage_pct: 12.5, cores: 8, tone: 'ok' },
      memory: { used_pct: 40, total_kb: 16_000_000, used_kb: 6_400_000, tone: 'ok' },
      disk: { used_pct: 92, total_bytes: 1_000_000, used_bytes: 920_000, free_bytes: 80_000, tone: 'critical' },
      load: { '1m': 0.5, '5m': 0.4, '15m': 0.3 },
      sampled_at: '2026-06-05T12:00:00+03:00',
    }),
    // Faz 21+H1 — NOC artık olay akışı için audit/logs da çekiyor;
    // mock yoksa requestClient gerçekten çağrılıyor, test fail oluyor.
    getAuditLogs: vi.fn().mockResolvedValue([]),
  };
});

import Noc from './index.vue';

describe('NOC view', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('renders 4 service cards and 3 resource gauges', async () => {
    const wrapper = mount(Noc);
    await flushPromises();
    expect(wrapper.findAll('.noc__svc')).toHaveLength(4);
    expect(wrapper.findAll('.noc__ring')).toHaveLength(3);
    wrapper.unmount();
  });

  it('reflects the overall status', async () => {
    const wrapper = mount(Noc);
    await flushPromises();
    expect(wrapper.find('.noc__overall').text()).toContain('Çalışıyor');
    wrapper.unmount();
  });

  it('applies the critical tone to a high-usage gauge', async () => {
    const wrapper = mount(Noc);
    await flushPromises();
    expect(wrapper.find('.noc__ring.tone-critical').exists()).toBe(true);
    wrapper.unmount();
  });
});
