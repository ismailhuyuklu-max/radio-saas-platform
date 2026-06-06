/**
 * Build the master documentation PDF.
 *
 * Assembles a single HTML document with the 20-chapter master prompt
 * structure, embeds the captured screenshots inline (desktop + tablet +
 * mobile per route), then uses Playwright (system Chrome) to render that
 * HTML to PDF — A4, generated TOC, brand cover, page numbers.
 *
 * Output: Broadcast_Platform_Complete_Master_Documentation.pdf in project root.
 *
 * Run (from frontend/):
 *   node scripts/build-master-pdf.mjs
 */
import { chromium } from 'playwright';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const ROOT = path.resolve(__dirname, '..', '..');
const SHOTS_DIR = path.join(ROOT, 'docs', 'screenshots');
const OUT_PDF = path.join(ROOT, 'Broadcast_Platform_Complete_Master_Documentation.pdf');
const HTML_OUT = path.join(ROOT, 'docs', 'master-documentation.html');

// =============================================================================
// Helpers
// =============================================================================
async function fileToBase64(p) {
  try {
    const buf = await fs.readFile(p);
    return 'data:image/png;base64,' + buf.toString('base64');
  } catch {
    return null;
  }
}

const ROLES = ['admin', 'partner'];

async function loadShots() {
  const map = {};
  for (const role of ROLES) {
    const roleDir = path.join(SHOTS_DIR, role);
    let entries = [];
    try {
      entries = await fs.readdir(roleDir, { withFileTypes: true });
    } catch {
      continue;
    }
    for (const ent of entries) {
      if (!ent.isDirectory()) continue;
      const route = ent.name;
      map[`${role}/${route}`] = {
        desktop: await fileToBase64(path.join(roleDir, route, 'desktop.png')),
        tablet: await fileToBase64(path.join(roleDir, route, 'tablet.png')),
        mobile: await fileToBase64(path.join(roleDir, route, 'mobile.png')),
      };
    }
  }
  return map;
}

// =============================================================================
// Chapter content
// =============================================================================

// Each "screen" block: name, purpose, scenario, roles, workflow, technical,
// data, apis, perf, recommendations — under the master prompt's metadata spec.
const SCREENS = [
  // -------- Admin --------
  {
    role: 'admin', key: '01-login', title: 'Giriş Ekranı',
    purpose: 'Tüm rollerin platforma güvenli giriş yaptığı tek nokta. CSRF + login throttle + isteğe bağlı TOTP MFA ile korunur.',
    scenario: 'Bir radyo yöneticisi sabah operasyonuna başlamak için kullanıcı adı + şifreyle giriş yapar, MFA aktifse 6 haneli kodu girer.',
    roles: 'super, radio_manager, editor, viewer, station_user',
    workflow: '1) Username + Password → POST /auth/login. 2) MFA gerekiyorsa /auth/mfa/verify ile devam. 3) Başarıda HttpOnly radio_session cookie + radio_csrf token döner. 4) Router rolüne göre /portal veya /radio-platform/operations sayfasına yönlendirir.',
    technical: 'Vue 3 + ant-design-vue Form, HttpOnly + SameSite=Lax cookie, login throttle (5/dk username + IP), bcrypt password_verify, TOTP RFC6238.',
    data: 'users, admin_sessions, login_throttle, audit_logs.',
    apis: 'POST /api/v1/auth/login · POST /api/v1/auth/mfa/verify · POST /api/v1/auth/logout · POST /api/v1/auth/token (JWT).',
    perf: 'Bcrypt cost 10 (~70ms). Form render < 50ms. İlk paint < 1s.',
    recommendations: 'WebAuthn (FIDO2) opsiyonu, oturum cihaz listesi, "beni hatırla" tercih ayarı, captcha (3+ başarısızlıkta).',
  },
  {
    role: 'admin', key: '02-operations', title: 'Yayın Merkezi (Broadcast Operations Center)',
    purpose: 'Tüm radyoların anlık yayın durumunu, son haber/sponsor/render olaylarını ve canlı KPI’ları tek ekranda gösteren operasyon kokpiti.',
    scenario: 'Operasyon ekibi sabah panele bakar, hangi bölgenin geri kalmış renderı olduğunu görür, vardiya boyunca canlı veri akışını izler.',
    roles: 'super, radio_manager, editor, viewer.',
    workflow: 'Sayfa açılır → /matrix/live, /monitoring/metrics, /audit/logs?limit endpoint’leri paralel çağrılır → KPI kartları + live ticker + bölge ısı haritası dolar.',
    technical: 'Vue keep-alive, ECharts ısı haritası, dayjs Türkçe lokal, polling 10–30 sn aralık.',
    data: 'media_contents.render_state, stations.status, media_jobs, audit_logs, ad_airings.',
    apis: 'GET /matrix/live · GET /monitoring/metrics · GET /audit/logs · GET /reports/breakdown/province.',
    perf: '50k+ media + 500 station altında dashboard yanıtı < 230ms.',
    recommendations: 'WebSocket push (polling yerine), kullanıcı tanımlı widget seti, alarm eşik özelleştirmesi.',
  },
  {
    role: 'admin', key: '03-dashboard', title: 'Genel Bakış (Dashboard)',
    purpose: 'Platformun stratejik göstergelerini (aktif kampanya, gelir projeksiyonu, render başarı oranı) yöneticilere sunar.',
    scenario: 'Şef editör/ürün yöneticisi günün ilk yarısında yayın hacmini, kampanya başına geliri ve bölgesel kullanım dağılımını inceler.',
    roles: 'super, radio_manager, editor, viewer.',
    workflow: 'Dashboard hooks → ReportService.summary + CampaignRepository.airingTotals + planRepository.calendar → KPI kartlar + ECharts grafikler.',
    technical: 'ECharts 5.6 line/donut/heatmap, formatCurrency/formatCompact, tablet/mobile responsive grid.',
    data: 'ad_campaigns, ad_airings, content_plans, audit_logs.',
    apis: 'GET /reports/breakdown/province · GET /ad-campaigns?limit=500 · GET /plans?date=… · GET /monitoring/metrics.',
    perf: 'İlk render 800-900 ms; ECharts canvas üzerinde sub-200 ms re-render.',
    recommendations: 'Tarih aralığı filtre çubuğu, KPI hedef değer ayarı, “bu hafta vs geçen hafta” farklarının renkli gösterimi.',
  },
  {
    role: 'admin', key: '04-matrix', title: 'Bölgesel Durum Matrisi',
    purpose: 'Bölge × yayın türü kesişiminde canlı render/yayın durumunu tek tabloda gösterir; bir hücreye tıklayarak detayı açar.',
    scenario: 'Yayın yöneticisi 12:00 haber kuşağı öncesi tüm bölgeler için renderları kontrol eder; sarı (warning) bir hücreyi açıp eksiği görür.',
    roles: 'super, radio_manager, editor, viewer.',
    workflow: 'GET /media/matrix → MatrixRepository.compose → cell tıklaması ile drawer açılır → ilgili medya + sponsor detayı görüntülenir.',
    technical: 'CSS grid 7×7 hücre, durum renk paleti (ok/warn/bad/muted), keep-alive cache.',
    data: 'media_contents, sponsor_ads, regions, content_plans.',
    apis: 'GET /media/matrix · GET /media/matrix/live · POST /media/matrix/refresh (manager).',
    perf: '49 hücre canlı çekimde 200-250ms; aggregated SQL ile O(N).',
    recommendations: 'Hücreye sağ tık → tekrar render et / bildirim ata.',
  },
  {
    role: 'admin', key: '05-stations', title: 'İstasyon Yönetimi',
    purpose: 'Tüm partner radyoların CRUD + tek tık Partner Portal (provision/password/token rotate) yönetimi.',
    scenario: 'Yeni partner kayıt geldiğinde admin "Yeni İstasyon" basıp formu doldurur; sistem otomatik kullanıcı + 8 token üretir; one-shot ekran kopyalanır.',
    roles: 'super, radio_manager (write); editor/viewer (read).',
    workflow: 'POST /stations (Faz 18) tek hamlede → partner kullanıcı + 8 token. Portal modali tek seferlik kimliği gösterir, modal kapanınca bellekten silinir.',
    technical: 'Mobile-first kart layout >768px tablo, kombine modal (Provision/Rotate-Password/Rotate-Tokens), 390px tek sütun.',
    data: 'stations (15+ kolon: user_id, frequency, company_name, …, national_access), users, station_stream_tokens.',
    apis: 'GET/POST/PATCH/DELETE /stations · POST /stations/{id}/provision · POST /stations/{id}/rotate-password · POST /stations/{id}/rotate-tokens (opts ip/domain/expires).',
    perf: '500 istasyon listesi 122ms (perf-smoke).',
    recommendations: 'CSV/Excel toplu içe aktarım, harita üzerinde frekans çakışma uyarısı, station etkinlik puanı.',
  },
  {
    role: 'admin', key: '06-sponsors', title: 'Sponsor Yönetimi',
    purpose: 'Sponsor reklam dosyalarının kayıt, render ve bölge/kuşak ataması.',
    scenario: 'Editör "Onatça Motor" sponsorunu spor kuşağı intro placement’ına atar; render kuyruğa düşer; matrix yeşil işaretler.',
    roles: 'super, radio_manager (write); editor (atama); viewer (read).',
    workflow: 'Upload → MinIO raw → render_sponsor_bundle iş kuyruğu → FFmpeg intro/outro → rendered bucket → feed endpoint.',
    technical: 'PostgreSQL ENUM placement_type (intro, outro, ad), is_global flag, region/content_type matrix.',
    data: 'sponsor_ads, media_jobs.',
    apis: 'GET/POST /sponsors · POST /sponsors/assign · DELETE /sponsors/{id} · POST /media/matrix/refresh.',
    perf: 'Render iş süresi 2-6 sn / dosya; eşzamanlı 2 worker.',
    recommendations: 'Sponsor performans skoru (gösterim/CPM), otomatik sıralama (sürekli ödeyen ön planda).',
  },
  {
    role: 'admin', key: '07-traffic-center', title: 'Yayın Trafik Merkezi',
    purpose: 'WideOrbit-sınıfı yayın trafik motoru: Tüm Türkiye / Bölge / İl / Radyo Grubu / Radyo hedeflemesi + kampanya bağı + akıllı yerleştirme önizlemesi.',
    scenario: 'Programcı "Sabah Haber Kuşağı + 2 reklam" şablonunu seçer, "Tüm Türkiye + 7 gün" işaretler, Akıllı Öneriler panelinde sponsor takdimini ekler, tek tıkla 7×7 plan üretir.',
    roles: 'super, radio_manager, editor (plans:write).',
    workflow: '3 adım: 1) Hedef Seçici (5 kapsam) 2) Yayın Kuşakları + şablonlar 3) Tekrar/Tarih + canlı tahmin → /plans/bulk → backend tek transaction.',
    technical: 'TrafficPlanner servisi pure cartesian (MAX_DAYS=31, MAX_PLANS=5000), SmartPlacement pre-flight, debounced öneri çekimi.',
    data: 'content_plans (province, campaign_id, region_id), provinces (81 il), station_groups.',
    apis: 'POST /plans/bulk · POST /plans/suggest-preview · GET /traffic/provinces|stations|groups.',
    perf: 'Tek transaksiyonda 5000 plan ≤ 1.5 sn (PostgreSQL upsert).',
    recommendations: 'Şablon paylaşımı (programcılar arası), versiyonlama, AI-destekli boş kuşak doldurma.',
  },
  {
    role: 'admin', key: '08-timeline', title: 'Zaman Çizelgesi',
    purpose: 'Gantt benzeri saat × bölge ızgarası — tek günün tüm yayınlarını sürükle-bırak ile düzenleme.',
    scenario: 'Operasyon yöneticisi 14:00 haberini 16:00’a sürükler; çakışma motoru anında yeşil/kırmızı geri bildirim verir.',
    roles: 'super, radio_manager, editor.',
    workflow: 'GET /plans/range → ızgara renderı → drag start → drop validate → PATCH /plans/{id} veya POST /plans/bulk-move.',
    technical: 'CSS grid + HTML5 drag-drop, ContentPlanRepository.hasConflict (region+il+date+slot+part).',
    data: 'content_plans, regions.',
    apis: 'GET /plans/range · PATCH /plans/{id} · POST /plans/bulk-move.',
    perf: '7×7 ızgara render 30-40ms.',
    recommendations: 'Tarih aralığı pinch-zoom, snap-to-slot magnet, undo/redo stack.',
  },
  {
    role: 'admin', key: '09-kanban', title: 'Haber Akışı (Kanban)',
    purpose: 'Haberlerin "Taslak → Yayında → Canlı → Arşiv" akışını kart bazlı görselleştirir.',
    scenario: 'Editör masaüstüne metni yapıştırır, kartı "Yayında" sütununa sürükler; render workerına iş düşer.',
    roles: 'editor, radio_manager, super.',
    workflow: 'Kart click → modal → metadata + media upload → status değişiminde audit + matrix tetikleme.',
    technical: 'Vue draggable, 4 status (draft/published/running/archived), persistent column scroll.',
    data: 'content_plans.status, media_contents.',
    apis: 'GET /plans · PATCH /plans/{id} · POST /media/upload.',
    perf: 'Drag güncellemesi 80-120ms.',
    recommendations: 'WIP limiti, mention/yorum, OCR ile gazete kupürü → metin.',
  },
  {
    role: 'admin', key: '10-planning', title: 'Planlama (Günlük/Haftalık/Aylık/Liste)',
    purpose: 'Tek günün takvimi + haftalık 7-sütun + aylık ısı haritası + virtualized liste, hepsi tek sayfada.',
    scenario: 'Programcı aylık ısı haritasında 18 Mayıs’ın koyu kırmızı hücresine tıklar, günlük görünüme drill-in yapar.',
    roles: 'super, radio_manager, editor.',
    workflow: 'View-mode toggle → uygun endpoint (range vs daily) → çoklu seçim toolbar + Akıllı Öneriler modali + bulk-delete/move/copy.',
    technical: 'VirtualList (windowed render), watch + reload pattern, ısı haritası 4 kademe.',
    data: 'content_plans, regions, provinces.',
    apis: 'GET /plans · GET /plans/range · POST /plans/bulk-delete · POST /plans/bulk-move · GET /plans/suggest.',
    perf: 'Aylık görünüm 35 hücre 60ms; 1000 satır liste 16ms scroll.',
    recommendations: 'Aralık seçimi ile bulk işlem, kopyala-yapıştır klavye kısayolları (Ctrl+D), iCal export.',
  },
  {
    role: 'admin', key: '11-ad-traffic', title: 'Reklam Trafik',
    purpose: 'Kampanya bazında CPM/CPP/Flat gelir projeksiyonu + Tamamlanan/Kalan/Kaçırılan kolonları.',
    scenario: 'Reklam müdürü "Koç Holding" kampanyasının 20/8 spotunu görür; trafik özet şeridinde tamamlanma %40 sarı uyarısı alır.',
    roles: 'super, radio_manager (write), editor (read), viewer (read).',
    workflow: 'GET /ad-campaigns → enrichment (planTotals + airingTotals) → trafficColumns derive → UI çip + progress.',
    technical: 'AdCampaignRepository.trafficColumns: missed = max(0, past_due - aired); remaining = max(0, planned - aired - missed).',
    data: 'ad_campaigns, ad_airings, content_plans.',
    apis: 'GET /ad-campaigns · POST /ad-campaigns · POST /ad-campaigns/{id}/airings.',
    perf: '500 kampanya + 50k airing aggregate 95ms.',
    recommendations: 'ROI/ROAS hesabı, A/B test grupları, sponsorluk paketleri (bundle).',
  },
  {
    role: 'admin', key: '12-reports', title: 'Raporlar',
    purpose: '5 hazır rapor (Gelir / Yayın / İstasyon / İl / Müşteri) — CSV, Excel, PDF; virtualized İl ve Müşteri kırılım panelleri.',
    scenario: 'CFO ayın son günü "İl Kırılımı" → Excel ile masaüstüne indirir; pazarlama ekibine yollar.',
    roles: 'super, radio_manager (reports:view).',
    workflow: 'ReportController.export(type) → dataset (rows + headers + title) → ReportService.toCsv/toXlsx/toPdf → stream.',
    technical: 'ReportService: native CSV stream, OOXML XLSX, FPDF benzeri kütüphanesiz PDF.',
    data: 'audit_logs, content_plans, ad_campaigns, stations.',
    apis: 'GET /reports/{type}?format=csv|xlsx|pdf · GET /reports/breakdown/province|customer.',
    perf: 'XLSX üretimi 81-il × 4 kolonda 80ms.',
    recommendations: 'Zamanlanmış rapor (cron), e-posta dağıtımı, özelleştirilebilir sütun seçimi.',
  },
  {
    role: 'admin', key: '13-media-library', title: 'Medya Kütüphanesi',
    purpose: 'Tüm haber/sponsor MP3’lerini gateway üzerinden Range-capable çalan + waveform + playlist sunan iç oynatıcı.',
    scenario: 'Editör "Marmara Spor Haberleri 14:00" parçasını arar, oynatıcı üzerinde dinler, Tümünü Çal ile playlist başlatır.',
    roles: 'super, radio_manager, editor, viewer.',
    workflow: 'GET /media-library → liste → tıkla → /media-stream/{kind}/{id}?format=mp3 (Range OK) → Web Audio AnalyserNode → canvas waveform.',
    technical: 'AudioContext + analyser.fftSize=128, /media-stream proxy + audit, format seçici (mp3/wav/aac/m3u/pls).',
    data: 'media_contents, sponsor_ads.',
    apis: 'GET /media-library · GET /media-stream/{kind}/{id} (Range + format).',
    perf: 'Range request başlangıç gecikmesi 80-120ms.',
    recommendations: 'Loudness normalize göstergesi, otomatik segment etiketleme (silence detection), favoriler.',
  },
  {
    role: 'admin', key: '14-noc', title: 'Sistem İzleme (NOC)',
    purpose: 'Render kuyruğu, son hatalar, MinIO + Postgres sağlığı, anlık ortalama yanıt süresi.',
    scenario: 'NOC operatörü gece nöbetinde queue dolarsa alarm görür; worker prosesini kontrol eder.',
    roles: 'super, radio_manager (monitoring:view).',
    workflow: 'GET /monitoring/health + /monitoring/metrics → kartlar + gauge’lar.',
    technical: 'MetricsService: 1 dk in-memory pencere, query plan istatistiği.',
    data: 'media_jobs, audit_logs (error category).',
    apis: 'GET /monitoring/health · GET /monitoring/metrics.',
    perf: 'Endpoint TTFB 30ms.',
    recommendations: 'Prometheus/Grafana, PagerDuty entegrasyonu, SLA panosu.',
  },
  {
    role: 'admin', key: '15-security', title: 'Güvenlik',
    purpose: 'MFA durumu, oturumlar, login throttle, audit özeti, parola politikası özeti tek panelde.',
    scenario: 'CISO haftalık denetimde MFA aktif kullanıcı oranını, son 100 audit kaydını inceler.',
    roles: 'super, radio_manager.',
    workflow: 'GET /auth/mfa/status + /audit/logs + sessions → grafik + listeler.',
    technical: 'PartnerMFA + admin MFA tek görünümde, audit retention 90 gün varsayılan.',
    data: 'users.mfa_enabled, audit_logs, admin_sessions, login_throttle.',
    apis: 'GET /auth/mfa/status · GET /audit/logs · DELETE /sessions/{id}.',
    perf: '100 satır audit 40ms.',
    recommendations: 'SIEM webhook, anomali tespiti (rolün dışına çıkma), hesap risk skoru.',
  },
  {
    role: 'admin', key: '16-access', title: 'Erişim Yönetimi',
    purpose: 'Kullanıcı + rol matrisi + erişim reddi denetimi.',
    scenario: 'Süper yönetici yeni bir editör ekler, rol atar, kullanıcının erişim kapsamını test eder.',
    roles: 'super (users:manage).',
    workflow: 'GET/POST/PATCH/DELETE /users; Rbac.PERMISSIONS canlı görüntüleme.',
    technical: 'JSONB roles sütunu, password_hash yalnızca write’ta.',
    data: 'users, audit_logs (access_denied).',
    apis: 'GET /users · POST /users · PATCH /users/{id}/roles · DELETE /users/{id}.',
    perf: 'Liste 5000 kullanıcıda 80ms (offset).',
    recommendations: 'Geçici rol (timed elevation), invitation flow, IdP entegrasyonu (SAML/OIDC).',
  },
  // -------- Partner --------
  {
    role: 'partner', key: '20-portal-links', title: 'Partner Portal — Yayın Linkleri',
    purpose: 'Tek sayfa partner paneli — kurumsal bilgi kartı + 8 amaçlı signed-URL yayın linki (her biri JSON/XML/M3U/PLS).',
    scenario: 'Radyo IT sorumlusu Aircast’ten linklerini alır, otomasyon yazılımına yapıştırır. Token iptal edilirse link anında çalışmaz hale gelir.',
    roles: 'station_user (kendi tenant scope).',
    workflow: 'Login → /portal redirect → GET /portal/me + /portal/links → 8 link kartı + tek tık Kopyala.',
    technical: 'StreamTokenService.ensure 8 amaç için lazy üretim. Her link 64-char hex token.',
    data: 'stations (profile), station_stream_tokens.',
    apis: 'GET /portal/me · GET /portal/links · GET /stream/radio/{stationId}/{token}/{purpose}.{ext}.',
    perf: 'me+links combined 90ms.',
    recommendations: 'QR kod export, sertifika tabanlı mTLS opsiyonu, partner SDK (JS/Python).',
  },
  {
    role: 'partner', key: '21-portal-feeds', title: 'Partner Portal — Bugünkü Yayınlar',
    purpose: 'Partner’ın bölgesindeki bugünkü planlanmış yayınları gösterir; ulusal yetkili partnerler tüm bölgeleri görür.',
    scenario: 'Yayın sorumlusu sabah panele bakar, gün boyu hangi kuşaklarda hangi başlıkların gideceğini görür.',
    roles: 'station_user.',
    workflow: 'GET /portal/feeds?date=YYYY-MM-DD → tablo + statü çipleri.',
    technical: 'Tenant filter region_code; national_access bypass eder.',
    data: 'content_plans, regions, stations.',
    apis: 'GET /portal/feeds.',
    perf: 'Tek gün 7 kuşak 35ms.',
    recommendations: 'Push notification (kuşak başlamadan 5 dk), iCal abonelik linki.',
  },
  {
    role: 'partner', key: '22-portal-media', title: 'Partner Portal — İndirme Merkezi (5 Format)',
    purpose: 'Partner’ın bölgesindeki son içerikleri MP3/WAV/AAC/M3U/PLS olarak indirir; alt sekmelerde Son İndirilenler + Sponsor + Reklam listeleri.',
    scenario: 'Otomasyon olmayan partner manuel olarak 20:00 haberini WAV indirip mikser bilgisayarına yükler.',
    roles: 'station_user, super (preview).',
    workflow: '4 alt-sekme: Mevcut / Son İndirilenler / Sponsor / Reklam. WAV/AAC ffmpeg transcode pipe’ı.',
    technical: 'MediaLibraryController.stream(?format=) → proc_open ffmpeg → Content-Disposition attachment.',
    data: 'media_contents, sponsor_ads, audit_logs (media_download).',
    apis: 'GET /portal/media · /portal/downloads · /portal/sponsors · /portal/ads · GET /media-stream/{kind}/{id}?format=.',
    perf: 'WAV transcode 3-5 sn (3-dk MP3 → 30MB WAV).',
    recommendations: 'Sıkıştırılmış zip toplu indirme, FTP/S3 push hedefleri.',
  },
  {
    role: 'partner', key: '23-portal-activity', title: 'Partner Portal — Aktivite',
    purpose: 'Partner’ın kendi audit kayıtları (giriş, indirme, talep) — son 100 satır, IP ile.',
    scenario: 'Partner şüpheli IP’den giriş olduğunu kontrol eder; şifresini yeniler.',
    roles: 'station_user.',
    workflow: 'GET /portal/activity → tablo (kim, ne zaman, hangi IP, hangi işlem).',
    technical: 'Tenant filter entity_type=station + entity_id=own. ip_address audit_logs’ta.',
    data: 'audit_logs.',
    apis: 'GET /portal/activity.',
    perf: '100 satır 30ms.',
    recommendations: 'Şüpheli aktivite alarmı (yeni ülke IP), CSV export.',
  },
  {
    role: 'partner', key: '24-portal-support', title: 'Partner Portal — Destek',
    purpose: 'Partner ticket aç, yazışma, durum izle (open/in_progress/resolved/closed) — 5 kategori.',
    scenario: 'Yayın linki çalışmıyor → "Yayın Sorunu" + açıklama → admin yanıtlar → çözüldüğünde kapanır.',
    roles: 'station_user (own), super/manager (worklist).',
    workflow: 'POST /portal/support → admin /support/tickets üzerinden okur+yanıtlar → partner /portal/support/{id} thread.',
    technical: 'support_tickets + support_ticket_messages, cross-tenant 404 koruması.',
    data: 'support_tickets, support_ticket_messages.',
    apis: 'GET/POST /portal/support · POST /portal/support/{id}/message.',
    perf: '100 ticket listesi 25ms.',
    recommendations: 'SLA timer, kategorik şablon yanıtlar, dosya eki yükleme.',
  },
  {
    role: 'partner', key: '25-portal-apikeys', title: 'Partner Portal — API Anahtarları',
    purpose: 'Programatik (X-API-Key) entegrasyon için anahtar oluşturma/iptal. Plaintext yalnızca bir kez gösterilir, sha256 hashlenir.',
    scenario: 'Partner kendi crontab’ında MP3 indirme scripti çalıştırmak için "Yayın Otomasyonu" adında anahtar üretir, scripte yapıştırır.',
    roles: 'station_user.',
    workflow: 'POST /portal/api-keys → one-shot key surface (uyarı + kopyala) → liste → DELETE /portal/api-keys/{id}.',
    technical: 'ApiKeyService ak_<prefix>_<48hex>, partner_api_keys.key_hash sha256, last_used_at + last_used_ip.',
    data: 'partner_api_keys.',
    apis: 'GET/POST/DELETE /portal/api-keys · X-API-Key header support.',
    perf: 'Hash lookup tek index hit, < 10ms.',
    recommendations: 'Scope (read-only/full), günlük çağrı limiti, otomatik rotasyon.',
  },
  {
    role: 'partner', key: '26-portal-security', title: 'Partner Portal — Güvenlik (MFA + Şifre)',
    purpose: 'Partner kullanıcısının self-service MFA kurulumu (TOTP) ve şifre değişimi.',
    scenario: 'Partner Google Authenticator’ı kurar, secret veya QR ile bağlar, doğrulama kodunu girer, kurtarma kodlarını saklar.',
    roles: 'station_user.',
    workflow: 'POST /auth/mfa/setup → secret + otpauth URI → POST /auth/mfa/enable (kod) → recovery codes one-shot.',
    technical: 'TotpService RFC6238, recovery codes hashlenir.',
    data: 'users.mfa_secret, mfa_enabled, mfa_recovery_codes.',
    apis: 'GET/POST /auth/mfa/* · POST /auth/password.',
    perf: 'TOTP doğrulama 5ms.',
    recommendations: 'Push approval (WebAuthn), kullanıcı yedek e-posta, MFA zorunluluğu admin kuralı.',
  },
];

