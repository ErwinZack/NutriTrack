<?php
require_once "../../includes/auth_health_worker.php";
require_once "../../config/config.php";
require_once "../../includes/functions.php";

$worker_id = (int) $_SESSION['worker_id'];

/* Filter tab — all, unread, read */
$filter = isset($_GET['filter']) && in_array($_GET['filter'], ['all', 'unread', 'read']) ? $_GET['filter'] : 'all';

/* Build query based on filter */
$whereClause = "WHERE n.recipient_id = :id AND n.recipient_role = 'health_worker'";
if ($filter === 'unread') $whereClause .= " AND n.is_read = 0";
if ($filter === 'read')   $whereClause .= " AND n.is_read = 1";

$stmt = $pdo->prepare("
    SELECT
        n.notification_id,
        n.message,
        n.is_read,
        n.created_at,
        n.sender_id,
        n.sender_role,
        hw.full_name     AS hw_full_name,
        hw.profile_image  AS hw_profile_image,
        a.full_name      AS a_full_name,
        a.profile_image   AS a_profile_image
    FROM notifications n
    LEFT JOIN health_workers hw ON n.sender_id = hw.worker_id AND n.sender_role = 'health_worker'
    LEFT JOIN admins a          ON n.sender_id = a.admin_id   AND n.sender_role = 'admin'
    $whereClause
    ORDER BY n.created_at DESC
");
$stmt->execute([':id' => $worker_id]);
$notifications = $stmt->fetchAll();

/* Counts for tab badges */
$totalCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_id = :id AND recipient_role = 'health_worker'");
$totalCount->execute([':id' => $worker_id]);
$total = (int) $totalCount->fetchColumn();

$unreadCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_id = :id AND recipient_role = 'health_worker' AND is_read = 0");
$unreadCount->execute([':id' => $worker_id]);
$unread = (int) $unreadCount->fetchColumn();

$readCount = $total - $unread;

/* Helper: get initials from full name */
function senderInitials(string $name): string {
    $parts    = explode(' ', trim($name));
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $initials .= strtoupper(substr(end($parts), 0, 1));
    return $initials;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notifications | NutriTrack</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/health_worker.css">
    <link rel="stylesheet" href="../../assets/css/notifications.css">
</head>
<body>

<?php include "../../includes/header.php"; ?>
<?php include "../../includes/sidebar_health_worker.php"; ?>

<main class="dashboard">
    <h1 class="page-title">Notifications</h1>

    <section class="section">

        <!-- Filter Tabs -->
        <div class="notif-page-tabs">
            <a href="?filter=all"    class="notif-page-tab <?= $filter === 'all'    ? 'active' : '' ?>">
                All <span class="notif-page-tab-badge"><?= $total ?></span>
            </a>
            <a href="?filter=unread" class="notif-page-tab <?= $filter === 'unread' ? 'active' : '' ?>">
                Unread <span class="notif-page-tab-badge unread"><?= $unread ?></span>
            </a>
            <!-- <a href="?filter=read"   class="notif-page-tab <?= $filter === 'read'   ? 'active' : '' ?>">
                Read <span class="notif-page-tab-badge"><?= $readCount ?></span>
            </a> -->
        </div>

        <!-- Notifications List -->
        <div class="notif-page-list">
            <?php if (!empty($notifications)): ?>
                <?php foreach ($notifications as $n):

                    /* Resolve sender name and profile image */
                    if (!empty($n['sender_id']) && $n['sender_role'] === 'health_worker') {
                        $senderName  = $n['hw_full_name']     ?? null;
                        $senderImage = $n['hw_profile_image'] ?? null;
                    } elseif (!empty($n['sender_id']) && $n['sender_role'] === 'admin') {
                        $senderName  = $n['a_full_name']     ?? null;
                        $senderImage = $n['a_profile_image'] ?? null;
                    } else {
                        $senderName  = null;
                        $senderImage = null;
                    }

                ?>
                    <div class="notif-page-item <?= $n['is_read'] == 0 ? 'unread' : 'read' ?>"
                         data-id="<?= $n['notification_id'] ?>">

                        <!-- Sender Avatar -->
                        <?php if ($senderName): ?>
                            <?php if (!empty($senderImage) && file_exists("../../" . $senderImage)): ?>
                                <img src="../../<?= htmlspecialchars($senderImage) ?>"
                                     alt="<?= htmlspecialchars($senderName) ?>"
                                     class="notif-sender-avatar">
                            <?php else: ?>
                                <div class="notif-sender-initials">
                                    <?= senderInitials($senderName) ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="notif-avatar-fallback">🔔</div>
                        <?php endif; ?>

                        <!-- Message + Time -->
                        <div class="notif-page-item-content">
                            <div class="notif-page-item-message">
                                <?= htmlspecialchars($n['message']) ?>
                            </div>
                            <div class="notif-page-item-time">
                                <?= date('F j, Y · g:i A', strtotime($n['created_at'])) ?>
                            </div>
                        </div>

                        <!-- Right side — 3-dot menu + unread dot -->
                        <div class="notif-item-right">

                            <!-- 3-dot Menu -->
                            <div class="notif-menu-wrap">
                                <button class="notif-menu-btn" onclick="toggleNotifMenu(this, event)">
                                    <span class="ndot"></span>
                                    <span class="ndot"></span>
                                    <span class="ndot"></span>
                                </button>
                                <div class="notif-menu-dropdown">
                                    <button class="notif-menu-item danger" onclick="deleteNotification(this)">
                                        <i class="fas fa-trash-alt"></i> Delete notification
                                    </button>
                                </div>
                            </div>

                            <!-- Unread / Read dot -->
                            <span class="notif-indicator <?= $n['is_read'] == 1 ? 'read' : '' ?>"></span>

                        </div>

                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="notif-page-empty">
                    <div class="notif-empty-icon">🔔</div>
                    <p>No notifications found.</p>
                </div>
            <?php endif; ?>
        </div>

    </section>
</main>

<?php include "../../includes/footer.php"; ?>

<script>
/* Toggle 3-dot dropdown */
function toggleNotifMenu(btn, e) {
    e.stopPropagation();
    const dropdown = btn.nextElementSibling;
    document.querySelectorAll('.notif-menu-dropdown.open').forEach(d => {
        if (d !== dropdown) d.classList.remove('open');
    });
    dropdown.classList.toggle('open');
}

/* Delete notification */
function deleteNotification(btn) {
    const item = btn.closest('.notif-page-item');
    const id   = item.dataset.id;

    fetch('../../includes/delete_notification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ notification_id: parseInt(id) })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            item.style.transition = 'opacity 0.25s, transform 0.25s';
            item.style.opacity    = '0';
            item.style.transform  = 'translateX(16px)';
            setTimeout(() => item.remove(), 280);
        }
    })
    .catch(() => {});
}

/* Close dropdown when clicking outside */
document.addEventListener('click', () => {
    document.querySelectorAll('.notif-menu-dropdown.open').forEach(d => d.classList.remove('open'));
});
</script>

</body>
</html>