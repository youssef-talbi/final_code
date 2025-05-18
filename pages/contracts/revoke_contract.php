<?php
session_start();
require_once __DIR__ . "/../../bootstrap.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['project_id'])) {
    $project_id = intval($_POST['project_id']);
    $client_id = $_SESSION['user_id'];
    $conn = getDbConnection();
    
    // Fetch freelancer ID
    $query = "SELECT freelancer_id FROM contracts WHERE project_id = $project_id AND client_id = $client_id";
    $result = $conn->query($query);
    $contract = $result->fetch(PDO::FETCH_ASSOC);

    if (!$contract) {
        $_SESSION['error'] = "Unauthorized or contract not found.";
        header("Location: ../projects/list.php");
        exit;
    }

    $freelancer_id = $contract['freelancer_id'];

    // Delete contract
    $conn->query("DELETE FROM contracts WHERE project_id = $project_id");

    // Set project back to open
    $conn->query("UPDATE projects SET status = 'open' WHERE project_id = $project_id");
    
    //Delete proposal
    $conn->query("DELETE FROM proposals WHERE project_id = $project_id");
    
    // Insert notification for freelancer
    $message = "Your contract for project #$project_id has been revoked by the client.";
    $conn->query("INSERT INTO notifications (user_id, type, content, related_id, read_status) VALUES ($freelancer_id, 'contract', '$message', $project_id, 0)");

    $_SESSION['success'] = "Award successfully revoked and freelancer notified.";
    header("Location: ../projects/list.php");
    exit;
} else {
    $_SESSION['error'] = "Invalid request.";
    header("Location: ../projects/list.php");
    exit;
}
?>
