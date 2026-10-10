# 🛠️ نقشهٔ رفع Bugs و بهبود ربات

## 📋 اولویت‌ها

### مرحلهٔ ۱: رفع CRITICAL BUGS (۲ ساعت)
- [ ] Bug #1: Integer overflow در تبدیل disk quota (Kernel.php:953-959)
- [ ] Bug #2: Race condition در test config auto-delete (Kernel.php:990-1001)
- [ ] Bug #3: SQL injection potential در listAdmins (PasarGuardClient.php:399)

### مرحلهٔ ۲: رفع SECURITY ISSUES (۱.۵ ساعت)
- [ ] Security #1: Crypto key در repo (config.php:65)
- [ ] Security #2: Test super_admins ID (config.php:36)
- [ ] Security #3: Missing rate limiting در WebApp (WebApp/Api.php)

### مرحلهٔ ۳: تکمیل INCOMPLETE FEATURES (۳ ساعت)
- [ ] Feature #1: تنظیمات Pasargad در ربات (دکمه‌ها)
- [ ] Feature #2: Admin bot setup
- [ ] Feature #3: Premium subscription complete flow

### مرحلهٔ ۴: بهبود UI/UX (۲ ساعت)
- [ ] UI #1: پیام‌های بهتر و واضح‌تر
- [ ] UI #2: دکمه‌های بهتر و منسجم
- [ ] UI #3: کمک‌های نمایشی (helper text)

---

## 🔴 CRITICAL BUGS

### Bug #1: Integer Overflow در Disk Quota
**فایل:** `src/Bot/Kernel.php:953-959`
**مسئله:** بدون سقف maximum
**راه‌حل:** افزودن max 10TB check

### Bug #2: Race Condition در Test Config
**فایل:** `src/Bot/Kernel.php:990-1001`
**مسئله:** دو کاربر همزمان می‌توانند update بزنند
**راه‌حل:** افزودن atomic transaction

### Bug #3: SQL Injection
**فایل:** `src/Panel/PasarGuardClient.php:399`
**مسئله:** `http_build_query` safe است اما validation بهتر است
**راه‌حل:** صریح validation

---

## 🟠 SECURITY ISSUES

### Security #1: Crypto Key در Repository
**فایل:** `config.php:65`
**مسئله:** Private key public است!
**راه‌حل:** .env یا environment variable

### Security #2: Test Super Admins
**فایل:** `config.php:36`
**مسئله:** `123456789` test ID است
**راه‌حل:** اخطار و validation

### Security #3: Rate Limiting
**فایل:** `src/WebApp/Api.php`
**مسئله:** بدون rate limiting
**راه‌حل:** افزودن throttling

---

## 🟡 تنظیمات Pasargad در ربات

### دکمه‌های جدید در Settings:
1. **تنظیم اتصال Pasargad**
   - base_url
   - owner_username / owner_password
   - rep_role_id
   
2. **تنظیمات پرداخت**
   - card_number
   - card_owner
   - autocard API
   
3. **تنظیمات عمومی**
   - bot_username
   - support_link
   - admin_chat_id

---

## ✅ QUICK WINS

1. اضافه کردن `store.name` به config.php ✅
2. اضافه کردن `telegram_api_base` ✅
3. اضافه کردن `admin_bot_token` ✅
4. اضافه کردن `autocard` ✅
5. بهبود پیام‌های خطا
6. افزودن validation بهتر
