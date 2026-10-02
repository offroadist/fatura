# hanotoyikama.com admin paneline "Faturalar" bölümü ekleme görevi

Bu dosya, sunucuda çalışan Claude oturumu için hazırlanmıştır. Oturuma şunu yazın:

> github.com/offroadist/fatura deposundaki `deploy/HANOTOYIKAMA-GOREV.md` dosyasını oku ve uygula.

## Amaç

hanotoyikama.com'un mevcut admin paneline **Faturalar** adında yeni bir bölüm eklenecek. Bu bölüm,
muhasebeciden alınan GİB e-Arşiv kullanıcı kodu ve şifresiyle:

1. Taslak fatura oluşturacak (alıcı VKN/TCKN sorgulama, kalemler, KDV ve toplamların otomatik hesabı)
2. Taslakları seçip **GİB onayına** gönderecek (GİB'de kayıtlı telefona SMS şifresi gider, şifreyle imzalanır)
3. e-Arşiv portalındaki faturaları tarih aralığına göre listeleyecek, görüntüleyecek, ZIP indirecek, taslak silecek

## Kaynak

Çalışan referans uygulama `offroadist/fatura` deposunun `claude/nedir-dizayn-sistem-dnr59i` dalında (PR #1):

| Dosya | İçerik |
| --- | --- |
| `index.js` | GİB e-Arşiv portal API istemcisi (giriş, taslak oluşturma, listeleme, SMS onayı, HTML/ZIP, silme, alıcı sorgulama) |
| `server.js` | İş mantığı: oturum, toplam hesabı (`buildInvoice`), ETTN'ye göre fatura bulma, SMS onay akışı, hata eşleme |
| `public/index.html` | Panel arayüzü (Faturalar listesi + Yeni fatura formu + SMS onay penceresi) |
| `README.md` | Akışın açıklaması |

Site PHP ise `index.js`'deki GİB çağrıları birebir PHP'ye taşınabilir; hepsi
`POST {BASE_URL}/earsiv-services/dispatch` adresine `cmd`, `callid`, `pageName`, `token`, `jp` (JSON) alanlarıyla
form-urlencoded istek. Giriş `POST /earsiv-services/assos-login` (`assoscmd=anologin&rtype=json&userid=…&sifre=…&sifre2=…&parola=1`).
Komut adları ve `jp` içerikleri `index.js`'deki `COMMANDS` ve her fonksiyonda açıkça yazılı.

## Yapılacaklar

1. Önce sitenin yapısını incele: dil/çatı, admin panelinin menü ve yetki sistemi, veritabanı erişimi, mevcut ayarlar sayfası.
2. **Ayarlar**: Admin paneline "GİB e-Arşiv" ayarları ekle: kullanıcı kodu, şifre, test/canlı ortam seçimi.
   Şifreyi veritabanında şifreleyerek sakla (sitede mevcut bir gizli anahtar/şifreleme yardımcısı varsa onu kullan).
   Şifre hiçbir zaman arayüze geri gönderilmesin; yalnızca "kayıtlı" bilgisi gösterilsin.
3. **Faturalar** menüsü (yalnızca admin yetkisi):
   - Liste: tarih aralığı filtresi, GİB'den çekilen faturalar, durum rozeti (Onaylandı / Onaylanmadı), Görüntüle, ZIP indir, taslak Sil
   - Yeni fatura: referans formun aynısı; toplamlar sunucu tarafında yeniden hesaplanmalı (istemciye güvenme)
   - GİB onayı: seçili taslaklar için SMS gönder → kod gir → imzala (referans `server.js` içindeki `/api/sms/send` ve `/api/sms/verify`)
   - Faturaların yerel kopyasını tutmak isteniyorsa: `faturalar` tablosu (ettn, belge_no, tarih, alici, vkn, tutar, durum, olusturan_admin, olusturma_zamani)
4. İstemciden gelen fatura nesnelerine güvenme: imzalama ve silme için her zaman GİB'den güncel listeyi çekip ETTN ile eşleştir (referans: `findInvoicesByETTN`).
5. GİB'den dönen hata mesajlarını (hatalı şifre, SMS kodu hatalı vb.) kullanıcıya göster; diğer hataları logla.
6. Önce **test ortamı** (`https://earsivportaltest.efatura.gov.tr`) ile dene; canlıya ayarlardan geçilsin.

## Dikkat

- İmzalama (SMS onayı) **GİB'de resmi fatura keser, geri alınamaz**. Onay penceresinde bunu açıkça uyar.
- GİB bilgileri ve şifreler koda değil, veritabanına/ortam değişkenlerine yazılır.
- Mevcut sitenin kod stilini ve admin panel şablonunu koru; yeni bir çatı ekleme.
