<?php
session_start();
require_once("../config/db.php");
require_once(__DIR__ . '/../core/Employee/EmployeeRepository.php');

use Core\Employee\EmployeeRepository;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'employee') {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$empRepo = new EmployeeRepository($conn);
$user = $empRepo->getById($user_id);

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $passwordHash = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : null;

    $empRepo->updateProfile($user_id, $name, $email, $passwordHash);

    $_SESSION['user_name'] = $name;

    $success = "✅ Profile updated successfully!";
}

include("../layouts/header.php");
?>

    <div class="card p-4 col-md-6">

        <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <form method="POST">

            <div class="mb-3">
                <label>Name</label>
                <input type="text" name="name" class="form-control"
                       value="<?php echo htmlspecialchars($user['name']); ?>" required>
            </div>

            <div class="mb-3">
                <label>Email</label>
                <input type="email" name="email" class="form-control"
                       value="<?php echo htmlspecialchars($user['email']); ?>" required>
            </div>

            <div class="mb-3">
                <label>New Password (leave empty if no change)</label>
                <input type="password" name="password" class="form-control">
            </div>

            <button class="btn btn-primary w-100">Update Profile</button>

        </form>

    </div>

<?php include("../layouts/footer.php"); ?>