# AdCast Pro v1.0 — Domain Architecture

**Versiyon:** v1.0 (production specification)
**Date:** 2026-06-07

ADCASTPRO bir web sitesi değil, **ulusal yayın içerik dağıtım altyapısıdır**. Mimarinin her kararı yüksek erişilebilirlik, ölçeklenebilirlik, güvenlik ve yayın sürekliliği üzerine kuruludur.

---

## 4-SUBDOMAIN AYRIMI

| Subdomain | Sorumluluk | Tüketici |
|---|---|---|
| `adcastpro.com` | Yönetim paneli (Vue 3 SPA) | Web tarayıcı kullanıcıları |
| `api.adcastpro.com` | REST API (PHP backend, JWT) | Sync Client + Vue panel |
| `files.adcastpro.com` | Dosya dağıtımı (MinIO / S3 / CDN) | Sync Client (signed URL) |
| `sync.adcastpro.com` | SignalR Hub (real-time push) | Sync Client (v1.1+) |

### Kritik Kural

> **Windows Sync Client, `adcastpro.com` (web panel) ile hiçbir bağımlılık taşımaz.** Sadece `api.adcastpro.com` + `files.adcastpro.com` üzerinden çalışır.
>
> Web panel kapalı olsa bile API + Files ayakta ise **radyolar haber almaya devam eder.** Bu zorunlu broadcast gereksinimi.

---

## DOĞRU İŞLEM AKIŞI

```
Windows Sync Client
        ↓
api.adcastpro.com  (POST /api/v1/sync/login → JWT)
        ↓
api.adcastpro.com  (GET /api/v1/sync/manifest → file list + signed URLs)
        ↓
files.adcastpro.com  (signed URL → MP3/WAV/...)
        ↓
Local Temp + SHA-256 Checksum
        ↓
Atomic Move (NTFS rename) → D:\AdCastPro\News\
        ↓
api.adcastpro.com  (POST /api/v1/sync/report → success)
        ↓
Broadcast Readiness → GREEN
```

---

## SERVICE DETAYI

### 1. `adcastpro.com` — Yönetim Paneli

**Stack:** Vue 3 + TypeScript + Vite + Ant Design Vue
**Container:** `radio-nginx` (Vite dist serve)
**TLS:** Caddy / nginx + Let's Encrypt

Görevleri:
- Kullanıcı yönetimi (admin auth + MFA)
- Radyo yönetimi (stations + bölge + şehir + grup)
- Bölge yönetimi (regions + provinces + national_access)
- Haber yönetimi (content_plans + planning + matrix)
- Reklam yönetimi (ad_campaigns + ad_traffic + airing)
- Medya planı yönetimi (media library + sponsor assignment)
- Raporlama (revenue + broadcast + customer + province CSV/XLSX/PDF)
- **Sync Client izleme** (`/radio-platform/sync` — NOC ekranı)

**Sync Client'a etkisi:** YOK (bağımsız servis).

### 2. `api.adcastpro.com` — REST API

**Stack:** PHP 8.2 + Nginx + JWT (HS256)
**Container:** `radio-php` + `radio-nginx` (server_name api.adcastpro.com)
**Rate Limit:** 100 r/s api zone, 5 r/s login zone, 2 r/s upload zone

Endpoint'ler:
```
POST /api/v1/sync/login        → JWT access + refresh token
POST /api/v1/sync/refresh      → Token rotation
GET  /api/v1/sync/me           → User + radio + permissions
GET  /api/v1/sync/manifest     → Next-24h files (ETag/304)
GET  /api/v1/sync/download/{id} → 302 → files.adcastpro.com signed URL
POST /api/v1/sync/report       → Sync result (success/failed/partial)
POST /api/v1/sync/heartbeat    → Online status + version + IP
GET  /api/v1/sync/update       → Auto-updater manifest
GET  /api/v1/sync-admin/clients → Admin NOC ekranı (super role)
```

**Kritik:** Sync Client tüm trafiği bu domain'e gönderir. Hardcoded URL YASAK — `appsettings.json/ApiBaseUrl` ile yönetilir.

### 3. `files.adcastpro.com` — Dosya Dağıtımı

