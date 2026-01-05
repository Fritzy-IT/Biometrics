<?php
// Start the session
session_start();

// Destroy all session data
$_SESSION = array();
session_destroy();

// Redirect to the login page (index.php)
header("location: index.php");
exit;
?>