-- =====================================================================
-- MUNICIPAL ROLE HIERARCHY MIGRATION — Final Version
-- Adds Municipal (Superadmin) tier + barangay scoping to:
-- admins, health_workers, residents, health_records, audit_log, notifications
-- =====================================================================

-- ─────────────────────────────────────────────
-- 1. NEW TABLE: barangays
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS barangays (
    barangay_id INT AUTO_INCREMENT PRIMARY KEY,
    barangay_name VARCHAR(100) NOT NULL UNIQUE,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ─────────────────────────────────────────────
-- 2. NEW TABLE: superadmins (Municipal)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS superadmins (
    superadmin_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    profile_image VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ─────────────────────────────────────────────
-- 3. admins → link to barangay (1 admin = 1 barangay)
-- ─────────────────────────────────────────────
ALTER TABLE admins
    ADD COLUMN barangay_id INT NULL AFTER role,
    ADD CONSTRAINT fk_admins_barangay
        FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id);

-- ─────────────────────────────────────────────
-- 4. health_workers → link directly to barangay
--    (no admin_id existed before — adding it too, so you know
--     WHICH admin manages each worker, not just which barangay)
-- ─────────────────────────────────────────────
ALTER TABLE health_workers
    ADD COLUMN admin_id INT NULL AFTER role,
    ADD COLUMN barangay_id INT NULL AFTER admin_id,
    ADD CONSTRAINT fk_health_workers_admin
        FOREIGN KEY (admin_id) REFERENCES admins(admin_id),
    ADD CONSTRAINT fk_health_workers_barangay
        FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id);

-- ─────────────────────────────────────────────
-- 5. residents → link directly to barangay
--    (residents had NO owner column at all before this)
-- ─────────────────────────────────────────────
ALTER TABLE residents
    ADD COLUMN barangay_id INT NULL AFTER address,
    ADD CONSTRAINT fk_residents_barangay
        FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id);

-- ─────────────────────────────────────────────
-- 6. health_records → add barangay_id directly
--    (kept as its own column, not derived via JOIN every query,
--     for faster filtering on reports/analytics pages)
-- ─────────────────────────────────────────────
ALTER TABLE health_records
    ADD COLUMN barangay_id INT NULL AFTER worker_id,
    ADD CONSTRAINT fk_health_records_barangay
        FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id);

-- ─────────────────────────────────────────────
-- 7. audit_log → so Municipal can filter logs per barangay
-- ─────────────────────────────────────────────
ALTER TABLE audit_log
    ADD COLUMN barangay_id INT NULL,
    ADD CONSTRAINT fk_audit_log_barangay
        FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id);

-- ─────────────────────────────────────────────
-- 8. notifications → prevent cross-barangay alert leaks
-- ─────────────────────────────────────────────
ALTER TABLE notifications
    ADD COLUMN barangay_id INT NULL,
    ADD CONSTRAINT fk_notifications_barangay
        FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id);

-- Unaffected on purpose: login_attempts, email_otp, password_resets
-- (keyed by email/username, not barangay), and system_settings
-- (Municipal-only, town-wide by design).

-- ─────────────────────────────────────────────
-- 9. SEED: barangays + assign your 3 existing admins
--    (rename to match your real Calasiao barangays)
-- ─────────────────────────────────────────────
INSERT INTO barangays (barangay_name, status) VALUES
    ('Gabon', 'active'),
    ('Poblacion', 'active'),
    ('Lasip', 'active');

UPDATE admins SET barangay_id = (SELECT barangay_id FROM barangays WHERE barangay_name = 'Gabon')     WHERE admin_id = 1;
UPDATE admins SET barangay_id = (SELECT barangay_id FROM barangays WHERE barangay_name = 'Poblacion') WHERE admin_id = 2;
UPDATE admins SET barangay_id = (SELECT barangay_id FROM barangays WHERE barangay_name = 'Lasip')     WHERE admin_id = 3;

-- ─────────────────────────────────────────────
-- 10. MIGRATE: since health_workers had no admin link before,
--     assign your 3 existing workers to admin/barangay manually
--     (adjust worker_id → admin_id mapping to match reality)
-- ─────────────────────────────────────────────
UPDATE health_workers SET admin_id = 1, barangay_id = (SELECT barangay_id FROM admins WHERE admin_id = 1) WHERE worker_id = 1;
UPDATE health_workers SET admin_id = 2, barangay_id = (SELECT barangay_id FROM admins WHERE admin_id = 2) WHERE worker_id = 2;
UPDATE health_workers SET admin_id = 3, barangay_id = (SELECT barangay_id FROM admins WHERE admin_id = 3) WHERE worker_id = 3;

-- ─────────────────────────────────────────────
-- 11. MIGRATE: residents → inherit barangay via the worker
--     who recorded their health_records (best available link)
-- ─────────────────────────────────────────────
UPDATE residents r
JOIN health_records hr ON hr.resident_id = r.resident_id
JOIN health_workers hw ON hw.worker_id = hr.worker_id
SET r.barangay_id = hw.barangay_id
WHERE r.barangay_id IS NULL;

-- ─────────────────────────────────────────────
-- 12. MIGRATE: health_records → inherit barangay from worker_id
-- ─────────────────────────────────────────────
UPDATE health_records hr
JOIN health_workers hw ON hw.worker_id = hr.worker_id
SET hr.barangay_id = hw.barangay_id
WHERE hr.barangay_id IS NULL;

-- ─────────────────────────────────────────────
-- 13. SEED: first Superadmin (Municipal) account
--     Replace password hash with a real bcrypt hash before running
-- ─────────────────────────────────────────────
INSERT INTO superadmins (full_name, username, password, email)
VALUES ('Municipal Nutrition Office', 'municipal', '$2b$10$Kij98d84W8a/SN.JUp.Y0.3IH/ff5P/sMhu1HNp/VaWSeTuinx5DS', 'municipal@calasiao.gov.ph');