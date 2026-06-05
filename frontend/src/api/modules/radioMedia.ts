import { notifyUnauthorized } from '@vben/request';

import { requestClient } from '#/api/request';

export type RegionCode =
  | 'marmara'
  | 'ege'
  | 'akdeniz'
  | 'karadeniz'
  | 'ic-anadolu'
  | 'dogu-anadolu'
  | 'guneydogu-anadolu';

export type PartCode = 'news' | 'sports' | 'economy' | 'weather';
export type FeedFormat = 'json' | 'xml' | 'm3u';
export type RenderPlacement = 'pre_roll' | 'post_roll';
export type MediaRenderState = 'queued' | 'rendering' | 'rendered' | 'failed' | 'raw';
export type SponsorStatus = 'active' | 'draft';
export type StationStatus = 'active' | 'paused' | 'archived';
export type UploadMime = 'audio/mpeg' | 'audio/mp3' | 'video/mp4' | string;

export interface ApiEnvelope<T> {
  code?: number;
  data: T;
  message?: string;
  error?: string;
}

export interface MatrixCellStatus {
  status: 'success' | 'warning' | 'danger';
  updated_at: string | null;
}

export interface MatrixRegionPayload {
  region: RegionCode;
  categories: Partial<Record<PartCode, MatrixCellStatus>>;
}

export interface MatrixCellSource {
  regionCode: RegionCode;
  partCode: PartCode;
  title?: string;
  stationSlug?: string;
  status?: MediaRenderState;
  renderState?: MediaRenderState;
  updatedAt?: string | null;
  renderedGeneratedAt?: string | null;
  hasSponsor?: boolean;
  streamMime?: string;
  streamUrl?: string;
}

export interface MatrixCellViewModel {
  regionCode: RegionCode;
  partCode: PartCode;
  status: 'success' | 'warning' | 'danger';
  updatedAt: string | null;
  title?: string;
  stationSlug?: string;
  hasSponsor?: boolean;
  streamMime?: string;
  streamUrl?: string;
}

export interface StationItem {
  id: string;
  name: string;
  slug: string;
  region_code: RegionCode;
  region_name: string;
  city_name?: string;
  status: StationStatus;
  is_active?: boolean;
  station_token?: string;
  stream_token?: string;
}

export interface StationSavePayload {
  id?: string;
  name: string;
  slug?: string;
  region_code: RegionCode;
  city_name: string;
  status?: StationStatus;
  is_active?: boolean;
  stream_token?: string;
}

export interface SponsorPayload {
  id?: string;
  name: string;
  placement: RenderPlacement;
  placement_type?: 'intro' | 'outro';
  is_global?: boolean;
  content_type?: PartCode;
  asset_bucket?: string;
  asset_key?: string;
  asset_mime?: string;
  asset_duration_ms?: number;
  target_regions: RegionCode[];
  target_parts: PartCode[];
  priority?: number;
}

export interface GenerateTokenResponse {
  station_id: string;
  station_token: string;
  stream_token?: string;
}

export interface UploadMediaResponse {
  accepted: boolean;
  media_content_id: string;
  job_id: string;
}

export interface UploadSponsorAssetResponse {
  asset_bucket: string;
  asset_key: string;
  asset_mime: string;
}

export interface FeedResponse {
  station: {
    id: string;
    name: string;
    slug: string;
    region_code: RegionCode;
    region_name: string;
  };
  media: {
    id: string;
    part_code: PartCode;
    title: string;
    render_state: MediaRenderState;
  };
  sponsor?: {
    id: string;
    sponsor_name: string;
    placement: RenderPlacement;
  } | null;
  stream: {
    bucket: string;
    key: string;
    mime: string;
    download_url: string;
    public_url: string;
  };
}

export interface SaveSponsorResponse {
  sponsor_id: string;
  job_id?: string;
}

export interface SponsorListItem {
  id: string;
  sponsor_name: string;
  region_code: RegionCode;
  region_name: string;
  part_code: PartCode;
  content_type: PartCode;
  placement: RenderPlacement;
  placement_type: 'intro' | 'outro';
  is_global: boolean;
  asset_bucket: string;
  asset_key: string;
  asset_mime: string;
  asset_duration_ms: number;
  priority: number;
  is_active: boolean;
}

