<?php
// user/pay_remaining.php - Pay remaining listing balance (seller)

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/seller_listing_payment.php';

date_default_timezone_set('Africa/Addis_Ababa');
requireLogin();

$page_title = 'Pay Remaining Balance';
ob_start();

$conn = getDbConnection();
$conn->query("SET time_zone = '+03:00'");

$user_id = (int) $_SESSION['user_id'];
$listing_id = isset($_GET['listing_id']) ? (int) $_GET['listing_id'] : 0;

$info = getSellerListingPaymentInfo($conn, $listing_id, $user_id);

if (!$info || !$info['can_pay_remaining']) {
    header('Location: listings.php');
    exit;
}

$listing = $conn->query("
    SELECT title, price FROM listings WHERE id = $listing_id AND seller_id = $user_id
")->fetch_assoc();

$transaction_id = $info['transaction_id'];
$amount = $info['remaining_balance'];

$existing_code = $conn->query("
    SELECT code, id AS code_id,
           TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_remaining
    FROM payment_codes
    WHERE transaction_id = $transaction_id
      AND user_id = $user_id
      AND type = 'remaining_balance'
      AND status = 'pending'
      AND expires_at > NOW()
    ORDER BY id DESC
    LIMIT 1
");

if ($existing_code && $existing_code->num_rows > 0) {
    $code_data = $existing_code->fetch_assoc();
    $payment_code = $code_data['code'];
    $code_id = (int) $code_data['code_id'];
    $final_seconds = max(0, (int) $code_data['seconds_remaining']);
} else {
    do {
        $payment_code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $code_check = $conn->prepare('SELECT id FROM payment_codes WHERE code = ? LIMIT 1');
        $code_check->bind_param('s', $payment_code);
        $code_check->execute();
        $exists = $code_check->get_result()->num_rows > 0;
        $code_check->close();
    } while ($exists);

    $stmt = $conn->prepare("
        INSERT INTO payment_codes (code, transaction_id, amount, user_id, type, expires_at, status, created_at)
        VALUES (?, ?, ?, ?, 'remaining_balance', DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'pending', NOW())
    ");
    $stmt->bind_param('sidi', $payment_code, $transaction_id, $amount, $user_id);
    $stmt->execute();
    $code_id = $conn->insert_id;
    $stmt->close();
    $final_seconds = 1800;
}

$conn->close();
?>

<style>
    .payment-container { max-width: 520px; margin: 24px auto; }
    .payment-header {
        background: linear-gradient(135deg, #10b981, #059669);
        border-radius: 20px;
        padding: 28px;
        color: white;
        margin-bottom: 20px;
        text-align: center;
    }
    .card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid #e2e8f0;
    }
    .summary-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid #f1f5f9;
        font-size: 14px;
    }
    .summary-row.total { font-weight: 700; font-size: 16px; border-bottom: none; color: #059669; }
    .payment-code {
        font-size: 32px;
        font-weight: 800;
        letter-spacing: 10px;
        text-align: center;
        padding: 20px;
        background: #f0fdf4;
        border-radius: 16px;
        margin: 16px 0;
        cursor: pointer;
    }
    .timer { text-align: center; font-weight: 600; color: #64748b; }
    .payment-status { text-align: center; margin-top: 20px; }
    .spinner {
        width: 36px; height: 36px;
        border: 3px solid #e2e8f0;
        border-top-color: #10b981;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
        margin: 0 auto;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .btn-back {
        display: inline-block;
        margin-top: 16px;
        color: #64748b;
        text-decoration: none;
    }
</style>

<div class="payment-container">
    <div class="payment-header">
        <h1><i class="fas fa-wallet"></i> Pay Remaining Balance</h1>
        <p><?php echo htmlspecialchars($listing['title']); ?></p>
    </div>

    <div class="card">
        <h3 style="margin-bottom: 12px;"><i class="fas fa-receipt"></i> Payment Summary</h3>
        <div class="summary-row">
            <span>Total Price</span>
            <span id="summaryTotal"><?php echo formatMoney($info['total_price']); ?></span>
        </div>
        <div class="summary-row">
            <span>Deposit Paid</span>
            <span id="summaryDeposit"><?php echo formatMoney($info['deposit_paid']); ?></span>
        </div>
        <div class="summary-row total">
            <span>Remaining Balance</span>
            <span id="summaryRemaining"><?php echo formatMoney($info['remaining_balance']); ?></span>
        </div>

        <div class="payment-code" id="paymentCode" onclick="copyCode()"><?php echo $payment_code; ?></div>
        <p class="timer">Code expires in: <span id="timer">--:--</span></p>

        <div style="margin-top: 16px; padding: 14px; background: #f8fafc; border-radius: 12px; border: 1px dashed #cbd5e1;">
            <p style="font-size: 13px; font-weight: 600; margin-bottom: 8px;">Paid in Telebirr? Confirm here</p>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <input type="password" id="confirmPin" value="1234" maxlength="4" placeholder="PIN"
                    style="flex:1;min-width:90px;padding:8px;border:1px solid #e2e8f0;border-radius:8px;">
                <button type="button" id="confirmPayBtn" onclick="confirmPaymentManually()"
                    style="padding:8px 16px;background:#10b981;color:#fff;border:none;border-radius:30px;font-weight:600;cursor:pointer;">
                    Confirm Payment
                </button>
            </div>
            <p id="confirmPayError" style="color:#dc2626;font-size:12px;margin-top:8px;display:none;"></p>
        </div>

        <div class="payment-status" id="paymentStatus">
            <div class="spinner"></div>
            <p style="margin-top: 12px;">Waiting for payment confirmation...</p>
        </div>

        <a href="listings.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to My Listings</a>
    </div>
</div>

<script>
const paymentCode = '<?php echo $payment_code; ?>';
const listingId = <?php echo $listing_id; ?>;
let pollingActive = true;
let pollInterval = null;
let countdownInterval = null;
let currentSecondsRemaining = <?php echo (int) $final_seconds; ?>;

function updateTimerDisplay(seconds) {
    const el = document.getElementById('timer');
    if (!el) return;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    el.textContent = `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

function startCountdown(sec) {
    currentSecondsRemaining = sec;
    updateTimerDisplay(sec);
    if (countdownInterval) clearInterval(countdownInterval);
    countdownInterval = setInterval(() => {
        if (currentSecondsRemaining > 0 && pollingActive) {
            currentSecondsRemaining--;
            updateTimerDisplay(currentSecondsRemaining);
        }
    }, 1000);
}

function updateSummary(summary) {
    if (!summary) return;
    if (summary.total_price_formatted) document.getElementById('summaryTotal').textContent = summary.total_price_formatted;
    if (summary.deposit_paid_formatted) document.getElementById('summaryDeposit').textContent = summary.deposit_paid_formatted;
    if (summary.remaining_balance_formatted) document.getElementById('summaryRemaining').textContent = summary.remaining_balance_formatted;
}

function updateUIFromBackend(data) {
    const statusDiv = document.getElementById('paymentStatus');
    if (data.seconds_remaining !== undefined) {
        startCountdown(Math.max(0, data.seconds_remaining));
    }
    if (data.summary) updateSummary(data.summary);

    if (data.payment_status === 'fully_paid' || data.is_paid) {
        pollingActive = false;
        clearInterval(pollInterval);
        clearInterval(countdownInterval);
        statusDiv.innerHTML = `
            <div style="color:#059669;">
                <i class="fas fa-check-circle" style="font-size:48px;"></i>
                <p style="font-weight:700;font-size:18px;margin-top:8px;">Fully Paid</p>
                <p>Your listing balance has been paid in full.</p>
            </div>`;
        setTimeout(() => { window.location.href = 'listings.php?fully_paid=1'; }, 2000);
    } else if (data.payment_status === 'expired' || data.is_expired) {
        pollingActive = false;
        clearInterval(pollInterval);
        statusDiv.innerHTML = '<p style="color:#dc2626;">Code expired. <a href="pay_remaining.php?listing_id=' + listingId + '">Refresh</a></p>';
    }
}

async function pollBackendStatus() {
    if (!pollingActive) return;
    try {
        const res = await fetch(`/broker_system/api/payment_status_remaining.php?code=${paymentCode}&listing_id=${listingId}&_=${Date.now()}`, { cache: 'no-store' });
        const data = await res.json();
        if (data.success) updateUIFromBackend(data);
    } catch (e) {
        console.error(e);
    }
}

function copyCode() {
    navigator.clipboard.writeText(paymentCode);
}

async function confirmPaymentManually() {
    const btn = document.getElementById('confirmPayBtn');
    const errEl = document.getElementById('confirmPayError');
    const pin = document.getElementById('confirmPin').value.trim();
    errEl.style.display = 'none';
    btn.disabled = true;
    btn.textContent = 'Confirming...';

    try {
        const res = await fetch('/broker_system/api/confirm_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ payment_code: paymentCode, pin: pin })
        });
        const data = await res.json();
        if (data.success) {
            pollBackendStatus();
        } else {
            errEl.textContent = data.error || 'Confirmation failed';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Confirm Payment';
        }
    } catch (e) {
        errEl.textContent = 'Network error';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Confirm Payment';
    }
}

startCountdown(currentSecondsRemaining);
pollBackendStatus();
pollInterval = setInterval(pollBackendStatus, 1500);
</script>

<?php
$content = ob_get_clean();
include '../includes/layout.php';
