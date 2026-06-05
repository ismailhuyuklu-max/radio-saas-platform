import type { RegionCode } from '#/api/modules/radioMedia';

/** Broadcast content types with colour coding (traffic timeline legend). */
export interface ContentType {
  key: string;
  label: string;
  color: string;
}

export const CONTENT_TYPES: ContentType[] = [
  { key: 'news', label: 'Haber', color: '#3b82f6' },
  { key: 'sports', label: 'Spor', color: '#22c55e' },
  { key: 'economy', label: 'Ekonomi', color: '#f59e0b' },
  { key: 'weather', label: 'Hava Durumu', color: '#06b6d4' },
  { key: 'sponsor', label: 'Sponsor', color: '#e11d48' },
  { key: 'ad', label: 'Reklam', color: '#a855f7' },
  { key: 'psa', label: 'Kamu Spotu', color: '#14b8a6' },
  { key: 'live', label: 'Canlı Yayın', color: '#ef4444' },
  { key: 'special', label: 'Özel Program', color: '#8b5cf6' },
  { key: 'emergency', label: 'Acil Yayın', color: '#dc2626' },
];

export function contentType(key: string): ContentType {
  return CONTENT_TYPES.find((c) => c.key === key) ?? { key, label: key, color: '#64748b' };
}

/** Turkey's 81 provinces mapped to their geographic region. */
export interface Province {
  name: string;
  region: RegionCode;
}

export const PROVINCES: Province[] = [
  // Marmara (11)
  { name: 'İstanbul', region: 'marmara' }, { name: 'Balıkesir', region: 'marmara' },
  { name: 'Bursa', region: 'marmara' }, { name: 'Çanakkale', region: 'marmara' },
  { name: 'Edirne', region: 'marmara' }, { name: 'Kırklareli', region: 'marmara' },
  { name: 'Kocaeli', region: 'marmara' }, { name: 'Sakarya', region: 'marmara' },
  { name: 'Tekirdağ', region: 'marmara' }, { name: 'Yalova', region: 'marmara' },
  { name: 'Bilecik', region: 'marmara' },
  // Ege (8)
  { name: 'İzmir', region: 'ege' }, { name: 'Aydın', region: 'ege' },
  { name: 'Denizli', region: 'ege' }, { name: 'Muğla', region: 'ege' },
  { name: 'Manisa', region: 'ege' }, { name: 'Afyonkarahisar', region: 'ege' },
  { name: 'Kütahya', region: 'ege' }, { name: 'Uşak', region: 'ege' },
  // Akdeniz (8)
  { name: 'Antalya', region: 'akdeniz' }, { name: 'Adana', region: 'akdeniz' },
  { name: 'Mersin', region: 'akdeniz' }, { name: 'Hatay', region: 'akdeniz' },
  { name: 'Isparta', region: 'akdeniz' }, { name: 'Burdur', region: 'akdeniz' },
  { name: 'Osmaniye', region: 'akdeniz' }, { name: 'Kahramanmaraş', region: 'akdeniz' },
  // İç Anadolu (13)
  { name: 'Ankara', region: 'ic-anadolu' }, { name: 'Konya', region: 'ic-anadolu' },
  { name: 'Kayseri', region: 'ic-anadolu' }, { name: 'Eskişehir', region: 'ic-anadolu' },
  { name: 'Sivas', region: 'ic-anadolu' }, { name: 'Yozgat', region: 'ic-anadolu' },
  { name: 'Aksaray', region: 'ic-anadolu' }, { name: 'Karaman', region: 'ic-anadolu' },
  { name: 'Kırıkkale', region: 'ic-anadolu' }, { name: 'Kırşehir', region: 'ic-anadolu' },
  { name: 'Nevşehir', region: 'ic-anadolu' }, { name: 'Niğde', region: 'ic-anadolu' },
  { name: 'Çankırı', region: 'ic-anadolu' },
  // Karadeniz (18)
  { name: 'Samsun', region: 'karadeniz' }, { name: 'Trabzon', region: 'karadeniz' },
  { name: 'Ordu', region: 'karadeniz' }, { name: 'Giresun', region: 'karadeniz' },
  { name: 'Rize', region: 'karadeniz' }, { name: 'Artvin', region: 'karadeniz' },
  { name: 'Gümüşhane', region: 'karadeniz' }, { name: 'Bayburt', region: 'karadeniz' },
  { name: 'Bartın', region: 'karadeniz' }, { name: 'Bolu', region: 'karadeniz' },
  { name: 'Çorum', region: 'karadeniz' }, { name: 'Düzce', region: 'karadeniz' },
  { name: 'Karabük', region: 'karadeniz' }, { name: 'Kastamonu', region: 'karadeniz' },
  { name: 'Sinop', region: 'karadeniz' }, { name: 'Tokat', region: 'karadeniz' },
  { name: 'Amasya', region: 'karadeniz' }, { name: 'Zonguldak', region: 'karadeniz' },
  // Doğu Anadolu (14)
  { name: 'Erzurum', region: 'dogu-anadolu' }, { name: 'Erzincan', region: 'dogu-anadolu' },
  { name: 'Ağrı', region: 'dogu-anadolu' }, { name: 'Ardahan', region: 'dogu-anadolu' },
  { name: 'Bingöl', region: 'dogu-anadolu' }, { name: 'Bitlis', region: 'dogu-anadolu' },
  { name: 'Elazığ', region: 'dogu-anadolu' }, { name: 'Hakkâri', region: 'dogu-anadolu' },
  { name: 'Iğdır', region: 'dogu-anadolu' }, { name: 'Kars', region: 'dogu-anadolu' },
  { name: 'Malatya', region: 'dogu-anadolu' }, { name: 'Muş', region: 'dogu-anadolu' },
  { name: 'Tunceli', region: 'dogu-anadolu' }, { name: 'Van', region: 'dogu-anadolu' },
  // Güneydoğu Anadolu (9)
  { name: 'Gaziantep', region: 'guneydogu-anadolu' }, { name: 'Diyarbakır', region: 'guneydogu-anadolu' },
  { name: 'Şanlıurfa', region: 'guneydogu-anadolu' }, { name: 'Mardin', region: 'guneydogu-anadolu' },
  { name: 'Batman', region: 'guneydogu-anadolu' }, { name: 'Siirt', region: 'guneydogu-anadolu' },
  { name: 'Şırnak', region: 'guneydogu-anadolu' }, { name: 'Adıyaman', region: 'guneydogu-anadolu' },
  { name: 'Kilis', region: 'guneydogu-anadolu' },
];

