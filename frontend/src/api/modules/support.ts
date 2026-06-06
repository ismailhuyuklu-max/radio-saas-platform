/**
 * Partner-side support tickets API (tenant-scoped on the server).
 */
import { requestClient } from '#/api/request';

export type SupportCategory = 'technical' | 'broadcast' | 'ad' | 'news' | 'general';
export type SupportStatus = 'open' | 'in_progress' | 'resolved' | 'closed';

export interface SupportTicket {
  id: string;
  station_id: string;
  category: SupportCategory;
  subject: string;
  body: string;
  status: SupportStatus;
  created_at: string;
  updated_at: string;
}

export interface SupportMessage {
  id: string;
  ticket_id: string;
  author_type: 'radio' | 'admin';
  author_id?: string | null;
  body: string;
  created_at: string;
}

export const CATEGORY_LABELS: Record<SupportCategory, string> = {
  technical: 'Teknik Destek',
  broadcast: 'Yayın Sorunu',
  ad: 'Reklam Sorunu',
  news: 'Haber Sorunu',
  general: 'Genel Talep',
};

export const STATUS_LABELS: Record<SupportStatus, string> = {
  open: 'Açık',
  in_progress: 'İnceleniyor',
  resolved: 'Çözüldü',
  closed: 'Kapalı',
};

export function listSupportTickets() {
  return requestClient.get<{ code: number; result: { tickets: SupportTicket[] } }>(
    '/portal/support',
  );
}
export function createSupportTicket(payload: {
  category: SupportCategory;
  subject: string;
  body: string;
}) {
  return requestClient.post<{ code: number; result: SupportTicket }>('/portal/support', payload);
}
export function getSupportTicket(id: string) {
  return requestClient.get<{
    code: number;
    result: { ticket: SupportTicket; messages: SupportMessage[] };
  }>(`/portal/support/${id}`);
}
export function replySupportTicket(id: string, body: string) {
  return requestClient.post<{ code: number; result: SupportMessage }>(
    `/portal/support/${id}/message`,
    { body },
  );
}