export interface MatrixTimeSlot {
  time: string;
  part_code: PartCode;
  part_label: string;
  status: 'success' | 'warning' | 'danger';
  station_count: number;
  station_names: string[];
  feed_urls: string[];
  updated_at: string | null;
}

export interface MatrixLiveStation {
  id: string;
  name: string;
  slug: string;
  city_name: string;
  region_code: RegionCode;
  region_name: string;
  is_active: boolean;
  status: StationStatus;
  stream_token: string;
  feed_url: string;
  feed_mime: string;
  updated_at: string | null;
}

export interface MatrixLiveResponse {
  region: {
    code: RegionCode;
    name: string;
  };
  slots: MatrixTimeSlot[];
  active_stations: MatrixLiveStation[];
}

export type PlanStatus = 'draft' | 'published' | 'running' | 'paused' | 'archived';

export interface ContentPlanItem {
  id: string;
  region_code: RegionCode;
  region_name: string;
  station_name?: string | null;
  station_city_name?: string | null;
  part_code: PartCode;
  slot_time: string;
  plan_date: string;
  content_title: string;
  content_kind: PartCode;
  status: PlanStatus;
  is_global: boolean;
  notes?: string | null;
}

export interface CalendarSlotItem {
  slot_time: string;
  status: 'success' | 'warning' | 'danger';
  items: ContentPlanItem[];
}

export interface PlanningResponse {
  plans: ContentPlanItem[];
  calendar: CalendarSlotItem[];
  filters: {
    date: string;
    region?: RegionCode | null;
    status?: PlanStatus | null;
  };
}

export interface PlanningSavePayload {
  id?: string;
  region_id: string;
  station_id?: string | null;
  part_code: PartCode;
  slot_time: string;
  plan_date: string;
  content_title: string;
  content_kind?: PartCode;
  status?: PlanStatus;
  is_global?: boolean;
  target_regions?: RegionCode[];
  target_parts?: PartCode[];
  notes?: string | null;
  created_by?: string;
}

export interface AuditLogItem {
  id: string;
  actor_username: string;
  action: string;
  entity_type: string;
  entity_id?: string | null;
  payload: Record<string, unknown>;
  created_at: string;
}

export interface AuditLogFilters {
  limit?: number;
  actor_username?: string;
  action?: string;
  entity_type?: string;
  entity_id?: string;
  date_from?: string;
  date_to?: string;
}

export interface UserAdminItem {
  id: string;
  username: string;
  real_name: string;
  roles: string[];
  is_active: boolean;
  last_login_at?: string | null;
}

export interface GetStationsParams {
  keyword?: string;
  region?: RegionCode;
  status?: StationStatus;
}

export interface ChunkUploadPayload {
  file: File;
  regionId: string;
  partCode: PartCode;
  title: string;
  chunkIndex?: number;
  chunkTotal?: number;
  uploadId?: string;
  durationMs?: number;
}

export interface MatrixGridCell extends MatrixCellViewModel {
  regionLabel: string;
  partLabel: string;
  key: string;
}

const API_BASE_URL = import.meta.env.VITE_GLOB_API_URL || import.meta.env.VITE_API_URL || '/api/v1';

function resolveApiUrl(path: string) {
  const base = new URL(
    API_BASE_URL.endsWith('/') ? API_BASE_URL : `${API_BASE_URL}/`,
    window.location.origin,
  );

  return new URL(path.replace(/^\//, ''), base).toString();
}

async function sendApiRequest<T>(method: 'PATCH' | 'DELETE', path: string, data?: unknown): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Accept-Language': navigator.language || 'tr-TR',
  };

  const token = localStorage.getItem('accessToken') || localStorage.getItem('token');
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  let body: BodyInit | undefined;
  if (data !== undefined) {
    if (data instanceof FormData || typeof data === 'string' || data instanceof Blob) {
      body = data;
    } else {
      body = JSON.stringify(data);
      headers['Content-Type'] = 'application/json';
    }
  }

  const response = await fetch(resolveApiUrl(path), {
    method,
    headers,
    body,
  });

  if (!response.ok) {
    if (response.status === 401) {
      notifyUnauthorized();
    }
    const errorText = await response.text().catch(() => '');
    throw new Error(errorText || `Request failed with status ${response.status}`);
  }

  const contentType = response.headers.get('content-type') ?? '';
  if (contentType.includes('application/json')) {
    return (await response.json()) as T;
  }

  return (await response.text()) as T;
}

