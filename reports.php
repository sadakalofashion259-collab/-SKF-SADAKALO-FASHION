<?php
declare(strict_types=1);

require_once 'db_connect.php';
require_once __DIR__ . '/Controllers/DashboardController.php';

$ctrl = new DashboardController($conn);
$ctrl->handlePostRequests();
$view = $ctrl->getViewData();
$role = (string)($view['userRole'] ?? 'viewer');
$currentUser = (string)($view['userName'] ?? 'Unknown');

/* ═══════════════════════════════════════════════
   CONSTANTS & HELPERS
═══════════════════════════════════════════════ */
$today        = date('Y-m-d');
$yesterday    = date('Y-m-d', strtotime('-1 day'));
$isAdmin      = ($role === 'admin');
$isManager    = ($role === 'manager');
$canSeeOld    = $isAdmin; // only admin sees older than 2 days

/* ─── Lock file path ─── */
$lockFile     = __DIR__ . '/Locks/report_lock.json';
$lockDir      = __DIR__ . '/Locks';
if (!is_dir($lockDir)) { @mkdir($lockDir, 0755, true); }

/* ─── Handle LOCK / UNLOCK action ─── */
if ($isAdmin && isset($_POST['toggle_lock'])) {
    $lockData = [];
    if (file_exists($lockFile)) {
        $lockData = json_decode(file_get_contents($lockFile), true) ?? [];
    }
    $nowLocked = !empty($lockData['locked']);
    $lockData  = [
        'locked'    => !$nowLocked,
        'locked_by' => !$nowLocked ? $currentUser : null,
        'locked_at' => !$nowLocked ? date('Y-m-d H:i:s') : null,
    ];
    file_put_contents($lockFile, json_encode($lockData));
    header('Location: reports.php' . (isset($_GET['date']) ? '?date='.urlencode($_GET['date']) : ''));
    exit;
}

/* ─── Read lock state ─── */
$lockData    = [];
if (file_exists($lockFile)) {
    $lockData = json_decode(file_get_contents($lockFile), true) ?? [];
}
$isLocked    = !empty($lockData['locked']);
$lockedBy    = htmlspecialchars((string)($lockData['locked_by'] ?? ''), ENT_QUOTES, 'UTF-8');
$lockedAt    = htmlspecialchars((string)($lockData['locked_at'] ?? ''), ENT_QUOTES, 'UTF-8');

