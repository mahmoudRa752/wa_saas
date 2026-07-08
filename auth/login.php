<?php
session_start();
require_once("../config/db.php");
require_once(__DIR__ . '/../core/Auth/AuthRepository.php');

use Core\Auth\AuthRepository;

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $type     = $_POST['type'];
    $authRepo = new AuthRepository($conn);

    if ($type === "company") {

        $company = $authRepo->findCompanyByEmail($email);

        if ($company) {
            if (password_verify($password, $company['password'])) {
                $_SESSION['company_id']   = $company['id'];
                $_SESSION['company_name'] = $company['name'];
                $_SESSION['role']         = "admin";
                header("Location: ../dashboard/index.php");
                exit;
            } else {
                $error = "Wrong password!";
            }
        } else {
            $error = "Company not found!";
        }

    } else {

        $user = $authRepo->findEmployeeByEmail($email);

        if ($user) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id']      = $user['id'];
                $_SESSION['user_name']    = $user['name'];
                $_SESSION['company_id']   = $user['company_id'];
                $_SESSION['company_name'] = $user['company_name'];
                $_SESSION['role']         = "employee";
                header("Location: ../dashboard/index.php");
                exit;
            } else {
                $error = "Wrong password!";
            }
        } else {
            $error = "Employee not found!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WA Manager — Login</title>
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
        .login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            box-sizing: border-box;
        }
        .login-card {
            width: 100%;
            max-width: 450px;
            background: #ffffff;
            padding: 2.5rem;
            border-radius: 0.75rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            text-align: center;
        }
        .login-logo {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: inline-block;
        }
        .subtitle {
            color: #64748b;
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
        }
        /* تنسيق أزرار التبديل لتبدو احترافية وجنب بعضها */
        .type-toggle {
            display: flex;
            background-color: #f8fafc;
            padding: 0.4rem;
            border-radius: 0.5rem;
            border: 1px solid #e2e8f0;
            gap: 5px;
        }
        .type-toggle input[type="radio"] {
            display: none;
        }
        .type-toggle label {
            flex: 1;
            padding: 0.6rem;
            cursor: pointer;
            border-radius: 0.375rem;
            font-weight: 600;
            color: #64748b;
            transition: all 0.2s;
            margin: 0;
        }
        .type-toggle input[type="radio"]:checked + label {
            background-color: #ffffff;
            color: #2563eb;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>

<div class="login-page">

    <div class="login-card">

        <div class="login-logo">💬</div>

        <h4 class="fw-bold text-dark">Welcome Back</h4>
        <p class="subtitle">Sign in to your WA Manager account</p>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger mb-3 py-2 text-start" style="font-size: 14px;">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="text-start">

            <div class="type-toggle mb-4">
                <input type="radio" name="type" id="type_company" value="company" <?php echo (!isset($_POST['type']) || $_POST['type'] === 'company') ? 'checked' : ''; ?>>
                <label for="type_company" class="text-center">
                    <i class="bi bi-building me-1"></i> Company
                </label>

                <input type="radio" name="type" id="type_employee" value="employee" <?php echo (isset($_POST['type']) && $_POST['type'] === 'employee') ? 'checked' : ''; ?>>
                <label for="type_employee" class="text-center">
                    <i class="bi bi-person me-1"></i> Employee
                </label>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium text-secondary" style="font-size: 14px;">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0" style="border-radius:10px 0 0 10px; border:1.5px solid #e2e8f0;">
                        <i class="bi bi-envelope text-muted"></i>
                    </span>
                    <input type="email" name="email" class="form-control border-start-0"
                           style="border-radius:0 10px 10px 0; border:1.5px solid #e2e8f0;"
                           placeholder="you@example.com"
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-medium text-secondary" style="font-size: 14px;">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0" style="border-radius:10px 0 0 10px; border:1.5px solid #e2e8f0;">
                        <i class="bi bi-lock text-muted"></i>
                    </span>
                    <input type="password" name="password" id="passwordInput"
                           class="form-control border-start-0 border-end-0"
                           style="border:1.5px solid #e2e8f0;"
                           placeholder="••••••••" required>
                    <button type="button" class="btn btn-outline-secondary border-start-0"
                            style="border-radius:0 10px 10px 0; border:1.5px solid #e2e8f0; border-left:none; background: #fff; color: #64748b;"
                            onclick="togglePassword()">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" style="font-size:15px; background-color: #2563eb; border: none;">
                Sign In <i class="bi bi-arrow-right ms-1"></i>
            </button>

        </form>

        <hr style="margin:24px 0; border-color:#e2e8f0;">

        <div class="text-center" style="font-size:13px; color:#64748b;">
            New here?
            <a href="register_company.php" style="color:#2563eb; font-weight:600; text-decoration:none;">
                Register Company
            </a>
            &nbsp;·&nbsp;
            <a href="register_employee.php" style="color:#2563eb; font-weight:600; text-decoration:none;">
                Register Employee
            </a>
        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function togglePassword() {
        const input = document.getElementById('passwordInput');
        const icon  = document.getElementById('eyeIcon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'bi bi-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'bi bi-eye';
        }
    }
</script>
</body>
</html>