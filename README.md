# 🧾 Fatura.js

eArşiv sistemi üzerinde fatura oluşturmanızı sağlar.

### Alternatifler

| Dil | Repo | Geliştirici |
| --- | ---- | ----------- |
| PHP | https://github.com/AdemAliDurmus/fatura | Adem Ali Durmuş |
| PHP | https://github.com/furkankadioglu/efatura | Furkan Kadıoğlu |

> Bu sistem **https://earsivportal.efatura.gov.tr/** adresini kullanarak bu sistem üzerinden fatura oluşturmanızı sağlar.

> Bu sistem GİB'e tabi **şahış şirketi** ya da **şirket** hesapları ile çalışır ve bu kişilikler adına resmi fatura oluşturur. Kesilen faturaları https://earsivportal.efatura.gov.tr/ adresinden görüntüleyebilir ya da bu kütüphane ile indirebilirsiniz.

#### Kullanıcı Adı ve Parola Bilgileri

> [https://earsivportal.efatura.gov.tr/intragiris.html](https://earsivportal.efatura.gov.tr/intragiris.html) adresindeki parola ekranında kullanılan kullanıcı kodu ve parola ile bu paketi kullanabilirsiniz.
> ℹ️ Bu **kullanıcı kodu ve parola bilgilerini** muhasebecinizden ya da **GİB - İnteraktif Vergi Dairesi**'nden edinebilirsiniz.

## Web Paneli

Kod yazmadan kullanmak için basit bir panel bulunur:

```
npm install
npm start            # http://127.0.0.1:3000
FATURA_TEST=1 npm start   # GİB test portalı
```

1. Muhasebecinizden aldığınız e-Arşiv kullanıcı kodu ve şifresiyle giriş yapın (şifre saklanmaz, yalnızca GİB oturumu tutulur).
2. **Yeni fatura** sekmesinden alıcıyı ve kalemleri girip taslak oluşturun. Toplamlar ve KDV otomatik hesaplanır.
3. **Faturalar** sekmesinde "Onaylanmadı" durumundaki taslakları seçip **GİB onayına gönder**'e basın; GİB'de kayıtlı telefona gelen SMS şifresiyle fatura kesilir.
4. Portaldaki tüm faturaları tarih aralığına göre listeleyebilir, görüntüleyip yazdırabilir ya da ZIP olarak indirebilirsiniz.

#### WhatsApp ile telefon doğrulama

**Çıkış**'a ilk basıldığında, bu cihazda doğrulanmış bir telefon yoksa panel numarayı (GİB'de kayıtlı numara önerilir) WhatsApp'tan gönderilen 6 haneli kodla doğrular ve imzalı bir çereze kaydeder. Sonraki çıkışlarda tekrar sormaz; giriş ekranı doğrulanmış numarayı gösterir.

Gönderim WhatsApp Cloud API ile, cevaplama.com WhatsApp Business hesabındaki **0850 840 52 14** (Netgsm) numarasından yapılır. Bunun için o numaranın Meta'da kayıtlı ve onaylı bir *authentication* (kod kopyala butonlu) şablonunun olması gerekir:

```
WHATSAPP_TOKEN=...              # Meta sistem kullanıcısı erişim token'ı
WHATSAPP_PHONE_NUMBER_ID=...    # 0850 840 52 14 numarasının Phone Number ID'si
WHATSAPP_TEMPLATE=...           # onaylı authentication şablonunun adı
WHATSAPP_TEMPLATE_LANG=tr
COOKIE_SECRET=...               # uzun, rastgele ve sabit bir değer
COOKIE_SECURE=1                 # HTTPS arkasında çalışırken
```

Bu değerleri sunucunun ortam değişkenlerine yazın; repoya eklemeyin.

> Panel varsayılan olarak yalnızca `127.0.0.1` üzerinde dinler. Sunucuya kurulacaksa önüne HTTPS'li bir ters vekil (nginx vb.) koyun.

## Yükleme

```
npm install fatura
```

## Kullanım

Oldukça kolay bir kullanıma sahiptir:

#### `createInvoiceAndGetDownloadURL(user, pass, invoice, { sign })`

Bu method ile fatura oluşturulup imzalanır ve indirme adresi döner.

```js
const fatura = require('fatura')

const faturaURL = await fatura.createInvoiceAndGetDownloadURL(
    'GIB Kullanıcı Adı', 
    'GIB Parolası', {
        ... faturaDetayları
    },
    // Varsayılan olarak sign: true gönderilir.
    { sign: false }
)
```

