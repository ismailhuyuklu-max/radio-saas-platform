import { notifyUnauthorized, readCsrfToken } from '@vben/request';

import { requestClient } from '#/api/request';
import { normalizeList, unwrap } from '#/utils/api-envelope';

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
  /** Bound partner-radio user id (Faz 12+). Falsy when not provisioned yet. */
  user_id?: string | null;
  /** Faz 22: national-access partner sees content across every region. */
  national_access?: boolean;
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
  /** Faz 22: ulusal yetkili radyo bayrağı. */
  national_access?: boolean;
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
  starts_at?: string | null;
  ends_at?: string | null;
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
  starts_at?: string | null;
  ends_at?: string | null;
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
  ip_address?: string | null;
  user_agent?: string | null;
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

  // CSRF double-submit for state-changing requests (cookie-auth).
  const csrf = readCsrfToken();
  if (csrf) {
    headers['X-CSRF-Token'] = csrf;
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
    credentials: 'include',
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
  // Faz H2-2: zarflı/zarfsız ikisini de destekle (geriye uyumlu)
  return requestClient
    .get<MatrixPayloadInput | { code: number; result: MatrixPayloadInput }>('/media/matrix')
    .then((r) => unwrap<MatrixPayloadInput>(r));
}

export function getMatrixLive(region: RegionCode) {
  return requestClient
    .get<MatrixLiveResponse | { code: number; result: MatrixLiveResponse }>('/media/matrix/live', {
      params: { region },
    })
    .then((r) => unwrap<MatrixLiveResponse>(r));
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

  // Faz H2-2: backend artık unified zarf döndürüyor; unwrap geriye uyumlu —
  // zarflıysa result, değilse ham yanıt.
  return requestClient
    .get<StationItem[] | { code: number; result: StationItem[] }>('/stations', { params: query })
    .then((r) => unwrap<StationItem[]>(r));
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
  if (payload.starts_at) {
    formData.append('starts_at', payload.starts_at);
  }
  if (payload.ends_at) {
    formData.append('ends_at', payload.ends_at);
  }

  return requestClient.post<SaveSponsorResponse>('/sponsors/assign', formData);
}

/**
 * Kaydedilmiş sponsor reklamlarını veritabanından listele.
 */
