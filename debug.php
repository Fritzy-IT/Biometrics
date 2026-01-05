<?php
// I-load ang settings mo
include 'sms_function.php';

// EDIT THIS: Ilagay ang number mo
$test_number = '09615546622'; 

echo "<h2>SMS Debugger Tool</h2>";
echo "Target IP: " . $GATEWAY_IP . "<br>";
echo "Target Port: " . $GATEWAY_PORT . "<br>";

// 1. Check Connection
echo "<hr>Step 1: Checking Connection... ";
$conn = @fsockopen($GATEWAY_IP, $GATEWAY_PORT, $errno, $errstr, 2);

if ($conn) {
    echo "<span style='color:green'><b>ONLINE! (Connected)</b></span><br>";
    fclose($conn);
    
    // 2. Attempt Sending (With Full Error Reporting)
    echo "Step 2: Attempting to Send SMS...<br>";
    
    $url = "http://$GATEWAY_IP:$GATEWAY_PORT/";
    $data = ['to' => $test_number, 'message' => 'DEBUG TEST MESSAGE'];
    
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n" .
                         "Authorization: " . $API_TOKEN . "\r\n",
            'method'  => 'POST',
            'content' => json_encode($data),
            'ignore_errors' => true // Importante: Para makita natin kung 401 Unauthorized
        ],
    ];

    $context  = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    
    // 3. Analyze Response
    echo "<br><b>Response from Phone:</b><br>";
    echo "<pre style='background:#eee; padding:10px;'>" . htmlspecialchars($response) . "</pre>";
    
    echo "<b>Response Headers:</b><br>";
    echo "<pre style='background:#eee; padding:10px;'>" . print_r($http_response_header, true) . "</pre>";

} else {
    echo "<span style='color:red'><b>OFFLINE!</b></span><br>";
    echo "Error: $errstr ($errno)<br>";
    echo "Tip: Check IP or Firewall.";
}
?>