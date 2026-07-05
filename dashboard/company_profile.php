<?php
session_start();
require_once("../config/db.php");

if (!isset($_SESSION['company_id']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

$company_id = $_SESSION['company_id'];

// ✅ جلب بيانات الشركة بأمان
$stmt = $conn->prepare("SELECT name, email FROM companies WHERE id = ?");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("Company not found.");
}

$company = $result->fetch_assoc();

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $name = trim($_POST['name']);
    $email = trim($_POST['email']);

    if (!empty($_POST['password'])) {

        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        $update = $conn->prepare("UPDATE companies SET name=?, email=?, password=? WHERE id=?");
        $update->bind_param("sssi", $name, $email, $password, $company_id);

    } else {

        $update = $conn->prepare("UPDATE companies SET name=?, email=? WHERE id=?");
        $update->bind_param("ssi", $name, $email, $company_id);
    }

    $update->execute();

    $_SESSION['company_name'] = $name;

    $success = "✅ Profile updated successfully!";

    // ✅ إعادة تحميل البيانات بعد التحديث
    $stmt = $conn->prepare("SELECT name, email FROM companies WHERE id = ?");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $company = $stmt->get_result()->fetch_assoc();
}

include("../layouts/header.php");
?>

    <div class="card p-4 col-md-6">

        <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <form method="POST">

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