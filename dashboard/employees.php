<?php
session_start();
require_once("../config/db.php");
require_once(__DIR__ . '/../core/Employee/EmployeeRepository.php');

use Core\Employee\EmployeeRepository;

if (!isset($_SESSION['company_id']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

$company_id = $_SESSION['company_id'];
$empRepo = new EmployeeRepository($conn);

/* ============================
   ✅ حذف موظف
============================ */
if (isset($_GET['delete'])) {

    $id = intval($_GET['delete']);

    $empRepo->deletePhoneNumbers($id);
    $empRepo->delete($id, $company_id);

    require_once(__DIR__ . '/../core/Services/AuditLogService.php');
    $auditService = new \Core\Services\AuditLogService($conn);
    $auditService->log($company_id, $_SESSION['user_id'] ?? null, 'delete_employee', "Deleted employee ID $id");

    header("Location: employees.php");
    exit;
}

require_once(__DIR__ . '/../core/Auth/CsrfHelper.php');
use Core\Auth\CsrfHelper;

/* ============================
   ✅ تعديل موظف
============================ */
if (isset($_POST['update'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'employees')) {
        die("Invalid CSRF Token.");
    }

    $user_id = intval($_POST['user_id']);
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone_number_id = trim($_POST['phone_number_id']);

    $empRepo->update($user_id, $company_id, $name, $email);
    $empRepo->upsertPhoneNumber($user_id, $phone_number_id);

    require_once(__DIR__ . '/../core/Services/AuditLogService.php');
    $auditService = new \Core\Services\AuditLogService($conn);
    $auditService->log($company_id, $_SESSION['user_id'] ?? null, 'update_employee', "Updated employee '$name' (ID $user_id)");

    $success = "✅ Employee updated successfully!";
}

/* ============================
   ✅ إضافة موظف
============================ */
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['update'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'employees')) {
        die("Invalid CSRF Token.");
    }

    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $phone_number_id = trim($_POST['phone_number_id']);

    if ($empRepo->emailExists($email)) {
        $error = "This email is already registered.";
    } else {
        $user_id = $empRepo->create($company_id, $name, $email, $password);
        if ($user_id) {
            if (!empty($phone_number_id)) {
                $empRepo->upsertPhoneNumber($user_id, $phone_number_id);
            }
            
            require_once(__DIR__ . '/../core/Services/AuditLogService.php');
            $auditService = new \Core\Services\AuditLogService($conn);
            $auditService->log($company_id, $_SESSION['user_id'] ?? null, 'create_employee', "Created employee '$name' (ID $user_id)");
            
            $success = "✅ Employee added successfully!";
        }
    }
}

/* ============================
   ✅ جلب الموظفين
============================ */
$employeesList = $empRepo->getWithPhoneNumbers($company_id);

include("../layouts/header.php");
?>

<?php if(isset($success)): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?php echo $success; ?></div>
<?php endif; ?>

<?php if(isset($error)): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-2"></i><?php echo $error; ?></div>
<?php endif; ?>

    <div class="row">

        <div class="col-md-5">
            <div class="card p-4">
                <h5>Add Employee</h5>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('employees'); ?>">
                    <input type="text" name="name" class="form-control mb-2" placeholder="Name" required>
                    <input type="email" name="email" class="form-control mb-2" placeholder="Email" required>
                    <input type="password" name="password" class="form-control mb-2" placeholder="Password" required>
                    <input type="text" name="phone_number_id" class="form-control mb-2" placeholder="Phone Number ID" required>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-person-plus me-1"></i> Add Employee
                    </button>
                </form>
            </div>
        </div>

        <div class="col-md-7">
            <div class="card p-4">
                <h5>Employees</h5>

                <table class="table-custom w-100">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone ID</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>

                    <?php foreach($employeesList as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td><?php echo htmlspecialchars($row['phone_number_id'] ?? '-'); ?></td>
                            <td>
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        onclick='editEmployee(<?php echo json_encode($row); ?>)'>
                                    Edit
                                </button>

                                <a href="?delete=<?php echo $row['id']; ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Are you sure?')">
                                    Delete
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- ✅ Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('employees'); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Employee</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="user_id" id="edit_id">
                        <input type="text" name="name" id="edit_name" class="form-control mb-2" required>
                        <input type="email" name="email" id="edit_email" class="form-control mb-2" required>
                        <input type="text" name="phone_number_id" id="edit_phone" class="form-control mb-2" required>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function editEmployee(data){
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_name').value = data.name;
            document.getElementById('edit_email').value = data.email;
            document.getElementById('edit_phone').value = data.phone_number_id ?? '';
            var modal = new bootstrap.Modal(document.getElementById('editModal'));
            modal.show();
        }
    </script>

<?php include("../layouts/footer.php"); ?>