<?php
// server.js'deki iş mantığının PHP karşılığı: toplam hesabı, doğrulama,
// ETTN ile GİB'den fatura bulma, telefon biçimleme ve şifre saklama.

declare(strict_types=1);

require_once __DIR__ . '/GibEarsiv.php';

/** Kullanıcıya gösterilecek, HTTP durum kodu taşıyan hata. */
final class FaturaHata extends RuntimeException
{
    /** @var int */
    private $status;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}

final class FaturaYardimci
{
    public static function round2(float $number): float
    {
        return round($number, 2);
    }

    /** İstanbul saatiyle GİB biçiminde tarih ve saat: ['date' => 'GG/AA/YYYY', 'time' => 'SS:DD:ss'] */
    public static function istanbulNow(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
        return ['date' => $now->format('d/m/Y'), 'time' => $now->format('H:i:s')];
    }

    /** Son N günü kapsayan GİB tarih aralığı: ['baslangic' => 'GG/AA/YYYY', 'bitis' => 'GG/AA/YYYY'] */
    public static function sonGunler(int $gun = 30): array
    {
        $gun = max(1, min($gun, 366));
        $bugun = new DateTimeImmutable('today', new DateTimeZone('Europe/Istanbul'));
        return [
            'baslangic' => $bugun->modify('-' . ($gun - 1) . ' days')->format('d/m/Y'),
            'bitis' => $bugun->format('d/m/Y'),
        ];
    }

    public static function isDate($value): bool
    {
        if (!is_string($value) || !preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[1], (int) $m[3]);
    }

