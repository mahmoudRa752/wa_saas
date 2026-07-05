<?php
session_start();
require_once("../config/db.php");

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$company_id = $_SESSION['company_id'];

// جلب موظفي الشركة
$stmt = $conn->prepare("SELECT * FROM users WHERE company_id = ?");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$employees = $stmt->get_result();

// إضافة رقم واتساب
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $user_id = $_POST['user_id'];
    $phone_number_id = $_POST['phone_number_id'];

    // تأكد إن الموظف مش مضاف له رقم قبل كده
    $check = $conn->prepare("SELECT * FROM whatsapp_numbers WHERE user_id = ?");
    $check->bind_param("i", $user_id);
    $check->execute();
    $exists = $check->get_result();

    if ($exists->num_rows > 0) {
        $error = "⚠ This employee already has a WhatsApp number.";
    } else {

        $stmt = $conn->prepare("INSERT INTO whatsapp_numbers (user_id, phone_number_id) VALUES (?, ?)");
        $stmt->bind_param("is", $user_id, $phone_number_id);
        $stmt->execute();

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

            <div class="mb-3">
                <label>Select Employee</label>
                <select name="user_id" class="form-control" required>
                    <option value="">Choose employee</option>
                    <?php while($emp = $employees->fetch_assoc()): ?>
                        <option value="<?php echo $emp['id']; ?>">
                            <?php echo $emp['name']; ?>
                        </option>
                    <?php endwhile; ?>
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