export const REGION_LABELS: Record<RegionCode, string> = {
  marmara: 'Marmara',
  ege: 'Ege',
  akdeniz: 'Akdeniz',
  karadeniz: 'Karadeniz',
  'ic-anadolu': 'İç Anadolu',
  'dogu-anadolu': 'Doğu Anadolu',
  'guneydogu-anadolu': 'Güneydoğu Anadolu',
};

export const PART_LABELS: Record<PartCode, string> = {
  news: 'Haber',
  sports: 'Spor',
  economy: 'Ekonomi',
  weather: 'Hava Durumu',
};

export const REGION_LIST: RegionCode[] = [
  'marmara',
  'ege',
  'akdeniz',
  'karadeniz',
  'ic-anadolu',
  'dogu-anadolu',
  'guneydogu-anadolu',
];

export const PART_LIST: PartCode[] = [
  'news',
  'sports',
  'economy',
  'weather',
];

export function createMatrixKey(regionCode: RegionCode, partCode: PartCode) {
  return `${regionCode}:${partCode}`;
}

export type MatrixPayloadInput =
  | Array<MatrixRegionPayload | MatrixCellSource>
  | Record<string, Record<string, MatrixCellStatus | MatrixCellSource | null | undefined>>;

export function normalizeMatrixPayload(payload: MatrixPayloadInput): MatrixGridCell[] {
  const seededCells = REGION_LIST.flatMap((regionCode) =>
    PART_LIST.map((partCode) => ({
      key: createMatrixKey(regionCode, partCode),
      regionCode,
      regionLabel: REGION_LABELS[regionCode],
      partCode,
      partLabel: PART_LABELS[partCode],
      status: 'danger' as const,
      updatedAt: null,
      title: undefined,
      stationSlug: undefined,
      hasSponsor: false,
    })),
  );

  const lookup = new Map<string, MatrixGridCell>(
    seededCells.map((cell) => [cell.key, cell]),
  );

  const normalizeStatus = (
    cell: MatrixCellStatus | MatrixCellSource | null | undefined,
  ): MatrixCellStatus => {
    if (!cell) {
      return { status: 'danger', updated_at: null };
    }

    if ('updated_at' in cell) {
      return cell;
    }

    const status =
      cell.renderState === 'rendered'
        ? 'success'
        : cell.renderState === 'queued'
          ? 'warning'
          : 'danger';

    return {
      status,
      updated_at: cell.updatedAt ?? cell.renderedGeneratedAt ?? null,
    };
  };

  const entries = Array.isArray(payload)
    ? payload
    : Object.entries(payload).map(([regionCode, categories]) => ({
        region: regionCode as RegionCode,
        categories: Object.fromEntries(
          Object.entries(categories).map(([partCode, cell]) => [
            partCode,
            normalizeStatus(cell as MatrixCellStatus | MatrixCellSource | null | undefined),
          ]),
        ) as Partial<Record<PartCode, MatrixCellStatus>>,
      }));

  entries.forEach((row) => {
    if ('region' in row) {
      Object.entries(row.categories).forEach(([partCode, cell]) => {
        if (!cell) return;
        const normalizedPart = partCode as PartCode;
        const key = createMatrixKey(row.region, normalizedPart);
        const regionLabel = REGION_LABELS[row.region];
        const partLabel = PART_LABELS[normalizedPart];
        const updatedAt = cell.updated_at ?? null;
        const hasSponsor = cell.status !== 'danger';
        lookup.set(key, {
          key,
          regionCode: row.region,
          regionLabel,
          partCode: normalizedPart,
          partLabel,
          status: cell.status,
          updatedAt,
          title: undefined,
          stationSlug: undefined,
          hasSponsor,
        });
      });
      return;
    }

    const key = createMatrixKey(row.regionCode, row.partCode);
    lookup.set(key, {
      key,
      regionCode: row.regionCode,
      regionLabel: REGION_LABELS[row.regionCode],
      partCode: row.partCode,
      partLabel: PART_LABELS[row.partCode],
      status:
        row.renderState === 'rendered'
          ? 'success'
          : row.renderState === 'queued'
            ? 'warning'
            : 'danger',
      updatedAt: row.renderedGeneratedAt ?? row.updatedAt ?? null,
      title: row.title,
      stationSlug: row.stationSlug,
      hasSponsor: Boolean(row.hasSponsor),
      streamMime: row.streamMime,
      streamUrl: row.streamUrl,
    });
  });

  return [...lookup.values()];
}

