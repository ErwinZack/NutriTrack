<aside class="sidebar">
    <div class="sidebar-header">
        <img src="../../assets/images/nutritrack-logo.png" alt="NutriTrack Logo" class="sidebar-logo">
        <div>
            <h2 class="sidebar-title">NutriTrack</h2>
            <p class="sidebar-subtitle">Health Monitoring System</p>
        </div>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <li class="nav-section-label">Main</li>

            <li>
                <a href="dashboard_health_worker.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'dashboard_health_worker.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-house"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li>
                <a href="residents.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'residents.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-users"></i>
                    <span>Residents</span>
                </a>
            </li>

            <li>
                <a href="health_records.php"
                   class="<?= (basename($_SERVER['PHP_SELF']) == 'health_records.php' || basename($_SERVER['PHP_SELF']) == 'view_resident_records.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-notes-medical"></i>
                    <span>Health Records</span>
                </a>
            </li>

            <li class="nav-section-label">Reports</li>

            <li>
                <a href="analytics.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'analytics.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Analytics</span>
                </a>
            </li>

            <li>
                <a href="reports.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-file-lines"></i>
                    <span>Reports</span>
                </a>
            </li>

            <li>
                <a href="ai_nutrition_advisor.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'ai_nutrition_advisor.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-robot"></i>
                    <span>AI Nutrition Advisor</span>
                </a>
            </li>

            <li class="nav-section-label">Support</li>

            <li>
                <a href="help_health_worker.php"
                   class="<?= basename($_SERVER['PHP_SELF']) == 'help_health_worker.php' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-circle-question"></i>
                    <span>Help & Guide</span>
                </a>
            </li>

        </ul>
    </nav>

    <!-- Logout pinned to bottom -->
    <div class="sidebar-footer">
        <a href="#" class="btn-logout" id="logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>

<!-- Logout Confirmation Modal -->
<div id="logout-modal" style="display:none;">
    <div class="logout-overlay" id="logout-overlay"></div>
    <div class="logout-modal-box">
        <div class="logout-modal-icon">
            <i class="fa-solid fa-right-from-bracket"></i>
        </div>
        <h3 class="logout-modal-title">Confirm Logout</h3>
        <p class="logout-modal-message">Are you sure you want to logout from NutriTrack?</p>
        <div class="logout-modal-actions">
            <button class="logout-modal-cancel" id="logout-cancel">
                <i class="fa-solid fa-xmark"></i> Cancel
            </button>
            <a href="logout_health_worker.php" class="logout-modal-confirm">
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