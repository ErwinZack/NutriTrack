# Database Hardening Checklist
## NutriTrack — Community Nutrition & Health Monitoring System

This document outlines the database security hardening measures applied to NutriTrack,
both at the application (PHP) level and at the MySQL server level.

---

## ✅ Application-Level Hardening (Implemented in Code)

### 1. Secure Credential Storage
- Database credentials are stored in a `.env` file outside of version control
- `config/config.php` loads credentials using `$_ENV` — no hardcoded passwords in source code
- `.env` is listed in `.gitignore` to prevent accidental exposure

### 2. PDO Prepared Statements
- All database queries use **PDO prepared statements** with bound parameters
- This prevents **SQL Injection** attacks across all pages
- Example pattern used throughout the codebase:
```php
$stmt = $pdo->prepare("SELECT * FROM residents WHERE resident_id = :id");
$stmt->execute([':id' => $id]);
```

### 3. Password Hashing
- All user passwords are hashed using **bcrypt** via `password_hash($password, PASSWORD_DEFAULT)`
- Plain text passwords are never stored in the database
- Login verification uses `password_verify()` — never direct string comparison

### 4. Role-Based Access Control (RBAC)
- Two roles defined: `admin` and `health_worker`
- `includes/auth_admin.php` — guards all admin pages
- `includes/auth_health_worker.php` — guards all health worker pages
- Unauthorized access redirects to the login page immediately

### 5. Audit Logging
- All sensitive actions are recorded in the `audit_log` table
- Logged actions include: LOGIN, LOGOUT, ADD, EDIT, DELETE for both roles
- Each log entry captures: role, user_id, action, description, ip_address, timestamp

### 6. Input Sanitization & Validation
- All user inputs are sanitized via `sanitizeInput()` before use
- Server-side validation is applied on all forms (not just client-side)
- CSRF tokens are verified on every POST request via `verifyCsrfToken()`

### 7. Session Security
- Session ID is regenerated on login to prevent **session fixation** attacks
- Session activity timestamps enforce **automatic timeout**
- Session is fully destroyed on logout including cookie cleanup

---

## ⚠️ MySQL Server-Level Hardening (Required on Production Deployment)

These steps cannot be performed in a local XAMPP environment but must be applied
when deploying NutriTrack to a live/production server.

### 1. Remove Anonymous Users
```sql
DELETE FROM mysql.user WHERE User = '';
FLUSH PRIVILEGES;
```

### 2. Remove Remote Root Login
```sql
DELETE FROM mysql.user WHERE User = 'root' AND Host != 'localhost';
FLUSH PRIVILEGES;
```

### 3. Remove Test Database
```sql
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db = 'test' OR Db = 'test\\_%';
FLUSH PRIVILEGES;
```

### 4. Create a Dedicated Application User (Least Privilege)
```sql
-- Create a user with only the permissions NutriTrack needs
CREATE USER 'nutritrack_user'@'localhost' IDENTIFIED BY 'StrongPassword123!';

-- Grant only necessary permissions (no DROP, no CREATE, no GRANT)
GRANT SELECT, INSERT, UPDATE, DELETE ON nutritrack_db.* TO 'nutritrack_user'@'localhost';
FLUSH PRIVILEGES;
```
> ⚠️ Never connect using the `root` user in production.

### 5. Enforce Strong MySQL Password Policy
```sql
SET GLOBAL validate_password.policy = MEDIUM;
SET GLOBAL validate_password.length = 12;
```

### 6. Disable LOAD DATA INFILE (Prevent File-Based Attacks)
Add to `my.cnf` or `my.ini`:
```ini
[mysqld]
local-infile = 0
```

### 7. Bind MySQL to Localhost Only
Add to `my.cnf` or `my.ini`:
```ini
[mysqld]
bind-address = 127.0.0.1
```
> This prevents MySQL from accepting connections from external IPs.

### 8. TLS/SSL Connections
- Not applicable in local XAMPP development (localhost-to-localhost)
- On production, enable SSL in PDO connection:
```php
$options = [
    PDO::MYSQL_ATTR_SSL_CA   => '/path/to/ca-cert.pem',
    PDO::MYSQL_ATTR_SSL_CERT => '/path/to/client-cert.pem',
    PDO::MYSQL_ATTR_SSL_KEY  => '/path/to/client-key.pem',
];
```

### 9. Database Encryption at Rest
- Not applicable in XAMPP development environment
- On production MySQL 8.0+, enable InnoDB tablespace encryption:
```sql
ALTER TABLE residents ENCRYPTION = 'Y';
ALTER TABLE health_records ENCRYPTION = 'Y';
ALTER TABLE admins ENCRYPTION = 'Y';
ALTER TABLE health_workers ENCRYPTION = 'Y';
ALTER TABLE audit_log ENCRYPTION = 'Y';
```

---

## 📋 Hardening Status Summary

| Measure | Status | Level |
|---|---|---|
| Secure credential storage (.env) | ✅ Done | Application |
| PDO prepared statements | ✅ Done | Application |
| bcrypt password hashing | ✅ Done | Application |
| Role-based access control | ✅ Done | Application |
| Audit logging | ✅ Done | Application |
| Input sanitization + CSRF | ✅ Done | Application |
| Session security | ✅ Done | Application |
| Remove anonymous MySQL users | 📋 Production | Server |
| Disable remote root login | 📋 Production | Server |
| Dedicated least-privilege DB user | 📋 Production | Server |
| Bind MySQL to localhost | 📋 Production | Server |
| TLS/SSL database connections | 📋 Production | Server |
| Database encryption at rest | 📋 Production | Server |

---

## Notes

- All **application-level** hardening is fully implemented in the NutriTrack codebase
- All **server-level** hardening steps are documented above and ready to apply upon production deployment
- Local development uses XAMPP which does not support server-level MySQL hardening by design

---

*Last updated: NutriTrack Security Implementation — Category 3: Database Security*
