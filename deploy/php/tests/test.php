<?php
// Ağ bağlantısı gerektirmeyen testler. Çalıştırma: php deploy/php/tests/test.php

declare(strict_types=1);

require_once __DIR__ . '/../FaturaYardimci.php';

$basarisiz = 0;
$toplam = 0;

function esit($beklenen, $gercek, string $ad): void
{
    global $basarisiz, $toplam;
    $toplam++;
    if ($beklenen === $gercek) {
        echo "  ok   $ad\n";
        return;
    }
    $basarisiz++;
    echo "  FAIL $ad\n       beklenen: " . var_export($beklenen, true) . "\n       gerçek:   " . var_export($gercek, true) . "\n";
}

function dogru(bool $kosul, string $ad): void
{
    esit(true, $kosul, $ad);
}

/** Kaydedici sahte taşıyıcı: istekleri toplar, sıradaki yanıtı döner. */
function sahteTasiyici(array &$istekler, array $yanitlar): callable
{
    return function (string $method, string $url, array $form, array $headers) use (&$istekler, &$yanitlar): string {
        $istekler[] = ['method' => $method, 'url' => $url, 'form' => $form, 'headers' => $headers];
        $yanit = array_shift($yanitlar);
        return is_string($yanit) ? $yanit : json_encode($yanit, JSON_UNESCAPED_UNICODE);
    };
}