**Stack:** MinIO (S3-compatible) veya CDN
**Container:** `radio-minio`
**Auth:** Presigned URL (5 dk TTL, SHA-256 query param)

Görevleri:
- Haber dosyaları (mp3/wav/aac)
- Reklam dosyaları
- Sponsor dosyaları (intro/outro/ad)
- Medya planları (m3u/pls/xml/json)
- Acil yayın dosyaları (emergency priority)

**Neden ayrı?** API yükünü azaltır. API sadece signed URL üretir, dosya transfer'i MinIO/CDN'den. 1000 radyo × 500MB dosya = 500GB/gün; API'yi bypass eder.

### 4. `sync.adcastpro.com` — Realtime (v1.1)

**Stack:** SignalR (ASP.NET Core veya Node.js veya alternatif WebSocket server)
**Container:** TBD (v1.0'da yok, v1.1'de ekleenir)

Görevleri:
- ManifestChanged event push (backend yüklediğinde anlık)
- EmergencyBroadcast push (acil yayın)
- UpdateAvailable push (yeni MSI versiyonu)

**Fallback:** SignalR olmazsa client polling sistemi devam eder (60s → adaptif 5s yaklaşırken). **Sistem polling olmadan çalışmaz; SignalR sadece "anlık tetik" sağlar.**

---

## BAKIM SENARYOLARI

| Senaryo | Web Panel | API | Files | SignalR | Radyo Yayını |
|---|---|---|---|---|---|
| Normal | UP | UP | UP | UP | ✅ Çalışır |
| Panel deploy | **DOWN** | UP | UP | UP | ✅ **Yayın devam eder** |
| SignalR çakılma | UP | UP | UP | **DOWN** | ✅ Polling devam |
| Hepsi DOWN | DOWN | DOWN | DOWN | DOWN | ❌ Yayın durur |
| API down + files up | UP | **DOWN** | UP | UP | ⚠ Mevcut manifest cache ile devam, yeni dosya gelmez |
| Files down + API up | UP | UP | **DOWN** | UP | ⚠ Download fail, eski dosyalar disk'te (yayın bir kuşak devam) |

**Tasarım hedefi:** Web panel bakım dönemleri (deploy, restart, update) radyo yayınını etkilemez.

---

## DNS YAPISI

Zorunlu A record'ları (production):

```
A      @        178.210.168.74    (root domain → web panel)
A      www      178.210.168.74    (www CNAME equivalent)
A      api      178.210.168.74    (REST API)
A      files    178.210.168.74    (MinIO/CDN — bu IP veya CDN endpoint)
A      sync     178.210.168.74    (SignalR Hub, v1.1)
```

Tek IP ile başlangıç. Ölçekleme sonrası `files.adcastpro.com` CDN'e (CloudFront, Bunny, Cloudflare R2) yönlendirilebilir.

---

## TLS / HTTPS ZORUNLULUĞU

| Madde | Gereksinim |
|---|---|
| HTTPS | Tüm 4 subdomain için ZORUNLU |
| TLS | 1.2+ (1.3 önerilen) |
| HSTS | `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` |
| HTTP → HTTPS redirect | Caddy/nginx 301 otomatik |
| Certificate | Let's Encrypt wildcard (`*.adcastpro.com`) veya 4 ayrı cert |

Caddy config'inde wildcard varsa tek sertifika 4 subdomain'i kapsar:
```caddy
*.adcastpro.com {
    tls ops@adcastpro.com
    @api host api.adcastpro.com
    handle @api { reverse_proxy backend_api:9000 }
    @files host files.adcastpro.com
    handle @files { reverse_proxy minio:9000 }
    ...
}
```

---

## ÖLÇEKLEME PLANI

| Ölçek | API | Files | Notlar |
|---|---|---|---|
| 100 radyo | 1 backend container | MinIO single node | Mevcut docker-compose |
| 500 radyo | 2-3 backend container (PgBouncer pool) | MinIO single | k6 1000 VU önce test |
| 1000 radyo | 3-5 backend container, Redis cache | MinIO distributed (4 node) veya CDN | Database read replica |
| 2500+ radyo | API Gateway (Kong/Traefik) + 5-10 container | CDN (CloudFront/Bunny) | Multi-region |

