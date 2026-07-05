<?php
session_start();
require_once("../config/db.php");

if (!isset($_SESSION['company_id']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

$company_id = $_SESSION['company_id'];
$plans = $conn->query("SELECT * FROM plans ORDER BY price ASC");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $plan_id = intval($_POST['plan_id']);
    $start   = date("Y-m-d");
    $end     = date("Y-m-d", strtotime("+30 days"));

    $del = $conn->prepare("DELETE FROM subscriptions WHERE company_id = ?");
    $del->bind_param("i", $company_id);
    $del->execute();

    $stmt = $conn->prepare("INSERT INTO subscriptions (company_id, plan_id, start_date, end_date) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $company_id, $plan_id, $start, $end);
    $stmt->execute();

    $success = "✅ Subscription activated successfully!";
}

include("../layouts/header.php");
?>

<?php if (isset($success)): ?>
    <div class="alert alert-success mb-4">
        <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
    </div>
<?php endif; ?>

<p style="color:#64748b; margin-bottom:28px;">Choose the plan that fits your needs. All plans include a 30-day billing cycle.</p>

<div class="row g-4 justify-content-center">

    <?php
    $plans_arr = [];
    while ($plan = $plans->fetch_assoc()) $plans_arr[] = $plan;
    $mid = floor(count($plans_arr) / 2);

    foreach ($plans_arr as $i => $plan):
        $featured = ($i == $mid);
    ?>

    <div class="col-md-4">
        <div class="plan-card <?php echo $featured ? 'featured' : ''; ?>">

            <?php if ($featured): ?>
                <div class="plan-badge">⭐ Most Popular</div>
            <?php endif; ?>

            <div style="font-size:13px; font-weight:700; color:#6366f1; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">
                <?php echo htmlspecialchars($plan['name']); ?>
            </div>

            <div class="plan-price">
                <sup>$</sup><?php echo number_format($plan['price']); ?>
                <span style="font-size:14px; font-weight:400; color:#94a3b8;">/mo</span>
            </div>

            <p style="color:#64748b; font-size:13px; margin:12px 0 20px;">
                <i class="bi bi-chat-dots me-1 text-success"></i>
                <strong><?php echo number_format($plan['monthly_limit']); ?></strong> messages per month
            </p>

            <ul style="list-style:none; padding:0; margin-bottom:24px; text-align:left;">
                <li style="font-size:13px; color:#475569; padding:5px 0; border-bottom:1px solid #f1f5f9;">
                    <i class="bi bi-check-circle-fill text-success me-2"></i> WhatsApp Cloud API
                </li>
                <li style="font-size:13px; color:#475569; padding:5px 0; border-bottom:1px solid #f1f5f9;">
                    <i class="bi bi-check-circle-fill text-success me-2"></i> Bulk Send via Excel
                </li>
                <li style="font-size:13px; color:#475569; padding:5px 0;">
                    <i class="bi bi-check-circle-fill text-success me-2"></i> Team Management
                </li>
            </ul>

            <form method="POST">
                <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                <button class="btn <?php echo $featured ? 'btn-primary' : 'btn-outline-primary'; ?> w-100">
                    Choose <?php echo htmlspecialchars($plan['name']); ?>
                </button>
            </form>

        </div>
    </div>

    <?php endforeach; ?>

</div>

<?php include("../layouts/footer.php"); ?>