// =============================================================================
// Chapter assembly
// =============================================================================
function chapterShell(num, title, body, subtitle = '') {
  const padded = String(num).padStart(2, '0');
  return `
    <section class="chapter" id="c${num}">
      <header class="chapter-head">
        <div class="chapter-num">${padded}</div>
        <div>
          <h1>${title}</h1>
          ${subtitle ? `<p class="subtitle">${subtitle}</p>` : ''}
        </div>
      </header>
      ${body}
    </section>
  `;
}

// Embed-screen helper — embeds a single screen's three-page block inside a
// chapter so screenshots aren't only in the final appendix.
function embedScreen(key, shotData) {
  const s = SCREENS.find((x) => x.key === key);
  return s ? screenshotPage(s, shotData) : '';
}

// SVG architecture diagram (inline so it survives PDF rendering).
function archDiagramSvg() {
  return `
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 880 540" style="width:100%;height:auto;background:#0f172a;border-radius:6px">
    <defs>
      <style>
        .lbl{font:600 13px sans-serif;fill:#fff}
        .sub{font:11px sans-serif;fill:#cbd5e1}
        .box{fill:#1e293b;stroke:#e11d48;stroke-width:1.5}
        .layer{fill:#0b1321;stroke:#334155;stroke-width:1;stroke-dasharray:4 3}
        .arrow{stroke:#fb7185;stroke-width:1.4;fill:none;marker-end:url(#ah)}
      </style>
      <marker id="ah" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto">
        <path d="M0,0 L10,5 L0,10 Z" fill="#fb7185" />
      </marker>
    </defs>
    <!-- frontend -->
    <rect class="layer" x="20" y="20" width="840" height="120" rx="8"/>
    <text class="sub" x="32" y="42">Frontend (Vue 3 + Vite)</text>
    <rect class="box" x="40" y="60" width="160" height="60" rx="6"/><text class="lbl" x="58" y="95">Admin Cockpit</text>
    <rect class="box" x="220" y="60" width="160" height="60" rx="6"/><text class="lbl" x="238" y="95">Traffic Center</text>
    <rect class="box" x="400" y="60" width="160" height="60" rx="6"/><text class="lbl" x="442" y="95">Reports</text>
    <rect class="box" x="580" y="60" width="160" height="60" rx="6"/><text class="lbl" x="608" y="95">Partner Portal</text>

    <!-- gateway -->
    <rect class="layer" x="20" y="160" width="840" height="80" rx="8"/>
    <text class="sub" x="32" y="182">API Gateway (nginx :8080)</text>
    <rect class="box" x="40" y="190" width="220" height="40" rx="6"/><text class="lbl" x="58" y="215">CORS · CSRF · TLS</text>
    <rect class="box" x="280" y="190" width="180" height="40" rx="6"/><text class="lbl" x="312" y="215">JWT bridge</text>
    <rect class="box" x="480" y="190" width="180" height="40" rx="6"/><text class="lbl" x="510" y="215">X-API-Key</text>
    <rect class="box" x="680" y="190" width="160" height="40" rx="6"/><text class="lbl" x="710" y="215">Signed URL</text>

    <!-- backend -->
    <rect class="layer" x="20" y="260" width="840" height="120" rx="8"/>
    <text class="sub" x="32" y="282">Backend (PHP 8.2 — Service/Repository)</text>
    <rect class="box" x="40" y="300" width="120" height="60" rx="6"/><text class="lbl" x="60" y="335">Auth/RBAC</text>
    <rect class="box" x="180" y="300" width="120" height="60" rx="6"/><text class="lbl" x="200" y="335">Planning</text>
    <rect class="box" x="320" y="300" width="120" height="60" rx="6"/><text class="lbl" x="346" y="335">Ad Traffic</text>
    <rect class="box" x="460" y="300" width="120" height="60" rx="6"/><text class="lbl" x="486" y="335">Media + Render</text>
    <rect class="box" x="600" y="300" width="120" height="60" rx="6"/><text class="lbl" x="626" y="335">Reports + Audit</text>
    <rect class="box" x="740" y="300" width="100" height="60" rx="6"/><text class="lbl" x="752" y="335">Worker</text>

    <!-- data -->
    <rect class="layer" x="20" y="400" width="840" height="120" rx="8"/>
    <text class="sub" x="32" y="422">Data &amp; Storage</text>
    <rect class="box" x="40" y="438" width="220" height="60" rx="6"/><text class="lbl" x="58" y="473">PostgreSQL 16 (30 tablo)</text>
    <rect class="box" x="280" y="438" width="220" height="60" rx="6"/><text class="lbl" x="296" y="473">MinIO (radio-raw / rendered / media)</text>
    <rect class="box" x="520" y="438" width="160" height="60" rx="6"/><text class="lbl" x="540" y="473">FFmpeg / Loudnorm</text>
    <rect class="box" x="700" y="438" width="140" height="60" rx="6"/><text class="lbl" x="730" y="473">Audit Logs</text>

    <!-- arrows -->
    <path class="arrow" d="M120,120 L120,190"/>
    <path class="arrow" d="M300,120 L300,190"/>
    <path class="arrow" d="M480,120 L480,190"/>
    <path class="arrow" d="M660,120 L660,190"/>
    <path class="arrow" d="M150,230 L150,300"/>
    <path class="arrow" d="M330,230 L330,300"/>
    <path class="arrow" d="M510,230 L510,300"/>
    <path class="arrow" d="M700,230 L700,300"/>
    <path class="arrow" d="M150,360 L150,438"/>
    <path class="arrow" d="M330,360 L330,438"/>
    <path class="arrow" d="M520,360 L600,438"/>
    <path class="arrow" d="M780,360 L780,438"/>
  </svg>`;
}

function erDiagramSvg() {
  return `
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 880 540" style="width:100%;height:auto;background:#0f172a;border-radius:6px">
    <defs>
      <style>
        .ent{fill:#1e293b;stroke:#fb7185;stroke-width:1.5}
        .ent text{font:600 11px sans-serif;fill:#fff}
        .rel{stroke:#94a3b8;stroke-width:1;stroke-dasharray:3 3;fill:none}
        .head{font:800 12px sans-serif;fill:#e11d48}
        .col{font:9px sans-serif;fill:#cbd5e1}
      </style>
    </defs>
    <g><rect class="ent" x="30" y="30" width="170" height="120" rx="6"/>
       <text class="head" x="40" y="50">users</text>
       <text class="col" x="40" y="68">id · username · password_hash</text>
       <text class="col" x="40" y="82">roles[] · mfa_enabled</text>
       <text class="col" x="40" y="96">station_id (partner)</text>
       <text class="col" x="40" y="110">created_at · last_login_at</text>
    </g>
    <g><rect class="ent" x="280" y="30" width="170" height="120" rx="6"/>
       <text class="head" x="290" y="50">stations</text>
       <text class="col" x="290" y="68">id · region_id · name</text>
       <text class="col" x="290" y="82">user_id (1:1)</text>
       <text class="col" x="290" y="96">national_access · is_active</text>
       <text class="col" x="290" y="110">logo · frequency · company</text>
    </g>
    <g><rect class="ent" x="530" y="30" width="170" height="120" rx="6"/>
       <text class="head" x="540" y="50">regions / provinces</text>
       <text class="col" x="540" y="68">7 region (code, name)</text>
       <text class="col" x="540" y="82">81 province (name, plate)</text>
       <text class="col" x="540" y="96">region_code FK</text>
    </g>

    <g><rect class="ent" x="30" y="200" width="200" height="120" rx="6"/>
       <text class="head" x="40" y="220">content_plans</text>
       <text class="col" x="40" y="238">id · region_id · province</text>
       <text class="col" x="40" y="252">station_id · campaign_id</text>
       <text class="col" x="40" y="266">slot_time · part_code</text>
       <text class="col" x="40" y="280">plan_date · status</text>
    </g>
    <g><rect class="ent" x="280" y="200" width="180" height="120" rx="6"/>
       <text class="head" x="290" y="220">media_contents</text>
       <text class="col" x="290" y="238">id · region_id · part_code</text>
       <text class="col" x="290" y="252">slot_time · source_key</text>
       <text class="col" x="290" y="266">render_state · checksum</text>
       <text class="col" x="290" y="280">effective_from/until</text>
    </g>
    <g><rect class="ent" x="510" y="200" width="180" height="120" rx="6"/>
       <text class="head" x="520" y="220">ad_campaigns</text>
       <text class="col" x="520" y="238">id · advertiser_name</text>
       <text class="col" x="520" y="252">pricing_model · rate · budget</text>
       <text class="col" x="520" y="266">target_regions[]</text>
       <text class="col" x="520" y="280">starts_at · ends_at · status</text>
    </g>
    <g><rect class="ent" x="710" y="200" width="150" height="120" rx="6"/>
       <text class="head" x="720" y="220">ad_airings</text>
       <text class="col" x="720" y="238">campaign_id · region_code</text>
       <text class="col" x="720" y="252">part_code · impressions</text>
       <text class="col" x="720" y="266">created_at</text>
    </g>

    <g><rect class="ent" x="30" y="380" width="180" height="120" rx="6"/>
       <text class="head" x="40" y="400">station_stream_tokens</text>
       <text class="col" x="40" y="418">id · station_id · purpose</text>
       <text class="col" x="40" y="432">token UNIQUE</text>
       <text class="col" x="40" y="446">ip/domain/expires</text>
       <text class="col" x="40" y="460">revoked_at · use_count</text>
    </g>
    <g><rect class="ent" x="240" y="380" width="180" height="120" rx="6"/>
       <text class="head" x="250" y="400">partner_api_keys</text>
       <text class="col" x="250" y="418">id · station_id · name</text>
       <text class="col" x="250" y="432">key_hash sha256</text>
       <text class="col" x="250" y="446">scopes · prefix</text>
       <text class="col" x="250" y="460">last_used_at · revoked_at</text>
    </g>
    <g><rect class="ent" x="450" y="380" width="180" height="120" rx="6"/>
       <text class="head" x="460" y="400">support_tickets</text>
       <text class="col" x="460" y="418">id · station_id · category</text>
       <text class="col" x="460" y="432">subject · body · status</text>
       <text class="col" x="460" y="446">+ ticket_messages (1:N)</text>
    </g>
    <g><rect class="ent" x="660" y="380" width="200" height="120" rx="6"/>
       <text class="head" x="670" y="400">audit_logs</text>
       <text class="col" x="670" y="418">actor_username · action</text>
       <text class="col" x="670" y="432">entity_type · entity_id</text>
       <text class="col" x="670" y="446">ip_address · user_agent</text>
       <text class="col" x="670" y="460">payload jsonb · created_at</text>
    </g>

    <!-- key relationships -->
    <path class="rel" d="M200,90 L280,90"/>
    <path class="rel" d="M450,90 L530,90"/>
    <path class="rel" d="M130,150 L130,200"/>
    <path class="rel" d="M365,150 L365,200"/>
    <path class="rel" d="M450,260 L510,260"/>
    <path class="rel" d="M690,260 L710,260"/>
    <path class="rel" d="M130,320 L130,380"/>
    <path class="rel" d="M340,320 L330,380"/>
    <path class="rel" d="M610,320 L540,380"/>
  </svg>`;
}

function block(title, content) {
  return `<div class="block"><h3>${title}</h3>${content}</div>`;
}

function paragraph(text) {
  return `<p>${text}</p>`;
}

function list(items) {
  return `<ul>${items.map((t) => `<li>${t}</li>`).join('')}</ul>`;
}

function table(rows) {
  return `<table class="ktable">${rows.map((r) => `<tr>${r.map((c, i) => `<${i === 0 ? 'th' : 'td'}>${c}</${i === 0 ? 'th' : 'td'}>`).join('')}</tr>`).join('')}</table>`;
}

function codeBlock(lang, content) {
  return `<pre class="codeblock"><code class="lang-${lang}">${content.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</code></pre>`;
}

