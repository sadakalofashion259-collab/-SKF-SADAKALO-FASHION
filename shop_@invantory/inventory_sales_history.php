<?php
declare(strict_types=1);

session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

function logSystemError(Throwable $e): void {
    $logDir = __DIR__ . '/../Logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
    $timestamp = date('Y-m-d H:i:s');
    $msg = "[{$timestamp}] Error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . PHP_EOL;
    @file_put_contents($logDir . '/error_log.txt', $msg, FILE_APPEND);
}

$isAjax = isset($_POST['ajax_action']);

$lastActivity = $_SESSION['last_activity'] ?? null;
if ($lastActivity !== null && is_int($lastActivity) && (time() - $lastActivity > 1200)) {
    session_unset(); session_destroy();
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status'=>'session_expired']); exit; }
    echo "<script>window.location.href='../index.php';</script>"; exit;
}
$_SESSION['last_activity'] = time();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status'=>'session_expired']); exit; }
    echo "<script>window.location.href='../index.php';</script>"; exit;
}

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrfToken = is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';

$db_path = '../db_connect.php';
if (file_exists($db_path)) include $db_path;

/** @var PDO $conn */
$role = isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : 'user';
$uid = isset($_SESSION['user_id']) && is_scalar($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
date_default_timezone_set('Asia/Dhaka');

// AJAX: Update Sale Item Rate (Admin Only)
if ($isAjax && $_POST['ajax_action'] === 'update_sale_item_rate') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $postCsrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if ($postCsrf === '' || !hash_equals($csrfToken, $postCsrf)) { echo json_encode(['status'=>'error','message'=>'Security token mismatch!']); exit; }
    if ($role !== 'admin') { echo json_encode(['status'=>'error','message'=>'Unauthorized access! Only Admins can edit rates.']); exit; }

    $saleId = isset($_POST['sale_id']) ? (int)$_POST['sale_id'] : 0;
    $productCode = isset($_POST['product_code']) ? trim($_POST['product_code']) : '';
    $newBuyPrice = isset($_POST['buy_price']) ? (float)$_POST['buy_price'] : 0.0;
    $newCost = isset($_POST['cost']) ? (float)$_POST['cost'] : 0.0;
    $newSellPrice = isset($_POST['sell_price']) ? (float)$_POST['sell_price'] : 0.0;

    if ($saleId <= 0 || empty($productCode) || $newSellPrice < 0 || $newBuyPrice < 0 || $newCost < 0) {
        echo json_encode(['status'=>'error','message'=>'Invalid or missing data!']); exit;
    }
    $newUnitProfit = $newSellPrice - ($newBuyPrice + $newCost);

    try {
        $conn->beginTransaction();
        $stmt = $conn->prepare("UPDATE inventory_sale_items SET buy_price = ?, cost = ?, sell_price = ?, profit = ? WHERE sale_id = ? AND product_code = ?");
        $stmt->execute([$newBuyPrice, $newCost, $newSellPrice, $newUnitProfit, $saleId, $productCode]);
        $conn->commit();
        echo json_encode(['status'=>'success','message'=>'রেট এবং প্রফিট সফলভাবে আপডেট হয়েছে!']);
    } catch (Throwable $e) {
        $conn->rollBack(); logSystemError($e);
        echo json_encode(['status'=>'error','message'=>'সিস্টেম এরর!']);
    }
    exit;
}

