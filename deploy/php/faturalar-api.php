<?php
// hanotoyikama.com admin paneli "Faturalar" bölümü için JSON API ucu (server.js rotalarının PHP karşılığı).
//
// Bu dosya siteye bağlanırken yalnızca aşağıdaki "SİTEYE BAĞLANACAK YERLER" bölümü düzenlenir;
// rotalar olduğu gibi kullanılabilir. Panelin arayüzü (public/index.html) bu uca
// ?islem=... ile istek atacak şekilde uyarlanır.
//
// Rotalar (hepsi JSON döner, hata durumunda {"error": "..."}):
//   GET  ?islem=durum                         GİB kullanıcı bilgisi, ortam (test/canlı)
//   GET  ?islem=liste&baslangic=GG/AA/YYYY&bitis=GG/AA/YYYY   (tarih verilmezse son 30 gün)
//   POST ?islem=olustur        gövde: FaturaYardimci::hesapla() girdisi (JSON)
//   POST ?islem=sil            gövde: {ettn, baslangic, bitis, neden}
//   GET  ?islem=html&ettn=...&onayli=0|1      Fatura HTML'i (iframe içinde gösterilir)
//   GET  ?islem=zip&ettn=...&onayli=0|1       ZIP indirme
//   GET  ?islem=alici&id=VKN|TCKN             Alıcı bilgisi sorgulama
//   POST ?islem=sms-gonder                    GİB'de kayıtlı telefona SMS şifresi gönderir -> {oid, telefon}
//   POST ?islem=sms-dogrula    gövde: {kod, oid, baslangic, bitis, ettnler: [...]}
//                              ☢️ Seçili taslakları imzalar: GİB'de resmi fatura keser, geri alınamaz.

declare(strict_types=1);

require_once __DIR__ . '/FaturaYardimci.php';

// ============================================================ SİTEYE BAĞLANACAK YERLER

/**
 * Mevcut admin oturumunu doğrular. Yetkisiz kullanıcı için FaturaHata(403) fırlatın.
 * Örnek: if (empty($_SESSION['admin_id'])) throw new FaturaHata(403, 'Yetkisiz.');
 */
function fatura_admin_kontrol(): void
{
    throw new FaturaHata(500, 'fatura_admin_kontrol() siteye göre düzenlenmedi.');
}

/**
 * Sitenin CSRF korumasını uygular (POST istekleri için). Site başlık ya da alan tabanlı
 * hangi yöntemi kullanıyorsa onu çağırın; uyumsuzsa FaturaHata(403) fırlatın.
 */
function fatura_csrf_kontrol(): void
{
}

/**
 * Admin panelindeki "GİB e-Arşiv" ayarlarını döner.
 * Şifre veritabanında FaturaYardimci::sifrele() ile saklanır; burada FaturaYardimci::coz() ile çözülür.
 * Anahtar (FATURA_SIFRE_ANAHTARI) ortam değişkeninden okunur; koda ya da veritabanına yazılmaz.
 *
 * Dönüş: ['kullanici' => '...', 'sifre' => '...', 'test' => true|false]
 */
function fatura_gib_ayarlari(): array
{
    throw new FaturaHata(500, 'fatura_gib_ayarlari() siteye göre düzenlenmedi.');
}

/** Beklenmeyen hataları sitenin log altyapısına yazar. */
function fatura_log(string $mesaj): void
{
    error_log('[faturalar] ' . $mesaj);
}

/**
 * İsteğe bağlı: oluşturulan/imzalanan faturanın yerel kopyasını `faturalar` tablosuna yazar.
 * $kayit: ettn, belge_no, tarih, alici, vkn, tutar, durum, olusturan_admin
 */
function fatura_yerel_kaydet(array $kayit): void
{
}

// ============================================================ Oturum ve GİB bağlantısı

const FATURA_TOKEN_SURESI = 30 * 60; // saniye