**Bağımsız ölçekleme:** API container'ı ölçeklerken Files etkilenmez ve tersi. Web panel zaten static, CDN'e taşınabilir.

---

## SINGLE POINT OF FAILURE ÖNLEMLERİ

| Servis | SPOF? | Mitigation |
|---|---|---|
| Web Panel | ❌ Hayır | Static SPA, kapalıyken Sync Client etkilenmez |
| API | ⚠ Evet | Multi-container + LB (production'da) |
| Database (PG) | ⚠ Evet | PgBouncer transaction pool + read replica + backup cron |
| Files (MinIO) | ⚠ Evet | MinIO distributed mode + CDN front |
| SignalR | ❌ Hayır | Polling fallback, yokluğu yayını durdurmaz |

---

## WINDOWS CLIENT CONFIG

`appsettings.json`:
```json
{
  "SyncClient": {
    "ApiBaseUrl": "https://api.adcastpro.com",
    "FilesBaseUrl": "https://files.adcastpro.com",
    "SignalRHubUrl": "https://sync.adcastpro.com/hubs/manifest",
    ...
  }
}
```

**Hardcoded URL YASAK.** Tüm URL'ler config'ten override edilebilir. Geliştirme için lokal test:
```json
{
  "SyncClient": {
    "ApiBaseUrl": "http://localhost:8080",
    "FilesBaseUrl": "http://localhost:9000",
    "SignalRHubUrl": ""
  }
}
```

---

## VALIDATION (Sandbox'tan Doğrulanan)

| Kontrol | Sonuç |
|---|---|
| Hardcoded URL sync client kodunda | ✅ 0 bulgu (sadece SyncClientOptions default'lar) |
| Localhost referansı sync client | ✅ 0 referans |
| 3 subdomain Core options'ta tanımlı | ✅ ApiBaseUrl, FilesBaseUrl, SignalRHubUrl |
| appsettings.json defaults | ✅ Production-ready |
| Frontend `VITE_GLOB_API_URL` env override | ✅ var |
| `.env.production.example` 4 URL ayrımı | ✅ APP_URL + API_URL + FILES_URL + SYNC_URL |

---

## RUNTIME DOĞRULAMA (Sen-tarafı VDS canlıya alındığında)

```bash
# DNS doğrulama
dig +short adcastpro.com
dig +short api.adcastpro.com
dig +short files.adcastpro.com
dig +short sync.adcastpro.com
# Hepsi VDS IP'sini dönmeli

# HTTPS handshake test
curl -sI https://adcastpro.com | grep -i strict-transport
curl -sI https://api.adcastpro.com/api/v1/healthz/deep
curl -sI https://files.adcastpro.com/  # MinIO health
# sync.adcastpro.com v1.1'de aktive edilecek

# Sync Client config'i doğru subdomain'lere çekiyor mu?
# %ProgramFiles%\AdCast Pro\Sync Client\appsettings.json
type "C:\Program Files\AdCast Pro\Sync Client\appsettings.json" | findstr BaseUrl

# Web panel kapalıyken Sync Client çalışmaya devam ediyor mu?
# adcastpro.com'a nginx 503 maintenance sayfası serve et,
# api.adcastpro.com'u canlı tut → Sync Client manifest çekmeye devam etmeli.
```

---

## SONUÇ

ADCASTPRO mimari kararları **broadcast altyapısı** olarak alınmıştır:

1. Web panel ↔ Sync Client **bağımlılığı YOK**
2. 4 subdomain **bağımsız ölçeklenebilir**
3. SignalR olmadan da sistem **polling ile çalışır**
4. Files ayrı katmanda → API yükü azalır, CDN'e taşınabilir
5. HTTPS / TLS 1.2+ / HSTS **zorunlu** tüm subdomain'lerde
6. Hardcoded URL YASAK → tüm config-driven

**Bu mimari v1.0 RC'de tam karşılanmış durumda. Production VDS canlıya alındığında DNS + 4 subdomain TLS cert + nginx server_name kurulması yeterli.**