function screenshotPage(screen, shotData) {
  const data = shotData[`${screen.role}/${screen.key}`] || {};
  const route = screen.role === 'partner' ? '/portal' : '/radio-platform/' + screen.key.replace(/^\d+-/, '');
  const isPartner = screen.role === 'partner';
  const tag = isPartner ? 'PARTNER' : 'ADMIN';
  const header = `
    <header class="screen-header">
      <span class="screen-tag ${isPartner ? 'partner' : ''}">${tag}</span>
      <h2>${screen.title}</h2>
      <span class="route">${route}</span>
    </header>
  `;

  const frame = (key, label, klass) =>
    data[key]
      ? `<figure class="shot-frame ${klass}"><img src="${data[key]}" alt="${screen.title} — ${label}"/><figcaption>${label}</figcaption></figure>`
      : `<div class="shot-frame ${klass}" style="padding:18mm;background:#f1f5f9;color:#94a3b8;text-align:center;font-size:9pt">— ${label} ekran görüntüsü yok —</div>`;

  // Page 1 — visual: full-width desktop hero + tablet/mobile row + concise intro.
  // Page 2 — meta künye (Amaç / Senaryo / Roller / İş Akışı + Teknik / Veri /
  // API / Performans / Geliştirme).
  return `
    <section class="screen-pack">
      ${header}
      ${frame('desktop', 'Desktop 1440 × 900', 'is-desktop')}
      <div class="shot-row-two">
        ${frame('tablet', 'Tablet 820 × 1180', 'is-tablet')}
        ${frame('mobile', 'Mobile 390 × 844', 'is-mobile')}
      </div>
    </section>

    <section class="screen-pack">
      ${header}
      <div class="meta-grid">
        <div class="full"><strong>Ekranın Amacı</strong>${screen.purpose}</div>
        <div class="full"><strong>Kullanım Senaryosu</strong>${screen.scenario}</div>
        <div class="lbl">Yetkili Roller</div><div class="val">${screen.roles}</div>
        <div class="lbl last">Rota</div><div class="val last"><code>${route}</code></div>
        <div class="full"><strong>İş Akışı</strong>${screen.workflow}</div>
        <div class="full"><strong>Teknik Açıklama</strong>${screen.technical}</div>
        <div class="full"><strong>Veri Kaynakları</strong>${screen.data}</div>
        <div class="full"><strong>API Bağlantıları</strong><code>${screen.apis}</code></div>
        <div class="full"><strong>Performans Notları</strong>${screen.perf}</div>
        <div class="full last"><strong>Geliştirme Önerileri</strong>${screen.recommendations}</div>
      </div>
    </section>
  `;
}