/**
 * Oturumdaki GİB token'ını döner; yoksa ya da süresi dolduysa ayarlardaki bilgilerle yeniden giriş yapar.
 * Şifre oturumda tutulmaz, yalnızca token tutulur.
 */
function fatura_gib(bool $yenidenGiris = false): array
{
    $ayar = fatura_gib_ayarlari();
    if (empty($ayar['kullanici']) || empty($ayar['sifre'])) {
        throw new FaturaHata(400, 'GİB e-Arşiv kullanıcı kodu ve şifresi ayarlarda tanımlı değil.');
    }
    $test = !empty($ayar['test']);
    $gib = new GibEarsiv($test);

    $oturum = $_SESSION['gib'] ?? null;
    $gecerli = $oturum
        && ($oturum['test'] ?? null) === $test
        && ($oturum['kullanici'] ?? null) === $ayar['kullanici']
        && time() - ($oturum['zaman'] ?? 0) < FATURA_TOKEN_SURESI;

    if ($yenidenGiris || !$gecerli) {
        $token = $gib->getToken(trim((string) $ayar['kullanici']), (string) $ayar['sifre']);
        $_SESSION['gib'] = ['token' => $token, 'test' => $test, 'kullanici' => $ayar['kullanici'], 'zaman' => time()];
    } else {
        $_SESSION['gib']['zaman'] = time();
    }
    return [$gib, $_SESSION['gib']['token']];
}

/**
 * GİB çağrısını yapar; oturum düşmüşse bir kez yeniden giriş yapıp tekrar dener.
 * $islem: function(GibEarsiv $gib, string $token)
 */
function fatura_gib_calistir(callable $islem)
{
    [$gib, $token] = fatura_gib();
    try {
        return $islem($gib, $token);
    } catch (GibException $e) {
        $metin = mb_strtolower($e->getMessage());
        $oturumHatasi = strpos($metin, 'oturum') !== false
            || strpos($metin, 'token') !== false
            || strpos($metin, 'giriş') !== false
            || strpos($metin, 'geçersiz yanıt') !== false;
        if (!$oturumHatasi) {
            throw $e;
        }
        [$gib, $token] = fatura_gib(true);
        return $islem($gib, $token);
    }
}

// ============================================================ Yardımcılar

function fatura_json(int $status, $veri): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($veri, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fatura_govde(): array
{
    $ham = file_get_contents('php://input');
    if (strlen($ham) > 1000000) {
        throw new FaturaHata(413, 'İstek çok büyük.');
    }
    if ($ham === '' || $ham === false) {
        return $_POST;
    }
    $veri = json_decode($ham, true);
    if (!is_array($veri)) {
        throw new FaturaHata(400, 'Geçersiz istek.');
    }
    return $veri;
}

function fatura_post_gerekli(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new FaturaHata(405, 'Yalnızca POST.');
    }
    fatura_csrf_kontrol();
}

function fatura_ettn_kontrol($ettn): string
{
    $ettn = (string) $ettn;
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $ettn)) {
        throw new FaturaHata(400, 'Geçersiz ETTN.');
    }
    return $ettn;
}

// ============================================================ Rotalar

