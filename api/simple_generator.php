<?php
// api/simple_generator.php - Generate code and save to file

// Generate 5-digit code
$code = str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
$amount = 500;
$expires = time() + 600; // 10 minutes

// Save to a simple JSON file
$data = [
    'code' => $code,
    'amount' => $amount,
    'expires' => $expires,
    'created' => time()
];

file_put_contents('payment_data.json', json_encode($data));

?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Code Generator</title>
    <style>
        body { text-align: center; padding: 50px; font-family: Arial; background: #f5f6fa; }
        .code { font-size: 64px; font-weight: bold; letter-spacing: 10px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 30px; border-radius: 10px; display: inline-block; margin: 20px; }
        .info { margin: 20px; color: #666; }
        button { padding: 10px 20px; font-size: 16px; background: #28a745; color: white; border: none; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>🏪 Ethio Brokerplace</h1>
    <h2>Your Payment Code</h2>
    <div class="code"><?php echo $code; ?></div>
    <div class="info">
        <p>Amount: <strong>500.00 ETB</strong></p>
        <p>Code expires in 10 minutes</p>
    </div>
    <button onclick="location.reload()">Generate New Code</button>
    <hr>
    <h3>Instructions:</h3>
    <ol style="text-align: left; max-width: 300px; margin: 0 auto;">
        <li>Copy this code: <strong><?php echo $code; ?></strong></li>
        <li>Open Telebirr app</li>
        <li>Go to Marketplace</li>
        <li>Enter the code: <strong><?php echo $code; ?></strong></li>
        <li>Enter PIN: <strong>1234</strong></li>
    </ol>
</body>
</html>