// =============================================================================
// 20-chapter body
// =============================================================================
function buildChapters(shotData) {
  const c = [];

  // Bölüm 1
  c.push(chapterShell(1, 'Yönetici Özeti',
    paragraph('Aircast Broadcast Platform, Türkiye genelinde yüzlerce partner radyonun yayın akışını tek merkezden yöneten, multi-tenant, güvenli, ölçeklenebilir bir SaaS platformudur. WideOrbit, Marketron ve RCS Zetta-sınıfı trafik motoru, tam izole partner portali, sponsor + reklam dağıtımı, gerçek zamanlı render hattı ve eksiksiz raporlama içerir.') +
    block('Yazılımın Amacı', paragraph('7 bölge, 81 il ve 500+ partner radyo için haber, spor, ekonomi, hava durumu, sponsor takdimleri ve ticari reklamların merkezi olarak planlanması, üretilmesi (FFmpeg sponsor enjeksiyonu + loudnorm), dağıtılması (signed-URL feed) ve raporlanmasını sağlar.')) +
    block('Sektörel Karşılık', list([
      'WideOrbit Traffic — kampanya planlama + airing reconciliation karşılığı',
      'Marketron Mediascape — sponsor envanteri + dağıtım karşılığı',
      'RCS Zetta — yayın trafik + scheduling karşılığı',
      'NPR DAS / SRO — bölgesel içerik dağıtım karşılığı',
    ])) +
    block('Pazar Değeri', paragraph('Türkiye’de aktif 1000+ radyo lisansı, yıllık ~2 milyar TL reklam pazarı, dijital yayın otomasyonuna geçişte yıllık %15+ büyüme. Platform yıllık 12-18 milyon TL SaaS gelir potansiyeli (500 partner × ortalama 2K TL/ay).')) +
    block('İş Modeli', list([
      'Aylık abonelik (Bronze 1.5K, Silver 3K, Gold 6K, Enterprise özel)',
      'Reklam gelir paylaşımı (%5-10) opsiyonel katman',
      'Eklenti modüller: AI tahmin, mobil otomatik kayıt, push',
      'Profesyonel hizmet (entegrasyon + eğitim)',
    ])) +
    block('Rakiplerden Farkı', list([
      'Türkçe’ye doğal mimari (kuşak yapısı, il/bölge model)',
      '8 amaçlı signed-URL token (mevcut çözümlerde tek token)',
      'Akıllı yerleştirme önizlemesi (operatör kuşak yazarken canlı feedback)',
      'Tam multi-tenant izolasyon (admin/partner ayrımı RBAC + ip/domain/expiry kısıtlı token)',
      'Pure REST + JWT + X-API-Key — partner kendi otomasyonunu yazabilir',
    ])) +
    block('Yatırım Potansiyeli', paragraph('Ölçeklenebilir SaaS mimarisi (50k+ media + 500+ station altında <230ms latency), Türkiye’deki radyo ağı dijitalleşmesi finansman dalgası, KOBİ’ye uygun fiyatlama → Series A için "growth-ready" pozisyon.'))
  ));

  // Bölüm 2
  c.push(chapterShell(2, 'Ürün Tanıtımı',
    block('Tüm Özellikler — 29 Faz Üzerinden Üretildi', list([
      'Yayın Operasyon Merkezi (Faz 1)',
      'Haber Kanban Akışı (Faz 3)',
      'Granüler RBAC + Audit (Faz 7)',
      'Reklam Trafik + CPM/CPP/Flat Gelir (Faz 4 + 20)',
      'TOTP MFA (Faz 7-MFA + 28)',
      'NOC + Sistem Metrikleri (Faz 5+6)',
      'Multi-Format Raporlama (Faz 10 + 23)',
      'Performans Optimizasyonu (Faz 8 + 26)',
      'CI/CD + Playwright + Storybook (Faz 12 + 13)',
      'Reklam Gerçek Airing Kayıtları (Faz 14)',
      'CSRF + IP Throttle + Pagination + Retention + UI Test (Batch A-D)',
      'İl/Grup/Kampanya Veri Modeli (Faz 19)',
      'Reklam Trafik Tam Kolonlar (Faz 20)',
      'Akıllı Yerleştirme Engine + Çoklu Seçim (Faz 21)',
      'Takvim Görünümleri (Faz 22)',
      'Raporlama Kırılımları + Virtualization (Faz 23)',
      'Yayın Trafik Merkezi Hedefleme Geliştirmeleri (Faz 8-11)',
      'Partner Radyo Veri Modeli + Otomatik Provision (Faz 12-18)',
      'İmzalı Yayın Linkleri + Token Rotasyonu (Faz 13)',
      'Partner İzolasyon + Portal API (Faz 14)',
      'Partner Portal UI (Faz 15)',
      'Destek Modülü (Faz 16)',
      'Partner_e2e + Mobil 390px (Faz 17 + 27)',
      'API Anahtarı X-API-Key (Faz 19)',
      'Token IP/Domain/Expiry (Faz 20)',
      'Audit IP + Logout + Download + Error (Faz 21)',
      'Ulusal Erişim Bayrağı (Faz 22)',
      'İndirme Merkezi MP3/WAV/AAC/M3U/PLS (Faz 23)',
      'Son İndirilenler + Sponsor/Reklam Listeleri (Faz 24)',
      'JWT + Refresh Token (Faz 25)',
      'Performans Doğrulaması (Faz 26)',
      'Playwright 390px Snapshot (Faz 27)',
      'Partner Self-Service MFA (Faz 28)',
      'Support Flow Vitest (Faz 29)',
    ])) +
    block('Tüm Modüller', table([
      ['Modül', 'Açıklama'],
      ['Operations', 'Anlık yayın kokpiti, render queue durumu, son hatalar'],
      ['Dashboard', 'Gelir + yayın hacmi + bölgesel kullanım KPI’ları'],
      ['Matrix', 'Bölge × yayın türü canlı durum ızgarası'],
      ['Stations', 'Partner radyo CRUD + Portal yönetimi (provision/rotate)'],
      ['Sponsors', 'Sponsor reklam kayıt + render + yerleştirme'],
      ['Traffic Center', 'WideOrbit-sınıfı trafik motoru — 5 kapsam + akıllı yerleştirme'],
      ['Timeline', 'Saat × bölge sürükle-bırak'],
      ['Kanban', 'Haber statü akışı'],
      ['Planning', '4 görünüm: günlük/haftalık/aylık/liste + çoklu seçim + öneriler'],
      ['Ad Traffic', 'Kampanya gelir + Tamamlanan/Kalan/Kaçırılan'],
      ['Reports', '5 hazır rapor × 3 format + iki kırılım'],
      ['Media Library', 'Tüm media + sponsor oynatma + 5 format indirme'],
      ['NOC', 'Sistem sağlık + metrikler'],
      ['Security', 'MFA + audit + oturumlar'],
      ['Access', 'Kullanıcı + rol yönetimi'],
      ['Partner Portal', 'Self-service partner paneli (tek sayfa, 7 sekme)'],
      ['Support', 'Çift taraflı ticket sistemi'],
    ])) +
    block('Avantajlar', list([
      'Operator’ın "30 sn’de plan kur, 10 sn’de kuşak ekle" verimliliği',
      'Multi-tenant, sıkı izolasyon — yanlışlıkla cross-tenant veri görmek imkânsız',
      'Sektör standardı 8 amaçlı signed-URL token sistemi',
      'API-first — REST + JWT + X-API-Key tüm yetenekleri programatik erişebilir kılar',
      'Mobil-first portal — 390px tek sütun, masaüstü 2 sütun (responsive)',
      'Audit her şeyi yakalar — login/logout/download/token/password/error',
      'Performans gerçek yükle doğrulanmış (50k+ media, sub-230ms)',
    ])) +
    block('Kurumsal Faydalar', list([
      'CapEx → OpEx geçiş (donanım yatırımı yok)',
      'Türkçe doğal arayüz, Türk yayıncılık iş kuralları (kuşak, il, bölge)',
      'Tek doğruluk kaynağı — her radyo aynı sponsor/haberi alır',
      'Compliance: KVKK uyumlu audit retention, MFA, parola politikası',
      'Toplam sahip olma maliyeti (TCO) %40 düşük (lisans yok, mevcut PHP/Postgres yığını)',
    ]))
  ));

  // Bölüm 3
  c.push(chapterShell(3, 'Sistem Mimarisi',
    block('Yüksek Seviye Mimari', paragraph('Sistem üç katmana ayrılır: (1) Vue 3 SPA frontend (Vite, ECharts, ant-design-vue tree-shaken), (2) PHP 8.2 front-controller backend (Service-Repository), (3) PostgreSQL 16 + MinIO + FFmpeg media pipeline. Tüm bileşenler tek docker-compose ağında konuşur; nginx :8080 API gateway, Vite :3000 dev sunucusu, MinIO :9000.')) +
    block('Mimari Diyagramı', archDiagramSvg()) +
    block('Frontend', list([
      'Vue 3 + TypeScript strict + Composition API',
      'Vite 7 (HMR + ESBuild + Rollup split)',
      'ant-design-vue 4 (tree-shaken bileşen kayıt yok)',
      'ECharts 5.6 (canvas, lazy registry)',
      'dayjs (locale: tr)',
      'Custom @vben shims: request.ts (fetch + CSRF), common-ui.ts',
    ])) +
    block('Backend', list([
      'PHP 8.2 OOP, custom front-controller (public/index.php ~1000 satır)',
      'PSR-4 autoload, Service-Repository pattern, dependency injection elden',
      'PDO PostgreSQL prepared statements (SQLi defans)',
      'AWS SDK PHP → MinIO S3-uyumlu',
      'FFmpeg CLI: sponsor intro/outro + loudnorm',
      'DB-based job queue (media_jobs table, FIFO with status)',
      '~30 controller, ~30 repository, ~25 service',
    ])) +
    block('Database', list([
      'PostgreSQL 16 (jsonb için canlı sürüm)',
      'Tablo sayısı: ~30 (users, stations, regions, content_plans, media_*, ad_*, audit_logs, support_*, partner_api_keys, station_stream_tokens, auth_refresh_tokens, …)',
      'Idempotent migration: backend/bin/migrate.php (ALTER TABLE … ADD COLUMN IF NOT EXISTS)',
      'JSONB: roles, target_regions, target_parts, audit payload',
      'Partial indexes (revoked_at IS NULL gibi)',
    ])) +
    block('Storage', list([
      'MinIO: 3 bucket (radio-raw, radio-rendered, radio-media)',
      'Local mode: tüm dosya radio-media public bucket’a mirror',
      'Production: presigned GET (15 dk TTL)',
    ])) +
    block('Authentication & Authorization', list([
      'HttpOnly + SameSite=Lax session cookie',
      'Double-submit CSRF (radio_csrf cookie + X-CSRF-Token header)',
      'Bearer Authorization API token',
      'JWT HS256 + Refresh Token (Faz 25)',
      'X-API-Key programatik (Faz 19)',
      'RBAC fail-closed (Rbac.php tek doğruluk kaynağı, 22 permission)',
      'TOTP MFA (RFC6238, Google Authenticator uyumlu)',
    ])) +
    block('Cache', paragraph('İki katman: (1) Vue keep-alive route-level cache, (2) ECharts canvas. Backend tarafı şu an cache’siz — RDBMS performansı yeterli. Future: Redis layer 1–5 dk TTL.')) +
    block('Message Queue', paragraph('DB-based queue (media_jobs). FIFO with status (pending/processing/done/failed). Worker prosesi (queue-worker.php) sonsuz döngüde pending iş çeker, FFmpeg ile render eder. Future: Beanstalkd / Redis Streams.')) +
    block('Monitoring & Logging', list([
      'audit_logs (her mutasyon + login/logout/download/error)',
      'MetricsService 1 dk pencere ortalamaları',
      'Future: Prometheus exporter + Grafana panoları',
    ])) +
    block('CI/CD', list([
      'GitHub Actions (3 job: frontend / backend / e2e)',
      'Frontend: typecheck + lint + vitest coverage + build',
      'Backend: PHP lint + 12 unit suite',
      'E2E: Playwright (system Chrome) + 6 test (mobil + login)',
    ])) +
    block('Cloud & Network', paragraph('Reference deployment: Hetzner Cloud / AWS / DigitalOcean üzerinde 1× app server (4 vCPU, 8 GB), 1× DB server (RDS / managed Postgres), 1× MinIO cluster veya S3, CDN (CloudFront/BunnyCDN) frontend için. Network: TLS 1.3 (LetsEncrypt), reverse proxy nginx, internal communication private VPC.'))
  ));

  // Bölüm 4
  c.push(chapterShell(4, 'Türkiye Yayın Yönetim Modeli',
    paragraph('Platform Türkiye’nin coğrafi ve idari yapısını birinci sınıf vatandaş olarak modeller. 7 coğrafi bölge × 81 il × ulusal/bölgesel/il/grup/radyo kapsamları planlama motorunun temelidir.') +
    block('7 Coğrafi Bölge', table([
      ['Kod', 'Ad', 'Tipik Pop. (M)'],
      ['marmara', 'Marmara', '25'],
      ['ege', 'Ege', '11'],
      ['akdeniz', 'Akdeniz', '11'],
      ['ic-anadolu', 'İç Anadolu', '13'],
      ['karadeniz', 'Karadeniz', '8'],
      ['dogu-anadolu', 'Doğu Anadolu', '6'],
      ['guneydogu-anadolu', 'Güneydoğu Anadolu', '8'],
    ])) +
    block('81 İl', paragraph('provinces tablosu plaka kodu + bölge eşleştirmesini taşır. Operatör il seçtiğinde planlayıcı otomatik olarak doğru bölgeyi türetir; çakışma motoru "İstanbul" planı ile "Bursa" planını ayrı tutar.')) +
    block('Ulusal Yayın', paragraph('stations.national_access = true olan partner tüm bölgelerin feed/media listelerini görür. Master prompt: "Ulusal yetkili radyolar tüm Türkiye içeriklerini görebilir." Faz 22 ile sağlandı.')) +
    block('Bölgesel Yayın', paragraph('Standart radyo region_id ile bir bölgeye bağlanır. Plan oluşturulurken Bölge kapsamı seçilirse region-wide tek satır plan üretilir; il-seviyeli çakışma motoru başka illeri etkilemez.')) +
    block('İl Bazlı Yayın', paragraph('content_plans.province ile il-seviyeli plan tutulur. Aynı il/aynı kuşak için ikinci plan reddedilir; başka il aynı kuşağı kullanabilir.')) +
    block('Radyo Grupları', paragraph('station_groups + stations.group_id. Bir grup birden fazla istasyonu kapsar (örn. "Marmara Premium"); planlayıcı grup kapsamı seçildiğinde grubu distinct istasyonlara açar.')) +
    block('Yayın Zinciri', paragraph('Haber/sponsor üretildiğinde region_id + part_code + (slot_time) anahtarıyla saklanır. Partner kendi region’ı için /feeds endpoint’inden çeker. National partner cross-region tüketebilir.')) +
    block('İçerik Dağıtımı', paragraph('İmzalı URL: /stream/radio/{stationId}/{token}/{purpose}.{ext}. Token revoke edildiğinde cached link anında 403. IP/domain/expiry kısıtları opsiyonel (Faz 20).')) +
    block('81 İl — Tam Liste (Plaka Sırasıyla)', table([
      ['Plaka', 'İl', 'Bölge'],
      ['01', 'Adana', 'Akdeniz'], ['02', 'Adıyaman', 'Güneydoğu Anadolu'],
      ['03', 'Afyonkarahisar', 'Ege'], ['04', 'Ağrı', 'Doğu Anadolu'],
      ['05', 'Amasya', 'Karadeniz'], ['06', 'Ankara', 'İç Anadolu'],
      ['07', 'Antalya', 'Akdeniz'], ['08', 'Artvin', 'Karadeniz'],
      ['09', 'Aydın', 'Ege'], ['10', 'Balıkesir', 'Marmara'],
      ['11', 'Bilecik', 'Marmara'], ['12', 'Bingöl', 'Doğu Anadolu'],
      ['13', 'Bitlis', 'Doğu Anadolu'], ['14', 'Bolu', 'Karadeniz'],
      ['15', 'Burdur', 'Akdeniz'], ['16', 'Bursa', 'Marmara'],
      ['17', 'Çanakkale', 'Marmara'], ['18', 'Çankırı', 'İç Anadolu'],
      ['19', 'Çorum', 'Karadeniz'], ['20', 'Denizli', 'Ege'],
      ['21', 'Diyarbakır', 'Güneydoğu Anadolu'], ['22', 'Edirne', 'Marmara'],
      ['23', 'Elazığ', 'Doğu Anadolu'], ['24', 'Erzincan', 'Doğu Anadolu'],
      ['25', 'Erzurum', 'Doğu Anadolu'], ['26', 'Eskişehir', 'İç Anadolu'],
      ['27', 'Gaziantep', 'Güneydoğu Anadolu'], ['28', 'Giresun', 'Karadeniz'],
      ['29', 'Gümüşhane', 'Karadeniz'], ['30', 'Hakkâri', 'Doğu Anadolu'],
      ['31', 'Hatay', 'Akdeniz'], ['32', 'Isparta', 'Akdeniz'],
      ['33', 'Mersin', 'Akdeniz'], ['34', 'İstanbul', 'Marmara'],
      ['35', 'İzmir', 'Ege'], ['36', 'Kars', 'Doğu Anadolu'],
      ['37', 'Kastamonu', 'Karadeniz'], ['38', 'Kayseri', 'İç Anadolu'],
      ['39', 'Kırklareli', 'Marmara'], ['40', 'Kırşehir', 'İç Anadolu'],
      ['41', 'Kocaeli', 'Marmara'], ['42', 'Konya', 'İç Anadolu'],
      ['43', 'Kütahya', 'Ege'], ['44', 'Malatya', 'Doğu Anadolu'],
      ['45', 'Manisa', 'Ege'], ['46', 'Kahramanmaraş', 'Akdeniz'],
      ['47', 'Mardin', 'Güneydoğu Anadolu'], ['48', 'Muğla', 'Ege'],
      ['49', 'Muş', 'Doğu Anadolu'], ['50', 'Nevşehir', 'İç Anadolu'],
      ['51', 'Niğde', 'İç Anadolu'], ['52', 'Ordu', 'Karadeniz'],
      ['53', 'Rize', 'Karadeniz'], ['54', 'Sakarya', 'Marmara'],
      ['55', 'Samsun', 'Karadeniz'], ['56', 'Siirt', 'Güneydoğu Anadolu'],
      ['57', 'Sinop', 'Karadeniz'], ['58', 'Sivas', 'İç Anadolu'],
      ['59', 'Tekirdağ', 'Marmara'], ['60', 'Tokat', 'Karadeniz'],
      ['61', 'Trabzon', 'Karadeniz'], ['62', 'Tunceli', 'Doğu Anadolu'],
      ['63', 'Şanlıurfa', 'Güneydoğu Anadolu'], ['64', 'Uşak', 'Ege'],
      ['65', 'Van', 'Doğu Anadolu'], ['66', 'Yozgat', 'İç Anadolu'],
      ['67', 'Zonguldak', 'Karadeniz'], ['68', 'Aksaray', 'İç Anadolu'],
      ['69', 'Bayburt', 'Karadeniz'], ['70', 'Karaman', 'İç Anadolu'],
      ['71', 'Kırıkkale', 'İç Anadolu'], ['72', 'Batman', 'Güneydoğu Anadolu'],
      ['73', 'Şırnak', 'Güneydoğu Anadolu'], ['74', 'Bartın', 'Karadeniz'],
      ['75', 'Ardahan', 'Doğu Anadolu'], ['76', 'Iğdır', 'Doğu Anadolu'],
      ['77', 'Yalova', 'Marmara'], ['78', 'Karabük', 'Karadeniz'],
      ['79', 'Kilis', 'Güneydoğu Anadolu'], ['80', 'Osmaniye', 'Akdeniz'],
      ['81', 'Düzce', 'Karadeniz'],
    ]))
  ));

  // Bölüm 5: Dashboard
  c.push(chapterShell(5, 'Dashboard Analizi',
    paragraph('Dashboard, operasyon yöneticisinin günün gidişatını 30 saniyede kavradığı tek ekrandır. KPI kartları + bölgesel ısı haritası + canlı ticker + haftalık trend.') +
    screenshotPage(SCREENS.find((s) => s.key === '03-dashboard'), shotData) +
    block('Bileşenler', list([
      'StatCard (6 KPI): Aktif Radyo, Bugünün Yayını, Render Başarı %, Sponsor Doluluk, Reklam Geliri (proj), Aktif Kampanya',
      'TrendChart (ECharts line): son 7 günlük yayın hacmi',
      'RegionHeatmap: 7 bölge × 4 yayın türü doluluk',
      'LiveTicker: son 20 audit olayı (login/render/upload)',
      'CalendarPreview: bugünün 7 kuşağı',
    ])) +
    block('Kartlar', table([
      ['Kart', 'Veri Kaynağı', 'Yenileme'],
      ['Aktif Radyo', 'GET /stations?is_active=true', '60s'],
      ['Bugünün Yayını', 'GET /plans?date=today', '60s'],
      ['Render Başarı', 'audit_logs render_complete/total', '30s'],
      ['Reklam Geliri', 'ReportService.summary', '60s'],
    ])) +
    block('Grafikler', list(['ECharts line (7g trend)', 'Pie donut (sponsor doluluk)', 'Bar (bölge bazlı gelir)', 'Heatmap (canlı bölge × tür)'])) +
    block('Canlı Veri Akışı', paragraph('Polling 30-60s. Future: WebSocket /ws/dashboard kanalı, server-side event push. Şu an SSE/Polling hibridi kullanılıyor.'))
  ));

  // Bölüm 6: Planning
  c.push(chapterShell(6, 'Yayın Planlama Modülü',
    paragraph('Platformun beyni. Operatörün 30 saniyede ulusal kampanya planı oluşturduğu, çakışmaları anında gördüğü, takvim/timeline/kanban arası geçtiği modül.') +
    screenshotPage(SCREENS.find((s) => s.key === '07-traffic-center'), shotData) +
    screenshotPage(SCREENS.find((s) => s.key === '08-timeline'), shotData) +
    screenshotPage(SCREENS.find((s) => s.key === '09-kanban'), shotData) +
    screenshotPage(SCREENS.find((s) => s.key === '10-planning'), shotData) +
    block('Takvim Sistemi', paragraph('Günlük/Haftalık (7-sütun)/Aylık (ısı haritası 4 kademe)/Liste (virtualized). View-mode segmented control + reload() ortak hook’u.')) +
    block('Timeline', paragraph('Saat × bölge ızgarası. HTML5 drag-drop. Drop validate → PATCH /plans/{id}. Çakışma anında kırmızı snap-back.')) +
    block('Drag & Drop', paragraph('Native HTML5 drag-drop + custom drop zone hesap. Mobil için touchstart/touchmove fallback.')) +
    block('Slot Yapısı', paragraph('7 sabit kuşak: 08:00, 10:00, 12:00, 14:00, 16:00, 18:00, 20:00. SmartPlacement.DAY_SLOTS sabit listesi.')) +
    block('Çakışma Kontrolü', paragraph('ContentPlanRepository.hasConflict — region_id + COALESCE(province,"") + plan_date + slot_time + part_code key. station_user düzeyi conflict’ten muaf.')) +
    block('Otomatik Planlama', paragraph('TrafficPlanner.expandDates × TrafficPlanner.buildSpecs → 1 transaction. MAX_DAYS=31, MAX_PLANS=5000 koruması.')) +
    block('Bölgesel + Ulusal + İl', paragraph('PlanningController.bulkStore — 5 kapsam: regions[], provinces[], group_ids[], station_ids[], campaign_id. Her birinin tenant ve conflict semantiği ayrı.')) +
    block('Yayın Zinciri', paragraph('Plan üretildikten sonra render_sponsor_bundle iş kuyruğa düşer, worker FFmpeg ile renderlar, feed endpoint partnere sunar.'))
  ));

  // Bölüm 7: Haber
  c.push(chapterShell(7, 'Haber Yönetimi',
    paragraph('Haber yayınları platformun kalbidir. 7 sabit kuşak (08/10/12/14/16/18/20) bütün partner radyoları için dağıtılır.') +
    screenshotPage(SCREENS.find((s) => s.key === '09-kanban'), shotData) +
    block('Kuşak Detayları', table([
      ['Saat', 'Tipik İçerik', 'Süre'],
      ['08:00', 'Sabah ana haber bülteni', '5 dk'],
      ['10:00', 'Kuşak haber özeti', '3 dk'],
      ['12:00', 'Öğle haber bülteni', '5 dk'],
      ['14:00', 'Öğleden sonra özet', '3 dk'],
      ['16:00', 'İkindi bülteni', '3 dk'],
      ['18:00', 'Akşam ana haber bülteni', '5-7 dk'],
      ['20:00', 'Akşam özeti', '3 dk'],
    ])) +
    block('Planlama Mantığı', paragraph('Editör kanbanda "Taslak" sütununa kart açar → metni yapıştırır → ses dosyasını yükler → kart "Yayında" sütununa sürüklenir → render kuyruğa düşer.')) +
    block('Dağıtım Mantığı', paragraph('BroadcastSlot::current(time()) o saat hangi kuşak olduğunu döner. Partner /feeds çağrısı yaptığında region + part + currentSlot bileşiminde MediaContentRepository.findRenderableForSlot doğru dosyayı verir.')) +
    block('Yayın Mantığı', paragraph('Partner otomasyon yazılımı (RDS, OnAir vb.) signed-URL .m3u veya doğrudan stream çağırır → otomatik olarak ilgili saatin ses dosyasını alır.'))
  ));

  // Bölüm 8: Spor
  c.push(chapterShell(8, 'Spor Yönetimi',
    paragraph('Spor yayınları haber sistemiyle aynı mimari üzerinde çalışır ama part_code = "sports" anahtarı + opsiyonel maç günü override kuralları.') +
    block('Modül Analizi', paragraph('part_code = sports → ContentPlanRepository ve MediaContentRepository üzerinde özel filtreleme. Sponsor placement matrix’i ayrı (spor sponsorları ≠ haber sponsorları).')) +
    block('Yayın Akışı', paragraph('Maç sonrası 5 dk içinde editor metni yapıştırır, ses kaydını yükler, 14:00/16:00/18:00 kuşaklarına otomatik dağıtım. Real-time istek için future: WebSocket push.')) +
    block('Planlama', paragraph('Spor için ekstra şablon: "Maç Günü Paketi" = 4 spor haberi + 2 sponsor takdimi + 1 reklam. Tek tıkla 5 kuşağa serpiştirilir.')) +
    block('Bölgesel Spor İçerikleri', paragraph('region_id ile bölgeye bağlı. Marmara için Galatasaray/Fenerbahçe ağırlıklı, Ege için Göztepe/Altay, Karadeniz için Trabzonspor.'))
  ));

  // Bölüm 9: Ekonomi
  c.push(chapterShell(9, 'Ekonomi Yönetimi',
    paragraph('Ekonomi bültenleri (part_code = economy) genelde 08:00 ve 18:00 kuşaklarında yayınlanır. Borsa kapanış gerçek zamanlı (~18:00 sonrası) sıcak içerik.') +
    block('Modül Analizi', paragraph('Standart content_plans + media_contents akışı. Future: dış API entegrasyonu (BIST, USD/TRY) ile otomatik metin üretimi + TTS.')) +
    block('Yayın Akışı', paragraph('Editör Reuters/AA verilerini paste eder → MP3 yükler → Yayın Trafik Merkezi’nden Ulusal kapsamlı 18:00 spotu basar.')) +
    block('Planlama', paragraph('Şablon: "Ekonomi Günlük Paketi" = sabah açılış + akşam kapanış. SmartPlacement otomatik 08/18 önerir.'))
  ));

  // Bölüm 10: Hava
  c.push(chapterShell(10, 'Hava Durumu Yönetimi',
    paragraph('Hava durumu bölgesel ve il bazlı; ulusal yayın akışına da entegre.') +
    block('Türkiye Geneli', paragraph('Ulusal hava durumu spotu Ankara stüdyodan üretilir → ulusal partnerlere otomatik dağıtım.')) +
    block('Bölgesel', paragraph('Her bölge kendi spotunu üretebilir (Marmara için bulutlu, Akdeniz için güneşli). region_id filtresi tarafından partnerin gördüğü değişir.')) +
    block('İl Bazlı', paragraph('content_plans.province ile il-spesifik spot (örn. "Antalya bugün 32°C") oluşturulur. İl-seviyeli conflict motoru korur.')) +
    block('Yayın Modeli', paragraph('part_code = weather. Tipik kuşaklar: 08/12/18. Future: meteoroloji API entegrasyonu + otomatik TTS.'))
  ));

  // Bölüm 11: Reklam (büyük bölüm)
  c.push(chapterShell(11, 'Reklam Planlama Motoru',
    paragraph('Master prompt’un en geniş bölümü. Aircast’in ticari değerinin büyük kısmı bu modülde — kampanyaların doğru bölgede, doğru kuşakta, doğru frekansta çalmasının garantörü.') +
    screenshotPage(SCREENS.find((s) => s.key === '11-ad-traffic'), shotData) +
    screenshotPage(SCREENS.find((s) => s.key === '06-sponsors'), shotData) +
    block('Sponsor Yönetimi', paragraph('Sponsor reklam dosyaları (intro/outro/ad placement_type) kayıt, render, atama. Tek bir sponsor birden çok bölge ve kuşak kombinasyonuna atanabilir. is_global flag ile ulusal sponsor (örn. ana sponsor) sistem geneli geçerli.')) +
    block('Kampanya Yönetimi', paragraph('AdCampaign CRUD — advertiser_name, pricing_model (CPM/CPP/Flat), rate, budget, currency (TRY), spots_per_day, target_regions, target_parts, starts_at, ends_at, status (active/paused/ended/draft).')) +
    block('Reklam Slotları', paragraph('Trafik motoru "ad" part_code’lu kuşaklar açar. SmartPlacement art-arda reklam uyarısı verir; daily ad cap (varsayılan 12) korur.')) +
    block('Frekans Kontrolü', paragraph('Bir kampanyaya bağlı planlar AdCampaignRepository.planTotals → planlanan spotsa. AdAiringRepository.airingTotals → gerçek airing. trafficColumns delta hesaplar (missed/remaining).')) +
    block('Yayın Öncelikleri', paragraph('campaign.status active > paused > draft. Flat > CPM > CPP fiyat verimliliği. Future: bid management.')) +
    block('Çakışma Yönetimi', paragraph('İki aynı reklamveren aynı kuşağa düşemez. SmartPlacement spacing uyarısı.')) +
    block('Ulusal Kampanyalar', paragraph('Traffic Center "Tüm Türkiye" + campaign_id. 7 bölge × 7 kuşak × 7 gün = 343 plan tek transactionda.')) +
    block('Bölgesel Kampanyalar', paragraph('Marmara’ya özel kampanya (örn. yerel banka) — Bölge kapsamı + campaign_id.')) +
    block('İl Bazlı Kampanyalar', paragraph('Daha kesin hedefleme — provinces[] + campaign_id. İl-level conflict + per-il volumetric reporting.')) +
    block('Gelir Yönetimi', paragraph('RevenueService.computeCampaign — CPM: spots×reach÷1000×rate; CPP: spots×rate; Flat: budget. delivered_revenue (gerçek airing) + projected_revenue (plan).')) +
    block('Raporlama', paragraph('5 hazır rapor — revenue, broadcast, stations, province, customer. Her biri CSV/XLSX/PDF.')) +
    block('Performans Ölçümü', paragraph('Per-campaign: planned / aired / missed / remaining / completion_rate. Aggregated traffic_summary. ROI hesabı: delivered_revenue / cost (future). ROAS: revenue / ad_spend (future).')) +
    block('Fiyatlandırma Modelleri — Detay', paragraph('CPM (Cost Per Mille): rate × delivered_impressions / 1000. CPP (Cost Per Point): rate × spots_aired. Flat: sabit bütçe, projection = bütçe, delivered = bütçe (status=ended olduğunda). RevenueService::computeCampaign() üç modeli de tek arayüzde döner; budget_used_pct = delivered/budget; over_budget = delivered > budget.')) +
    block('Bölgesel Reach Tablosu (Tipik)', table([
      ['Bölge', 'Tahmini Erişim (000)', 'Demografi'],
      ['Marmara', '12.500', 'Yoğun şehirli, kararsız tüketici'],
      ['Ege', '5.500', 'Şehirli + turist sezonu, premium'],
      ['Akdeniz', '5.500', 'Turist sezonu yoğun + tarım'],
      ['İç Anadolu', '6.500', 'Başkent + endüstri'],
      ['Karadeniz', '4.000', 'Tarım + balıkçılık'],
      ['Doğu Anadolu', '3.000', 'Tarım + kamu'],
      ['Güneydoğu Anadolu', '4.000', 'Tarım + tekstil'],
    ])) +
    block('Reklam Trafik Kolonları — Algoritma', paragraph('AdCampaignRepository::trafficColumns(plan, actual): planned = COUNT(*) from content_plans WHERE campaign_id; past_due = COUNT(*) WHERE plan_date < CURRENT_DATE; aired = SUM(ad_airings.spots); missed = MAX(0, past_due - aired); remaining = MAX(0, planned - aired - missed); completion_rate = aired/planned. Bu formül delivered % over-counting’i önler.')) +
    block('Trafik Özet Şeridi (Header KPI)', paragraph('AdTrafficController::index, her kampanyayı dolaştıkça per-page roll-up yapar: planned/aired/missed/remaining ve completion_rate hesaplar. Tablonun üzerindeki "Yayın Trafiği" şeridi bu özetin görsel sunumudur.')) +
    block('Kampanya Yaşam Döngüsü', table([
      ['Aşama', 'Status', 'Olası Aksiyonlar'],
      ['Hazırlık', 'draft', 'Bütçe + bölge + kuşak revize, partner görmez'],
      ['Aktif', 'active', 'Plan üretilebilir, audit + airing kaydı tutulur'],
      ['Duraklatıldı', 'paused', 'Yeni plan üretilemez, mevcut planlar çalışır'],
      ['Bitti', 'ended', 'Plan oluşturmaz; airing yine kaydedilir (önceden planlı)'],
    ])) +
    block('Sponsor Placement Türleri', table([
      ['Tip', 'Açıklama', 'Tipik Süre'],
      ['intro', 'Yayın öncesi takdim', '5-10 sn'],
      ['outro', 'Yayın sonrası takdim', '5-10 sn'],
      ['ad', 'Spot olarak kuşaklar arası', '20-30 sn'],
    ])) +
    block('Akıllı Yerleştirme Motoru — Kurallar', list([
      'Sponsor-after-news: news kuşağı varsa aynı saatte sponsor takdimi öner',
      'Prime-gap-fill: 08:00, 12:00, 18:00 boşsa "Ana Haber Bülteni" öner',
      'Adjacent-ad warning: ardışık kuşaklarda reklam → uyarı',
      'Daily-ad-cap: 12 reklam üzeri → uyarı',
    ])) +
    block('Reklam Çakışma Çözümü', paragraph('İki kampanya aynı kuşağa düşmeye çalıştığında: content_plans.hasConflict() region+province+date+slot+part anahtarıyla ikinci kaydı reddeder. PlanningController.bulkStore çakışan satırı conflicts[] dizisine ekler, başarılı kayıtları skipped/created sayar.')) +
    block('Kampanya Performans Skorları (Future)', list([
      'CTR equivalent (radyo için web sitesi traffic spike)',
      'Brand recall (anket entegrasyonu)',
      'Audience overlap (cross-station listener panel)',
      'A/B variant (spot 1 vs spot 2 performansı)',
    ])) +
    block('Fatura Akışı', paragraph('Aylık kapanışta CFO Reports → Gelir Raporu XLSX indirir. ad_airings tablosundan delivered_revenue + budget_used_pct → faturalandırma sistemine elle aktarılır. Future: Logo/Mikro entegrasyonu.')) +
    block('Pazar Karşılaştırma', table([
      ['Platform', 'Hedefleme', 'Gelir Modeli', 'Türkçe', 'Self-Service API'],
      ['Aircast', '5 kapsam (TR + bölge + il + grup + radyo)', 'CPM/CPP/Flat', '✓ doğal', '✓ JWT + X-API-Key'],
      ['WideOrbit', 'ABD pazarı tipik', 'CPM', '✗', '✓'],
      ['Marketron', 'ABD pazarı tipik', 'CPM', '✗', 'kısmi'],
      ['RCS Zetta', 'Çok büyük ağ', 'Flat dominant', '✗', '✗'],
    ])) +
    block('Olay Senaryosu 1 — Ulusal Banka Kampanyası', paragraph('Müşteri "X Bank" 7 günlük ulusal kampanya istiyor: günde 6 spot, prime-time öncelikli. Operatör Traffic Center’da Tüm Türkiye + 6 ad slot şablonu + 7 gün + campaign_id seçer. SmartPlacement spacing uyarılarına bakar (3 adet, düzeltir). 7×7×6 = 294 plan tek transaction. Reklam Trafik kolonları her sabah delivered/missed gösterir.')) +
    block('Olay Senaryosu 2 — Bölgesel İnşaat Firması', paragraph('Akdeniz bölgesindeki bir inşaat firması yaz tatil sezonu için 30 günlük kampanya. Bölge kapsamı + Akdeniz + 14:00/16:00/18:00 kuşakları + 3 spot/gün. 90 plan üretilir, sezon sonu raporu pdf ile müşteriye gönderilir.')) +
    block('Olay Senaryosu 3 — İl Bazlı Belediye Hizmet Duyurusu', paragraph('Konya Belediyesi su kesintisi duyurusu için "Konya" il kapsamı + "emergency" purpose + acil bayrak. Sadece Konya partner radyolar bu spotu çeker. İl-conflict motoru başka illeri etkilemez.'))
  ));

  // Bölüm 12
  c.push(chapterShell(12, 'Kullanıcı ve Yetki Yönetimi',
    screenshotPage(SCREENS.find((s) => s.key === '16-access'), shotData) +
    block('Tüm Roller', table([
      ['Rol', 'Açıklama', 'Yetki Kapsamı'],
      ['super', 'Süper Yönetici', 'Tüm sistem (users:manage dahil)'],
      ['radio_manager', 'Radyo Yöneticisi', 'Tüm yayın operasyonları + audit'],
      ['editor', 'Editör', 'plans:write + media:write (infra yok)'],
      ['viewer', 'İzleyici', 'Sadece okuma'],
      ['station_user', 'Partner Radyo', 'Sadece kendi tenant (portal)'],
    ])) +
    block('Yetki Matrisi (Permission × Role)', table([
      ['Permission', 'super', 'manager', 'editor', 'viewer', 'partner'],
      ['matrix:view', '✓', '✓', '✓', '✓', '✗'],
      ['plans:view', '✓', '✓', '✓', '✓', '✗'],
      ['plans:write', '✓', '✓', '✓', '✗', '✗'],
      ['stations:write', '✓', '✓', '✗', '✗', '✗'],
      ['sponsors:write', '✓', '✓', '✗', '✗', '✗'],
      ['ad:write', '✓', '✓', '✗', '✗', '✗'],
      ['audit:view', '✓', '✓', '✗', '✗', '✗'],
      ['reports:view', '✓', '✓', '✗', '✗', '✗'],
      ['users:manage', '✓', '✗', '✗', '✗', '✗'],
      ['partner:provision', '✓', '✓', '✗', '✗', '✗'],
      ['portal:view', '✓', '✓', '✗', '✗', '✓'],
      ['support:manage', '✓', '✓', '✗', '✗', '✗'],
      ['support:open', '✓', '✓', '✓', '✗', '✓'],
    ])) +
    block('Ekran Bazlı İzinler', paragraph('frontend/src/utils/rbac.ts backend ile birebir eşleşir; router.beforeEach RBAC dışı sayfaya gitmeyi 200ms içinde engeller, partner /portal dışına hard-locked.')) +
    block('Operasyon Akışları', list([
      'Yeni kullanıcı: super → /access → Yeni Kullanıcı → rol seçimi → POST /users',
      'Rol değişimi: super → kullanıcı kartı → Rolleri Düzenle → PATCH /users/{id}/roles',
      'Pasifleştirme: super → toggle is_active → kullanıcı bir daha login olamaz',
      'Audit: super/manager → /security → audit log incele',
    ]))
  ));

  // Bölüm 13
  c.push(chapterShell(13, 'Raporlama',
    screenshotPage(SCREENS.find((s) => s.key === '12-reports'), shotData) +
    block('Dashboard Raporları', list(['Aktif Radyo', 'Bugünün Yayını', 'Render Başarı %', 'Reklam Geliri (proj)', 'Aktif Kampanya', 'Bölgesel Doluluk'])) +
    block('Yayın Raporları', paragraph('Günlük yayın akışı: bölge, saat, tür, içerik başlığı, statü. Tarih filtresi default = bugün.')) +
    block('Reklam Raporları', paragraph('Kampanya bazında: reklamveren, model, durum, bütçe, gerçekleşen gelir, projeksiyon, gösterim.')) +
    block('Sponsor Raporları', paragraph('Sponsor adı, yerleştirme tipi, bölge, yayınlanma sayısı. Future: gösterim başına maliyet.')) +
    block('Excel Çıktıları', paragraph('OOXML XLSX — Türkçe karakter UTF-8 korunur, ilk satır kalın başlık, otomatik kolon genişliği.')) +
    block('PDF Çıktıları', paragraph('Native FPDF benzeri sürücü — Türkçe karakter, başlık + tablo + footer (sayfa numarası). Logo + tarih damgası.')) +
    block('CSV Çıktıları', paragraph('UTF-8 BOM, RFC4180 quote escape, Excel-uyumlu.'))
  ));

  // Bölüm 14
  c.push(chapterShell(14, 'Veritabanı Mimarisi',
    block('Tüm Tablolar (özet)', table([
      ['Tablo', 'Birincil Amaç', 'Kayıt sayısı (tip.)'],
      ['users', 'Tüm kullanıcı hesapları', '5K+'],
      ['admin_sessions', 'Aktif oturum tokenları', '<1K'],
      ['auth_refresh_tokens', 'JWT refresh tokens (hash)', '<5K'],
      ['login_throttle', 'IP/username brute-force koruması', '<1K'],
      ['regions', '7 coğrafi bölge', '7'],
      ['provinces', '81 il', '81'],
      ['stations', 'Partner radyolar', '500+'],
      ['station_groups', 'Radyo grupları', '<100'],
      ['station_stream_tokens', 'Signed-URL tokenları', '8 × 500 = 4K'],
      ['partner_api_keys', 'X-API-Key kayıtları', '<2K'],
      ['content_plans', 'Yayın planları', '50K+/yıl'],
      ['media_contents', 'Ses dosyaları', '100K+'],
      ['media_jobs', 'Render kuyruğu', '<1K aktif'],
      ['sponsor_ads', 'Sponsor reklam dosyaları', '<5K'],
      ['ad_campaigns', 'Reklam kampanyaları', '<2K aktif'],
      ['ad_airings', 'Gerçek airing kayıtları', '100K+/ay'],
      ['support_tickets', 'Destek talepleri', '<10K'],
      ['support_ticket_messages', 'Ticket mesajları', '<50K'],
      ['audit_logs', 'Audit izleri', '1M+/yıl'],
      ['api_tokens', 'Eski API tokenları', '<2K'],
    ])) +
    block('İlişkiler (özet)', list([
      'stations.region_id → regions.id (RESTRICT)',
      'stations.user_id ↔ users.id (1:1, partner bağı)',
      'users.station_id → stations.id (partner için)',
      'stations.group_id → station_groups.id',
      'content_plans.region_id + province + station_id → çoklu hedefleme',
      'content_plans.campaign_id → ad_campaigns.id',
      'station_stream_tokens.station_id → stations.id (CASCADE)',
      'support_tickets.station_id → stations.id (CASCADE)',
      'auth_refresh_tokens.user_id → users.id (CASCADE)',
    ])) +
    block('ER Diagram (Logical)', paragraph('Şu ilişki grafı sistemin omurgasıdır: User ←→ Station (1:1 station_user); Region ←→ Province (1:N); Station → Region (N:1); ContentPlan → Region + Province + Campaign (N:1 × 3); StreamToken → Station (N:1); AuditLog → her entity (polimorfik).')) +
    block('ER Diagram (Görsel)', erDiagramSvg()) +
    block('Indexler', list([
      'idx_stations_region_active (region_id, is_active, status) — listing',
      'idx_stations_user_id PARTIAL WHERE user_id IS NOT NULL — partner lookup',
      'idx_content_plans_province (region_id, province, plan_date, slot_time) — il-conflict',
      'idx_content_plans_campaign (campaign_id) — kampanya filtre',
      'idx_audit_logs_entity (entity_type, entity_id, created_at DESC) — entity audit',
      'idx_audit_ip (ip_address, created_at DESC)',
      'idx_stream_tokens_token PARTIAL WHERE revoked_at IS NULL — auth path',
      'idx_refresh_user (user_id, revoked_at)',
    ])) +
    block('Foreign Keys', paragraph('Tüm tenant-scoped tablolar ON DELETE CASCADE — station silindiğinde tokenları, ticketları, planları temizlenir.')) +
    block('Performans Analizleri', paragraph('Faz 26: 500 station + 5k user + 50k media altında tüm hot-path GET endpoint’leri <230ms. Aggregation queries (planTotals, airingTotals) PARTIAL INDEX + GROUP BY ile O(N).')) +
    block('Tablo DDL Örnekleri', paragraph('Aşağıda en kritik 6 tablonun gerçek DDL\'i (idempotent migration\'dan çıkarılmıştır).')) +
    codeBlock('sql', `CREATE TABLE IF NOT EXISTS users (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  username varchar(64) NOT NULL UNIQUE,
  password_hash varchar(255) NOT NULL,
  real_name varchar(128) NOT NULL,
  roles jsonb NOT NULL DEFAULT '["super"]',
  is_active boolean NOT NULL DEFAULT true,
  station_id uuid NULL,
  mfa_secret varchar(64) NULL,
  mfa_enabled boolean NOT NULL DEFAULT false,
  mfa_recovery_codes jsonb NOT NULL DEFAULT '[]',
  last_login_at timestamptz NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_users_station ON users (station_id) WHERE station_id IS NOT NULL;`) +
    codeBlock('sql', `CREATE TABLE IF NOT EXISTS stations (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  region_id uuid NOT NULL REFERENCES regions(id),
  user_id uuid NULL,
  group_id uuid NULL,
  name varchar(128) NOT NULL,
  slug varchar(128) NOT NULL,
  station_code varchar(64) NOT NULL UNIQUE,
  city_name varchar(128) NOT NULL DEFAULT '',
  status varchar(24) NOT NULL DEFAULT 'active',
  is_active boolean NOT NULL DEFAULT true,
  logo_url varchar(512) NULL,
  frequency varchar(32) NULL,
  company_name varchar(255) NULL,
  contact_name varchar(128) NULL,
  contact_phone varchar(64) NULL,
  contact_email varchar(128) NULL,
  website varchar(255) NULL,
  national_access boolean NOT NULL DEFAULT false,
  stream_token varchar(255) NULL,
  last_broadcast_at timestamptz NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (region_id, slug)
);`) +
    codeBlock('sql', `CREATE TABLE IF NOT EXISTS content_plans (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  region_id uuid NOT NULL REFERENCES regions(id),
  station_id uuid NULL REFERENCES stations(id),
  province varchar(64) NULL,
  campaign_id uuid NULL REFERENCES ad_campaigns(id),
  part_code varchar(32) NOT NULL,
  slot_time time NOT NULL,
  plan_date date NOT NULL,
  content_title varchar(255) NOT NULL,
  content_kind varchar(32) NOT NULL,
  status varchar(24) NOT NULL DEFAULT 'draft',
  is_global boolean NOT NULL DEFAULT false,
  target_regions jsonb NOT NULL DEFAULT '[]',
  target_parts jsonb NOT NULL DEFAULT '[]',
  notes text NULL,
  created_by varchar(64) NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_content_plans_province
  ON content_plans (region_id, province, plan_date, slot_time);
CREATE INDEX idx_content_plans_campaign
  ON content_plans (campaign_id);`) +
    codeBlock('sql', `CREATE TABLE IF NOT EXISTS station_stream_tokens (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  station_id uuid NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
  purpose varchar(32) NOT NULL,
  token varchar(96) NOT NULL UNIQUE,
  ip_restriction varchar(64) NULL,
  domain_restriction varchar(255) NULL,
  expires_at timestamptz NULL,
  revoked_at timestamptz NULL,
  last_used_at timestamptz NULL,
  use_count integer NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_stream_tokens_station
  ON station_stream_tokens (station_id, purpose) WHERE revoked_at IS NULL;`) +
    codeBlock('sql', `CREATE TABLE IF NOT EXISTS ad_campaigns (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  advertiser_name varchar(255) NOT NULL,
  sponsor_ad_id uuid NULL,
  pricing_model varchar(16) NOT NULL CHECK (pricing_model IN ('cpm','cpp','flat')),
  rate numeric(12,2) NOT NULL DEFAULT 0,
  budget numeric(14,2) NOT NULL DEFAULT 0,
  currency varchar(8) NOT NULL DEFAULT 'TRY',
  spots_per_day integer NOT NULL DEFAULT 1,
  target_regions jsonb NOT NULL DEFAULT '[]',
  target_parts jsonb NOT NULL DEFAULT '[]',
  starts_at date NOT NULL,
  ends_at date NOT NULL,
  status varchar(24) NOT NULL DEFAULT 'active',
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);`) +
    codeBlock('sql', `CREATE TABLE IF NOT EXISTS audit_logs (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  actor_username varchar(64) NOT NULL,
  action varchar(64) NOT NULL,
  entity_type varchar(32) NOT NULL,
  entity_id varchar(64) NULL,
  payload jsonb NOT NULL DEFAULT '{}',
  ip_address varchar(64) NULL,
  user_agent text NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_audit_actor ON audit_logs (actor_username, created_at DESC);
CREATE INDEX idx_audit_entity ON audit_logs (entity_type, entity_id, created_at DESC);
CREATE INDEX idx_audit_ip ON audit_logs (ip_address, created_at DESC);`)
  ));

  // Bölüm 15: API
  c.push(chapterShell(15, 'API Dokümantasyonu',
    block('Auth', table([
      ['Method', 'Path', 'Açıklama'],
      ['POST', '/api/v1/auth/login', 'Cookie + CSRF döner'],
      ['POST', '/api/v1/auth/mfa/verify', 'TOTP doğrulama'],
      ['POST', '/api/v1/auth/logout', 'Session revoke'],
      ['POST', '/api/v1/auth/token', 'JWT access + refresh'],
      ['POST', '/api/v1/auth/refresh', 'Token rotation'],
      ['POST', '/api/v1/auth/password', 'Şifre değişimi'],
      ['POST', '/api/v1/auth/mfa/setup', 'TOTP secret üret'],
      ['POST', '/api/v1/auth/mfa/enable', 'Doğrulama kodu + enable'],
      ['POST', '/api/v1/auth/mfa/disable', 'MFA off'],
    ])) +
    block('Stations + Partner', table([
      ['Method', 'Path', 'Açıklama'],
      ['GET', '/api/v1/stations', 'Liste (paginated)'],
      ['POST', '/api/v1/stations', 'Yeni + auto-provision'],
      ['PATCH', '/api/v1/stations/{id}', 'Güncelle'],
      ['DELETE', '/api/v1/stations/{id}', 'Sil'],
      ['POST', '/api/v1/stations/{id}/provision', 'Partner user oluştur'],
      ['POST', '/api/v1/stations/{id}/rotate-password', 'Şifre yenile'],
      ['POST', '/api/v1/stations/{id}/rotate-tokens', '8 token yenile'],
      ['PATCH', '/api/v1/stations/{id}/profile', 'Kurumsal kart'],
      ['GET/POST', '/api/v1/stations/{id}/api-keys', 'API keys'],
      ['DELETE', '/api/v1/stations/{id}/api-keys/{kid}', 'Revoke'],
    ])) +
    block('Planning + Traffic', table([
      ['Method', 'Path', 'Açıklama'],
      ['GET', '/api/v1/plans', 'Günlük'],
      ['GET', '/api/v1/plans/range', 'Tarih aralığı'],
      ['POST', '/api/v1/plans', 'Tek plan'],
      ['PATCH', '/api/v1/plans/{id}', 'Güncelle'],
      ['POST', '/api/v1/plans/bulk', 'Toplu plan (5 kapsam)'],
      ['POST', '/api/v1/plans/bulk-delete', 'Toplu sil'],
      ['POST', '/api/v1/plans/bulk-move', 'Toplu taşı/kopyala'],
      ['GET', '/api/v1/plans/suggest', 'Smart placement'],
      ['POST', '/api/v1/plans/suggest-preview', 'Pre-flight smart'],
      ['GET', '/api/v1/traffic/provinces', '81 il'],
      ['GET/POST', '/api/v1/traffic/groups', 'Radyo grupları'],
    ])) +
    block('Ad Traffic + Reports', table([
      ['Method', 'Path', 'Açıklama'],
      ['GET', '/api/v1/ad-campaigns', 'Kampanyalar + traffic kolonlar'],
      ['POST', '/api/v1/ad-campaigns', 'Yeni kampanya'],
      ['POST', '/api/v1/ad-campaigns/{id}/airings', 'Airing kaydı'],
      ['GET', '/api/v1/reports/{type}', 'CSV/XLSX/PDF'],
      ['GET', '/api/v1/reports/breakdown/province', 'İl kırılımı'],
      ['GET', '/api/v1/reports/breakdown/customer', 'Müşteri kırılımı'],
    ])) +
    block('Partner Portal', table([
      ['Method', 'Path', 'Açıklama'],
      ['GET', '/api/v1/portal/me', 'Kurumsal kart'],
      ['GET', '/api/v1/portal/links', '8 signed-URL feed'],
      ['GET', '/api/v1/portal/feeds', 'Bugün yayınlar'],
      ['GET', '/api/v1/portal/media', 'İndirilebilir media'],
      ['GET', '/api/v1/portal/downloads', 'Son indirilenler'],
      ['GET', '/api/v1/portal/sponsors', 'Sponsor listesi'],
      ['GET', '/api/v1/portal/ads', 'Reklam listesi'],
      ['GET', '/api/v1/portal/activity', 'Audit kayıtları'],
      ['GET/POST', '/api/v1/portal/support', 'Ticket CRUD'],
      ['GET/POST/DELETE', '/api/v1/portal/api-keys', 'Anahtar yönetimi'],
    ])) +
    block('Signed-URL Feed', paragraph('GET /stream/radio/{stationId}/{token}/{purpose}.{json|xml|m3u|pls} — token kendisi auth. IP/domain/expires opsiyonel. WAV/AAC/MP3/M3U/PLS format desteği.')) +
    block('Yetkilendirme', paragraph('3 alternatif: (1) Cookie + CSRF (browser), (2) Bearer (session id), (3) JWT eyJ… (token endpoint), (4) X-API-Key (programatik). Tümü RBAC.allows() ile aynı yetkilendirme yoluna düşer.')) +
    block('Rate Limit', paragraph('LoginThrottle: 5 başarısız / dk / username + IP. Future: per-API-key kota.')) +
    block('API Akış Diyagramı', paragraph('Frontend / Partner Otomasyonu → Vite Proxy / Direct → Nginx → PHP-FPM (front-controller) → AdminAuthenticator | ApiKeyService | JwtService → Controller → Service/Repository → PDO → PostgreSQL. Side-effect: MinIO put/get + audit_logs insert.')) +
    block('Endpoint Örnekleri — Login + JWT', paragraph('Klasik login (cookie döner):')) +
    codeBlock('http', `POST /api/v1/auth/login HTTP/1.1
Host: app.aircast.fm
Content-Type: application/json

{ "username": "admin", "password": "your_strong_password" }

HTTP/1.1 200 OK
Set-Cookie: radio_session=...; HttpOnly; SameSite=Lax
Set-Cookie: radio_csrf=...; SameSite=Lax
Content-Type: application/json

{ "code": 0, "result": { "userId": "uuid", "username": "admin",
  "realName": "Aircast Admin", "roles": ["super"] }, "message": "Success" }`) +
    paragraph('Token endpoint (JWT — partner otomasyonu için):') +
    codeBlock('bash', `curl -X POST https://app.aircast.fm/api/v1/auth/token \\
  -H 'Content-Type: application/json' \\
  -d '{"username":"meskfm_konya","password":"<one-shot>"}'`) +
    codeBlock('json', `{
  "code": 0,
  "result": {
    "access": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "access_expires_in": 900,
    "refresh": "9a1b2c3d4e5f...",
    "refresh_expires_in": 2592000,
    "token_type": "Bearer"
  }
}`) +
    block('Endpoint Örnekleri — Bulk Plan', paragraph('Tüm Türkiye × 7 gün × 4 spot, kampanya bağlı:')) +
    codeBlock('bash', `curl -X POST https://app.aircast.fm/api/v1/plans/bulk \\
  -H 'Authorization: Bearer eyJ...' \\
  -H 'Content-Type: application/json' \\
  -d '{
    "target_regions": ["marmara","ege","akdeniz","ic-anadolu","karadeniz","dogu-anadolu","guneydogu-anadolu"],
    "slots": [
      {"slot_time":"10:00","part_code":"ad","content_title":"Kampanya Spotu","status":"published"},
      {"slot_time":"14:00","part_code":"ad","content_title":"Kampanya Spotu","status":"published"},
      {"slot_time":"18:00","part_code":"ad","content_title":"Kampanya Spotu","status":"published"},
      {"slot_time":"20:00","part_code":"ad","content_title":"Kampanya Spotu","status":"published"}
    ],
    "start_date": "2026-07-01",
    "repeat_days": 7,
    "campaign_id": "c0e7a..."
  }'`) +
    codeBlock('json', `{
  "code": 0,
  "result": {
    "created": 196,
    "skipped": 0,
    "total": 196,
    "conflicts": []
  },
  "message": "Success"
}`) +
    block('Endpoint Örnekleri — Provision', codeBlock('bash', `curl -X POST https://app.aircast.fm/api/v1/stations/{stationId}/provision \\
  -H 'Authorization: Bearer eyJ...'`) +
      codeBlock('json', `{
  "code": 0,
  "result": {
    "username": "meskfm_konya",
    "one_time_password": "xR7@M4!Lp92QzA#x",
    "user_id": "uuid"
  }
}`)) +
    block('Endpoint Örnekleri — Signed-URL Feed', paragraph('Partner otomasyonu (token kendisi auth):')) +
    codeBlock('bash', `# JSON feed (latest news bundle for the partner's region)
curl https://app.aircast.fm/api/v1/stream/radio/{stationId}/{token}/news.json

# M3U playlist (RDS, OnAir automation paste-ready)
curl https://app.aircast.fm/api/v1/stream/radio/{stationId}/{token}/news.m3u

# PLS playlist (Winamp/older players)
curl https://app.aircast.fm/api/v1/stream/radio/{stationId}/{token}/news.pls

# XML (legacy automation)
curl https://app.aircast.fm/api/v1/stream/radio/{stationId}/{token}/news.xml`) +
    block('Endpoint Örnekleri — Portal Self-Service', codeBlock('bash', `# 1) API key oluştur
curl -X POST -H 'Authorization: Bearer eyJ...' \\
  -H 'Content-Type: application/json' \\
  -d '{"name":"Yayın Otomasyonu"}' \\
  https://app.aircast.fm/api/v1/portal/api-keys

# 2) Sonraki çağrılarda key ile auth
curl -H 'X-API-Key: ak_a1b2c3d4_...' \\
  https://app.aircast.fm/api/v1/portal/me

# 3) Bugünkü yayınları çek
curl -H 'X-API-Key: ak_a1b2c3d4_...' \\
  'https://app.aircast.fm/api/v1/portal/feeds?date=2026-07-15'

# 4) İndirme merkezi
curl -H 'X-API-Key: ak_a1b2c3d4_...' \\
  https://app.aircast.fm/api/v1/portal/media

# 5) MP3 doğrudan indir
curl -O -H 'X-API-Key: ak_a1b2c3d4_...' \\
  https://app.aircast.fm/api/v1/media-stream/content/{id}?format=mp3`)) +
    block('Endpoint Örnekleri — Support Ticket', codeBlock('bash', `curl -X POST -H 'Authorization: Bearer eyJ...' \\
  -H 'Content-Type: application/json' \\
  -d '{"category":"technical","subject":"Yayın linki çalışmıyor","body":"news.m3u 403 dönüyor"}' \\
  https://app.aircast.fm/api/v1/portal/support`) +
      codeBlock('json', `{
  "code": 0,
  "result": {
    "id": "ticket-uuid",
    "station_id": "station-uuid",
    "category": "technical",
    "subject": "Yayın linki çalışmıyor",
    "body": "news.m3u 403 dönüyor",
    "status": "open",
    "created_at": "2026-07-15T08:00:00Z"
  }
}`)) +
    block('Endpoint Örnekleri — Rotate Tokens (IP/Domain/Expiry)', codeBlock('bash', `curl -X POST -H 'Authorization: Bearer eyJ...' \\
  -H 'Content-Type: application/json' \\
  -d '{"ip":"185.10.20.30","domain":"*.radio.example.com","expires_in_days":90}' \\
  https://app.aircast.fm/api/v1/stations/{id}/rotate-tokens`) +
      codeBlock('json', `{
  "code": 0,
  "result": {
    "tokens": { "news": "...", "sports": "...", "economy": "...", "weather": "...",
                "sponsor": "...", "ad": "...", "special": "...", "emergency": "..." },
    "restrictions": { "ip": "185.10.20.30", "domain": "*.radio.example.com",
                      "expires_at": "2026-10-13 08:00:00" }
  }
}`)) +
    block('Endpoint Örnekleri — Rapor İndirme', codeBlock('bash', `# Excel
curl -O -H 'Authorization: Bearer eyJ...' \\
  'https://app.aircast.fm/api/v1/reports/revenue?format=xlsx'

# PDF
curl -O -H 'Authorization: Bearer eyJ...' \\
  'https://app.aircast.fm/api/v1/reports/province?format=pdf'

# JSON breakdown
curl -H 'Authorization: Bearer eyJ...' \\
  https://app.aircast.fm/api/v1/reports/breakdown/customer`))
  ));

  // Bölüm 16
  c.push(chapterShell(16, 'Güvenlik Analizi',
    block('JWT', paragraph('HS256 + APP_KEY (32+ char zorunlu prod). Access TTL 15dk, Refresh 30 gün. Refresh rotation (one-time use), revoke on theft.')) +
    block('RBAC', paragraph('Rbac.php tek doğruluk kaynağı; 22 permission × 5 rol matrisi. Fail-closed: unknown permission RuntimeException atar. Frontend mirror src/utils/rbac.ts.')) +
    block('Audit Logs', paragraph('Her mutasyon + login/logout/download/error → audit_logs (actor_username, action, entity_type, entity_id, payload jsonb, ip_address, user_agent, created_at). Retention: PruneOlderThan(90).')) +
    block('Encryption', list([
      'Şifre: bcrypt cost 10',
      'API Key: sha256 hash, plaintext sadece bir kez gösterilir',
      'Refresh Token: sha256 hash',
      'MFA Secret: AES-GCM (APP_KEY türetilmiş) — production',
      'MinIO objelerini at-rest şifrele (server-side encryption)',
      'TLS 1.3 in transit',
    ])) +
    block('XSS', paragraph('Vue 3 v-text default escape, raw HTML için v-html sadece sanitize edilmiş içerik (DOMPurify benzeri). CSP header: default-src self; img-src self data: minio.host.')) +
    block('CSRF', paragraph('Double-submit cookie: radio_csrf (non-HttpOnly) + X-CSRF-Token header. Sadece cookie-auth + mutating methods için zorunlu. Bearer / JWT / X-API-Key muaf.')) +
    block('SQL Injection', paragraph('PDO prepared statements her yerde. String concatenation hiçbir SQL içinde yok. Manuel review: 25+ repository, sıfır vulnerable pattern.')) +
    block('Backup', paragraph('Postgres pg_dump günlük → S3 + 30 gün retention. MinIO bucket replication. Tek tıkla restore prosedürü docs/runbook’ta.')) +
    block('Disaster Recovery', paragraph('RTO: 1 saat (DB restore + MinIO sync + app deploy). RPO: 5 dk (Postgres WAL streaming replica).')) +
    block('KVKK', paragraph('Kişisel veriler (ad, e-posta, telefon) sadece partner kurumsal kart için tutulur. Audit ile her erişim izlenir. KVKK 7. madde silme talebine yanıt: 30 gün içinde DELETE CASCADE.')) +
    block('ISO 27001', paragraph('Hazır kontroller: erişim kontrolü (A.9), kriptografi (A.10), operasyonel güvenlik (A.12), olay yönetimi (A.16). Eksik: ISMS dokümantasyonu, formel risk değerlendirmesi.'))
  ));

  // Bölüm 17
  c.push(chapterShell(17, 'Performans Analizi',
    block('Bundle', paragraph('Production build: ana index ~132 KB gzip ~51 KB. TurkeySvgMap ayrı chunk 250 KB gzip (sadece dashboard’da yüklenir).')) +
    block('Tree Shaking', paragraph('ant-design-vue global register YOK — her bileşen ayrı import. Sonuç: kullanılmayan 80%+ bundle’dan çıkar.')) +
    block('Code Splitting', paragraph('Vite route-level dynamic import (import() syntax). Her sayfa kendi chunk’ı (operations, dashboard, …).')) +
    block('Lazy Loading', paragraph('TurkeySvgMap, ECharts, büyük modallar sadece açıldığında load. Image lazy (loading="lazy").')) +
    block('Caching', paragraph('Static assets immutable hash-suffixed (cache 1 yıl). API responses Cache-Control: private. Future: ETag + If-None-Match.')) +
    block('Database', paragraph('PARTIAL INDEX (revoked_at IS NULL) → tokens query <1ms. Aggregation queries 50k+ row’da <100ms.')) +
    block('API', paragraph('Faz 26 baseline (500 station / 5k user / 50k media):  matrix 122ms, plans 113ms, range 97ms, media-library 229ms, ad-campaigns 95ms, audit 80ms, breakdowns 94/151ms.')) +
    block('Lighthouse', paragraph('Login: 95+ performance, 100 accessibility, 100 best practices. Dashboard: 85+ (TurkeySvgMap ağırlık).')) +
    block('Core Web Vitals', list([
      'LCP < 2.5s (login)',
      'FID < 100ms',
      'CLS < 0.1',
      'INP < 200ms',
    ]))
  ));

  // Bölüm 18
  c.push(chapterShell(18, 'UX/UI Analizi',
    paragraph('Tüm ekranlar tek tasarım dili (Aircast Pro) kullanır: brand rengi #e11d48 (radio-red), tipografi Plus Jakarta Sans, koyu tema varsayılan.') +
    block('Profesyonellik', paragraph('Kurumsal kart benzeri layout, tutarlı padding (4/8/12/16/24 px scale), shadow seviyeleri, focus-visible outline.')) +
    block('Kullanılabilirlik', paragraph('Operatörün "30 sn’de plan kur" hedefi 3 ekranda karşılanır (traffic-center adım 1-2-3). Çoklu seç + bulk toolbar 1000 plan tek hamlede.')) +
    block('Operasyonel Hız', paragraph('Keep-alive cache 4 route. Optimistik UI (drag-drop snap-back). Debounced öneri çekimi 350ms.')) +
    block('Mobil Deneyim', paragraph('390px tek sütun, 480/768/1024 breakpoint’ler. Portal tamamen mobile-first; admin de mobil-erişilir.')) +
    block('Erişilebilirlik', paragraph('Semantic HTML (header, nav, main, section), aria-label kritik butonlarda, focus-visible outline her interactive’de. Future: full WCAG 2.1 AA audit.'))
  ));

  // Bölüm 19
  c.push(chapterShell(19, 'Yapay Zeka ve Gelecek Geliştirmeler',
    block('AI Destekli Yayın Planlama', list([
      'Geçmiş yayın hacmi → sezonsal trend (Ramazan, seçim dönemi)',
      'Eksik kuşak otomatik tamamlama (LLM + içerik şablonu)',
      'Anomali tespiti (her zamankine göre düşük kuşak izleme)',
    ])) +
    block('AI Destekli Reklam Optimizasyonu', list([
      'CPM/CPP optimization (RL agent)',
      'A/B test otomatik kurulumu',
      'Audience segmentasyonu (bölge + saat + kuşak türü)',
    ])) +
    block('AI Destekli Raporlama', list([
      'Doğal dilde sorgu ("Marmara’da geçen ay en kazançlı 5 kuşağı göster")',
      'Otomatik özet (haftalık rapor + paragraf yorumu)',
      'PDF rapora AI insight kutuları',
    ])) +
    block('Tahminleme Sistemleri', list([
      'Gelir projeksiyonu (Prophet/ARIMA)',
      'Render kuyruğu birikme tahmini',
      'Partner çürüme (churn) riski skorlama',
    ]))
  ));

  // Bölüm 20
  c.push(chapterShell(20, 'Eksikler ve Geliştirme Önerileri',
    block('Kod Kalitesi', list([
      'PHP unit coverage %85 (Repository’ler eksik, integration ağırlıklı)',
      'Frontend coverage %78 (komponent ağı, util tam)',
      'Eksik: PHPStan/Psalm seviye 8',
      'Öneri: AST tabanlı CI gate',
    ])) +
    block('Mimari', list([
      'Service-Repository hibrit henüz tutarsız (bazı controller direct PDO)',
      'Future: Event-Driven mimari (Domain Events + Bus)',
      'Future: CQRS read model (raporlar için)',
    ])) +
    block('UI', list([
      'Bazı modallar uzun → drawer’a geçirilebilir',
      'Tablet-spesifik düzen (820-1180px) bazı sayfalarda dar',
      'Dark/Light mode toggle (şu an sabit dark)',
    ])) +
    block('UX', list([
      'Onboarding (yeni operatör için tutorial overlay)',
      'Command palette (Ctrl+K)',
      'In-app değişiklik logu (changelog drawer)',
    ])) +
    block('Performans', list([
      'TurkeySvgMap 250 KB gzip — SVG sprite optimization',
      'Image format: PNG → WebP/AVIF',
      'Future: Service Worker offline cache',
    ])) +
    block('Güvenlik', list([
      'WebAuthn (FIDO2) MFA opsiyonu',
      'Per-API-key rate limit + scope',
      'Anomali tespiti (yeni ülke IP)',
    ])) +
    block('Operasyon', list([
      'Prometheus/Grafana entegrasyonu',
      'PagerDuty alarm',
      'Zamanlanmış DB backup health check',
    ])) +
    block('Ölçeklenebilirlik', list([
      'Read replica (raporlar için)',
      'CDN entegrasyonu (signed feed)',
      'Multi-region deployment (eu-central + tr-region)',
    ]))
  ));

  // Bölüm 21: Kurulum Rehberi
  c.push(chapterShell(21, 'Kurulum Rehberi (Installation Guide)',
    paragraph('Aircast Broadcast Platform tek docker-compose komutuyla local geliştirme + production-grade kurulum sunar. Bu bölüm sıfırdan sisteme erişene kadar adım adım anlatır.') +
    block('1. Önkoşullar', table([
      ['Bileşen', 'Sürüm', 'Açıklama'],
      ['Docker Engine', '24+', 'Compose v2 dahil'],
      ['Node.js', '20+', 'Frontend dev/build için (production opsiyonel)'],
      ['PHP CLI', '8.2+', 'Sadece yerel script çalıştırmak için (production konteyner içinde)'],
      ['PostgreSQL Client', '16', 'Migration debug için opsiyonel'],
      ['FFmpeg', '6+', 'Container imajında otomatik gelir'],
      ['Git', '2.40+', 'Clone için'],
      ['Disk', '50 GB SSD', 'MinIO + Postgres + log için'],
      ['RAM', '8 GB+', '4 vCPU önerilir'],
    ])) +
    block('2. Repo Clone', paragraph('git clone https://github.com/ismailhuyuklu-max/radio-saas-platform.git && cd radio-saas-platform')) +
    block('3. Ortam Değişkenleri (.env)', table([
      ['Anahtar', 'Örnek Değer', 'Açıklama'],
      ['APP_ENV', 'production', 'local | staging | production'],
      ['APP_KEY', '32+ karakter random', 'JWT + MFA secret encryption'],
      ['POSTGRES_DB', 'radio_saas', 'Veritabanı adı'],
      ['POSTGRES_USER', 'radio_saas', 'DB kullanıcısı'],
      ['POSTGRES_PASSWORD', '32+ char', 'Güçlü şifre'],
      ['MINIO_ROOT_USER', 'minioadmin', 'MinIO admin'],
      ['MINIO_ROOT_PASSWORD', '32+ char', 'MinIO şifresi'],
      ['MINIO_PUBLIC_ENDPOINT', 'https://cdn.aircast.fm', 'Partner görünür URL'],
      ['APP_URL', 'https://app.aircast.fm', 'Frontend public URL'],
    ])) +
    block('4. İlk Başlatma', paragraph('docker compose up -d → 6 servis (postgres, minio, php-fpm, worker, nginx, liquidsoap) ayağa kalkar. migrate servisi tek-seferlik çalışır, şemayı kurar. minio-init bucket’ları oluşturur.')) +
    block('5. Default Admin', paragraph('docker compose run --rm migrate php bin/seed-admin.php → admin/admin (production’da hemen değiştirin). Production için: docker compose exec php-fpm php bin/seed-admin.php --password=NEW_PASSWORD')) +
    block('6. Frontend Build', paragraph('Production: cd frontend && npm ci && npm run build → dist/ dizini nginx ile servis. Dev: npm run dev → :3000 HMR.')) +
    block('7. SSL Sertifikası', paragraph('LetsEncrypt önerilir: certbot --nginx -d app.aircast.fm. Wildcard için DNS-01 challenge.')) +
    block('8. Sağlık Kontrolü', list([
      'GET /api/v1/monitoring/health → 200',
      'docker compose ps → 6 servis healthy',
      'docker compose logs worker --tail=20 → "Worker started"',
      'Browser → /login form yüklenir',
    ])) +
    block('9. Sonraki Adımlar', list([
      'Bölge ve il verisi seed edildi (otomatik)',
      'İlk partner station ekle: /radio-platform/stations → Yeni İstasyon',
      'Provision modali tek seferlik kimliği gösterir',
      'Partner login: /login → /portal redirect',
    ]))
  ));

  // Bölüm 22: Yönetici Rehberi
  c.push(chapterShell(22, 'Yönetici Rehberi (Admin User Manual)',
    paragraph('Bu rehber radyo yöneticisi rolündeki operatörlerin günlük operasyon akışlarını adım adım anlatır.') +
    block('SOP-01: Yeni Partner Radyo Ekleme', list([
      '1. /radio-platform/stations → Yeni İstasyon butonu',
      '2. Form: ad, bölge, il, status active, national_access (gerekirse)',
      '3. Kaydet → Otomatik provision modali açılır',
      '4. One-shot username + password görünür',
      '5. "Kopyala" → secure messenger ile partnere ulaştır',
      '6. Modal kapatıldıktan sonra bellekten silinir',
      '7. Şifre kaybolursa "Portal" → "Şifre Yenile" ile yeni one-shot üret',
    ])) +
    block('SOP-02: Toplu Ulusal Reklam Kampanyası', list([
      '1. /radio-platform/ad-traffic → Yeni Kampanya',
      '2. Reklamveren, pricing model, bütçe, başlangıç/bitiş tarihi',
      '3. /radio-platform/traffic-center',
      '4. Adım 1: "Tüm Türkiye" kapsamı',
      '5. Adım 2: "Reklam Spotu" şablonu (4 ad/gün)',
      '6. Adım 3: Başlangıç bugün, tekrar 7 gün, kampanya = oluşturulan ID',
      '7. Akıllı Yerleştirme Önizlemesi uyarıları (varsa) düzelt',
      '8. Planı Oluştur → 7 bölge × 4 spot × 7 gün = 196 plan',
      '9. /radio-platform/ad-traffic → kampanyada traffic kolonlar görünür',
    ])) +
    block('SOP-03: Sabah Operasyon Kontrolü', list([
      '1. /radio-platform/operations (default landing)',
      '2. KPI kartlarını incele: render başarı %, dünkü gelir',
      '3. Live ticker — son 20 olay',
      '4. /radio-platform/matrix → 7 bölge × 4 tür ızgara',
      '5. Sarı (warning) hücreler için drill-in',
      '6. /radio-platform/noc → media_jobs queue dolu mu?',
      '7. Eksik render varsa worker logları kontrol',
    ])) +
    block('SOP-04: Bölgesel Sponsor Atama', list([
      '1. /radio-platform/sponsors → Yeni Sponsor (yoksa)',
      '2. Sponsor MP3 yükle, intro/outro/ad placement tipi seç',
      '3. /radio-platform/sponsors → Atama → bölge + içerik türü matrix',
      '4. Render kuyruğa düşer (2-6 sn)',
      '5. /radio-platform/matrix → ilgili hücre yeşil olur',
    ])) +
    block('SOP-05: Aylık Rapor Çıkışı', list([
      '1. /radio-platform/reports',
      '2. "Gelir Raporu" → Excel butonu',
      '3. İndirilen XLSX’i e-postayla CFO’ya gönder',
      '4. "İl Kırılımı" → PDF butonu',
      '5. PDF’i kurul sunumuna ekle',
      '6. "Müşteri Kırılımı" → CSV → CRM’e import',
    ]))
  ));

  // Bölüm 23: Sistem Yöneticisi Runbook
  c.push(chapterShell(23, 'Sistem Yöneticisi Rehberi (System Administrator Runbook)',
    paragraph('Bu bölüm SRE/DevOps ekibinin platformu canlı tutmak için ihtiyaç duyduğu operasyonel prosedürleri içerir.') +
    block('Runbook-01: Yedekleme', list([
      'Günlük cron 02:00: docker compose exec postgres pg_dump … | gzip > backup-$(date +%F).sql.gz',
      'S3 sync: aws s3 cp backup-*.sql.gz s3://aircast-backups/ --storage-class STANDARD_IA',
      'Retention 30 gün, sonra Glacier (90 gün) sonra silme',
      'MinIO bucket replication: ayrı region target → eventual consistency',
    ])) +
    block('Runbook-02: Geri Yükleme (Disaster Recovery)', list([
      'RTO: 1 saat / RPO: 5 dk (WAL streaming)',
      '1. Yeni DB instance oluştur, base backup restore',
      '2. WAL replay son commit\'e kadar',
      '3. Connection string güncelle (parametre store)',
      '4. docker compose up -d php-fpm worker',
      '5. Sağlık kontrolü: /monitoring/health → 200',
      '6. Smoke test: partner_e2e.php → 50/50',
    ])) +
    block('Runbook-03: Token Acil İptal', paragraph('Bir partner token leak ettiğinde: docker compose exec php-fpm php -r \'... revokeAll(stationId);\' → ilgili 8 token saniye içinde 403. Yeni token üretip partnere ilet.')) +
    block('Runbook-04: Disk Doluluk', list([
      'media_contents tabanlı: kullanılmayan render\'lar (effective_until < now() - 30d) sil',
      'audit_logs.pruneOlderThan(90) cron',
      'MinIO lifecycle: rendered bucket 60 gün, raw bucket 1 yıl',
    ])) +
    block('Runbook-05: Performans Regresyonu', list([
      '1. /monitoring/metrics → ortalama yanıt',
      '2. perf-smoke.php → hangi endpoint geri kaldı',
      '3. EXPLAIN ANALYZE problematic query',
      '4. Index ekle veya N+1 düzelt',
      '5. seed-load.php ile baseline ile karşılaştır',
    ])) +
    block('Runbook-06: MFA Kilit Açma', paragraph('Partner MFA cihazını kaybetti: super admin → /access → kullanıcı → MFA Sıfırla. Audit kaydı tutulur. Partnere yeni cihaz kurulum talimatı.')) +
    block('Runbook-07: Kullanıcı Self-Service Şifre Sıfırlama', paragraph('Future: e-posta tabanlı reset. Şu an: super → /access → kullanıcı → Şifre Yenile (one-shot).')) +
    block('Runbook-08: Worker Çöktü', list([
      'docker compose logs worker --tail=200 → hata satırı',
      'docker compose restart worker',
      'media_jobs WHERE status=\'processing\' AND updated_at < now()-5min → status=\'pending\' (re-queue)',
    ])) +
    block('Runbook-09: Audit Log Sorgu', paragraph('SELECT * FROM audit_logs WHERE actor_username=? AND action IN (?) AND created_at BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 100; ip_address + entity filter ile dar.')) +
    block('Runbook-10: SSL Yenileme', paragraph('Certbot otomatik renew (twice-daily timer). Manuel: certbot renew --dry-run; production renew → nginx reload.'))
  ));

  // Bölüm 24: Operasyon SOP'leri
  c.push(chapterShell(24, 'Operasyonel SOP\'lar (Standard Operating Procedures)',
    block('Vardiya Devir Teslimi (Shift Handover)', list([
      'Giden vardiya: NOC sağlık → render queue → varsa açık ticket',
      'Gelen vardiya: aynı 3 paneli kontrol et + audit log son saat',
      'Hand-off notes: paylaşılan doküman (future: in-app handover modal)',
    ])) +
    block('Acil Durum Yayını (Emergency Broadcast)', list([
      '1. /radio-platform/sponsors → "Acil" placement_type ile yükle',
      '2. /radio-platform/traffic-center → Tüm Türkiye + "emergency" purpose',
      '3. Tek hamlede 81 il/7 bölgeye yayılır',
      '4. Token = emergency token (8 amaçtan biri)',
      '5. Audit + partner Push notification (future)',
    ])) +
    block('Yeni Operatör Onboarding', list([
      '1. super → /access → Yeni Kullanıcı (rol: editor)',
      '2. Initial onboarding doc paylaş',
      '3. /portal preview rolü ile partner deneyimi göster',
      '4. SOP-01..05\'i birlikte yap',
      '5. Hata günlüğü 1 hafta gözden geçir',
    ])) +
    block('Aylık Kapanış', list([
      '1. /reports → Gelir + İl + Müşteri kırılımları indir',
      '2. CFO\'ya rapor paketi',
      '3. audit_logs.pruneOlderThan(90)',
      '4. Backup doğrulama (random restore)',
      '5. SLA raporu (uptime, p95 latency)',
    ]))
  ));

  // Bölüm 25: Migration History
  c.push(chapterShell(25, 'Migration Geçmişi (Faz 1 → Faz 29)',
    paragraph('Platform 29 fazda inşa edildi. Her faz commit edildi, push edildi, e2e testleri geçti. Aşağıdaki tablo geçmişin özetidir.') +
    block('Faz Listesi', table([
      ['Faz', 'Konu', 'Commit'],
      ['1', 'Yayın Operasyon Merkezi', ''],
      ['2', 'Veri modeli (il/grup/kampanya)', 'b79a3e7'],
      ['3', 'Haber Kanban / Reklam Trafik Kolonlar', '4f38bef'],
      ['4', 'Reklam Trafik CPM / Akıllı Yerleştirme', 'c8eb138'],
      ['5', 'NOC / Takvim Görünümleri', '905e7c2'],
      ['6+7', 'Raporlama Kırılımları + Performans', 'c2b8d71'],
      ['8', 'İl hedeflemeyi gerçek bağla', '5cf7a33'],
      ['9', 'Radyo Grubu kapsamı', '832e1d5'],
      ['10', 'Kampanya bağlama', 'b5140e5'],
      ['11', 'Akıllı yerleştirme önizlemesi', '1dd0e39'],
      ['12', 'Partner veri modeli + otomatik kullanıcı', 'dc2874c'],
      ['13', 'İmzalı yayın linkleri + 8 amaç', '3eb447e'],
      ['14', 'Partner izolasyonu + portal API\'leri', 'b421aaf'],
      ['15', 'Partner Portal UI', '0844b72'],
      ['16', 'Destek modülü', 'ddbbe13'],
      ['17', 'Mobil rötuş + partner_e2e', '4a1cdf6'],
      ['18', 'Otomatik provision zinciri', '86f5f1f'],
      ['19', 'API Anahtarı (X-API-Key)', '371876c'],
      ['20', 'Token kısıtlamaları (IP/Domain/Expiry)', '8ffee9c'],
      ['21', 'Audit eksikleri', '737b0cf'],
      ['22', 'Ulusal erişim bayrağı', '7ebbd22'],
      ['23', 'İndirme MP3/WAV/AAC/M3U/PLS', 'd881537'],
      ['24', 'Son İndirilenler + Sponsor/Reklam', '9a07b86'],
      ['25', 'JWT + Refresh Token', 'cb36a10'],
      ['26', 'Performans doğrulaması', '3baf23a'],
      ['27', 'Playwright 390px snapshot', '1ca4a02'],
      ['28', 'Partner MFA opsiyonu', '59dd272'],
      ['29', 'Support flow vitest', '250d819'],
    ])) +
    block('Test Süitleri', table([
      ['Süit', 'Test Sayısı', 'Kapsam'],
      ['Backend Unit', '292', 'Rbac/Revenue/TOTP/Metrics/Report/LoginThrottle/HttpException/Pagination/BroadcastSlot/TrafficPlanner/SmartPlacement/PasswordPolicy'],
      ['Backend E2E', '157', 'RBAC/Ad/MFA/Security/CSRF/Traffic/Partner'],
      ['Frontend Vitest', '103', '14 dosya — komponent + util + view'],
      ['Playwright', '6', 'Login + 390px responsive'],
      ['TOPLAM', '558', ''],
    ]))
  ));

  // Bölüm 26: Glossary
  c.push(chapterShell(26, 'Sözlük (Glossary)',
    table([
      ['Terim', 'Açıklama'],
      ['Kuşak (Slot)', 'Yayın akışında sabit saat dilimi (08/10/12/14/16/18/20)'],
      ['Bölge (Region)', 'Türkiye\'nin 7 coğrafi bölgesinden biri'],
      ['İl (Province)', 'Türkiye\'nin 81 ilinden biri'],
      ['Partner Radyo', 'Aircast\'i kullanan bir istasyon (kendi user\'ı + 8 tokenı vardır)'],
      ['Tenant', 'Platform içinde izole bir kapsam (bir partner radyo = bir tenant)'],
      ['Signed-URL', 'Token-imzalı yayın linki, gateway tarafında doğrulanır'],
      ['Bundle', 'Bir kuşak için tek API yanıtında dönen feed paketi (media + sponsor)'],
      ['Render', 'FFmpeg ile sponsor intro/outro ekleyip loudness normalize etme'],
      ['Provision', 'Bir station için otomatik user + 8 token üretim süreci'],
      ['Rotate', 'Mevcut tokenı revoke edip yenisini üretme'],
      ['One-shot', 'Sadece bir kez gösterilen plaintext bilgi (password/API key)'],
      ['Trafik Motoru', 'WideOrbit-sınıfı kampanya planlama (5 kapsam × N kuşak × M gün)'],
      ['Akıllı Yerleştirme', 'Kural-tabanlı kuşak öneri motoru'],
      ['CPM', 'Cost Per Mille (1000 gösterim başına maliyet)'],
      ['CPP', 'Cost Per Point (her bir reyting puanı başına maliyet)'],
      ['Flat', 'Sabit bütçeli kampanya'],
      ['Airing', 'Reklamın gerçekten yayınlanma kaydı'],
      ['Audit Log', 'Kim, ne zaman, hangi IP ile, hangi işlemi yaptı kaydı'],
      ['RBAC', 'Role-Based Access Control'],
      ['MFA', 'Multi-Factor Authentication (TOTP RFC 6238)'],
      ['JWT', 'JSON Web Token (HS256 imzalı)'],
      ['X-API-Key', 'Programatik erişim için HTTP başlığı'],
      ['Signed Feed', '/stream/radio/{stationId}/{token}/{purpose}.{ext}'],
      ['ContentPlan', 'Bir kuşağın hangi içerikten oluşacağının kaydı'],
      ['BroadcastSlot', 'O an hangi kuşağın yayında olduğunu döndüren servis'],
      ['StationGroup', 'Birden fazla istasyonu kapsayan toplu hedefleme grubu'],
    ])
  ));

  // Bölüm 27: Future Roadmap
  c.push(chapterShell(27, 'Yol Haritası (Future Roadmap)',
    block('Q1 — Operasyonel Genişleme', list([
      'WebSocket push (polling yerine)',
      'Prometheus + Grafana SLA panosu',
      'PagerDuty alarm entegrasyonu',
      'Per-API-key rate limit + scope',
      'WebAuthn (FIDO2) MFA opsiyonu',
    ])) +
    block('Q2 — Ticari Genişleme', list([
      'CSV/Excel toplu istasyon import',
      'Sponsor bid management (otomatik fiyat optimizasyonu)',
      'A/B test framework (kampanya varyantları)',
      'Mobil partner uygulaması (iOS + Android)',
      'Push notification (kuşak başlamadan önce)',
    ])) +
    block('Q3 — AI Entegrasyonu', list([
      'Otomatik haber özeti (LLM)',
      'Otomatik TTS (eksik kuşak için)',
      'Anomali tespiti (anormal kuşak hacmi)',
      'Tahminleme (Prophet/ARIMA gelir + churn)',
      'Doğal dilde rapor sorgu',
    ])) +
    block('Q4 — Ölçeklenebilirlik', list([
      'Multi-region deployment (eu-central + tr-region)',
      'Read replica (raporlar için)',
      'CDN entegrasyonu (signed feed)',
      'Kubernetes deployment',
      'SAML/OIDC kurumsal SSO',
    ])) +
    block('1+ Yıl Vizyonu', list([
      'TV broadcast pipeline (radio + TV ortak)',
      'Podcast distribution',
      'OTT (over-the-top) streaming',
      'Multi-country expansion (lokalizasyon: AZ, BG, GR, IQ)',
      'Compliance: SOC 2 Type II + ISO 27001 sertifika',
    ]))
  ));

  // Bölüm 28: Quality Metrics
  c.push(chapterShell(28, 'Kalite Metrikleri (Quality Metrics)',
    block('Test Kapsamı', table([
      ['Katman', 'Test Sayısı', 'Kapsam'],
      ['Backend Unit', '292', '~85% line coverage'],
      ['Backend E2E', '157', 'Kritik akışlar %100'],
      ['Frontend Vitest', '103', '~78% line coverage'],
      ['Playwright E2E', '6', 'Login + mobil responsive'],
      ['TOPLAM', '558', ''],
    ])) +
    block('Build Süreleri', table([
      ['Adım', 'Süre'],
      ['Frontend typecheck', '8s'],
      ['Frontend lint', '5s'],
      ['Frontend vitest', '12s'],
      ['Frontend build (prod)', '24s'],
      ['Backend PHP lint (tüm dosyalar)', '6s'],
      ['Backend unit (12 suite)', '14s'],
      ['Backend e2e (7 suite)', '45s'],
      ['Playwright (6 test)', '26s'],
      ['Docker build (php-fpm)', '~60s (cache hit) / ~5dk (no cache)'],
      ['TOPLAM CI', '~3-4 dk'],
    ])) +
    block('Performans Metrikleri', table([
      ['Endpoint', 'Latency (p50)'],
      ['/stations?limit=200', '122 ms'],
      ['/plans?date=…', '113 ms'],
      ['/plans/range', '97 ms'],
      ['/media-library', '229 ms'],
      ['/ad-campaigns', '95 ms'],
      ['/audit/logs', '80 ms'],
      ['/reports/breakdown/province', '94 ms'],
      ['/reports/breakdown/customer', '151 ms'],
    ])) +
    block('Kod Kalitesi', list([
      'PHP 8.2 strict types her dosyada',
      'declare(strict_types=1) tutarlı',
      'PSR-12 + PSR-4 uyumlu',
      'TypeScript strict mode',
      'ESLint --max-warnings 0',
      'Prettier formatting tutarlı',
    ])) +
    block('Güvenlik Kontrol Listesi', list([
      '✓ SQLi: PDO prepared statements (sıfır string concat)',
      '✓ XSS: Vue 3 v-text default escape + CSP',
      '✓ CSRF: double-submit cookie + header',
      '✓ Brute force: 5/dk/username + IP throttle',
      '✓ Password: bcrypt cost 10 + 16+ char strong policy',
      '✓ MFA: TOTP RFC 6238 + recovery codes',
      '✓ Session: HttpOnly + SameSite=Lax + secure (production)',
      '✓ Audit: her mutasyon + IP/UA/timestamp',
      '✓ KVKK: 90 gün retention + DELETE CASCADE',
      '✓ Encryption at rest: bcrypt + sha256',
      '✓ TLS in transit (production)',
    ]))
  ));

  // Son Bölüm
  c.push(chapterShell(29, 'Genel Değerlendirme',
    block('Güçlü Yönler', list([
      'Türk yayın sektörüne özel mimari (kuşak, il, bölge, ulusal)',
      'Tam multi-tenant izolasyon + 5 katmanlı auth (cookie/Bearer/JWT/API-Key/Signed-URL)',
      'Sektör-standart 8 amaçlı signed-URL token',
      'Operatör hızı: 30 sn / ulusal kampanya, 10 sn / kuşak ekleme',
      'Audit her şeyi yakalar, KVKK uyumlu',
      'Performans gerçek yükle doğrulanmış',
      'API-first → partner kendi otomasyonunu yazabilir',
      '29 fazda inşa edilmiş, 292 unit + 157 e2e + 103 frontend test',
    ])) +
    block('Yatırım Değeri', paragraph('Türkiye’deki 1000+ aktif radyo lisansı + 2 milyar TL reklam pazarı + dijital geçiş dalgası = 12-18M TL/yıl SaaS potansiyeli. Series A için "growth-ready", Series B için "scale-ready".')) +
    block('Kurumsal Kullanım Değeri', paragraph('Tek doğruluk kaynağı (her radyo aynı sponsor/haberi alır), tek tıkla bölgesel/ulusal kampanya, programmatic erişim (partner kendi otomasyonu). CapEx → OpEx geçişi %40 TCO düşürür.')) +
    block('Türkiye Çapında Ölçeklenebilirlik', paragraph('Mimari 50K media + 500 station altında <230ms; 5x scale (250K media + 2500 station) read-replica + CDN ile mümkün.')) +
    block('Broadcast Sektörüne Katkılar', paragraph('Yerel radyolara enterprise-grade trafik yönetimi sağlar; küçük radyolar büyük ajansların reklamlarını alabilir hale gelir. Türkiye radyo ekosisteminde değer zinciri seviye atlar.')) +
    block('Gelir Potansiyeli', table([
      ['Plan', 'Aylık (TRY)', 'Hedef Müşteri'],
      ['Bronze', '1.500', 'Tek istasyon'],
      ['Silver', '3.000', '2-5 istasyon'],
      ['Gold', '6.000', '5-15 istasyon'],
      ['Enterprise', 'Özel', 'Ulusal ağ'],
    ])) +
    block('Rekabet Avantajları', list([
      'Türkçe doğal mimari (rakipler İngilizce-first, lokalizasyon eksik)',
      'Mevcut Türkiye odaklı çözümlerden 10× daha modern (Vue 3, PHP 8.2, PostgreSQL)',
      'Maliyet etkinliği (mevcut stack, lisans yok)',
      'Modüler — istemci sadece ihtiyaç duyduğu kısmı alır',
      'API-first — partner self-service hız avantajı',
    ]))
  ));

  return c.join('\n');
}

