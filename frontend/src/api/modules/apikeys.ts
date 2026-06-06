import { requestClient } from '#/api/request';

export interface PartnerApiKey {
  id: string;
  station_id: string;
  name: string;
  key_prefix: string;
  scopes: string[];
  last_used_at: string | null;
  last_used_ip: string | null;
  revoked_at: string | null;
  created_at: string;
}

export function listPartnerApiKeys() {
  return requestClient.get<{ code: number; result: { keys: PartnerApiKey[] } }>(
    '/portal/api-keys',
  );
}

export function issuePartnerApiKey(name: string, scopes: string[] = []) {
  return requestClient.post<{
    code: number;
    result: { record: PartnerApiKey; one_time_key: string };
  }>('/portal/api-keys', { name, scopes });
}

export function revokePartnerApiKey(id: string) {
  return requestClient.delete<{ code: number; result: { revoked: boolean } }>(
    `/portal/api-keys/${id}`,
  );
}
