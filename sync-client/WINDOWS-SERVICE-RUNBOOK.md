# AdCast Pro Sync Service — Windows Service Runbook

**Service Name:** `AdCastProSyncService`
**Display Name:** AdCast Pro Sync Service
**Account:** LocalSystem
**Startup:** Automatic
**Recovery:** Restart on failure (60s delay, 3 attempts/24h)

---

## KURULUM (MSI ile Otomatik)

```powershell
# Admin yetkisi gerekli (UAC prompt)
msiexec /i AdCastProSyncClient.msi /quiet /log install.log
```

MSI çalıştırılınca otomatik olarak:
1. `C:\Program Files\AdCast Pro\Sync Client\` dizinine kopyalama
2. `AdCastProSyncService` adıyla Windows Service kayıt
3. Event Log source `AdCastProSync` (Application) registration
4. Start Menu shortcut + Run registry entry (tray UI auto-start)
5. Servis hemen başlatılır
6. Recovery policy uygulanır (crash sonrası 60s delay restart)

**Doğrulama:**
```powershell
sc query AdCastProSyncService
# Beklenen: STATE: 4 RUNNING
```

---

## SERVICE YÖNETIM KOMUTLARI

```powershell
# Durum sorgula
sc query AdCastProSyncService
Get-Service -Name AdCastProSyncService

# Başlat / Durdur / Restart
sc start AdCastProSyncService
sc stop AdCastProSyncService
Restart-Service -Name AdCastProSyncService -Force

# Konfigürasyon görüntüle
sc qc AdCastProSyncService
# Recovery policy
sc qfailure AdCastProSyncService

# Geçici olarak durdur (Windows boot'unda otomatik başlamaz)
sc config AdCastProSyncService start= demand   # manual start

# Tekrar otomatik başlatmaya çevir
sc config AdCastProSyncService start= auto

# Log dizini
explorer C:\ProgramData\AdCastPro\Logs\
```

---

## EVENT LOG İZLEME

```powershell
# Son 50 olay (Application log, AdCastProSync source)
Get-EventLog -LogName Application -Source AdCastProSync -Newest 50

# Sadece hatalar
Get-EventLog -LogName Application -Source AdCastProSync -EntryType Error -Newest 20

# Belirli tarih aralığı
Get-EventLog -LogName Application -Source AdCastProSync `
    -After (Get-Date).AddHours(-1)

# UI: Event Viewer → Windows Logs → Application → Filter: Source=AdCastProSync
eventvwr.msc
```

Tipik event'ler:
- `Information`: Servis başlatıldı / durduruldu
- `Warning`: Network kesintisi, retry tetiklendi
- `Error`: Disk dolu, checksum fail, auth fail, beklenmedik exception

---

## LOG DOSYALARI

| Lokasyon | İçerik |
|---|---|
| `C:\ProgramData\AdCastPro\Logs\sync-{Date}.log` | Serilog ana log (file rolling daily, 14 gün retention, 50MB cap) |
| `C:\ProgramData\AdCastPro\sync.db` | SQLite local cache (manifest + downloaded files + history + errors) |
| Event Log → Application → AdCastProSync | Windows Event Viewer'da kritik olaylar |

```powershell
# Son log dosyasını izle
$latest = Get-ChildItem C:\ProgramData\AdCastPro\Logs\sync-*.log |
    Sort-Object LastWriteTime -Descending | Select-Object -First 1
Get-Content $latest.FullName -Tail 50 -Wait
```

---

## RECOVERY POLICY

WiX installer şu policy'i uygular:

| Sıra | Aksiyon | Delay |
|---|---|---|
| 1. Crash | Restart Service | 60 sn |
| 2. Crash | Restart Service | 60 sn |
| 3. Crash | Restart Service | 60 sn |
| Reset period | 1 gün (24 saat içinde sayaç sıfırlanır) | — |

