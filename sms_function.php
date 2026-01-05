<?php
// ==========================================
// CONFIGURATION (EDIT THESE TWO LINES)
// ==========================================
// Ang IP Address ng Phone mo (Check Traccar App)
$GATEWAY_IP = '10.111.88.241'; 

// Ang Port number (Default is 8082)
$GATEWAY_PORT = '8082';

// Ang Token/API Key mo
$API_TOKEN = 'fb323971-df05-4fd5-a642-4534cb21a075'; 
// ==========================================


/**
 * Function 1: Send SMS
 * Gamitin ito sa pag-send ng text.
 */
function sendSMS($number, $message) {
    global $GATEWAY_IP, $GATEWAY_PORT, $API_TOKEN;

    $url = "http://$GATEWAY_IP:$GATEWAY_PORT/";

    $data = [
        'to' => $number,
        'message' => $message
    ];

    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n" .
                         "Authorization: " . $API_TOKEN . "\r\n",
            'method'  => 'POST',
            'content' => json_encode($data),
            'timeout' => 5 // 5 seconds timeout
        ],
    ];

    $context  = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    return ($result !== FALSE);
}

/**
 * Function 2: Check Connection (Health Check)
 * Gamitin ito sa Dashboard para malaman kung ONLINE ang phone.
 */
function checkGatewayConnection() {
    global $GATEWAY_IP, $GATEWAY_PORT;
    
    // Subukang kumonekta sa loob ng 1 second lang
    $connection = @fsockopen($GATEWAY_IP, $GATEWAY_PORT, $errno, $errstr, 1);

    if ($connection) {
        fclose($connection);
        return true; // ONLINE 🟢
    } else {
        return false; // OFFLINE 🔴
    }
}
?>