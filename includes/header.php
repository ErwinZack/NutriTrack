<?php
// ----------------------------------
// AUTO PAGE TITLE
// ----------------------------------
if (!isset($pageTitle)) {
    $pageTitle = ucwords(
        str_replace(['.php', '_'], ['', ' '], basename($_SERVER['PHP_SELF']))
    );
}

// ----------------------------------
// SESSION
// ----------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../config/config.php";

// ----------------------------------
// DEFAULTS
// ----------------------------------
$profileImage = '../../assets/images/profile_icon.png';
$fullName     = 'Guest';
$profileLink  = '#';
$userLabel    = 'Guest';

// ----------------------------------
// ROLE-BASED PROFILE LOADING
// ----------------------------------
if (isset($_SESSION['role'])) {

    // ==================================
    // HEALTH WORKER
    // ==================================
    if ($_SESSION['role'] === 'health_worker' && isset($_SESSION['worker_id'])) {

        $stmt = $pdo->prepare("
            SELECT full_name, profile_image
            FROM health_workers
            WHERE worker_id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $_SESSION['worker_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $fullName     = $user['full_name'];
            $profileImage = !empty($user['profile_image'])
                ? '../../' . $user['profile_image']
                : $profileImage;
            $profileLink  = '../health_worker/profile_health_worker.php';
            $userLabel    = 'Health Worker';
        }
    }

    // ==================================
    // ADMIN
    // ==================================
    elseif ($_SESSION['role'] === 'admin' && isset($_SESSION['admin_id'])) {

        $stmt = $pdo->prepare("
            SELECT full_name, profile_image
            FROM admins
            WHERE admin_id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $_SESSION['admin_id']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            $fullName     = $admin['full_name'];
            $profileImage = !empty($admin['profile_image'])
                ? '../../' . $admin['profile_image']
                : $profileImage;
            $profileLink  = '../admin/profile_admin.php';
            $userLabel    = 'Admin';
        }
    }

    // ==================================
    // MUNICIPAL (SUPERADMIN)
    // ==================================
    elseif ($_SESSION['role'] === 'superadmin' && isset($_SESSION['superadmin_id'])) {

        $stmt = $pdo->prepare("
            SELECT full_name, profile_image
            FROM superadmins
            WHERE superadmin_id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $_SESSION['superadmin_id']]);
        $superadmin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($superadmin) {
            $fullName     = $superadmin['full_name'];
            $profileImage = !empty($superadmin['profile_image'])
                ? '../../' . $superadmin['profile_image']
                : $profileImage;
            $profileLink  = '../municipal/profile_municipal.php';
            $userLabel    = 'Municipal';
        }
    }
}

// -- Resolve notification page + endpoints based on role
$notifLink        = '#';
$notifEndpoint    = '';
$markReadEndpoint = '';

if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'health_worker') {
        $notifLink        = '../health_worker/notifications_health_worker.php';
        $notifEndpoint    = '../../pages/health_worker/get_notifications_health_worker.php';
        $markReadEndpoint = '../../pages/health_worker/mark_read_health_worker.php';
    } elseif ($_SESSION['role'] === 'admin') {
        $notifLink        = '../admin/notifications_admin.php';
        $notifEndpoint    = '../../pages/admin/get_notifications_admin.php';
        $markReadEndpoint = '../../pages/admin/mark_read_admin.php';
    } elseif ($_SESSION['role'] === 'superadmin') {
        $notifLink        = '../municipal/notifications_municipal.php';
        $notifEndpoint    = '../../pages/municipal/get_notifications_municipal.php';
        $markReadEndpoint = '../../pages/municipal/mark_read_municipal.php';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle); ?> | NutriTrack</title>

    <link rel="stylesheet" href="../../assets/css/health_worker.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <link rel="stylesheet" href="../../assets/css/municipal.css">
    <link rel="stylesheet" href="../../assets/css/notifications.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>

<header class="header">
    <div class="header-left">
        <h2 class="page-title"><?= htmlspecialchars($pageTitle); ?></h2>
    </div>

    <div class="header-right">

        <!-- ── Notification Bell ── -->
        <div class="notif-wrap">
            <button class="notif-btn" id="notifBtn" onclick="toggleNotifDropdown()" aria-label="Notifications">
                <svg viewBox="0 0 24 24">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                <span class="notif-count-badge" id="notifCountBadge"></span>
            </button>

            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-dropdown-header">
                    <span class="notif-dropdown-title">Notifications</span>
                    <span class="notif-unread-badge" id="notifUnreadBadge"></span>
                </div>
                <div id="notifList">
                    <div class="notif-loading">Loading...</div>
                </div>
              <a href="<?= htmlspecialchars($notifLink) ?>" class="notif-view-all" onclick="markRead()">View all notifications →</a>
            </div>
        </div>

        <!-- ── Profile ── -->
        <div class="profile">
            <a href="<?= htmlspecialchars($profileLink); ?>">
                <img src="<?= htmlspecialchars($profileImage); ?>" class="profile-icon" alt="Profile">
            </a>
            <span class="header-worker-name">
                <?= htmlspecialchars($userLabel . ': ' . $fullName); ?>
            </span>
        </div>

    </div>
</header>

<!-- Role-based endpoint variables for notifications.js -->
<script>
    const NOTIF_ROLE         = '<?= htmlspecialchars($_SESSION['role'] ?? '') ?>';
    const NOTIF_ENDPOINT     = '<?= htmlspecialchars($notifEndpoint) ?>';
    const NOTIF_LINK         = '<?= htmlspecialchars($notifLink) ?>';
    const MARK_READ_ENDPOINT = '<?= htmlspecialchars($markReadEndpoint) ?>';
</script>

<!-- Auto logout after 30 minutes of inactivity -->
<script>
   setTimeout(function() {
    const role = '<?= htmlspecialchars($_SESSION['role'] ?? '') ?>';
    let logoutUrl = '/NutriTrack/pages/health_worker/logout_health_worker.php';
    if (role === 'admin') {
        logoutUrl = '/NutriTrack/pages/admin/logout_admin.php';
    } else if (role === 'superadmin') {
        logoutUrl = '/NutriTrack/pages/municipal/logout_municipal.php';
    }
    window.location.href = logoutUrl;
}, 1800000);
</script>

<!-- Notification bell logic -->
<script src="../../assets/js/notifications.js"></script>

</body>
</html>