<?php
session_start();
require_once("../config/db.php");
require_once(__DIR__ . '/../core/Company/CompanyRepository.php');

use Core\Company\CompanyRepository;

if (!isset($_SESSION['company_id']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

$company_id = $_SESSION['company_id'];
$companyRepo = new CompanyRepository($conn);

// ✅ جلب بيانات الشركة بأمان
$company = $companyRepo->getById($company_id);

if (!$company) {
    die("Company not found.");
}

require_once(__DIR__ . '/../core/Auth/CsrfHelper.php');
use Core\Auth\CsrfHelper;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'company_profile')) {
        die("Invalid CSRF Token.");
    }

    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $passwordHash = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : null;

    $companyRepo->updateProfile($company_id, $name, $email, $passwordHash);

    $_SESSION['company_name'] = $name;

    $success = "✅ Profile updated successfully!";

    // ✅ إعادة تحميل البيانات بعد التحديث
    $company = $companyRepo->getById($company_id);
}

include("../layouts/header.php");
?>

    <div class="card p-4 col-md-6">

        <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('company_profile'); ?>">

            <div class="mb-3">
                <label>Company Name</label>
                <input type="text"
                       name="name"
                       class="form-control"
                       value="<?php echo htmlspecialchars($company['name'] ?? ''); ?>"
                       required>
            </div>

            <div class="mb-3">
                <label>Email</label>
                <input type="email"
                       name="email"
                       class="form-control"
                       value="<?php echo htmlspecialchars($company['email'] ?? ''); ?>"
                       required>
            </div>

            <div class="mb-3">
                <label>New Password (leave empty if no change)</label>
                <input type="password" name="password" class="form-control">
            </div>

            <button class="btn btn-primary w-100">Update Profile</button>

        </form>

    </div>

<?php include("../layouts/footer.php"); ?>