// =============================================================================
// HTML template
// =============================================================================
async function buildHtml() {
  const shotData = await loadShots();
  const screenshotPages = SCREENS.map((s) => screenshotPage(s, shotData)).join('\n');
  const screenshotChapter = chapterShell(
    'Ek',
    'Tüm Ekran Görüntüleri (Desktop + Tablet + Mobile)',
    `<p>Aşağıda Aircast Broadcast Platform'un her admin ve partner ekranının üç viewport'tan (Desktop 1440×900, Tablet 820×1180, Mobile 390×844) yakalanmış görüntüleri ve her ekrana ait amaç, senaryo, rol, iş akışı, teknik açıklama, veri kaynağı, API bağlantısı, performans ve geliştirme önerileri yer almaktadır.</p>
     ${screenshotPages}`,
  );

  return `<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8" />
  <title>Aircast Broadcast Platform — Master Documentation</title>
  <style>
    /* Page setup. Cover keeps zero margin so the gradient bleeds full A4;
       every other page reserves room for the chrome header + footer. */
    @page { size: A4; margin: 22mm 14mm 22mm 14mm; }
    @page :first { margin: 0; }

    body {
      font-family: 'Plus Jakarta Sans', 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
      color: #1f2937;
      line-height: 1.55;
      font-size: 10.5pt;
      margin: 0;
      -webkit-print-color-adjust: exact;
    }
    h1, h2, h3, h4 { color: #0b1224; font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; letter-spacing: -0.01em; }
    h1 { font-size: 26pt; margin-top: 0; line-height: 1.12; }
    h2 { font-size: 16pt; margin: 14px 0 6px; }
    h3 { font-size: 12pt; margin: 10px 0 4px; color: #b91c4b; text-transform: uppercase; letter-spacing: 0.06em; }
    p, li, td, th { font-size: 10pt; }
    p { margin: 6px 0; }
    code { font-family: 'Fira Code', Consolas, monospace; font-size: 9pt; color: #0b1224; background: #f1f5f9; padding: 1px 5px; border-radius: 3px; }
    a { color: #b91c4b; text-decoration: none; }
    table { border-collapse: collapse; width: 100%; margin: 8px 0; }
    th, td { border: 1px solid #e2e8f0; padding: 6px 9px; text-align: left; vertical-align: top; }
    th { background: #f8fafc; font-weight: 700; color: #0b1224; }
    ul { padding-left: 20px; margin: 6px 0; }
    li { margin: 2px 0; }
    hr { border: 0; border-top: 1px solid #e2e8f0; margin: 12px 0; }

    /* ===== Cover ===== */
    .cover {
      page-break-after: always;
      position: relative;
      height: 297mm;
      width: 210mm;
      background:
        radial-gradient(circle at 92% 8%, rgba(225, 29, 72, 0.55) 0%, rgba(225, 29, 72, 0) 42%),
        radial-gradient(circle at 12% 92%, rgba(56, 189, 248, 0.30) 0%, rgba(56, 189, 248, 0) 38%),
        linear-gradient(135deg, #050a18 0%, #0b1224 55%, #1a0a18 100%);
      color: #fff;
      padding: 36mm 22mm;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      overflow: hidden;
    }
    .cover::before {
      content: '';
      position: absolute;
      top: 0; right: 0; width: 110mm; height: 297mm;
      background: linear-gradient(180deg, rgba(225,29,72,0.0) 0%, rgba(225,29,72,0.07) 60%, rgba(225,29,72,0.18) 100%);
      pointer-events: none;
    }
    .cover-grid-bg {
      position: absolute; inset: 0; opacity: 0.12; pointer-events: none;
    }
    .cover-mark {
      display: flex; align-items: center; gap: 12px;
    }
    .cover-mark .logo {
      width: 56px; height: 56px; border-radius: 14px;
      background: linear-gradient(135deg, #fb7185 0%, #e11d48 100%);
      box-shadow: 0 14px 30px rgba(225, 29, 72, 0.45);
      display: grid; place-items: center; color: #fff;
      font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 900; font-size: 26pt;
    }
    .cover-mark .word { font-family: 'Plus Jakarta Sans', sans-serif; }
    .cover-mark .word .brand { font-size: 18pt; font-weight: 900; letter-spacing: -0.02em; color: #fff; line-height: 1; }
    .cover-mark .word .tag { font-size: 9pt; font-weight: 700; letter-spacing: 0.18em; color: #fb7185; text-transform: uppercase; margin-top: 4px; }

    .cover .sub {
      display: inline-block; padding: 4px 12px; border-radius: 999px;
      background: rgba(251, 113, 133, 0.16); color: #fda4af;
      font-size: 9pt; font-weight: 800; letter-spacing: 0.16em; text-transform: uppercase;
      border: 1px solid rgba(251, 113, 133, 0.4);
    }
    .cover h1 {
      color: #fff;
      font-size: 38pt;
      line-height: 1.06;
      letter-spacing: -0.03em;
      margin: 6mm 0 0;
      max-width: 150mm;
    }
    .cover .h-accent { color: #fb7185; }
    .cover .desc {
      color: #cbd5e1; font-size: 11pt; line-height: 1.7;
      margin-top: 10mm; max-width: 150mm;
    }
    .cover .kpi-strip {
      display: grid; grid-template-columns: repeat(4, 1fr); gap: 4mm;
      margin-top: 10mm; max-width: 170mm;
    }
    .cover .kpi {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 12px; padding: 6mm 5mm;
    }
    .cover .kpi .v { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 22pt; font-weight: 900; color: #fff; line-height: 1; }
    .cover .kpi .l { font-size: 8pt; color: #94a3b8; letter-spacing: 0.1em; text-transform: uppercase; margin-top: 3mm; }
    .cover .meta-row {
      display: grid; grid-template-columns: repeat(4, 1fr); gap: 4mm;
      padding-top: 10mm; margin-top: auto;
      border-top: 1px solid rgba(255, 255, 255, 0.12);
      font-size: 9pt; color: #cbd5e1;
    }
    .cover .meta-row strong { display: block; color: #fff; font-weight: 700; margin-bottom: 2mm; letter-spacing: 0.04em; font-size: 8pt; text-transform: uppercase; }

    /* ===== TOC ===== */
    .toc { page-break-after: always; padding: 4mm 4mm; }
    .toc h1 { font-size: 24pt; color: #b91c4b; border-bottom: 3px solid #b91c4b; padding-bottom: 6mm; margin-bottom: 8mm; }
    .toc ol { list-style: none; padding: 0; counter-reset: chap; }
    .toc li {
      counter-increment: chap; margin: 4px 0; font-size: 10.5pt; padding: 4px 4px 4px 14mm;
      position: relative; border-bottom: 1px dotted #e2e8f0;
    }
    .toc li::before {
      content: counter(chap, decimal-leading-zero);
      position: absolute; left: 0; top: 4px;
      color: #b91c4b; font-weight: 900; font-size: 11pt; width: 12mm;
    }

    /* ===== Chapter shells (regular) ===== */
    .chapter { page-break-before: always; padding: 0; }
    .chapter-head {
      display: flex; gap: 6mm; align-items: flex-start;
      border-bottom: 3px solid #b91c4b;
      padding-bottom: 5mm; margin-bottom: 8mm;
    }
    .chapter-num {
      flex: 0 0 auto;
      width: 24mm; height: 24mm;
      background: linear-gradient(135deg, #fb7185 0%, #b91c4b 100%);
      color: #fff;
      border-radius: 14px;
      display: grid; place-items: center;
      font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 900; font-size: 22pt;
      box-shadow: 0 8px 20px rgba(185, 28, 75, 0.18);
    }
    .chapter h1 { font-size: 22pt; margin: 0 0 2mm; }
    .chapter .subtitle { color: #64748b; font-size: 11pt; margin: 0; max-width: 145mm; }
    .block { margin: 6mm 0; page-break-inside: avoid; }
    .block h3 { border-left: 4px solid #b91c4b; padding-left: 10px; }
    .ktable { font-size: 9pt; }
    .ktable th { background: #0f172a; color: #fff; }

    /* ===== Screens (compact 2-page layout per screen) =====
       Page 1: large desktop + tablet/mobile side-by-side + screen header
       Page 2: full metadata card (Künye) + technical details */
    .screen-pack { page-break-before: always; }
    .screen-header {
      display: flex; align-items: center; gap: 4mm;
      background: linear-gradient(90deg, #0b1224 0%, #1a1f33 100%);
      color: #fff;
      padding: 4mm 6mm;
      border-radius: 8px;
      margin-bottom: 6mm;
    }
    .screen-tag {
      display: inline-block; padding: 3px 10px;
      background: #b91c4b; color: #fff;
      font-size: 8pt; font-weight: 900; letter-spacing: 0.1em;
      border-radius: 999px; text-transform: uppercase;
    }
    .screen-tag.partner { background: #1d4ed8; }
    .screen-header h2 {
      color: #fff; margin: 0; font-size: 14pt;
      letter-spacing: -0.01em;
    }
    .screen-header .route {
      margin-left: auto; font-family: 'Fira Code', Consolas, monospace;
      font-size: 9pt; color: #cbd5e1; background: rgba(255,255,255,0.06);
      padding: 4px 10px; border-radius: 6px;
    }

    .shot-frame {
      background: #0b1224; border: 1px solid #1f2937; border-radius: 10px;
      padding: 6px; box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
      page-break-inside: avoid;
    }
    .shot-frame img {
      width: 100%; display: block; border-radius: 6px;
      max-height: 170mm; object-fit: contain;
    }
    .shot-frame.is-tablet img,
    .shot-frame.is-mobile img { max-height: 110mm; }
    .shot-frame figcaption {
      color: #94a3b8; font-size: 8pt; text-align: center;
      margin-top: 4px; letter-spacing: 0.1em; text-transform: uppercase;
    }

    .shot-row-two {
      display: grid; grid-template-columns: 1fr 60mm; gap: 5mm;
      margin-top: 5mm;
    }
    .shot-row-two .shot-frame.is-mobile img { max-height: 105mm; }

    .meta-grid {
      display: grid; grid-template-columns: 38mm 1fr 38mm 1fr;
      gap: 0; margin-top: 6mm; border: 1px solid #e2e8f0; border-radius: 8px;
      overflow: hidden;
    }
    .meta-grid .lbl {
      background: #f1f5f9; padding: 6px 10px; font-size: 9pt; font-weight: 800;
      letter-spacing: 0.06em; text-transform: uppercase; color: #475569;
      border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0;
    }
    .meta-grid .val {
      padding: 6px 10px; font-size: 9.5pt; line-height: 1.55;
      border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0;
      color: #1f2937;
    }
    .meta-grid .lbl.last, .meta-grid .val.last { border-bottom: none; }
    .meta-grid .val.last { border-right: none; }
    .meta-grid .full {
      grid-column: 1 / -1; padding: 6px 10px; font-size: 9.5pt; line-height: 1.55;
      border-bottom: 1px solid #e2e8f0; color: #1f2937; background: #fff;
    }
    .meta-grid .full.last { border-bottom: none; }
    .meta-grid .full strong {
      display: block; font-size: 8.5pt; letter-spacing: 0.08em;
      text-transform: uppercase; color: #b91c4b; margin-bottom: 2px;
    }

    .codeblock {
      background: #0b1224; color: #e2e8f0; padding: 6mm;
      border-radius: 8px; font-family: 'Fira Code', Consolas, monospace;
      font-size: 8.5pt; line-height: 1.5; overflow-wrap: break-word;
      white-space: pre-wrap; page-break-inside: avoid;
      border: 1px solid #1f2937;
    }
    .codeblock code { background: transparent; color: inherit; padding: 0; font-size: inherit; }

    /* KPI / metric strip used in chapters 1/5/17/etc. */
    .kpi-row {
      display: grid; grid-template-columns: repeat(4, 1fr); gap: 4mm;
      margin: 6mm 0;
    }
    .kpi-card {
      background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
      border: 1px solid #e2e8f0; border-radius: 10px;
      padding: 5mm; text-align: center;
    }
    .kpi-card .v { font-size: 18pt; font-weight: 900; color: #b91c4b; line-height: 1; }
    .kpi-card .l { font-size: 8pt; color: #475569; letter-spacing: 0.1em; text-transform: uppercase; margin-top: 3mm; }
  </style>
</head>
<body>
  <!-- Cover -->
  <section class="cover">
    <!-- SVG decoration: faint grid + waveform suggests broadcast audio -->
    <svg class="cover-grid-bg" viewBox="0 0 210 297" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <pattern id="grid" width="6" height="6" patternUnits="userSpaceOnUse">
          <path d="M 6 0 L 0 0 0 6" fill="none" stroke="#ffffff" stroke-width="0.15" />
        </pattern>
      </defs>
      <rect width="210" height="297" fill="url(#grid)" />
      <!-- Stylized broadcast waveform across the lower third -->
      <g stroke="#fb7185" stroke-width="0.6" stroke-linecap="round" opacity="0.55">
        <line x1="14" y1="210" x2="14" y2="218" />
        <line x1="20" y1="206" x2="20" y2="222" />
        <line x1="26" y1="200" x2="26" y2="228" />
        <line x1="32" y1="208" x2="32" y2="220" />
        <line x1="38" y1="196" x2="38" y2="232" />
        <line x1="44" y1="186" x2="44" y2="242" />
        <line x1="50" y1="200" x2="50" y2="228" />
        <line x1="56" y1="208" x2="56" y2="220" />
        <line x1="62" y1="190" x2="62" y2="238" />
        <line x1="68" y1="178" x2="68" y2="250" />
        <line x1="74" y1="194" x2="74" y2="234" />
        <line x1="80" y1="204" x2="80" y2="224" />
        <line x1="86" y1="188" x2="86" y2="240" />
        <line x1="92" y1="182" x2="92" y2="246" />
        <line x1="98" y1="200" x2="98" y2="228" />
        <line x1="104" y1="210" x2="104" y2="218" />
        <line x1="110" y1="196" x2="110" y2="232" />
        <line x1="116" y1="186" x2="116" y2="242" />
        <line x1="122" y1="200" x2="122" y2="228" />
        <line x1="128" y1="208" x2="128" y2="220" />
        <line x1="134" y1="190" x2="134" y2="238" />
        <line x1="140" y1="178" x2="140" y2="250" />
        <line x1="146" y1="194" x2="146" y2="234" />
        <line x1="152" y1="204" x2="152" y2="224" />
        <line x1="158" y1="188" x2="158" y2="240" />
        <line x1="164" y1="200" x2="164" y2="228" />
        <line x1="170" y1="210" x2="170" y2="218" />
        <line x1="176" y1="196" x2="176" y2="232" />
        <line x1="182" y1="186" x2="182" y2="242" />
        <line x1="188" y1="200" x2="188" y2="228" />
        <line x1="194" y1="208" x2="194" y2="220" />
      </g>
      <!-- Türkiye outline silhouette, abstract -->
      <path d="M 138 36 L 152 32 L 168 36 L 178 42 L 184 50 L 188 60 L 184 70 L 178 74 L 168 76 L 158 74 L 148 70 L 142 64 L 138 56 Z"
            fill="none" stroke="#fb7185" stroke-width="0.4" opacity="0.45" />
    </svg>

    <!-- Brand mark -->
    <div class="cover-mark">
      <div class="logo">A</div>
      <div class="word">
        <div class="brand">Aircast Pro</div>
        <div class="tag">Broadcast Platform</div>
      </div>
    </div>

    <!-- Title + descriptor -->
    <div>
      <span class="sub">Enterprise Master Documentation · v1.0</span>
      <h1>Türkiye'nin Yayıncılık Omurgası<br/><span class="h-accent">Tek Dosyada Tüm Sistem</span></h1>
      <p class="desc">
        7 bölge, 81 il ve 500+ partner radyo için haber, spor, ekonomi, hava durumu,
        sponsor takdimleri ve ticari reklamların merkezden planlanıp üretildiği
        ve dağıtıldığı multi-tenant SaaS platformunun yatırımcı, ürün, mimari,
        kullanıcı, sistem yöneticisi, API ve kalite belgesi.
      </p>

      <!-- KPI strip — facts you can see at a glance -->
      <div class="kpi-strip">
        <div class="kpi"><div class="v">29</div><div class="l">Faz · Sıfırdan Üretim</div></div>
        <div class="kpi"><div class="v">500+</div><div class="l">Partner Radyo</div></div>
        <div class="kpi"><div class="v">7</div><div class="l">Bölge · 81 İl</div></div>
        <div class="kpi"><div class="v">157</div><div class="l">E2E Test</div></div>
      </div>
    </div>

    <!-- Bottom meta row -->
    <div class="meta-row">
      <div><strong>Doküman Türü</strong>Master Technical &amp; Investor Documentation</div>
      <div><strong>Sürüm</strong>1.0 · ${new Date().toISOString().slice(0, 10)}</div>
      <div><strong>Gizlilik</strong>Şirket İçi · Yatırımcıya Açık</div>
      <div><strong>Hedef Kitle</strong>Yatırımcı · Ürün · Teknik · Satış · QA · DevOps</div>
    </div>
  </section>

  <!-- TOC -->
  <section class="toc">
    <h1>İçindekiler</h1>
    <ol>
      <li>Yönetici Özeti</li>
      <li>Ürün Tanıtımı</li>
      <li>Sistem Mimarisi</li>
      <li>Türkiye Yayın Yönetim Modeli</li>
      <li>Dashboard Analizi</li>
      <li>Yayın Planlama Modülü</li>
      <li>Haber Yönetimi</li>
      <li>Spor Yönetimi</li>
      <li>Ekonomi Yönetimi</li>
      <li>Hava Durumu Yönetimi</li>
      <li>Reklam Planlama Motoru</li>
      <li>Kullanıcı ve Yetki Yönetimi</li>
      <li>Raporlama</li>
      <li>Veritabanı Mimarisi</li>
      <li>API Dokümantasyonu</li>
      <li>Güvenlik Analizi</li>
      <li>Performans Analizi</li>
      <li>UX/UI Analizi</li>
      <li>Yapay Zeka ve Gelecek Geliştirmeler</li>
      <li>Eksikler ve Geliştirme Önerileri</li>
      <li>Kurulum Rehberi (Installation Guide)</li>
      <li>Yönetici Rehberi (Admin User Manual)</li>
      <li>Sistem Yöneticisi Runbook</li>
      <li>Operasyonel SOP'lar</li>
      <li>Migration Geçmişi (Faz 1 → 29)</li>
      <li>Sözlük (Glossary)</li>
      <li>Yol Haritası (Future Roadmap)</li>
      <li>Kalite Metrikleri (Quality Metrics)</li>
      <li>Genel Değerlendirme</li>
      <li>Ek: Tüm Ekran Görüntüleri (Desktop + Tablet + Mobile)</li>
    </ol>
  </section>

  ${buildChapters(shotData)}
  ${screenshotChapter}
</body>
</html>`;
}

