# AdCast Pro Sync Client

Windows desktop sync client for AdCast Pro broadcast platform.
Yayıncılık kalitesinde dosya dağıtımı: doğru radyo · doğru bölge · doğru şehir · doğru saat · doğru dosya · checksum doğrulanmış · atomic taşıma.

## Vizyon

Yandex Drive/Google Drive değil — **broadcast-grade** dağıtım:
- Yarım dosya yayına asla düşmez (atomic move)
- Yanlış bölge/şehir dosyası asla inmez (per-radio scope token)
- Haber saatinden 15 dk önce ready garantisi
- Internet koparsa exponential backoff
- Offline queue + resume download

## Teknoloji Yığını

| Katman | Teknoloji | Not |
|---|---|---|
| **UI** | .NET 8 + WPF | Native Windows, düşük kaynak |
| **Hosted Service** | Microsoft.Extensions.Hosting | Arka plan worker'ı |
| **Local DB** | SQLite + EF Core | Manifest cache, log, token metadata |
| **Secure Storage** | Windows Credential Manager (DPAPI) | Token + refresh token |
| **HTTP** | HttpClient (resilient — Polly) | Retry + circuit breaker |
| **Checksum** | SHA-256 streaming | Büyük dosyalarda RAM verimli |
| **Tray** | Hardcodet.NotifyIcon.Wpf | Sistem tepsisi |
| **Logging** | Serilog (file + remote) | JSON yapısal, API'ye gönderim |
| **Installer** | WiX Toolset v4 | Authenticode imzalı MSI |

## Proje Yapısı

```
sync-client/
├── AdCastPro.SyncClient.sln
├── src/
│   ├── AdCastPro.SyncClient.Core/       (POCO + interfaces — UI'siz)
│   ├── AdCastPro.SyncClient.Infrastructure/  (HTTP, SQLite, DPAPI, FS)
│   ├── AdCastPro.SyncClient.App/        (Hosted Service + Worker)
│   └── AdCastPro.SyncClient.UI/         (WPF — login, settings, logs, tray)
├── tests/
│   ├── AdCastPro.SyncClient.UnitTests/
│   └── AdCastPro.SyncClient.IntegrationTests/  (gerçek API'ye karşı)
├── installer/
│   └── AdCastPro.Installer.wixproj      (WiX v4)
└── README.md
```

## Backend API Sözleşmesi

| Endpoint | Method | Açıklama |
|---|---|---|
| `/api/v1/sync/login` | POST | username+password → access+refresh token |
| `/api/v1/sync/refresh` | POST | refresh_token → yeni access_token |
| `/api/v1/sync/me` | GET | radio, region, city, permissions, client_version_min |
| `/api/v1/sync/manifest` | GET | scheduled files (next 24h) — diff için ETag |
| `/api/v1/sync/download/{fileId}` | GET | signed URL redirect (5 dk valid) |
| `/api/v1/sync/report` | POST | başarılı/başarısız raporu |
| `/api/v1/sync/error` | POST | client hata raporu |
| `/api/v1/sync/heartbeat` | POST | online/offline + version + IP |

Tüm endpoint'ler `JwtService` + `Rbac` + `LoginThrottle` + signed-URL altyapısını kullanır (zaten mevcut).

## Yayıncılık Garantileri

**Yayına düşmesi imkansız senaryolar:**
- ❌ Yarım dosya → temp/ + atomic rename'den önce checksum fail = silinir
- ❌ Yanlış bölge → API token zaten radio_id'ye scoped, manifest'e yanlış bölge düşmez
- ❌ Geç dosya → 15 dk önce ready değilse client uyarır + API'ye "not ready" raporu
- ❌ Bağlantı kopması → exponential backoff (1s, 2s, 4s, ..., max 5dk), internet gelince devam
- ❌ Disk dolu → Windows API ile pre-flight check, tray notification
- ❌ Yetkisiz dosya → JWT token + Rbac::checkRadioAccess() backend'de validate

## Faz Planı

| Faz | İçerik | Süre |
|---|---|---|
| **F1** | Backend `/api/v1/sync/*` endpoint'leri + migration + tests | 2-3 gün |
| **F2** | .NET 8 solution skeleton + Auth flow + Token storage | 3 gün |
| **F3** | Manifest poller + Atomic downloader + Checksum | 4 gün |
| **F4** | Tray UI + Settings + Logs viewer + Online status | 3 gün |
| **F5** | Offline queue + Resume download + Retry policy | 2 gün |
| **F6** | Installer (WiX) + Authenticode sign + Auto-update | 2 gün |
| **F7** | Test suite (16 zorunlu test senaryosu) | 3 gün |
| **F8** | Admin panele sync izleme entegrasyonu | 2 gün |
| **TOPLAM** | | **~3-4 hafta** |

## Geliştirme Ortamı

```powershell
# .NET SDK 8 (LTS)
winget install Microsoft.DotNet.SDK.8

# WiX Toolset v4 (installer için)
dotnet tool install --global wix

# Code signing certificate (production için)
# Sectigo / DigiCert OV/EV code signing — ayrıca temin edilecek

# Geliştirme komutları
cd sync-client
dotnet restore
dotnet build
dotnet test
dotnet run --project src/AdCastPro.SyncClient.App
```

## Güvenlik

- HTTPS zorunlu, sertifika doğrulama kapatılamaz (`HttpClientHandler.ServerCertificateCustomValidationCallback` disabled in Release)
- Token'lar DPAPI (`ProtectedData.Protect`) ile `LocalMachine` scope'da
- Şifre asla disk'e yazılmaz (yalnız RAM, login sonrası imha)
- Audit log her API çağrısında
- File extension whitelist: mp3, wav, aac, m3u, pls, xml, json
- Path traversal koruması: tüm path'ler `Path.GetFullPath` + base dir prefix check

## Lisans

Proprietary — AdCast Pro internal use.
