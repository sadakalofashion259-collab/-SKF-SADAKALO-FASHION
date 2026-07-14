<?php
declare(strict_types=1);
if (!defined('SK_APP')) { http_response_code(403); exit('403 Forbidden'); }

/**
 * MiMSMS গেটওয়ে — নিম্ন-স্তরের প্রেরক।
 *   সিক্রেট (username, apikey) আসে .env থেকে।
 *   নন-সিক্রেট (sender, type, endpoint, enabled) আসে sms_gateway টেবিল থেকে।
 */
final class SmsGateway
{
    /** @param array<string,mixed> $cfg */
    private function __construct(private array $cfg) {}

    public static function fromDb(\PDO $conn): self
    {
        $row = [];
        try {
            $stmt = $conn->query("SELECT * FROM sms_gateway ORDER BY id LIMIT 1");
            $row  = $stmt ? ($stmt->fetch() ?: []) : [];
        } catch (\Throwable $e) {
            Logger::error('sms_gateway read failed', $e);
        }

        return new self([
            'enabled'    => (int)($row['is_enabled'] ?? 1) === 1,
            'username'   => sk_env('SMS_USERNAME'),
            'apikey'     => sk_env('SMS_APIKEY'),
            'sender'     => (string)($row['sender_id']   ?? sk_env('SMS_SENDER', '8809617633941')),
            'endpoint'   => (string)($row['endpoint']    ?? 'https://api.mimsms.com/api/SmsSending/SMS'),
            'trans_type' => (string)($row['trans_type']  ?? 'T'),
            'campaign'   => (string)($row['campaign_id']  ?? 'null'),
            'timeout'    => 20,
        ]);
    }

    /** @return array<string,mixed> */
    public function config(): array
    {
        return $this->cfg;
    }

    /** মাস্ক করা API key (ড্যাশবোর্ডে দেখানোর জন্য) */
    public function maskedKey(): string
    {
        $k = (string)$this->cfg['apikey'];
        if ($k === '') {
            return '';
        }
        return strlen($k) > 8
            ? substr($k, 0, 4) . str_repeat('•', 8) . substr($k, -3)
            : '••••••';
    }

    public function isReady(): bool
    {
        if (empty($this->cfg['enabled'])) {
            return false;
        }
        if (empty($this->cfg['username']) || empty($this->cfg['apikey'])) {
            return false;
        }
        // .env টেমপ্লেটের প্লেসহোল্ডার এখনো বসানো থাকলে "প্রস্তুত নয়"
        if (str_contains((string)$this->cfg['apikey'], 'paste_')) {
            return false;
        }
        return true;
    }

    /** বাংলাদেশি নম্বর → 8801XXXXXXXXX (ভুল হলে '') */
    public static function normalizePhone(string $raw): string
    {
        $d = preg_replace('/[^0-9]/', '', $raw) ?? '';
        if ($d === '') {
            return '';
        }
        if (str_starts_with($d, '0088')) {
            $d = substr($d, 2);
        }
        if (str_starts_with($d, '88') && strlen($d) === 13) {
            return $d;
        }
        if (str_starts_with($d, '01') && strlen($d) === 11) {
            return '88' . $d;
        }
        if (str_starts_with($d, '1') && strlen($d) === 10) {
            return '880' . $d;
        }
        if (!str_starts_with($d, '88')) {
            $d = '88' . $d;
        }
        return $d;
    }

    /**
     * একটি SMS পাঠায়।
     * @return array{ok:bool,msg:string,trxnId:string,http:int,raw:string}
     */
    public function send(string $phone, string $message): array
    {
        $out = ['ok' => false, 'msg' => '', 'trxnId' => '', 'http' => 0, 'raw' => ''];

        if (!$this->isReady()) {
            $out['msg'] = 'SMS পরিষেবা বন্ধ অথবা API তথ্য বসানো হয়নি।';
            return $out;
        }

        $to = self::normalizePhone($phone);
        if (strlen($to) < 12) {
            $out['msg'] = 'মোবাইল নম্বর সঠিক নয়।';
            return $out;
        }
        if (trim($message) === '') {
            $out['msg'] = 'বার্তা খালি।';
            return $out;
        }

        $payload = json_encode([
            'UserName'        => $this->cfg['username'],
            'Apikey'          => $this->cfg['apikey'],
            'MobileNumber'    => $to,
            'CampaignId'      => $this->cfg['campaign'],
            'SenderName'      => $this->cfg['sender'],
            'TransactionType' => $this->cfg['trans_type'],
            'Message'         => $message,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init((string)$this->cfg['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => (int)$this->cfg['timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $out['http'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            $out['msg'] = 'নেটওয়ার্ক সমস্যা: ' . $err;
            return $out;
        }
        $out['raw'] = trim((string)$resp);

        $data   = json_decode((string)$resp, true);
        $status = strtolower((string)($data['status'] ?? ''));
        $scode  = (string)($data['statusCode'] ?? '');

        if ($status === 'success' || $scode === '200') {
            $out['ok']     = true;
            $out['trxnId'] = (string)($data['trxnId'] ?? '');
            $out['msg']    = (string)($data['responseResult'] ?? 'SMS পাঠানো হয়েছে।');
        } else {
            $reason = (string)($data['responseResult'] ?? ($data['status'] ?? ''));
            if ($reason === '') {
                $reason = $out['raw'] !== '' ? $out['raw'] : 'SMS পাঠানো যায়নি।';
            }
            $out['msg'] = $scode !== '' ? ($reason . ' (কোড: ' . $scode . ')') : $reason;
        }

        return $out;
    }
}
