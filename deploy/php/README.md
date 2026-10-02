# GİB e-Arşiv istemcisi (PHP)

`index.js` ve `server.js`'deki GİB çağrılarının ve iş mantığının PHP karşılığı.
`deploy/HANOTOYIKAMA-GOREV.md` görevinde sitenin PHP olması durumunda kullanılmak üzere hazırlandı.
Sunucuya bağımlılık eklemez: PHP 7.4+, `curl`, `json`, `mbstring`, `openssl` yeterlidir.

| Dosya | İçerik |
| --- | --- |
| `GibEarsiv.php` | GİB portal istemcisi: giriş, taslak oluşturma, listeleme, SMS onayı, HTML/ZIP, silme, alıcı sorgulama, kullanıcı bilgisi. `index.js` ile aynı fonksiyon adları ve fatura alanları. |
| `FaturaYardimci.php` | `server.js` iş mantığı: toplam ve KDV hesabı (`hesapla`), ETTN ile GİB'den fatura bulma (`findInvoicesByETTN`), telefon biçimleme, şifrenin veritabanında şifreli saklanması (`sifrele` / `coz`). |
| `faturalar-api.php` | Admin paneli için hazır JSON API ucu. Yalnızca üstteki "SİTEYE BAĞLANACAK YERLER" bölümü siteye göre doldurulur. |
| `son-faturalar.php` | Komut satırı aracı: son N gündeki faturaları listeler, HTML'lerini klasöre kaydeder. |
| `tests/test.php` | Ağ bağlantısı gerektirmeyen testler: `php deploy/php/tests/test.php` |

## Son 1 ayın faturalarını listeleme ve görüntüleme

Panel kurulmadan, sunucuda doğrudan:

```
cd deploy/php
read -s -p "GİB şifresi: " GIB_SIFRE; echo; export GIB_SIFRE
GIB_KULLANICI=KULLANICI_KODU php son-faturalar.php --sadece-onayli --indir=./faturalar
```

Tarih, belge no, alıcı, tutar, durum ve ETTN sütunlarıyla son 30 günün faturalarını yazar ve her
faturanın HTML'ini `./faturalar/` içine `TARIH-BELGENO-ETTN.html` adıyla kaydeder; dosyalar tarayıcıda
açılıp yazdırılabilir. `--gun=60` aralığı değiştirir, `--test` test portalını kullanır, `--json` ham listeyi verir.

Panelde aynı iş `GET faturalar-api.php?islem=liste` ile yapılır: tarih verilmezse son 30 gün döner;
her satırdaki ETTN ile `?islem=html&ettn=...&onayli=1` görüntüler, `?islem=zip&ettn=...&onayli=1` indirir.

## Siteye bağlama

1. Üç PHP dosyasını admin panelinin yanına (ör. `admin/faturalar/`) kopyalayın.
2. `faturalar-api.php` içinde dört fonksiyonu sitenin kendi altyapısıyla doldurun:
   - `fatura_admin_kontrol()`: mevcut admin oturum/yetki kontrolü, yetkisizse `FaturaHata(403)`.
   - `fatura_csrf_kontrol()`: sitenin CSRF yöntemi.
   - `fatura_gib_ayarlari()`: ayarlar tablosundan kullanıcı kodu, şifre (`FaturaYardimci::coz()` ile çözülür) ve test/canlı seçimi.
   - `fatura_yerel_kaydet()`: isteğe bağlı; `faturalar` tablosuna yerel kopya.
3. Ayarlar sayfasına "GİB e-Arşiv" bölümü ekleyin: kullanıcı kodu, şifre, ortam (test/canlı).
   Şifreyi `FaturaYardimci::sifrele($sifre, getenv('FATURA_SIFRE_ANAHTARI'))` ile kaydedin.
   Şifre arayüze geri gönderilmez; yalnızca "kayıtlı" bilgisi gösterilir.
   Anahtar `openssl rand -hex 32` ile üretilip sunucunun ortam değişkenine yazılır.
4. Panel arayüzü için referans daldaki `public/index.html` kullanılır; `/api/...` istekleri
   `faturalar-api.php?islem=...` adreslerine çevrilir (eşleme aşağıda).
