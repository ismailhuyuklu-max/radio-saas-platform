# AdCast Pro Sync Client — Geliştirme Yol Haritası

**Hedef:** Windows desktop sync client + backend `/api/v1/sync/*` API.

**Bu doküman:** Faz faz neyin ne zaman yapılacağını anlatır. Master prompt
"AD CAST PRO — WINDOWS RADYO SENKRONİZASYON YAZILIMI" referans alındı.

---

## Mevcut Durum (Foundation)

✅ **Hazır altyapı (Reuse — yeniden yazılmayacak):**
- `JwtService.php` — access+refresh token pair üretimi/doğrulaması
- `LoginThrottle.php` — IP+username bazlı brute-force koruması
- `Rbac.php` — radio/region/city authorization checks
- `RadioCredentialService.php` — radyo başına 8 amaç token (Faz 13)
- `StreamTokenService.php` — signed URL üretimi
- `AuditLogRepository.php` — generic event audit
- `Logger.php` — yapısal JSON log + request_id correlation
- nginx rate-limit (api:100r/s, login:5r/s, upload:2r/s)
- ETag/304 cache infrastructure

✅ **Yeni eklenen scaffolding (bu commit):**
- `sync-client/README.md` — proje vizyonu + teknoloji yığını
- `backend/src/Controller/SyncController.php` — 7 endpoint iskeleti (login, refresh, me, manifest, download, report, heartbeat)
- `sql/002_sync_clients.sql` — `sync_clients` + `sync_activity` tabloları + `v_sync_client_status` view

⏳ **Sıradaki (TODO her fazda detay var):**

---

## Faz 1 — Backend Sync API (Tahmini 2-3 gün)

### F1.1 Repository + Service katmanı
- `backend/src/Repository/SyncClientRepository.php` — sync_clients CRUD + upsert
- `backend/src/Repository/SyncActivityRepository.php` — append-only event log
- `backend/src/Service/SyncManifestService.php` — radyo için next-24h dosyaları toplama, ContentPlan + MediaContent join

### F1.2 SyncController gerçek implementation
- `manifest()` — `SyncManifestService::buildForRadio($radioId, $since)` çağrı, signed URL üretimi
- `download()` — file access check + `StreamTokenService::generateOneShotUrl()` → 302 redirect
- ETag/304 desteği (`If-None-Match` header) — büyük manifest payload tekrar inmesin

### F1.3 Route registration
- `backend/public/index.php` içine 7 yeni route:
  ```php
  case '/api/v1/sync/login':     (new SyncController(...))->login(); break;
  case '/api/v1/sync/refresh':   (new SyncController(...))->refresh(); break;
  // ...
  ```

