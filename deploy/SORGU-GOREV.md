# sorgu.co/fat adresine e-Arşiv fatura panelini kurma görevi

Bu dosya, sorgu.co sunucusunda çalışan Claude oturumu (veya kurulumu yapan kişi) için hazırlanmıştır.
Oturuma şunu yazın:

> github.com/offroadist/fatura deposundaki `deploy/SORGU-GOREV.md` dosyasını oku ve uygula.

## Amaç

`offroadist/fatura` deposunun `claude/nj-rv8gxn` dalındaki fatura paneli (Node.js, `server.js` + `public/index.html`)
**https://sorgu.co/fat** adresinde yayınlanacak. Panel `BASE_PATH=/fat` ile alt yolda çalışmaya hazırdır;
hanotoyikama (Hana) faturaları buradan kesilecek.

## Hazır dosyalar (`deploy/sorgu/`)

| Dosya | İçerik |
| --- | --- |
| `kur.sh` | Tek komutla kurulum/güncelleme: `fatura` sistem kullanıcısı, `/opt/fatura` klonu, `npm ci`, `/etc/fatura/fatura.env`, systemd servisi |
| `fatura.service` | systemd birimi (127.0.0.1:3000'de dinler, root değil) |
| `fatura.env.example` | Ortam değişkenleri; `kur.sh` ilk kurulumda `COOKIE_SECRET` üretip `/etc/fatura/fatura.env` olarak kopyalar |
| `nginx-fat.conf` | sorgu.co server bloğuna eklenecek `location /fat` ters vekil bloğu |

## Adımlar

1. Sunucuyu incele: web sunucusu (nginx/apache), sorgu.co'nun server bloğu ve HTTPS sertifikası, Node sürümü (`node -v`, 18+ gerekli).
2. Kurulumu çalıştır (root):
   ```
   curl -fsSL https://raw.githubusercontent.com/offroadist/fatura/claude/nj-rv8gxn/deploy/sorgu/kur.sh | sudo bash
   ```
   Node yoksa betik kurulum komutunu yazdırır; kurup tekrar çalıştır.
3. `deploy/sorgu/nginx-fat.conf` içindeki `location /fat` bloğunu sorgu.co'nun **HTTPS** server bloğuna ekle, `nginx -t && systemctl reload nginx`.
   Apache ise karşılığı: `ProxyPass /fat http://127.0.0.1:3000/fat` ve `ProxyPassReverse /fat http://127.0.0.1:3000/fat` (mod_proxy, mod_proxy_http).
4. Kontrol: `https://sorgu.co/fat` → giriş ekranı açılmalı; `https://sorgu.co/fat/api/me` → `401` JSON dönmeli.
   `curl -sI https://sorgu.co/fat` → `302` ve `Location: /fat/`.
5. Tarayıcıdan GİB **test** kullanıcı kodu ile giriş yapıp taslak oluşturma ve listelemeyi dene (`FATURA_TEST=1` açık gelir).
6. Canlıya geçiş: `/etc/fatura/fatura.env` içinden `FATURA_TEST=1` satırını sil, `systemctl restart fatura`.
7. İsteğe bağlı WhatsApp doğrulama: `deploy/wa-otp.php` dosyasını sorgu.co'da HTTPS ile erişilen bir yere koy, içindeki `WA_HELPER`
   yolunu `sq_wa_gonder()` fonksiyonunun bulunduğu dosyaya ayarla; `openssl rand -hex 32` ile ürettiğin anahtarı hem PHP'de hem
   `fatura.env` içinde `WA_OTP_URL` / `WA_OTP_KEY` olarak tanımla, servisi yeniden başlat.

Güncelleme için aynı `kur.sh` komutu tekrar çalıştırılır; `fatura.env` korunur.

## Node çalıştırılamıyorsa (yalnızca PHP barındırma)

`claude/hanotoyikama-gorev-deploy-qqn1le` dalındaki (PR #2) `deploy/php/` klasörü aynı iş mantığının bağımlılıksız PHP sürümüdür
(`GibEarsiv.php`, `FaturaYardimci.php`, `faturalar-api.php`). `public/index.html` içindeki `api/...` çağrıları
`deploy/php/README.md` içindeki rota eşlemesine göre `faturalar-api.php?islem=...` adreslerine çevrilerek `/fat/` altında yayınlanır.

## Dikkat

- SMS onayı **GİB'de resmi fatura keser, geri alınamaz**; canlıya geçmeden önce test ortamında dene.
- GİB şifresi sunucuda saklanmaz; yalnızca 30 dakikalık GİB oturum token'ı bellekte tutulur. Panel yalnızca HTTPS arkasında çalıştırılmalı (`COOKIE_SECURE=1`).
- Panelin kendi kullanıcı girişi yoktur; GİB kullanıcı kodu ve şifresi girişin kendisidir. İstenirse nginx tarafında `auth_basic` ile ek bir kapı konabilir.
