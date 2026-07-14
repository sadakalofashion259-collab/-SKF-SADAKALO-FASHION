<?php
/**
 * শেয়ার্ড এরর পেজ ভিউ।
 * ডিজাইন আপনার আগের db_connect.php-এর এরর পেজ থেকে হুবহু রাখা হয়েছে।
 *
 * @var callable $e            escape হেল্পার
 * @var string   $userMessage  ইউজারকে দেখানোর নিরাপদ বার্তা
 * @var int      $statusCode   HTTP স্ট্যাটাস
 */
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Connection Error</title>
    <style>
        body { background-color: #0f172a; margin: 0; display: flex; align-items: center; justify-content: center; height: 100vh; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }
        .error-container { background: #1e293b; border: 2px solid #ef4444; border-radius: 12px; padding: 40px; text-align: center; max-width: 500px; width: 90%; box-shadow: 0 15px 35px rgba(239, 68, 68, 0.2); }
        .error-icon { font-size: 70px; margin-bottom: 20px; line-height: 1; }
        .error-title { color: #ef4444; font-size: 28px; margin: 0 0 15px 0; font-weight: 900; }
        .error-text { color: #cbd5e1; font-size: 16px; line-height: 1.6; margin: 0 0 25px 0; }
        .error-btn { background: #ef4444; color: #ffffff; padding: 12px 25px; border-radius: 8px; font-weight: bold; display: inline-block; text-transform: uppercase; font-size: 14px; letter-spacing: 1px; text-decoration: none; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">⚠️</div>
        <h1 class="error-title">সিস্টেম ত্রুটি!</h1>
        <p class="error-text"><?php echo $e($userMessage); ?></p>
        <a href="/" class="error-btn">হোমপেজে ফিরুন</a>
    </div>
</body>
</html>
