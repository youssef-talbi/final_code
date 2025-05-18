<?php
// Include bootstrap
require_once __DIR__ ."/../../bootstrap.php";
$baseUrl="/final_code";
// Get project ID from query string
$project_id = isset($_GET["id"]) ? filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT) : null;

if (!$project_id) {
    redirect("improved/pages/projects/list.php?error=invalid_project_id");
    exit;
}

// Database connection
$db = getDbConnection();
if (!$db) {
    echo "Database connection error."; // Replace with a proper error page
    exit;
}

// Fetch project data
$query = "SELECT p.*, c.category_name, 
                 u.user_id as client_user_id, u.first_name as client_first_name, u.last_name as client_last_name, u.registration_date as client_reg_date,
                 cp.company_name, cp.website AS client_website
          FROM projects p
          JOIN categories c ON p.category_id = c.category_id
          JOIN users u ON p.client_id = u.user_id
          LEFT JOIN client_profiles cp ON u.user_id = cp.user_id
          WHERE p.project_id = '$project_id'";
$result = $db->query($query);
$project = $result->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    // Project not found
    redirect("/pages/projects/list.php?error=project_not_found");
    exit;
}

// Fetch project skills
$skill_query = "SELECT s.skill_name FROM skills s JOIN project_skills ps ON s.skill_id = ps.skill_id WHERE ps.project_id = '$project_id'";
$skill_result = $db->query($skill_query);
$skills = $skill_result->fetchAll(PDO::FETCH_COLUMN);

// Fetch project attachments
$attachment_query = "SELECT attachment_id, file_name, file_path, file_size FROM project_attachments WHERE project_id = '$project_id'";
$attachment_result = $db->query($attachment_query);
$attachments = $attachment_result->fetchAll(PDO::FETCH_ASSOC);

// Fetch proposals for this project
$proposal_query = "SELECT pr.*, u.user_id as freelancer_user_id, u.first_name as freelancer_first_name, u.last_name as freelancer_last_name, 
                        fp.headline as freelancer_headline, u.profile_picture as freelancer_picture,
                        COALESCE(AVG(r.rating), 0) as freelancer_avg_rating, COUNT(DISTINCT r.review_id) as freelancer_review_count
                   FROM proposals pr
                   JOIN users u ON pr.freelancer_id = u.user_id
                   JOIN freelancer_profiles fp ON u.user_id = fp.user_id
                   LEFT JOIN reviews r ON u.user_id = r.reviewee_id
                   WHERE pr.project_id = '$project_id'
                   GROUP BY pr.proposal_id, u.user_id, u.first_name, u.last_name, fp.headline, u.profile_picture
                   ORDER BY pr.submission_date DESC";
$proposal_result = $db->query($proposal_query);
$proposals = $proposal_result->fetchAll(PDO::FETCH_ASSOC);

// Check user status for conditional display
$is_logged_in = is_logged_in();
$current_user_id = $is_logged_in ? get_current_user_id() : null;
$current_user_type = $is_logged_in ? get_user_type() : null;
$is_project_owner = ($is_logged_in && $current_user_id == $project["client_user_id"]); // Use == for comparison
$is_freelancer_viewer = ($is_logged_in && $current_user_type === "freelancer");

// Check if the freelancer viewer has already submitted a proposal
$has_submitted_proposal = false;
if ($is_freelancer_viewer) {
    foreach ($proposals as $proposal) {
        if ($proposal["freelancer_user_id"] == $current_user_id) { // Use == for comparison
            $has_submitted_proposal = true;
            break;
        }
    }
}

// Include header
$page_title = "Project: " . htmlspecialchars($project["title"]);
require_once __DIR__ ."/../../includes/header.php";
// Check if there's an awarded contract for this project
$contract_query = "SELECT * FROM contracts WHERE project_id = '$project_id'";
$contract_result = $db->query($contract_query);
$current_contract = $contract_result->fetch(PDO::FETCH_ASSOC);
$awarded_freelancer_id = $current_contract ? $current_contract['freelancer_id'] : null;


?>

