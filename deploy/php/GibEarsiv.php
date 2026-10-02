<?php
// GİB e-Arşiv portal istemcisi (index.js'in PHP karşılığı).
//
// Tüm çağrılar POST {BASE_URL}/earsiv-services/dispatch adresine
// cmd, callid, pageName, token, jp (JSON) alanlarıyla form-urlencoded gider.
// Giriş POST /earsiv-services/assos-login adresine yapılır.
//
// Gereksinimler: PHP 7.4+, curl, json, mbstring.
//
// Kullanım:
//   $gib   = new GibEarsiv(true);                 // true: test portalı, false: canlı
//   $token = $gib->getToken($kullanici, $sifre);
//   $liste = $gib->getAllInvoicesByDateRange($token, '01/10/2026', '31/10/2026');

declare(strict_types=1);

final class GibException extends RuntimeException
{
    /** @var array GİB'in döndürdüğü ham yanıt */
    private $response;

    public function __construct(string $message, array $response = [])
    {
        parent::__construct($message);
        $this->response = $response;
    }

    public function getResponse(): array
    {
        return $this->response;
    }
}

final class GibEarsiv
{
    public const PROD_URL = 'https://earsivportal.efatura.gov.tr';
    public const TEST_URL = 'https://earsivportaltest.efatura.gov.tr';

    private const COMMANDS = [
        'createDraftInvoice' => ['EARSIV_PORTAL_FATURA_OLUSTUR', 'RG_BASITFATURA'],
        'getAllInvoicesByDateRange' => ['EARSIV_PORTAL_TASLAKLARI_GETIR', 'RG_BASITTASLAKLAR'],
        'signDraftInvoice' => ['EARSIV_PORTAL_FATURA_HSM_CIHAZI_ILE_IMZALA', 'RG_BASITTASLAKLAR'],
        'getInvoiceHTML' => ['EARSIV_PORTAL_FATURA_GOSTER', 'RG_BASITTASLAKLAR'],
        'cancelDraftInvoice' => ['EARSIV_PORTAL_FATURA_SIL', 'RG_BASITTASLAKLAR'],
        'getRecipientDataByTaxIDOrTRID' => ['SICIL_VEYA_MERNISTEN_BILGILERI_GETIR', 'RG_BASITFATURA'],
        'sendSignSMSCode' => ['EARSIV_PORTAL_SMSSIFRE_GONDER', 'RG_SMSONAY'],
        'verifySignSMSCode' => ['EARSIV_PORTAL_SMSSIFRE_DOGRULA', 'RG_SMSONAY'],
        'getPhoneNumber' => ['EARSIV_PORTAL_TELEFONNO_SORGULA', 'RG_BASITTASLAKLAR'],
        'getUserData' => ['EARSIV_PORTAL_KULLANICI_BILGILERI_GETIR', 'RG_KULLANICI'],
        'updateUserData' => ['EARSIV_PORTAL_KULLANICI_BILGILERI_KAYDET', 'RG_KULLANICI'],
    ];

    /** @var bool */
    private $test;

    /**
     * İsteği gönderen fonksiyon: function(string $method, string $url, array $form, array $headers): string
     * Varsayılan cURL'dür; testlerde sahte yanıt vermek için değiştirilebilir.
     *
     * @var callable
     */
    private $transport;

    /** @var int */
    private $timeout;

    public function __construct(bool $test = false, ?callable $transport = null, int $timeout = 30)
    {
        $this->test = $test;
        $this->transport = $transport ?: [$this, 'curlTransport'];
        $this->timeout = $timeout;
    }

    public function isTest(): bool
    {
        return $this->test;
    }

    public function baseUrl(): string
    {
        return $this->test ? self::TEST_URL : self::PROD_URL;
    }

    // ---------------------------------------------------------------- Oturum

    public function getToken(string $userName, string $password): string
    {
        $json = $this->postForm('/earsiv-services/assos-login', [
            'assoscmd' => $this->test ? 'login' : 'anologin',
            'rtype' => 'json',
            'userid' => $userName,
            'sifre' => $password,
            'sifre2' => $password,
            'parola' => '1',
        ], '/intragiris.html');

        if (empty($json['token'])) {
            throw new GibException(self::errorMessage($json), $json);
        }
        return (string) $json['token'];
    }

    public function logout(string $token): array
    {
        return $this->postForm('/earsiv-services/assos-login', [
            'assoscmd' => 'logout',
            'rtype' => 'json',
            'token' => $token,
        ], '/intragiris.html');
    }

