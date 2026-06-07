# AdCast Pro Sync Client v1.0 — Pilot Radio Test Plan

**Hedef:** 3-5 pilot radyoda Windows desktop client'ın gerçek yayın koşullarında test edilmesi.

---

## ÖN HAZIRLIK (Pilot Başlamadan Önce)

### Backend Production Kontrolleri
- [ ] `adcastpro.com` DNS A record → VDS IP
- [ ] `api.adcastpro.com`, `files.adcastpro.com`, `sync.adcastpro.com` DNS aktif
- [ ] Let's Encrypt sertifika `*.adcastpro.com` veya 4 ayrı cert
- [ ] Docker stack production'da çalışıyor (`docker compose ps` healthy)
- [ ] Migration uygulandı (`sync_clients`, `sync_activity` tabloları)
- [ ] SignalR Hub backend tarafında deploy edildi (`/hubs/manifest`)
- [ ] Auto-update endpoint test edildi (`curl /api/v1/sync/update`)

### MSI Build & Sign
- [ ] `cd sync-client/installer && dotnet build -c Release` ✓
- [ ] Authenticode sertifikası ile imzala:
  ```powershell
  signtool sign /tr http://timestamp.digicert.com /td sha256 /fd sha256 /a `
      bin\Release\AdCastProSyncClient.msi
  ```
- [ ] Hash hesapla:
  ```powershell
  Get-FileHash bin\Release\AdCastProSyncClient.msi -Algorithm SHA256
  ```
- [ ] MSI'ı `files.adcastpro.com/releases/AdCastProSyncClient-1.0.0.msi`'ye yükle

### Pilot Radyo Seçimi (3-5 radyo, farklı bölge)
- [ ] **R1 — Marmara / İstanbul** (yoğun trafik, kritik test)
- [ ] **R2 — Ege / İzmir** (ulusal + bölgesel mix)
- [ ] **R3 — İç Anadolu / Ankara** (yedek)
- [ ] **R4 — Akdeniz / Antalya** (turist sezonu, reklam ağırlıklı)
- [ ] **R5 — Karadeniz / Trabzon** (bölgesel içerik)

Her radyo için:
- Kullanıcı adı + şifre üret (admin panelden)
- Test PC spec'i kayıt: Windows version, RAM, disk, internet hızı
- Mevcut radyo otomasyonu (Solea/RCS/RadioDJ vs.) — sync klasörü bu otomasyona feed edilecek

---

## TEST AŞAMALARI

### Aşama 1 — KURULUM (3 gün, R1+R2)

| # | Test | Beklenen | Başarı Kriteri |
|---|---|---|---|
| 1.1 | MSI çift tıkla → kurulum sihirbazı | Setup wizard açılır | Türkçe arayüz |
| 1.2 | API URL otomatik (`https://api.adcastpro.com`) | Config dosyasında |Override gerek yok |
| 1.3 | Klasör seç: D:\\AdCastPro\\News | Sihirbazda klasör browser | Klasör oluşturulur |
| 1.4 | Klasör seç: D:\\AdCastPro\\Ads | İkinci klasör adımı | OK |
| 1.5 | Klasör seç: D:\\AdCastPro\\MediaPlan | Üçüncü klasör adımı | OK |
| 1.6 | Kurulum bitti → tray'de logo görünüyor | TaskbarIcon visible | Sağ tık menü çalışır |
| 1.7 | Start Menu shortcut | "AdCast Pro Sync" entry | Çalıştırılabilir |
| 1.8 | Windows + R → "AdCastProSyncService" | services.msc'de görünür | Running status |
| 1.9 | %LOCALAPPDATA%\\AdCastPro\\sync.db var | SQLite dosyası | EnsureCreated tetiklenmiş |
| 1.10 | %LOCALAPPDATA%\\AdCastPro\\Logs\\ | Serilog dosyası yazılıyor | Daily rolling |

### Aşama 2 — AUTH + İLK SYNC (1 gün, R1+R2)

