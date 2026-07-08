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

// ✅ جلب الموظفين
if ($role == 'admin') {
    $stmt = $conn->prepare("
        SELECT u.id, u.name
        FROM users u
        WHERE u.company_id = ?
    ");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $employees = $stmt->get_result();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    if ($used >= $limit) {
        $error = "❌ Monthly limit reached.";
    } else {

        if ($role == 'admin') {
            $user_id = $_POST['user_id'];
        } else {
            $user_id = $_SESSION['user_id'];
        }

        $stmt = $conn->prepare("SELECT phone_number_id FROM whatsapp_numbers WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $numberData = $stmt->get_result()->fetch_assoc();

        if (!$numberData) {
            $error = "No WhatsApp number linked.";
        } else {

            $phoneNumberId = $numberData['phone_number_id'];
            $accessToken = WHATSAPP_TOKEN;

            $spreadsheet = IOFactory::load($_FILES['file']['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            // جلب العناوين (Headers) لتعويض القيم المخصصة في الرسالة
            $headers = $rows[0];

            foreach ($rows as $index => $row) {

                if ($index == 0) continue;

                // قراءة الرقم وتنظيفه
                $recipient = preg_replace('/[^0-9]/', '', $row[0]);
                if (empty($recipient)) continue;

                // جلب نص الرسالة الأصلي المكتوب في الـ Textarea
                $messageText = $_POST['message'];

                // تعويض المتغيرات الديناميكية بناءً على ملف الإكسيل
                foreach ($headers as $key => $columnName) {
                    $value = $row[$key] ?? "";
                    $messageText = str_replace("{" . trim($columnName) . "}", $value, $messageText);
                }

                $response = $whatsAppService->sendText($phoneNumberId, $recipient, $messageText, $accessToken);
                $httpCode = $response['httpCode'];

                // ✅ لو فشل بسبب عدم وجود نافذة محادثة نشطة (Session) -> نستخدم Template كخيار بديل تلقائي
                if ($httpCode != 200) {
                    $whatsAppService->sendTemplate($phoneNumberId, $recipient, $accessToken);
                    $messageText = "[Template: hello_world]";
                }

                // حفظ الرسالة الفعلية المسلمة في جدول التقارير
                $stmt = $conn->prepare("INSERT INTO messages (user_id, recipient, message, status) VALUES (?, ?, ?, 'sent')");
                $stmt->bind_param("iss", $user_id, $recipient, $messageText);
                $stmt->execute();
            }

            $success = "✅ Bulk messages processed successfully.";
        }
    }
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
                    <label class="form-label">Select Employee</label>
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
                    <label class="form-label">Sending From</label>
                    <input type="text"
                           class="form-control"
                           value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>"
                           disabled>
                </div>

            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label">Upload Excel (.xlsx)</label>
                <input type="file" name="file" class="form-control" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Message Template</label>
                <textarea name="message"
                          class="form-control"
                          rows="6"
                          placeholder="اكتب رسالتك الجماعية هنا...
يمكنك استعمال أسماء الأعمدة المكتوبة في ملف الـ Excel للتعويض التلقائي، مثلاً:
مرحباً {name}، نود إخطارك بـ {status}." required></textarea>
            </div>

            <div class="mb-3">
                <a href="../download_template.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-download me-1"></i> Download Excel Template
                </a>
            </div>

            <button class="btn btn-primary w-100 py-2">
                <i class="bi bi-send-fill me-2"></i>Send Bulk
            </button>

        </form>

    </div>

<?php include("../layouts/footer.php"); ?>