# Customer Module — সাদা কালো ফ্যাশন

সম্পূর্ণ মডিউলার স্ট্রাকচার। সব ফাইল যাবে: `/home/sadakalo/public_html/Customer/`

## ফোল্ডার স্ট্রাকচার

```
Customer/
├── config/
│   ├── bootstrap.php      # সেশন, অথ গার্ড, CSRF, হেডার, DB — সব পেজের এন্ট্রি পয়েন্ট
│   ├── Env.php            # /home/sadakalo/App/.env লোডার
│   ├── Database.php       # PDO কানেকশন (utf8mb4, prepared statements)
│   └── .htaccess          # ডাইরেক্ট এক্সেস ব্লক
├── Controllers/
│   └── CustomerController.php
├── Models/
│   ├── CustomerModelInterface.php
│   └── CustomerModel.php  # শুধু ডাটাবেস লজিক
├── Services/
│   ├── SmsService.php     # MiMSMS — credential আসে .env থেকে
│   └── MailService.php    # Email — recipient আসে .env থেকে
├── Helpers/
│   └── ImageUploader.php  # সিকিউর base64 ইমেজ সেভ/ডিলিট
├── Logs/                  # এরর লগ (.htaccess দিয়ে ব্লকড)
├── uploads/               # ছবি (PHP execution ব্লকড)
├── customers.php          # কাস্টমার লিস্ট পেজ
└── customer_profile.php   # কাস্টমার প্রোফাইল/লেজার পেজ
```

## ইনস্টলেশন

1. পুরো `Customer/` ফোল্ডারটি `public_html/` এর ভিতরে আপলোড করুন।
2. `/home/sadakalo/App/.env` ফাইলে key-গুলো মিলিয়ে নিন — নমুনা: `ENV_EXAMPLE.txt`
   (SMS_KEY / SMS_API_KEY, EMAIL / NOTIFY_EMAIL — দুই নামই কাজ করবে)।
3. পুরনো `uploads/` ফোল্ডারের ছবিগুলো নতুন `Customer/uploads/` এ কপি করুন
   (DB-তে path relative আছে, তাই স্ট্রাকচার একই রাখলেই চলবে)।
4. DB credentials `.env`-এ দিলে `db_connect.php` লাগবে না; না দিলে
   `public_html/db_connect.php` আগের মতোই কাজ করবে।

## সিকিউরিটি ফিক্স (এই ভার্সনে)

- SMS API key/username কোড থেকে সরিয়ে `.env`-এ (আগে hardcoded ছিল)
- Email ঠিকানাও `.env`-এ
- ইমেজ আপলোড: আসল JPEG/PNG/WebP ভেরিফিকেশন, 5MB লিমিট, path-traversal ব্লক
- SMS পাঠানো এখন শুধু admin/manager (আগে যেকোনো লগইন ইউজার পারত)
- Bulk SMS প্রতি ব্যাচে সর্বোচ্চ ৩০ জন
- ট্রানজেকশন: ঋণাত্মক অঙ্ক ব্লক, তারিখ ফরম্যাট ভ্যালিডেশন
- MD5 পাসওয়ার্ড অটো-আপগ্রেড হয়ে bcrypt হবে প্রথম সফল লগইন-ভেরিফাইয়ে
- সেশন: HttpOnly + SameSite কুকি, ৩০ মিনিট পরপর session ID রোটেশন
- Logs/, uploads/, config/ — .htaccess দিয়ে সুরক্ষিত