### F1.4 Tests
- `backend/tests/SyncControllerTest.php` — 8 senaryo:
  - Login success + login fail + throttle
  - Refresh success + refresh expired
  - Manifest scope (radyo A kullanıcısı radyo B'nin dosyasını GÖREMEZ)
  - Download 302 + signed URL TTL
  - Heartbeat upsert

### F1.5 Migration
- `sql/002_sync_clients.sql` migration çalıştır
- Production'da `bin/migrate.php` ile uygula

**Çıktı:** cURL ile manifest çekilebilir, signed URL ile dosya inebilir.

---

## Faz 2 — .NET 8 WPF Solution Skeleton (3 gün)

### F2.1 Solution + projects
```
sync-client/
├── AdCastPro.SyncClient.sln
├── src/
│   ├── AdCastPro.SyncClient.Core/             (POCO + interfaces)
│   │   ├── Models/ (User, Radio, ManifestFile, SyncReport)
│   │   ├── Abstractions/ (IApiClient, ITokenStore, IFileStore, IChecksumService)
│   │   └── AdCastPro.SyncClient.Core.csproj
│   ├── AdCastPro.SyncClient.Infrastructure/   (impl)
│   │   ├── Api/ApiClient.cs (HttpClient + Polly)
│   │   ├── Storage/DpapiTokenStore.cs
│   │   ├── Storage/SqliteCache.cs (EF Core)
│   │   ├── Fs/AtomicFileWriter.cs (temp+rename pattern)
│   │   ├── Crypto/Sha256ChecksumService.cs
│   │   └── AdCastPro.SyncClient.Infrastructure.csproj
│   ├── AdCastPro.SyncClient.App/              (Hosted Service host)
│   │   ├── Program.cs (Generic Host + DI)
│   │   ├── Workers/ManifestPollerService.cs (BackgroundService)
│   │   ├── Workers/DownloadWorker.cs (Channel-based queue)
│   │   ├── Workers/HeartbeatService.cs
│   │   └── AdCastPro.SyncClient.App.csproj
│   └── AdCastPro.SyncClient.UI/               (WPF)
│       ├── App.xaml (Application entry)
│       ├── Views/LoginWindow.xaml
│       ├── Views/MainWindow.xaml (tray icon host)
│       ├── Views/SettingsView.xaml
│       ├── Views/LogsView.xaml
│       ├── ViewModels/ (MVVM — CommunityToolkit.Mvvm)
│       └── AdCastPro.SyncClient.UI.csproj
└── tests/
    ├── AdCastPro.SyncClient.UnitTests/
    └── AdCastPro.SyncClient.IntegrationTests/
```

### F2.2 NuGet bağımlılıkları
- `Microsoft.Extensions.Hosting` (8.x)
- `Microsoft.Data.Sqlite` + `EntityFrameworkCore.Sqlite`
- `Polly` (HTTP retry/circuit-breaker)
- `Serilog.Sinks.File` + custom HTTP sink (`/api/v1/sync/error`)
- `Hardcodet.NotifyIcon.Wpf` (tray)
- `CommunityToolkit.Mvvm`

### F2.3 Auth flow
- LoginWindow → username + password → `IApiClient.LoginAsync()` → DPAPI'ye token kaydet
- App start → token oku → `LoginAsync` skip → MainWindow doğrudan açıl
- Token expire → otomatik refresh (Polly retry policy + DelegatingHandler)

---

## Faz 3 — Senkronizasyon Mantığı (4 gün)

### F3.1 Manifest poller (BackgroundService)
- Default 60s polling (config'ten override)
- ETag conditional GET → 304 = "değişiklik yok, hiçbir şey yapma"
- 200 → manifest diff hesapla (yeni dosyalar, değişen dosyalar, silinmiş dosyalar)
- Diff'i `IDownloadQueue` channel'a push

### F3.2 Atomic downloader
- Her dosya için:
  1. `Temp/{fileId}.partial` dosyasına stream
  2. SHA-256 streaming hesapla
  3. Manifest'teki checksum ile karşılaştır → eşleşmezse `.partial` sil + retry
  4. Eşleşirse `File.Move(temp, dest)` (atomic NTFS rename)
  5. Audit: `POST /api/v1/sync/report { status: "success" }`

### F3.3 Resume download (range request)
- `.partial` varsa, mevcut byte sayısını oku
- `Range: bytes=N-` header ile resume
- Backend signed URL'i range request destekler (zaten nginx default)

### F3.4 15 dk öncesi garanti
- Her dosyanın `scheduled_air_time - 15 min` = `download_deadline`
- Queue priority: deadline yakın olan önce
- Deadline geçti ama dosya yok → `POST /api/v1/sync/error { severity: "critical" }`
- Tray notification: "🚨 08:00 haberi eksik, son şans 07:45"

---

## Faz 4 — UI (3 gün)

### F4.1 Tray icon
- Online (yeşil) / stale (sarı) / offline (kırmızı) badge
- Sağ tık menu: "Sync Now", "Settings", "Logs", "Quit"

### F4.2 Settings view
- Klasör seçicileri (Haber, Reklam, Medya Planı, Arşiv, Temp)
- Polling interval slider (30s — 5dk)
- Startup with Windows checkbox (Registry HKCU\...\Run)
- Reset (logout + clear cache)

### F4.3 Logs view
- Tarih filtreleme, level filter (Info/Warn/Error)
- Search box
- Export to file butonu

---

## Faz 5 — Offline + Resilience (2 gün)
- Network kopması: Polly Circuit Breaker (5 fail → 30s open)
- Internet gelince auto-resume
- Disk full ön kontrol: `DriveInfo.AvailableFreeSpace` < manifest total bytes → toast + retry sonra
- Klasör silinmiş/erişimsiz: pre-flight check her sync öncesi

---

## Faz 6 — Installer + Auto-update (2 gün)
- WiX Toolset v4 → `AdCastPro.Installer.msi`
- Program Files\AdCastPro\ kurulum
- Auto-startup registry entry
- Authenticode imzalama (Sectigo/DigiCert OV code signing cert lazım — ayrı temin)
- Squirrel.Windows benzeri auto-update (`/api/v1/sync/me` response'unda `latest_version` field → karşılaştır → indir)

---

## Faz 7 — Test Suite (3 gün)

Master prompt'ta listelenen **16 zorunlu test**:

1. ✓ Login (geçerli kullanıcı)
2. ✓ Login (yanlış şifre — fail2ban tetiklenir)
3. ✓ Token refresh akışı
4. ✓ Manifest scope — radyo A → radyo B dosyası GÖRÜNMEZ
5. ✓ Bölge yetki — Marmara → Ege dosyası inmez
6. ✓ Şehir yetki — İstanbul → İzmir dosyası inmez
7. ✓ Dosya indirme + checksum doğrulama
8. ✓ Yarım dosya — checksum fail → `.partial` silinir, hedef klasör temiz
9. ✓ İnternet kopma — exponential backoff, gelince devam
10. ✓ API kapanma — circuit breaker açılır, sync queue persist
11. ✓ Disk dolu — pre-flight check + uyarı, yarım indirme yok
12. ✓ Yanlış klasör (klasör silinmiş) — uyarı + sync durdur
13. ✓ Haber saati öncesi sync (15 dk garantisi)
14. ✓ Reklam dosyası mimari (file_type=ad)
15. ✓ Medya planı mimari (file_type=media_plan)
16. ✓ Windows restart sonrası auto-start (registry + Hosted Service)

---

## Faz 8 — Admin Panel Sync İzleme (2 gün)

`frontend/src/views/radio-platform/sync/index.vue`:
- v_sync_client_status view'inden veri çek
- Tablo: Radyo · Kullanıcı · IP · Versiyon · Online/Offline · Son sync · Hata
- Filtre: bölge, durum (online/offline/error)
- Detay drawer: son 100 sync activity

---

## Geliştirme Ortamı Gereksinimi

```powershell
# Windows geliştirme makinesinde:
winget install Microsoft.DotNet.SDK.8
winget install JetBrains.ReSharper  # opsiyonel, VS Code C# Dev Kit yeterli
dotnet tool install --global wix    # WiX Toolset v4
dotnet workload install winui       # WinUI 3 (eğer WPF yerine onu seçersek)
```

---

## Tahmini Toplam Süre

| Faz | İçerik | Adam-gün |
|---|---|---:|
| 1 | Backend Sync API + tests + migration | 3 |
| 2 | .NET 8 WPF solution skeleton + auth | 3 |
| 3 | Senkronizasyon mantığı (manifest + downloader) | 4 |
| 4 | UI (tray, settings, logs) | 3 |
| 5 | Offline + resilience | 2 |
| 6 | Installer + auto-update | 2 |
| 7 | Test suite | 3 |
| 8 | Admin panel entegrasyonu | 2 |
| **TOPLAM** | | **22 adam-gün** |

**Takvim:** Tek developer ile ~4-5 hafta. Paralelde 2 developer ile ~2.5 hafta.

---

## Bağımlılıklar (External)

| Bağımlılık | Durum | Not |
|---|---|---|
| Production AdCast Pro backend canlı | ❌ Deploy yarım | SSH unblock sonrası bitirilecek |
| Test radyosu hesabı | ✓ admin/admin default | Faz 1 sonrası gerçek user oluşturulacak |
| .NET 8 SDK lokal | ⚠ Kontrol gerekli | Geliştirici makinesinde olmalı |
| Code signing certificate | ❌ Yok | Faz 6 öncesi temin (~$300/yıl) |
| Test Windows makinesi | ⚠ VM/fiziksel | Faz 7'de gerekli (Windows 10 + 11) |

---

## Versiyonlama

- `v3.0.0-sync` → Sync Client backend API (Faz 1 bitince)
- `v3.1.0-client-alpha` → İlk çalışan Windows client (Faz 4 bitince)
- `v3.2.0-client-beta` → Test radyolarına dağıtım (Faz 7 sonra)
- `v3.3.0-client-ga` → General Availability (Faz 8 sonra)
