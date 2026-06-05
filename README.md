# Radio SaaS Platform

Docker tabanli, bolge bazli medya dagitim ve sponsor enjeksiyon platformu iskeleti.

## Kapsam

- 7 bolge: Marmara, Ege, Akdeniz, Karadeniz, Ic Anadolu, Dogu Anadolu, Guneydogu Anadolu
- 4 parca: Haber, Spor, Ekonomi, Hava Durumu
- Depolama: MinIO
- Veritabani: PostgreSQL
- Medya isleme: FFmpeg + Liquidsoap
- API: PHP 8.2+ OOP, Service-Repository pattern
- Frontend: Vben Admin uyumlu Vue 3 / TypeScript servis katmani

## Ana Endpointler

- `GET /api/v1/feeds/{stationSlug}/{part}.json`
- `GET /api/v1/feeds/{stationSlug}/{part}.xml`
- `GET /api/v1/feeds/{stationSlug}/{part}.m3u`
- `GET /api/v1/streams/{stationSlug}/{part}`
- `POST /api/v1/media/upload`

## Notlar

- Bu iskelet framework bagimsizdir; Slim, Laravel veya Symfony uzerine kolayca tasinabilir.
- Presigned URL uretilmesi icin AWS SDK kullanilir, hedef MinIO ile tam uyumludur.
- Uretim ortaminda imaj etiketlerini sabitlemeniz ve secret'lari `.env` ile yonetmeniz onerilir.
- Production senaryosu icin `docker-compose.prod.yml` ve adim adim kontrol listesi icin `docs/production-checklist.md` dosyasina bakin.
- Tek komutluk kurulum icin proje kokundeki `setup.sh` betigini kullanabilirsiniz.
- Lokal Vben giris testi icin varsayilan kullanici: `admin / 123456`.
- Uretim akisinda frontend ve API gateway `http://localhost:8080` adresinden Nginx tarafindan servis edilir. Vite gelistirme sunucusu gerektiginde `docker compose -f docker-compose.prod.yml --profile dev up -d frontend` ile `http://localhost:3000` adresinde ayrica baslatilabilir.
- Matrix Dashboard ve yonetim ekranlari 4 icerik turu standardini kullanir.