<div class="container mt-5">
    <?php display_message(); ?>

    <div class="flex" style="gap: 30px; flex-wrap: wrap;">
        <!-- Main Project Details -->
        <div style="flex: 2.5; min-width: 300px;">
            <div class="card mb-4">
                <div class="card-content">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <h1 class="mb-1"><?php echo htmlspecialchars($project["title"]); ?></h1>
                            <p class="text-muted">Posted <?php echo time_ago($project["creation_date"]); ?> in <a href="<?php echo $baseUrl; ?>/pages/projects/list.php?category=<?php echo $project["category_id"]; ?>"><?php echo htmlspecialchars($project["category_name"]); ?></a></p>
                        </div>
                        <span class="badge <?php echo $project["status"] === 'open' ? 'badge-success' : 'badge-secondary'; ?>" style="font-size: 1rem;"><?php echo ucfirst($project["status"]); ?></span>
                    </div>

                    <hr class="mb-4">

                        <p><?php echo nl2br(htmlspecialchars($project["description"])); ?></p>

                        <?php if (!empty($skills)): ?>
                            <div class="mt-4 mb-4">
                                <strong>Skills Required:</strong>
                                <div class="flex gap-10 flex-wrap mt-2 tags">
                                    <?php foreach ($skills as $skill): ?>
                                        <span class="tag"><?php echo htmlspecialchars($skill); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($attachments)): ?>
                            <div class="mt-4 mb-4">
                                <strong>Attachments:</strong>
                                <ul style="list-style: none; padding: 0; margin-top: 0.5rem;">
                                    <?php foreach ($attachments as $attachment): ?>
                                        <li class="mb-1">
                                            <a href="<?php echo htmlspecialchars(get_attachment_url($attachment["file_path"])); ?>" target="_blank">
                                                <?php echo htmlspecialchars($attachment["file_name"]); ?>
                                                <small class="text-muted">(<?php echo format_file_size($attachment["file_size"]); ?>)</small>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Proposals Section -->
                <div class="card mb-4">
                    <div class="card-content">
                        <h3>Proposals Received (<?php echo count($proposals); ?>)</h3>

                        <?php if ($is_project_owner): ?>
                            <?php if (empty($proposals)): ?>
                                <p class="text-muted">No proposals submitted yet.</p>
                            <?php else: ?>
                                <?php foreach ($proposals as $proposal): ?>
                                    <div class="card mb-3">
                                        <div class="card-content">
                                            <div class="flex gap-20 items-start">
                                                <img src="<?php echo htmlspecialchars(get_profile_picture_url($proposal["freelancer_picture"])); ?>" alt="<?php echo htmlspecialchars($proposal["freelancer_first_name"]); ?>" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover;">
                                                <div>
                                                    <div class="flex justify-between items-center mb-1">
                                                        <h4 class="mb-0"><a href="<?php echo $baseUrl; ?>/pages/profile/view.php?id=<?php echo $proposal["freelancer_user_id"]; ?>"><?php echo htmlspecialchars($proposal["freelancer_first_name"] . " " . $proposal["freelancer_last_name"]); ?></a></h4>
                                                        <span class="text-muted"><?php echo time_ago($proposal["submission_date"]); ?></span>
                                                    </div>
                                                    <p class="text-primary mb-1"><?php echo htmlspecialchars($proposal["freelancer_headline"] ?? "Freelancer"); ?></p>
                                                    <div class="mb-2">
                                                        <span style="color: #ffc107;">★</span>
                                                        <?php echo number_format($proposal["freelancer_avg_rating"], 1); ?>
                                                        <span class="text-muted">(<?php echo $proposal["freelancer_review_count"]; ?> reviews)</span>
                                                    </div>
                                                    <p><strong>Proposed Bid:</strong> <?php echo format_currency($proposal["price"]); ?> (<?php echo $proposal["estimated_completion_days"]; ?> days)</p> <!-- Corrected field names -->
                                                    <p><?php echo nl2br(htmlspecialchars(truncate_text($proposal["cover_letter"], 200))); ?></p>
                                                    <div class="mt-2">

                                                        <a href="<?php echo $baseUrl; ?>/pages/messaging/conversation.php?recipient_id=<?php echo $proposal["freelancer_user_id"]; ?>" class="btn btn-sm btn-secondary">Message</a>

                                                        <?php if ($awarded_freelancer_id == $proposal["freelancer_user_id"]): ?>
                                                            <!-- Show Revoke Button for awarded freelancer -->
                                                            <button class="btn btn-danger" onclick="openRevokeModal()">Revoke Award</button>

                                                            <!-- Modal -->
                                                            <div id="revokeModal" class="modal" style="display:none;">
                                                                <div class="modal-content">
                                                                    <h3>Confirm Revocation</h3>
                                                                    <p>Are you sure you want to revoke the award? This will cancel the contract and reopen the project.</p>
                                                                    <form method="POST" action="<?php echo $baseUrl; ?>/pages/contracts/revoke_contract.php">
                                                                        <input type="hidden" name="project_id" value="<?= htmlspecialchars($proposal['project_id']) ?>">
                                                                        <button type="submit" class="btn btn-danger">Yes, Revoke</button>
                                                                        <button type="button" onclick="closeRevokeModal()" class="btn btn-secondary">Cancel</button>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        <?php elseif (!$awarded_freelancer_id): ?>

                                                            <a href="<?php echo $baseUrl; ?>/pages/contracts/award.php?proposal_id=<?php echo $proposal['proposal_id']; ?>" class="btn btn-sm btn-success">Award Project</a>
                                                        <?php endif; ?>
                                                        <script>
                                                            function openRevokeModal() {
                                                                document.getElementById('revokeModal').style.display = 'flex';
                                                            }
                                                            function closeRevokeModal() {
                                                                document.getElementById('revokeModal').style.display = 'none';
                                                            }
                                                        </script>


                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php elseif ($is_freelancer_viewer): ?>
                            <p class="text-muted">Proposals are only visible to the project owner.</p>
                        <?php else: ?>
                            <p class="text-muted">Please <a href="/pages/auth/login.php?redirect=/pages/projects/view.php?id=<?php echo $project_id; ?>">log in</a> as the project owner to view proposals.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Sidebar (Project Info & Client Info) -->
            <div style="flex: 1; min-width: 250px;">
                <!-- Submit Proposal Button -->
                <?php if ($project["status"] === 'open'): ?>
                    <?php if ($is_freelancer_viewer && !$is_project_owner): ?>
                        <?php if ($has_submitted_proposal): ?>
                            <div class="card mb-4">
                                <div class="card-content text-center">
                                    <p class="text-success">You have submitted a proposal for this project.</p>
                                    <a href="#" class="btn btn-secondary btn-sm mt-2">View/Edit Proposal</a>
                                </div>
                            </div>
                        <?php else: ?>
                            <a href="<?php echo $baseUrl; ?>/pages/proposals/submit.php?project_id=<?php echo $project_id; ?>" class="btn btn-lg mb-4" style="width: 100%;">Submit a Proposal</a>
                        <?php endif; ?>
                    <?php elseif (!$is_logged_in): ?>
                        <div class="card mb-4">
                            <div class="card-content text-center">
                                <p>Interested in this project?</p>
                                <a href="/pages/auth/login.php?redirect=/pages/projects/view.php?id=<?php echo $project_id; ?>" class="btn btn-sm">Log In to Submit Proposal</a>
                                <p class="mt-2"><small>New here? <a href="<?php echo $baseUrl; ?>/pages/auth/register.php">Sign Up</a></small></p>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Project Details Card -->
                <div class="card mb-4">
                    <div class="card-content">
                        <h3>Project Details</h3>
                        <ul class="list-unstyled">
                            <li class="mb-2">
                                <strong>Project Type:</strong><br>
                                <?php echo ucfirst(htmlspecialchars($project["project_type"])); ?>
                            </li>
                            <li class="mb-2">
                                <strong>Budget:</strong><br>
                                <?php
                                if ($project["budget_min"] && $project["budget_max"]) {
                                    echo format_currency($project["budget_min"]) . " - " . format_currency($project["budget_max"]);
                                } elseif ($project["budget_min"]) {
                                    echo "From " . format_currency($project["budget_min"]);
                                } elseif ($project["budget_max"]) {
                                    echo "Up to " . format_currency($project["budget_max"]);
                                } else {
                                    echo "Not specified";
                                }
                                ?>
                            </li>
                            <li class="mb-2">
                                <strong>Deadline:</strong><br>
                                <?php echo $project["deadline"] ? format_date($project["deadline"]) : "Not specified"; ?>
                            </li>
                            <li class="mb-2">
                                <strong>Status:</strong><br>
                                <?php echo ucfirst(htmlspecialchars($project["status"])); ?>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Client Info Card -->
                <div class="card mb-4">
                    <div class="card-content">
                        <h3>About the Client</h3>
                        <div class="mb-3">
                            <a href="<?php echo $baseUrl; ?>/pages/profile/view.php?id=<?php echo $project["client_user_id"]; ?>">
                                <?php echo htmlspecialchars($project["client_first_name"] . " " . $project["client_last_name"]); ?>
                            </a>
                        </div>
                        <ul class="list-unstyled">
                            <?php if (!empty($project["company_name"])): ?>
                                <li class="mb-2">
                                    <strong>Company:</strong><br>
                                    <?php echo htmlspecialchars($project["company_name"]); ?>
                                </li>
                            <?php endif; ?>
                            <li class="mb-2">
                                <strong>Member Since:</strong><br>
                                <?php echo format_date($project["client_reg_date"]); ?>
                            </li>
                            <?php if (!empty($project["client_website"])): ?>
                                <li class="mb-2">
                                    <strong>Website:</strong><br>
                                    <a href="<?php echo htmlspecialchars($project["client_website"]); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo htmlspecialchars($project["client_website"]); ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php
// Include footer
require_once __DIR__ . "/../../includes/footer.php";
?>
