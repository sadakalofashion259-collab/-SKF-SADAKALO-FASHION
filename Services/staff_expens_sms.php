<?php
/**
 * staff_expens_sms.php
 * SMS Notification Service — SADA KALO FASHION
 * Location: Public_html/Services/staff_expens_sms.php
 *
 * শুধুমাত্র Expense (খরচ) এর SMS পাঠাবে।
 * রানিং ব্যালেন্স মাইনাসের SMS যাবে না।
 * SMS ফরম্যাট ছোট (শর্টকাট) রাখা হয়েছে।
 *
 * ⚠️  শুধু এই ফাইলে পরিবর্তন করলেই SMS আপডেট হবে।
 *     অন্য কোনো ফাইলে হাত দিতে হবে না।
 */

// =============================================
// MiMSMS API কনফিগারেশন
// =============================================
if (!defined('SMS_API_URL'))      define('SMS_API_URL',      'https://api.mimsms.com/api/SmsSending/SMS');
if (!defined('SMS_API_USERNAME')) define('SMS_API_USERNAME', 'sajpoint99@gmail.com');
if (!defined('SMS_API_KEY'))      define('SMS_API_KEY',      'HIWDMHZHVKH98ZGLI782THVLM');
if (!defined('SMS_SENDER_NAME'))  define('SMS_SENDER_NAME',  '8809617633941');
if (!defined('SMS_TYPE'))         define('SMS_TYPE',         'T'); // T = Transactional


// =============================================
// SMS পাঠানোর মূল ফাংশন
// =============================================
function sendSMS(string $mobileNumber, string $message): array
{
    $mobile = preg_replace('/\D/', '', $mobileNumber);

    // নম্বর ফরম্যাট ঠিক করা
    if (strlen($mobile) === 11 && str_starts_with($mobile, '0')) {
        $mobile = '88' . $mobile;
    } elseif (strlen($mobile) === 10) {
        $mobile = '880' . $mobile;
    }

    $payload = json_encode([
        'UserName'        => SMS_API_USERNAME,
        'Apikey'          => SMS_API_KEY,
        'MobileNumber'    => $mobile,
        'SenderName'      => SMS_SENDER_NAME,
        'TransactionType' => SMS_TYPE,
        'CampaignId'      => 'null',
        'Message'         => $message,
    ]);

    $ch = curl_init(SMS_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: bearer',
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        logSmsError("SMS cURL Error: {$curlError} | Mobile: {$mobile}");
        return ['success' => false, 'error' => $curlError];
    }

    $decoded = json_decode($response, true);
    if ($httpCode !== 200 || empty($decoded)) {
        logSmsError("SMS API Error: HTTP {$httpCode} | Response: {$response} | Mobile: {$mobile}");
        return ['success' => false, 'error' => "HTTP {$httpCode}: {$response}"];
    }

    return ['success' => true, 'response' => $decoded];
}


// =============================================
// Expense SMS পাঠানোর ফাংশন
// (শুধু এই ফাংশনটা কল করলেই SMS যাবে)
// =============================================
function sendExpenseSMS(
    string $staffPhone,
    string $expenseDate,   // Y-m-d ফরম্যাটে পাঠাও
    float  $amount,
    int    $staffId = 0    // শুধু লগের জন্য
): bool {

    if (empty($staffPhone)) {
        return false;
    }

    // =======================================
    // SMS ম্যাসেজ ফরম্যাট — শর্টকাট
    // শুধু কত টাকা নিল সেটাই যাবে
    // কোনো ব্যালেন্স / মাইনাস / প্লাস নেই
    // শুধু এই ব্লকটা বদলালেই হবে পরবর্তীতে
    // =======================================
    $formattedDate   = date('d/m/y', strtotime($expenseDate)); // 05/06/26
    $formattedAmount = number_format($amount, 2);

    $message  = "SADA KALO FASHION\n";
    $message .= "{$formattedDate}\n";
    $message .= "Payment Tk.{$formattedAmount}";
    // =======================================

    $result = sendSMS($staffPhone, $message);

    if (!$result['success']) {
        logSmsError("Expense SMS failed for Staff ID {$staffId}: " . ($result['error'] ?? 'Unknown'));
        return false;
    }

    return true;
}


// =============================================
// SMS এরর লগ ফাংশন
// =============================================
function logSmsError(string $message): void
{
    $logDir  = __DIR__ . '/../../Logs';
    $logFile = $logDir . '/error_log.txt';

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(
        $logFile,
        "[{$timestamp}] [SMS] {$message}" . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}
