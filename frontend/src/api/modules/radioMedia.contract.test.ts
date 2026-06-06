/**
 * Faz H2-5 — API kontrakt testleri.
 *
 * Backend bazı endpoint'ler tarihsel olarak düz dizi/obje döndürür, H2-2
 * sonrası unified `{code, result, message}` zarfı kullanır. Frontend her
 * iki şekli de DOĞRU şekilde unwrap etmeli — bu testler kontratı kilitler.
 *
 * Stratejim: requestClient.get'i mock'la, çağrıyı iki kez yap (legacy +
 * envelope), her ikisinin de eşit sonuç verdiğini doğrula.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';

import { requestClient } from '#/api/request';

vi.mock('#/api/request', () => ({
  requestClient: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

import {
  getAuditLogs,
  getMatrixStatus,
  getSponsors,
  getStationGroups,
  getStations,
} from './radioMedia';

const mockGet = vi.mocked(requestClient.get);

describe('API kontrakt — zarflı / zarfsız backend yanıtı (Faz H2-2)', () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  describe('getStations', () => {
    it('düz dizi yanıtını olduğu gibi alır (legacy)', async () => {
      mockGet.mockResolvedValueOnce([
        { id: 'a', name: 'Akdeniz FM', slug: 'a' },
        { id: 'b', name: 'Ege FM', slug: 'b' },
      ]);
      const result = await getStations();
      expect(result).toHaveLength(2);
      expect(result[0]?.name).toBe('Akdeniz FM');
    });

    it('zarflı yanıtın `result`\'unu çıkarır (post-H2-2)', async () => {
      mockGet.mockResolvedValueOnce({
        code: 0,
        result: [{ id: 'c', name: 'Karadeniz FM', slug: 'c' }],
        message: 'Success',
      });
      const result = await getStations();
      expect(result).toHaveLength(1);
      expect(result[0]?.name).toBe('Karadeniz FM');
    });
  });

  describe('getMatrixStatus', () => {
    it('zarfsız nesne yanıtı olduğu gibi alır', async () => {
      mockGet.mockResolvedValueOnce({ regions: [{ code: 'marmara' }] });
      const result = await getMatrixStatus();
      expect((result as unknown as { regions: unknown[] }).regions).toHaveLength(1);
    });

    it('zarflı yanıtın result\'unu çıkarır', async () => {
      mockGet.mockResolvedValueOnce({
        code: 0,
        result: { regions: [{ code: 'ege' }, { code: 'akdeniz' }] },
        message: 'Success',
      });
      const result = await getMatrixStatus();
      expect((result as unknown as { regions: unknown[] }).regions).toHaveLength(2);
    });
  });

  describe('getSponsors', () => {
    it('zarfsız dizi yanıtı (legacy)', async () => {
      mockGet.mockResolvedValueOnce([{ id: 's1', sponsor_name: 'Onatça' }]);
      const result = await getSponsors();
      expect(result).toHaveLength(1);
    });
    it('zarflı dizi yanıtı', async () => {
      mockGet.mockResolvedValueOnce({ code: 0, result: [{ id: 's2', sponsor_name: 'Koç' }] });
      const result = await getSponsors();
      expect(result).toHaveLength(1);
      expect(result[0]?.sponsor_name).toBe('Koç');
    });
  });

  describe('getStationGroups', () => {
    it('zarfsız {groups:[]} (legacy)', async () => {
      mockGet.mockResolvedValueOnce({ groups: [{ id: 'g1', name: 'Premium' }] });
      const { groups } = await getStationGroups();
      expect(groups).toHaveLength(1);
    });
    it('zarflı {code,result:{groups:[]}}', async () => {
      mockGet.mockResolvedValueOnce({
        code: 0,
        result: { groups: [{ id: 'g2', name: 'Standart' }] },
      });
      const { groups } = await getStationGroups();
      expect(groups).toHaveLength(1);
      expect(groups[0]?.name).toBe('Standart');
    });
    it('beklenmedik HTML body (string) → boş dizi', async () => {
      mockGet.mockResolvedValueOnce('<html>500 Internal Server Error</html>');
      const { groups } = await getStationGroups();
      expect(groups).toEqual([]);
    });
  });

  describe('getAuditLogs', () => {
    it('zarfsız düz dizi (legacy)', async () => {
      mockGet.mockResolvedValueOnce([
        { id: '1', actor_username: 'admin', action: 'login', entity_type: 'user', created_at: '' },
      ]);
      const result = await getAuditLogs();
      expect(result).toHaveLength(1);
    });
    it('zarfsız {logs:[]}', async () => {
      mockGet.mockResolvedValueOnce({
        logs: [
          { id: '2', actor_username: 'admin', action: 'logout', entity_type: 'user', created_at: '' },
        ],
      });
      const result = await getAuditLogs();
      expect(result).toHaveLength(1);
      expect(result[0]?.action).toBe('logout');
    });
    it('zarflı {code,result:{logs:[]}}', async () => {
      mockGet.mockResolvedValueOnce({
        code: 0,
        result: {
          logs: [
            { id: '3', actor_username: 'admin', action: 'error', entity_type: 'request', created_at: '' },
          ],
        },
      });
      const result = await getAuditLogs();
      expect(result).toHaveLength(1);
      expect(result[0]?.action).toBe('error');
    });
    it('HTML hata gövdesi → boş dizi (NOC tipi regresyona karşı)', async () => {
      mockGet.mockResolvedValueOnce('<br/><b>Fatal error</b>: PDOException');
      const result = await getAuditLogs();
      expect(result).toEqual([]);
    });
  });
});