5. Önce **test** ortamıyla deneyin (`test => true`); canlıya ayarlardan geçilir.

### Rota eşlemesi (`server.js` → `faturalar-api.php`)

| server.js | faturalar-api.php |
| --- | --- |
| `GET /api/me` | `GET ?islem=durum` |
| `GET /api/invoices?startDate&endDate` | `GET ?islem=liste&baslangic&bitis` (boşsa son 30 gün) |
| `POST /api/invoices` | `POST ?islem=olustur` |
| `POST /api/invoices/cancel` | `POST ?islem=sil` `{ettn, baslangic, bitis, neden}` |
| `GET /api/invoice-html?ettn&signed` | `GET ?islem=html&ettn&onayli` |
| `GET /api/invoice-download?ettn&signed` | `GET ?islem=zip&ettn&onayli` |
| `GET /api/recipient?id` | `GET ?islem=alici&id` |
| `POST /api/sms/send` | `POST ?islem=sms-gonder` |
| `POST /api/sms/verify` | `POST ?islem=sms-dogrula` `{kod, oid, baslangic, bitis, ettnler}` |

`/api/login` ve `/api/logout` yoktur: GİB girişi ayarlardaki bilgilerle otomatik yapılır,
token PHP oturumunda 30 dakika tutulur ve gerekirse yenilenir. WhatsApp telefon doğrulaması
(`/api/whatsapp/*`) panele özgüdür ve bu uca taşınmadı; gerekirse `wa-otp.php` ile aynı
yöntemle eklenebilir.

### `faturalar` tablosu (isteğe bağlı yerel kopya)

```sql
CREATE TABLE faturalar (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ettn CHAR(36) NOT NULL UNIQUE,
  belge_no VARCHAR(32) DEFAULT '',
  tarih CHAR(10) NOT NULL,            -- GG/AA/YYYY
  alici VARCHAR(255) DEFAULT '',
  vkn VARCHAR(11) DEFAULT '',
  tutar DECIMAL(12,2) DEFAULT 0,
  durum VARCHAR(16) NOT NULL,         -- Onaylanmadı / Onaylandı
  olusturan_admin INT NULL,
  olusturma_zamani TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Doğrudan kullanım

```php
require 'GibEarsiv.php';
require 'FaturaYardimci.php';

$gib   = new GibEarsiv(true); // test portalı
$token = $gib->getToken('KULLANICI', 'SIFRE');

$fatura = FaturaYardimci::hesapla([
    'taxIDOrTRID' => '11111111111',
    'title'       => 'Örnek Müşteri',
    'fullAddress' => 'X Sok. No: 3 İstanbul',
    'items'       => [['name' => 'Oto yıkama', 'quantity' => 1, 'unitPrice' => 500, 'VATRate' => 20]],
]);
$taslak = $gib->createDraftInvoice($token, $fatura);

// İmzalama (fatura kesme) iki adımdır ve geri alınamaz:
$oid    = $gib->sendSignSMSCode($token, $gib->getPhoneNumber($token));
$kayit  = FaturaYardimci::findInvoicesByETTN($gib, $token, $taslak['date'], $taslak['date'], [$taslak['uuid']]);
$gib->verifySignSMSCode($token, 'SMS_KODU', $oid, $kayit);
```

## `index.js`'den farklar

- `irsaliyeTarihi` alanı `dispatchDate`'ten, `halRusumuTutari` alanı `halRusumuTutari`'nden okunur
  (JS'de sırasıyla `discountDate` ve `hammaliyeTutari` okunuyordu; yazım hatası).
- Fatura notu için sayı-yazı çevirisi paketsiz yapılır: `2500 → "İKİ BİN BEŞ YÜZ"`
  (JS paketi `"İKİ BİN, BEŞ YÜZ"` ve `1000000` için hatalı `"BİR MİLYON, BİN"` üretiyordu).
- Taslak silme ve imzalama için GİB'den gelen kayıt kullanılır; istemciden gelen fatura nesnesi kabul edilmez.
