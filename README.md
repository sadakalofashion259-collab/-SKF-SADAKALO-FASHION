# Hisab — Core Foundation (Step 1)

মডিউল-ভিত্তিক MVC আর্কিটেকচারের ভিত্তি। টগলযোগ্য সব কনফিগ (SMS, Email,
timezone, session) এখন `settings` DB টেবিলে — কোডে হার্ডকোড নয়।

## ফোল্ডার স্ট্রাকচার

```
Hisab/  (= document root)
├── bootstrap.php          ← একক প্রবেশ-ভিত্তি (আগের db_connect.php-এর নতুন রূপ)
├── Config/
│   ├── AppConfig.php       ← পাথ (auto-detect) + fallback ডিফল্ট
│   └── schema/settings.sql ← settings টেবিল + ডিফল্ট সিড
├── Core/
│   ├── Env.php             ← শক্তিশালী .env পার্সার
│   ├── Logger.php          ← সব এরর → Logs/error_log.txt
│   ├── Database.php        ← PDO + prepared + transaction
│   ├── Settings.php        ← DB-চালিত টগলযোগ্য কনফিগ (on/off)
│   ├── Session.php         ← একীভূত নিরাপদ সেশন
│   ├── Csrf.php            ← CSRF টোকেন
│   ├── Security.php        ← XSS escape + base64url
│   ├── View.php            ← ভিউ রেন্ডারার
│   ├── Response.php        ← redirect / json / errorPage
│   └── Views/error.php     ← এরর পেজ (আগের ডিজাইন)
├── Modules/                ← Auth, Biometric, User, Sms, Email (পরের ধাপে)
└── Logs/error_log.txt
```

## কনফিগ কোথায় থাকে

| জিনিস                         | কোথায়            | কেন |
|-------------------------------|------------------|-----|
| DB creds, এনক্রিপশন কি        | `.env` (ভল্ট)    | DB-তে ঢোকার আগেই লাগে |
| timezone, session, SMS, Email | `settings` টেবিল | অ্যাডমিন প্যানেল থেকে on/off ও এডিট |
| পাথ                           | AppConfig (auto) | __DIR__ থেকে স্বয়ংক্রিয় |

## ইনস্টল

```php
// যেকোনো এন্ট্রি ফাইলের শুরুতে:
require_once __DIR__ . '/bootstrap.php';
// এখন $db, $logger, $settings প্রস্তুত; সেশনও চালু।
```

১. ফাইল আপলোড করুন (document root-এ)।
২. `Config/schema/settings.sql` DB-তে import করুন (phpMyAdmin বা mysql CLI)।
৩. `.env` প্রজেক্ট রুটের এক ধাপ উপরে `App/.env`-এ রাখুন।
