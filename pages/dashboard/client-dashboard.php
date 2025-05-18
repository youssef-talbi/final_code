<?php
require_once __DIR__ . '/../../includes/header.php';
$baseUrl="/final_code";
// Check if logged in and is a client
if (!is_logged_in() || get_user_type() !== 'client') {
    redirect('/pages/auth/login.php', 'Unauthorized access. Please login as a client.', 'danger');
}
$db = getDbConnection();
$user_id = get_current_user_id();

$totalProjects = 0;
$totalSpent = 0;

if ($db) {
    $query = "SELECT COUNT(*) FROM projects WHERE client_id = '$user_id'";
    $result = $db->query($query);
    $totalProjects = $result->fetchColumn();

    $query2 = "SELECT SUM(total_amount) FROM contracts WHERE client_id = '$user_id' AND status = 'completed'";
    $result2 = $db->query($query2);
    $totalSpent = $result2->fetchColumn() ?? 0;
}

?>

<div class="container mt-5">
    <h2>👋 Welcome, <?php echo get_user_name(); ?></h2>
    <p class="text-muted">This is your client dashboard. You can manage your projects, view proposals, and hire freelancers.</p>
    <p><strong>Projects Posted:</strong> <?php echo $totalProjects; ?></p>
    <p><strong>Total Spent:</strong> <?php echo format_currency($totalSpent); ?></p>

    <div class="grid mt-4">
        <div class="card">
            <div class="card-content">
                <h3>📢 My Projects</h3>
                <p>View and manage the projects you've posted.</p>
                <a href="<?php echo $baseUrl; ?>/pages/projects/my_projects.php" class="btn btn-sm">Manage Projects</a>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <h3>📬 Proposals</h3>
                <p>Review proposals submitted by freelancers.</p>
                <a href="<?php echo $baseUrl; ?>/pages/proposals/received.php" class="btn btn-sm">View Proposals</a>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <h3>🧑‍💼 Hires</h3>
                <p>Manage freelancers you've hired and ongoing contracts.</p>
                <a href="<?php echo $baseUrl; ?>/pages/contracts/client_contracts.php" class="btn btn-sm">Manage Contracts</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
