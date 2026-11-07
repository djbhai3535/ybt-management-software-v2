# DJ CRM Software 5.1 — Complete Installation & Deployment Guide
# ڈی جے سی آر ایم سافٹ ویئر 5.1 — مکمل انسٹالیشن اور تعیناتی گائیڈ

---

## Part 1: English Installation Instructions

### Pre-requisites:
- Hostinger Premium Shared Hosting with PHP 8.2 or 8.3
- MySQL / MariaDB Database created in Hostinger hPanel
- Database Name, Database Username, and Database Password ready
- Gmail App Password for SMTP (generated from Google Account > Security > 2-Step Verification > App Passwords)

---

### Step 1: Uploading the Deployment Package
1. Log in to your Hostinger Control Panel (hPanel).
2. Navigate to **Websites** > click **Manage** for your domain (`apkashow.com`).
3. Open **File Manager** and open the `public_html` directory.
4. If you have an existing `.env` file in `public_html`, take a quick copy of your database credentials and Gmail App Password so you don't lose them.
5. Upload `DJ-CRM-Software-5.1.zip` directly into `public_html`.
6. Right-click `DJ-CRM-Software-5.1.zip` and select **Extract**.
7. Extract the contents directly into `public_html`. (Choose "Replace / Overwrite" if prompted).
8. Once extracted, delete `DJ-CRM-Software-5.1.zip` from `public_html` to save disk space.

---

### Step 2: Database Setup in Hostinger phpMyAdmin
1. In Hostinger hPanel, go to **Databases** > **phpMyAdmin** and click **Enter phpMyAdmin** next to your database (e.g. `u290903472_djcrm`).
2. Click on your database name in the left sidebar.
3. Click the **Import** tab at the top.
4. Click **Choose File** and select `database/dj-crm-5.1-install.sql` (found inside the extracted folder or from your local machine).
5. Click **Go** at the bottom to execute the import.
6. All 47 tables with dual-column compatibility and initial configurations will be created cleanly.

---

### Step 3: Configuring the `.env` File
1. In Hostinger File Manager (`public_html/`), look for `.env`. If it does not exist, rename or copy `.env.example` to `.env`.
2. Right-click `.env` and click **Edit**.
3. Fill in your actual Hostinger credentials:
   ```env
   APP_NAME="DJ CRM Software"
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://apkashow.com
   APP_TIMEZONE=Asia/Karachi

   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=u290903472_djcrm
   DB_USER=YOUR_HOSTINGER_DB_USER
   DB_PASS=YOUR_HOSTINGER_DB_PASSWORD
   DB_CHARSET=utf8mb4

   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=465
   SMTP_USERNAME=your_gmail_address@gmail.com
   SMTP_PASSWORD=your_16_digit_gmail_app_password
   MAIL_FROM_ADDRESS=your_gmail_address@gmail.com
   MAIL_FROM_NAME="DJ CRM Software"

   CLOUD_SYNC_URL=https://apkashow.com
   WHATSAPP_SUPPORT_NUMBER=923000000000
   YBT_ENCRYPTION_KEY=d7b5f082e6194ca4b8f5209c183de2b4
   ```
4. Click **Save** and close the editor.

---

### Step 4: Storage Permissions & Verification
1. In `public_html`, ensure the `storage/` folder exists along with its subdirectories:
   - `storage/logs/`
   - `storage/uploads/`
   - `storage/backups/`
2. Ensure permissions on `storage/` are set to `755`.
3. Open `https://apkashow.com` in your browser.
4. Test Registration (`https://apkashow.com/register.php`) and Login (`https://apkashow.com/login.php`).
5. Check your Dashboard (`https://apkashow.com/dashboard.php`).

---
---

## حصہ 2: آسان اردو میں انسٹالیشن گائیڈ (Urdu Guide)

