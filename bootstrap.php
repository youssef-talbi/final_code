<?php
// Bootstrap file for the freelance platform

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Define base path for file includes
define("BASE_PATH", __DIR__); // Use __DIR__ as the project root

// Define Base URL for web paths (adjust if project is in a subdirectory)
$baseUrl="/final_code"; // Assuming the project lives in /improved/ relative to the web root

// Include configuration files
require_once BASE_PATH . "/config/database.php";
// require_once BASE_PATH . "/config/constants.php"; // If you have constants
// require_once BASE_PATH . "/config/settings.php"; // If you have settings

// Include helper functions
require_once BASE_PATH . "/utils/Helpers.php";

// Autoload classes (if using OOP)
// spl_autoload_register(function ($class_name) {
//     $file = BASE_PATH .
//             str_replace("\\", "/", $class_name) . ".php";
//     if (file_exists($file)) {
//         require_once $file;
//     }
// });

// Error reporting (adjust for production)
error_reporting(E_ALL);
ini_set("display_errors", 1);

// Set default timezone
date_default_timezone_set("UTC");

// Check for remember me cookie
if (!is_logged_in() && isset($_COOKIE["remember_token"])) {
    $token = $_COOKIE["remember_token"];

    try {
        $db = getDbConnection();
        if ($db) {
            $query = "SELECT u.user_id, u.email, u.user_type, u.first_name, u.last_name 
                     FROM users u 
                     JOIN user_tokens ut ON u.user_id = ut.user_id 
                     WHERE ut.token = '$token' AND ut.expiry > NOW() AND u.account_status = 'active'";
            $result = $db->query($query);

            if ($result && $result->rowCount() > 0) {
                $user = $result->fetch(PDO::FETCH_ASSOC);

                // Log the user in (using helper function)
                log_user_in($user);

            } else {
                // Invalid or expired token, remove cookie
                setcookie("remember_token", "", time() - 3600, "/");
            }
        }
    } catch (PDOException $e) {
        error_log("Remember me error: " . $e->getMessage());
    }
}

// Include this bootstrap file at the beginning of your main PHP scripts (e.g., index.php, page scripts)
