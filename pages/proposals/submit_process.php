<?php
// Process proposal submission form
$baseUrl="/final_code";
// Include bootstrap and check user permissions
require_once __DIR__ . "/../../bootstrap.php";

// Check if user is logged in and is a freelancer
if (!is_logged_in() || get_user_type() !== "freelancer") {
    redirect("final_code/pages/auth/login.php?error=login_required");
    exit;
}

// Check if form was submitted via POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    redirect("final_code/pages/projects/list.php?error=invalid_request");
    exit;
}

// Get form data
$freelancer_id = get_current_user_id();
$project_id = filter_input(INPUT_POST, "project_id", FILTER_VALIDATE_INT);
$cover_letter = sanitize_input($_POST["cover_letter"] ?? "");
$price = filter_input(INPUT_POST, "price", FILTER_VALIDATE_FLOAT);
$estimated_completion_days = filter_input(INPUT_POST, "estimated_completion_days", FILTER_VALIDATE_INT);

// Basic validation
if (empty($project_id) || empty($cover_letter) || $price === false || $price < 0 || $estimated_completion_days === false || $estimated_completion_days < 1) {
    redirect("final_code/pages/proposals/submit.php?project_id=" . $project_id . "&error=empty_or_invalid");
    exit;
}

// Database connection
$db = getDbConnection();
if (!$db) {
    redirect("final_code/pages/proposals/submit.php?project_id=" . $project_id . "&error=db_error");
    exit;
}

try {
    // Begin transaction
    $db->beginTransaction();

    // 1. Verify project exists and is open
    $query = "SELECT status FROM projects WHERE project_id = '$project_id'";
    $result = $db->query($query);
    $project = $result->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        $db->rollBack();
        redirect("final_code/pages/projects/list.php?error=project_not_found");
        exit;
    }

    if ($project["status"] !== "open") {
        $db->rollBack();
        redirect("final_code/pages/projects/view.php?id=" . $project_id . "&error=project_not_open");
        exit;
    }

    // 2. Check if freelancer has already submitted (redundant check, but good practice)
    $check_query = "SELECT proposal_id FROM proposals WHERE project_id = '$project_id' AND freelancer_id = '$freelancer_id'";
    $check_result = $db->query($check_query);
    if ($check_result->fetch()) {
        $db->rollBack();
        redirect("final_code/pages/projects/view.php?id=" . $project_id . "&error=already_submitted");
        exit;
    }

    // 3. Insert proposal into proposals table
    $insert_query = "INSERT INTO proposals (project_id, freelancer_id, cover_letter, price, estimated_completion_days, submission_date, status) 
                   VALUES ('$project_id', '$freelancer_id', '$cover_letter', '$price', '$estimated_completion_days', NOW(), 'submitted')";
    $db->query($insert_query);
    
    // Fetch client_id of the project to notify
    $client_query = "SELECT client_id FROM projects WHERE project_id = '$project_id'";
    $client_result = $db->query($client_query);
    $project_data = $client_result->fetch(PDO::FETCH_ASSOC);

    if ($project_data && isset($project_data['client_id'])) {
        create_notification(
            $project_data['client_id'],
            'proposal',
            'You received a new proposal for your project.',
            $project_id
        );
    }

    // Commit transaction
    $db->commit();

    // Redirect to the project view page with success message
    redirect("/pages/projects/view.php?id=" . $project_id . "&message=proposal_submitted");
    exit;

} catch (PDOException $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    // Log error
    error_log("Proposal submission error for project $project_id by freelancer $freelancer_id: " . $e->getMessage());

    // Redirect back with error
    redirect("/pages/proposals/submit.php?project_id=" . $project_id . "&error=db_error");
    exit;
}
?>
