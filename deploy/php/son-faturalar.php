<?php
// Komut satırından son N gündeki faturaları listeler ve isteğe bağlı olarak
// her birinin HTML'ini bir klasöre kaydeder (tarayıcıda açıp görüntülemek için).
//
// Kullanım (sunucuda):
//   GIB_KULLANICI=... GIB_SIFRE=... php son-faturalar.php              # son 30 gün, canlı portal
//   GIB_KULLANICI=... GIB_SIFRE=... php son-faturalar.php --gun=60     # son 60 gün
//   GIB_KULLANICI=... GIB_SIFRE=... php son-faturalar.php --test       # test portalı
//   GIB_KULLANICI=... GIB_SIFRE=... php son-faturalar.php --indir=./faturalar   # HTML'leri kaydet
//   ... --sadece-onayli      yalnızca kesilmiş (Onaylandı) faturalar
//   ... --json               tabloyu değil ham JSON listesini yaz
//
// Şifreyi komut satırına yazmayın; ortam değişkeni ya da `read -s` ile girin:
//   read -s -p "GİB şifresi: " GIB_SIFRE; export GIB_SIFRE

declare(strict_types=1);

require_once __DIR__ . '/FaturaYardimci.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$secenek = getopt('', ['gun::', 'test', 'indir::', 'sadece-onayli', 'json']);
$gun = (int) ($secenek['gun'] ?? 30) ?: 30;
$test = array_key_exists('test', $secenek);
$indir = isset($secenek['indir']) ? (string) $secenek['indir'] : null;
$sadeceOnayli = array_key_exists('sadece-onayli', $secenek);
$jsonCikti = array_key_exists('json', $secenek);

$kullanici = getenv('GIB_KULLANICI') ?: '';
$sifre = getenv('GIB_SIFRE') ?: '';
if ($kullanici === '' || $sifre === '') {
    fwrite(STDERR, "GIB_KULLANICI ve GIB_SIFRE ortam değişkenlerini tanımlayın.\n");
    exit(2);
}

$gib = new GibEarsiv($test);
$aralik = FaturaYardimci::sonGunler($gun);

try {
    $token = $gib->getToken(trim($kullanici), $sifre);
    $liste = $gib->getAllInvoicesByDateRange($token, $aralik['baslangic'], $aralik['bitis']);
} catch (GibException $e) {
    fwrite(STDERR, 'GİB hatası: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($sadeceOnayli) {
    $liste = array_values(array_filter($liste, function ($f) {
        return ($f['onayDurumu'] ?? '') === 'Onaylandı';
    }));
}

if ($jsonCikti) {
    echo json_encode($liste, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
} else {
    printf("%s portalı, %s - %s arası: %d fatura\n\n", $test ? 'TEST' : 'CANLI', $aralik['baslangic'], $aralik['bitis'], count($liste));
    printf("%-10s  %-16s  %-12s  %-30s  %12s  %-12s  %s\n", 'Tarih', 'Belge No', 'VKN/TCKN', 'Alıcı', 'Tutar', 'Durum', 'ETTN');
    foreach ($liste as $f) {
        printf(
            "%-10s  %-16s  %-12s  %-30s  %12s  %-12s  %s\n",
            $f['belgeTarihi'] ?? '',
            $f['belgeNumarasi'] ?? '',
            $f['aliciVknTckn'] ?? '',
            mb_strimwidth((string) ($f['aliciUnvanAdi'] ?? ''), 0, 30, '…'),
            $f['tutar'] ?? '',
            $f['onayDurumu'] ?? '',
            $f['ettn'] ?? ''
        );
    }
}

if ($indir !== null && $liste) {
    $indir = $indir === '' ? './faturalar' : $indir;
    if (!is_dir($indir) && !mkdir($indir, 0750, true)) {
        fwrite(STDERR, "Klasör oluşturulamadı: $indir\n");
        exit(1);
    }
    $kayit = 0;
    foreach ($liste as $f) {
        $ettn = (string) ($f['ettn'] ?? '');
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $ettn)) {
            continue;
        }
        $onayli = ($f['onayDurumu'] ?? '') === 'Onaylandı';
        $belge = preg_replace('/[^A-Za-z0-9]/', '', (string) ($f['belgeNumarasi'] ?? '')) ?: 'taslak';
        $tarih = str_replace('/', '-', (string) ($f['belgeTarihi'] ?? ''));
        $dosya = rtrim($indir, '/') . "/$tarih-$belge-$ettn.html";
        try {
            $html = $gib->getInvoiceHTML($token, $ettn, $onayli);
        } catch (GibException $e) {
            fwrite(STDERR, "$ettn alınamadı: " . $e->getMessage() . "\n");
            continue;
        }
        file_put_contents($dosya, $html);
        $kayit++;
    }
    fwrite(STDERR, "\n$kayit fatura HTML olarak kaydedildi: $indir\n");
}

try {
    $gib->logout($token);
} catch (Throwable $e) {
    // çıkış hatası önemsiz
}
