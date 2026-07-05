# WA Manager — System Architecture & Technical Specification Document

> **Version:** 1.0  
> **Date:** 2025  
> **Purpose:** مرجع هندسي ثابت لمواصلة تطوير المشروع بأي أداة ذكاء اصطناعي دون التسبب في تعارض أو انهيار في الكود.

---

## 1. System Overview — نظرة عامة على النظام

**WA Manager** منصة SaaS متعددة المستأجرين (Multi-Tenant) مبنية بـ Vanilla PHP تتيح للشركات إرسال رسائل واتساب جماعية وفردية عبر **WhatsApp Cloud API (Meta Graph API v19.0)**.

### فكرة العمل الأساسية

```
شركة (Admin) تسجل → تحصل على Company Code
    ↓
موظفون يسجلون بالـ Company Code → ينضمون لنفس الشركة
    ↓
كل موظف يُربط بـ Phone Number ID من WhatsApp Cloud API
    ↓
الإرسال يتم من رقم الموظف عبر API → يُسجَّل في جدول messages
    ↓
الشركة تشترك في باقة → تحدد الحد الشهري للرسائل
```

### التقنيات المستخدمة

| التقنية | الإصدار | الاستخدام |
|---|---|---|
| PHP | 7.4+ | Backend كامل (Vanilla, بدون Framework) |
| MySQL | 5.7+ | قاعدة البيانات |
| Bootstrap | 5.3.2 | CSS Framework للـ Dashboard |
| Bootstrap Icons | 1.11.3 | أيقونات الواجهة |
| PhpSpreadsheet | latest | قراءة ملفات Excel (.xlsx) |
| WhatsApp Cloud API | v19.0 | إرسال الرسائل |
| XAMPP | - | بيئة التطوير المحلية (Port: 3307) |

---

## 2. Folder & Directory Structure — بنية المجلدات

```
wa_saas/
│
├── assets/
│   └── style.css                  ← ملف CSS الوحيد للمشروع (Dashboard فقط)
│
├── auth/                          ← صفحات المصادقة (Standalone - مستقلة تماماً)
│   ├── login.php                  ← تسجيل دخول (Company + Employee في نفس الصفحة)
│   ├── register_company.php       ← تسجيل شركة جديدة
│   ├── register_employee.php      ← تسجيل موظف بكود الشركة
│   └── logout.php                 ← تدمير الـ Session والتوجيه لـ login.php
│
├── config/
│   └── db.php                     ← اتصال MySQL + تعريف WHATSAPP_TOKEN constant
│
├── dashboard/                     ← جميع صفحات لوحة التحكم (تعتمد على layouts)
│   ├── index.php                  ← الصفحة الرئيسية (KPI Cards + Usage + Recent)
│   ├── send.php                   ← إرسال رسالة فردية أو عبر Excel
│   ├── bulk_send.php              ← إرسال جماعي عبر Excel مع Template fallback
│   ├── employees.php              ← إدارة الموظفين (Admin فقط)
│   ├── whatsapp_numbers.php       ← ربط أرقام واتساب بالموظفين
│   ├── upgrade.php                ← اختيار وتفعيل باقة اشتراك (Admin فقط)
│   ├── company_profile.php        ← تعديل بيانات الشركة (Admin فقط)
│   └── user_profile.php           ← تعديل بيانات الموظف (Employee فقط)
│
├── layouts/                       ← القوالب المشتركة للـ Dashboard
│   ├── header.php                 ← HTML head + Sidebar + Topbar + فتح page-body div
│   └── footer.php                 ← إغلاق divs + Bootstrap JS + Chart.js
│
├── uploads/
│   └── logos/                     ← لوجوهات الشركات المرفوعة
│
├── vendor/                        ← Composer dependencies (PhpSpreadsheet)
│   └── autoload.php
│
├── download_template.php          ← توليد وتحميل ملف Excel نموذجي
├── composer.json
└── .env.example                   ← مثال لمتغيرات البيئة
```

---

## 3. Database Schema — مخطط قاعدة البيانات

> اسم قاعدة البيانات: `wa_saas` | Port: `3307`

### 3.1 جدول `companies`