// AJAX: Load Sales History
if ($isAjax && $_POST['ajax_action'] === 'load_sales_history') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $postCsrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if ($postCsrf === '' || !hash_equals($csrfToken, $postCsrf)) {
        echo json_encode(['error'=>'Security token mismatch!']); exit;
    }

    $mode = isset($_POST['mode']) && is_string($_POST['mode']) ? $_POST['mode'] : 'recent';
    $page = isset($_POST['page']) && is_numeric($_POST['page']) ? (int)$_POST['page'] : 1;

    $whereClause = "1";
    $params = [];
    $limitDays = 7;
    $offsetDays = 0;

    if ($role !== 'admin') {
        $whereClause = "s.created_at >= CURDATE() AND s.created_at < CURDATE() + INTERVAL 1 DAY";
        $limitDays = 1; $offsetDays = 0;
    } else {
        if ($mode === 'custom') {
            $rawStart = isset($_POST['start_date']) && is_string($_POST['start_date']) ? $_POST['start_date'] : '';
            $rawEnd = isset($_POST['end_date']) && is_string($_POST['end_date']) ? $_POST['end_date'] : '';
            $startDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawStart)) ? $rawStart : date('Y-m-d');
            $endDate   = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawEnd))   ? $rawEnd   : date('Y-m-d');
            $whereClause = "s.created_at >= ? AND s.created_at <= ?";
            $params[] = $startDate . ' 00:00:00';
            $params[] = $endDate . ' 23:59:59';
            $limitDays = 10000;
            $offsetDays = 0;
        } else {
            $whereClause = "1";
            $limitDays = 7;
            $offsetDays = ($page - 1) * $limitDays;
        }
    }

    $datesList = [];
    $totalPages = 1;
    try {
        $countQuery = "SELECT COUNT(DISTINCT DATE(s.created_at)) FROM inventory_sales s WHERE $whereClause";
        $stmtCount = $conn->prepare($countQuery);
        $stmtCount->execute($params);
        $totalDates = (int)$stmtCount->fetchColumn();
        $totalPages = (int)ceil($totalDates / ($limitDays > 0 ? $limitDays : 1));

        $dateQuery = "SELECT DISTINCT DATE(s.created_at) as sale_date FROM inventory_sales s WHERE $whereClause ORDER BY sale_date DESC LIMIT $offsetDays, $limitDays";
        $stmtDates = $conn->prepare($dateQuery);
        $stmtDates->execute($params);
        $datesList = $stmtDates->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { logSystemError($e); }

    $allSales = [];
    if (is_array($datesList) && count($datesList) > 0) {
        try {
            $inQuery = implode(',', array_fill(0, count($datesList), '?'));
            $itemParams = array_merge($params, $datesList);

            $query = "
                SELECT
                    s.id as sale_id,
                    s.invoice_no,
                    s.created_at,
                    i.product_code,
                    i.category_name,
                    i.buy_price,
                    i.cost,
                    i.sell_price,
                    i.profit as unit_profit,
                    i.pieces,
                    u.username as entry_by,
                    inv.image_path,
                    inv.name as product_name,
                    COALESCE((SELECT SUM(return_pieces) FROM inventory_returns r WHERE r.invoice_no = s.invoice_no AND r.product_code = i.product_code AND r.status = 'approved'), 0) as returned_qty
                FROM inventory_sales s
                JOIN inventory_sale_items i ON s.id = i.sale_id
                LEFT JOIN inventory inv ON i.product_code = inv.product_code
                LEFT JOIN users u ON s.sold_by = u.id
                WHERE $whereClause AND DATE(s.created_at) IN ($inQuery)
                ORDER BY s.created_at DESC
            ";
            $stmt = $conn->prepare($query);
            $stmt->execute($itemParams);
            $allSales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { logSystemError($e); }
    }

    // Group by date AND by invoice
    $groupedByDate = [];
    $groupedByInvoice = []; // invoice_no => ['date'=>..., 'created_at'=>..., 'entry_by'=>..., 'items'=>[...]]
    $groupTotals = [];

    $grandTotalPieces = 0; $grandTotalAmount = 0.0; $grandTotalProfit = 0.0;
    $grandTotalBuyPrice = 0.0; $grandTotalCost = 0.0;

    foreach ($allSales as $row) {
        if (!is_array($row)) continue;
        $createdAt = isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : date('Y-m-d H:i:s');
        $dateKey = date('Y-m-d', strtotime($createdAt));
        $invKey = (string)($row['invoice_no'] ?? '');

        if (!isset($groupedByInvoice[$invKey])) {
            $groupedByInvoice[$invKey] = [
                'date' => $dateKey,
                'created_at' => $createdAt,
                'entry_by' => $row['entry_by'] ?? 'Unknown',
                'items' => []
            ];
        }
        $groupedByInvoice[$invKey]['items'][] = $row;
        $groupedByDate[$dateKey][$invKey] = true;

        $rawPieces = (int)($row['pieces'] ?? 0);
        $rawReturn = (int)($row['returned_qty'] ?? 0);
        $netPieces = $rawPieces - $rawReturn;

        if (!isset($groupTotals[$dateKey])) {
            $groupTotals[$dateKey] = ['pieces'=>0,'amount'=>0.0,'profit'=>0.0,'buy'=>0.0,'cost'=>0.0,'invoices'=>0];
        }
        if ($netPieces > 0) {
            $sellPrice = (float)($row['sell_price'] ?? 0);
            $unitProfit = (float)($row['unit_profit'] ?? 0);
            $buyPriceRow = (float)($row['buy_price'] ?? 0);
            $costRow = (float)($row['cost'] ?? 0);

            $amt = $sellPrice * $netPieces;
            $prf = $unitProfit * $netPieces;
            $buyAmtTotal = $buyPriceRow * $netPieces;
            $costAmtTotal = $costRow * $netPieces;

            $groupTotals[$dateKey]['pieces'] += $netPieces;
            $groupTotals[$dateKey]['amount'] += $amt;
            $groupTotals[$dateKey]['profit'] += $prf;
            $groupTotals[$dateKey]['buy']    += $buyAmtTotal;
            $groupTotals[$dateKey]['cost']   += $costAmtTotal;

            $grandTotalPieces += $netPieces;
            $grandTotalAmount += $amt;
            $grandTotalProfit += $prf;
            $grandTotalBuyPrice += $buyAmtTotal;
            $grandTotalCost += $costAmtTotal;
        }
    }
    foreach ($groupedByDate as $dk => $invMap) {
        $groupTotals[$dk]['invoices'] = count($invMap);
    }

    // ────── Build HTML — Memo / Receipt style ──────
    $html = '';
    if (count($groupedByInvoice) > 0) {

        // SubTotal (page grand total) — top card
        $html .= '<div class="sk-memo-sub">';
        $html .= '  <div class="sk-memo-sub__title"><i class="fas fa-calculator"></i> এই পেজের মোট হিসাব · Sub Total</div>';
        $html .= '  <div class="sk-memo-sub__row"><span class="label">মোট নিট পিস</span><span class="value">' . number_format($grandTotalPieces) . ' পিস</span></div>';
        $html .= '  <div class="sk-memo-sub__row"><span class="label">মোট নিট সেলস</span><span class="value">৳ ' . number_format($grandTotalAmount) . '</span></div>';
        if ($role === 'admin') {
            $html .= '  <div class="sk-memo-sub__row" style="opacity:.75;"><span class="label">কেনা + কস্ট</span><span class="value">৳ ' . number_format($grandTotalBuyPrice) . ' + ' . number_format($grandTotalCost) . '</span></div>';
            $html .= '  <div class="sk-memo-sub__row bold" onclick="toggleAllProfits()" style="cursor:pointer; margin-top:8px; padding-top:8px; border-top:1px dashed rgba(255,255,255,.2);"><span class="label"><i class="fas fa-eye-slash" id="globalEyeIcon"></i> মোট নিট প্রফিট</span><span class="value"><span class="profit-mask">***</span><span class="profit-amt hidden">৳ ' . number_format($grandTotalProfit) . '</span></span></div>';
        }
        $html .= '</div>';

        // Render each invoice as a MEMO (grouped under a date header)
        $currentDate = '';
        foreach ($groupedByInvoice as $invNo => $invGroup) {
            $thisDate = $invGroup['date'];
            if ($thisDate !== $currentDate) {
                $currentDate = $thisDate;
                $displayDate = date('d M Y · l', strtotime($thisDate));
                $tot = $groupTotals[$thisDate];
                $html .= '<div class="sk-dayhead"><span>' . htmlspecialchars($displayDate, ENT_QUOTES, 'UTF-8') . '</span></div>';
                $html .= '<div class="sk-memo-sub" style="background:linear-gradient(135deg, var(--sk-brand-soft) 0%, var(--sk-surface) 70%); color:var(--sk-ink); border:1px solid color-mix(in oklab, var(--sk-brand) 22%, var(--sk-line));">';
                $html .= '  <div class="sk-memo-sub__title" style="color:var(--sk-brand-2);"><i class="fas fa-calendar-day"></i> ' . htmlspecialchars($displayDate, ENT_QUOTES, 'UTF-8') . '</div>';
                $html .= '  <div class="sk-memo-sub__row"><span class="label" style="color:var(--sk-muted);">মেমো সংখ্যা</span><span class="value" style="color:var(--sk-ink);">' . (int)$tot['invoices'] . ' টি</span></div>';
                $html .= '  <div class="sk-memo-sub__row"><span class="label" style="color:var(--sk-muted);">নিট পিস</span><span class="value" style="color:var(--sk-ink);">' . number_format($tot['pieces']) . ' পিস</span></div>';
                $html .= '  <div class="sk-memo-sub__row bold"><span class="label" style="color:var(--sk-ink);">দিনের বিক্রি</span><span class="value">৳ ' . number_format($tot['amount']) . '</span></div>';
                if ($role === 'admin') {
                    $html .= '  <div class="sk-memo-sub__row bold" onclick="toggleSingleProfit(this)" style="cursor:pointer; color:var(--sk-success-ink);"><span class="label" style="color:var(--sk-ink);"><i class="fas fa-eye-slash eye-icon"></i> দিনের প্রফিট</span><span class="value" style="color:var(--sk-success);"><span class="profit-mask">***</span><span class="profit-amt hidden">৳ ' . number_format($tot['profit']) . '</span></span></div>';
                }
                $html .= '</div>';
            }

            // ─── MEMO CARD ───
            $invSafe = htmlspecialchars($invNo, ENT_QUOTES, 'UTF-8');
            $entryBy = htmlspecialchars((string)$invGroup['entry_by'], ENT_QUOTES, 'UTF-8');
            $time = date('h:i A', strtotime($invGroup['created_at']));
            $dateOnly = date('d M Y', strtotime($invGroup['created_at']));

            $invTotalPcs = 0; $invTotalAmt = 0.0; $invTotalProfit = 0.0;
            foreach ($invGroup['items'] as $it) {
                $np = (int)$it['pieces'] - (int)$it['returned_qty'];
                if ($np > 0) {
                    $invTotalPcs += $np;
                    $invTotalAmt += (float)$it['sell_price'] * $np;
                    $invTotalProfit += (float)$it['unit_profit'] * $np;
                }
            }

            $html .= '<div class="sk-memo">';
            // Memo header
            $html .= '  <div class="sk-memo__head">';
            $html .= '    <div class="sk-memo__brand">SADA <span>KALO</span></div>';
            $html .= '    <div class="sk-memo__tag">FASHION · MEMO RECEIPT</div>';
            $html .= '    <div class="sk-memo__date">';
            $html .= '      <span>INVOICE <strong>' . $invSafe . '</strong></span>';
            $html .= '      <span>' . $dateOnly . ' · ' . $time . '</span>';
            $html .= '    </div>';
            $html .= '  </div>';

            // Memo body — items
            $html .= '  <div class="sk-memo__body">';
            foreach ($invGroup['items'] as $row) {
                $rawPieces = (int)($row['pieces'] ?? 0);
                $rawReturn = (int)($row['returned_qty'] ?? 0);
                $netPieces = $rawPieces - $rawReturn;
                $sellPrice = (float)($row['sell_price'] ?? 0);
                $unitProfit = (float)($row['unit_profit'] ?? 0);
                $buyPriceR = (float)($row['buy_price'] ?? 0);
                $costR = (float)($row['cost'] ?? 0);
                $rowTotalAmount = $sellPrice * $netPieces;
                $rowTotalProfit = $unitProfit * $netPieces;

                $rawPCode = (string)($row['product_code'] ?? '');
                $pCode = htmlspecialchars($rawPCode, ENT_QUOTES, 'UTF-8');
                $jsPCode = addslashes($rawPCode);
                $pName = htmlspecialchars((string)($row['product_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8');
                $cName = htmlspecialchars((string)($row['category_name'] ?? ''), ENT_QUOTES, 'UTF-8');

                $img = !empty($row['image_path']) ? htmlspecialchars($row['image_path'], ENT_QUOTES, 'UTF-8') : '';
                $saleIdVal = (int)($row['sale_id'] ?? 0);

                $imgEl = $img !== ''
                    ? "<img src='{$img}' alt='{$pCode}' onclick=\"openImageModal('{$img}', '{$jsPCode}')\">"
                    : "<div style='width:38px;height:38px;border-radius:8px;background:var(--sk-surface-3);display:flex;align-items:center;justify-content:center;color:var(--sk-muted);'><i class='fas fa-image'></i></div>";

                $html .= '<div class="sk-memo__row">';
                $html .= '  <div class="sk-memo__rowtop">';
                $html .= '    ' . $imgEl;
                $html .= '    <div class="sk-memo__rowinfo">';
                $html .= '      <div class="sk-memo__code">' . $pCode . '</div>';
                $html .= '      <div class="sk-memo__name">' . $pName . ($cName !== '' ? ' · ' . $cName : '') . '</div>';
                $html .= '    </div>';
                $html .= '  </div>';

                if ($rawReturn > 0) {
                    $html .= '  <div class="sk-memo__line muted"><span class="label">বিক্রি / রিটার্ন</span><span class="value">' . $rawPieces . ' − ' . $rawReturn . ' = নিট ' . $netPieces . '</span></div>';
                } else {
                    $html .= '  <div class="sk-memo__line"><span class="label">পরিমাণ × রেট</span><span class="value">' . $rawPieces . ' × ৳ ' . number_format($sellPrice, 2) . '</span></div>';
                }

                if ($netPieces > 0) {
                    if ($role === 'admin') {
                        $html .= '<div class="sk-memo__line muted"><span class="label">কেনা + কস্ট</span><span class="value">৳ ' . number_format($buyPriceR) . ' + ' . number_format($costR) . '</span></div>';
                        $html .= '<div class="sk-memo__line bold"><span class="label">আইটেম মোট</span><span class="value">৳ ' . number_format($rowTotalAmount, 2) . '</span></div>';

                        $profitToneClass = $rowTotalProfit >= 0 ? 'sk-memo__chip--ok' : 'sk-memo__chip--bad';
                        $profPrefix = $rowTotalProfit > 0 ? '+' : '';
                        $html .= '<div class="sk-memo__item-actions">';
                        $html .= '  <span class="sk-memo__chip ' . $profitToneClass . '" onclick="toggleSingleProfit(this)" style="cursor:pointer;"><i class="fas fa-eye-slash eye-icon"></i> প্রফিট: <span class="profit-mask">***</span><span class="profit-amt hidden">' . $profPrefix . '৳' . number_format($rowTotalProfit) . '</span></span>';
                        $html .= '  <button class="sk-memo__btn sk-memo__btn--brand" onclick="openEditRateModal(' . $saleIdVal . ', \'' . $jsPCode . '\', ' . $buyPriceR . ', ' . $costR . ', ' . $sellPrice . ')"><i class="fas fa-edit"></i> রেট</button>';
                        $html .= '</div>';
                    } else {
                        $html .= '<div class="sk-memo__line bold"><span class="label">আইটেম মোট</span><span class="value">৳ ' . number_format($rowTotalAmount, 2) . '</span></div>';
                    }
                } else {
                    $html .= '<div class="sk-memo__item-actions"><span class="sk-memo__chip sk-memo__chip--bad"><i class="fas fa-undo"></i> সম্পূর্ণ রিটার্ন</span></div>';
                }
                $html .= '</div>';
            }
            $html .= '  </div>';

            // Memo foot — totals + entry-by + barcode
            $html .= '  <div class="sk-memo__foot">';
            $html .= '    <div class="sk-memo__line muted"><span class="label">মোট আইটেম</span><span class="value">' . count($invGroup['items']) . ' টি</span></div>';
            $html .= '    <div class="sk-memo__line muted"><span class="label">নিট পিস</span><span class="value">' . $invTotalPcs . ' পিস</span></div>';
            if ($role === 'admin') {
                $html .= '<div class="sk-memo__line muted" onclick="toggleSingleProfit(this)" style="cursor:pointer;"><span class="label"><i class="fas fa-eye-slash eye-icon"></i> মেমো প্রফিট</span><span class="value"><span class="profit-mask">***</span><span class="profit-amt hidden">৳ ' . number_format($invTotalProfit) . '</span></span></div>';
            }
            $html .= '    <div class="sk-memo__total">';
            $html .= '      <span class="label">গ্র্যান্ড টোটাল</span>';
            $html .= '      <span class="value">৳ ' . number_format($invTotalAmt, 2) . '</span>';
            $html .= '    </div>';
            $html .= '    <div class="sk-memo__barcode">';
            $html .= '      <div class="sk-memo__bars">' . str_repeat('<span></span>', 40) . '</div>';
            $html .= '      <div>' . $invSafe . '</div>';
            $html .= '    </div>';
            $html .= '    <div class="sk-memo__thanks">এন্ট্রি: ' . $entryBy . ' · ধন্যবাদ আবার আসবেন</div>';
            $html .= '  </div>';
            $html .= '</div>';
        }

    } else {
        $html .= '<div class="sk-card" style="text-align:center; max-width:460px; margin:30px auto;">';
        $html .= '  <div class="sk-empty"><i class="fas fa-receipt"></i><p>কোনো সেলস রেকর্ড নেই!</p></div>';
        $html .= '</div>';
    }

    echo json_encode(['html'=>$html, 'totalPages'=>$totalPages, 'currentPage'=>$page]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>সেলস হিস্ট্রি — SADA KALO</title>
    <meta name="theme-color" content="#ffffff">
    <script>(function(){try{var t=localStorage.getItem('sk-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);else if(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Hind+Siliguri:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="theme.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script defer src="theme-toggle.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<header class="sk-appbar top-navbar">
    <div class="sk-appbar__left">
        <button type="button" class="sk-iconbtn menu-btn" onclick="toggleSidebar()" aria-label="Menu"><i class="fas fa-bars"></i></button>
        <a href="inventory_dashboard.php" class="sk-iconbtn" aria-label="Back"><i class="fas fa-arrow-left"></i></a>
    </div>
    <div class="sk-appbar__title"><span class="dot"></span> সেলস মেমো</div>
    <div class="sk-appbar__right top-right-icons">
        <a href="../logout.php" class="sk-iconbtn sk-iconbtn--danger" title="Logout"><i class="fas fa-power-off"></i></a>
    </div>
</header>

<div class="sk-overlay" id="myOverlay" onclick="toggleSidebar()"></div>
<aside class="sk-drawer" id="mySidebar">
    <div class="sk-drawer__head">
        <button type="button" class="sk-drawer__close" onclick="toggleSidebar()"><i class="fas fa-times"></i></button>
        <img src="logo.png" alt="Logo" onerror="this.style.display='none'" class="sk-drawer__logo">
        <div class="sk-drawer__brand">SADA KALO</div>
        <div class="sk-drawer__sub">SALES HISTORY</div>
    </div>
    <div class="sk-drawer__section">Quick Menu</div>
    <nav class="sk-drawer__grid">
        <a href="../dashboard.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-home"></i></div><span class="sk-drawer__label">হোম</span></a>
        <a href="inventory_dashboard.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-th-large"></i></div><span class="sk-drawer__label">ড্যাশবোর্ড</span></a>
        <a href="inventory.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-plus"></i></div><span class="sk-drawer__label">Add Item</span></a>
        <a href="Invantory_Items.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-box-open"></i></div><span class="sk-drawer__label">Item List</span></a>
        <a href="inventory_pos.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-shopping-cart"></i></div><span class="sk-drawer__label">POS</span></a>
        <a href="inventory_sales_history.php" class="sk-drawer__item active"><div class="sk-drawer__icon"><i class="fas fa-receipt"></i></div><span class="sk-drawer__label">History</span></a>
        <a href="return_product.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-undo-alt"></i></div><span class="sk-drawer__label">Return</span></a>
        <a href="out_of_stock.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-exclamation-triangle"></i></div><span class="sk-drawer__label">Out Stock</span></a>
        <?php if($role === 'admin'): ?>
        <a href="admin_inventory_control.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-cogs"></i></div><span class="sk-drawer__label">Admin</span></a>
        <?php endif; ?>
    </nav>
</aside>

<main class="sk-container">

    <div class="sk-section-title">
        <h2><i class="fas fa-receipt"></i> সেলস মেমো হিস্ট্রি</h2>
        <span class="sk-sub"><?php echo $role === 'admin' ? 'Admin View' : 'আজকের সেলস'; ?></span>
    </div>

    <?php if($role === 'admin'): ?>
    <div class="sk-card" style="margin-bottom:14px;">
        <div class="sk-row sk-row--between sk-row--wrap" style="gap:10px;">
            <div class="sk-row" id="paginationContainer" style="gap:6px;">
                <button onclick="changePage(-1)" class="sk-pager__btn" id="prevBtn"><i class="fas fa-chevron-left"></i></button>
                <span class="sk-pill sk-pill--ghost">Page <span id="pageNumDisplay">1</span> / <span id="totalPagesDisplay">1</span></span>
                <button onclick="changePage(1)" class="sk-pager__btn" id="nextBtn"><i class="fas fa-chevron-right"></i></button>
            </div>
            <button type="button" onclick="toggleFilterDiv()" class="sk-btn sk-btn--accent sk-btn--sm" id="btn-filter-toggle"><i class="fas fa-filter"></i> কাস্টম ফিল্টার</button>
        </div>

        <div id="customFilterDiv" class="hidden" style="margin-top:14px; padding-top:14px; border-top:1px dashed var(--sk-line);">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div class="sk-field"><label class="sk-label">শুরুর তারিখ</label><input type="date" id="startDate" class="sk-input" value="<?php echo date('Y-m-d'); ?>"></div>
                <div class="sk-field"><label class="sk-label">শেষের তারিখ</label><input type="date" id="endDate" class="sk-input" value="<?php echo date('Y-m-d'); ?>"></div>
            </div>
            <div class="sk-row" style="gap:8px;">
                <button type="button" onclick="setMode('custom')" class="sk-btn sk-btn--ink sk-grow"><i class="fas fa-search"></i> খুঁজুন</button>
                <button type="button" onclick="setMode('recent')" class="sk-btn sk-btn--ghost sk-grow"><i class="fas fa-undo"></i> ৭ দিনে রিসেট</button>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="sk-card sk-card--accent" style="text-align:center; margin-bottom:14px;">
        <div class="sk-row sk-row--center" style="gap:10px;">
            <i class="fas fa-calendar-day" style="color:var(--sk-brand); font-size:18px;"></i>
            <h2 style="margin:0; font-weight:900; font-size:13px; letter-spacing:1.5px; text-transform:uppercase; color:var(--sk-ink);">আজকের সেলস মেমো</h2>
        </div>
    </div>
    <?php endif; ?>

    <div id="historyContainer" style="min-height:200px;">
        <div class="sk-empty"><i class="fas fa-spinner fa-spin"></i><p>মেমো লোড হচ্ছে...</p></div>
    </div>

</main>

<!-- Image Lightbox -->
<div id="imageLightbox" onclick="closeImageModal()" style="display:none; position:fixed; z-index:100000; inset:0; background:rgba(9,9,11,.95); align-items:center; justify-content:center; flex-direction:column; backdrop-filter:blur(8px);">
    <span class="close-lightbox" onclick="closeImageModal()" style="position:absolute; top:20px; right:30px; color:#fff; font-size:40px; font-weight:bold; cursor:pointer;">&times;</span>
    <img id="lightboxImg" src="" alt="" style="max-width:90%; max-height:80vh; border-radius:18px; border:4px solid #fff; object-fit:contain; box-shadow:0 20px 40px rgba(0,0,0,.5);">
    <div id="lightboxText" class="sk-pill sk-pill--ink" style="margin-top:14px; padding:8px 16px; font-size:12px;"></div>
</div>

<?php if($role === 'admin'): ?>
<div id="editRateModal" class="sk-modal">
    <div class="sk-modal__sheet">
        <div class="sk-modal__head">
            <div class="sk-modal__title"><i class="fas fa-edit"></i> রেট আপডেট</div>
            <button type="button" onclick="closeEditRateModal()" class="sk-modal__close">&times;</button>
        </div>
        <input type="hidden" id="edit_sale_id">
        <input type="hidden" id="edit_product_code">

        <div id="edit_product_display" class="sk-pill sk-pill--info" style="display:block; text-align:center; padding:9px 12px; margin-bottom:14px; font-size:11px;"></div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div class="sk-field"><label class="sk-label">কেনা রেট (Buy)</label><input type="number" id="edit_buy_price" step="0.01" class="sk-input"></div>
            <div class="sk-field"><label class="sk-label">অতিরিক্ত খরচ (Cost)</label><input type="number" id="edit_cost" step="0.01" class="sk-input"></div>
        </div>

        <div class="sk-field">
            <label class="sk-label" style="color:var(--sk-success);">বিক্রি রেট (Sell)</label>
            <input type="number" id="edit_sell_price" step="0.01" class="sk-input" style="border-color:var(--sk-success); background:var(--sk-success-soft); color:var(--sk-success-ink); font-weight:900;">
        </div>

        <div class="sk-row" style="gap:8px; margin-top:6px;">
            <button type="button" onclick="closeEditRateModal()" class="sk-btn sk-btn--ghost sk-grow">বাতিল</button>
            <button type="button" onclick="submitEditRate()" id="btn_save_rate" class="sk-btn sk-btn--accent sk-grow"><i class="fas fa-save"></i> সেভ</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const userCsrfToken = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
let currentMode = 'recent';
let currentPage = 1;
let totalPagesLimit = 1;
let userRole = "<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>";
let profitsVisible = false;

function toggleSidebar() {
    document.getElementById("mySidebar").classList.toggle("open");
    document.getElementById("myOverlay").classList.toggle("active");
}
function openImageModal(imgSrc, textLabel) {
    if(!imgSrc) return;
    document.getElementById('lightboxImg').src = imgSrc;
    document.getElementById('lightboxText').innerText = textLabel || '';
    document.getElementById('imageLightbox').style.display = 'flex';
}
function closeImageModal() { document.getElementById('imageLightbox').style.display = 'none'; }

function toggleAllProfits() {
    profitsVisible = !profitsVisible;
    if(profitsVisible) {
        $('#globalEyeIcon').removeClass('fa-eye-slash').addClass('fa-eye');
        $('.profit-mask').addClass('hidden');
        $('.profit-amt').removeClass('hidden');
        $('.eye-icon').removeClass('fa-eye-slash').addClass('fa-eye');
    } else {
        $('#globalEyeIcon').removeClass('fa-eye').addClass('fa-eye-slash');
        $('.profit-mask').removeClass('hidden');
        $('.profit-amt').addClass('hidden');
        $('.eye-icon').removeClass('fa-eye').addClass('fa-eye-slash');
    }
}

window.toggleSingleProfit = function(el) {
    let $el = $(el);
    let $mask = $el.find('.profit-mask');
    let $amt = $el.find('.profit-amt');
    let $icon = $el.find('.eye-icon');
    if($mask.hasClass('hidden')) {
        $mask.removeClass('hidden');
        $amt.addClass('hidden');
        $icon.removeClass('fa-eye').addClass('fa-eye-slash');
    } else {
        $mask.addClass('hidden');
        $amt.removeClass('hidden');
        $icon.removeClass('fa-eye-slash').addClass('fa-eye');
    }
};

function openEditRateModal(saleId, productCode, buyPrice, cost, sellPrice) {
    $('#edit_sale_id').val(saleId);
    $('#edit_product_code').val(productCode);
    $('#edit_buy_price').val(buyPrice);
    $('#edit_cost').val(cost);
    $('#edit_sell_price').val(sellPrice);
    $('#edit_product_display').html('<i class="fas fa-barcode"></i> পণ্য কোড: <strong>' + productCode + '</strong>');
    $('#editRateModal').addClass('open');
}
function closeEditRateModal() { $('#editRateModal').removeClass('open'); }

function submitEditRate() {
    let saleId = $('#edit_sale_id').val();
    let productCode = $('#edit_product_code').val();
    let buyPrice = $('#edit_buy_price').val();
    let cost = $('#edit_cost').val();
    let sellPrice = $('#edit_sell_price').val();

    if (!saleId || !productCode || sellPrice === '' || buyPrice === '' || cost === '') {
        Swal.fire({icon:'error', title:'ত্রুটি', text:'সবগুলো তথ্য পূরণ করুন!', confirmButtonColor:'#e11d48'});
        return;
    }

    let $btn = $('#btn_save_rate');
    let originalText = $btn.html();
    $btn.html('<i class="fas fa-spinner fa-spin"></i> সেভ হচ্ছে...').prop('disabled', true);

    $.ajax({
        url: 'inventory_sales_history.php', type: 'POST',
        data: { ajax_action:'update_sale_item_rate', csrf_token: userCsrfToken,
                sale_id: saleId, product_code: productCode,
                buy_price: buyPrice, cost: cost, sell_price: sellPrice },
        dataType: 'json',
        success: function(res) {
            $btn.html(originalText).prop('disabled', false);
            if (res.status === 'success') {
                closeEditRateModal();
                loadHistoryData();
                Swal.fire({icon:'success', title:'সফল!', text:res.message, timer:2000, showConfirmButton:false, confirmButtonColor:'#e11d48'});
            } else if (res.status === 'session_expired') {
                window.location.href = '../index.php';
            } else {
                Swal.fire({icon:'error', title:'ত্রুটি', text:res.message, confirmButtonColor:'#e11d48'});
            }
        },
        error: function() {
            $btn.html(originalText).prop('disabled', false);
            Swal.fire({icon:'error', title:'সার্ভার এরর', text:'রেট সেভ করা যায়নি।', confirmButtonColor:'#e11d48'});
        }
    });
}

function toggleFilterDiv() { $('#customFilterDiv').slideToggle(200); }

function setMode(mode) {
    if (mode === 'custom') {
        let sDate = $('#startDate').val();
        let eDate = $('#endDate').val();
        if(new Date(sDate) > new Date(eDate)) {
            Swal.fire({icon:'warning', title:'ভুল তারিখ', text:'শুরুর তারিখ অবশ্যই শেষের তারিখের আগে।', confirmButtonColor:'#e11d48'});
            return;
        }
    }
    currentMode = mode;
    currentPage = 1;
    if(mode === 'custom') {
        $('#paginationContainer').hide();
        $('#customFilterDiv').slideUp(200);
    } else {
        $('#paginationContainer').show();
    }
    loadHistoryData();
}

window.changePage = function(direction) {
    let newPage = currentPage + direction;
    if (newPage >= 1 && newPage <= totalPagesLimit) {
        currentPage = newPage;
        loadHistoryData();
        $('html, body').animate({ scrollTop: $(".top-navbar").offset().top }, 300);
    }
};

function loadHistoryData() {
    $('#historyContainer').html('<div class="sk-empty"><i class="fas fa-spinner fa-spin"></i><p>মেমো লোড হচ্ছে...</p></div>');
    let reqData = { ajax_action:'load_sales_history', csrf_token: userCsrfToken, mode: currentMode, page: currentPage };
    if (currentMode === 'custom') {
        reqData.start_date = $('#startDate').val();
        reqData.end_date = $('#endDate').val();
    }
    $.ajax({
        url: 'inventory_sales_history.php', type:'POST', data:reqData, dataType:'json',
        success: function(res) {
            if(res.status === 'session_expired') { window.location.href = '../index.php'; return; }
            if(res.error) {
                $('#historyContainer').html('<div class="sk-card" style="text-align:center; color:var(--sk-danger); font-weight:800;">' + res.error + '</div>');
                return;
            }
            $('#historyContainer').html(res.html);
            if (profitsVisible) { profitsVisible = false; toggleAllProfits(); }

            if (userRole === 'admin' && currentMode !== 'custom') {
                totalPagesLimit = parseInt(res.totalPages) || 1;
                $('#pageNumDisplay').text(res.currentPage);
                $('#totalPagesDisplay').text(totalPagesLimit);
                $('#prevBtn').prop('disabled', res.currentPage <= 1);
                $('#nextBtn').prop('disabled', res.currentPage >= totalPagesLimit);
                if (totalPagesLimit > 1) $('#paginationContainer').css('display','flex'); else $('#paginationContainer').hide();
            }
        },
        error: function() {
            $('#historyContainer').html('<div class="sk-card" style="text-align:center; color:var(--sk-warn); font-weight:800;">ডাটা লোড করতে সমস্যা হয়েছে!</div>');
        }
    });
}

$(document).ready(function() { loadHistoryData(); });
</script>
</body>
</html>
