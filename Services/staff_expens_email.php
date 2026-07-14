<?php
/**
 * staff_expens_email.php
 * Email Notification Service — SADA KALO FASHION
 * Location: Public_html/Services/staff_expens_email.php
 *
 * Expense (খরচ) এর বিস্তারিত Email পাঠাবে।
 * শুধু খরচের টাকা দেখাবে — ব্যালেন্স/মাইনাস কিছু নেই।
 *
 * ⚠️  শুধু এই ফাইলে পরিবর্তন করলেই Email টেমপ্লেট আপডেট হবে।
 *     অন্য কোনো ফাইলে হাত দিতে হবে না।
 */


// =============================================
// Email পাঠানোর মূল ফাংশন
// =============================================
function sendExpenseEmail(
    string $staffEmail,
    string $staffName,
    string $expenseType,
    string $expenseDate,    // Y-m-d ফরম্যাটে পাঠাও
    float  $amount,
    string $details   = '',
    string $entryBy   = 'Admin',
    int    $staffId   = 0   // শুধু লগের জন্য
): bool {

    if (empty($staffEmail) || !filter_var($staffEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $formattedDate   = date('d/m/Y', strtotime($expenseDate));
    $formattedAmount = number_format($amount, 2);
    $safeStaffName   = htmlspecialchars($staffName,   ENT_QUOTES, 'UTF-8');
    $safeExpenseType = htmlspecialchars($expenseType, ENT_QUOTES, 'UTF-8');
    $safeDetails     = htmlspecialchars($details,     ENT_QUOTES, 'UTF-8');
    $safeEntryBy     = htmlspecialchars($entryBy,     ENT_QUOTES, 'UTF-8');

    $detailsRow = '';
    if (!empty($safeDetails)) {
        $detailsRow = "
        <tr>
            <td style='padding:10px 0;border-bottom:1px solid #e2e8f0;color:#475569;font-size:14px;'>Description</td>
            <td style='text-align:right;font-weight:bold;color:#334155;font-size:14px;'>{$safeDetails}</td>
        </tr>";
    }

    // =======================================
    // Email বিষয় ও HTML টেমপ্লেট
    // শুধু এই ব্লকটা বদলালেই হবে পরবর্তীতে
    // =======================================
    $subject = "Expense/Advance Alert - SADA KALO FASHION";

    $body = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    </head>
    <body style='margin:0;padding:0;background-color:#f1f5f9;font-family:Arial,Helvetica,sans-serif;'>
        <table width='100%' cellpadding='0' cellspacing='0' style='background-color:#f1f5f9;padding:30px 0;'>
            <tr>
                <td align='center'>
                    <table width='600' cellpadding='0' cellspacing='0'
                           style='max-width:600px;width:100%;background:#ffffff;border-radius:12px;
                                  overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);'>

                        <!-- Header -->
                        <tr>
                            <td style='background:linear-gradient(135deg,#ef4444,#b91c1c);
                                        padding:28px 30px;text-align:center;'>
                                <h1 style='margin:0;color:#ffffff;font-size:22px;
                                           text-transform:uppercase;letter-spacing:2px;'>
                                    SADA KALO FASHION
                                </h1>
                                <p style='margin:6px 0 0;color:#fecaca;font-size:12px;
                                          letter-spacing:1px;text-transform:uppercase;'>
                                    Expense &amp; Advance Notification
                                </p>
                            </td>
                        </tr>

                        <!-- Body -->
                        <tr>
                            <td style='padding:30px;'>
                                <p style='color:#334155;font-size:16px;margin:0 0 6px;'>
                                    Hello, <strong>{$safeStaffName}</strong>
                                </p>
                                <p style='color:#64748b;font-size:14px;margin:0 0 24px;'>
                                    একটি খরচ / অ্যাডভান্স আপনার অ্যাকাউন্টে এন্ট্রি করা হয়েছে।
                                    নিচে বিস্তারিত তথ্য দেখুন:
                                </p>

                                <!-- Details Table -->
                                <table width='100%' cellpadding='0' cellspacing='0'
                                       style='border-collapse:collapse;'>
                                    <tr>
                                        <td style='padding:10px 0;border-bottom:1px solid #e2e8f0;
                                                    color:#475569;font-size:14px;'>Date</td>
                                        <td style='text-align:right;font-weight:bold;color:#334155;
                                                    font-size:14px;'>{$formattedDate}</td>
                                    </tr>
                                    <tr>
                                        <td style='padding:10px 0;border-bottom:1px solid #e2e8f0;
                                                    color:#475569;font-size:14px;'>Type</td>
                                        <td style='text-align:right;font-weight:bold;
                                                    color:#ef4444;font-size:14px;'>{$safeExpenseType}</td>
                                    </tr>
                                    {$detailsRow}
                                    <tr>
                                        <td style='padding:10px 0;border-bottom:1px solid #e2e8f0;
                                                    color:#475569;font-size:14px;'>Amount</td>
                                        <td style='text-align:right;font-weight:900;
                                                    color:#ef4444;font-size:20px;'>
                                            Tk. {$formattedAmount}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style='padding:10px 0;color:#94a3b8;font-size:13px;'>
                                            Entry By
                                        </td>
                                        <td style='text-align:right;color:#94a3b8;font-size:13px;'>
                                            {$safeEntryBy}
                                        </td>
                                    </tr>
                                </table>

                                <!-- Alert Box -->
                                <div style='margin-top:24px;background:#fef2f2;border-left:4px solid #ef4444;
                                            border-radius:6px;padding:14px 18px;'>
                                    <p style='margin:0;color:#b91c1c;font-size:13px;font-weight:bold;'>
                                        &#9888;&#65039; যদি এই এন্ট্রি আপনি অনুমোদন না দিয়ে থাকেন,
                                        অনুগ্রহ করে অবিলম্বে HR-এর সাথে যোগাযোগ করুন।
                                    </p>
                                </div>
                            </td>
                        </tr>

                        <!-- Footer -->
                        <tr>
                            <td style='background:#f8fafc;padding:18px 30px;
                                        text-align:center;border-top:1px solid #e2e8f0;'>
                                <p style='margin:0;font-size:11px;color:#94a3b8;'>
                                    This is an auto-generated email. Please do not reply.<br>
                                    &copy; " . date('Y') . " SADA KALO FASHION &mdash; All rights reserved.
                                </p>
                            </td>
                        </tr>

                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>";
    // =======================================

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: SADA KALO HR <no-reply@sadakalofashion.com>\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    $sent = @mail($staffEmail, $subject, $body, $headers);

    if (!$sent) {
        logEmailError("Email failed for Staff ID {$staffId} | Email: {$staffEmail}");
        return false;
    }

    return true;
}


// =============================================
// Email এরর লগ ফাংশন
// =============================================
function logEmailError(string $message): void
{
    $logDir  = __DIR__ . '/../../Logs';
    $logFile = $logDir . '/error_log.txt';

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(
        $logFile,
        "[{$timestamp}] [EMAIL] {$message}" . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}
