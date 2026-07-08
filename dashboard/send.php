<?php
session_start();
require_once("../config/db.php");
require_once("../vendor/autoload.php");
require_once(__DIR__ . '/../core/Services/WhatsAppService.php');
require_once(__DIR__ . '/../core/Services/UsageService.php');

use Core\Services\UsageService;
use Core\Services\WhatsAppService;
use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$company_id = $_SESSION['company_id'];
$role = $_SESSION['role'];
$whatsAppService = new WhatsAppService();
$usageService = new UsageService($conn);
$usage = $usageService->getMonthlyLimitAndUsage($company_id);
$limit = $usage['limit'];
$used = $usage['used'];

/* ===============================
   ✅ جلب الموظفين (Admin فقط)
================================= */
if ($role == 'admin') {
    $stmt = $conn->prepare("SELECT id, name FROM users WHERE company_id = ?");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $employees = $stmt->get_result();
}

/* ===============================
   ✅ تنفيذ الإرسال
================================= */
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    if ($used >= $limit) {
        $error = "❌ Monthly message limit reached.";
    } else {

        if ($role == 'admin') {
            $user_id = $_POST['user_id'];
        } else {
            $user_id = $_SESSION['user_id'];
        }

        $mode = $_POST['mode'];
        $accessToken = WHATSAPP_TOKEN;

        // ✅ جلب رقم واتساب
        $stmt = $conn->prepare("SELECT phone_number_id FROM whatsapp_numbers WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $numberData = $stmt->get_result()->fetch_assoc();

        if (!$numberData) {
            $error = "No WhatsApp number linked.";
        } else {

            $phoneNumberId = $numberData['phone_number_id'];

            if ($mode == "manual") {

                $recipient = preg_replace('/[^0-9]/', '', $_POST['recipient']);
                $message = $_POST['message'];

                $message = str_replace(
                        ["{name}", "{company}"],
                        [$_SESSION['user_name'] ?? '', $_SESSION['company_name'] ?? ''],
                        $message
                );

                sendWhatsApp($user_id, $phoneNumberId, $recipient, $message, $accessToken);
                $success = "✅ Message sent successfully!";

            } else {

                $spreadsheet = IOFactory::load($_FILES['excel_file']['tmp_name']);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray();

                $headers = $rows[0];

                foreach ($rows as $index => $row) {

                    if ($index == 0) continue;

                    $recipient = preg_replace('/[^0-9]/', '', $row[0]);
                    $message = $_POST['message'];

                    foreach ($headers as $key => $columnName) {
                        $value = $row[$key] ?? "";
                        $message = str_replace("{" . $columnName . "}", $value, $message);
                    }

                    sendWhatsApp($user_id, $phoneNumberId, $recipient, $message, $accessToken);
                }

                $success = "✅ Bulk messages processed!";
            }
        }
    }
}

/* ===============================
   ✅ دالة الإرسال
================================= */
function sendWhatsApp($user_id, $phoneNumberId, $recipient, $message, $accessToken){

    global $conn;
    global $whatsAppService;

    $result = $whatsAppService->sendText($phoneNumberId, $recipient, $message, $accessToken);

    $stmt = $conn->prepare("INSERT INTO messages (user_id, recipient, message, status) VALUES (?, ?, ?, 'sent')");
    $stmt->bind_param("iss", $user_id, $recipient, $message);
    $stmt->execute();
}

include("../layouts/header.php");
?>

    <div class="card p-4 col-md-7">

        <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <?php if($role == 'admin'): ?>

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

            <?php else: ?>

                <input type="hidden" name="user_id" value="<?php echo $_SESSION['user_id']; ?>">

                <div class="mb-3">
                    <label>Sending From</label>
                    <input type="text"
                           class="form-control"
                           value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>"
                           disabled>
                </div>

            <?php endif; ?>

            <div class="mb-3">
                <label>Send Mode</label>
                <select name="mode" id="mode" class="form-control" onchange="toggleMode()">
                    <option value="manual">Manual Number</option>
                    <option value="excel">Upload Excel</option>
                </select>
            </div>

            <div class="mb-3" id="manualInput">
                <label>Recipient Number (without +)</label>
                <input type="text" name="recipient" class="form-control">
            </div>

            <div class="mb-3" id="excelInput" style="display:none;">
                <label>Upload Excel (.xlsx)</label>
                <input type="file" name="excel_file" class="form-control">
            </div>

            <div class="mb-3">
                <label>Message</label>
                <textarea name="message"
                          class="form-control"
                          rows="5"
                          placeholder="اكتب الرسالة هنا...
يمكنك استخدام:
{name}
{company}"></textarea>
            </div>

            <div class="mb-3">
                <a href="../download_template.php" class="btn btn-outline-secondary btn-sm">
                    Download Excel Template
                </a>
            </div>

            <button class="btn btn-primary w-100">
                <i class="bi bi-send me-2"></i>Send Message
            </button>

        </form>

    </div>

    <script>
        function toggleMode(){
            let mode = document.getElementById("mode").value;
            document.getElementById("manualInput").style.display = mode === "manual" ? "block" : "none";
            document.getElementById("excelInput").style.display = mode === "excel" ? "block" : "none";
        }
    </script>

<?php include("../layouts/footer.php"); ?>