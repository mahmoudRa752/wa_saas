<?php
session_start();
require_once("../config/db.php");
require_once(__DIR__ . '/../core/Employee/EmployeeRepository.php');
require_once(__DIR__ . '/../core/WhatsAppNumber/WhatsAppNumberRepository.php');

use Core\Employee\EmployeeRepository;
use Core\WhatsAppNumber\WhatsAppNumberRepository;

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$company_id = $_SESSION['company_id'];
$empRepo = new EmployeeRepository($conn);
$waNumRepo = new WhatsAppNumberRepository($conn);

// جلب موظفي الشركة
$employeesList = $empRepo->getEmployees($company_id);

require_once(__DIR__ . '/../core/Auth/CsrfHelper.php');
use Core\Auth\CsrfHelper;

// إضافة رقم واتساب
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'whatsapp_numbers')) {
        die("Invalid CSRF Token.");
    }

    $user_id = (int)$_POST['user_id'];
    $phone_number_id = trim($_POST['phone_number_id']);

    $existing = $waNumRepo->getPhoneNumberIdByUserId($user_id);

    if ($existing !== null) {
        $error = "⚠ This employee already has a WhatsApp number.";
    } else {
        $empRepo->upsertPhoneNumber($user_id, $phone_number_id);
        $success = "✅ WhatsApp number linked successfully!";
    }
}

include("../layouts/header.php");
?>

    <div class="card p-4 col-md-6">

        <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('whatsapp_numbers'); ?>">

            <div class="mb-3">
                <label>Select Employee</label>
                <select name="user_id" class="form-control" required>
                    <option value="">Choose employee</option>
                    <?php foreach($employeesList as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>">
                            <?php echo htmlspecialchars($emp['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label>Phone Number ID</label>
                <input type="text" name="phone_number_id" class="form-control" placeholder="1165715256628007" required>
                <small class="text-muted">Get this from WhatsApp Cloud API</small>
            </div>

            <button class="btn btn-primary w-100">
                <i class="bi bi-phone me-2"></i>Save Number
            </button>

        </form>

    </div>

<?php include("../layouts/footer.php"); ?>