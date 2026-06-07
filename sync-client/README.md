# AdCast Pro Sync Client

Windows desktop sync client — broadcast-grade dosya dağıtımı.

## Hızlı Build

```powershell
# .NET 8 SDK gerekli (winget install Microsoft.DotNet.SDK.8)
cd sync-client
dotnet restore
dotnet build --configuration Release

# Test
dotnet test --collect:"XPlat Code Coverage"

# Çalıştır (UI versiyon)
dotnet run --project src/AdCastPro.SyncClient.UI

# Servis versiyon (UI yok, sadece HostedService)
dotnet run --project src/AdCastPro.SyncClient.App
```

## Proje Yapısı

```
sync-client/
├── AdCastPro.SyncClient.sln                       (5-project solution)
├── src/
│   ├── AdCastPro.SyncClient.Core/                 (POCO + interfaces — UI'siz)
│   │   ├── Models/                                (AuthModels + ManifestModels)
│   │   ├── Abstractions/                          (IApiClient, ITokenStore, ILocalCache, IAtomicFileWriter, IChecksumService)
│   │   └── Configuration/                         (SyncClientOptions)
│   ├── AdCastPro.SyncClient.Infrastructure/       (impl)
│   │   ├── Api/                                   (ApiClient + AuthDelegatingHandler)
│   │   ├── Storage/                               (DpapiTokenStore + SqliteCache + AppDbContext + Entities)
│   │   ├── Files/                                 (AtomicFileWriter + Sha256ChecksumService)
│   │   └── Resilience/                            (PollyPolicies)
│   ├── AdCastPro.SyncClient.App/                  (Hosted Service host — Generic Host + DI)
│   │   ├── Workers/                               (ManifestPoller + DownloadWorker + Heartbeat)
│   │   ├── BroadcastReadinessService.cs           (YEŞIL/SARI/KIRMIZI status)
│   │   ├── Program.cs                             (Serilog + DI + WindowsService)
│   │   └── appsettings.json
│   └── AdCastPro.SyncClient.UI/                   (WPF — kullanıcı uygulaması)
│       ├── App.xaml + App.xaml.cs                 (DI container, ShutdownMode tray)
│       ├── ViewModels/                            (Login + Main + Settings + Logs)
│       ├── Views/                                 (4 window)
│       └── Services/                              (TrayIconHost + NavigationService)
├── tests/
│   └── AdCastPro.SyncClient.UnitTests/            (xUnit + FluentAssertions + Moq)
│       ├── Files/                                 (AtomicFileWriter + Sha256 testleri)
│       ├── Storage/                               (SqliteCache testleri)
│       ├── Resilience/                            (PollyPolicies testleri)
│       └── BroadcastReadinessServiceTests.cs
└── installer/
    ├── AdCastPro.Installer.wixproj                (WiX v4 SDK)
    ├── Product.wxs                                (per-user kurulum + auto-start + shortcuts)
    └── License.rtf
```

## Yayıncılık Garantileri

| Garanti | Nerede Kodlanmış |
|---|---|
| Yarım dosya yayına düşmez | `AtomicFileWriter` — temp + checksum + atomic NTFS rename |
| Yanlış bölge/şehir dosyası inmez | Backend manifest scope (JWT radio_id) + download endpoint manifest re-check |
| 15dk öncesi ready garantisi | `BroadcastReadinessService` — RED level uyarısı |
| Path traversal koruması | `AtomicFileWriter.SanitizeFilename` — ../ \\ : <> red |
| Extension whitelist | mp3, wav, aac, m3u, pls, xml, json, txt |
| DPAPI token storage | `DpapiTokenStore` — `ProtectedData.Protect` CurrentUser scope |
| Resume download | `DownloadWorker` — Range header partial byte count |
| Adaptif polling | `ManifestPollerService` — 60s → 30s → 15s → 5s haber saatine kala |
| Offline mode | `SqliteCache` manifest fallback + Polly circuit breaker |
| Disk pre-flight | `AtomicFileWriter.GetFreeBytes` size × 1.5 check |
| Auto-startup | `SettingsViewModel.ApplyAutoStart` — `HKCU\...\Run` registry |

## Polling Mantığı

```
Normal:                       60 saniye
Haber saatine 20dk kala:      30 saniye
Haber saatine 10dk kala:      15 saniye
Haber saatine 5dk kala:        5 saniye
```

Türkiye haber kuşakları: 08:00 · 10:00 · 12:00 · 14:00 · 16:00 · 18:00 · 20:00

## Test Komutları

```powershell
# Tüm testler + coverage
dotnet test --collect:"XPlat Code Coverage" --results-directory ./TestResults

# Coverage rapor (reportgenerator gerekli)
dotnet tool install -g dotnet-reportgenerator-globaltool
reportgenerator -reports:./TestResults/**/coverage.cobertura.xml -targetdir:./coverage-report -reporttypes:Html

# Sadece AtomicFileWriter testleri
dotnet test --filter "FullyQualifiedName~AtomicFileWriter"
```

## Installer Build

```powershell
# WiX v4 SDK gerekli
dotnet tool install --global wix

cd installer
dotnet build -c Release
# Çıktı: bin/Release/AdCastProSyncClient.msi
```

İmzalama (production):
```powershell
signtool sign /tr http://timestamp.digicert.com /td sha256 /fd sha256 /a `
    AdCastProSyncClient.msi
```

## Geliştirici Notları

- **Lokal dev DB**: `%LOCALAPPDATA%\AdCastPro\sync.db` (EF Core EnsureCreated)
- **Lokal log**: `%LOCALAPPDATA%\AdCastPro\Logs\sync-{Date}.log` (Serilog rolling)
- **Token storage**: `%LOCALAPPDATA%\AdCastPro\tokens.dpapi`
- **Machine ID**: `HKCU\Software\AdCastPro\MachineId` (stable UUID)
- **Auto-start**: `HKCU\Software\Microsoft\Windows\CurrentVersion\Run\AdCastProSyncClient`