/* ─── If locked and not admin → block ─── */
if ($isLocked && !$isAdmin) {
    /* Show locked screen and exit */
  
    ?>
    <!DOCTYPE html>
    <html lang="bn" data-bs-theme="light">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
        <title>পেজ লক — সাদাকালো ফ্যাশন</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;700;800&family=DM+Sans:wght@400;700;800&display=swap" rel="stylesheet">
        <style>
            body { font-family:'DM Sans','Noto Sans Bengali',sans-serif; background:#F1F5F9; min-height:100vh; display:flex; align-items:center; justify-content:center; margin:0; }
            .lock-box { background:#fff; border-radius:20px; padding:40px 30px; text-align:center; box-shadow:0 10px 40px rgba(15,23,42,.12); max-width:320px; width:90%; border-top:4px solid #DC2626; }
            .lock-icon { width:72px; height:72px; background:#FEE2E2; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:30px; color:#DC2626; }
            h2 { font-size:20px; font-weight:900; margin:0 0 6px; color:#0F172A; }
            p  { font-size:13px; color:#64748B; margin:0 0 4px; }
            .meta { font-size:11px; color:#94A3B8; margin-top:10px; }
            .back-btn { display:inline-flex; align-items:center; gap:6px; margin-top:18px; background:#2563EB; color:#fff; border-radius:10px; padding:10px 20px; font-size:13px; font-weight:800; text-decoration:none; }
        </style>
    </head>
    <body>
        <div class="lock-box">
            <div class="lock-icon"><i class="fas fa-lock"></i></div>
            <h2>রিপোর্ট পেজ লক!</h2>
            <p>এডমিন এই পেজটি লক করে রেখেছেন।</p>
            <?php if($lockedBy): ?><p class="meta"><i class="fas fa-user"></i> লক করেছেন: <strong><?php echo $lockedBy; ?></strong></p><?php endif; ?>
            <?php if($lockedAt):  ?><p class="meta"><i class="fas fa-clock"></i> সময়: <?php echo $lockedAt; ?></p><?php endif; ?>
            <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> ড্যাশবোর্ডে ফিরুন</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/* ─── Date filter with role restriction ─── */
$rawDate    = trim((string)($_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) { $rawDate = $today; }

/* Non-admin: clamp to last 2 days */
if (!$canSeeOld) {
    $reqTs = strtotime($rawDate);
    $ystTs = strtotime($yesterday);
    if ($reqTs < $ystTs) {
        $rawDate = $yesterday; // silently clamp
        $dateWarning = 'আপনি শুধুমাত্র আজ এবং গতকালের ডেটা দেখতে পারবেন।';
    }
}
/* Nobody can select future dates */
if (strtotime($rawDate) > strtotime($today)) { $rawDate = $today; }
$filterDate = htmlspecialchars($rawDate, ENT_QUOTES, 'UTF-8');

/* ─── Fetch report data ─── */
$entries    = [];
$reportInfo = null;
$grandBill  = 0.0; $grandPaid = 0.0; $grandDue = 0.0; $grandQty = 0.0; $entryCount = 0;

try {
    $stmt = $conn->prepare("SELECT * FROM daily_reports WHERE report_date = ? LIMIT 1");
    $stmt->execute([$filterDate]);
    $reportInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($reportInfo) {
        $rid   = (int)$reportInfo['id'];
        $stmt2 = $conn->prepare("SELECT * FROM sales_entries WHERE report_id = ? ORDER BY memo_no ASC");
        $stmt2->execute([$rid]);
        $entries = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        foreach ($entries as $e) {
            $grandBill += (float)$e['total_bill'];
            $grandPaid += (float)$e['paid_amount'];
            $grandDue  += (float)$e['due_amount'];
            $grandQty  += (float)$e['quantity'];
            $entryCount++;
        }
    }
} catch (PDOException $ex) {
    error_log('[REPORT] ' . $ex->getMessage());
}

$bnDays = ['Saturday'=>'শনিবার','Sunday'=>'রবিবার','Monday'=>'সোমবার','Tuesday'=>'মঙ্গলবার','Wednesday'=>'বুধবার','Thursday'=>'বৃহস্পতিবার','Friday'=>'শুক্রবার'];
$dayName = $bnDays[date('l', strtotime($filterDate))] ?? '';
$printSerial = 'SKF-' . date('YmdHi');
?>
<!DOCTYPE html>
<html lang="bn" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no,viewport-fit=cover">
    <title>দৈনিক রিপোর্ট — সাদাকালো ফ্যাশন</title>
    <meta name="theme-color" content="#2563EB">
    <link rel="manifest" href="manifest.json">

    <!-- Bootstrap 5.3.8 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700;800&family=DM+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        /* ═══════════════════ TOKENS ═══════════════════ */
        :root {
            --p:#2563EB; --p-d:#1D4ED8; --p-l:#DBEAFE;
            --accent:#0EA5E9;
            --ok:#059669; --ok-l:#D1FAE5;
            --err:#DC2626; --err-l:#FEE2E2;
            --warn:#D97706; --warn-l:#FEF3C7;
            --purple:#7C3AED; --purple-l:#EDE9FE;

            --bg:#F1F5F9; --card:#FFFFFF; --nav-bg:#FFFFFF;
            --inp-bg:#F8FAFC; --muted:#F1F5F9;
            --tx1:#0F172A; --tx2:#475569; --tx3:#94A3B8;
            --border:#CBD5E1;
            --r:14px; --r-sm:10px;
            --sh:0 1px 4px rgba(15,23,42,.08);
            --sh-md:0 4px 14px rgba(15,23,42,.10);
            --sh-lg:0 10px 32px rgba(15,23,42,.12);
            --nav-h:62px; --bar-h:70px;
        }
        [data-bs-theme="dark"] {
            --bg:#0F172A; --card:#1E293B; --nav-bg:#1E293B;
            --inp-bg:#0F172A; --muted:#1E293B;
            --tx1:#F8FAFC; --tx2:#CBD5E1; --tx3:#64748B; --border:#334155;
            --sh:0 1px 3px rgba(0,0,0,.5); --sh-md:0 4px 12px rgba(0,0,0,.5); --sh-lg:0 10px 30px rgba(0,0,0,.6);
        }

        *,*::before,*::after{box-sizing:border-box;}
        html{-webkit-text-size-adjust:100%;}
        body{
            font-family:'DM Sans','Noto Sans Bengali',sans-serif;
            background:var(--bg); color:var(--tx1); margin:0; overflow-x:hidden;
            padding-bottom:calc(var(--bar-h) + env(safe-area-inset-bottom) + 10px);
            -webkit-font-smoothing:antialiased; transition:background .3s,color .3s;
        }

        /* ── TICKER ── */
        @keyframes ticker{to{transform:translateX(-50%)}}
        @keyframes bgC{0%{background:#2563EB}33%{background:#059669}66%{background:#0EA5E9}100%{background:#2563EB}}
        .ticker-strip{animation:bgC 12s infinite;height:26px;overflow:hidden;display:flex;align-items:center;}
        .ticker-inner{white-space:nowrap;display:inline-block;animation:ticker 22s linear infinite;color:#fff;font-size:11px;font-weight:700;letter-spacing:.3px;}

        /* ── NAVBAR ── */
        .app-nav{position:sticky;top:0;z-index:900;background:var(--nav-bg);border-bottom:1px solid var(--border);height:var(--nav-h);display:flex;align-items:center;padding:0 14px;gap:10px;box-shadow:var(--sh);transition:background .3s,border-color .3s;}
        .nav-ico{width:38px;height:38px;border-radius:50%;border:none;background:var(--muted);color:var(--tx2);font-size:15px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;text-decoration:none;transition:.2s;}
        .nav-ico:hover{background:var(--p-l);color:var(--p);}
        .brand-logo{width:36px;height:36px;border-radius:50%;border:2px solid var(--p-l);object-fit:cover;flex-shrink:0;}
        .brand-h1{font-size:15px;font-weight:900;color:var(--p);margin:0;text-transform:uppercase;letter-spacing:.4px;line-height:1.2;}
        .brand-sub{font-size:10px;color:var(--tx3);margin:0;font-weight:700;letter-spacing:.4px;}

        /* ── LOCK BANNER ── */
        .lock-banner{
            background:linear-gradient(90deg,#DC2626,#B91C1C);
            color:#fff; padding:10px 16px;
            display:flex; align-items:center; gap:10px;
            font-size:12px; font-weight:700;
        }
        .lock-banner i{font-size:16px;}
        .lock-banner .lb-meta{font-size:10px;opacity:.8;flex:1;}
        .unlock-btn{background:rgba(255,255,255,.2);border:1.5px solid rgba(255,255,255,.4);color:#fff;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:800;cursor:pointer;font-family:inherit;}

        /* ── DRAWER ── */
        .dr-ov{position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);z-index:1040;opacity:0;visibility:hidden;transition:.3s;}
        .dr-ov.on{opacity:1;visibility:visible;}
        .drawer{position:fixed;top:0;left:-295px;width:278px;height:100%;background:var(--card);z-index:1050;overflow-y:auto;border-right:1px solid var(--border);box-shadow:var(--sh-lg);transition:left .35s cubic-bezier(.4,0,.2,1);}
        .drawer.on{left:0;}
        .dr-head{background:linear-gradient(135deg,var(--p),var(--accent));padding:26px 16px 18px;color:#fff;position:relative;}
        .dr-close{position:absolute;top:12px;right:12px;background:rgba(255,255,255,.2);border:none;color:#fff;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:13px;}
        .dr-av{width:52px;height:52px;border-radius:50%;border:3px solid rgba(255,255,255,.4);object-fit:cover;margin-bottom:8px;}
        .dr-nm{font-size:15px;font-weight:800;margin:0;}
        .dr-rl{font-size:10px;opacity:.8;text-transform:uppercase;letter-spacing:.5px;}
        .dr-sl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--tx3);padding:12px 14px 4px;}
        .dr-it{display:flex;align-items:center;gap:12px;padding:11px 14px;color:var(--tx2);text-decoration:none;font-size:13px;font-weight:600;border-radius:10px;margin:2px 8px;transition:.2s;cursor:pointer;}
        .dr-it:hover,.dr-it.on{background:var(--p-l);color:var(--p);}
        .dr-it .ic{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}

        /* ── LOCK TOGGLE in SIDEBAR ── */
        .lock-toggle-item{
            display:flex;align-items:center;gap:12px;
            padding:11px 14px;border-radius:10px;margin:2px 8px;
            cursor:pointer;border:none;width:calc(100% - 16px);
            font-size:13px;font-weight:700;font-family:inherit;
            transition:.2s;
        }
        .lock-toggle-item.locked{background:var(--err-l);color:var(--err);}
        .lock-toggle-item.locked:hover{background:var(--err);color:#fff;}
        .lock-toggle-item.unlocked{background:var(--ok-l);color:var(--ok);}
        .lock-toggle-item.unlocked:hover{background:var(--ok);color:#fff;}
        .lock-ic{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;background:inherit;}

        .dr-th{width:calc(100% - 16px);margin:8px;padding:11px;border-radius:10px;border:1.5px solid var(--border);background:var(--muted);color:var(--tx1);font-weight:700;font-size:13px;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:8px;}
        .dr-th:hover{background:var(--p-l);border-color:var(--p);color:var(--p);}

        /* ── PAGE WRAP ── */
        .pw{max-width:600px;margin:0 auto;padding:12px 11px;}

        /* ── WARNING CHIP ── */
        .warn-chip{
            background:var(--warn-l);border:1.5px solid var(--warn);
            border-radius:10px;padding:8px 14px;
            display:flex;align-items:center;gap:8px;
            font-size:12px;font-weight:700;color:var(--warn);
            margin-bottom:12px;
        }

        /* ── FILTER BAR ── */
        .filter-bar{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:12px 14px;display:flex;align-items:center;gap:10px;box-shadow:var(--sh);margin-bottom:14px;flex-wrap:wrap;}
        .filter-bar label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--tx3);flex-shrink:0;}
        .date-inp{flex:1;min-width:130px;background:var(--inp-bg);color:var(--tx1);border:1.5px solid var(--border);border-radius:var(--r-sm);padding:9px 12px;font-size:13px;font-weight:700;font-family:inherit;outline:none;transition:.2s;-webkit-appearance:none;}
        .date-inp:focus{border-color:var(--p);box-shadow:0 0 0 3px rgba(37,99,235,.13);}
        .filter-btn{background:var(--p);color:#fff;border:none;border-radius:var(--r-sm);padding:9px 16px;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;transition:.15s;white-space:nowrap;}
        .filter-btn:active{transform:scale(.97);}

        /* role restriction badge */
        .date-restrict-tag{display:inline-flex;align-items:center;gap:5px;background:var(--warn-l);color:var(--warn);border-radius:6px;padding:3px 8px;font-size:10px;font-weight:800;white-space:nowrap;}

        /* ── STAT CARDS ── */
        .stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin-bottom:14px;}
        @media(max-width:430px){.stat-grid{grid-template-columns:repeat(2,1fr);}}
        .scard{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:13px 8px;text-align:center;box-shadow:var(--sh);position:relative;overflow:hidden;}
        .scard::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
        .sc-blue::before{background:var(--p);}
        .sc-green::before{background:var(--ok);}
        .sc-red::before{background:var(--err);}
        .sc-purple::before{background:var(--purple);}
        .scard .sv{font-size:20px;font-weight:900;line-height:1;margin-bottom:3px;}
        .scard .sl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--tx3);}
        .sc-blue .sv{color:var(--p);}
        .sc-green .sv{color:var(--ok);}
        .sc-red .sv{color:var(--err);}
        .sc-purple .sv{color:var(--purple);}

        /* ── INVOICE CARD ── */
        .invoice-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--sh-md);overflow:hidden;margin-bottom:14px;}

        /* Header */
        .inv-header{background:linear-gradient(135deg,#0F172A 0%,#1E293B 100%);padding:18px 16px 14px;color:#fff;position:relative;}
        .inv-logo-row{display:flex;align-items:center;gap:12px;margin-bottom:12px;}
        .inv-logo{width:42px;height:42px;border-radius:50%;border:2px solid rgba(255,255,255,.3);object-fit:cover;}
        .inv-brand h2{font-size:16px;font-weight:900;margin:0;color:#38BDF8;line-height:1.2;}
        .inv-brand p{font-size:10px;margin:0;opacity:.7;text-transform:uppercase;letter-spacing:.5px;}
        .inv-serial{font-size:9px;color:#64748B;margin-top:2px;letter-spacing:.3px;}
        .inv-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;background:rgba(255,255,255,.06);border-radius:10px;padding:10px 12px;border:1px solid rgba(255,255,255,.1);}
        .im-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;opacity:.6;display:block;margin-bottom:1px;}
        .im-val{font-size:12px;font-weight:800;color:#E2E8F0;}
        .inv-prep{display:flex;align-items:center;gap:7px;margin-top:10px;font-size:11px;color:#94A3B8;}
        .inv-prep span{color:#38BDF8;font-weight:800;}

        /* Table */
        .inv-table-wrap{overflow-x:auto;}
        .inv-table{width:100%;border-collapse:collapse;font-size:12px;min-width:380px;}
        .inv-table thead tr{background:var(--p);color:#fff;}
        .inv-table thead th{padding:9px 7px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;}
        .inv-table tbody tr{border-bottom:1px solid var(--border);transition:background .15s;}
        .inv-table tbody tr:hover{background:var(--muted);}
        .inv-table tbody tr:nth-child(even){background:rgba(15,23,42,.015);}
        .inv-table tbody td{padding:9px 7px;font-weight:600;vertical-align:middle;}
        .inv-table tfoot tr{font-weight:900;}
        .inv-table tfoot td{padding:10px 7px;border-top:2px solid var(--border);font-size:13px;background:var(--muted);}

        .memo-chip{background:var(--p-l);color:var(--p);border-radius:20px;padding:2px 8px;font-size:10px;font-weight:800;display:inline-block;white-space:nowrap;}
        .amt-ok{color:var(--ok);font-weight:900;}
        .amt-err{color:var(--err);font-weight:900;}
        .amt-p{color:var(--p);font-weight:900;}
        .amt-zero{color:var(--tx3);font-weight:600;}

        .tbl-photo{width:30px;height:30px;border-radius:6px;object-fit:cover;border:1.5px solid var(--border);cursor:pointer;transition:.2s;}
        .tbl-photo:hover{transform:scale(1.12);border-color:var(--p);}

        /* Footer */
        .inv-footer{background:var(--muted);border-top:2px dashed var(--border);padding:14px 16px;}
        .inv-total-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
        .itc{text-align:center;padding:10px 6px;border-radius:10px;}
        .itc-bill{background:var(--p-l);}  .itc-paid{background:var(--ok-l);}  .itc-due{background:var(--err-l);}
        .itv{font-size:17px;font-weight:900;line-height:1;margin-bottom:2px;}
        .itl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--tx3);}
        .itc-bill .itv{color:var(--p);}  .itc-paid .itv{color:var(--ok);}  .itc-due .itv{color:var(--err);}

        /* Signature */
        .sig-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:16px;padding-top:12px;border-top:1.5px dashed var(--border);}
        .sig-box{text-align:center;}
        .sig-line{height:32px;border-bottom:1.5px solid var(--tx3);margin-bottom:4px;}
        .sig-lbl{font-size:10px;color:var(--tx3);font-weight:700;}

        /* Empty */
        .empty-state{background:var(--card);border:2px dashed var(--border);border-radius:var(--r);padding:40px 20px;text-align:center;}
        .empty-state i{font-size:42px;color:var(--tx3);display:block;margin-bottom:12px;}
        .empty-state h3{font-size:16px;font-weight:800;margin:0 0 4px;color:var(--tx2);}
        .empty-state p{font-size:12px;color:var(--tx3);margin:0;}

        /* ── LIGHTBOX ── */
        .lightbox{display:none;position:fixed;inset:0;background:rgba(15,23,42,.92);backdrop-filter:blur(8px);z-index:9999;align-items:center;justify-content:center;}
        .lightbox.on{display:flex;}
        .lightbox img{max-width:90vw;max-height:85vh;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.5);}
        .lb-close{position:absolute;top:16px;right:16px;background:rgba(255,255,255,.15);border:none;color:#fff;width:36px;height:36px;border-radius:50%;font-size:16px;display:flex;align-items:center;justify-content:center;cursor:pointer;}

        /* ── BOTTOM BAR ── */
        .btm-bar{position:fixed;bottom:0;left:0;right:0;z-index:700;background:var(--nav-bg);border-top:1px solid var(--border);padding:9px 14px;padding-bottom:calc(9px + env(safe-area-inset-bottom));display:flex;align-items:center;gap:9px;box-shadow:0 -4px 20px rgba(15,23,42,.08);transition:background .3s;}
        .print-btn{flex:1;background:var(--p);color:#fff;border:none;border-radius:12px;padding:12px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:7px;transition:.15s;}
        .print-btn:active{transform:scale(.97);}
        .share-btn{background:var(--ok);color:#fff;border:none;border-radius:12px;padding:12px 14px;font-size:15px;font-weight:800;cursor:pointer;font-family:inherit;flex-shrink:0;transition:.15s;}
        .share-btn:active{transform:scale(.97);}

        /* ══════════════════════════════════════
           PRINT STYLES
        ══════════════════════════════════════ */
        @media print {
            .ticker-strip,.app-nav,.lock-banner,.filter-bar,.btm-bar,
            .dr-ov,.drawer,.lightbox,.warn-chip { display:none!important; }

            body { padding:0!important; background:#fff!important; color:#000!important; font-size:11px; }
            .pw  { max-width:100%; padding:0; margin:0; }

            /* ── Print company header ── */
            .print-company-header {
                display:block!important;
                text-align:center;
                padding:16px 0 12px;
                border-bottom:2px solid #0F172A;
                margin-bottom:14px;
            }
            .print-company-header h1 {
                font-size:22px; font-weight:900; margin:0; color:#0F172A; letter-spacing:1px;
            }
            .print-company-header p {
                font-size:13px; margin:2px 0 0; color:#475569; letter-spacing:.5px; font-weight:700;
            }
            .print-company-header .print-sub {
                font-size:10px; color:#94A3B8; margin-top:4px;
            }

            .stat-grid { display:grid; grid-template-columns:repeat(4,1fr)!important; }
            .scard { border:1px solid #CBD5E1!important; box-shadow:none!important; }

            .invoice-card { box-shadow:none!important; border:1px solid #CBD5E1!important; page-break-inside:avoid; }
            .inv-header {
                background:#0F172A!important;
                -webkit-print-color-adjust:exact!important;
                print-color-adjust:exact!important;
            }
            .inv-table thead tr {
                background:#2563EB!important;
                -webkit-print-color-adjust:exact!important;
                print-color-adjust:exact!important;
            }
            .inv-total-row .itc {
                -webkit-print-color-adjust:exact!important;
                print-color-adjust:exact!important;
            }
            .inv-footer { background:#F1F5F9!important; -webkit-print-color-adjust:exact!important; print-color-adjust:exact!important; }
        }

        /* Hidden on screen, shown on print */
        .print-company-header { display:none; }

        ::-webkit-scrollbar{width:4px;}
        ::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px;}
    </style>
</head>
<body>

<!-- TICKER -->
<div class="ticker-strip">
    <div class="ticker-inner">
        &nbsp;&nbsp;📊 দৈনিক বিক্রি রিপোর্ট | সাদাকালো ফ্যাশন &nbsp;&nbsp;
        🌿 বিসমিল্লাহির রাহমানির রাহিম — পরম করুণাময় ও অসীম দয়ালু আল্লাহর নামে 🍃 &nbsp;&nbsp;
        📊 দৈনিক বিক্রি রিপোর্ট | সাদাকালো ফ্যাশন &nbsp;&nbsp;
        🌿 بِسْمِ ٱللَّٰهِ ٱلرَّحْمَٰنِ ٱلرَّحِيمِ &nbsp;&nbsp;
    </div>
</div>

<!-- LOCK BANNER (when locked, admin sees this) -->
<?php if ($isLocked && $isAdmin): ?>
<div class="lock-banner">
    <i class="fas fa-lock"></i>
    <div class="lb-meta">
        পেজ লক আছে — সাধারণ ইউজাররা এই পেজ দেখতে পাচ্ছেন না।
        <?php if($lockedBy): ?> | লক করেছেন: <strong><?php echo $lockedBy; ?></strong><?php endif; ?>
    </div>
    <form method="POST" style="margin:0;">
        <input type="hidden" name="toggle_lock" value="1">
        <button type="submit" class="unlock-btn"><i class="fas fa-lock-open"></i> আনলক করুন</button>
    </form>
</div>
<?php endif; ?>

<!-- NAVBAR -->
<nav class="app-nav">
    <button class="nav-ico" onclick="toggleDrawer()" aria-label="মেনু"><i class="fas fa-bars"></i></button>
    <a href="dashboard.php" style="text-decoration:none;display:flex;align-items:center;gap:9px;flex:1;min-width:0;">
        <img src="logo.png" class="brand-logo" alt="Logo" onerror="this.src='https://via.placeholder.com/36/2563EB/fff?text=SK'">
        <div>
            <h1 class="brand-h1">দৈনিক রিপোর্ট</h1>
            <p class="brand-sub">সাদাকালো ফ্যাশন</p>
        </div>
    </a>
    <a href="cash_sale.php" class="nav-ico" title="বিক্রি"><i class="fas fa-shopping-cart"></i></a>
    <a href="dashboard.php" class="nav-ico" title="Dashboard"><i class="fas fa-arrow-left"></i></a>
</nav>

<!-- DRAWER OVERLAY -->
<div class="dr-ov" id="drOverlay" onclick="toggleDrawer()"></div>
<aside class="drawer" id="mainDrawer">
    <div class="dr-head">
        <button class="dr-close" onclick="toggleDrawer()"><i class="fas fa-times"></i></button>
        <img src="logo.png" class="dr-av" alt="Logo" onerror="this.src='https://via.placeholder.com/52/fff/2563EB?text=SK'">
        <p class="dr-nm"><?php echo htmlspecialchars($currentUser, ENT_QUOTES, 'UTF-8'); ?></p>
        <p class="dr-rl"><?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <div class="dr-sl">মূল মেনু</div>
    <a href="dashboard.php"   class="dr-it"><div class="ic" style="background:#DBEAFE;color:#2563EB;"><i class="fas fa-home"></i></div> ড্যাশবোর্ড</a>
    <a href="cash_sale.php"   class="dr-it"><div class="ic" style="background:#D1FAE5;color:#059669;"><i class="fas fa-shopping-cart"></i></div> ক্যাশ বিক্রি</a>
    <?php if ($isAdmin): ?>
    <div class="dr-sl">এডমিন টুলস</div>
    <!-- LOCK / UNLOCK BUTTON -->
    <form method="POST" style="margin:0;padding:0 0;">
        <input type="hidden" name="toggle_lock" value="1">
        <?php if($isLocked): ?>
        <button type="submit" class="lock-toggle-item locked">
            <div class="ic" style="background:var(--err-l);color:var(--err);border-radius:9px;width:34px;height:34px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;">
                <i class="fas fa-lock-open"></i>
            </div>
            রিপোর্ট আনলক করুন
        </button>
        <?php else: ?>
        <button type="submit" class="lock-toggle-item unlocked">
            <div class="ic" style="background:var(--ok-l);color:var(--ok);border-radius:9px;width:34px;height:34px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;">
                <i class="fas fa-lock"></i>
            </div>
            রিপোর্ট লক করুন
        </button>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <div class="dr-sl">সেটিংস</div>
    <button class="dr-th" id="themeBtn" onclick="toggleTheme()">
        <i class="fas fa-moon" id="themeIco"></i> <span id="themeTxt">ডার্ক মোড</span>
    </button>
</aside>

<!-- PRINT-ONLY COMPANY HEADER -->
<div class="print-company-header">
    <h1>সাদাকালো ফ্যাশন</h1>
    <p>হিসাব সিস্টেম</p>
    <div class="print-sub">দৈনিক বিক্রি রিপোর্ট | তারিখ: <?php echo $filterDate; ?> | <?php echo htmlspecialchars($dayName, ENT_QUOTES, 'UTF-8'); ?></div>
</div>

<!-- MAIN -->
<main class="pw">

    <?php if (!empty($dateWarning)): ?>
    <div class="warn-chip">
        <i class="fas fa-exclamation-triangle"></i>
        <?php echo htmlspecialchars($dateWarning, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <!-- DATE FILTER -->
    <form method="GET" action="reports.php">
        <div class="filter-bar">
            <label><i class="fas fa-calendar-day" style="font-size:12px;"></i> তারিখ</label>
            <input type="date" name="date" class="date-inp"
                   value="<?php echo $filterDate; ?>"
                   max="<?php echo $today; ?>"
                   <?php echo $canSeeOld ? '' : 'min="'.$yesterday.'"'; ?>>
            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
                <button type="submit" class="filter-btn"><i class="fas fa-search"></i> দেখুন</button>
                <?php if(!$canSeeOld): ?>
                <span class="date-restrict-tag"><i class="fas fa-lock"></i> ২ দিন</span>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <!-- STAT CARDS -->
    <div class="stat-grid">
        <div class="scard sc-purple">
            <div class="sv"><?php echo $entryCount; ?></div>
            <div class="sl">মোট মেমো</div>
        </div>
        <div class="scard sc-blue">
            <div class="sv"><?php echo number_format($grandQty, 0); ?></div>
            <div class="sl">মোট পিস</div>
        </div>
        <div class="scard sc-green">
            <div class="sv">৳<?php echo number_format($grandPaid, 0); ?></div>
            <div class="sl">মোট জমা</div>
        </div>
        <div class="scard sc-red">
            <div class="sv">৳<?php echo number_format($grandDue, 0); ?></div>
            <div class="sl">মোট বাকি</div>
        </div>
    </div>

    <!-- INVOICE CARD -->
    <?php if ($reportInfo && !empty($entries)): ?>
    <div class="invoice-card" id="invoiceCard">

        <!-- Header -->
        <div class="inv-header">
            <div class="inv-logo-row">
                <img src="logo.png" class="inv-logo" alt="Logo"
                     onerror="this.src='https://via.placeholder.com/42/38BDF8/0F172A?text=SK'">
                <div class="inv-brand">
                    <h2>সাদাকালো ফ্যাশন</h2>
                    <p>দৈনিক বিক্রি ইনভয়েস</p>
                    <!-- Serial shown, DB ID hidden -->
                    <div class="inv-serial">রিপোর্ট: <?php echo htmlspecialchars($printSerial, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
            <div class="inv-meta">
                <div>
                    <span class="im-lbl">তারিখ</span>
                    <span class="im-val"><?php echo htmlspecialchars($filterDate, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div>
                    <span class="im-lbl">দিন</span>
                    <span class="im-val"><?php echo htmlspecialchars($dayName, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div>
                    <span class="im-lbl">মোট মেমো</span>
                    <span class="im-val"><?php echo $entryCount; ?>টি</span>
                </div>
                <div>
                    <span class="im-lbl">প্রিন্ট সময়</span>
                    <span class="im-val" id="printTime">--</span>
                </div>
            </div>
            <?php if (!empty($reportInfo['prepared_by'])): ?>
            <div class="inv-prep">
                <i class="fas fa-user-check" style="font-size:11px;"></i>
                প্রস্তুতকারী: <span><?php echo htmlspecialchars((string)$reportInfo['prepared_by'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Table -->
        <div class="inv-table-wrap">
            <table class="inv-table">
                <thead>
                    <tr>
                        <th style="text-align:center;width:34px;">#</th>
                        <th style="text-align:center;">মেমো</th>
                        <th style="text-align:left;">কাস্টমার</th>
                        <th style="text-align:center;">পিস</th>
                        <th style="text-align:right;">বিল ৳</th>
                        <th style="text-align:right;">জমা ৳</th>
                        <th style="text-align:right;">বাকি ৳</th>
                        <th style="text-align:center;">ছবি</th>
                        <th style="text-align:center;">By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $sl = 1; foreach ($entries as $entry): ?>
                    <tr>
                        <td style="text-align:center;color:var(--tx3);font-size:11px;"><?php echo $sl++; ?></td>
                        <td style="text-align:center;">
                            <span class="memo-chip"><?php echo htmlspecialchars((string)$entry['memo_no'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td style="text-align:left;max-width:110px;">
                            <span style="font-size:12px;font-weight:700;word-break:break-word;">
                                <?php echo htmlspecialchars((string)$entry['customer_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td style="text-align:center;font-weight:700;"><?php echo htmlspecialchars((string)$entry['quantity'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="text-align:right;" class="amt-p"><?php echo number_format((float)$entry['total_bill'], 0); ?></td>
                        <td style="text-align:right;" class="amt-ok"><?php echo number_format((float)$entry['paid_amount'], 0); ?></td>
                        <td style="text-align:right;" class="<?php echo (float)$entry['due_amount'] > 0 ? 'amt-err' : 'amt-zero'; ?>">
                            <?php echo number_format((float)$entry['due_amount'], 0); ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if (!empty($entry['photo'])): ?>
                            <img src="<?php echo htmlspecialchars((string)$entry['photo'], ENT_QUOTES, 'UTF-8'); ?>"
                                 class="tbl-photo" alt="Memo"
                                 onclick="openLightbox(this.src)"
                                 onerror="this.style.display='none'">
                            <?php else: ?>
                            <span style="color:var(--tx3);font-size:11px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <span style="font-size:10px;color:var(--tx3);font-weight:700;">
                                <?php echo htmlspecialchars((string)($entry['entry_by'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align:right;color:var(--tx3);font-size:11px;">সর্বমোট ৳</td>
                        <td style="text-align:center;color:var(--purple);font-weight:900;"><?php echo number_format($grandQty, 0); ?></td>
                        <td style="text-align:right;" class="amt-p"><?php echo number_format($grandBill, 0); ?></td>
                        <td style="text-align:right;" class="amt-ok"><?php echo number_format($grandPaid, 0); ?></td>
                        <td style="text-align:right;" class="<?php echo $grandDue > 0 ? 'amt-err' : 'amt-zero'; ?>"><?php echo number_format($grandDue, 0); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Footer -->
        <div class="inv-footer">
            <div class="inv-total-row">
                <div class="itc itc-bill">
                    <div class="itv">৳<?php echo number_format($grandBill, 0); ?></div>
                    <div class="itl">মোট বিল</div>
                </div>
                <div class="itc itc-paid">
                    <div class="itv">৳<?php echo number_format($grandPaid, 0); ?></div>
                    <div class="itl">মোট জমা</div>
                </div>
                <div class="itc itc-due">
                    <div class="itv">৳<?php echo number_format($grandDue, 0); ?></div>
                    <div class="itl">মোট বাকি</div>
                </div>
            </div>

            <!-- Company sig row -->
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-top:14px;flex-wrap:wrap;gap:8px;">
                <div style="font-size:11px;color:var(--tx2);">
                    <strong style="color:var(--tx1);font-size:13px;">সাদাকালো ফ্যাশন</strong><br>
                    <span style="color:var(--tx3);">হিসাব সিস্টেম</span>
                </div>
                <div style="text-align:right;font-size:11px;color:var(--tx3);">
                    তারিখ: <strong style="color:var(--tx1);"><?php echo htmlspecialchars($filterDate, ENT_QUOTES, 'UTF-8'); ?></strong><br>
                    <span style="font-size:10px;">কম্পিউটার জেনারেটেড ইনভয়েস</span>
                </div>
            </div>

            <!-- Signature lines -->
            <div class="sig-row">
                <div class="sig-box">
                    <div class="sig-line"></div>
                    <div class="sig-lbl">প্রস্তুতকারীর স্বাক্ষর</div>
                </div>
                <div class="sig-box">
                    <div class="sig-line">#Sada Kalo#</div>
                    <div class="sig-lbl">অনুমোদনকারীর স্বাক্ষর</div>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <div class="empty-state">
        <i class="fas fa-file-invoice"></i>
        <h3>কোনো রিপোর্ট পাওয়া যায়নি</h3>
        <p><?php echo htmlspecialchars($filterDate, ENT_QUOTES, 'UTF-8'); ?> তারিখে কোনো বিক্রি এন্ট্রি নেই।</p>
        <a href="cash_sale.php" style="display:inline-flex;align-items:center;gap:6px;margin-top:14px;background:var(--p);color:#fff;border-radius:10px;padding:10px 18px;font-size:12px;font-weight:800;text-decoration:none;">
            <i class="fas fa-plus"></i> বিক্রি এন্ট্রি করুন
        </a>
    </div>
    <?php endif; ?>

</main>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <button class="lb-close"><i class="fas fa-times"></i></button>
    <img id="lbImg" src="" alt="Photo">
</div>

<!-- BOTTOM ACTION BAR -->
<div class="btm-bar">
    <button class="print-btn" onclick="window.print()">
        <i class="fas fa-print"></i> প্রিন্ট করুন
    </button>
    <button class="share-btn" onclick="shareReport()" title="শেয়ার">
        <i class="fas fa-share-alt"></i>
    </button>
</div>

<!-- Bootstrap 5.3.8 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── THEME ── */
(function(){
    const t=localStorage.getItem('skTheme')||'light';
    document.documentElement.setAttribute('data-bs-theme',t);
    if(t==='dark'){const l=document.getElementById('themeTxt'),i=document.getElementById('themeIco');if(l)l.textContent='লাইট মোড';if(i)i.className='fas fa-sun';}
})();
function toggleTheme(){
    const h=document.documentElement,d=h.getAttribute('data-bs-theme')==='dark';
    h.setAttribute('data-bs-theme',d?'light':'dark');localStorage.setItem('skTheme',d?'light':'dark');
    const l=document.getElementById('themeTxt'),i=document.getElementById('themeIco');
    if(l)l.textContent=d?'ডার্ক মোড':'লাইট মোড';if(i)i.className=d?'fas fa-moon':'fas fa-sun';
}

/* ── DRAWER ── */
function toggleDrawer(){document.getElementById('mainDrawer').classList.toggle('on');document.getElementById('drOverlay').classList.toggle('on');}

/* ── PRINT TIME ── */
(function(){
    const el=document.getElementById('printTime'); if(!el)return;
    const d=new Date(),h=d.getHours(),m=d.getMinutes(),ap=h>=12?'PM':'AM',H=h%12||12,f=n=>String(n).padStart(2,'0');
    el.textContent=`${f(H)}:${f(m)} ${ap}`;
})();

/* ── LIGHTBOX ── */
function openLightbox(src){document.getElementById('lbImg').src=src;document.getElementById('lightbox').classList.add('on');}
function closeLightbox(){document.getElementById('lightbox').classList.remove('on');}

/* ── SHARE ── */
function shareReport(){
    if(navigator.share){navigator.share({title:'সাদাকালো ফ্যাশন রিপোর্ট',url:window.location.href}).catch(()=>{});}
    else{navigator.clipboard.writeText(window.location.href).then(()=>alert('লিংক কপি হয়েছে!'));}
}

/* ── Block future dates in date input ── */
(function(){
    const di=document.querySelector('.date-inp');
    if(!di)return;
    di.addEventListener('change',function(){
        const today=new Date().toISOString().split('T')[0];
        if(this.value>today){this.value=today;alert('ভবিষ্যতের তারিখ সিলেক্ট করা যাবে না!');}
    });
})();
</script>
</body>
</html>
