<?php
declare(strict_types=1);
session_start();

// এররগুলো যেন সাদা পেজ না দেখিয়ে স্ক্রিনে দেখায় তার ব্যবস্থা (ডিবাগিংয়ের জন্য)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// সঠিক ফোল্ডার পাথ
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../Controllers/AdminController.php';

// অ্যাডমিন ছাড়া অন্য কাউকে ঢুকতে দেওয়া হবে না
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php"); 
    exit;
}

$adminCtrl = new AdminController($conn);
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
$status_msg = "";

// CSRF টোকেন তৈরি করা (সিকিউরিটির জন্য)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==========================================
// ফর্ম সাবমিশন ও লজিক হ্যান্ডলিং
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // CSRF ভেরিফিকেশন
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $status_msg = "❌ সিকিউরিটি ত্রুটি (CSRF)! পেজ রিফ্রেশ করুন।";
    } 
    // ব্রডকাস্ট মেসেজ লজিক
    elseif (isset($_POST['send_broadcast'])) {
        $msg = trim($_POST['message']);
        if (!empty($msg)) {
            if ($adminCtrl->broadcastMessage($uid, $msg)) {
                $status_msg = "✅ নোটিশটি ডাটাবেসে সেভ হয়েছে এবং পুশ নোটিফিকেশন পাঠানো হয়েছে!";
            } else {
                $status_msg = "❌ সিস্টেম এরর! মেসেজ পাঠানো যায়নি।";
            }
        }
    } 
    // নতুন ইউজার তৈরি বা আপডেট লজিক
    elseif (isset($_POST['save_user'])) {
        $user    = htmlspecialchars(trim($_POST['username']));
        $pass    = trim($_POST['password']);
        $role    = htmlspecialchars($_POST['role']);
        $email   = !empty($_POST['email']) ? filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL) : null;
        $phone   = htmlspecialchars(trim($_POST['phone']));
        $address = htmlspecialchars(trim($_POST['address']));
        $joining = date('Y-m-d');

        if(!empty($user)){
            try {
                $conn->beginTransaction();
                $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $check->execute([$user]);
                
                if($check->rowCount() > 0){
                    // ইউজার আপডেট
                    if(!empty($pass)) {
                        $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("UPDATE users SET password=?, role=?, email=?, phone=?, address=? WHERE username=?");
                        $stmt->execute([$hashed_pass, $role, $email, $phone, $address, $user]);
                    } else {
                        $stmt = $conn->prepare("UPDATE users SET role=?, email=?, phone=?, address=? WHERE username=?");
                        $stmt->execute([$role, $email, $phone, $address, $user]);
                    }
                    $status_msg = "✅ ইউজার '$user' এর তথ্য সফলভাবে আপডেট হয়েছে!";
                } else {
                    // নতুন ইউজার ইনসার্ট
                    if(empty($pass)) {
                        $status_msg = "❌ নতুন ইউজার তৈরির জন্য পাসওয়ার্ড বাধ্যতামূলক!";
                    } else {
                        $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
                        // সেলারি অপশন বাদ দিয়ে ডাটাবেজে যুক্ত করা হচ্ছে এবং is_verified = 0 রাখা হচ্ছে যেন সে OTP ভেরিফাই করে
                        $stmt = $conn->prepare("INSERT INTO users (username, password, role, email, phone, address, joining_date, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
                        $stmt->execute([$user, $hashed_pass, $role, $email, $phone, $address, $joining]);
                        $status_msg = "✅ নতুন ইউজার তৈরি হয়েছে!";
                    }
                }
                
                if ($conn->inTransaction()) { $conn->commit(); }
            } catch (Exception $e) {
                if ($conn->inTransaction()) { $conn->rollBack(); }
                $status_msg = "❌ ডাটাবেজ এরর: " . $e->getMessage();
            }
        }
    }
}

// ==========================================
// ডিলিট অ্যাকশন (GET Request)
// ==========================================
if (isset($_GET['del_user'])) {
    $del_id = filter_var($_GET['del_user'], FILTER_VALIDATE_INT);
    if ($del_id) {
        $adminCtrl->deleteUser($del_id, $uid);
        header("Location: admin_panel.php"); 
        exit;
    }
}

