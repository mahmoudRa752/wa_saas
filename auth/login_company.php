<?php
session_start();
require_once("../config/db.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM companies WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {

        $company = $result->fetch_assoc();

        if (password_verify($password, $company['password'])) {

            $_SESSION['company_id'] = $company['id'];
            $_SESSION['company_name'] = $company['name'];
            $_SESSION['role'] = "admin";

            header("Location: ../dashboard/index.php");
            exit;

        } else {
            $error = "Wrong password!";
        }

    } else {
        $error = "Company not found!";
    }

    $stmt->close();
}
?>

<?php include("../layouts/header.php"); ?>

    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card shadow border-0 p-4">

                <h4 class="mb-4 text-center">Company Login</h4>

                <?php if(isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST">

                    <div class="mb-3">
                        <input type="email" name="email" class="form-control" placeholder="Company Email" required>
                    </div>

                    <div class="mb-3">
                        <input type="password" name="password" class="form-control" placeholder="Password" required>
                    </div>

                    <button class="btn btn-dark w-100">Login</button>

                </form>

            </div>
        </div>
    </div>

<?php include("../layouts/footer.php"); ?>