/**
 * Bölge/icerik matris durumunu cek.
 */
export function getMatrixStatus() {
  return requestClient.get<MatrixPayloadInput>('/media/matrix');
}

export function getMatrixLive(region: RegionCode) {
  return requestClient.get<MatrixLiveResponse>('/media/matrix/live', {
    params: { region },
  });
}

/**
 * Yeni medya dosyası yükle.
 */
export function uploadMedia(payload: ChunkUploadPayload) {
  const formData = new FormData();

  formData.append('file', payload.file);
  formData.append('region_id', payload.regionId);
  formData.append('part_code', payload.partCode);
  formData.append('title', payload.title);

  if (typeof payload.durationMs === 'number') {
    formData.append('duration_ms', String(payload.durationMs));
  }

  if (typeof payload.chunkIndex === 'number') {
    formData.append('chunk_index', String(payload.chunkIndex));
  }

  if (typeof payload.chunkTotal === 'number') {
    formData.append('chunk_total', String(payload.chunkTotal));
  }

  if (payload.uploadId) {
    formData.append('upload_id', payload.uploadId);
  }

  return requestClient.post<UploadMediaResponse>('/media/upload', formData);
}

export function uploadSponsorAsset(file: File) {
  const formData = new FormData();
  formData.append('file', file);
  return requestClient.post<UploadSponsorAssetResponse>('/sponsors/upload', formData);
}

/**
 * Chunk upload destekli alternatif endpoint.
 * Backend tarafında chunk aggregation eklendiğinde doğrudan kullanılabilir.
 */
/**
 * İstasyon listesini çek.
 */
export function getStations(params?: GetStationsParams) {
  const query: Record<string, string> = {};

  if (params?.keyword) {
    query.keyword = params.keyword;
  }

  if (params?.region) {
    query.region = params.region;
  }

  if (params?.status) {
    query.status = params.status;
  }

  return requestClient.get<StationItem[]>('/stations', { params: query });
}

export function getFeed(stationSlug: string, partCode: PartCode, format: FeedFormat = 'json') {
  return requestClient.get<FeedResponse>(`/feeds/${stationSlug}/${partCode}.${format}`);
}

/**
 * Sponsor kaydet ve render kuyruğunu tetikle.
 */
export function saveSponsor(payload: SponsorPayload) {
  const formData = new FormData();

  formData.append('name', payload.name);
  formData.append('sponsor_name', payload.name);
  formData.append('placement', payload.placement);
  formData.append(
    'placement_type',
    payload.placement_type ?? (payload.placement === 'post_roll' ? 'outro' : 'intro'),
  );
  formData.append('is_global', String(payload.is_global ?? payload.target_regions.length >= REGION_LIST.length));
  formData.append('content_type', payload.content_type ?? payload.target_parts[0] ?? 'news');
  formData.append('target_regions', JSON.stringify(payload.target_regions));
  formData.append('target_parts', JSON.stringify(payload.target_parts));
  if (payload.target_regions[0]) {
    formData.append('region_id', payload.target_regions[0]);
  }
  if (payload.target_parts[0]) {
    formData.append('part_code', payload.target_parts[0]);
  }

  if (payload.asset_bucket) formData.append('asset_bucket', payload.asset_bucket);
  if (payload.asset_key) formData.append('asset_key', payload.asset_key);
  if (payload.asset_mime) formData.append('asset_mime', payload.asset_mime);
  if (typeof payload.asset_duration_ms === 'number') {
    formData.append('asset_duration_ms', String(payload.asset_duration_ms));
  }
  if (typeof payload.priority === 'number') {
    formData.append('priority', String(payload.priority));
  }

  return requestClient.post<SaveSponsorResponse>('/sponsors/assign', formData);
}

/**
 * Kaydedilmiş sponsor reklamlarını veritabanından listele.
 */
export function getSponsors() {
  return requestClient.get<SponsorListItem[]>('/sponsors');
}

/**
 * Bir sponsor reklamını sil.
 */