if (isset($_GET['del_msg'])) {
    $msg_id = filter_var($_GET['del_msg'], FILTER_VALIDATE_INT);
    if ($msg_id) {
        $conn->prepare("DELETE FROM notifications WHERE id = ?")->execute([$msg_id]);
        header("Location: admin_panel.php"); 
        exit;
    }
}

// ==========================================
// ডাটা ফেচিং (ভিউ লোড করার জন্য)
// ==========================================
try {
    $users_list = $conn->query("SELECT id, username, role, email, phone FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC); 
    $inbox_msg = $conn->query("SELECT n.*, u.username FROM notifications n JOIN users u ON n.user_id = u.id WHERE n.status = 'pending' ORDER BY n.id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC); 
    $has_new_messages = count($inbox_msg) > 0 ? 'true' : 'false';
} catch (Exception $e) {
    $users_list = []; $inbox_msg = []; $has_new_messages = 'false';
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ADD USER & USER MESSAGE( SADA KALO FASHION)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Hind Siliguri', sans-serif; }
        #userForm { transition: max-height 0.4s ease, opacity 0.4s ease; overflow: hidden; }
        .form-hidden { max-height: 0; opacity: 0; margin: 0; padding: 0; border: none; }
        .form-visible { max-height: 800px; opacity: 1; margin-top: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #e2e8f0; }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen pb-10">

    <audio id="sendSound" src="https://actions.google.com/sounds/v1/ui/message_sent.ogg" preload="auto"></audio>
    <audio id="receiveSound" src="https://actions.google.com/sounds/v1/alarms/beep_short.ogg" preload="auto"></audio>

    <div class="max-w-6xl mx-auto px-4 mt-6">
        
        <div class="flex flex-col sm:flex-row justify-between items-center bg-blue-900 text-white p-5 rounded-2xl shadow-lg mb-6 gap-4 border-b-4 border-purple-500">
            <div>
     <h1 class="text-xl font-bold flex items-center gap-2 uppercase"><i class="fas fa-bullhorn text-blue-300"></i>ADD USER & MESSAGE(SADA KALO FASHION)</h1>
            </div>
            <div class="flex gap-2">
                <a href="master_control.php" class="bg-slate-700 hover:bg-slate-600 px-4 py-2.5 rounded-lg font-bold transition flex items-center gap-2 text-sm border border-slate-500">
                    <i class="fas fa-arrow-left"></i> ব্যাক
                </a>
                <a href="../dashboard.php" class="bg-blue-600 hover:bg-blue-500 px-4 py-2.5 rounded-lg font-bold transition flex items-center gap-2 text-sm border border-blue-400">
                    <i class="fas fa-home"></i> হোম
                </a>
                 <a href="../SuperAdminDashboard.php" class="bg-blue-600 hover:bg-blue-500 px-4 py-2.5 rounded-lg font-bold transition flex items-center gap-2 text-sm border border-red-400">
                    <i class="fas fa-paper-amber-500"></i>ADMIN-Dashboard
                </a>
            </div>
        </div>

        <?php if($status_msg): ?>
            <div class="bg-emerald-50 border-l-4 <?php echo strpos($status_msg, '❌') !== false ? 'border-rose-500 text-rose-800 bg-rose-50' : 'border-emerald-500 text-emerald-800'; ?> p-4 rounded-lg shadow-sm mb-6 font-bold flex items-center gap-3 text-sm">
                <?php echo strpos($status_msg, '❌') !== false ? '<i class="fas fa-times-circle text-rose-500 text-lg"></i>' : '<i class="fas fa-check-circle text-emerald-500 text-lg"></i>'; ?> 
                <?php echo $status_msg; ?>
            </div>
        <?php endif; ?>

        <div class="bg-white p-5 rounded-2xl shadow-md border-t-4 border-amber-500 mb-6">
            <h3 class="text-lg font-bold text-amber-600 mb-4 flex items-center gap-2 border-b border-slate-200 pb-3">
                <i class="fas fa-paper-plane text-amber-500"></i> মেসেজ ও ব্রডকাস্ট প্যানেল
            </h3>
            
            <div class="flex flex-col lg:flex-row gap-6">
                <div class="flex-1">
                    <form method="POST" id="broadcastForm" onsubmit="playSendSound(event)" class="bg-amber-50 border border-amber-200 p-4 rounded-xl h-full flex flex-col justify-between">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div>
                            <label class="font-bold text-amber-800 text-sm mb-2 block"><i class="fas fa-bell text-amber-500"></i> নতুন নোটিশ পাঠান (সবার জন্য)</label>
                            <textarea name="message" placeholder="নোটিশটি এখানে লিখুন..." required class="w-full bg-white border border-amber-300 rounded-lg p-3 font-semibold text-sm outline-none focus:border-amber-500 resize-none h-24 mb-4"></textarea>
                        </div>
                        <button type="submit" name="send_broadcast" class="w-full bg-amber-500 hover:bg-amber-600 text-white font-bold py-3 rounded-lg uppercase transition flex items-center justify-center gap-2 text-sm shadow-md">
                            ব্রডকাস্ট করুন <i class="fas fa-volume-up"></i>
                        </button>
                    </form>
                </div>

                <div class="flex-1 flex flex-col gap-4">
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 flex-1">
                        <h4 class="font-bold text-blue-900 mb-3 text-sm flex items-center gap-2"><i class="fas fa-inbox text-blue-500"></i> প্রাপ্ত মেসেজ (Inbox)</h4>
                        <div class="flex flex-col gap-2 max-h-48 overflow-y-auto pr-1">
                            <?php if(count($inbox_msg) > 0): foreach($inbox_msg as $msg): ?>
                                <div class="relative bg-white border-l-4 border-blue-500 p-3 rounded-lg shadow-sm border border-slate-100">
                                    <div class="font-bold text-blue-600 text-xs mb-1"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($msg['username']); ?></div>
                                    <p class="text-slate-700 text-xs font-medium pr-6"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                                    <a href="?del_msg=<?php echo $msg['id']; ?>" class="absolute top-2 right-2 text-rose-400 hover:text-rose-600 transition p-1"><i class="fas fa-trash text-sm"></i></a>
                                </div>
                            <?php endforeach; else: ?>
                                <div class="text-center py-4 bg-white rounded-lg border border-dashed border-slate-300"><span class="text-slate-400 text-xs font-semibold">কোনো মেসেজ নেই</span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl shadow-md border-t-4 border-emerald-500">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center border-b border-slate-200 pb-3 gap-3">
                <h3 class="text-lg font-bold text-emerald-700 flex items-center gap-2"><i class="fas fa-user-plus text-emerald-500"></i> নতুন ইউজার প্যানেল</h3>
                <button onclick="toggleUserForm()" class="w-full sm:w-auto bg-emerald-50 hover:bg-emerald-100 text-emerald-700 px-4 py-2 rounded-lg text-sm font-bold border border-emerald-200 transition flex items-center justify-center gap-2">
                    <i class="fas fa-plus-circle" id="toggleIcon"></i> <span id="toggleText">নতুন ইউজার ফর্ম দেখান</span>
                </button>
            </div>
            
            <form method="POST" id="userForm" class="form-hidden">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 mb-4 mt-4">
                    <div>
                        <label class="font-bold text-slate-600 text-xs mb-1 block">ইউজারনেম *</label>
                        <input type="text" name="username" required placeholder="ex: masum12" class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 outline-none font-semibold text-sm focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="font-bold text-slate-600 text-xs mb-1 block">পাসওয়ার্ড <span class="text-[10px] text-slate-400">(আপডেট করতে চাইলে দিন)</span></label>
                        <input type="text" name="password" placeholder="নতুন পাসওয়ার্ড" class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 outline-none font-semibold text-sm focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="font-bold text-slate-600 text-xs mb-1 block">রোল *</label>
                        <select name="role" class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 outline-none font-bold text-sm focus:border-emerald-500">
                            <option value="staff">Staff</option>
                            <option value="manager">Manager</option>
                            <option value="viewer">Viewer</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div>
                        <label class="font-bold text-slate-600 text-xs mb-1 block">ইমেইল</label>
                        <input type="email" name="email" placeholder="example@email.com" class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 outline-none font-semibold text-sm focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="font-bold text-slate-600 text-xs mb-1 block">ফোন নাম্বার</label>
                        <input type="text" name="phone" placeholder="017XXXXXXX" class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 outline-none font-semibold text-sm focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="font-bold text-slate-600 text-xs mb-1 block">ঠিকানা</label>
                        <input type="text" name="address" placeholder="ঠিকানা..." class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 outline-none font-semibold text-sm focus:border-emerald-500">
                    </div>
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" name="save_user" class="w-full sm:w-auto bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-8 rounded-lg transition text-sm shadow-md flex items-center justify-center gap-2">
                        <i class="fas fa-save"></i> ইউজার সেভ করুন
                    </button>
                </div>
            </form>

            <div class="mt-5 overflow-x-auto bg-slate-50 border border-slate-200 rounded-lg">
                <table class="w-full text-left whitespace-nowrap">
                    <thead class="bg-slate-200 text-slate-600 text-[11px] uppercase font-black">
                        <tr>
                            <th class="p-3 border-b border-slate-300">ইউজার</th>
                            <th class="p-3 border-b border-slate-300">ইমেইল / ফোন</th>
                            <th class="p-3 border-b border-slate-300">রোল</th>
                            <th class="p-3 border-b border-slate-300 text-center">অ্যাকশন</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 text-sm">
                        <?php foreach($users_list as $u): ?>
                        <tr class="hover:bg-white transition">
                            <td class="p-3 font-bold text-blue-900"><?php echo htmlspecialchars($u['username']); ?></td>
                            <td class="p-3 text-slate-600 text-xs">
                                <div><?php echo !empty($u['email']) ? htmlspecialchars($u['email']) : '<span class="text-slate-400 italic">No Email</span>'; ?></div>
                                <div><?php echo !empty($u['phone']) ? htmlspecialchars($u['phone']) : ''; ?></div>
                            </td>
                            <td class="p-3"><span class="px-2.5 py-1 rounded-md text-[10px] font-black uppercase bg-slate-200"><?php echo htmlspecialchars($u['role']); ?></span></td>
                            <td class="p-3 text-center">
                                <?php if($u['id'] != $_SESSION['user_id'] && $u['username'] != '@sadakalo'): ?>
                                    <a href="?del_user=<?php echo $u['id']; ?>" onclick="return confirm('আপনি কি নিশ্চিত যে এই ইউজারকে ডিলিট করতে চান?');" class="text-rose-500 hover:bg-rose-100 p-2 rounded transition inline-block"><i class="fas fa-trash-alt"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function toggleUserForm() {
            const form = document.getElementById('userForm');
            const icon = document.getElementById('toggleIcon');
            const text = document.getElementById('toggleText');
            if (form.classList.contains('form-hidden')) {
                form.classList.remove('form-hidden'); form.classList.add('form-visible');
                icon.classList.replace('fa-plus-circle', 'fa-minus-circle'); text.innerText = 'ফর্ম লুকান';
            } else {
                form.classList.remove('form-visible'); form.classList.add('form-hidden');
                icon.classList.replace('fa-minus-circle', 'fa-plus-circle'); text.innerText = 'নতুন ইউজার ফর্ম দেখান';
            }
        }

        function playSendSound(event) {
            event.preventDefault(); 
            document.getElementById('sendSound').play();
            setTimeout(() => { event.target.submit(); }, 400); 
        }

        document.addEventListener("DOMContentLoaded", function() {
            if (<?php echo $has_new_messages; ?>) document.getElementById('receiveSound').play().catch(e => console.log(e));
        });
    </script>
</body>
</html>