| # | Test | Beklenen | Başarı Kriteri |
|---|---|---|---|
| 2.1 | LoginWindow açılır (token yok) | Username+password formu | Tab navigation çalışır |
| 2.2 | Yanlış şifre dener | "Kullanıcı adı/şifre hatalı" toast | UI bloklanmaz |
| 2.3 | Doğru şifre + Login | DPAPI'ye token yazılır, MainWindow açılır | `tokens.dpapi` dosyası var |
| 2.4 | MainWindow → "Radyo: X" görünür | /me endpoint cevabı | Radyo adı, frekans, bölge |
| 2.5 | Settings → klasör override | FolderBrowserDialog | Yeni path kaydedilir |
| 2.6 | İlk manifest poll (60s içinde) | Logs'ta "Manifest indirildi" | ETag SQLite'da |
| 2.7 | Dosya iniyor (Temp → checksum → Final) | İlk haber dosyası D:\\AdCastPro\\News'e iner | Tam boy + SHA256 ok |
| 2.8 | Tray icon YEŞIL | BroadcastReadinessService | Tooltip "HAZIR — ..." |

### Aşama 3 — HABER SAATİ SIMÜLASYON (1 gün, R1)

08:00 yayını için 07:30'da test:

| # | Saat | Test | Beklenen |
|---|---|---|---|
| 3.1 | 07:30 | Manifest polling | 60s normal interval |
| 3.2 | 07:40 | Manifest polling hızlanıyor | 30s (20dk eşiği) |
| 3.3 | 07:50 | Polling 15s | 10dk eşiği |
| 3.4 | 07:55 | Polling 5s | 5dk eşiği |
| 3.5 | 07:45 | Tray YEŞIL — dosya hazır | "haber08 ready" log |
| 3.6 | 07:55 | Eğer dosya hâlâ yoksa → KIRMIZI | 5dk eşiği KRİTİK |
| 3.7 | 08:00 | Radyo otomasyonu dosyayı çaldı mı | Studio'da işitilir kanıt |

**Tüm 7 kuşak için (08/10/12/14/16/18/20) tekrarlanacak.**

### Aşama 4 — STRES TESTLERİ (2 gün, R1+R2)

| # | Test | Yöntem | Beklenen |
|---|---|---|---|
| 4.1 | Internet kopması | Ethernet kabloyu çek | Circuit breaker açık, polling fallback |
| 4.2 | 5 dakika down sonra resume | Kabloyu tak | "Reconnected" log, queue devam |
| 4.3 | Disk dolu senaryo | Test partition'ı doldur | Pre-flight reddet, tray TURUNCU |
| 4.4 | Klasör silindi | Manual delete D:\\AdCastPro\\News | Uyarı toast, sync durdur |
| 4.5 | PC sleep → uyandır | Sleep + 30 dk + wake | Service auto-resume, manifest poll |
| 4.6 | PC restart | Reboot | Tray auto-start, login skip, manifest poll |
| 4.7 | Antivirus interference | Defender real-time scan | False positive yok, MSI signed |
| 4.8 | UAC prompt | Standart user account | Per-user install, UAC istemez |

### Aşama 5 — YAYINCILIK KALİTESİ (3 gün, tüm pilotlar)

**Sürekli izleme — her pilot 72 saat:**

| Metrik | Hedef | Ölçüm |
|---|---|---|
| Uptime | %99.9 | `sync_clients.last_seen_at` continuity |
| Yarım dosya hedef klasörde | 0 adet | Manual klasör inspection + grep `.partial` |
| Yanlış bölge dosyası | 0 adet | Audit log scan `sync_download_denied` |
| 15dk öncesi hazır oranı | %100 | `BroadcastReadiness GREEN @ T-15min` |
| Checksum failed | 0 adet | `sync_activity.checksum_ok=false` row |
| RAM kullanımı | <200 MB | Task Manager izleme |
| CPU kullanımı | <%5 ortalama | Task Manager |
| Disk I/O | <50 MB/s peak | Task Manager |

