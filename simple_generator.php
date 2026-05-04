<?php
session_start();

$code = str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
$_SESSION['payment_code'] = $code;
$_SESSION['payment_amount'] = 500;
$_SESSION['payment_expires'] = time() + 600;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Code</title>
    <style>
        body { text-align: center; padding: 50px; font-family: Arial; }
        .code { font-size: 64px; font-weight: bold; letter-spacing: 10px; background: #667eea; color: white; padding: 30px; border-radius: 10px; display: inline-block; }
    </style>
</head>
<body>
    <h1>Your Payment Code</h1>
    <div class="code"><?php echo $code; ?></div>
    <p>Amount: 500.00 ETB</p>
    <p>Use this code in Telebirr app</p>
    <button onclick="location.reload()">Generate New</button>
</body>
</html>