export function getSponsors() {
  // Faz H2-2: backend zarflıysa unwrap, zarfsızsa olduğu gibi
  return requestClient
    .get<SponsorListItem[] | { code: number; result: SponsorListItem[] }>('/sponsors')
    .then((r) => unwrap<SponsorListItem[]>(r));
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

/**
 * Create a station. On success the backend auto-provisions a partner user
 * + 8 stream tokens unless {auto_provision: false} is passed, and the
 * response carries the one-shot credentials so the admin can read them
 * back immediately (Faz 18).
 */
export interface CreateStationResult {
  station: StationItem | null;
  partner: {
    username?: string;
    one_time_password?: string;
    user_id?: string;
    error?: string;
  } | null;
  tokens: Record<string, string> | null;
}
export function createStation(payload: StationSavePayload & { auto_provision?: boolean }) {
  return requestClient.post<{ code: number; result: CreateStationResult }>(
    '/stations',
    payload,
  );
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

// --- Faz 5: calendar range feed (weekly / monthly / list) -------------------

export interface PlanRangeResponse {
  plans: ContentPlanItem[];
  counts: Record<string, number>;
  range: { start: string; end: string };
}

export function getPlanRange(filters: { start: string; end: string; region?: RegionCode }) {
  const params: Record<string, string> = { start: filters.start, end: filters.end };
  if (filters.region) params.region = filters.region;
  return requestClient.get<PlanRangeResponse>('/plans/range', { params });
}

// --- Faz 6: reporting breakdowns (il / müşteri) -----------------------------

export interface ProvinceBreakdownRow {
  province: string;
  region_code: string;
  region_name: string;
  plan_count: number;
  campaign_count: number;
}
export interface CustomerBreakdownRow {
  advertiser_name: string;
  status: string;
  budget: number;
  planned_spots: number;
  aired_spots: number;
  impressions: number;
}

// --- Faz 9: radio groups (Radyo Grubu targeting) ----------------------------

export interface StationGroup {
  id: string;
  name: string;
  description?: string | null;
  station_count?: number;
  station_ids: string[];
}

export function getStationGroups() {
  // Faz H2-2: backend artık `{code,result:{groups:[]}}`; legacy `{groups:[]}`'i
  // de destekle. normalizeList ile sıfır-elemana güvenle düş.
  return requestClient
    .get<{ groups: StationGroup[] } | { code: number; result: { groups: StationGroup[] } }>(
      '/traffic/groups',
    )
    .then((r) => ({ groups: normalizeList<StationGroup>(r, 'groups') }));
}
export function createStationGroup(payload: {
  name: string;
  description?: string;
  station_ids: string[];
}) {
  return requestClient.post<{ code: number; result: StationGroup }>('/traffic/groups', payload);
}
export function updateStationGroupMembers(id: string, stationIds: string[]) {
  return requestClient.put<{ code: number; result: { station_ids: string[] } }>(
    `/traffic/groups/${id}/members`,
    { station_ids: stationIds },
  );
}
export function deleteStationGroup(id: string) {
  return requestClient.delete<{ code: number; result: { deleted: boolean } }>(
    `/traffic/groups/${id}`,
  );
}

export function getProvinceBreakdown() {
  return requestClient.get<{ type: string; rows: ProvinceBreakdownRow[]; count: number }>(
    '/reports/breakdown/province',
  );
}
export function getCustomerBreakdown() {
  return requestClient.get<{ type: string; rows: CustomerBreakdownRow[]; count: number }>(
    '/reports/breakdown/customer',
  );
}

export interface BulkPlanPayload {
  target_regions?: string[];
  target_provinces?: string[];
  station_ids?: string[];
  group_ids?: string[];
  campaign_id?: string;
  slots: Array<{ slot_time: string; part_code: string; content_title: string; status: string }>;
  start_date: string;
  repeat_days: number;
}

export interface BulkPlanResult {
  created: number;
  skipped: number;
  total: number;
  conflicts: string[];
}

export function bulkPlan(payload: BulkPlanPayload) {
  return requestClient.post<{ code: number; result: BulkPlanResult }>('/plans/bulk', payload);
}

// --- Faz 4: smart placement + timeline bulk operations ----------------------

export interface PlacementSuggestion {
  slot_time: string;
  part_code: string;
  content_title: string;
  reason: string;
}
export interface PlacementWarning {
  slot_time: string;
  message: string;
}
export interface PlacementResult {
  suggestions: PlacementSuggestion[];
  warnings: PlacementWarning[];
}

export function getPlanSuggestions(filters: { date?: string; region?: RegionCode }) {
  const params: Record<string, string> = {};
  if (filters.date) params.date = filters.date;
  if (filters.region) params.region = filters.region;
  return requestClient.get<{ code: number; result: PlacementResult }>('/plans/suggest', {
    params,
  });
}

/**
 * Pre-flight smart placement: send a candidate slot set, get sponsor/spacing/
 * cap suggestions BEFORE running the bulk planner.
 */
export function previewPlanSuggestions(slots: Array<{ slot_time: string; part_code: string }>) {
  return requestClient.post<{ code: number; result: PlacementResult }>('/plans/suggest-preview', {
    slots,
  });
}

export function bulkDeletePlans(ids: string[]) {
  return requestClient.post<{ code: number; result: { deleted: number } }>('/plans/bulk-delete', {
    ids,
  });
}

export interface BulkMovePayload {
  ids: string[];
  target_date?: string;
  slot_shift?: number;
  copy?: boolean;
}
export function bulkMovePlans(payload: BulkMovePayload) {
  return requestClient.post<{
    code: number;
    result: { written: number; skipped: number; copy: boolean };
  }>('/plans/bulk-move', payload);
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

  // Faz H2-2: backend artık `{code, result:{logs:[]}}` döner; legacy düz dizi
  // de destekleniyor. normalizeList her iki durumu da AuditLogItem[]'a düşürür.
  return requestClient
    .get<
      | AuditLogItem[]
      | { logs: AuditLogItem[] }
      | { code: number; result: { logs: AuditLogItem[] } }
    >('/audit/logs', { params })
    .then((r) => normalizeList<AuditLogItem>(r, 'logs'));
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
    // The HttpOnly session cookie carries auth (sent cross-origin via credentials).
    // Only add a Bearer header if a legacy token is present (never an empty one).
    credentials: 'include',
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

// --- Faz 4: Ad Traffic / revenue --------------------------------------------

export type PricingModel = 'cpm' | 'cpp' | 'flat';
export type CampaignStatus = 'active' | 'paused' | 'ended' | 'draft';

export interface CampaignMetrics {
  pricing_model: PricingModel;
  total_days: number;
  delivered_days: number;
  projected_spots: number;
  delivered_spots: number;
  projected_impressions: number;
  delivered_impressions: number;
  projected_revenue: number;
  delivered_revenue: number;
  budget_used_pct: number;
  over_budget: boolean;
  reach_per_day: number;
  has_actuals: boolean;
}

/** Reklam Trafik columns: scheduled vs aired vs missed vs remaining spots. */
export interface CampaignTraffic {
  planned: number;
  aired: number;
  missed: number;
  remaining: number;
  past_due: number;
  completion_rate: number;
}

export interface AdCampaign {
  id: string;
  advertiser_name: string;
  sponsor_ad_id?: string | null;
  pricing_model: PricingModel;
  rate: number;
  budget: number;
  currency: string;
  spots_per_day: number;
  target_regions: RegionCode[];
  target_parts: PartCode[];
  starts_at: string;
  ends_at: string;
  status: CampaignStatus;
  metrics?: CampaignMetrics;
  traffic?: CampaignTraffic;
}

export interface AdTrafficSummary {
  total_projected_revenue: number;
  total_delivered_revenue: number;
  total_projected_impressions: number;
  total_budget: number;
  budget_used_pct: number;
  active_campaigns: number;
  campaign_count: number;
  avg_cpm: number;
  revenue_by_region: Record<string, number>;
  revenue_by_model: Record<string, number>;
}

export interface AdTrafficColumnsSummary {
  planned: number;
  aired: number;
  missed: number;
  remaining: number;
  completion_rate: number;
}

export interface AdTrafficResponse {
  campaigns: AdCampaign[];
  summary: AdTrafficSummary;
  traffic_summary?: AdTrafficColumnsSummary;
  region_reach: Record<string, number>;
}

export interface AdCampaignPayload {
  advertiser_name: string;
  pricing_model: PricingModel;
  rate: number;
  budget: number;
  spots_per_day: number;
  target_regions: RegionCode[];
  target_parts: PartCode[];
  starts_at: string;
  ends_at: string;
  status: CampaignStatus;
  currency?: string;
}

export function getAdTraffic() {
  return requestClient.get<AdTrafficResponse>('/ad-campaigns');
}

export function createAdCampaign(payload: AdCampaignPayload) {
  return requestClient.post('/ad-campaigns', payload);
}

export function updateAdCampaign(id: string, payload: Partial<AdCampaignPayload>) {
  return sendApiRequest('PATCH', `/ad-campaigns/${id}`, payload);
}

export function deleteAdCampaign(id: string) {
  return sendApiRequest<{ deleted: boolean; campaign_id: string }>('DELETE', `/ad-campaigns/${id}`);
}

export function recordAdAiring(
  id: string,
  payload: { region_code: RegionCode; part_code?: PartCode; impressions?: number },
) {
  return requestClient.post(`/ad-campaigns/${id}/airings`, payload);
}

// --- Faz 5+6: NOC monitoring -------------------------------------------------

export type ServiceStatus = 'up' | 'degraded' | 'down';

export interface ServiceHealth {
  key: string;
  label: string;
  status: ServiceStatus;
  detail: string;
  latency_ms: number | null;
  meta?: Record<string, number>;
}

export interface HealthResponse {
  overall: ServiceStatus;
  services: ServiceHealth[];
  checked_at: string;
}

export interface MetricGauge {
  used_pct: number | null;
  tone: string;
}

export interface MetricsResponse {
  cpu: { usage_pct: number | null; cores: number; tone: string };
  memory: { used_pct: number | null; total_kb: number; used_kb: number; tone: string };
  disk: {
    used_pct: number;
    total_bytes: number;
    used_bytes: number;
    free_bytes: number;
    tone: string;
  };
  load: { '1m': number; '5m': number; '15m': number };
  sampled_at: string;
}

// --- Media library / player --------------------------------------------------

export interface MediaLibraryItem {
  id: string;
  kind: 'content' | 'sponsor';
  title: string;
  part_code: string;
  slot_time?: string | null;
  region_code: string;
  region_name: string;
  render_state?: string;
  placement_type?: string;
  is_global?: boolean;
  url: string;
}

export interface MediaLibraryResponse {
  content: MediaLibraryItem[];
  sponsors: MediaLibraryItem[];
}

export function getMediaLibrary() {
  return requestClient.get<MediaLibraryResponse>('/media-library');
}

export function getHealth() {
  return requestClient.get<HealthResponse>('/monitoring/health');
}

export function getMetrics() {
  return requestClient.get<MetricsResponse>('/monitoring/metrics');
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