    /**
     * GİB'e komut gönderir. Yanıtta error varsa GibException fırlatır.
     */
    public function runCommand(string $token, string $command, string $pageName, array $data = []): array
    {
        $json = $this->postForm('/earsiv-services/dispatch', [
            'cmd' => $command,
            'callid' => self::uuid(),
            'pageName' => $pageName,
            'token' => $token,
            'jp' => json_encode(
                $data ?: new stdClass(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ], '/login.jsp');

        if (!empty($json['error'])) {
            throw new GibException(self::errorMessage($json), $json);
        }
        return $json;
    }

    private function command(string $name): array
    {
        return self::COMMANDS[$name];
    }

    // ---------------------------------------------------------------- Fatura

    /**
     * Taslak fatura oluşturur. $invoice anahtarları index.js ile aynıdır:
     * date ("GG/AA/YYYY"), time ("SS:DD:ss"), taxIDOrTRID, taxOffice, title, name, surname,
     * fullAddress, district, city, email, items[] (name, quantity, unitType, unitPrice, price,
     * VATRate, VATAmount), totalVAT, grandTotal, grandTotalInclVAT, paymentTotal, uuid (isteğe bağlı).
     *
     * Dönüş: ['date' => ..., 'uuid' => ..., 'data' => GİB mesajı, ...GİB yanıtı]
     */
    public function createDraftInvoice(string $token, array $invoice): array
    {
        $items = [];
        foreach ($invoice['items'] ?? [] as $item) {
            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unitPrice'] ?? 0);
            $items[] = [
                'iskontoArttm' => $item['discount'] ?? 'İskonto',
                'malHizmet' => (string) $item['name'],
                'miktar' => $quantity == floor($quantity) ? (int) $quantity : $quantity,
                'birim' => $item['unitType'] ?? 'C62',
                'birimFiyat' => self::money($unitPrice),
                'fiyat' => self::money($item['price'] ?? 0),
                'iskontoOrani' => $item['discountRate'] ?? 0,
                'iskontoTutari' => self::money($item['discountAmount'] ?? 0),
                'iskontoNedeni' => $item['discountReason'] ?? '',
                'malHizmetTutari' => self::money($quantity * $unitPrice),
                'kdvOrani' => (string) (int) round((float) ($item['VATRate'] ?? 0)),
                'vergiOrani' => $item['taxRate'] ?? 0,
                'kdvTutari' => self::money($item['VATAmount'] ?? 0),
                'vergininKdvTutari' => self::money($item['VATAmountOfTax'] ?? 0),
            ];
        }

        $returnItems = [];
        foreach ($invoice['returnItems'] ?? [] as $ignored) {
            $returnItems[] = new stdClass();
        }

        $data = [
            'faturaUuid' => $invoice['uuid'] ?? self::uuid(),
            'belgeNumarasi' => $invoice['documentNumber'] ?? '',
            'faturaTarihi' => $invoice['date'],
            'saat' => $invoice['time'],
            'paraBirimi' => $invoice['currency'] ?? 'TRY',
            'dovzTLkur' => $invoice['currencyRate'] ?? '0',
            'faturaTipi' => $invoice['invoiceType'] ?? 'SATIS',
            'hangiTip' => $invoice['hangiTip'] ?? 'Buyuk',
            'siparisNumarasi' => $invoice['orderNumber'] ?? '',
            'siparisTarihi' => $invoice['orderDate'] ?? '',
            'irsaliyeNumarasi' => $invoice['dispatchNumber'] ?? '',
            'irsaliyeTarihi' => $invoice['dispatchDate'] ?? '',
            'fisNo' => $invoice['slipNumber'] ?? '',
            'fisTarihi' => $invoice['slipDate'] ?? '',
            'fisSaati' => $invoice['slipTime'] ?? ' ',
            'fisTipi' => $invoice['slipType'] ?? ' ',
            'zRaporNo' => $invoice['zReportNumber'] ?? '',
            'okcSeriNo' => $invoice['okcSerialNumber'] ?? '',
            'vknTckn' => $invoice['taxIDOrTRID'] ?? '11111111111',
            'aliciUnvan' => $invoice['title'] ?? '',
            'aliciAdi' => $invoice['name'] ?? '',
            'aliciSoyadi' => $invoice['surname'] ?? '',
            'bulvarcaddesokak' => $invoice['fullAddress'] ?? '',
            'binaAdi' => $invoice['buildingName'] ?? '',
            'binaNo' => $invoice['buildingNumber'] ?? '',
            'kapiNo' => $invoice['doorNumber'] ?? '',
            'kasabaKoy' => $invoice['town'] ?? '',
            'mahalleSemtIlce' => $invoice['district'] ?? '',
            'sehir' => $invoice['city'] ?? ' ',
            'ulke' => $invoice['country'] ?? 'Türkiye',
            'postaKodu' => $invoice['zipCode'] ?? '',
            'tel' => $invoice['phoneNumber'] ?? '',
            'fax' => $invoice['faxNumber'] ?? '',
            'eposta' => $invoice['email'] ?? '',
            'websitesi' => $invoice['webSite'] ?? '',
            'vergiDairesi' => $invoice['taxOffice'] ?? '',
            'komisyonOrani' => $invoice['commissionRate'] ?? 0,
            'navlunOrani' => $invoice['freightRate'] ?? 0,
            'hammaliyeOrani' => $invoice['hammaliyeOrani'] ?? 0,
            'nakliyeOrani' => $invoice['nakliyeOrani'] ?? 0,
            'komisyonTutari' => $invoice['komisyonTutari'] ?? '0',
            'navlunTutari' => $invoice['navlunTutari'] ?? '0',
            'hammaliyeTutari' => $invoice['hammaliyeTutari'] ?? '0',
            'nakliyeTutari' => $invoice['nakliyeTutari'] ?? '0',
            'komisyonKDVOrani' => $invoice['komisyonKDVOrani'] ?? 0,
            'navlunKDVOrani' => $invoice['navlunKDVOrani'] ?? 0,
            'hammaliyeKDVOrani' => $invoice['hammaliyeKDVOrani'] ?? 0,
            'nakliyeKDVOrani' => $invoice['nakliyeKDVOrani'] ?? 0,
            'komisyonKDVTutari' => $invoice['komisyonKDVTutari'] ?? '0',
            'navlunKDVTutari' => $invoice['navlunKDVTutari'] ?? '0',
            'hammaliyeKDVTutari' => $invoice['hammaliyeKDVTutari'] ?? '0',
            'nakliyeKDVTutari' => $invoice['nakliyeKDVTutari'] ?? '0',
            'gelirVergisiOrani' => $invoice['gelirVergisiOrani'] ?? 0,
            'bagkurTevkifatiOrani' => $invoice['bagkurTevkifatiOrani'] ?? 0,
            'gelirVergisiTevkifatiTutari' => $invoice['gelirVergisiTevkifatiTutari'] ?? '0',
            'bagkurTevkifatiTutari' => $invoice['bagkurTevkifatiTutari'] ?? '0',
            'halRusumuOrani' => $invoice['halRusumuOrani'] ?? 0,
            'ticaretBorsasiOrani' => $invoice['ticaretBorsasiOrani'] ?? 0,
            'milliSavunmaFonuOrani' => $invoice['milliSavunmaFonuOrani'] ?? 0,
            'digerOrani' => $invoice['digerOrani'] ?? 0,
            'halRusumuTutari' => $invoice['halRusumuTutari'] ?? '0',
            'ticaretBorsasiTutari' => $invoice['ticaretBorsasiTutari'] ?? '0',
            'milliSavunmaFonuTutari' => $invoice['milliSavunmaFonuTutari'] ?? '0',
            'digerTutari' => $invoice['digerTutari'] ?? '0',
            'halRusumuKDVOrani' => $invoice['halRusumuKDVOrani'] ?? 0,
            'ticaretBorsasiKDVOrani' => $invoice['ticaretBorsasiKDVOrani'] ?? 0,
            'milliSavunmaFonuKDVOrani' => $invoice['milliSavunmaFonuKDVOrani'] ?? 0,
            'digerKDVOrani' => $invoice['digerKDVOrani'] ?? 0,
            'halRusumuKDVTutari' => $invoice['halRusumuKDVTutari'] ?? '0',
            'ticaretBorsasiKDVTutari' => $invoice['ticaretBorsasiKDVTutari'] ?? '0',
            'milliSavunmaFonuKDVTutari' => $invoice['milliSavunmaFonuKDVTutari'] ?? '0',
            'digerKDVTutari' => $invoice['digerKDVTutari'] ?? '0',
            'iadeTable' => $returnItems,
            'ozelMatrahTutari' => $invoice['specialTaxBaseAmount'] ?? '0',
            'ozelMatrahOrani' => $invoice['specialTaxBaseRate'] ?? 0,
            'ozelMatrahVergiTutari' => self::money($invoice['specialTaxBaseTaxAmount'] ?? 0),
            'vergiCesidi' => $invoice['taxType'] ?? ' ',
            'malHizmetTable' => $items,
            'tip' => 'İskonto',
            'matrah' => self::money($invoice['grandTotal']),
            'malhizmetToplamTutari' => self::money($invoice['grandTotal']),
            'toplamIskonto' => self::money($invoice['totalDiscount'] ?? 0),
            'hesaplanankdv' => self::money($invoice['totalVAT']),
            'vergilerToplami' => self::money($invoice['totalVAT']),
            'vergilerDahilToplamTutar' => self::money($invoice['grandTotalInclVAT']),
            'toplamMasraflar' => $invoice['toplamMasraflar'] ?? '0',
            'odenecekTutar' => self::money($invoice['paymentTotal']),
            'not' => self::priceToText((float) $invoice['paymentTotal']),
        ];

        $result = $this->runCommand($token, ...array_merge($this->command('createDraftInvoice'), [$data]));

        return array_merge(
            ['date' => $data['faturaTarihi'], 'uuid' => $data['faturaUuid']],
            $result
        );
    }

    /**
     * Tarih aralığındaki (GG/AA/YYYY) tüm faturaları döner. Her kayıtta
     * ettn, belgeNumarasi, belgeTarihi, aliciUnvanAdi, aliciVknTckn, tutar, onayDurumu bulunur.
     */
    public function getAllInvoicesByDateRange(string $token, string $startDate, string $endDate): array
    {
        $result = $this->runCommand($token, ...array_merge($this->command('getAllInvoicesByDateRange'), [[
            'baslangic' => $startDate,
            'bitis' => $endDate,
            'hangiTip' => '5000/30000',
            'table' => [],
        ]]));
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    public function findInvoice(string $token, string $date, string $uuid): ?array
    {
        foreach ($this->getAllInvoicesByDateRange($token, $date, $date) as $invoice) {
            if (($invoice['ettn'] ?? null) === $uuid) {
                return $invoice;
            }
        }
        return null;
    }

    /**
     * ☢️ Faturayı keser; vergi sisteminde mali veri oluşturur, geri alınamaz.
     * $draftInvoice getAllInvoicesByDateRange ile GİB'den alınmış kayıt olmalıdır.
     */
    public function signDraftInvoice(string $token, array $draftInvoice): array
    {
        return $this->runCommand($token, ...array_merge($this->command('signDraftInvoice'), [[
            'imzalanacaklar' => [$draftInvoice],
        ]]));
    }

    public function getInvoiceHTML(string $token, string $uuid, bool $signed = false): string
    {
        $result = $this->runCommand($token, ...array_merge($this->command('getInvoiceHTML'), [[
            'ettn' => $uuid,
            'onayDurumu' => $signed ? 'Onaylandı' : 'Onaylanmadı',
        ]]));
        return (string) ($result['data'] ?? '');
    }

    public function getDownloadURL(string $token, string $uuid, bool $signed = false): string
    {
        return $this->baseUrl() . '/earsiv-services/download?' . http_build_query([
            'token' => $token,
            'ettn' => $uuid,
            'belgeTip' => 'FATURA',
            'onayDurumu' => $signed ? 'Onaylandı' : 'Onaylanmadı',
            'cmd' => 'downloadResource',
        ]) . '&';
    }

    /** ZIP (html + xml) içeriğini ham olarak döner. */
    public function downloadInvoiceZip(string $token, string $uuid, bool $signed = false): string
    {
        $body = ($this->transport)('GET', $this->getDownloadURL($token, $uuid, $signed), [], [
            'Referer: ' . $this->baseUrl() . '/login.jsp',
        ]);
        if ($body === '' || strncmp($body, "PK", 2) !== 0) {
            throw new GibException('Fatura indirilemedi.');
        }
        return $body;
    }

    /** Yalnızca onaylanmamış taslaklar silinebilir. GİB'in mesajını döner. */
    public function cancelDraftInvoice(string $token, string $reason, array $draftInvoice)
    {
        $result = $this->runCommand($token, ...array_merge($this->command('cancelDraftInvoice'), [[
            'silinecekler' => [$draftInvoice],
            'aciklama' => $reason,
        ]]));
        return $result['data'] ?? null;
    }

    /** VKN/TCKN'den alıcı bilgilerini (unvan, ad, soyad, vergi dairesi, adres) sorgular. */
    public function getRecipientDataByTaxIDOrTRID(string $token, string $taxIDOrTRID): array
    {
        $result = $this->runCommand($token, ...array_merge($this->command('getRecipientDataByTaxIDOrTRID'), [[
            'vknTcknn' => $taxIDOrTRID,
        ]]));
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    // ---------------------------------------------------------------- SMS onayı

    /** GİB'de kayıtlı cep telefonu (yoksa null). */
    public function getPhoneNumber(string $token): ?string
    {
        $result = $this->runCommand($token, ...$this->command('getPhoneNumber'));
        $phone = $result['data']['telefon'] ?? null;
        return $phone !== null && $phone !== '' ? (string) $phone : null;
    }

    /** SMS şifresi gönderir, işlem kimliğini (oid) döner. */
    public function sendSignSMSCode(string $token, string $phone): string
    {
        $result = $this->runCommand($token, ...array_merge($this->command('sendSignSMSCode'), [[
            'CEPTEL' => $phone,
            'KCEPTEL' => false,
            'TIP' => '',
        ]]));
        $oid = $result['data']['oid'] ?? $result['oid'] ?? null;
        if (!$oid) {
            throw new GibException('SMS gönderilemedi.', $result);
        }
        return (string) $oid;
    }

    /**
     * ☢️ SMS şifresiyle taslakları imzalar (fatura keser, geri alınamaz).
     * $draftInvoices getAllInvoicesByDateRange ile alınmış, onayDurumu "Onaylanmadı" kayıtlar olmalıdır.
     */
    public function verifySignSMSCode(string $token, string $smsCode, string $operationId, array $draftInvoices)
    {
        $result = $this->runCommand($token, ...array_merge($this->command('verifySignSMSCode'), [[
            'SIFRE' => $smsCode,
            'OID' => $operationId,
            'OPR' => 1,
            'DATA' => array_values($draftInvoices),
        ]]));
        return $result['data'] ?? null;
    }

    // ---------------------------------------------------------------- Kullanıcı

    public function getUserData(string $token): array
    {
        $result = $this->runCommand($token, ...$this->command('getUserData'));
        $d = $result['data'] ?? [];
        return [
            'taxIDOrTRID' => $d['vknTckn'] ?? null,
            'title' => $d['unvan'] ?? null,
            'name' => $d['ad'] ?? null,
            'surname' => $d['soyad'] ?? null,
            'registryNo' => $d['sicilNo'] ?? null,
            'mersisNo' => $d['mersisNo'] ?? null,
            'taxOffice' => $d['vergiDairesi'] ?? null,
            'fullAddress' => $d['cadde'] ?? null,
            'buildingName' => $d['apartmanAdi'] ?? null,
            'buildingNumber' => $d['apartmanNo'] ?? null,
            'doorNumber' => $d['kapiNo'] ?? null,
            'town' => $d['kasaba'] ?? null,
            'district' => $d['ilce'] ?? null,
            'city' => $d['il'] ?? null,
            'zipCode' => $d['postaKodu'] ?? null,
            'country' => $d['ulke'] ?? null,
            'phoneNumber' => $d['telNo'] ?? null,
            'faxNumber' => $d['faksNo'] ?? null,
            'email' => $d['ePostaAdresi'] ?? null,
            'webSite' => $d['webSitesiAdresi'] ?? null,
            'businessCenter' => $d['isMerkezi'] ?? null,
        ];
    }

    public function updateUserData(string $token, array $user)
    {
        $result = $this->runCommand($token, ...array_merge($this->command('updateUserData'), [[
            'vknTckn' => $user['taxIDOrTRID'] ?? '',
            'unvan' => $user['title'] ?? '',
            'ad' => $user['name'] ?? '',
            'soyad' => $user['surname'] ?? '',
            'sicilNo' => $user['registryNo'] ?? '',
            'mersisNo' => $user['mersisNo'] ?? '',
            'vergiDairesi' => $user['taxOffice'] ?? '',
            'cadde' => $user['fullAddress'] ?? '',
            'apartmanAdi' => $user['buildingName'] ?? '',
            'apartmanNo' => $user['buildingNumber'] ?? '',
            'kapiNo' => $user['doorNumber'] ?? '',
            'kasaba' => $user['town'] ?? '',
            'ilce' => $user['district'] ?? '',
            'il' => $user['city'] ?? '',
            'postaKodu' => $user['zipCode'] ?? '',
            'ulke' => $user['country'] ?? '',
            'telNo' => $user['phoneNumber'] ?? '',
            'faksNo' => $user['faxNumber'] ?? '',
            'ePostaAdresi' => $user['email'] ?? '',
            'webSitesiAdresi' => $user['webSite'] ?? '',
            'isMerkezi' => $user['businessCenter'] ?? '',
        ]]));
        return $result['data'] ?? null;
    }

    // ---------------------------------------------------------------- Yardımcılar

    /** Rastgele UUID v4. */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** 118.5 -> "118.50" (GİB tutarları nokta ayraçlı metin bekler). */
    public static function money($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** 118.0 -> "YÜZ ON SEKİZ LIRA SIFIR KURUS" (faturanın "not" alanı). */
    public static function priceToText(float $price): string
    {
        [$main, $sub] = explode('.', self::money($price));
        return self::numberToText((int) $main) . ' LIRA ' . self::numberToText((int) $sub) . ' KURUS';
    }

    /** Tam sayıyı büyük harf Türkçe yazıya çevirir: 2500 -> "İKİ BİN BEŞ YÜZ". */
    public static function numberToText(int $number): string
    {
        if ($number === 0) {
            return 'SIFIR';
        }
        if ($number < 0) {
            return 'EKSİ ' . self::numberToText(-$number);
        }

        static $ones = ['', 'BİR', 'İKİ', 'ÜÇ', 'DÖRT', 'BEŞ', 'ALTI', 'YEDİ', 'SEKİZ', 'DOKUZ'];
        static $tens = ['', 'ON', 'YİRMİ', 'OTUZ', 'KIRK', 'ELLİ', 'ALTMIŞ', 'YETMİŞ', 'SEKSEN', 'DOKSAN'];
        static $scales = [[1000000000000, 'TRİLYON'], [1000000000, 'MİLYAR'], [1000000, 'MİLYON'], [1000, 'BİN']];

        $parts = [];
        foreach ($scales as [$scale, $word]) {
            if ($number >= $scale) {
                $count = intdiv($number, $scale);
                // "BİR BİN" yerine "BİN"; milyon ve üstünde "BİR MİLYON".
                if (!($count === 1 && $scale === 1000)) {
                    $parts[] = self::numberToText($count);
                }
                $parts[] = $word;
                $number %= $scale;
            }
        }
        if ($number >= 100) {
            $hundreds = intdiv($number, 100);
            if ($hundreds > 1) {
                $parts[] = $ones[$hundreds];
            }
            $parts[] = 'YÜZ';
            $number %= 100;
        }
        if ($number >= 10) {
            $parts[] = $tens[intdiv($number, 10)];
            $number %= 10;
        }
        if ($number > 0) {
            $parts[] = $ones[$number];
        }
        return implode(' ', $parts);
    }

    /** GİB yanıtındaki messages[].text alanlarını tek mesajda birleştirir. */
    public static function errorMessage(array $json): string
    {
        $texts = [];
        foreach ($json['messages'] ?? [] as $message) {
            if (!empty($message['text'])) {
                $texts[] = (string) $message['text'];
            }
        }
        return $texts ? implode(' ', $texts) : 'GİB isteği başarısız oldu.';
    }

    // ---------------------------------------------------------------- HTTP

    private function postForm(string $path, array $form, string $referrerPath): array
    {
        $body = ($this->transport)('POST', $this->baseUrl() . $path, $form, [
            'Accept: */*',
            'Accept-Language: tr,en-US;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
            'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
            'Pragma: no-cache',
            'Referer: ' . $this->baseUrl() . $referrerPath,
        ]);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            // Portal bazen HTML (bakım sayfası, süresi dolmuş oturum) döner.
            throw new GibException('GİB portalından geçersiz yanıt alındı.', ['raw' => mb_substr($body, 0, 500)]);
        }
        return $json;
    }

    /** Varsayılan taşıyıcı: cURL. */
    private function curlTransport(string $method, string $url, array $form, array $headers): string
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new GibException('GİB portalına bağlanılamadı: ' . $error);
        }
        if ($status >= 500) {
            throw new GibException('GİB portalı şu anda yanıt vermiyor (HTTP ' . $status . ').');
        }
        return (string) $body;
    }
}
