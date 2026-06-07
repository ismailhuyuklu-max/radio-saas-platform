/**
 * AdCast Pro Sync Client — Admin tarafı API.
 *
 * Windows desktop client'ların online/offline durumlarını, son sync zamanını,
 * versiyon ve hata bilgisini admin paneline aktarır. NOC ekranında kullanılır.
 *
 * Backend: GET /api/v1/sync-admin/clients?filter=all|online|offline|error
 */
import { requestClient } from '#/api/request';

export type SyncConnectionStatus = 'online' | 'stale' | 'offline';
export type SyncFilter = 'all' | 'online' | 'offline' | 'error';

export interface SyncClient {
  id: number;
  user_id: number;
  machine_id: string;
  client_version: string;
  os: string;
  last_seen_ip: string | null;
  last_seen_at: string;
  last_sync_at: string | null;
  disk_free_gb: number;
  last_error: string | null;
  last_error_at: string | null;
  connection_status: SyncConnectionStatus;
  username: string;
  radio_id: number | null;
  radio_name: string | null;
  radio_region: string | null;
  radio_province: string | null;
}

export interface SyncStatusCounts {
  online: number;
  stale: number;
  offline: number;
  with_error: number;
}

export interface SyncAdminListResult {
  clients: SyncClient[];
  counts: SyncStatusCounts;
  filter: SyncFilter;
  limit: number;
  offset: number;
}

export const STATUS_LABELS: Record<SyncConnectionStatus, string> = {
  online: 'Çevrimiçi',
  stale: 'Bekliyor',
  offline: 'Çevrimdışı',
};

export const STATUS_COLORS: Record<SyncConnectionStatus, string> = {
  online: 'success',
  stale: 'warning',
  offline: 'default',
};

export function listSyncClients(params: {
  filter?: SyncFilter;
  limit?: number;
  offset?: number;
} = {}): Promise<{ code: number; result: SyncAdminListResult; message: string }> {
  return requestClient.get('/sync-admin/clients', {
    params: {
      filter: params.filter ?? 'all',
      limit: params.limit ?? 200,
      offset: params.offset ?? 0,
    },
  });
}
