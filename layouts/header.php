<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once(__DIR__ . "/../config/db.php");
require_once(__DIR__ . '/../core/Company/CompanyRepository.php');

use Core\Company\CompanyRepository;

$companyLogo = null;
if (isset($_SESSION['company_id'])) {
    if ($_SESSION['role'] === 'admin' && !isset($_SESSION['user_id'])) {
        $stmt = $conn->prepare("SELECT id, name FROM users WHERE company_id = ? AND role = 'admin' LIMIT 1");
        $cid = (int)$_SESSION['company_id'];
        $stmt->bind_param("i", $cid);
        $stmt->execute();
        $adminUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$adminUser) {
            $stmt = $conn->prepare("SELECT email, password, name FROM companies WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $cid);
            $stmt->execute();
            $comp = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($comp) {
                $adminName = $comp['name'] . " (Admin)";
                $adminEmail = $comp['email'];
                $adminPass = $comp['password'];
                $roleAdmin = 'admin';
                $stmt = $conn->prepare("INSERT INTO users (company_id, name, email, password, role) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("issss", $cid, $adminName, $adminEmail, $adminPass, $roleAdmin);
                $stmt->execute();
                $_SESSION['user_id'] = $conn->insert_id;
                $_SESSION['user_name'] = $adminName;
                $stmt->close();
            }
        } else {
            $_SESSION['user_id'] = $adminUser['id'];
            $_SESSION['user_name'] = $adminUser['name'];
        }
    }

    $companyRepo = new CompanyRepository($conn);
    $row = $companyRepo->getById((int)$_SESSION['company_id']);
    $companyLogo = $row['logo'] ?? null;
}
$currentPage = basename($_SERVER['PHP_SELF']);
$titles = [
        'index.php'            => 'Dashboard',
        'send.php'             => 'Send Message',
        'broadcasts.php'       => 'Broadcast Campaigns',
        'automations.php'      => 'Auto-Replies & Automation',
        'chat.php'             => 'Live Chat',
        'search.php'           => 'Advanced Search',
        'internal_chat.php'    => 'Internal Chat',
        'employees.php'        => 'Employees',
        'employee_performance.php' => 'Employee Performance',
        'audit_logs.php'       => 'Audit Logs',
        'settings.php'         => 'Settings',
        'upgrade.php'          => 'Upgrade Plan',
        'user_profile.php'     => 'My Profile',
];
$pageTitle = $titles[$currentPage] ?? 'WA Manager';
?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>WA Manager — <?php echo $pageTitle; ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

        <link href="/wa_saas/assets/style.css" rel="stylesheet">

        <style>
            body {
                background-color: #f8f9fa;
                font-family: system-ui, -apple-system, sans-serif;
                margin: 0;
                padding: 0;
                min-height: 100vh;
            }

            /* ═══ تنسيقات لوحة التحكم (عند وجود جلسة نشطة) ═══ */
            <?php if (isset($_SESSION['company_id'])): ?>
            .app-wrapper {
                display: flex;
                min-height: 100vh;
                width: 100%;
            }
            .sidebar {
                width: 260px;
                background-color: #1e293b;
                color: #ffffff;
                display: flex;
                flex-direction: column;
                position: fixed;
                top: 0;
                bottom: 0;
                left: 0;
                z-index: 1000;
                padding: 1.5rem 1rem;
            }
            .sidebar-brand {
                padding-bottom: 1.5rem;
                border-bottom: 1px solid #334155;
                margin-bottom: 1.5rem;
                text-align: center;
            }
            .brand-logo {
                max-width: 80px;
                height: auto;
                margin-bottom: 0.5rem;
            }
            .brand-icon {
                font-size: 2.5rem;
                margin-bottom: 0.5rem;
            }
            .role-badge {
                font-size: 0.75rem;
                background-color: #3b82f6;
                padding: 0.2rem 0.6rem;
                border-radius: 50px;
                text-transform: uppercase;
                display: inline-block;
            }
            .sidebar-nav {
                flex: 1;
                overflow-y: auto;
            }
            .nav-section-title {
                font-size: 0.75rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #64748b;
                margin-top: 1.25rem;
                margin-bottom: 0.5rem;
                padding-left: 0.5rem;
            }
            .sidebar-nav .nav-link {
                color: #cbd5e1;
                display: flex;
                align-items: center;
                padding: 0.7rem 0.75rem;
                border-radius: 0.375rem;
                text-decoration: none;
                margin-bottom: 0.25rem;
                transition: all 0.2s;
            }
            .sidebar-nav .nav-link:hover, .sidebar-nav .nav-link.active {
                background-color: #334155;
                color: #ffffff;
            }
            .nav-icon {
                margin-right: 0.75rem;
                font-size: 1.1rem;
            }
            .sidebar-footer {
                padding-top: 1rem;
                border-top: 1px solid #334155;
            }
            .logout-btn {
                color: #ef4444;
                text-decoration: none;
                display: flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.5rem;
            }
            .main-content {
                flex: 1;
                margin-left: 260px;
                display: flex;
                flex-direction: column;
                min-width: 0;
                background-color: #f8f9fa;
            }
            .topbar {
                background-color: #ffffff;
                height: 60px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 0 2rem;
                border-bottom: 1px solid #e2e8f0;
            }
            .topbar-title {
                font-size: 1.25rem;
                font-weight: 600;
                color: #0f172a;
            }
            .topbar-avatar {
                width: 35px;
                height: 35px;
                background-color: #cbd5e1;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: bold;
                color: #334155;
            }
            .page-body {
                padding: 2rem;
                flex: 1;
            }

            /* ═══ تنسيقات خاصة بصفحات الـ Auth (خارج لوحة التحكم) ═══ */
            <?php else: ?>
            .auth-wrapper {
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background-color: #f1f5f9 !important;
                width: 100vw;
                padding: 2rem 1rem;
                box-sizing: border-box;
            }
            .auth-container {
                width: 100%;
                max-width: 480px !important; /* يجبر الصندوق على ألا يتمدد نهائياً */
                background: #ffffff;
                padding: 2.5rem;
                border-radius: 0.75rem;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
                box-sizing: border-box;
            }
            /* ضبط المدخلات والأزرار داخل صفحة اللوجن تلقائياً من الهيدر */
            .auth-container form .form-control,
            .auth-container form .input-group {
                max-width: 100% !important;
            }
            <?php endif; ?>
        </style>
    </head>
<body>

<?php if (isset($_SESSION['company_id'])): ?>
    <div class="app-wrapper">

    <aside class="sidebar">
        <div class="sidebar-brand">
            <?php if ($companyLogo): ?>
                <img src="/wa_saas/uploads/logos/<?php echo htmlspecialchars($companyLogo); ?>" class="brand-logo" alt="logo">
            <?php else: ?>
                <div class="brand-icon">💬</div>
            <?php endif; ?>
            <h6 class="mt-2 mb-1 text-white"><?php echo htmlspecialchars($_SESSION['company_name'] ?? 'Company'); ?></h6>
            <span class="role-badge"><?php echo ucfirst($_SESSION['role'] ?? 'User'); ?></span>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-title">Main</div>
            <a href="/wa_saas/dashboard/index.php" class="nav-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="bi bi-grid-1x2-fill"></i></span> Dashboard
            </a>
            <a href="/wa_saas/dashboard/send.php" class="nav-link <?php echo $currentPage === 'send.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="bi bi-send-fill"></i></span> Send Message
            </a>
            <a href="/wa_saas/dashboard/broadcasts.php" class="nav-link <?php echo $currentPage === 'broadcasts.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="bi bi-broadcast"></i></span> Broadcast Campaigns
            </a>
            <a href="/wa_saas/dashboard/automations.php" class="nav-link <?php echo $currentPage === 'automations.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="bi bi-robot"></i></span> Auto-Replies
            </a>
            <a href="/wa_saas/dashboard/chat.php" class="nav-link <?php echo $currentPage === 'chat.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="bi bi-chat-dots-fill"></i></span> Live Chat
            </a>
            <a href="/wa_saas/dashboard/search.php" class="nav-link <?php echo $currentPage === 'search.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="bi bi-search"></i></span> Advanced Search
            </a>
            <a href="/wa_saas/dashboard/internal_chat.php" class="nav-link <?php echo $currentPage === 'internal_chat.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="bi bi-chat-left-text-fill"></i></span> Internal Chat
            </a>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <div class="nav-section-title">Management</div>
                <a href="/wa_saas/dashboard/employees.php" class="nav-link <?php echo $currentPage === 'employees.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="bi bi-people-fill"></i></span> Employees
                </a>
                <a href="/wa_saas/dashboard/employee_performance.php" class="nav-link <?php echo $currentPage === 'employee_performance.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="bi bi-bar-chart-line-fill"></i></span> Performance
                </a>
                <a href="/wa_saas/dashboard/audit_logs.php" class="nav-link <?php echo $currentPage === 'audit_logs.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="bi bi-shield-check"></i></span> Audit Logs
                </a>
                <a href="/wa_saas/dashboard/upgrade.php" class="nav-link <?php echo $currentPage === 'upgrade.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="bi bi-lightning-charge-fill"></i></span> Upgrade Plan
                </a>
                <div class="nav-section-title">Settings</div>
                <a href="/wa_saas/dashboard/settings.php" class="nav-link <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="bi bi-gear-fill"></i></span> System Settings
                </a>
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'employee'): ?>
                <div class="nav-section-title">Account</div>
                <a href="/wa_saas/dashboard/user_profile.php" class="nav-link <?php echo $currentPage === 'user_profile.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="bi bi-person-bounding-box"></i></span> My Profile
                </a>
                <a href="/wa_saas/dashboard/settings.php" class="nav-link <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>">
                    <span class="nav-icon"><i class="bi bi-gear-fill"></i></span> Preferences & Roles
                </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <a href="/wa_saas/auth/logout.php" class="logout-btn">
                <i class="bi bi-box-arrow-left"></i> Logout
            </a>
        </div>
    </aside>
    <div class="main-content">
    <div class="topbar">
        <span class="topbar-title"><?php echo $pageTitle; ?></span>
        <div class="topbar-avatar">
            <?php echo strtoupper(substr($_SESSION['company_name'] ?? 'U', 0, 1)); ?>
        </div>
    </div>
    <div class="page-body">

<?php else: ?>

    <div class="auth-wrapper">
        <div class="auth-container">

<?php endif; ?>