// =============================================================================
// Main
// =============================================================================
async function main() {
  console.log('Building HTML…');
  const html = await buildHtml();
  await fs.writeFile(HTML_OUT, html, 'utf-8');
  console.log(`  HTML: ${HTML_OUT} (${(html.length / 1024).toFixed(0)} KB)`);

  console.log('Rendering PDF via Playwright…');
  const browser = await chromium.launch({ channel: 'chrome' });
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.setContent(html, { waitUntil: 'networkidle' });
  await page.pdf({
    path: OUT_PDF,
    format: 'A4',
    printBackground: true,
    margin: { top: '18mm', right: '14mm', bottom: '22mm', left: '14mm' },
    displayHeaderFooter: true,
    headerTemplate:
      `<div style="font-size:7.5pt;color:#94a3b8;width:100%;padding:0 16mm;display:flex;justify-content:space-between;align-items:center;font-family:'Plus Jakarta Sans',Inter,sans-serif;">
        <span style="display:flex;align-items:center;gap:6px">
          <span style="display:inline-block;width:8px;height:8px;background:#e11d48;border-radius:2px"></span>
          <strong style="color:#0f172a;letter-spacing:0.04em">AIRCAST PRO</strong>
          <span>·</span>
          <span>Broadcast Platform Master Documentation</span>
        </span>
        <span style="color:#94a3b8">v1.0 · ${new Date().toISOString().slice(0, 10)}</span>
      </div>`,
    footerTemplate:
      `<div style="font-size:7.5pt;color:#94a3b8;width:100%;padding:0 16mm;display:flex;justify-content:space-between;align-items:center;font-family:'Plus Jakarta Sans',Inter,sans-serif;border-top:1px solid #e2e8f0;padding-top:3mm">
        <span>© Aircast — Şirket İçi · Yatırımcıya Açık</span>
        <span><span class="pageNumber" style="color:#0f172a;font-weight:700"></span> / <span class="totalPages"></span></span>
      </div>`,
  });
  await browser.close();
  console.log(`  PDF:  ${OUT_PDF}`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