export function deleteSponsor(id: string) {
  return sendApiRequest<{ deleted: boolean; sponsor_id: string }>('DELETE', `/sponsors/${id}`);
}

/**
 * İstasyona yeni otomasyon tokeni üret.
 */
export function generateToken(stationId: string) {
  return requestClient.post<GenerateTokenResponse>(`/stations/${stationId}/token`, null);
}

/**
 * Tek bir istasyon için stream linki üret.
 */
export function buildSoleaLink(regionCode: RegionCode, category: PartCode, token: string) {
  const baseUrl = 'https://api.domain.com/v1/stream';
  return `${baseUrl}/${regionCode}/${category}?token=${encodeURIComponent(token)}`;
}

export function createStation(payload: StationSavePayload) {
  return requestClient.post<StationItem>('/stations', payload);
}

export function updateStation(stationId: string, payload: StationSavePayload) {
  return sendApiRequest<StationItem>('PATCH', `/stations/${stationId}`, payload);
}

export function deleteStation(stationId: string) {
  return sendApiRequest<{ deleted: boolean; station_id: string }>('DELETE', `/stations/${stationId}`);
}

export function toggleStationStatus(stationId: string, isActive: boolean) {
  return sendApiRequest<StationItem>('PATCH', `/stations/${stationId}/toggle`, {
    is_active: isActive,
  });
}

export function getPlanning(filters?: { date?: string; region?: RegionCode; status?: PlanStatus }) {
  const query: Record<string, string> = {};
  if (filters?.date) query.date = filters.date;
  if (filters?.region) query.region = filters.region;
  if (filters?.status) query.status = filters.status;

  return requestClient.get<PlanningResponse>('/plans', { params: query });
}

export function savePlanning(payload: PlanningSavePayload) {
  return requestClient.post('/plans', payload);
}

export function updatePlanning(planId: string, payload: PlanningSavePayload) {
  return sendApiRequest('PATCH', `/plans/${planId}`, payload);
}

export function getAuditLogs(filters: AuditLogFilters = {}) {
  const params: Record<string, string> = {};

  if (typeof filters.limit === 'number') {
    params.limit = String(filters.limit);
  }
  if (filters.actor_username) {
    params.actor_username = filters.actor_username;
  }
  if (filters.action) {
    params.action = filters.action;
  }
  if (filters.entity_type) {
    params.entity_type = filters.entity_type;
  }
  if (filters.entity_id) {
    params.entity_id = filters.entity_id;
  }
  if (filters.date_from) {
    params.date_from = filters.date_from;
  }
  if (filters.date_to) {
    params.date_to = filters.date_to;
  }

  return requestClient.get<AuditLogItem[]>('/audit/logs', { params });
}

export async function exportAuditLogsCsv(filters: AuditLogFilters = {}) {
  const query = new URLSearchParams();
  query.set('export', 'csv');

  if (typeof filters.limit === 'number') {
    query.set('limit', String(filters.limit));
  }
  if (filters.actor_username) query.set('actor_username', filters.actor_username);
  if (filters.action) query.set('action', filters.action);
  if (filters.entity_type) query.set('entity_type', filters.entity_type);
  if (filters.entity_id) query.set('entity_id', filters.entity_id);
  if (filters.date_from) query.set('date_from', filters.date_from);
  if (filters.date_to) query.set('date_to', filters.date_to);

  const csvToken = localStorage.getItem('accessToken') || localStorage.getItem('token');
  const response = await fetch(resolveApiUrl(`/audit/logs?${query.toString()}`), {
    // Same-origin request: the HttpOnly session cookie is sent automatically.
    // Only add a Bearer header if a legacy token is present (never an empty one).
    headers: {
      Accept: 'text/csv',
      ...(csvToken ? { Authorization: `Bearer ${csvToken}` } : {}),
    },
  });

  if (!response.ok) {
    throw new Error(`CSV export failed with status ${response.status}`);
  }

  return response.text();
}

export function getUsers() {
  return requestClient.get<UserAdminItem[]>('/users');
}

export function updateUserRoles(userId: string, roles: string[]) {
  return sendApiRequest('PATCH', `/users/${userId}/roles`, { roles });
}

export function toggleUserActive(userId: string, isActive: boolean) {
  return sendApiRequest('PATCH', `/users/${userId}/active`, { is_active: isActive });
}
