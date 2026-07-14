<?php
declare(strict_types=1);

session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$timeout_duration = 1200;
$last_activity = $_SESSION['LAST_ACTIVITY'] ?? null;
if ($last_activity !== null && is_int($last_activity) && (time() - $last_activity) > $timeout_duration) {
    session_unset(); session_destroy();
    header("Location: ../index.php"); exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { header("Location: ../index.php"); exit; }



if (!isset($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';

$db_path = __DIR__ . '/../db_connect.php';
if (file_exists($db_path)) { require_once $db_path; }

/** @var PDO $conn */
$rawRole = isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : 'user';
$userRole = strtolower(trim($rawRole));
$isAdmin = ($userRole === 'admin');
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

$ajax_action = isset($_POST['ajax_action']) && is_string($_POST['ajax_action']) ? $_POST['ajax_action'] : '';

if ($ajax_action !== '') {
    if ($ajax_action === 'update_full_product') {
        ob_clean(); header('Content-Type: application/json');
        $post_csrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if ($post_csrf === '' || !hash_equals($csrfToken, $post_csrf)) { echo json_encode(['status'=>'error','message'=>'Security token mismatch!']); exit; }
        if (!$isAdmin) { echo json_encode(['status'=>'error','message'=>'অ্যাক্সেস ডিনাইড!']); exit; }
        $pCode = isset($_POST['product_code']) && is_string($_POST['product_code']) ? trim($_POST['product_code']) : '';
        $newName = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
        $newBuy = isset($_POST['buy_price']) && is_numeric($_POST['buy_price']) ? (float)$_POST['buy_price'] : -1.0;
        $newCost = isset($_POST['cost']) && is_numeric($_POST['cost']) ? (float)$_POST['cost'] : -1.0;
        $newCashSell = isset($_POST['cash_sell']) && is_numeric($_POST['cash_sell']) ? (float)$_POST['cash_sell'] : -1.0;
        if($pCode === '' || $newName === '' || $newBuy < 0.0 || $newCost < 0.0 || $newCashSell < 0.0) { echo json_encode(['status'=>'error','message'=>'সঠিক তথ্য দিন!']); exit; }
        try {
            $conn->beginTransaction();
            $stmtOld = $conn->prepare("SELECT name, buy_price, cost, cash_sell FROM inventory WHERE product_code = ? FOR UPDATE");
            $stmtOld->execute([$pCode]);
            $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);
            if (!$oldData) throw new Exception("পণ্যটি পাওয়া যায়নি!");
            $changes = [];
            if ($oldData['name'] !== $newName) $changes[] = "নাম: [{$oldData['name']}] ➔ [{$newName}]";
            if ((float)$oldData['buy_price'] !== $newBuy) $changes[] = "ক্রয়: ৳" . (float)$oldData['buy_price'] . " ➔ ৳" . $newBuy;
            if ((float)$oldData['cost'] !== $newCost) $changes[] = "খরচ: ৳" . (float)$oldData['cost'] . " ➔ ৳" . $newCost;
            if ((float)$oldData['cash_sell'] !== $newCashSell) $changes[] = "বিক্রি: ৳" . (float)$oldData['cash_sell'] . " ➔ ৳" . $newCashSell;
            if (!empty($changes)) {
                $insertLog = $conn->prepare("INSERT INTO product_edit_history (product_code, changes_details, changed_by) VALUES (?, ?, ?)");
                $insertLog->execute([$pCode, implode(' | ', $changes), $userId]);
            }
            $stmtUpdate = $conn->prepare("UPDATE inventory SET name = ?, buy_price = ?, cost = ?, cash_sell = ? WHERE product_code = ?");
            $stmtUpdate->execute([$newName, $newBuy, $newCost, $newCashSell, $pCode]);
            $conn->commit();
            echo json_encode(['status'=>'success','message'=>'পণ্যটি সফলভাবে আপডেট হয়েছে!']); exit;
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $logDir = __DIR__ . '/../Logs'; @mkdir($logDir, 0755, true);
            @file_put_contents($logDir . '/error_log.txt', "[" . date('Y-m-d H:i:s') . "] Edit Error: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
            echo json_encode(['status'=>'error','message'=>'ডাটাবেস আপডেটে সমস্যা।']); exit;
        }
    }

    if ($ajax_action === 'load_categories') {
        ob_clean(); header('Content-Type: application/json');
        $catStmt = $conn->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name ASC");
        $categories = $catStmt instanceof PDOStatement ? $catStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $catOptions = '<option value="all">সব ক্যাটাগরি</option>';
        foreach($categories as $cat) {
            $cId = isset($cat['id']) ? (int)$cat['id'] : 0;
            $cName = isset($cat['name']) ? htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') : '';
            $catOptions .= '<option value="'.$cId.'">'.$cName.'</option>';
        }
        $catOptions .= '<option value="none">ক্যাটাগরি নেই</option>';
        echo json_encode(['html'=>$catOptions]); exit;
    }

    if ($ajax_action === 'load_items_dt') {
        ob_clean(); header('Content-Type: application/json');
        $draw = isset($_POST['draw']) ? (int)$_POST['draw'] : 1;
        $start = isset($_POST['start']) ? (int)$_POST['start'] : 0;
        $length = isset($_POST['length']) ? (int)$_POST['length'] : 10;
        $searchValue = isset($_POST['search']['value']) ? trim($_POST['search']['value']) : '';
        $catFilter = isset($_POST['category_filter']) ? $_POST['category_filter'] : 'all';
        $stockFilter = isset($_POST['stock_filter']) ? $_POST['stock_filter'] : 'all';
        $whereParts = ["i.pieces > 0"]; $params = [];
        if ($searchValue !== '') { $whereParts[] = "(i.product_code LIKE ? OR i.name LIKE ? OR c.name LIKE ?)"; $w = "%$searchValue%"; array_push($params, $w, $w, $w); }
        if ($catFilter !== 'all' && $catFilter !== '') {
            if ($catFilter === 'none') $whereParts[] = "(i.category_id IS NULL OR i.category_id = 0)";
            else { $whereParts[] = "i.category_id = ?"; $params[] = (int)$catFilter; }
        }
        if ($stockFilter === 'low') $whereParts[] = "i.pieces < 10";
        elseif ($stockFilter === 'high') $whereParts[] = "i.pieces >= 10";
        $whereSql = implode(" AND ", $whereParts);
        try {
            $totalRecords = $conn->query("SELECT COUNT(id) FROM inventory WHERE pieces > 0")->fetchColumn();
            $stmtCount = $conn->prepare("SELECT COUNT(i.id) FROM inventory i LEFT JOIN categories c ON i.category_id = c.id WHERE $whereSql");
            $stmtCount->execute($params);
            $filteredRecords = $stmtCount->fetchColumn();
            $stmtSum = $conn->prepare("SELECT SUM(i.pieces) FROM inventory i LEFT JOIN categories c ON i.category_id = c.id WHERE $whereSql");
            $stmtSum->execute($params);
            $totalPiecesFiltered = $stmtSum->fetchColumn() ?: 0;
            $query = "SELECT i.*, c.name as cat_name, u.username as entry_by FROM inventory i LEFT JOIN categories c ON i.category_id = c.id LEFT JOIN users u ON i.added_by = u.id WHERE $whereSql ORDER BY i.id DESC LIMIT $start, $length";
            $stmtData = $conn->prepare($query); $stmtData->execute($params);
            $items = $stmtData->fetchAll(PDO::FETCH_ASSOC);
            $dataArray = [];
            foreach ($items as $item) {
                $imgPath = !empty($item['image_path']) ? htmlspecialchars($item['image_path'], ENT_QUOTES, 'UTF-8') : '';
                $entryBy = !empty($item['entry_by']) ? htmlspecialchars($item['entry_by'], ENT_QUOTES, 'UTF-8') : 'Unknown';
                $pCode = htmlspecialchars($item['product_code'] ?? '', ENT_QUOTES, 'UTF-8');
                $pNameStr = $item['name'] ?? '';
                $pName = htmlspecialchars($pNameStr, ENT_QUOTES, 'UTF-8');
                $catName = htmlspecialchars($item['cat_name'] ?? 'No Category', ENT_QUOTES, 'UTF-8');
                $stockPieces = (int)($item['pieces'] ?? 0);
                $stockCls = ($stockPieces < 10) ? 'sk-pill--warn' : 'sk-pill--success';
                $displayDate = !empty($item['created_at']) ? date('d M Y', strtotime($item['created_at'])) : 'তারিখ নেই';
                $buyP = (float)($item['buy_price'] ?? 0);
                $costP = (float)($item['cost'] ?? 0);
                $cashP = (float)($item['cash_sell'] ?? 0);
                $totalBuyingPrice = $buyP + $costP;
                $imgTag = $imgPath !== ''
                    ? "<img src='{$imgPath}' style='width:48px; height:48px; object-fit:cover; border-radius:.5rem; border:1px solid var(--sk-line); cursor:pointer;' onclick=\"openImageModal('{$imgPath}')\">"
                    : "<div style='width:48px; height:48px; border-radius:.5rem; background:var(--sk-surface-3); display:inline-flex; align-items:center; justify-content:center; color:var(--sk-muted);'><i class='fas fa-image'></i></div>";

                $adminExtraHtml = "";
                if ($isAdmin) {
                    $encodedData = htmlspecialchars(json_encode(['code'=>$pCode, 'name'=>$pNameStr, 'buy'=>$buyP, 'cost'=>$costP, 'cash'=>$cashP]), ENT_QUOTES, 'UTF-8');
                    $adminExtraHtml = "<div style='display:flex; align-items:center; gap:.375rem; margin-bottom:.375rem;'><span class='sk-pill sk-pill--danger'>কেনা+খরচ: ৳" . number_format($totalBuyingPrice, 2) . "</span><button onclick='openProductEditModal({$encodedData})' class='sk-btn sk-btn--sm sk-btn--accent'><i class='fas fa-edit'></i></button></div>";
                }

                $col1 = "<div style='text-align:center;'>{$imgTag}<div style='display:flex; flex-direction:column; gap:.25rem; margin-top:.375rem;'><span class='sk-pill sk-pill--info'><i class='fas fa-user'></i> {$entryBy}</span><span class='sk-pill sk-pill--ghost'><i class='far fa-calendar-alt'></i> {$displayDate}</span></div></div>";
                $col2 = "<div><div style='color:var(--sk-primary); font-weight:800; font-size:.95rem;'>{$pCode}</div><div style='color:var(--sk-ink-2); font-weight:600; font-size:.8rem; margin-top:.125rem; line-height:1.3;'>{$pName}</div><span class='sk-pill sk-pill--ghost' style='margin-top:.375rem;'>{$catName}</span></div>";
                $col3 = "<div>{$adminExtraHtml}<div><span class='sk-pill sk-pill--accent'>বিক্রি: ৳" . number_format($cashP, 2) . "</span></div><div style='margin-top:.375rem;'><span class='sk-pill {$stockCls}'>স্টক: {$stockPieces} পিস</span></div></div>";

                $dataArray[] = [$col1, $col2, $col3];
            }
            echo json_encode(["draw"=>$draw, "recordsTotal"=>(int)$totalRecords, "recordsFiltered"=>(int)$filteredRecords, "customTotalPieces"=>(int)$totalPiecesFiltered, "data"=>$dataArray]); exit;
        } catch (Exception $e) { echo json_encode(['error'=>'সার্ভার থেকে ডেটা লোড করতে সমস্যা!']); exit; }
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>পণ্য তালিকা — SADA KALO</title>
    <meta name="theme-color" content="#ffffff">
    <script>(function(){try{var t=localStorage.getItem('sk-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);else if(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="theme.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script defer src="theme-toggle.js"></script>
    <style>
        #itemTable.dataTable { border-collapse:collapse !important; width:100% !important; }
        #itemTable.dataTable thead th { padding:.75rem; font-size:.7rem; background:var(--sk-surface-3); color:var(--sk-ink); text-align:left; text-transform:uppercase; letter-spacing:.05em; border-bottom:2px solid var(--sk-line); font-weight:700; }
        #itemTable.dataTable tbody td { padding:.75rem; font-size:.85rem; border-bottom:1px solid var(--sk-line-2); background:var(--sk-surface); vertical-align:top; }
        #itemTable.dataTable tbody tr:hover td { background:var(--sk-surface-2); }
        .dataTables_wrapper .dataTables_paginate { padding-top:1rem; }
        .dataTables_wrapper .dataTables_paginate .paginate_button { padding:.375rem .75rem !important; margin-left:.25rem; border-radius:.375rem !important; font-size:.75rem; font-weight:600; background:var(--sk-surface) !important; border:1px solid var(--sk-line) !important; color:var(--sk-primary) !important; }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current, .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover { background:var(--sk-primary) !important; color:#fff !important; border:1px solid var(--sk-primary) !important; }
        .dataTables_info { font-size:.75rem; font-weight:600; color:var(--sk-muted); padding-top:.75rem; }
        .filters-grid { display:grid; grid-template-columns:1fr; gap:.625rem; }
        @media(min-width:640px){ .filters-grid { grid-template-columns:repeat(3,1fr); } }
    </style>
</head>
<body>

<header class="sk-appbar">
    <div class="sk-appbar__left">
        <button type="button" class="sk-iconbtn" onclick="skOpenDrawer()" aria-label="Menu"><i class="fas fa-bars"></i></button>
        <a href="inventory_dashboard.php" class="sk-iconbtn" title="Back"><i class="fas fa-arrow-left"></i></a>
    </div>
    <div class="sk-appbar__title"><span class="dot"></span> পণ্য তালিকা</div>
    <div class="sk-appbar__right">
        <?php if($isAdmin): ?>
        <a href="product_edit_history.php" class="sk-iconbtn" title="এডিট হিস্ট্রি"><i class="fas fa-history"></i></a>
        <?php endif; ?>
        <a href="../logout.php" class="sk-iconbtn sk-iconbtn--danger" title="লগআউট"><i class="fas fa-power-off"></i></a>
    </div>
</header>

<div class="sk-overlay" id="skOverlay" onclick="skCloseDrawer()"></div>
<aside class="sk-drawer" id="skDrawer">
    <div class="sk-drawer__head">
        <button type="button" class="sk-drawer__close" onclick="skCloseDrawer()"><i class="fas fa-times"></i></button>
        <img src="logo.png" alt="SADA KALO" class="sk-drawer__logo" onerror="this.style.display='none'">
        <div class="sk-drawer__brand">SADA KALO</div>
        <div class="sk-drawer__sub">ITEM LIST</div>
    </div>
    <div class="sk-drawer__section">Menu</div>
    <nav class="sk-drawer__grid">
        <a href="../dashboard.php" class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-home"></i></span><span class="sk-drawer__label">হোম</span></a>
        <a href="inventory_dashboard.php" class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-th-large"></i></span><span class="sk-drawer__label">ড্যাশবোর্ড</span></a>
        <a href="inventory.php" class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-plus"></i></span><span class="sk-drawer__label">Add Item</span></a>
        <a href="Invantory_Items.php" class="sk-drawer__item active"><span class="sk-drawer__icon"><i class="fas fa-box-open"></i></span><span class="sk-drawer__label">Item List</span></a>
        <a href="inventory_pos.php" class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-shopping-cart"></i></span><span class="sk-drawer__label">POS</span></a>
        <a href="inventory_sales_history.php" class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-receipt"></i></span><span class="sk-drawer__label">History</span></a>
        <a href="return_product.php" class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-undo-alt"></i></span><span class="sk-drawer__label">Return</span></a>
        <a href="out_of_stock.php" class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-exclamation-triangle"></i></span><span class="sk-drawer__label">Out Stock</span></a>
        <?php if($isAdmin): ?>
        <a href="admin_inventory_control.php" class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-cogs"></i></span><span class="sk-drawer__label">Inv Ctrl</span></a>
        <a href="admin_category_control.php" class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-tags"></i></span><span class="sk-drawer__label">Category</span></a>
        <a href="daily_activity.php" class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-clipboard-check"></i></span><span class="sk-drawer__label">Daily Act.</span></a>
        <a href="product_edit_history.php" class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-pen-to-square"></i></span><span class="sk-drawer__label">Edit Log</span></a>
        <?php endif; ?>
    </nav>
</aside>

<main class="sk-container">

    <div class="sk-section-title">
        <h2><i class="fas fa-box-open"></i> পণ্য তালিকা</h2>
        <span class="sk-sub">Inventory Items</span>
    </div>

    <div class="sk-card" style="margin-bottom:.75rem;">
        <div class="filters-grid">
            <div class="sk-input-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="customSearch" placeholder="কোড বা নাম..." class="sk-input sk-input--icon">
            </div>
            <div class="sk-input-wrap">
                <i class="fas fa-tags"></i>
                <select id="categoryFilter" class="sk-select sk-input--icon">
                    <option value="all">লোড হচ্ছে...</option>
                </select>
            </div>
            <div class="sk-input-wrap">
                <i class="fas fa-layer-group"></i>
                <select id="stockFilter" class="sk-select sk-input--icon">
                    <option value="all">সব স্টক</option>
                    <option value="low">লো-স্টক (&lt;১০)</option>
                    <option value="high">পর্যাপ্ত (১০+)</option>
                </select>
            </div>
        </div>
    </div>

    <div class="sk-stats sk-stats--2" style="margin-bottom:.75rem;">
        <div class="sk-stat sk-stat--info">
            <div class="sk-row sk-row--between">
                <span class="sk-stat__icon"><i class="fas fa-boxes"></i></span>
                <span class="sk-pill sk-pill--info">ITEMS</span>
            </div>
            <div class="sk-stat__lbl">দৃশ্যমান আইটেম</div>
            <div class="sk-stat__val"><span id="visibleCount">0</span> <small>টি</small></div>
        </div>
        <div class="sk-stat sk-stat--accent">
            <div class="sk-row sk-row--between">
                <span class="sk-stat__icon" style="background:var(--sk-primary);"><i class="fas fa-sort-amount-up-alt"></i></span>
                <span class="sk-pill sk-pill--accent">PIECES</span>
            </div>
            <div class="sk-stat__lbl">মোট পিস</div>
            <div class="sk-stat__val"><span id="totalPiecesDisplay">0</span> <small>পিস</small></div>
        </div>
    </div>

    <div class="sk-table-wrap" style="padding:.375rem;">
        <table id="itemTable" class="sk-table display w-full">
            <thead>
                <tr>
                    <th width="25%">ছবি ও এন্ট্রি</th>
                    <th width="45%">পণ্যের বিবরণ</th>
                    <th width="30%">মূল্য ও স্টক</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</main>

<?php if ($isAdmin): ?>
<div id="itemFullEditModal" class="sk-modal">
    <div class="sk-modal__sheet">
        <div class="sk-modal__head">
            <div class="sk-modal__title"><i class="fas fa-edit"></i> প্রোডাক্ট ফুল আপডেট</div>
            <button type="button" class="sk-modal__close" onclick="closeProductEditModal()">&times;</button>
        </div>
        <form id="fullEditForm" onsubmit="submitProductEdit(event)">
            <input type="hidden" id="edit_product_code">
            <div class="sk-field">
                <label class="sk-label">পণ্যের নাম</label>
                <input type="text" id="edit_product_name" class="sk-input" required>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:.625rem;">
                <div class="sk-field"><label class="sk-label">ক্রয় দাম (৳)</label><input type="number" step="0.01" id="edit_buy_price" class="sk-input" required></div>
                <div class="sk-field"><label class="sk-label">অন্যান্য খরচ (৳)</label><input type="number" step="0.01" id="edit_cost" class="sk-input" required></div>
            </div>
            <div class="sk-field">
                <label class="sk-label">ক্যাশ বিক্রয় মূল্য (৳)</label>
                <input type="number" step="0.01" id="edit_cash_sell" class="sk-input" style="text-align:center; font-size:1.1rem; font-weight:800; color:var(--sk-success); background:var(--sk-success-soft); border-color:var(--sk-success);" required>
            </div>
            <button type="submit" class="sk-btn sk-btn--accent sk-btn--block sk-btn--lg"><i class="fas fa-save"></i> আপডেট করুন</button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function skOpenDrawer(){ document.getElementById('skDrawer').classList.add('open'); document.getElementById('skOverlay').classList.add('active'); }
function skCloseDrawer(){ document.getElementById('skDrawer').classList.remove('open'); document.getElementById('skOverlay').classList.remove('active'); }

const userCsrfToken = "<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>";
function openImageModal(imgSrc) { window.open(imgSrc, '_blank'); }
function openProductEditModal(dataObj) {
    $('#edit_product_code').val(dataObj.code);
    $('#edit_product_name').val(dataObj.name);
    $('#edit_buy_price').val(dataObj.buy);
    $('#edit_cost').val(dataObj.cost);
    $('#edit_cash_sell').val(dataObj.cash);
    $('#itemFullEditModal').addClass('open');
}
function closeProductEditModal() { $('#itemFullEditModal').removeClass('open'); }

$(document).ready(function() {
    $.post('Invantory_Items.php', { ajax_action: 'load_categories' }, function(res) { if(res.html) $('#categoryFilter').html(res.html); });

    var table = $('#itemTable').DataTable({
        "processing": true, "serverSide": true, "ordering": false, "pageLength": 10, "dom": '<"top">rt<"bottom"p><"clear">',
        "ajax": {
            "url": "Invantory_Items.php", "type": "POST",
            "data": function(d) { d.ajax_action='load_items_dt'; d.csrf_token=userCsrfToken; d.category_filter=$('#categoryFilter').val(); d.stock_filter=$('#stockFilter').val(); d.search.value=$('#customSearch').val(); },
            "dataSrc": function(json) { $('#visibleCount').text(json.recordsFiltered); $('#totalPiecesDisplay').text(json.customTotalPieces || 0); return json.data; }
        },
        "language": { "processing": '<i class="fas fa-spinner fa-spin" style="color:var(--sk-primary); font-size:1.5rem;"></i>', "paginate": { "previous": "আগে", "next": "পরে" }, "emptyTable": "কোনো পণ্য পাওয়া যায়নি", "zeroRecords": "মিল আছে এমন কোনো পণ্য নেই" }
    });
    $('#customSearch').on('keyup', function() { table.draw(); });
    $('#categoryFilter, #stockFilter').on('change', function() { table.draw(); });
});

function submitProductEdit(e) {
    e.preventDefault();
    let btn = $('#fullEditForm button[type="submit"]'); let origText = btn.html();
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> আপডেট হচ্ছে...');
    $.ajax({
        url: 'Invantory_Items.php', type: 'POST', dataType: 'json',
        data: { ajax_action:'update_full_product', csrf_token: userCsrfToken, product_code: $('#edit_product_code').val(), name: $('#edit_product_name').val(), buy_price: $('#edit_buy_price').val(), cost: $('#edit_cost').val(), cash_sell: $('#edit_cash_sell').val() },
        success: function(res) { alert(res.message); if(res.status === 'success') { closeProductEditModal(); $('#itemTable').DataTable().ajax.reload(null, false); } },
        error: function() { alert('সার্ভার এরর!'); },
        complete: function() { btn.prop('disabled', false).html(origText); }
    });
}
</script>
        <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.9.10/dist/dotlottie-wc.js" type="module"></script>
        <dotlottie-wc src="https://lottie.host/1b30fa08-92da-4e38-81b5-e6236b92f63a/cGNSbBLSFu.lottie" style="width:200px;height:200px" autoplay loop></dotlottie-wc>
        
</body>
</html>
