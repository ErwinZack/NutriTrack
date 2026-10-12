<aside class="sidebar">
    <div class="sidebar-header">
        <img src="../../assets/images/nutritrack-logo.png" alt="NutriTrack Logo" class="sidebar-logo">
        <div>
            <h2 class="sidebar-title">NutriTrack</h2>
            <p class="sidebar-subtitle">Municipal Panel</p>
        </div>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <li class="nav-section-label">Main</li>

            <li>
                <a href="dashboard_municipal.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'dashboard_municipal.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-house"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li class="nav-section-label">Barangay Oversight</li>

            <li>
                <a href="barangay_management.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'barangay_management.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-map-location-dot"></i>
                    <span>Barangay Management</span>
                </a>
            </li>

            <li>
                <a href="cross_barangay_analytics.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'cross_barangay_analytics.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Cross-Barangay Analytics</span>
                </a>
            </li>

            <li>
                <a href="program_planning.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'program_planning.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-clipboard-list"></i>
                    <span>Program Planning</span>
                </a>
            </li>

            <li class="nav-section-label">Management</li>

            <li>
                <a href="user_management_municipal.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'user_management_municipal.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-users"></i>
                    <span>Manage Users</span>
                </a>
            </li>

            <li class="nav-section-label">Monitoring</li>

            <li>
                <a href="audit_logs_municipal.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'audit_logs_municipal.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>Audit Logs</span>
                </a>
            </li>

            <li>
                <a href="reports_municipal.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'reports_municipal.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-file-lines"></i>
                    <span>Reports</span>
                </a>
            </li>

            <li class="nav-section-label">Account</li>

            <li>
                <a href="profile_municipal.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'profile_municipal.php' ? 'active' : ''; ?>">
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
        <p class="logout-modal-message">Are you sure you want to logout from NutriTrack Municipal Panel?</p>
        <div class="logout-modal-actions">
            <button class="logout-modal-cancel" id="logout-cancel">
                <i class="fa-solid fa-xmark"></i> Cancel
            </button>
            <a href="logout_municipal.php" class="logout-modal-confirm">
                <i class="fa-solid fa-right-from-bracket"></i> Yes, Logout
            </a>
        </div>
    </div>
</div>

<script>
const logoutBtn     = document.getElementById('logout-btn');
const logoutModal   = document.getElementById('logout-modal');
const logoutCancel  = document.getElementById('logout-cancel');
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