```sql
CREATE TABLE companies (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(255) NOT NULL,
    email        VARCHAR(255) NOT NULL UNIQUE,
    password     VARCHAR(255) NOT NULL,          -- bcrypt (password_hash)
    company_code VARCHAR(20)  NOT NULL UNIQUE,   -- مثال: PORTO123
    logo         VARCHAR(255) DEFAULT NULL,      -- اسم الملف فقط (بدون path)
    plan_id      INT          DEFAULT NULL,      -- FK → plans.id
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);
```

### 3.2 جدول `users` (الموظفون)

```sql
CREATE TABLE users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT          NOT NULL,            -- FK → companies.id
    name       VARCHAR(255) NOT NULL,
    email      VARCHAR(255) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,            -- bcrypt
    role       ENUM('admin','employee') DEFAULT 'employee',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);
```

> **ملاحظة:** الـ `admin` في هذا الجدول غير مستخدم حالياً — الـ admin الفعلي هو صاحب الشركة في جدول `companies`.

### 3.3 جدول `whatsapp_numbers`

```sql
CREATE TABLE whatsapp_numbers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL UNIQUE, -- FK → users.id (علاقة 1:1)
    phone_number_id VARCHAR(100) NOT NULL,        -- Phone Number ID من Meta Dashboard
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### 3.4 جدول `plans`

```sql
CREATE TABLE plans (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,          -- مثال: Starter, Pro, Enterprise
    price         DECIMAL(10,2) NOT NULL,
    monthly_limit INT NOT NULL,                   -- عدد الرسائل المسموح بها شهرياً
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 3.5 جدول `subscriptions`

```sql
CREATE TABLE subscriptions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT  NOT NULL,                     -- FK → companies.id
    plan_id    INT  NOT NULL,                     -- FK → plans.id
    start_date DATE NOT NULL,
    end_date   DATE NOT NULL,
    status     ENUM('active','expired','cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id)    REFERENCES plans(id)
);
```

### 3.6 جدول `messages`

```sql
CREATE TABLE messages (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id   INT          NOT NULL,              -- FK → users.id (الموظف المُرسِل)
    recipient VARCHAR(50)  NOT NULL,              -- رقم الهاتف (أرقام فقط، بدون +)
    message   TEXT         DEFAULT NULL,
    status    ENUM('sent','failed') DEFAULT 'sent',
    sent_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### 3.7 علاقات قاعدة البيانات (ERD Summary)

```
companies (1) ──── (N) users
companies (1) ──── (N) subscriptions
companies (1) ──── (1) plans  [عبر companies.plan_id]
plans     (1) ──── (N) subscriptions
users     (1) ──── (1) whatsapp_numbers
users     (1) ──── (N) messages
```

---

## 4. Session Variables — متغيرات الجلسة

### Admin (صاحب الشركة) — بعد تسجيل الدخول من `companies`

```php
$_SESSION['company_id']   = (int)    // companies.id
$_SESSION['company_name'] = (string) // companies.name
$_SESSION['role']         = "admin"
// ملاحظة: $_SESSION['user_id'] غير موجود للـ admin
```

### Employee (الموظف) — بعد تسجيل الدخول من `users`

```php
$_SESSION['user_id']      = (int)    // users.id
$_SESSION['user_name']    = (string) // users.name
$_SESSION['company_id']   = (int)    // users.company_id
$_SESSION['company_name'] = (string) // companies.name (من JOIN)
$_SESSION['role']         = "employee"
```

### التحقق من الصلاحيات في كل صفحة

```php
// حماية أي صفحة dashboard
if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// حماية صفحات Admin فقط
if (!isset($_SESSION['company_id']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

// حماية صفحات Employee فقط
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'employee') {
    header("Location: index.php");
    exit;
}
```

---

## 5. UI/UX Styling Rules — قواعد التصميم الصارمة

### 5.1 نوعان من الصفحات — قاعدة لا تُكسر

#### النوع الأول: صفحات Auth (مستقلة تماماً — Standalone)

```
auth/login.php
auth/register_company.php
auth/register_employee.php
```

**القواعد:**
- ✅ كل صفحة تحتوي على `<!DOCTYPE html>` كاملة بداخلها
- ✅ تستخدم `<style>` داخلي (Inline CSS) لتنسيق الكارت المركزي
- ✅ تستدعي Bootstrap 5.3.2 و Bootstrap Icons مباشرة من CDN
- ✅ **لا تستدعي** `layouts/header.php` أو `layouts/footer.php` أبداً
- ✅ **لا تعتمد** على `assets/style.css` للـ layout الأساسي
- ✅ الهدف: عزل صفحات الدخول تماماً عن أي تغيير في ملفات الـ Dashboard

#### النوع الثاني: صفحات Dashboard (تعتمد على layouts)

```
dashboard/index.php
dashboard/send.php
dashboard/bulk_send.php
dashboard/employees.php
dashboard/whatsapp_numbers.php
dashboard/upgrade.php
dashboard/company_profile.php
dashboard/user_profile.php
```

**القواعد:**
- ✅ تبدأ بـ PHP logic (session, DB queries)
- ✅ تستدعي `include("../layouts/header.php")` قبل أي HTML
- ✅ تضع محتوى الصفحة مباشرة بعد الـ header
- ✅ تنتهي بـ `include("../layouts/footer.php")`
- ✅ **لا تحتوي** على `<!DOCTYPE html>` أو `<head>` أو `<body>` — هذه في الـ header

### 5.2 HTML Layout Structure للـ Dashboard

```html
<!-- header.php يُنتج هذا: -->
<aside class="sidebar">          <!-- fixed, width: 260px -->
    ...
</aside>

<div class="main-content">       <!-- margin-left: 260px -->
    <div class="topbar">...</div>
    <div class="page-body fade-in">

        <!-- محتوى الصفحة هنا -->

    </div><!-- /page-body -->
</div><!-- /main-content -->

<!-- footer.php يُغلق هذا -->
```

### 5.3 CSS Variables الأساسية (assets/style.css)

```css
:root {
    --primary:    #6366f1;   /* اللون الأساسي البنفسجي */
    --primary-dk: #4f46e5;   /* الأغمق */
    --primary-lt: #e0e7ff;   /* الأفتح */
    --danger:     #ef4444;
    --success:    #10b981;
    --warning:    #f59e0b;
    --sidebar-w:  260px;     /* عرض الـ Sidebar — لا تغيره */
    --sidebar-bg: #0f172a;   /* خلفية الـ Sidebar الداكنة */
    --bg:         #f1f5f9;   /* خلفية الصفحة */
    --border:     #e2e8f0;
    --radius:     14px;
}
```

### 5.4 CSS Classes المهمة

| Class | الاستخدام |
|---|---|
| `.sidebar` | القائمة الجانبية (position: fixed) |
| `.main-content` | المحتوى الرئيسي (margin-left: 260px) |
| `.topbar` | الشريط العلوي (position: sticky) |
| `.page-body` | منطقة المحتوى (padding: 28px) |
| `.kpi-card` | بطاقات الإحصائيات |
| `.kpi-purple/blue/green/orange` | ألوان بطاقات KPI |
| `.table-custom` | جداول البيانات المخصصة |
| `.badge-success/danger/warning` | شارات الحالة |
| `.plan-card` | بطاقات الباقات |
| `.fade-in` | animation عند تحميل الصفحة |

### 5.5 CDN Links المستخدمة (ثابتة — لا تغيرها)

```html
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/wa_saas/assets/style.css" rel="stylesheet">
<!-- JS في footer.php -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
```

---

## 6. Core Logic & Integrations — الربط البرمجي

### 6.1 WhatsApp Cloud API — آلية الإرسال

**Endpoint:**
```
POST https://graph.facebook.com/v19.0/{phone_number_id}/messages
Authorization: Bearer {WHATSAPP_TOKEN}
Content-Type: application/json
```

**الـ Token:** مُعرَّف كـ PHP constant في `config/db.php`:
```php
define("WHATSAPP_TOKEN", getenv("WHATSAPP_TOKEN") ?: "<your_token_here>");
```

**دالة الإرسال الأساسية (في send.php):**
```php
function sendWhatsApp($phoneNumberId, $recipient, $message, $accessToken) {
    global $conn;
    $url = "https://graph.facebook.com/v19.0/$phoneNumberId/messages";
    $payload = [
        "messaging_product" => "whatsapp",
        "to"   => $recipient,   // أرقام فقط بدون + (مثال: 966501234567)
        "type" => "text",
        "text" => ["body" => $message]
    ];
    // cURL POST → يُسجَّل في جدول messages بعد الإرسال
}
```

**Template Fallback (في bulk_send.php):**
```
إذا فشل الإرسال النصي (HTTP != 200)
    → يُرسَل template "hello_world" (en_US) كبديل تلقائي
    → يُسجَّل في messages بنص "[Template: hello_world]"
```

### 6.2 Excel Processing — قراءة ملفات الإكسيل

**المكتبة:** `PhpOffice\PhpSpreadsheet\IOFactory`

**آلية تعويض المتغيرات الديناميكية:**
```php
$spreadsheet = IOFactory::load($_FILES['file']['tmp_name']);
$sheet  = $spreadsheet->getActiveSheet();
$rows   = $sheet->toArray();
$headers = $rows[0]; // الصف الأول = أسماء الأعمدة

foreach ($rows as $index => $row) {
    if ($index == 0) continue; // تخطي الـ header
    $recipient = preg_replace('/[^0-9]/', '', $row[0]); // العمود الأول = الرقم
    $message   = $_POST['message']; // نص الرسالة من الـ Textarea

    // تعويض {column_name} بقيمة الخلية المقابلة
    foreach ($headers as $key => $columnName) {
        $message = str_replace("{" . trim($columnName) . "}", $row[$key] ?? "", $message);
    }
}
```

**بنية ملف Excel النموذجي (download_template.php):**
```
| number        | name  | company       | message        |
|---------------|-------|---------------|----------------|
| 9665XXXXXXX   | أحمد  | Porto Academy | أهلاً {name}   |
```

**المتغيرات المدعومة في الرسائل:**
- `{name}` — من عمود name في Excel أو من `$_SESSION['user_name']`
- `{company}` — من عمود company في Excel أو من `$_SESSION['company_name']`
- `{أي_اسم_عمود}` — أي عمود في ملف Excel يمكن استخدامه كمتغير

### 6.3 Company Code Generation

```php
$prefix       = strtoupper(substr(preg_replace("/[^A-Za-z]/", "", $name), 0, 5));
$random       = rand(100, 999);
$company_code = $prefix . $random; // مثال: PORTO123
```

### 6.4 Subscription & Limit Check

```php
// جلب الحد الشهري من plans عبر companies.plan_id
SELECT p.monthly_limit FROM companies c JOIN plans p ON c.plan_id = p.id WHERE c.id = ?

// عدد الرسائل المُرسَلة هذا الشهر
SELECT COUNT(*) FROM messages m JOIN users u ON m.user_id = u.id
WHERE u.company_id = ? AND MONTH(m.sent_at) = MONTH(CURRENT_DATE())

// التحقق قبل الإرسال
if ($used >= $limit) { $error = "Monthly limit reached."; }
```

---

## 7. Future Development Rules — قواعد التطوير المستقبلي

> **⚠️ هذه القواعد إلزامية لأي مطور أو أداة ذكاء اصطناعي تعمل على هذا المشروع.**

### 7.1 قواعد الملفات والمجلدات

```
✅ يجب الحفاظ على هيكل المجلدات الحالي كما هو
✅ الملفات الجديدة في dashboard/ يجب أن تستخدم header.php و footer.php
✅ الملفات الجديدة في auth/ يجب أن تكون Standalone مع Inline CSS
❌ لا تنشئ مجلدات جديدة في الجذر دون ضرورة موثقة
❌ لا تعدل vendor/ أو composer.json مباشرة
```

### 7.2 قواعد قاعدة البيانات

```
✅ أسماء الجداول الحالية ثابتة: companies, users, whatsapp_numbers, messages, plans, subscriptions
✅ أسماء الحقول الحالية ثابتة — لا تُعيد تسميتها
✅ أي جدول جديد يجب أن يرتبط بـ company_id لضمان Multi-Tenancy
✅ استخدم Prepared Statements دائماً (mysqli prepare/bind_param)
❌ لا تستخدم query() المباشرة مع متغيرات المستخدم
❌ لا تحذف أو تعدل الحقول الموجودة في الجداول الحالية
```

### 7.3 قواعد الـ Session

```
✅ تحقق دائماً من $_SESSION['company_id'] لحماية صفحات Dashboard
✅ تحقق من $_SESSION['role'] لتمييز Admin عن Employee
✅ الـ admin لا يملك $_SESSION['user_id'] — تحقق بـ isset() قبل الاستخدام
❌ لا تضف session variables جديدة دون توثيقها هنا
```

### 7.4 قواعد CSS والتصميم

```
✅ أضف styles جديدة في نهاية assets/style.css فقط
✅ استخدم CSS Variables الموجودة (--primary, --sidebar-w, إلخ)
✅ أي component جديد يجب أن يتوافق مع نظام الألوان الحالي
❌ لا تعدل .sidebar أو .main-content أو --sidebar-w
   (هذه تتحكم في الـ Layout الأساسي وأي تعديل ينهار الصفحة)
❌ لا تضف position: fixed أو position: absolute لعناصر جديدة
   دون التأكد من عدم تعارضها مع الـ sidebar
```

### 7.5 إضافة ميزات جديدة — الطريقة الصحيحة

#### إضافة صفحة Dashboard جديدة (مثال: reports.php)

```php
<?php
session_start();
require_once("../config/db.php");

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// ... PHP logic هنا ...

include("../layouts/header.php");
?>

<!-- HTML content هنا -->

<?php include("../layouts/footer.php"); ?>
```

ثم أضف الرابط في `layouts/header.php` داخل `<nav class="sidebar-nav">`:
```php
<a href="/wa_saas/dashboard/reports.php"
   class="nav-link <?php echo $currentPage === 'reports.php' ? 'active' : ''; ?>">
    <span class="nav-icon"><i class="bi bi-bar-chart-fill"></i></span> Reports
</a>
```

وأضف العنوان في مصفوفة `$titles` في header.php:
```php
'reports.php' => 'Reports',
```

#### إضافة Webhook (ميزة مستقبلية)

```
✅ أنشئ مجلد جديد: /wa_saas/webhook/
✅ الملف الرئيسي: webhook/receive.php (لا يستخدم layouts)
✅ يجب أن يتحقق من X-Hub-Signature-256 من Meta
✅ يُسجَّل البيانات في جدول جديد: incoming_messages
   مع حقل company_id للحفاظ على Multi-Tenancy
❌ لا تعدل جدول messages الحالي لاستيعاب الرسائل الواردة
```

#### إضافة Live Chat (ميزة مستقبلية)

```
✅ أنشئ: /wa_saas/dashboard/chat.php (يستخدم layouts)
✅ أنشئ جدول: conversations (id, company_id, contact_number, last_message_at)
✅ أنشئ جدول: chat_messages (id, conversation_id, direction ENUM('in','out'), body, sent_at)
✅ استخدم Polling أو WebSocket منفصل في /wa_saas/api/
❌ لا تدمج منطق الـ Chat مع جدول messages الحالي
```

---

## 8. Known Issues & Technical Debt

| المشكلة | الملف | الأولوية |
|---|---|---|
| `send.php` يستخدم `$_SESSION['user_id']` في `sendWhatsApp()` لكن الـ admin لا يملكه | `dashboard/send.php` | عالية |
| `whatsapp_numbers.php` يستخدم `SELECT *` بدلاً من تحديد الحقول | `dashboard/whatsapp_numbers.php` | منخفضة |
| لا يوجد CSRF protection على الـ forms | جميع صفحات dashboard | عالية |
| الـ WHATSAPP_TOKEN مُعرَّف في db.php (يجب نقله لـ .env منفصل) | `config/db.php` | متوسطة |
| `bulk_send.php` لا يتحقق من نوع الملف المرفوع | `dashboard/bulk_send.php` | متوسطة |
| لا يوجد Rate Limiting على الإرسال | `dashboard/send.php`, `bulk_send.php` | متوسطة |

---

## 9. Local Development Setup

```bash
# متطلبات البيئة
- XAMPP (Apache + MySQL)
- PHP 7.4+
- MySQL Port: 3307 (غير القياسي 3306)
- Project Path: C:\xampp\htdocs\wa_saas\
- URL: http://localhost/wa_saas/

# تثبيت الـ Dependencies
cd C:\xampp\htdocs\wa_saas
composer install

# قاعدة البيانات
- اسم قاعدة البيانات: wa_saas
- المستخدم: root
- كلمة المرور: (فارغة)
- Port: 3307
```

---

*هذا المستند مرجع هندسي ثابت — يجب تحديثه عند إضافة أي ميزة جديدة أو تغيير في البنية.*
