<?php
session_start();
require_once("../config/db.php");

if (!isset($_SESSION['company_id']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

$company_id = $_SESSION['company_id'];

/* ============================
   ✅ حذف موظف
============================ */
if (isset($_GET['delete'])) {

    $id = intval($_GET['delete']);

    // حذف رقم واتساب أولاً
    $stmt = $conn->prepare("DELETE FROM whatsapp_numbers WHERE user_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    // حذف الموظف
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND company_id = ?");
    $stmt->bind_param("ii", $id, $company_id);
    $stmt->execute();

    header("Location: employees.php");
    exit;
}

/* ============================
   ✅ تعديل موظف
============================ */
if (isset($_POST['update'])) {

    $user_id = intval($_POST['user_id']);
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone_number_id = trim($_POST['phone_number_id']);

    // تحديث بيانات الموظف
    $stmt = $conn->prepare("
        UPDATE users 
        SET name=?, email=? 
        WHERE id=? AND company_id=?
    ");
    $stmt->bind_param("ssii", $name, $email, $user_id, $company_id);
    $stmt->execute();

    // تحقق من وجود رقم سابق
    $check = $conn->prepare("SELECT id FROM whatsapp_numbers WHERE user_id = ?");
    $check->bind_param("i", $user_id);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {

        $stmt2 = $conn->prepare("
            UPDATE whatsapp_numbers 
            SET phone_number_id=? 
            WHERE user_id=?
        ");
        $stmt2->bind_param("si", $phone_number_id, $user_id);
        $stmt2->execute();

    } else {

        $stmt2 = $conn->prepare("
            INSERT INTO whatsapp_numbers (user_id, phone_number_id)
            VALUES (?, ?)
        ");
        $stmt2->bind_param("is", $user_id, $phone_number_id);
        $stmt2->execute();
    }

    $success = "✅ Employee updated successfully!";
}

/* ============================
   ✅ إضافة موظف
============================ */
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['update'])) {

    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $phone_number_id = trim($_POST['phone_number_id']);

    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $exists = $check->get_result();

    if ($exists->num_rows > 0) {

        $error = "This email is already registered.";

    } else {

        $stmt = $conn->prepare("
            INSERT INTO users (company_id, name, email, password, role)
            VALUES (?, ?, ?, ?, 'employee')
        ");
        $stmt->bind_param("isss", $company_id, $name, $email, $password);

        if ($stmt->execute()) {

            $user_id = $stmt->insert_id;

            $stmt2 = $conn->prepare("
                INSERT INTO whatsapp_numbers (user_id, phone_number_id)
                VALUES (?, ?)
            ");
            $stmt2->bind_param("is", $user_id, $phone_number_id);
            $stmt2->execute();

            $success = "✅ Employee added successfully!";
        }
    }
}

/* ============================
   ✅ جلب الموظفين
============================ */
$stmt = $conn->prepare("
    SELECT u.id, u.name, u.email, w.phone_number_id
    FROM users u
    LEFT JOIN whatsapp_numbers w ON u.id = w.user_id
    WHERE u.company_id = ?
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$employees = $stmt->get_result();

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

                    <?php while($row = $employees->fetch_assoc()): ?>
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
                    <?php endwhile; ?>

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