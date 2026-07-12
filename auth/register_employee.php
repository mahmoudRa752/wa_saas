<?php
session_start();
require_once("../config/db.php");
require_once(__DIR__ . '/../core/Auth/AuthRepository.php');
require_once(__DIR__ . '/../core/Company/CompanyRepository.php');

use Core\Auth\AuthRepository;
use Core\Company\CompanyRepository;

// إذا كان المستخدم مسجل دخوله بالفعل، يتم توجيهه للـ Dashboard فوراً
if (isset($_SESSION['company_id'])) {
    header("Location: ../dashboard/index.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $company_code = trim($_POST['company_code']);
    $authRepo = new AuthRepository($conn);
    $companyRepo = new CompanyRepository($conn);

    // نبحث عن الشركة بالكود
    $company = $companyRepo->findByCompanyCode($company_code);

    if ($company) {
        $company_id = $company['id'];

        $newId = $authRepo->createEmployee($name, $email, $password, $company_id);

        if ($newId) {

            $_SESSION['user_id'] = $newId;
            $_SESSION['company_id'] = $company_id;
            $_SESSION['user_name'] = $name;
            $_SESSION['role'] = "employee";

            header("Location: ../dashboard/index.php");
            exit;

        } else {
            $error = "Registration failed!";
        }

    } else {
        $error = "Invalid Company Code!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WA Manager — Employee Registration</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/wa_saas/assets/style.css" rel="stylesheet">

    <style>
        body {
            background-color: #f1f5f9;
            font-family: system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 0;
        }
        .register-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            box-sizing: border-box;
        }
        .register-card {
            width: 100%;
            max-width: 480px;
            background: #ffffff;
            padding: 2.5rem;
            border-radius: 0.75rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        .brand-logo-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            text-align: center;
        }
        .form-control {
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.6rem 0.75rem;
        }
        .form-control:focus {
            border-color: #2563eb;
            box-shadow: none;
        }
    </style>
</head>
<body>

<div class="register-page">

    <div class="register-card">

        <div class="brand-logo-icon text-center">💬</div>

        <h4 class="fw-bold text-center text-dark mb-2">Employee Registration</h4>
        <p class="text-muted text-center mb-4" style="font-size: 14px;">Join your company workspace using the invitation code</p>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger mb-3 py-2" style="font-size: 14px;">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST">

            <div class="mb-3">
                <label class="form-label fw-medium text-secondary" style="font-size: 14px;">Full Name</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. John Doe" required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium text-secondary" style="font-size: 14px;">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="name@company.com" required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium text-secondary" style="font-size: 14px;">Password</label>
                <input type="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>

            <div class="mb-4">
                <label class="form-label fw-medium text-secondary" style="font-size: 14px;">Company Code</label>
                <input type="text" name="company_code" class="form-control" placeholder="Enter 8-digit code" required>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" style="font-size:15px; background-color: #2563eb; border: none;">
                Register <i class="bi bi-arrow-right ms-1"></i>
            </button>
        </form>

        <hr style="margin:24px 0; border-color:#e2e8f0;">

        <div class="text-center" style="font-size:13px; color:#64748b;">
            Already have an account?
            <a href="login.php" class="fw-bold text-decoration-none" style="color:#2563eb;">
                Sign In
            </a>
        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>