/** Unique region codes covered by a set of province names. */
export function provincesToRegions(names: string[]): RegionCode[] {
  const set = new Set<RegionCode>();
  for (const n of names) {
    const p = PROVINCES.find((x) => x.name === n);
    if (p) set.add(p.region);
  }
  return [...set];
}

export interface QuickTemplate {
  key: string;
  label: string;
  icon: string;
  slots: Array<{ slot_time: string; part_code: string; content_title: string; status: string }>;
}

const NEWS_SLOTS = ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00'];

export const TEMPLATES: QuickTemplate[] = [
  { key: 'sabah', label: 'Sabah Haber Kuşağı', icon: '🌅', slots: [{ slot_time: '08:00', part_code: 'news', content_title: 'Sabah Haber Bülteni', status: 'published' }] },
  { key: 'ogle', label: 'Öğle Haber Kuşağı', icon: '☀️', slots: [{ slot_time: '12:00', part_code: 'news', content_title: 'Öğle Haber Bülteni', status: 'published' }] },
  { key: 'aksam', label: 'Akşam Haber Kuşağı', icon: '🌆', slots: [{ slot_time: '18:00', part_code: 'news', content_title: 'Akşam Haber Bülteni', status: 'published' }] },
  { key: 'tamgun', label: 'Tam Gün Haber (08–20)', icon: '📰', slots: NEWS_SLOTS.map((t) => ({ slot_time: t, part_code: 'news', content_title: `${t} Haber Bülteni`, status: 'published' })) },
  { key: 'spor', label: 'Spor Kuşağı', icon: '⚽', slots: [{ slot_time: '10:00', part_code: 'sports', content_title: 'Spor Haberleri', status: 'published' }] },
  { key: 'ekonomi', label: 'Ekonomi Kuşağı', icon: '💹', slots: [{ slot_time: '09:00', part_code: 'economy', content_title: 'Ekonomi Haberleri', status: 'published' }] },
  { key: 'hava', label: 'Hava Durumu', icon: '🌤️', slots: [{ slot_time: '07:00', part_code: 'weather', content_title: 'Hava Durumu', status: 'published' }] },
  { key: 'ulusal-reklam', label: 'Ulusal Reklam Bloğu', icon: '📢', slots: [{ slot_time: '08:30', part_code: 'ad', content_title: 'Ulusal Reklam Bloğu', status: 'published' }] },
  { key: 'kamu', label: 'Kamu Spotu', icon: '📣', slots: [{ slot_time: '11:00', part_code: 'psa', content_title: 'Kamu Spotu', status: 'published' }] },
];
