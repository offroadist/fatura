<?php
// Fatura panelinin WhatsApp doğrulama kodlarını, sunucudaki mevcut
// sq_wa_gonder() altyapısıyla (cevaplama.com, 0850 840 52 14) gönderir.
//
// Kurulum:
//  1. Bu dosyayı HTTPS ile erişilen bir dizine koyun.
//  2. WA_HELPER yolunu sq_wa_gonder() fonksiyonunun tanımlandığı dosyaya ayarlayın.
//  3. Web sunucusunda WA_OTP_KEY ortam değişkenini panelle aynı uzun, rastgele değere ayarlayın.
//
// Yalnızca telefon ve kod kabul edilir; mesaj metni burada oluşturulur. Böylece anahtar
// ele geçirilse bile bu uç noktadan istenen metinle mesaj gönderilemez.

const WA_HELPER = '/var/www/vhosts/BURAYI/DUZENLEYIN/wa.php';
const MARKA = 'cevaplama.com';
const SAATLIK_LIMIT = 5; // aynı numaraya saatte en fazla kod

header('Content-Type: application/json; charset=utf-8');

function cevap($durum, $veri)
{
    http_response_code($durum);
    echo json_encode($veri, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cevap(405, ['ok' => false, 'error' => 'method']);
}

$anahtar = getenv('WA_OTP_KEY') ?: '';
$yetki = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (strlen($anahtar) < 32 || !hash_equals('Bearer ' . $anahtar, $yetki)) {
    cevap(401, ['ok' => false, 'error' => 'yetki']);
}

$girdi = json_decode(file_get_contents('php://input'), true) ?: [];
$tel = (string) ($girdi['phone'] ?? '');
$kod = (string) ($girdi['code'] ?? '');
if (!preg_match('/^905\d{9}$/', $tel) || !preg_match('/^\d{6}$/', $kod)) {
    cevap(400, ['ok' => false, 'error' => 'girdi']);
}

// Numara başına saatlik gönderim sınırı
$limitDosyasi = sys_get_temp_dir() . '/wa-otp-' . hash('sha256', $tel);
$simdi = time();
$gecmis = array_filter(
    json_decode((string) @file_get_contents($limitDosyasi), true) ?: [],
    function ($zaman) use ($simdi) {
        return $simdi - $zaman < 3600;
    }
);
if (count($gecmis) >= SAATLIK_LIMIT) {
    cevap(429, ['ok' => false, 'error' => 'limit']);
}

require_once WA_HELPER;

$mesaj = MARKA . " doğrulama kodunuz: $kod\n"
    . "Bu kod 5 dakika geçerlidir. Kimseyle paylaşmayın.";

if (!sq_wa_gonder($tel, $mesaj)) {
    cevap(502, ['ok' => false, 'error' => 'gonderilemedi']);
}

$gecmis[] = $simdi;
file_put_contents($limitDosyasi, json_encode(array_values($gecmis)), LOCK_EX);
cevap(200, ['ok' => true]);