#### `createInvoiceAndGetHTML(user, pass, invoice, { sign })`

Bu method ile fatura oluşturulup imzalanır ve fatura HTML'i döner. Bu HTML'i `iframe` içerisinde gösterip yazdırılmasını sağlayabilirsiniz.

```js
const fatura = require('fatura')

const faturaHTML = await fatura.createInvoiceAndGetHTML(
    'GIB Kullanıcı Adı', 
    'GIB Parolası', {
        ... faturaDetayları
    },
    // Varsayılan olarak sign: true gönderilir.
    { sign: false }
)
```

---

## Diğer Fonksiyonlar

Muhtemelen pek gerekmeyecek diğer alt fonksiyonlar:

#### `enableTestMode()`

Bu fonksiyonu çağırdığınızda sistem **https://earsivportaltest.efatura.gov.tr** adresini kullanmaya geçer. Fakat burada sistem bazen dolu olabilir.

#### `getToken(user, pass): String`

**eFatura Portal**'ını kullanabileceğiniz `token`'ı döner.

#### `createDraftInvoice(token, invoiceDetails): Object`

eFatura.gov.tr'de fatura direkt oluşmaz. Önce **Taslak** fatura oluşturmak gerekir. `createDraftInvoice` size taslak bir fatura oluşturacaktır. `invoiceDetails` parametresi aşağıdaki şekilde bir JavaScript nesnesi kabul eder:

> ℹ️ UUID vermezseniz yeni bir UUID atanır.

```js
{
    // UUID vermezseniz yeni bir UUID yaratılacaktır.
    uuid: "4c72cb57-b72d-4812-ac48-0a0bce83e771",

    date: "08/02/2020",
    time: "09:07:48",
    taxIDOrTRID: "11111111111",
    taxOffice: "Beylikduzu",
    title: "FATIH AKIN",
    name: "",
    surname: "",
    fullAddress: "X Sok. Y Cad. No: 3 Z Istanbul",
    items: [
        {
        name: "Stickker",
        quantity: 1,
        unitPrice: 100,
        price: 100,
        VATRate: 18,
        VATAmount: 18
        }
    ],
    totalVAT: 18,
    grandTotal: 100.0,
    grandTotalInclVAT: 118.0,
    paymentTotal: 118.0
}
```

#### `findInvoice(token, { date, uuid }): Object`

Her fatura için bir `uuid` oluşturulur. Bu `uuid` kullanılarak faturanın oluşturulduğu tarih içerisindeki taslak fatura bulunur ve getirilir. Bu veri içerisinde **imzalama** esnasında gerekecek **GIB Belge Numarası** bulunur. Bu method ile diğer taslak faturalara da erişebilirsiniz.

#### `getAllInvoicesByDateRange(token, { startDate, endDate }): Array`

İki tarih arasındaki tüm faturaları döner.

#### `signDraftInvoice(token, draftInvoice): void`

☢️ Fatura imzalama faturanın kesilmesi işlemidir ve **vergi sisteminde mali veri oluşturur.** Bu nedenle dikkatli kullanınız.

`findDraftInvoice` methodu ile alınan veri `draftInvoice` parametresine gönderilerek bulunan faturanın imzalanması sağlanır.

#### `getDownloadURL(token, uuid): String`

İmzalanmış faturaların efatura.gov.tr üzerinden indirme bağlantısını döner ve `.zip` formatında indirir. Bu dosya içerisinde `html` ve `xml` dosyaları bulunur.

#### `getInvoiceHTML(token, uuid): String`

İmzalanmış faturaların efatura.gov.tr üzerinden HTML içerigini döner. Bu metni dosyaya kaydedebilir ya da `iframe` üzerinden yazdırılmasını sağlayabilirsiniz.

#### `cancelDraftInvoice(token, reason, draftInvoice): String`

Taslak halindeki faturalar iptal edilebilir.

## Lisans
MIT

----

> ☢️ **BU PAKET VERGİYE TABİ OLAN MALİ VERİ OLUŞTURUR.** BU PAKET NEDENİYLE OLUŞABİLECEK SORUNLARDAN BU PAKET SORUMLU TUTULAMAZ, RİSK KULLANANA AİTTİR. RİSKLİ GÖRÜYORSANIZ KULLANMAYINIZ.
