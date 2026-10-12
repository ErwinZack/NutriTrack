<aside class="sidebar">
    <div class="sidebar-header">
        <img src="../../assets/images/nutritrack-logo.png" alt="NutriTrack Logo" class="sidebar-logo">
        <div>
            <h2 class="sidebar-title">NutriTrack</h2>
            <p class="sidebar-subtitle">Admin Panel</p>
        </div>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <li class="nav-section-label">Main</li>

            <li>
                <a href="dashboard_admin.php"
                    class="<?= basename($_SERVER['PHP_SELF']) == 'dashboard_admin.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-house"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li class="nav-section-label">Management</li>

            <li>
                <a href="user_management.php"
                    class="<?= basename($_SERVER['PHP_SELF']) == 'user_management.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-users"></i>
                    <span>Manage Users</span>
                </a>
            </li>

            <li>
                <a href="add_user.php" class="<?= basename($_SERVER['PHP_SELF']) == 'add_user.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>Add User</span>
                </a>
            </li>

            <li class="nav-section-label">Monitoring</li>

            <li>
                <a href="audit_logs.php"
                    class="<?= basename($_SERVER['PHP_SELF']) == 'audit_logs.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>Audit Logs</span>
                </a>
            </li>

            <!-- <li>
                <a href="blockchain_ledger.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'blockchain_ledger.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-link"></i>
                    <span>Blockchain Ledger</span>
                </a>
            </li> -->

            <!-- <li>
                <a href="health_records_admin.php"
                    class="<?= basename($_SERVER['PHP_SELF']) == 'health_records_admin.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-file-medical"></i>
                    <span>Health Records</span>
                </a>
            </li> -->

            <li class="nav-section-label">Programs</li>

            <li>
                <a href="programs_admin.php"
                    class="<?= basename($_SERVER['PHP_SELF']) == 'programs_admin.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-clipboard-list"></i>
                    <span>Programs</span>
                </a>
            </li>

            <li class="nav-section-label">Account</li>

            <li>
                <a href="profile_admin.php"
                    class="<?= basename($_SERVER['PHP_SELF']) == 'profile_admin.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-circle-user"></i>
                    <span>My Profile</span>
                </a>
            </li>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <a href="#" class="btn-logout" id="logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>

<!-- ── Logout Confirmation Modal ── -->
<div id="logout-modal" style="display:none;">
    <div class="logout-overlay" id="logout-overlay"></div>
    <div class="logout-modal-box">
        <div class="logout-modal-icon">
            <i class="fa-solid fa-right-from-bracket"></i>
        </div>
        <h3 class="logout-modal-title">Confirm Logout</h3>
        <p class="logout-modal-message">Are you sure you want to logout from NutriTrack Admin Panel?</p>
        <div class="logout-modal-actions">
            <button class="logout-modal-cancel" id="logout-cancel">
                <i class="fa-solid fa-xmark"></i> Cancel
            </button>
            <a href="logout_admin.php" class="logout-modal-confirm">
                <i class="fa-solid fa-right-from-bracket"></i> Yes, Logout
            </a>
        </div>
    </div>
</div>

<script>
    const logoutBtn = document.getElementById('logout-btn');
    const logoutModal = document.getElementById('logout-modal');
    const logoutCancel = document.getElementById('logout-cancel');
    const logoutOverlay = document.getElementById('logout-overlay');

    logoutBtn.addEventListener('click', e => {
        e.preventDefault();
        logoutModal.style.display = 'block';
    });

    logoutCancel.addEventListener('click', () => {
        logoutModal.style.display = 'none';
    });

    logoutOverlay.addEventListener('click', () => {
        logoutModal.style.display = 'none';
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') logoutModal.style.display = 'none';
    });
</script>