echo "Sayı-yazı\n";
esit('SIFIR', GibEarsiv::numberToText(0), '0');
esit('YÜZ ON SEKİZ', GibEarsiv::numberToText(118), '118');
esit('BİN BİR', GibEarsiv::numberToText(1001), '1001');
esit('İKİ BİN BEŞ YÜZ', GibEarsiv::numberToText(2500), '2500');
esit('ON İKİ BİN ÜÇ YÜZ KIRK BEŞ', GibEarsiv::numberToText(12345), '12345');
esit('BİR MİLYON', GibEarsiv::numberToText(1000000), '1000000');
esit('İKİ MİLYON BEŞ YÜZ BİN', GibEarsiv::numberToText(2500000), '2500000');
esit('BİR MİLYAR', GibEarsiv::numberToText(1000000000), '1000000000');
esit('YÜZ ON SEKİZ LIRA SIFIR KURUS', GibEarsiv::priceToText(118.0), 'priceToText 118.00');
esit('YÜZ ON SEKİZ LIRA ELLİ KURUS', GibEarsiv::priceToText(118.5), 'priceToText 118.50');
esit('SIFIR LIRA BEŞ KURUS', GibEarsiv::priceToText(0.05), 'priceToText 0.05');
esit('1234.50', GibEarsiv::money(1234.5), 'money');
dogru((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', GibEarsiv::uuid()), 'uuid v4');

echo "Giriş\n";
$istekler = [];
$gib = new GibEarsiv(false, sahteTasiyici($istekler, [['token' => 'TKN123']]));
esit('TKN123', $gib->getToken('user', 'p&ss'), 'token döner');
esit(GibEarsiv::PROD_URL . '/earsiv-services/assos-login', $istekler[0]['url'], 'canlı giriş adresi');
esit('anologin', $istekler[0]['form']['assoscmd'], 'canlıda anologin');
esit('p&ss', $istekler[0]['form']['sifre2'], 'şifre olduğu gibi forma konur');
dogru(in_array('Referer: ' . GibEarsiv::PROD_URL . '/intragiris.html', $istekler[0]['headers'], true), 'giriş referer');

$istekler = [];
$gib = new GibEarsiv(true, sahteTasiyici($istekler, [['token' => 'T']]));
$gib->getToken('u', 'p');
esit('login', $istekler[0]['form']['assoscmd'], 'testte login');
esit(GibEarsiv::TEST_URL . '/earsiv-services/assos-login', $istekler[0]['url'], 'test giriş adresi');

$istekler = [];
$gib = new GibEarsiv(false, sahteTasiyici($istekler, [['messages' => [['type' => 'error', 'text' => 'Kullanıcı kodu veya şifre hatalı.']]]]));
try {
    $gib->getToken('u', 'yanlis');
    dogru(false, 'hatalı giriş GibException fırlatmalı');
} catch (GibException $e) {
    esit('Kullanıcı kodu veya şifre hatalı.', $e->getMessage(), 'GİB mesajı aktarılır');
}

$gib = new GibEarsiv(false, sahteTasiyici($istekler, ['<html>bakım</html>']));
try {
    $gib->getToken('u', 'p');
    dogru(false, 'HTML yanıt GibException fırlatmalı');
} catch (GibException $e) {
    esit('GİB portalından geçersiz yanıt alındı.', $e->getMessage(), 'HTML yanıt hatası');
}

echo "Komut gönderimi\n";
$istekler = [];
$gib = new GibEarsiv(false, sahteTasiyici($istekler, [['data' => [['ettn' => 'A', 'onayDurumu' => 'Onaylanmadı']]]]));
$liste = $gib->getAllInvoicesByDateRange('TKN', '01/10/2026', '31/10/2026');
esit(1, count($liste), 'liste döner');
$form = $istekler[0]['form'];
esit('EARSIV_PORTAL_TASLAKLARI_GETIR', $form['cmd'], 'cmd');
esit('RG_BASITTASLAKLAR', $form['pageName'], 'pageName');
esit('TKN', $form['token'], 'token');
esit(GibEarsiv::PROD_URL . '/earsiv-services/dispatch', $istekler[0]['url'], 'dispatch adresi');
esit(['baslangic' => '01/10/2026', 'bitis' => '31/10/2026', 'hangiTip' => '5000/30000', 'table' => []], json_decode($form['jp'], true), 'jp içeriği');
dogru(in_array('Referer: ' . GibEarsiv::PROD_URL . '/login.jsp', $istekler[0]['headers'], true), 'dispatch referer');

$istekler = [];
$gib = new GibEarsiv(false, sahteTasiyici($istekler, [['data' => ['telefon' => '5321234567']], ['data' => ['oid' => 'OID1']], ['data' => 'ok']]));
esit('5321234567', $gib->getPhoneNumber('T'), 'telefon');
esit('{}', $istekler[0]['form']['jp'], 'veri yoksa jp boş nesne');
esit('OID1', $gib->sendSignSMSCode('T', '5321234567'), 'sms oid');
esit(['CEPTEL' => '5321234567', 'KCEPTEL' => false, 'TIP' => ''], json_decode($istekler[1]['form']['jp'], true), 'sms jp');
$gib->verifySignSMSCode('T', '123456', 'OID1', [['ettn' => 'A']]);
esit(['SIFRE' => '123456', 'OID' => 'OID1', 'OPR' => 1, 'DATA' => [['ettn' => 'A']]], json_decode($istekler[2]['form']['jp'], true), 'sms doğrulama jp');

$istekler = [];
$gib = new GibEarsiv(false, sahteTasiyici($istekler, [['error' => true, 'messages' => [['text' => 'SMS şifresi hatalı.']]]]));
try {
    $gib->verifySignSMSCode('T', '000000', 'OID1', []);
    dogru(false, 'error yanıtı fırlatmalı');
} catch (GibException $e) {
    esit('SMS şifresi hatalı.', $e->getMessage(), 'komut hatası mesajı');
}

echo "İndirme\n";
$gib = new GibEarsiv(true, sahteTasiyici($istekler, []));
$url = $gib->getDownloadURL('T', 'ETTN-1', true);
dogru(strpos($url, GibEarsiv::TEST_URL . '/earsiv-services/download?') === 0, 'indirme adresi');
dogru(strpos($url, 'onayDurumu=Onayland%C4%B1') !== false, 'onayDurumu kodlanır');
dogru(strpos($url, 'cmd=downloadResource&') !== false, 'cmd=downloadResource');
$istekler = [];
$gib = new GibEarsiv(false, sahteTasiyici($istekler, ["PK\x03\x04zip"]));
esit("PK\x03\x04zip", $gib->downloadInvoiceZip('T', 'ETTN-1'), 'zip içeriği');
esit('GET', $istekler[0]['method'], 'zip GET ile alınır');
$gib = new GibEarsiv(false, sahteTasiyici($istekler, ['<html>hata</html>']));
try {
    $gib->downloadInvoiceZip('T', 'ETTN-1');
    dogru(false, 'zip olmayan yanıt fırlatmalı');
} catch (GibException $e) {
    esit('Fatura indirilemedi.', $e->getMessage(), 'zip hatası');
}

echo "Hesaplama\n";
$fatura = FaturaYardimci::hesapla([
    'taxIDOrTRID' => '11111111111',
    'title' => 'Örnek Müşteri',
    'date' => '15/10/2026',
    'items' => [
        ['name' => 'Yıkama', 'quantity' => 2, 'unitPrice' => '150,50', 'VATRate' => 20],
        ['name' => 'Cila', 'quantity' => 1, 'unitPrice' => 99.99, 'VATRate' => 10],
        ['name' => '   ', 'quantity' => 5, 'unitPrice' => 1, 'VATRate' => 20],
    ],
]);
esit(2, count($fatura['items']), 'boş adlı kalem atlanır');
esit(301.0, $fatura['items'][0]['price'], 'virgüllü fiyat ve çarpım');
esit(60.2, $fatura['items'][0]['VATAmount'], 'kalem KDV');
esit(400.99, $fatura['grandTotal'], 'matrah');
esit(70.2, $fatura['totalVAT'], 'toplam KDV');
esit(471.19, $fatura['grandTotalInclVAT'], 'KDV dahil');
esit(471.19, $fatura['paymentTotal'], 'ödenecek');
esit('15/10/2026', $fatura['date'], 'tarih korunur');
esit(' ', $fatura['city'], 'şehir boşsa tek boşluk');
dogru((bool) preg_match('/^\d{2}:\d{2}:\d{2}$/', $fatura['time']), 'saat biçimi');

$bugun = FaturaYardimci::hesapla(['taxIDOrTRID' => '1234567890', 'name' => 'A', 'surname' => 'B', 'date' => '99/99/2026', 'items' => [['name' => 'X', 'quantity' => 1, 'unitPrice' => 1, 'VATRate' => 0]]]);
esit(FaturaYardimci::istanbulNow()['date'], $bugun['date'], 'geçersiz tarih yerine bugün');

foreach ([
    'kalem yok' => ['taxIDOrTRID' => '11111111111', 'title' => 'A', 'items' => []],
    'miktar sıfır' => ['taxIDOrTRID' => '11111111111', 'title' => 'A', 'items' => [['name' => 'X', 'quantity' => 0, 'unitPrice' => 1]]],
    'VKN kısa' => ['taxIDOrTRID' => '123', 'title' => 'A', 'items' => [['name' => 'X', 'quantity' => 1, 'unitPrice' => 1]]],
    'alıcı yok' => ['taxIDOrTRID' => '11111111111', 'name' => 'A', 'items' => [['name' => 'X', 'quantity' => 1, 'unitPrice' => 1]]],
    'KDV 150' => ['taxIDOrTRID' => '11111111111', 'title' => 'A', 'items' => [['name' => 'X', 'quantity' => 1, 'unitPrice' => 1, 'VATRate' => 150]]],
] as $ad => $girdi) {
    try {
        FaturaYardimci::hesapla($girdi);
        dogru(false, "$ad reddedilmeli");
    } catch (FaturaHata $e) {
        esit(400, $e->getStatus(), "$ad reddedilir");
    }
}

echo "Taslak oluşturma\n";
$istekler = [];
$gib = new GibEarsiv(true, sahteTasiyici($istekler, [['data' => 'Taslak oluşturuldu']]));
$sonuc = $gib->createDraftInvoice('T', $fatura + ['uuid' => '4c72cb57-b72d-4812-ac48-0a0bce83e771']);
esit('4c72cb57-b72d-4812-ac48-0a0bce83e771', $sonuc['uuid'], 'uuid korunur');
esit('15/10/2026', $sonuc['date'], 'tarih döner');
esit('Taslak oluşturuldu', $sonuc['data'], 'GİB mesajı');
$jp = json_decode($istekler[0]['form']['jp'], true);
esit('EARSIV_PORTAL_FATURA_OLUSTUR', $istekler[0]['form']['cmd'], 'oluşturma komutu');
esit('RG_BASITFATURA', $istekler[0]['form']['pageName'], 'oluşturma sayfası');
esit('11111111111', $jp['vknTckn'], 'vkn');
esit('Örnek Müşteri', $jp['aliciUnvan'], 'unvan');
esit('TRY', $jp['paraBirimi'], 'para birimi');
esit('SATIS', $jp['faturaTipi'], 'fatura tipi');
esit('400.99', $jp['matrah'], 'matrah metin');
esit('70.20', $jp['hesaplanankdv'], 'kdv metin');
esit('471.19', $jp['odenecekTutar'], 'ödenecek metin');
esit('DÖRT YÜZ YETMİŞ BİR LIRA ON DOKUZ KURUS', $jp['not'], 'not alanı');
esit(2, count($jp['malHizmetTable']), 'kalem sayısı');
$kalem = $jp['malHizmetTable'][0];
esit('Yıkama', $kalem['malHizmet'], 'kalem adı');
esit(2, $kalem['miktar'], 'miktar tam sayı');
esit('C62', $kalem['birim'], 'birim');
esit('150.50', $kalem['birimFiyat'], 'birim fiyat metin');
esit('301.00', $kalem['fiyat'], 'fiyat metin');
esit('301.00', $kalem['malHizmetTutari'], 'malHizmetTutari');
esit('20', $kalem['kdvOrani'], 'kdv oranı metin');
esit('60.20', $kalem['kdvTutari'], 'kdv tutarı metin');
esit('İskonto', $kalem['iskontoArttm'], 'iskonto');
esit([], $jp['iadeTable'], 'iade tablosu boş');
esit('İskonto', $jp['tip'], 'tip');
esit('Türkiye', $jp['ulke'], 'ülke');
esit(' ', $jp['sehir'], 'şehir');
esit('0.00', $jp['ozelMatrahVergiTutari'], 'özel matrah vergi');
dogru(strpos($istekler[0]['form']['jp'], 'Türkiye') !== false, 'jp Türkçe karakterleri kaçırmaz');

echo "ETTN ile bulma\n";
$istekler = [];
$gib = new GibEarsiv(false, sahteTasiyici($istekler, [['data' => [['ettn' => 'A', 'onayDurumu' => 'Onaylandı'], ['ettn' => 'B', 'onayDurumu' => 'Onaylanmadı'], ['ettn' => 'C']]]]));
$bulunan = FaturaYardimci::findInvoicesByETTN($gib, 'T', '01/10/2026', '31/10/2026', ['B', 'C', 'YOK']);
esit(['B', 'C'], array_column($bulunan, 'ettn'), 'yalnızca istenen ETTN\'ler');
try {
    FaturaYardimci::findInvoicesByETTN($gib, 'T', '2026-10-01', '31/10/2026', ['A']);
    dogru(false, 'yanlış tarih reddedilmeli');
} catch (FaturaHata $e) {
    esit(400, $e->getStatus(), 'yanlış tarih 400');
}
$gib = new GibEarsiv(false, sahteTasiyici($istekler, [['data' => []]]));
try {
    FaturaYardimci::findInvoicesByETTN($gib, 'T', '01/10/2026', '31/10/2026', ['A']);
    dogru(false, 'bulunamayan 404 olmalı');
} catch (FaturaHata $e) {
    esit(404, $e->getStatus(), 'bulunamayan 404');
}

echo "Telefon\n";
esit('905321234567', FaturaYardimci::normalizePhone('0532 123 45 67'), 'başında 0');
esit('905321234567', FaturaYardimci::normalizePhone('+90 (532) 123-45-67'), '+90');
esit('905321234567', FaturaYardimci::normalizePhone('5321234567'), '10 hane');
try {
    FaturaYardimci::normalizePhone('02121234567');
    dogru(false, 'sabit hat reddedilmeli');
} catch (FaturaHata $e) {
    esit(400, $e->getStatus(), 'sabit hat 400');
}
esit('*********67', FaturaYardimci::maskPhone('05321234567'), 'maske');

echo "Şifre saklama\n";
$anahtar = str_repeat('k', 64);
$sakli = FaturaYardimci::sifrele('GibSifresi!', $anahtar);
dogru($sakli !== 'GibSifresi!' && strpos($sakli, 'GibSifresi') === false, 'şifreli metin düz metni içermez');
esit('GibSifresi!', FaturaYardimci::coz($sakli, $anahtar), 'çözme');
esit(null, FaturaYardimci::coz($sakli, str_repeat('x', 64)), 'yanlış anahtar null');
esit(null, FaturaYardimci::coz('bozuk', $anahtar), 'bozuk veri null');
dogru(FaturaYardimci::sifrele('a', $anahtar) !== FaturaYardimci::sifrele('a', $anahtar), 'her şifreleme farklı IV');
try {
    FaturaYardimci::sifrele('a', 'kisa');
    dogru(false, 'kısa anahtar reddedilmeli');
} catch (RuntimeException $e) {
    dogru(true, 'kısa anahtar reddedilir');
}

echo "Son günler\n";
$aralik = FaturaYardimci::sonGunler(30);
dogru(FaturaYardimci::isDate($aralik['baslangic']) && FaturaYardimci::isDate($aralik['bitis']), 'aralık GİB biçiminde');
esit(FaturaYardimci::istanbulNow()['date'], $aralik['bitis'], 'bitiş bugün');
$b = DateTimeImmutable::createFromFormat('!d/m/Y', $aralik['baslangic']);
$e = DateTimeImmutable::createFromFormat('!d/m/Y', $aralik['bitis']);
esit(29, (int) $b->diff($e)->days, '30 gün = 29 gün fark');
esit(FaturaYardimci::sonGunler(1)['bitis'], FaturaYardimci::sonGunler(1)['baslangic'], '1 gün = bugün');

echo "Tarih\n";
dogru(FaturaYardimci::isDate('31/10/2026'), 'geçerli tarih');
dogru(!FaturaYardimci::isDate('31/02/2026'), '31 Şubat geçersiz');
dogru(!FaturaYardimci::isDate('2026-10-31'), 'ISO biçimi geçersiz');

echo "\n$toplam test, $basarisiz başarısız\n";
exit($basarisiz ? 1 : 0);