### Aşama 6 — SIGNALR PUSH NOTIFICATION (1 gün)

| # | Test | Beklenen |
|---|---|---|
| 6.1 | Admin panel → haber yükle | Backend SignalR event publish |
| 6.2 | Client SignalR Hub'a bağlı mı | Logs'ta "Connected: hub" |
| 6.3 | Manifest refresh anında tetiklendi | Polling interval beklemeden |
| 6.4 | Emergency broadcast simülasyonu | Tray notification + KIRMIZI |
| 6.5 | Hub bağlantısı kopması | "Closed" log + auto-reconnect |
| 6.6 | Reconnect başarılı | "Reconnected: hub" log |

### Aşama 7 — AUTO-UPDATE (1 gün, R1)

| # | Test | Beklenen |
|---|---|---|
| 7.1 | Backend yeni MSI yayınla | `/api/v1/sync/update` 1.0.1 döner |
| 7.2 | Client update check (6h) | UpdateAvailable event |
| 7.3 | SHA-256 verify | Eşleşirse devam |
| 7.4 | Authenticode verify | Geçerli imza |
| 7.5 | msiexec /quiet kurulum | Eski versiyon kaldırılır, yenisi gelir |
| 7.6 | Servis restart | Yeni versiyonla başlar |
| 7.7 | Rollback simülasyonu | Bozuk MSI ile dene → eski versiyona dön |

---

## BAŞARI KRİTERLERİ (GO/NO-GO)

### GO Şartları (tüm pilotlar)

- [ ] 72 saat sürekli çalışma %99.9 uptime
- [ ] 0 yarım dosya, 0 yanlış bölge, 0 checksum fail
- [ ] Tüm 7 haber kuşağında 15dk öncesi YEŞIL
- [ ] SignalR push notification anında tetikleniyor
- [ ] Auto-update başarılı (eski versiyona rollback dahil)
- [ ] PC restart sonrası auto-start
- [ ] Internet kesintisi sonrası graceful resume
- [ ] RAM <200 MB, CPU <%5 ortalama

### NO-GO Şartları

- ❌ 1+ checksum fail
- ❌ 1+ yanlış bölge dosyası (audit'te `sync_download_denied`)
- ❌ Tray icon stuck (UI freeze)
- ❌ Service crash > 1 kez/24 saat
- ❌ Memory leak (24 saatte +50MB+)
- ❌ Disk I/O sürekli %100

---

## RAPORLAMA

Her pilot için günlük rapor:

```
PILOT: R<numara> <radyo-adı>
TARİH: YYYY-MM-DD
UPTIME: HH:MM (yüzde)
SYNC SAYISI: N (başarılı/başarısız)
ORTALAMA INDIRME SÜRESİ: Xms
EKSİK DOSYA: N
HATA: <list>
ÖZEL NOTLAR: <metin>
```

72 saat sonunda toplam rapor + GO/NO-GO kararı.

---

## ROLLBACK PLANI (Pilot Başarısız Olursa)

1. **Hot-fix gerek yoksa:** Pilot durdurulur, MSI uninstall edilir, manuel sync devam eder
2. **Critical bug bulunursa:** GitHub Issue açılır, fix branch'i + acil release
3. **Mimari problem:** Pilot ertelenir, refactor sonrası tekrar
4. **Eski versiyona dönüş:** Rollback MSI ile (auto-updater desteklenir)

---

## PİLOT SONRASI — GENEL GO İÇİN ŞARTLAR

1. ✅ 3-5 pilot radyo başarılı 72 saat
2. ✅ Tüm haber kuşaklarında YEŞIL
3. ✅ Real-world load test (50+ eş zamanlı sync)
4. ✅ Auto-update başarılı en az 1 versiyon geçişi
5. ✅ Security audit clean
6. ✅ Dokümantasyon güncel

**Sonra:** 50 radyoya rollout → 200'e → tüm partner ağına.
