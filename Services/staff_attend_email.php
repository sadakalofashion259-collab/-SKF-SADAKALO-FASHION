<?php
/**
 * staff_attend_email.php
 * Email Notification Service — Attendance
 * Location: Public_html/Services/staff_attend_email.php
 *
 * Attendance-এর বিস্তারিত Email পাঠাবে।
 * Email-এ সম্পূর্ণ তথ্য থাকবে (SMS-এর মতো শর্টকাট নয়)।
 *
 * ⚠️  শুধু এই ফাইলে পরিবর্তন করলেই Email টেমপ্লেট আপডেট হবে।
 *     অন্য কোনো ফাইলে হাত দিতে হবে না।
 */


// =============================================
// Attendance Email পাঠানোর ফাংশন
// (শুধু এই ফাংশনটা call করলেই Email যাবে)
// =============================================
function sendAttendanceEmail(
    string $staffEmail,
    string $staffName,
    string $status,
    int    $lateMinutes = 0,
    string $leaveNote   = '',
    int    $staffId     = 0   // শুধু লগের জন্য
): bool {

    if (empty($staffEmail) || !filter_var($staffEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $formattedDate   = date('d/m/Y');
    $safeStaffName   = htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8');
    $safeStatus      = htmlspecialchars($status,    ENT_QUOTES, 'UTF-8');
    $safeLeaveNote   = htmlspecialchars($leaveNote, ENT_QUOTES, 'UTF-8');

    // স্ট্যাটাস অনুযায়ী রং
    $statusColor = '#10b981'; // Present = সবুজ
    if ($status === 'Absent') $statusColor = '#f43f5e';
    elseif ($status === 'Half') $statusColor = '#f97316';
    elseif ($status === 'Leave') $statusColor = '#0ea5e9';

    // লেট টাইম রো
    $lateRow = '';
    if ($lateMinutes > 0) {
        $lateRow = "
                                    <tr>
                                        <td style='padding:10px 0;color:#475569;font-size:14px;
                                                    border-top:1px solid #e2e8f0;font-weight:bold;'>Late Time</td>
                                        <td style='padding:10px 0;color:#f43f5e;text-align:right;
                                                    font-weight:bold;font-size:14px;
                                                    border-top:1px solid #e2e8f0;'>{$lateMinutes} Min</td>
                                    </tr>";
    }

    // নোট রো
    $noteRow = '';
    if (!empty($safeLeaveNote)) {
        $noteRow = "
                                    <tr>
                                        <td style='padding:10px 0;color:#475569;font-size:14px;
                                                    border-top:1px solid #e2e8f0;font-weight:bold;'>Remark/Note</td>
                                        <td style='padding:10px 0;color:#0ea5e9;text-align:right;
                                                    font-weight:bold;font-size:14px;
                                                    border-top:1px solid #e2e8f0;'>{$safeLeaveNote}</td>
                                    </tr>";
    }

    // =======================================
    // Email বিষয় ও HTML টেমপ্লেট
    // শুধু এই ব্লকটা বদলালেই হবে পরবর্তীতে
    // =======================================
    $subject = "Attendance Alert - SADA KALO FASHION";

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
                            <td style='background-color:#0f172a;padding:28px 30px;text-align:center;'>
                                <h1 style='margin:0;color:#10b981;font-size:22px;
                                           text-transform:uppercase;letter-spacing:2px;'>
                                    SADA KALO FASHION
                                </h1>
                                <p style='margin:6px 0 0;color:#94a3b8;font-size:12px;
                                          letter-spacing:1px;text-transform:uppercase;'>
                                    Daily Attendance Alert
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
                                    আপনার <b>{$formattedDate}</b> তারিখের হাজিরা রেকর্ড করা হয়েছে।
                                    নিচে বিস্তারিত তথ্য দেখুন:
                                </p>

                                <!-- Details Table -->
                                <div style='background:#f8fafc;border:1px solid #e2e8f0;
                                            border-radius:8px;padding:15px 20px;'>
                                    <table width='100%' cellpadding='0' cellspacing='0'
                                           style='border-collapse:collapse;font-size:14px;'>
                                        <tr>
                                            <td style='padding:10px 0;color:#475569;font-weight:bold;'>Date</td>
                                            <td style='padding:10px 0;color:#334155;text-align:right;
                                                        font-weight:bold;'>{$formattedDate}</td>
                                        </tr>
                                        <tr>
                                            <td style='padding:10px 0;color:#475569;font-weight:bold;
                                                        border-top:1px solid #e2e8f0;'>Status</td>
                                            <td style='padding:10px 0;text-align:right;font-weight:bold;
                                                        color:{$statusColor};font-size:15px;
                                                        border-top:1px solid #e2e8f0;'>{$safeStatus}</td>
                                        </tr>
                                        {$lateRow}
                                        {$noteRow}
                                    </table>
                                </div>

                                <p style='margin-top:24px;font-size:11px;color:#94a3b8;text-align:center;'>
                                    This is an auto-generated email. Please do not reply.
                                </p>
                            </td>
                        </tr>

                        <!-- Footer -->
                        <tr>
                            <td style='background:#f8fafc;padding:18px 30px;
                                        text-align:center;border-top:1px solid #e2e8f0;'>
                                <p style='margin:0;font-size:11px;color:#94a3b8;'>
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
    $headers .= "From: SADA KALO Attendance Alert <no-reply@sadakalofashion.com>\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    $sent = @mail($staffEmail, $subject, $body, $headers);

    if (!$sent) {
        logAttendEmailError("Email failed for Staff ID {$staffId} | Email: {$staffEmail}");
        return false;
    }

    return true;
}


// =============================================
// Email এরর লগ ফাংশন
// =============================================
function logAttendEmailError(string $message): void
{
    $logDir  = __DIR__ . '/../Logs';
    $logFile = $logDir . '/error_log.txt';

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(
        $logFile,
        "[{$timestamp}] [ATTEND-EMAIL] {$message}" . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}
