# GitHub / Vben Research Notes

Bu proje için referans alınan ana açık kaynak depo:

- [vbenjs/vue-vben-admin](https://github.com/vbenjs/vue-vben-admin)

## Araştırma Özeti

Resmi depo aşağıdaki mimari sinyalleri veriyor:

- Vue 3, Vite ve TypeScript tabanlı modern bir admin panel yapısı kullanıyor.
- Monorepo düzeni ile ekranlar, paketler ve ortak yardımcılar ayrıştırılmış.
- Route tabanlı yetkilendirme ve tema sistemleri ürünleşmiş durumda.
- GitHub üstündeki README ve klasör yapısı, büyük ekiplerde sürdürülebilir modülleme için iyi bir örnek oluşturuyor.

## Bu Projeye Uygulanan İlkeler

- Harita odaklı Matrix ekranı, tek bir tablo yerine operasyonel karar vermeyi kolaylaştıran bir dashboard düzenine taşındı.
- Bölge kartları, canlı durumları doğrudan Türkiye haritası üzerinde gösteriyor.
- Sağ panel, seçili bölgenin toplu özetini sunuyor.
- Alt kısımda klasik matrix görünümü korunarak hızlı operasyon akışı devam ediyor.
- `PartCode` sözleşmesi 4 içerik türüne indirildi ve tüm ekranlarla uyumlu hale getirildi.

## Tasarım Kararları

- Koyu hero alanı ve hafif cam efektleri, Vben’in kurumsal panel hissini desteklemek için kullanıldı.
- Harita resmi `public/images` altında tutuldu; build sırasında güvenli ve deterministik referans sağlıyor.
- Bölge hotspot’ları, harita üzerinde dağıtılmış operasyon kartları gibi davranıyor.
- KPI kartları, durum yoğunluğunu tek bakışta gösteriyor.

## Uygulama Notu

Harita görselleştirmesi, proje içindeki Vben Admin yapısıyla uyumlu şekilde geliştirilmiştir. Amaç, yalnızca estetik bir ekran değil, aynı zamanda günlük yayın operasyonu için hızlı ve okunabilir bir kontrol yüzeyi sağlamaktır.

