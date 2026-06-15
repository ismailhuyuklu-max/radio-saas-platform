/**
 * Destek Paneli — yayinci (sync client) destek talepleri (admin).
 * Backend: /api/v1/support/requests
 */
import { requestClient } from '#/api/request';
import { sendApiRequest } from '#/api/modules/radioMedia';

export type SupportStatus = 'new' | 'in_progress' | 'resolved' | 'closed';

export const SUPPORT_STATUS_LABELS: Record<SupportStatus, string> = {
  new: 'Yeni',
  in_progress: 'İşleme Alındı',
  resolved: 'Çözüldü',
  closed: 'Kapatıldı',
};

export interface SupportTicket {
  id: string;
  station_id: string;
  radio_name: string | null;
  frequency: string | null;
  region: string | null;
  city: string | null;
  full_name: string;
  phone: string;
  email: string;
  message: string;
  status: SupportStatus;
  admin_note: string | null;
  created_at: string;
  updated_at: string;
}

interface Envelope<T> {
  code: number;
  result: T;
  message?: string;
}

export interface SupportFilters {
  status?: SupportStatus;
  keyword?: string;
}

/** Tum destek taleplerini listele (duruma/anahtar kelimeye gore filtreli). */
export async function getSupportRequests(filters: SupportFilters = {}): Promise<SupportTicket[]> {
  const params: Record<string, unknown> = {};
  if (filters.status) params.status = filters.status;
  if (filters.keyword) params.keyword = filters.keyword;
  const r = await requestClient.get<Envelope<SupportTicket[]> | SupportTicket[]>(
    '/support/requests',
    { params },
  );
  if (Array.isArray(r)) return r;
  return (r as Envelope<SupportTicket[]>)?.result ?? [];
}

/** Tek talep detayi. */
export async function getSupportRequest(id: string): Promise<SupportTicket> {
  const r = await requestClient.get<Envelope<SupportTicket> | SupportTicket>(
    `/support/requests/${id}`,
  );
  return (r as Envelope<SupportTicket>)?.result ?? (r as SupportTicket);
}

/** Durum ve/veya admin notu guncelle (PATCH). */
export async function updateSupportRequest(
  id: string,
  payload: { status?: SupportStatus; admin_note?: string },
): Promise<SupportTicket> {
  const r = await sendApiRequest<Envelope<SupportTicket> | SupportTicket>(
    'PATCH',
    `/support/requests/${id}`,
    payload,
  );
  return (r as Envelope<SupportTicket>)?.result ?? (r as SupportTicket);
}
