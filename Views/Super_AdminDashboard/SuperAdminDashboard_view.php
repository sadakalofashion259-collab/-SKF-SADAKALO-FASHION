<!DOCTYPE html>
<html lang="bn" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ড্যাশবোর্ড — Sada Kalo Fashion</title>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Outfit:wght@500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/style_css/super_admindashboard.css">
</head>
<body>
<div class="shell">
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">
      <div class="logo-icon">SKF</div>
      <div><div class="logo-text">সাদা কালো ফ্যাশন</div><div class="logo-sub">Super Admin </div></div>
    </div>
  </div>
  <nav class="sidebar-menu">
    <div class="menu-group-label">প্রধান</div>
    <a class="menu-item active" href="dashboard.php"><i class="fas fa-home"></i>HOME PAGE</a>
    <div class="menu-group-label">লেনদেন</div>
    <a class="menu-item" href="historys.php"><i class="fas fa-book-open"></i> হিস্ট্রি লেজার</a>
    <a class="menu-item" href="../shop_@invantory/admin_inventory_control.php"><i class="fas fa-arrow-up" style="color:var(--green)"></i> ইনভেন্টরি এন্ট্রি</a>
    <a class="menu-item" href="../main_sub_files/expense.php"><i class="fas fa-arrow-down" style="color:var(--red)"></i> খরচ এন্ট্রি</a>
    <div class="menu-group-label">পার্টি</div>
    <a class="menu-item" href="customers.php">
      <i class="fas fa-user" style="color:var(--blue)"></i> কাস্টমার
      <?php if($totalCustCount>0):?><span class="menu-badge"><?php echo $totalCustCount;?></span><?php endif;?>
    </a>
    <a class="menu-item" href="suppliers.php">
      <i class="fas fa-industry" style="color:var(--orange)"></i> সাপ্লায়ার
      <?php if($totalSupCount>0):?><span class="menu-badge"><?php echo $totalSupCount;?></span><?php endif;?>
    </a>
    <div class="menu-group-label">স্টক ও স্টাফ</div>
    <a class="menu-item" href="stock.php">
      <i class="fas fa-box" style="color:var(--cyan)"></i> ইনভেন্টরি
      <?php if(isset($lowInvCount) && $lowInvCount > 0):?><span class="menu-badge gold"><i class="fas fa-triangle-exclamation"></i> <?php echo $lowInvCount;?></span><?php endif;?>
    </a>
    <a class="menu-item" href="Sadakalo_staff/Sadakalo_staff_atteend.php"><i class="fas fa-users" style="color:var(--gold)"></i> স্টাফ 👪 হাজিরা</a>
    <div class="menu-group-label">আর্থিক</div>
    <a class="menu-item" href="../Sadakalo_Account/sadakalo_account_dashboard.php"><i class="fas fa-university" style="color:var(--purple)"></i> লোন</a>
    <a class="menu-item" href="monthly_report.php"><i class="fas fa-piggy-bank" style="color:var(--gold)"></i>মাসিক রিপোর্ট </a>
    <div class="menu-group-label">রিপোর্ট</div>
    <a class="menu-item" href="./routine_manager.php"><i class="fas fa-chart-bar" style="color:var(--green)"></i> দৈনিক Task</a>
  </nav>
  <div class="sidebar-bottom">
    <div class="user-card">
      <div class="user-avatar"><?php echo mb_substr($sessionUser,0,1,'UTF-8');?></div>
      <div>
        <div class="user-name"><?php echo htmlspecialchars($sessionUser);?></div>
        <div class="user-role"><?php echo ucfirst(htmlspecialchars($role));?> / Owner</div>
      </div>
    </div>
  </div>
</aside>

