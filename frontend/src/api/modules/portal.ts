/**
 * Aircast Radio Partner Portal — tenant-scoped client.
 *
 * Every call here hits a /portal/* endpoint that the backend resolves to the
 * caller's own station. Cross-tenant lookups are rejected server-side.
 */
import { requestClient } from '#/api/request';

export type PortalPurpose =
  | 'news'
  | 'sports'
  | 'economy'
  | 'weather'
  | 'sponsor'
  | 'ad'
  | 'special'
  | 'emergency';

export interface PortalCard {
  station_id: string;
  name: string;
  slug: string;
  logo_url: string | null;
  frequency: string | null;
  company_name: string | null;
  contact_name: string | null;
  phone: string | null;
  email: string | null;
  website: string | null;
  region_code: string | null;
  region_name: string | null;
  city_name: string | null;
  status: string | null;
  is_active: boolean;
  last_login_at: string | null;
  last_broadcast_at: string | null;
}

export interface PortalLink {
  purpose: PortalPurpose;
  token: string;
  urls: { json: string; xml: string; m3u: string; pls: string };
}

export interface PortalPlan {
  id: string;
  slot_time: string;
  part_code: string;
  content_title: string;
  status: string;
  region_name?: string;
  plan_date?: string;
}

export interface PortalMediaItem {
  id: string;
  title: string;
  part_code: string;
  region_name?: string;
  region_code?: string;
  render_state: string;
  source_mime?: string;
  source_duration_ms?: number;
  published_at?: string | null;
}

export interface PortalActivity {
  id: string;
  actor_username: string;
  action: string;
  entity_type: string;
  entity_id: string | null;
  created_at: string;
  payload?: Record<string, unknown> | null;
}

export const PURPOSE_LABELS: Record<PortalPurpose, string> = {
  news: 'Haber',
  sports: 'Spor',
  economy: 'Ekonomi',
  weather: 'Hava Durumu',
  sponsor: 'Sponsor',
  ad: 'Reklam',
  special: 'Özel Yayın',
  emergency: 'Acil Yayın',
};

export const PURPOSE_ICONS: Record<PortalPurpose, string> = {
  news: '📰',
  sports: '⚽',
  economy: '💹',
  weather: '🌤️',
  sponsor: '🎯',
  ad: '📢',
  special: '🎙️',
  emergency: '🚨',
};

export function getPortalMe() {
  return requestClient.get<{ code: number; result: PortalCard }>('/portal/me');
}

export function getPortalLinks() {
  return requestClient.get<{ code: number; result: { links: PortalLink[] } }>('/portal/links');
}

export function getPortalFeeds(date?: string) {
  const params: Record<string, string> = {};
  if (date) params.date = date;
  return requestClient.get<{ code: number; result: { plans: PortalPlan[] } }>('/portal/feeds', {
    params,
  });
}

export function getPortalMedia() {
  return requestClient.get<{ code: number; result: { items: PortalMediaItem[] } }>(
    '/portal/media',
  );
}

export function getPortalActivity() {
  return requestClient.get<{ code: number; result: { logs: PortalActivity[] } }>(
    '/portal/activity',
  );
}

// --- Admin-side partner management ---------------------------------------

export interface PartnerCredentials {
  username: string;
  one_time_password: string;
  user_id: string;
}

/** Provision a partner user for an unprovisioned station. */
export function provisionPartner(stationId: string) {
  return requestClient.post<{ code: number; result: PartnerCredentials }>(
    `/stations/${stationId}/provision`,
  );
}

/** Rotate the partner's password — returns the one-shot plaintext. */
export function rotatePartnerPassword(stationId: string) {
  return requestClient.post<{ code: number; result: { one_time_password: string } }>(
    `/stations/${stationId}/rotate-password`,
  );
}

/** Rotate all 8 stream tokens — any cached partner URL is invalidated. */
export function rotatePartnerTokens(stationId: string) {
  return requestClient.post<{
    code: number;
    result: { tokens: Record<string, string> };
  }>(`/stations/${stationId}/rotate-tokens`);
}
