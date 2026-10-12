<?php
// pages/admin/programs_admin.php
session_start();
require_once "../../config/config.php";

// Redirect if not logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login_admin.php");
    exit();
}

$admin_id = $_SESSION['admin_id'];
$barangay_id = $_SESSION['barangay_id'];

// --------------------------------------
// FETCH BARANGAY NAME
// --------------------------------------
$barangayStmt = $pdo->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = :id LIMIT 1");
$barangayStmt->execute([':id' => $barangay_id]);
$barangay = $barangayStmt->fetch(PDO::FETCH_ASSOC);
$barangayName = $barangay['barangay_name'] ?? 'Unknown Barangay';

// --------------------------------------
// FETCH: Programs assigned to this barangay
// --------------------------------------
$stmt = $pdo->prepare("
    SELECT
        p.program_id,
        p.program_name,
        p.description,
        p.category,
        p.priority_level,
        p.start_date,
        p.end_date,
        p.created_at,
        p.target_scope
    FROM programs p
    INNER JOIN program_barangays pb ON pb.program_id = p.program_id
    WHERE pb.barangay_id = :barangay_id
    ORDER BY p.created_at DESC
");
$stmt->execute([':barangay_id' => $barangay_id]);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------
// HELPERS
// --------------------------------------
const PROGRAM_CATEGORIES = ['Feeding Program', 'Deworming', 'Nutrition Education', 'Supplementation', 'Other'];
const PRIORITY_LEVELS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

// Reuses existing admin-badge-* variants already defined in admin.css
function priorityBadgeClass($priority)
{
    switch ($priority) {
        case 'high':
            return 'admin-badge-priority-high';
        case 'medium':
            return 'admin-badge-priority-medium';
        case 'low':
            return 'admin-badge-priority-low';
        default:
            return 'admin-badge';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Programs | NutriTrack Admin</title>

    <!-- Shared admin CSS — same as manage_users.php -->
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body>

    <?php include "../../includes/header.php"; ?>
    <?php include "../../includes/sidebar_admin.php"; ?>

    <main class="dashboard">

        <!-- Page header -->
        <div class="admin-page-header">
            <div>
                <!-- <h1 class="admin-page-title">Programs</h1> -->
                <p class="admin-welcome-message">
                    Programs assigned to <strong><?= htmlspecialchars($barangayName) ?></strong> by the Municipal Health
                    Office
                </p>
            </div>
        </div>

        <section class="section">

            <!-- Top bar: always-visible program count -->
            <div class="admin-table-topbar">
                <span class="admin-filter-count" id="programCount">
                    <?= count($programs) ?> program post<?= count($programs) !== 1 ? 's' : '' ?>
                </span>
            </div>

            <!-- Filter bar: priority dropdown, category dropdown, search
                 Reuses admin-filter-bar and admin-filter-search from admin.css -->
            <div class="admin-filter-bar">

                <select id="programPriorityFilter" onchange="filterFeed()">
                    <option value="">All Priorities</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>

                <select id="programCategoryFilter" onchange="filterFeed()">
                    <option value="">All Categories</option>
                    <?php foreach (PROGRAM_CATEGORIES as $cat): ?>
                        <option value="<?= htmlspecialchars(strtolower($cat)) ?>">
                            <?= htmlspecialchars($cat) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="admin-filter-search">
                    <input type="text" id="programSearch" placeholder="Search by program name or description…"
                        oninput="filterFeed()">
                </div>

            </div>

            <!-- Program Feed -->
            <div class="admin-program-feed" id="programFeed">
                <?php if (!empty($programs)): ?>
                    <?php foreach ($programs as $p): ?>

                        <!-- data-* attrs drive the JS filter -->
                        <div class="admin-program-post" data-name="<?= htmlspecialchars(strtolower($p['program_name'])) ?>"
                            data-desc="<?= htmlspecialchars(strtolower($p['description'] ?? '')) ?>"
                            data-priority="<?= htmlspecialchars($p['priority_level']) ?>"
                            data-category="<?= htmlspecialchars(strtolower($p['category'] ?? '')) ?>">

                            <!-- Badges: priority level + category -->
                            <div class="admin-program-post-header">
                                <div class="admin-program-post-meta">
                                    <span class="admin-badge <?= priorityBadgeClass($p['priority_level']) ?>">
                                        <?= htmlspecialchars(PRIORITY_LEVELS[$p['priority_level']] ?? ucfirst($p['priority_level'])) ?>
                                    </span>
                                    <?php if (!empty($p['category'])): ?>
                                        <span class="admin-badge admin-badge-neutral">
                                            <?= htmlspecialchars($p['category']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Program name -->
                            <h3 class="admin-program-post-title">
                                <?= htmlspecialchars($p['program_name']) ?>
                            </h3>

                            <!-- Full description, not truncated -->
                            <?php if (!empty($p['description'])): ?>
                                <p class="admin-program-post-desc">
                                    <?= nl2br(htmlspecialchars($p['description'])) ?>
                                </p>
                            <?php endif; ?>

                            <!-- Footer: assigned scope (left) + date range (right) -->
                            <div class="admin-program-post-footer">
                                <div class="admin-program-post-tags">
                                    <i class="fas fa-location-dot" style="color:#4338ca;font-size:12px;"></i>
                                    <?= $p['target_scope'] === 'all'
                                        ? '<strong>All Barangays</strong>'
                                        : htmlspecialchars($barangayName) ?>
                                </div>
                                <div class="admin-program-post-date">
                                    <i class="fas fa-calendar" style="font-size:12px;"></i>
                                    <?= date('M j, Y', strtotime($p['start_date'])) ?> –
                                    <?= date('M j, Y', strtotime($p['end_date'])) ?>
                                    <span class="admin-program-post-muted">
                                        · Posted <?= date('M j, Y', strtotime($p['created_at'])) ?>
                                    </span>
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Empty state: no programs assigned to this barangay yet -->
                    <div class="admin-empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <p>No programs have been assigned to <?= htmlspecialchars($barangayName) ?> yet.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Filter empty state: shown by JS when filters return zero results -->
            <div id="programEmptyState" style="display:none;" class="admin-empty-state">
                <i class="fas fa-magnifying-glass"></i>
                <p>No programs match your current filters.</p>
            </div>

        </section>
    </main>

    <?php include "../../includes/footer.php"; ?>

    <script>
        // ============================================================
        //  PROGRAMS — Filter + Search
        //  Follows the same pattern as filterWorkersTable()
        //  in manage_users.php: reads data-* attrs, hides/shows rows,
        //  updates the count label, toggles the empty state.
        // ============================================================
        function filterFeed() {
            const priorityVal = (document.getElementById('programPriorityFilter')?.value || '').toLowerCase();
            const categoryVal = (document.getElementById('programCategoryFilter')?.value || '').toLowerCase();
            const searchVal = (document.getElementById('programSearch')?.value || '').toLowerCase().trim();

            const posts = document.querySelectorAll('#programFeed .admin-program-post');
            const emptyEl = document.getElementById('programEmptyState');
            const countEl = document.getElementById('programCount');

            let visible = 0;

            posts.forEach(post => {
                const matchPriority = !priorityVal || post.dataset.priority === priorityVal;
                const matchCategory = !categoryVal || post.dataset.category === categoryVal;
                const matchSearch = !searchVal ||
                    [post.dataset.name, post.dataset.desc].some(v => (v || '').includes(searchVal));

                const show = matchPriority && matchCategory && matchSearch;
                post.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            // Update count label
            if (countEl) countEl.textContent = visible + ' program post' + (visible !== 1 ? 's' : '');

            // Toggle filter empty state
            if (emptyEl) emptyEl.style.display = (visible === 0 && posts.length > 0) ? '' : 'none';
        }

        // Run once on load so count is always accurate
        document.addEventListener('DOMContentLoaded', filterFeed);
    </script>

</body>

</html>