<div class="main">
  <header class="topbar">
    <div>
      <div class="topbar-title">সাদা কালো ফ্যাশন(❁´◡`❁) ড্যাশবোর্ড</div>
      <div class="topbar-date"><?php echo date('l, d F Y');?></div>
    </div>
    <div style="display:flex;align-items:center;gap:7px;margin-left:18px">
      <div class="live-dot"></div>
      <span style="font-size:10px;color:var(--green);font-weight:700">লাইভ</span>
    </div>
    <div class="topbar-spacer"></div>
    <button class="theme-btn" onclick="toggleTheme()" id="themeBtn" title="থিম পরিবর্তন">
      <i class="fas fa-sun" id="themeIco"></i>
    </button>
    <a href="Message" class="topbar-btn"><i class="fas fa-envelope"></i> MESSAGE</a>
    <a href="admin/master_control.php" class="topbar-btn primary"><i class="fas fa-chart-line"></i> Admin-HUB</a>
  </header>

  <div class="content">

    <div class="cash-hero">
      <div class="cash-hero-inner">
        <div class="cash-main">
          <div class="cash-label">বর্তমান ক্যাশ ব্যালেন্স</div>
          <div class="cash-amount" style="color:<?php echo $cashColor;?>">
            <span>৳</span><?php echo number_format($currentCash,0,'.',',');?>
          </div>
          <div class="cash-sub">
            গতকালের তুলনায় <b style="color:<?php echo $vsTodayColor;?>"><?php echo $vsTodaySign.$fmtBDT(abs($vsTodayDiff));?></b>
            &nbsp;|&nbsp; আজকের নেট: <b style="color:<?php echo $netChangeClr;?>"><?php echo $netChangeDir.' '.$fmtBDT(abs($todayNetChange));?></b>
          </div>
        </div>
        <div class="cash-divider"></div>
        <div class="cash-stats">
          <div class="cash-stat"><div class="cash-stat-val green"><?php echo $fmtBDT($todayTotalIn);?></div><div class="cash-stat-label">↑ আজ ঢুকেছে</div></div>
          <div class="cash-stat"><div class="cash-stat-val red"><?php echo $fmtBDT($todayTotalOut);?></div><div class="cash-stat-label">↓ আজ বের হয়েছে</div></div>
          <div class="cash-stat"><div class="cash-stat-val gold"><?php echo $fmtBDT($monthNetProfit);?></div><div class="cash-stat-label">এ মাসে নেট</div></div>
        </div>
      </div>
    </div>

    <div class="kpi-grid">

      <div class="kpi-card green">
        <div class="kpi-top"><div class="kpi-icon green"><i class="fas fa-chart-line"></i></div><span class="kpi-trend up">↑ আজ</span></div>
        <div class="kpi-val"><?php echo $fmtBDT($todaySaleCash);?> <span>আজ</span></div>
        <div class="kpi-label">মোট বিক্রি (ক্যাশ)</div>
        <?php if($monthSaleCash>0):?><div class="kpi-mini">এই মাসে: <?php echo $fmtBDT($monthSaleCash);?></div><?php endif;?>
      </div>

      <div class="kpi-card red">
        <div class="kpi-top"><div class="kpi-icon red"><i class="fas fa-arrow-trend-down"></i></div><span class="kpi-trend down">↓ খরচ</span></div>
        <div class="kpi-val"><?php echo $fmtBDT($todayTotalExpense);?> <span>আজ</span></div>
        <div class="kpi-label">মোট খরচ</div>
        <?php if($todayShopExpense>0||$todayStaffExpense>0):?>
        <div class="kpi-mini"><?php echo $todayShopExpense>0?'দোকান: '.$fmtBDT($todayShopExpense).' ':''?><?php echo $todayStaffExpense>0?'স্টাফ: '.$fmtBDT($todayStaffExpense):'';?></div>
        <?php endif;?>
      </div>

      <div class="kpi-card blue">
        <div class="kpi-top"><div class="kpi-icon blue"><i class="fas fa-user-clock"></i></div><span class="kpi-trend neutral"><?php echo $totalCustCount;?> জন</span></div>
        <div class="kpi-val"><?php echo $fmtBDT($totalCustDue);?></div>
        <div class="kpi-label">কাস্টমার বাকি</div>
        <?php if($todayCustRcv>0):?><div class="kpi-mini" style="color:var(--green)">আজ আদায়: <?php echo $fmtBDT($todayCustRcv);?></div><?php endif;?>
      </div>

      <div class="kpi-card orange">
        <div class="kpi-top"><div class="kpi-icon orange"><i class="fas fa-truck"></i></div><span class="kpi-trend neutral"><?php echo $totalSupCount;?> জন</span></div>
        <div class="kpi-val"><?php echo $fmtBDT($totalSupDue);?></div>
        <div class="kpi-label">সাপ্লায়ার দেনা</div>
        <?php if($todaySupPay>0):?><div class="kpi-mini" style="color:var(--orange)">আজ পেমেন্ট: <?php echo $fmtBDT($todaySupPay);?></div><?php endif;?>
      </div>

      <div class="kpi-card purple">
        <div class="kpi-top"><div class="kpi-icon purple"><i class="fas fa-university"></i></div><span class="kpi-trend down">বকেয়া</span></div>
        <div class="kpi-val"><?php echo $fmtBDT($loanOutstanding);?></div>
        <div class="kpi-label">লোন বকেয়া (মোট)</div>
        <?php if($nextLoanDate):?><div class="kpi-mini" style="color:var(--red)">পরের কিস্তি: <?php echo date('d M',strtotime($nextLoanDate));?><?php echo $nextLoanName?' — '.htmlspecialchars($nextLoanName):'';?></div><?php endif;?>
      </div>

      <div class="kpi-card cyan">
        <div class="kpi-top"><div class="kpi-icon cyan"><i class="fas fa-piggy-bank"></i></div><span class="kpi-trend up">↑ জমা</span></div>
        <div class="kpi-val"><?php echo $fmtBDT($dpsTotalBalance);?></div>
        <div class="kpi-label">DPS / FDR জমা</div>
        <?php if($dpsInstallAmt>0||$dpsMaturity):?>
        <div class="kpi-mini"><?php echo $dpsInstallAmt>0?'কিস্তি: '.$fmtBDT($dpsInstallAmt):'';?><?php echo $dpsMaturity?' | মেয়াদ: '.$dpsMaturity:'';?></div>
        <?php endif;?>
      </div>

      <div class="kpi-card gold">
        <div class="kpi-top"><div class="kpi-icon gold"><i class="fas fa-users"></i></div><span class="kpi-trend <?php echo $attPresent>0?'up':'neutral';?>"><?php echo $attPresent;?>/<?php echo $totalStaff;?> জন</span></div>
        <div class="kpi-val"><?php echo $attPresent;?> জন <span>উপস্থিত</span></div>
        <div class="kpi-label">আজকের হাজিরা</div>
        <?php if($attAbsent>0):?><div class="kpi-mini" style="color:var(--red)">অনুপস্থিত: <?php echo htmlspecialchars($absentStr);?></div>
        <?php elseif($totalStaff>0):?><div class="kpi-mini" style="color:var(--green)">সবাই উপস্থিত ✓</div><?php endif;?>
      </div>

      <div class="kpi-card" style="border-color:rgba(212,168,67,.22)">
        <div class="kpi-top">
          <div class="kpi-icon gold"><i class="fas fa-boxes-stacked"></i></div>
          <?php if(isset($lowInvCount) && $lowInvCount>0):?><span class="kpi-trend down"><i class="fas fa-triangle-exclamation"></i> <?php echo $lowInvCount;?>টি কম</span>
          <?php else:?><span class="kpi-trend up">স্বাভাবিক</span><?php endif;?>
        </div>
        <div class="kpi-val" style="color:var(--gold)"><?php echo $totalInvCount;?> <span>আইটেম</span></div>
        <div class="kpi-label">ইনভেন্টরি স্টক</div>
        <?php if($totalInvPieces>0):?><div class="kpi-mini">মোট পিস: <?php echo number_format($totalInvPieces);?>
        <?php if(isset($lowInvCount) && $lowInvCount>0): $lnames=implode(', ',array_column(array_slice($lowInvList,0,2),'name')); ?> | কম: <?php echo htmlspecialchars($lnames);?><?php endif;?></div>
        <?php endif;?>
      </div>

    </div>

    <div class="main-grid">

      <div class="chart-card">
        <div class="card-header">
          <div><div class="card-title">আয় বনাম খরচ</div><div class="card-sub">গত ৭ দিন</div></div>
        </div>
        <?php $barMax=max(1,$chartMaxVal);?>
        <div class="bar-chart-wrap">
          <?php foreach($chartDayArr as $idx=>$dl):
            $inH=max(2,(int)(($chartInArr[$idx]/$barMax)*116));
            $outH=max(2,(int)(($chartOutArr[$idx]/$barMax)*116));
            $isT=$weekDates[$idx]===$today;
          ?>
          <div class="bar-group">
            <div class="bars">
              <div class="bar income" style="height:<?php echo $inH;?>px<?php echo $isT?';box-shadow:0 0 10px rgba(34,197,94,.5)':'';?>"></div>
              <div class="bar expense" style="height:<?php echo $outH;?>px"></div>
            </div>
            <div class="bar-day" style="<?php echo $isT?'color:var(--gold);font-weight:800':'';?>"><?php echo $isT?'আজ':$dl;?></div>
          </div>
          <?php endforeach;?>
        </div>
        <div class="bar-legend">
          <div class="bleg"><div class="bleg-dot" style="background:var(--green)"></div> আয়</div>
          <div class="bleg"><div class="bleg-dot" style="background:var(--red)"></div> খরচ</div>
        </div>
      </div>

      <div class="chart-card">
        <div class="card-header">
          <div><div class="card-title">মাসিক নেট ব্যালেন্স গতি</div><div class="card-sub"><?php echo date('F Y');?> — প্রতিদিন</div></div>
          <div style="font-size:12px;color:<?php echo $monthNetProfit>=0?'var(--green)':' var(--red)';?>;font-weight:800"><?php echo $fmtSign($monthNetProfit);?></div>
        </div>
        <?php
        $svgW=320;$svgH=130;$mnMin=min($monthNetArr?:[0]);$mnMax=max($monthNetArr?:[1]);$mnRange=max(1,$mnMax-$mnMin);
        $pts=[];$cnt=count($monthNetArr);
        foreach($monthNetArr as $mi=>$mv){$x=(int)(($mi/max(1,$cnt-1))*$svgW);$y=(int)($svgH-(($mv-$mnMin)/$mnRange)*($svgH-15)-5);$pts[]="$x,$y";}
        $lastPt=end($pts)?:'0,125';$lx=explode(',',$lastPt)[0];$ly=explode(',',$lastPt)[1];
        $pf=implode(' ',$pts)." $svgW,$svgH 0,$svgH";$lc=$monthNetProfit>=0?'#22c55e':'#ef4444';
        ?>
        <div style="height:140px">
          <svg viewBox="0 0 <?php echo $svgW;?> <?php echo $svgH;?>" width="100%" height="<?php echo $svgH;?>" xmlns="http://www.w3.org/2000/svg">
            <defs><linearGradient id="lgM" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="<?php echo $lc;?>" stop-opacity=".2"/><stop offset="100%" stop-color="<?php echo $lc;?>" stop-opacity="0"/></linearGradient></defs>
            <?php if($cnt>1):?>
            <polygon points="<?php echo $pf;?>" fill="url(#lgM)"/>
            <polyline points="<?php echo implode(' ',$pts);?>" fill="none" stroke="<?php echo $lc;?>" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="<?php echo $lx;?>" cy="<?php echo $ly;?>" r="4" fill="<?php echo $lc;?>"/>
            <circle cx="<?php echo $lx;?>" cy="<?php echo $ly;?>" r="9" fill="<?php echo $lc;?>22"/>
            <?php endif;?>
          </svg>
        </div>
      </div>

      <div>
        <div class="alert-section">
          <div class="alert-sec-title"><i class="fas fa-clock" style="color:var(--gold)"></i> আসন্ন কিস্তি (৭ দিনে)</div>
          <?php if(empty($upcomingInstallments)):?>
          <div class="empty-row"><i class="fas fa-check-circle" style="color:var(--green)"></i>কোনো আসন্ন কিস্তি নেই</div>
          <?php else: foreach($upcomingInstallments as $inst):?>
          <div class="alert-item">
            <div class="alert-dot <?php echo $inst['urgent']?'red':'yellow';?>"></div>
            <div class="alert-info"><div class="alert-name"><?php echo htmlspecialchars($inst['name']);?></div><div class="alert-detail"><?php echo $inst['date'];?> · <?php echo htmlspecialchars($inst['type']);?></div></div>
            <div class="alert-amt <?php echo $inst['urgent']?'red':'gold';?>"><?php echo $fmtBDT($inst['amount']);?></div>
          </div>
          <?php endforeach; endif;?>
        </div>
        <div class="alert-section">
          <div class="alert-sec-title"><i class="fas fa-user-clock" style="color:var(--blue)"></i> বাকি কাস্টমার</div>
          <?php if(empty($topDueCustomers)):?>
          <div class="empty-row"><i class="fas fa-check-circle" style="color:var(--green)"></i>কোনো বাকি নেই ✓</div>
          <?php else: foreach($topDueCustomers as $idx=>$cust):
            $dc=$idx===0?'red':($idx===1?'yellow':'green');$ac=$idx===0?'red':($idx===1?'gold':'green');
            $ldate=!empty($cust['last_date'])?date('d M',strtotime($cust['last_date'])):'';
          ?>
          <div class="alert-item">
            <div class="alert-dot <?php echo $dc;?>"></div>
            <div class="alert-info"><div class="alert-name"><?php echo htmlspecialchars($cust['shop_name']?:$cust['customer_name']);?></div><?php if($ldate):?><div class="alert-detail"><?php echo $ldate;?></div><?php endif;?></div>
            <div class="alert-amt <?php echo $ac;?>"><?php echo $fmtBDT((float)$cust['due']);?></div>
          </div>
          <?php endforeach; endif;?>
        </div>
      </div>
    </div>

    <div class="bottom-grid">

      <div class="chart-card">
        <div class="card-header"><div><div class="card-title">আজকের ক্যাশ ফ্লো</div><div class="card-sub">টাকা কোথা থেকে এলো / কোথায় গেল</div></div></div>
        <?php if(empty($cashFlowItems)):?>
        <div class="empty-row"><i class="fas fa-inbox"></i>আজ কোনো লেনদেন নেই</div>
        <?php else:?><div class="cf-list">
          <?php foreach($cashFlowItems as $cf):?>
          <div class="cf-item">
            <div class="cf-arrow <?php echo $cf['dir'];?>"><i class="fas <?php echo $cf['icon'];?>"></i></div>
            <div class="cf-name"><?php echo $cf['name'];?></div>
            <div class="cf-amount <?php echo $cf['dir'];?>"><?php echo $cf['dir']=== 'in'?'+':'-';?><?php echo $fmtBDT($cf['amt']);?></div>
          </div>
          <?php endforeach;?>
        </div><?php endif;?>
      </div>

      <div class="chart-card">
        <div class="card-header"><div><div class="card-title">লোন ও DPS অবস্থা</div><div class="card-sub">পরিশোধের অগ্রগতি</div></div></div>
        <?php if(empty($loanProgressList)&&empty($dpsProgressList)):?>
        <div class="empty-row"><i class="fas fa-check-circle" style="color:var(--green)"></i>কোনো সক্রিয় লোন বা DPS নেই</div>
        <?php endif;?>
        <?php foreach($loanProgressList as $ln):
          $taken=(float)$ln['total_taken'];$paid=(float)$ln['total_paid'];
          $pct=$taken>0?min(100,(int)(($paid/$taken)*100)):0;
          $lt=strtolower($ln['loan_category']??'')=== 'ngo'?'ngo':'bank';
          $tlbl=strtoupper($ln['loan_category']??'BANK');
        ?>
        <div class="loan-item">
          <div class="loan-top"><div class="loan-name"><?php echo htmlspecialchars($ln['borrower_name']);?></div><span class="loan-type <?php echo $lt;?>"><?php echo $tlbl;?></span></div>
          <div class="loan-progress"><div class="loan-bar <?php echo $lt;?>" style="width:<?php echo $pct;?>%"></div></div>
          <div class="loan-nums"><span><?php echo $fmtBDT($paid);?> পরিশোধ</span><span>বাকি <?php echo $fmtBDT((float)$ln['current_balance']);?></span></div>
        </div>
        <?php endforeach;?>
        <?php foreach($dpsProgressList as $dp):
          $db=(float)$dp['total_balance'];$tgt=$dpsInstallAmt>0?$dpsInstallAmt*24:max(1,$db*2);$dp2=min(100,(int)(($db/$tgt)*100));?>
        <div class="loan-item">
          <div class="loan-top"><div class="loan-name"><?php echo htmlspecialchars($dp['client_name']);?> — <?php echo htmlspecialchars($dp['account_type']);?></div><span class="loan-type dps">DPS</span></div>
          <div class="loan-progress"><div class="loan-bar dps" style="width:<?php echo $dp2;?>%"></div></div>
          <div class="loan-nums"><span><?php echo $fmtBDT($db);?> জমা</span><span><?php echo !empty($dp['maturity_date'])?'মেয়াদ: '.date('M Y',strtotime($dp['maturity_date'])):'';?></span></div>
        </div>
        <?php endforeach;?>
      </div>

      <div class="chart-card">
        <div class="card-header">
          <div><div class="card-title">ইনভেন্টরি স্টক</div><div class="card-sub">কম পিস আগে দেখাচ্ছে</div></div>
          <?php if(isset($lowInvCount) && $lowInvCount>0):?><span style="font-size:10px;font-weight:700;background:rgba(239,68,68,.12);color:var(--red);padding:3px 9px;border-radius:20px"><i class="fas fa-triangle-exclamation"></i> <?php echo $lowInvCount;?>টি কম</span><?php endif;?>
        </div>
        <?php if(empty($invDisplayList)):?>
        <div class="empty-row"><i class="fas fa-box-open"></i>কোনো ইনভেন্টরি তথ্য নেই</div>
        <?php else:
          $sm=max(1,max(array_column($invDisplayList,'pieces')?:[1]));
          foreach($invDisplayList as $inv):
            $qty=(int)($inv['pieces']??0);
            $pct=$sm>0?max(3,(int)(($qty/$sm)*100)):3;
            $cls=$qty<=0?'low':($qty<5?'low':($qty<15?'mid':'ok'));
        ?>
        <div class="stock-item">
          <div class="stock-name" title="<?php echo htmlspecialchars($inv['name']);?>"><?php echo htmlspecialchars($inv['name']);?></div>
          <div class="stock-bar-wrap"><div class="stock-bar-fill <?php echo $cls;?>" style="width:<?php echo $pct;?>%"></div></div>
          <div class="stock-qty <?php echo $cls;?>"><?php echo $qty;?> পিস<?php echo $cls==='low'?' ⚠':'';?></div>
        </div>
        <?php endforeach; endif;?>
      </div>

    </div>
  </div>
</div>

<script>
function toggleTheme() {
    const html  = document.documentElement;
    const isDark = html.getAttribute('data-theme') === 'dark';
    const next   = isDark ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('skf_theme', next);
    document.getElementById('themeIco').className = next === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
}
(function() {
    const saved = localStorage.getItem('skf_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', saved);
    const ico = document.getElementById('themeIco');
    if (ico) ico.className = saved === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
})();
</script>
</body>
</html>