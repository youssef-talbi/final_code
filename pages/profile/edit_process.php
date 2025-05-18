<?php
// Process profile edit form submission

// Include bootstrap and check user permissions
require_once __DIR__ . "/../../bootstrap.php";

// Check if user is logged in
if (!is_logged_in()) {
    redirect("/pages/auth/login.php?error=login_required");
    exit;
}

// Check if form was submitted via POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    redirect("/pages/profile/edit.php?error=invalid_request");
    exit;
}

// Get current user ID and type
$user_id = get_current_user_id();
$user_type = get_user_type();

// --- Get and Sanitize Form Data ---

// Basic Info
$first_name = sanitize_input($_POST["first_name"] ?? "");
$last_name = sanitize_input($_POST["last_name"] ?? "");
$email = filter_input(INPUT_POST, "email", FILTER_VALIDATE_EMAIL);

// Profile Specific Info
$freelancer_headline = ($user_type === "freelancer") ? sanitize_input($_POST["freelancer_headline"] ?? "") : null;
$freelancer_bio = ($user_type === "freelancer") ? sanitize_input($_POST["freelancer_bio"] ?? "") : null;
$skills_input = ($user_type === "freelancer") ? sanitize_input($_POST["skills"] ?? "") : null;
$hourly_rate = ($user_type === "freelancer") ? filter_input(INPUT_POST, "hourly_rate", FILTER_VALIDATE_FLOAT) : null;
$experience_level = ($user_type === "freelancer") ? sanitize_input($_POST["experience_level"] ?? "") : null;

$company_name = ($user_type === "client") ? sanitize_input($_POST["company_name"] ?? "") : null;
$client_website = ($user_type === "client") ? filter_input(INPUT_POST, "client_website", FILTER_VALIDATE_URL) : null;
$client_bio = ($user_type === "client") ? sanitize_input($_POST["client_bio"] ?? "") : null;

// Password Change
$current_password = $_POST["current_password"] ?? "";
$new_password = $_POST["new_password"] ?? "";
$confirm_password = $_POST["confirm_password"] ?? "";

// --- Basic Validation ---
if (empty($first_name) || empty($last_name) || empty($email)) {
    redirect("/pages/profile/edit.php?error=empty_basic");
    exit;
}

// --- Password Change Validation ---
$change_password = !empty($new_password);
if ($change_password) {
    if (empty($current_password) || empty($confirm_password)) {
        redirect("/pages/profile/edit.php?error=empty_password_fields");
        exit;
    }
    if (strlen($new_password) < 8) {
        redirect("/pages/profile/edit.php?error=password_short");
        exit;
    }
    if ($new_password !== $confirm_password) {
        redirect("/pages/profile/edit.php?error=password_mismatch");
        exit;
    }
}

// Database connection
$db = getDbConnection();
if (!$db) {
    redirect("/pages/profile/edit.php?error=db_error");
    exit;
}

try {
    // Begin transaction
    $db->beginTransaction();

    // --- Verify Current Password (if changing) ---
    if ($change_password) {
        $query = "SELECT password_hash FROM users WHERE user_id = '$user_id'";
        $result = $db->query($query);
        $user_pwd = $result->fetch(PDO::FETCH_ASSOC);

        if (!$user_pwd || !password_verify($current_password, $user_pwd["password_hash"])) {
            $db->rollBack();
            redirect("/pages/profile/edit.php?error=current_password_incorrect");
            exit;
        }
    }

    // --- Handle Profile Picture Upload ---
    $profile_picture_path = null;
    if (isset($_FILES["profile_picture"]) && $_FILES["profile_picture"]["error"] === UPLOAD_ERR_OK) {
        $upload_dir = BASE_PATH . "/public_html/uploads/profile_pictures/";
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $upload_result = upload_file($_FILES["profile_picture"], $upload_dir, ["jpg", "jpeg", "png", "gif"], 2 * 1024 * 1024); // Max 2MB

        if ($upload_result["success"]) {
            $profile_picture_path = $upload_result["path"]; // Relative path for DB
            // Optionally, delete the old profile picture file here
        } else {
            $db->rollBack();
            redirect("/pages/profile/edit.php?error=" . urlencode($upload_result["error"]));
            exit;
        }
    }

    // --- Update Users Table ---
    $sql_parts = [];
    if ($change_password) {
        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $sql_parts[] = "password_hash = '$new_password_hash'";
    }

    if ($profile_picture_path !== null) {
        $sql_parts[] = "profile_picture = '$profile_picture_path'";
    }

    $sql_parts[] = "first_name = '$first_name'";
    $sql_parts[] = "last_name = '$last_name'";
    $sql_parts[] = "email = '$email'";

    $sql_user_update = "UPDATE users SET " . implode(", ", $sql_parts) . " WHERE user_id = '$user_id'";
    $db->query($sql_user_update);

    // --- Update Profile Specific Table ---
    if ($user_type === "freelancer") {
        $hourly_rate_param = ($hourly_rate === false || $hourly_rate === "") ? "NULL" : $hourly_rate;
        $query = "UPDATE freelancer_profiles 
                 SET headline = '$freelancer_headline', 
                     summary = '$freelancer_bio', 
                     hourly_rate = $hourly_rate_param, 
                     experience_level = '$experience_level'
                 WHERE user_id = '$user_id'";
        $db->query($query);

        // --- Update Skills ---
        // 1. Remove existing skills for the user
        $delete_skills_query = "DELETE FROM user_skills WHERE user_id = '$user_id'";
        $db->query($delete_skills_query);

        // 2. Add new skills
        $skills_array = array_unique(array_filter(array_map("trim", explode(",", $skills_input))));
        if (!empty($skills_array)) {
            foreach ($skills_array as $skill_name) {
                // Check if skill exists
                $find_query = "SELECT skill_id FROM skills WHERE skill_name = '$skill_name'";
                $find_result = $db->query($find_query);
                $skill_result = $find_result->fetch(PDO::FETCH_ASSOC);

                $current_skill_id = null;
                if ($skill_result) {
                    $current_skill_id = $skill_result["skill_id"];
                } else {
                    // Skill doesn't exist, insert it
                    $insert_query = "INSERT INTO skills (skill_name) VALUES ('$skill_name')";
                    $db->query($insert_query);
                    $current_skill_id = $db->lastInsertId();
                }

                // Associate skill with user
                if ($current_skill_id) {
                    try {
                        $user_skill_query = "INSERT INTO user_skills (user_id, skill_id) VALUES ('$user_id', '$current_skill_id')";
                        $db->query($user_skill_query);
                    } catch (PDOException $e) {
                        if ($e->getCode() != 23000) { throw $e; } // Ignore duplicates
                    }
                }
            }
        }

    } elseif ($user_type === "client") {
        $query = "UPDATE client_profiles 
                 SET company_name = '$company_name', 
                     website = " . ($client_website ? "'$client_website'" : "NULL") . ", 
                     description = '$client_bio'
                 WHERE user_id = '$user_id'";
        $db->query($query);
    }

    // Commit transaction
    $db->commit();

    // Update session data if necessary (e.g., name, email)
    $_SESSION["user"]["first_name"] = $first_name;
    $_SESSION["user"]["email"] = $email;
    // Add other fields as needed

    // Redirect to profile view page with success message
    redirect("/pages/profile/view.php?id=" . $user_id . "&message=profile_updated");
    exit;

} catch (PDOException $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    // Log error
    error_log("Profile update error for user $user_id: " . $e->getMessage());

    // Redirect back with error
    redirect("/pages/profile/edit.php?error=db_error");
    exit;
}
?>