### ضروری معلومات جو آپ کے پاس ہونی چاہئیں:
1. ہوسٹنگر پر بنایا گیا MySQL ڈیٹا بیس کا نام، یوزر نیم، اور پاس ورڈ۔
2. جی میل کا 16 ہندسوں والا ایپ پاس ورڈ (Gmail 16-digit App Password)۔
3. `DJ-CRM-Software-5.1.zip` فائل۔

---

### مرحلہ 1: زپ فائل اپلوڈ اور ایکسٹریکٹ کرنا
1. سب سے پہلے ہوسٹنگر (Hostinger hPanel) میں لاگ ان کریں۔
2. اپنے ڈومین (`apkashow.com`) کے سامنے **Manage** پر کلک کریں۔
3. بائیں جانب مینو سے **File Manager** کھولیں اور `public_html` فولڈر کے اندر جائیں۔
4. اگر آپ کی پرانی `.env` فائل موجود ہے تو اس کے اندر سے اپنے ڈیٹا بیس کی تفصیلات محفوظ رکھ لیں۔
5. `DJ-CRM-Software-5.1.zip` کو `public_html` میں اپلوڈ کریں۔
6. اپلوڈ مکمل ہونے پر فائل پر رائٹ کلک کریں اور **Extract** منتخب کریں۔
7. فائلیں `public_html` کے اندر ہی ایکسٹریکٹ کریں۔ اگر کوئی فائل پہلے سے موجود ہو تو **Replace / Overwrite** کو منتخب کریں۔
8. ایکسٹریکٹ ہونے کے بعد زپ فائل ڈیلیٹ کر دیں تاکہ ہوسٹنگ پر اضافی جگہ نہ گھیرے۔

---

### مرحلہ 2: ڈیٹا بیس امپورٹ کرنا (phpMyAdmin)
1. ہوسٹنگر hPanel میں **Databases** پر جائیں اور اپنے ڈیٹا بیس (مثلاً `u290903472_djcrm`) کے سامنے **phpMyAdmin** پر کلک کریں۔
2. بائیں جانب اپنے ڈیٹا بیس کے نام پر کلک کریں۔
3. اوپر موجود **Import** والے ٹیب پر کلک کریں۔
4. **Choose File** پر کلک کریں اور `database/dj-crm-5.1-install.sql` فائل منتخب کریں۔
5. سب سے نیچے جا کر **Go** کے بٹن پر کلک کریں۔
6. تمام 47 ٹیبلز کامیابی کے ساتھ بن جائیں گے اور ڈوئل کالم سپورٹ فعال ہو جائے گی۔

---

### مرحلہ 3: `.env` فائل ترتیب دینا
1. فائل مینیجر میں `public_html` کے اندر `.env` فائل تلاش کریں۔ اگر موجود نہ ہو تو `.env.example` کا نام تبدیل کر کے `.env` رکھ دیں۔
2. `.env` فائل کو ایڈٹ (Edit) کریں اور اپنی اصل تفصیلات درج کریں:
   - `DB_HOST=localhost`
   - `DB_NAME=آپ کے ڈیٹا بیس کا نام`
   - `DB_USER=آپ کے ڈیٹا بیس کا یوزر نیم`
   - `DB_PASS=آپ کا ڈیٹا بیس پاس ورڈ`
   - `SMTP_USERNAME=آپ کا جی میل ایڈریس`
   - `SMTP_PASSWORD=آپ کا 16 ہندسوں کا گوگل ایپ پاس ورڈ`
   - `MAIL_FROM_ADDRESS=آپ کا جی میل ایڈریس`
3. فائل کو **Save** کر دیں۔

---

### مرحلہ 4: لائیو ٹیسٹنگ اور تصدیق
1. براؤزر میں جا کر ویب سائٹ کھولیں: `https://apkashow.com`
2. نیا اکاؤنٹ رجسٹر کریں: `https://apkashow.com/register.php`
3. لاگ ان کریں اور ڈیش بورڈ چیک کریں: `https://apkashow.com/dashboard.php`
4. مبارک ہو! آپ کا DJ CRM Software 5.1 کامیابی کے ساتھ لائیو اور مکمل طور پر فعال ہو چکا ہے۔