function fatura_rota(string $islem): void
{
    switch ($islem) {
        case 'durum':
            [$gib, $token] = fatura_gib();
            $kullanici = null;
            try {
                $kullanici = $gib->getUserData($token);
            } catch (GibException $e) {
                fatura_log('Kullanıcı bilgisi alınamadı: ' . $e->getMessage());
            }
            fatura_json(200, ['user' => $kullanici, 'testMode' => $gib->isTest()]);
            // fatura_json çıkar

        case 'liste':
            $baslangic = $_GET['baslangic'] ?? '';
            $bitis = $_GET['bitis'] ?? '';
            if ($baslangic === '' && $bitis === '') {
                ['baslangic' => $baslangic, 'bitis' => $bitis] = FaturaYardimci::sonGunler(30);
            }
            if (!FaturaYardimci::isDate($baslangic) || !FaturaYardimci::isDate($bitis)) {
                throw new FaturaHata(400, 'Tarih aralığı gerekli.');
            }
            $liste = fatura_gib_calistir(function (GibEarsiv $gib, string $token) use ($baslangic, $bitis) {
                return $gib->getAllInvoicesByDateRange($token, $baslangic, $bitis);
            });
            fatura_json(200, ['baslangic' => $baslangic, 'bitis' => $bitis, 'invoices' => $liste]);

        case 'olustur':
            fatura_post_gerekli();
            $fatura = FaturaYardimci::hesapla(fatura_govde());
            $taslak = fatura_gib_calistir(function (GibEarsiv $gib, string $token) use ($fatura) {
                return $gib->createDraftInvoice($token, $fatura);
            });
            fatura_yerel_kaydet([
                'ettn' => $taslak['uuid'],
                'belge_no' => '',
                'tarih' => $taslak['date'],
                'alici' => $fatura['title'] !== '' ? $fatura['title'] : $fatura['name'] . ' ' . $fatura['surname'],
                'vkn' => $fatura['taxIDOrTRID'],
                'tutar' => $fatura['paymentTotal'],
                'durum' => 'Onaylanmadı',
                'olusturan_admin' => $_SESSION['admin_id'] ?? null,
            ]);
            fatura_json(200, ['uuid' => $taslak['uuid'], 'date' => $taslak['date'], 'message' => $taslak['data'] ?? null]);

        case 'sil':
            fatura_post_gerekli();
            $govde = fatura_govde();
            $ettn = fatura_ettn_kontrol($govde['ettn'] ?? '');
            $mesaj = fatura_gib_calistir(function (GibEarsiv $gib, string $token) use ($govde, $ettn) {
                [$fatura] = FaturaYardimci::findInvoicesByETTN(
                    $gib, $token, $govde['baslangic'] ?? '', $govde['bitis'] ?? '', [$ettn]
                );
                if (($fatura['onayDurumu'] ?? '') !== 'Onaylanmadı') {
                    throw new FaturaHata(400, 'Yalnızca onaylanmamış taslaklar silinebilir.');
                }
                return $gib->cancelDraftInvoice($token, trim((string) ($govde['neden'] ?? '')) ?: 'Hatalı düzenlendi', $fatura);
            });
            fatura_json(200, ['message' => $mesaj]);

        case 'html':
            $ettn = fatura_ettn_kontrol($_GET['ettn'] ?? '');
            $onayli = ($_GET['onayli'] ?? '') === '1';
            $html = fatura_gib_calistir(function (GibEarsiv $gib, string $token) use ($ettn, $onayli) {
                return $gib->getInvoiceHTML($token, $ettn, $onayli);
            });
            header('Content-Type: text/html; charset=utf-8');
            header("Content-Security-Policy: script-src 'none'");
            header('Cache-Control: no-store');
            echo $html;
            exit;

        case 'zip':
            $ettn = fatura_ettn_kontrol($_GET['ettn'] ?? '');
            $onayli = ($_GET['onayli'] ?? '') === '1';
            $zip = fatura_gib_calistir(function (GibEarsiv $gib, string $token) use ($ettn, $onayli) {
                return $gib->downloadInvoiceZip($token, $ettn, $onayli);
            });
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="fatura-' . $ettn . '.zip"');
            header('Content-Length: ' . strlen($zip));
            header('Cache-Control: no-store');
            echo $zip;
            exit;

        case 'alici':
            $id = (string) ($_GET['id'] ?? '');
            if (!preg_match('/^\d{10,11}$/', $id)) {
                throw new FaturaHata(400, 'VKN 10, TCKN 11 haneli olmalıdır.');
            }
            $alici = fatura_gib_calistir(function (GibEarsiv $gib, string $token) use ($id) {
                return $gib->getRecipientDataByTaxIDOrTRID($token, $id);
            });
            fatura_json(200, ['recipient' => $alici ?: new stdClass()]);

        // GİB onayı 1. adım: kayıtlı cep telefonuna SMS şifresi gönderilir.
        case 'sms-gonder':
            fatura_post_gerekli();
            $sonuc = fatura_gib_calistir(function (GibEarsiv $gib, string $token) {
                $telefon = $gib->getPhoneNumber($token);
                if (!$telefon) {
                    throw new FaturaHata(400, "GİB'de kayıtlı cep telefonu bulunamadı. e-Arşiv portalından telefon numaranızı tanımlayın.");
                }
                return ['oid' => $gib->sendSignSMSCode($token, $telefon), 'phone' => FaturaYardimci::maskPhone($telefon)];
            });
            fatura_json(200, $sonuc);

        // GİB onayı 2. adım: SMS şifresi ile seçili taslaklar imzalanır. ☢️ Geri alınamaz.
        case 'sms-dogrula':
            fatura_post_gerekli();
            $govde = fatura_govde();
            $kod = (string) ($govde['kod'] ?? $govde['code'] ?? '');
            $oid = (string) ($govde['oid'] ?? '');
            if (!preg_match('/^\d{4,8}$/', $kod) || $oid === '') {
                throw new FaturaHata(400, 'SMS şifresini kontrol edin.');
            }
            $ettnler = array_map('fatura_ettn_kontrol', (array) ($govde['ettnler'] ?? $govde['ettns'] ?? []));
            if (!$ettnler) {
                throw new FaturaHata(400, 'İmzalanacak fatura seçin.');
            }
            $sonuc = fatura_gib_calistir(function (GibEarsiv $gib, string $token) use ($govde, $ettnler, $kod, $oid) {
                $faturalar = FaturaYardimci::findInvoicesByETTN(
                    $gib, $token, $govde['baslangic'] ?? '', $govde['bitis'] ?? '', $ettnler
                );
                $taslaklar = array_values(array_filter($faturalar, function ($f) {
                    return ($f['onayDurumu'] ?? '') === 'Onaylanmadı';
                }));
                if (!$taslaklar) {
                    throw new FaturaHata(400, 'Seçilen faturalar zaten onaylanmış.');
                }
                $cevap = $gib->verifySignSMSCode($token, $kod, $oid, $taslaklar);
                foreach ($taslaklar as $t) {
                    fatura_yerel_kaydet([
                        'ettn' => $t['ettn'] ?? '',
                        'belge_no' => $t['belgeNumarasi'] ?? '',
                        'tarih' => $t['belgeTarihi'] ?? '',
                        'alici' => $t['aliciUnvanAdi'] ?? '',
                        'vkn' => $t['aliciVknTckn'] ?? '',
                        'tutar' => $t['tutar'] ?? '',
                        'durum' => 'Onaylandı',
                        'olusturan_admin' => $_SESSION['admin_id'] ?? null,
                    ]);
                }
                return ['result' => $cevap, 'count' => count($taslaklar)];
            });
            fatura_json(200, $sonuc);

        default:
            throw new FaturaHata(404, 'Bulunamadı.');
    }
}

// ============================================================ Giriş noktası

if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    try {
        fatura_admin_kontrol();
        fatura_rota((string) ($_GET['islem'] ?? ''));
    } catch (FaturaHata $e) {
        fatura_json($e->getStatus(), ['error' => $e->getMessage()]);
    } catch (GibException $e) {
        // GİB'in döndürdüğü hatalar (hatalı şifre, SMS kodu vb.) kullanıcıya gösterilir.
        fatura_json(400, ['error' => $e->getMessage()]);
    } catch (Throwable $e) {
        fatura_log($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        fatura_json(500, ['error' => 'Beklenmeyen hata.']);
    }
}