    /**
     * Formdan gelen veriden fatura nesnesi kurar; toplamlar ve KDV sunucuda yeniden hesaplanır.
     * İstemciden gelen toplamlara güvenilmez.
     *
     * Girdi: taxIDOrTRID, taxOffice, title | name+surname, fullAddress, district, city, email,
     *        date (isteğe bağlı GG/AA/YYYY), items[] (name, quantity, unitType, unitPrice, VATRate)
     *
     * @throws FaturaHata (400) geçersiz girdi
     */
    public static function hesapla(array $body): array
    {
        $items = [];
        foreach ($body['items'] ?? [] as $item) {
            if (!is_array($item) || trim((string) ($item['name'] ?? '')) === '') {
                continue;
            }
            $quantity = self::toNumber($item['quantity'] ?? 0);
            $unitPrice = self::toNumber($item['unitPrice'] ?? 0);
            $vatRate = self::toNumber($item['VATRate'] ?? 0);
            $price = self::round2($quantity * $unitPrice);
            $items[] = [
                'name' => trim((string) $item['name']),
                'quantity' => $quantity,
                'unitType' => (string) ($item['unitType'] ?? 'C62') ?: 'C62',
                'unitPrice' => $unitPrice,
                'price' => $price,
                'VATRate' => $vatRate,
                'VATAmount' => self::round2($price * $vatRate / 100),
            ];
        }

        if (!$items) {
            throw new FaturaHata(400, 'En az bir kalem ekleyin.');
        }
        foreach ($items as $item) {
            if ($item['quantity'] <= 0 || $item['unitPrice'] < 0) {
                throw new FaturaHata(400, 'Miktar ve birim fiyatı kontrol edin.');
            }
            if ($item['VATRate'] < 0 || $item['VATRate'] > 100) {
                throw new FaturaHata(400, 'KDV oranını kontrol edin.');
            }
        }
        $taxId = (string) ($body['taxIDOrTRID'] ?? '');
        if (!preg_match('/^\d{10,11}$/', $taxId)) {
            throw new FaturaHata(400, 'VKN 10, TCKN 11 haneli olmalıdır.');
        }
        $title = trim((string) ($body['title'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        $surname = trim((string) ($body['surname'] ?? ''));
        if ($title === '' && ($name === '' || $surname === '')) {
            throw new FaturaHata(400, 'Alıcı unvanı ya da adı-soyadı gerekli.');
        }

        $now = self::istanbulNow();
        $grandTotal = self::round2(array_sum(array_column($items, 'price')));
        $totalVAT = self::round2(array_sum(array_column($items, 'VATAmount')));
        $city = trim((string) ($body['city'] ?? ''));

        return [
            'date' => self::isDate($body['date'] ?? null) ? $body['date'] : $now['date'],
            'time' => $now['time'],
            'taxIDOrTRID' => $taxId,
            'taxOffice' => trim((string) ($body['taxOffice'] ?? '')),
            'title' => $title,
            'name' => $name,
            'surname' => $surname,
            'fullAddress' => trim((string) ($body['fullAddress'] ?? '')),
            'district' => trim((string) ($body['district'] ?? '')),
            'city' => $city === '' ? ' ' : $city,
            'email' => trim((string) ($body['email'] ?? '')),
            'items' => $items,
            'totalVAT' => $totalVAT,
            'grandTotal' => $grandTotal,
            'grandTotalInclVAT' => self::round2($grandTotal + $totalVAT),
            'paymentTotal' => self::round2($grandTotal + $totalVAT),
        ];
    }

    /**
     * GİB'den tarih aralığındaki faturaları çekip verilen ETTN'leri seçer.
     * İmzalama ve silme için istemciden gelen fatura nesnesi yerine daima bu kullanılır.
     *
     * @throws FaturaHata 400 tarih hatalı, 404 bulunamadı
     */
    public static function findInvoicesByETTN(GibEarsiv $gib, string $token, $startDate, $endDate, array $ettns): array
    {
        if (!self::isDate($startDate) || !self::isDate($endDate)) {
            throw new FaturaHata(400, 'Tarih aralığı gerekli.');
        }
        $wanted = array_flip(array_map('strval', $ettns));
        $found = [];
        foreach ($gib->getAllInvoicesByDateRange($token, $startDate, $endDate) as $invoice) {
            if (isset($wanted[(string) ($invoice['ettn'] ?? '')])) {
                $found[] = $invoice;
            }
        }
        if (!$found) {
            throw new FaturaHata(404, 'Seçilen fatura bulunamadı.');
        }
        return $found;
    }

    /** 05321234567 -> *********67 */
    public static function maskPhone(string $phone): string
    {
        $len = mb_strlen($phone);
        if ($len <= 2) {
            return $phone;
        }
        return preg_replace('/\d/', '*', mb_substr($phone, 0, $len - 2)) . mb_substr($phone, $len - 2);
    }

    /** 05321234567, 5321234567, +90 532 123 45 67 -> 905321234567 */
    public static function normalizePhone($phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strncmp($digits, '0', 1) === 0) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '90' . $digits;
        }
        if (!preg_match('/^905\d{9}$/', $digits)) {
            throw new FaturaHata(400, 'Geçerli bir cep telefonu numarası girin.');
        }
        return $digits;
    }

    /** Dize ya da sayı girdisini float'a çevirir; "1,5" gibi virgüllü girdiyi de kabul eder. */
    public static function toNumber($value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));
        return is_numeric($value) ? (float) $value : 0.0;
    }

    // ---------------------------------------------------------------- Şifre saklama

    /**
     * GİB şifresini veritabanında saklamak için AES-256-GCM ile şifreler.
     * $key en az 32 baytlık gizli anahtar (ortam değişkeninden okunmalı, koda yazılmamalı).
     * Çıktı: base64(iv . tag . ciphertext)
     */
    public static function sifrele(string $plain, string $key): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::anahtar($key), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Şifreleme başarısız.');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    /** sifrele() çıktısını çözer; bozuk ya da yanlış anahtarla şifrelenmiş veri için null döner. */
    public static function coz(string $stored, string $key): ?string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $plain = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            self::anahtar($key),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16)
        );
        return $plain === false ? null : $plain;
    }

    private static function anahtar(string $key): string
    {
        if (strlen($key) < 32) {
            throw new RuntimeException('Şifreleme anahtarı en az 32 karakter olmalıdır.');
        }
        return hash('sha256', $key, true);
    }
}
