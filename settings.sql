-- ============================================================
--  settings টেবিল
--  ------------------------------------------------------------
--  SMS, Email, timezone, session ইত্যাদি টগলযোগ্য/এডিটযোগ্য সব মান
--  এখানে থাকে — কোডে হার্ডকোড নয়। অ্যাডমিন প্যানেল থেকে on/off ও এডিট করা যায়।
--
--  is_enabled = 0 করলে ওই সেটিং "বন্ধ" ধরা হয় এবং কোড তার নিরাপদ
--  ডিফল্টে ফিরে যায় (সার্ভিসের ক্ষেত্রে সার্ভিসটি নিষ্ক্রিয় হয়)।
-- ============================================================

CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key`   VARCHAR(100)  NOT NULL,
    `setting_value` TEXT          NULL,
    `is_enabled`    TINYINT(1)    NOT NULL DEFAULT 1,
    `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  ডিফল্ট সিড — প্রথমবার ইনস্টলের সময়।
--  ইতিমধ্যে থাকলে ডুপ্লিকেট হবে না (INSERT IGNORE)।
-- ============================================================

-- ── সাধারণ সেটিংস ──────────────────────────────────────────
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `is_enabled`) VALUES
    ('timezone',         'Asia/Dhaka', 1),
    ('session_timeout',  '1200',       1),  -- সেকেন্ডে (২০ মিনিট)
    ('session_name',     'HISAB_SID',  1),
    ('site_notice',      '',           0);  -- লগইন পেজের নোটিশ বার (খালি = বন্ধ)

-- ── SMS সার্ভিস ────────────────────────────────────────────
--  master টগল: setting_key='sms', is_enabled=0 → SMS বন্ধ।
--  চালু করতে হলে is_enabled=1 করে নিচের কনফিগ পূরণ করুন।
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `is_enabled`) VALUES
    ('sms',              '',  0),  -- master on/off
    ('sms_api_url',      '',  1),
    ('sms_api_key',      '',  1),
    ('sms_sender_id',    '',  1);

-- ── Email সার্ভিস ──────────────────────────────────────────
--  master টগল: setting_key='email', is_enabled=0 → Email বন্ধ।
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `is_enabled`) VALUES
    ('email',            '',            0),  -- master on/off
    ('email_smtp_host',  '',            1),
    ('email_smtp_port',  '587',         1),
    ('email_smtp_user',  '',            1),
    ('email_smtp_pass',  '',            1),
    ('email_from_name',  'Sada Kalo',   1),
    ('email_from_addr',  '',            1);
