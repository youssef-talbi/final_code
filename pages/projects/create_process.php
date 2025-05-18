<?php
// Process project creation form submission

// Include bootstrap and check user permissions
require_once __DIR__ . "/../../bootstrap.php";

// Check if user is logged in and is a client
if (!is_logged_in() || get_user_type() !== 'client') {
    redirect('/pages/auth/login.php?error=unauthorized');
    exit;
}

// Check if form was submitted via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/pages/projects/create.php?error=invalid_request');
    exit;
}

// Get form data
$client_id = get_current_user_id();
$title = sanitize_input($_POST['title'] ?? '');
$description = sanitize_input($_POST['description'] ?? '');
$category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
$skills_input = sanitize_input($_POST['skills'] ?? '');
$project_type = sanitize_input($_POST['project_type'] ?? '');
$budget_min = filter_input(INPUT_POST, 'budget_min', FILTER_VALIDATE_FLOAT);
$budget_max = filter_input(INPUT_POST, 'budget_max', FILTER_VALIDATE_FLOAT);
$deadline = sanitize_input($_POST['deadline'] ?? '');

// Basic validation
if (empty($title) || empty($description) || empty($category_id) || empty($project_type)) {
    redirect('/pages/projects/create.php?error=empty');
    exit;
}

// Validate budget range
if ($budget_min !== false && $budget_max !== false && $budget_min > $budget_max) {
    redirect('/pages/projects/create.php?error=invalid_budget');
    exit;
}

// Process skills
$skills_array = array_map('trim', explode(',', $skills_input));
$skill_ids = [];

// Database connection
$db = getDbConnection();
if (!$db) {
    redirect('/pages/projects/create.php?error=db_error');
    exit;
}

try {
    // Begin transaction
    $db->beginTransaction();

    // 1. Insert project into projects table
    $budget_min_param = ($budget_min === false || $budget_min === '') ? "NULL" : $budget_min;
    $budget_max_param = ($budget_max === false || $budget_max === '') ? "NULL" : $budget_max;
    $deadline_param = empty($deadline) ? "NULL" : "'$deadline'";
    
    $query = "INSERT INTO projects (client_id, title, description, category_id, project_type, budget_min, budget_max, deadline, creation_date, status) 
              VALUES ('$client_id', '$title', '$description', '$category_id', '$project_type', $budget_min_param, $budget_max_param, $deadline_param, NOW(), 'open')";
    
    $db->query($query);
    $project_id = $db->lastInsertId();

    // 2. Process and insert skills
    if (!empty($skills_array)) {
        foreach ($skills_array as $skill_name) {
            if (empty($skill_name)) continue;

            // Check if skill exists
            $query = "SELECT skill_id FROM skills WHERE skill_name = '$skill_name'";
            $result = $db->query($query);
            
            $current_skill_id = null;
            if ($result && $result->rowCount() > 0) {
                $skill_result = $result->fetch(PDO::FETCH_ASSOC);
                $current_skill_id = $skill_result['skill_id'];
            } else {
                // Skill doesn't exist, insert it
                $query = "INSERT INTO skills (skill_name, category_id) VALUES ('$skill_name', '$category_id')";
                $db->query($query);
                $current_skill_id = $db->lastInsertId();
            }

            // Associate skill with project
            if ($current_skill_id) {
                try {
                    $query = "INSERT INTO project_skills (project_id, skill_id) VALUES ('$project_id', '$current_skill_id')";
                    $db->query($query);
                } catch (PDOException $e) {
                    // Ignore duplicate entry errors if UNIQUE constraint exists
                    if ($e->getCode() != 23000) {
                        throw $e; // Re-throw other errors
                    }
                }
            }
        }
    }

    // 3. Handle file uploads
    $upload_dir = BASE_PATH . '/public_html/uploads/project_attachments/' . $project_id;
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $file_count = count($_FILES['attachments']['name']);
        for ($i = 0; $i < $file_count; $i++) {
            $file = [
                'name' => $_FILES['attachments']['name'][$i],
                'type' => $_FILES['attachments']['type'][$i],
                'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                'error' => $_FILES['attachments']['error'][$i],
                'size' => $_FILES['attachments']['size'][$i]
            ];

            $upload_result = upload_file($file, $upload_dir);

            if ($upload_result['success']) {
                $query = "INSERT INTO project_attachments (project_id, file_name, file_path, file_size, upload_date, file_type) 
                          VALUES ('$project_id', '{$file['name']}', '{$upload_result['path']}', '{$file['size']}', NOW(), '{$file['type']}')";
                $db->query($query);
            } else {
                // Handle upload error - maybe log it, but continue transaction for now
                error_log("File upload failed for project $project_id: " . $upload_result['error']);
            }
        }
    }

    // Commit transaction
    $db->commit();

    // Redirect to the newly created project page or dashboard
    redirect("/pages/dashboard/client-dashboard.php?message=project_created");
    exit;

} catch (PDOException $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    // Log error
    error_log("Project creation error: " . $e->getMessage());

    // Redirect back with error
    redirect('/pages/projects/create.php?error=db_error');
    exit;
}
