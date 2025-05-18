<?php
// Include bootstrap and check login
require_once __DIR__ . "/../../bootstrap.php";

// Ensure user is a logged-in freelancer
if (!is_logged_in() || get_user_type() !== 'freelancer') {
    redirect('/pages/auth/login.php?error=unauthorized');
    exit;
}

// Get project ID
$project_id = isset($_GET['project_id']) ? filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT) : null;
if (!$project_id) {
    redirect('/pages/projects/list.php?error=invalid_project');
    exit;
}

$db = getDbConnection();

// Check if project exists and is open
$query = "SELECT title, status FROM projects WHERE project_id = '$project_id'";
$result = $db->query($query);
$project = $result->fetch(PDO::FETCH_ASSOC);

if (!$project || $project['status'] !== 'open') {
    redirect('/pages/projects/list.php?error=project_closed');
    exit;
}

// Check if this freelancer already submitted a proposal
$freelancer_id = get_current_user_id();
$check_query = "SELECT proposal_id FROM proposals WHERE project_id = '$project_id' AND freelancer_id = '$freelancer_id'";
$check_result = $db->query($check_query);
$existing_proposal = $check_result->fetch();

if ($existing_proposal) {
    redirect('/pages/projects/view.php?id=' . $project_id . '&message=already_applied');
    exit;
}

// Include header
require_once __DIR__ . "/../../includes/header.php";
?>

<div class="container">
    <div class="form-container">
        <h2 class="form-title">Submit Proposal for: "<?php echo htmlspecialchars($project['title']); ?>"</h2>
        <?php display_message(); ?>

        <form action="/pages/proposals/submit_process.php" method="post" class="validate-form">
            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">

            <div class="form-group">
                <label for="cover_letter">Cover Letter</label>
                <textarea id="cover_letter" name="cover_letter" rows="6" required placeholder="Write your cover letter here..."></textarea>
            </div>

            <div class="form-group">
                <label for="price">Your Bid ($)</label>
                <input type="number" id="price" name="price" step="0.01" required>
            </div>

            <div class="form-group">
                <label for="estimated_completion_days">Estimated Time (Days)</label>
                <input type="number" id="estimated_completion_days" name="estimated_completion_days" min="1" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn">Submit Proposal</button>
                <a href="/pages/projects/view.php?id=<?php echo $project_id; ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php
// Include footer
require_once __DIR__ . "/../../includes/footer.php";
?>
