<?php
require_once __DIR__ . '/../../bootstrap.php';
$baseUrl="/final_code";
if (is_logged_in()) {
    $userId = get_current_user_id();
}

// End session
session_unset();
session_destroy();

// Redirect to login with message
header("Location: " . $baseUrl . "/pages/auth/login.php?message=logged_out");

exit;