Manual override (production'da admin ihtiyacı varsa):
```powershell
sc failure AdCastProSyncService `
    reset= 86400 `
    actions= restart/60000/restart/60000/restart/60000
```

---

## SORUN GİDERME

### Servis başlamıyor
```powershell
# 1. Event Log'da hata var mı
Get-EventLog -LogName Application -Source AdCastProSync -EntryType Error -Newest 5

# 2. Bağımlılıklar healthy mi?
Get-Service Tcpip, Dnscache

# 3. Executable bulunabiliyor mu?
Test-Path "C:\Program Files\AdCast Pro\Sync Client\AdCastPro.SyncClient.App.exe"

# 4. Manuel console modunda çalıştır (hata gör)
& "C:\Program Files\AdCast Pro\Sync Client\AdCastPro.SyncClient.App.exe" --console
```

### Login problemi
```powershell
# DPAPI token dosyası
dir "C:\Users\$env:USERNAME\AppData\Local\AdCastPro\tokens.dpapi"

# Sıfırla (kullanıcı yeniden login yapar)
Remove-Item "C:\Users\$env:USERNAME\AppData\Local\AdCastPro\tokens.dpapi"
```

### Disk dolu / klasör erişim
```powershell
# Yapılandırılmış klasörler
Get-Content "C:\Program Files\AdCast Pro\Sync Client\appsettings.json" |
    ConvertFrom-Json |
    Select-Object -ExpandProperty SyncClient |
    Select-Object -ExpandProperty Folders

# Disk durumu
Get-PSDrive -PSProvider FileSystem
```

### Yüksek CPU / RAM kullanımı
```powershell
# Servis process ID
$svc = Get-WmiObject Win32_Service -Filter "Name='AdCastProSyncService'"
Get-Process -Id $svc.ProcessId | Select-Object Name, WS, CPU, Threads
```

### Reset edilmesi gereken durumlar
```powershell
# Cache + DB tamamen sıfırla (kullanıcı verisi kaybolur, manifest yeniden çekilir)
Stop-Service AdCastProSyncService
Remove-Item "C:\ProgramData\AdCastPro\sync.db"
Remove-Item "C:\ProgramData\AdCastPro\Logs\*" -Force
Start-Service AdCastProSyncService
```

---

## UNINSTALL (TEMIZ KALDIRMA)

MSI ile kuruldu → MSI ile kaldır:

```powershell
# Tüm AdCast Pro Sync ürünlerini bul
Get-WmiObject Win32_Product -Filter "Name LIKE '%AdCast%'" | Select-Object Name, Version, IdentifyingNumber

# Sessiz kaldırma
msiexec /x "{PRODUCT_CODE}" /quiet /log uninstall.log

# Veya MSI yolu ile
msiexec /x AdCastProSyncClient.msi /quiet
```

MSI uninstall sırasında otomatik olarak:
1. `AdCastProSyncService` durdurulur
2. Service registration silinir (`sc delete AdCastProSyncService` eşdeğeri)
3. Program Files'tan dosyalar silinir
4. Event Log source `AdCastProSync` kaldırılır
5. Start Menu shortcut silinir
6. Run registry entry temizlenir
7. `HKLM\Software\AdCastPro` silinir

**Kullanıcı verisi (`C:\ProgramData\AdCastPro\`) ve log dosyaları SİLİNMEZ** (audit/debug için).
Tam temizlik isteniyorsa elle:
```powershell
Remove-Item -Recurse -Force "C:\ProgramData\AdCastPro"
Remove-Item -Recurse -Force "C:\Users\*\AppData\Local\AdCastPro"
```

---

## UPGRADE (YENİ SÜRÜM GEÇİŞİ)

### Auto-updater ile (önerilen)
Servis kendi auto-updater'ı ile `api.adcastpro.com/api/v1/sync/update` endpoint'ini sorar.
Yeni sürüm varsa MSI'ı `files.adcastpro.com`'dan indirir, SHA-256 + Authenticode verify eder, kurar.

### Manuel
```powershell
# Yeni MSI ile in-place upgrade (MajorUpgrade WiX otomatik handle)
msiexec /i AdCastProSyncClient-1.0.1.msi /quiet /log upgrade.log
```

Eski servis durdurulur, yeni dosyalar deploy edilir, servis yeniden başlar.
Migration: SQLite schema EnsureCreated ile yeni kolonlar eklenir.

### Rollback
```powershell
# Eski MSI'ı reinstall
msiexec /i AdCastProSyncClient-1.0.0.msi /quiet
```

---

## WINDOWS 10 / 11 UYUMLULUK MATRİSİ

| Windows Sürümü | Build | Status | Not |
|---|---|---|---|
| Windows 10 Home | — | ❌ Desteklenmez | Pro/Enterprise için |
| Windows 10 Pro | 17763+ (1809) | ✅ Destekleniyor | Minimum |
| Windows 10 Enterprise | 17763+ | ✅ Destekleniyor | Recommended |
| Windows 10 LTSC | 17763+ | ✅ Destekleniyor | Recommended (broadcast environment) |
| Windows 11 Pro | 22000+ | ✅ Destekleniyor | Native |
| Windows 11 Enterprise | 22000+ | ✅ Destekleniyor | Recommended |
| Windows Server 2019/2022 | — | ⚠ Kullanılabilir | Test edilmedi, broadcast PC için değil |

Launch Condition'lar MSI'da:
```xml
<Launch Condition="VersionNT64 OR VersionNT >= 600" />
<Launch Condition="Privileged" />
```

---

## GÜVENLİK NOTLARI

1. **LocalSystem account** — servis full-trust çalışır. Network access için yeterli, ancak SECURITY DESCRIPTOR (DACL) ile sadece Admin start/stop yapabilir.
2. **Authenticode imza** — Production MSI signtool ile imzalı olmalı (SmartScreen + Defender false-positive engeli).
3. **Auto-update** — yalnız HTTPS URL'lerden, SHA-256 + signature verify zorunlu.
4. **DPAPI token** — `LocalSystem` yerine `CurrentUser` scope (UI tray app'in kullanıcısı).
5. **Event Log** — `AdCastProSync` source sadece installer tarafından create edilir, runtime CreateEventSource gerek yok (admin yetkisi gerektirir).
6. **Network egress** — sadece `api.adcastpro.com`, `files.adcastpro.com`, `sync.adcastpro.com` (corporate firewall'da whitelist).

---

## İLGİLİ DOSYALAR

- `installer/Product.wxs` — WiX v4 MSI tanımı
- `installer/License.rtf` — Sözleşme
- `scripts/build-msi.ps1` — MSI build + sign script
- `src/AdCastPro.SyncClient.App/Program.cs` — Service entry point (`WindowsServiceHelpers.IsWindowsService()` algılama)
- `src/AdCastPro.SyncClient.App/appsettings.json` — Production config
