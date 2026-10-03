# hanotoyikama.com — Hesabım › Muhasebe › e-Arşiv Fatura bölümü

Bu dosya, sitenin sunucusunda çalışan Claude oturumu için hazırlanmıştır. Oturuma şunu yazın:

> github.com/offroadist/fatura deposundaki `deploy/HANOTOYIKAMA-MUHASEBE-GOREV.md` dosyasını oku ve uygula.

## Amaç

Kullanıcı profilinde **Hesabım › Muhasebe** menüsü altına **e-Arşiv Fatura** bölümü eklenecek. Akış:

1. **Kamera**: Sayfa kamerayı açar, canlı görüntüdeki aracı **Kamyonet** ya da **Hususi araç** olarak sınıflandırır
   (cihazda, TensorFlow.js COCO-SSD: `truck` → kamyonet, `car` → hususi). Kullanıcı sonucu onaylar ya da elle değiştirir.
2. **Fiyat**: Araç cinsine göre kullanıcının tanımladığı fiyat gösterilir (KDV dahil), plaka isteğe bağlı; kullanıcı **onaylar**.
3. **Belge**: **Bilgi Fişi** ya da **e-Arşiv Fatura** seçilir.
   - Bilgi Fişi: mali değeri olmayan, kullanıcıya özel sıra numaralı, yazdırılabilir fiş; veritabanına kaydedilir.
   - e-Arşiv Fatura: kullanıcının **Ayarlar'da kaydettiği** GİB kullanıcı kodu/şifresi ile sistem GİB'e giriş yapar,
     tek kalemlik ("Oto yıkama hizmeti - Kamyonet (34 ABC 123)") taslak fatura keser, ardından günün
     **onaylı** ve **onaysız** faturalarını GİB'den çekip listeler. SMS ile imzalama (GİB onayı) mevcut Faturalar bölümünden yapılır.

## Çalışan referans uygulama

`offroadist/fatura` deposu, `claude/nj-rv8gxn` dalı (PR #3), https://sorgu.co/fat adresinde yayında:

| Dosya | İçerik |
| --- | --- |
| `public/arac.html` | Akışın tamamı: kamera + sınıflandırma, fiyat onayı, Bilgi Fişi / e-Arşiv seçimi, alıcı sorgulama, fatura kesme, onaylı/onaysız liste, ayarlar |
| `server.js` | `washInvoiceItem` (KDV dahil tutardan net fiyat; toplam kuruşu kuruşuna tutar), `/api/arac/fis`, `/api/arac/fatura`, `/api/settings`, kayıtlı GİB bilgisiyle giriş (`POST /api/login {saved:true}`), AES-256-GCM `encrypt/decrypt` |
| `index.js` | GİB e-Arşiv istemcisi (giriş, taslak, listeleme, SMS onayı) |
| `deploy/php/*` (PR #2 dalı) | Aynı GİB istemcisi ve iş mantığının PHP sürümü; site PHP ise bunu kullanın |

## Yapılacaklar

1. Sitenin yapısını incele: çatı, kullanıcı/profil modeli, Hesabım menüsünün nasıl tanımlandığı, şifreleme yardımcıları.
2. **Veritabanı** (kullanıcıya özel):
   - `muhasebe_ayar`: `kullanici_id`, `gib_kullanici`, `gib_sifre_sifreli`, `gib_test` (bool), `fiyat_kamyonet`, `fiyat_hususi`, `kdv_orani`, `isletme_ad`, `isletme_adres`
   - `bilgi_fisleri`: `id`, `kullanici_id`, `sira_no` (kullanıcı başına artan), `tarih_saat`, `arac_cinsi`, `plaka`, `tutar`, `kdv_orani`
   - `faturalar` (yerel kopya, isteğe bağlı): `kullanici_id`, `ettn`, `belge_no`, `tarih`, `alici`, `vkn`, `tutar`, `onay_durumu`
3. **Hesabım › Muhasebe › e-Arşiv Fatura** sayfası: `public/arac.html` akışını sitenin şablonuna taşı (aynı adımlar, aynı metinler).
   Ayarlar alt sayfası: GİB kullanıcı kodu/şifre (şifre geri gösterilmez, yalnızca "kayıtlı"), test/canlı, fiyatlar, KDV, işletme adı.
4. **Sunucu uçları** (`server.js` → site):
   - `PUT settings` → ayarları kaydet (şifreyi sitenin şifreleme yardımcısıyla ya da AES-256-GCM ile)
   - `POST arac/fis` → bilgi fişi kaydet, sıra no ver, fiş HTML'i döndür (`arac.html` içindeki `showReceipt` şablonu)
   - `POST arac/fatura` → GİB'e giriş (kayıtlı bilgi), `washInvoiceItem` ile kalem, taslak oluştur, günün listesini onaylı/onaysız ayır
   - Alıcı verilmezse `11111111111` / "Nihai Tüketici"; verilirse VKN/TCKN GİB'den sorgulanır (`getRecipientDataByTaxIDOrTRID`)
   - Tutar ve fiyat **sunucu tarafında** yeniden hesaplanır; istemciden gelen fatura nesnelerine güvenilmez (ETTN ile GİB'den eşleştir)
5. Mevcut Faturalar bölümüyle bağla: onaysız listede "GİB onayına gönder" aynı SMS akışına gitsin.
6. Önce GİB **test** ortamında dene; canlıya ayarlardan geçilsin.

## Dikkat

- Kamera yalnızca **HTTPS** sayfada açılır. Model dosyaları CDN'den (jsdelivr) yüklenir; isterseniz siteye kopyalayın.
- SMS onayı GİB'de resmi fatura keser, geri alınamaz; onay penceresinde açıkça uyar.
- GİB şifreleri yalnızca şifreli saklanır, loglanmaz, arayüze geri gönderilmez.
- 333,33 ₺ gibi bazı KDV dahil tutarlar iki ondalıklı net fiyatla birebir tutmaz; `washInvoiceItem` en yakın kuruşu seçer ve
  sayfa GİB'e giden gerçek toplamı gösterir.
