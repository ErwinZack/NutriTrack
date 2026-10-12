# NutriTrack

A web-based health monitoring system for Barangay San Miguel, Calasiao, Pangasinan.
Built for the Rural Health Unit (RHU) to digitally manage resident health records, track nutritional status, and support data-driven health decisions.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Security Documentation](#2-security-documentation)
3. [API Documentation](#3-api-documentation)
4. [Deployment Guide](#4-deployment-guide)
5. [Troubleshooting](#5-troubleshooting)
6. [Maintenance Notes](#6-maintenance-notes)

---

## 1. Project Overview

**Version:** 1.0
**Duration:** December 2025 – February 2026
**Agency:** Rural Health Unit (RHU), Barangay San Miguel, Calasiao, Pangasinan

### What it does

- Manage resident health and nutrition records
- Auto-calculate BMI per health visit
- Generate AI-powered nutrition recommendations
- Visualize malnutrition trends via analytics dashboard
- Export reports in PDF and Excel format
- Full audit logging of all sensitive actions

### Tech Stack

- **Frontend:** HTML, CSS, JavaScript, Chart.js
- **Backend:** PHP 8.x with Apache (XAMPP)
- **Database:** MySQL / MariaDB
- **AI Module:** Python, Flask, scikit-learn
- **Tools:** XAMPP, VS Code

### User Roles

- **Admin** — manages health worker accounts and system settings
- **Health Worker** — manages residents, records health data, views analytics

### Default Login

- **Username:** `admin2@gmail.com`
- **Password:** `Admin@123456789`

### Project Structure

```text
NutriTrack/
├── .env                          # DB credentials (never share or commit)
├── .htaccess                     # Blocks public access to .env
├── config/config.php             # DB connection, session, CSRF setup
├── includes/
│   ├── functions.php             # Validation, CSRF, audit logging helpers
│   ├── auth_admin.php            # Admin session guard
│   └── auth_health_worker.php   # Health worker session guard
├── pages/
│   ├── admin/                    # Admin panel pages
│   └── health_worker/            # Health worker pages
├── assets/
│   ├── css/                      # Stylesheets
│   └── js/                       # Scripts, PDF/Excel export
├── ml/api/app.py                 # Flask AI API
└── database/nutri_track_db.sql   # Database schema and sample data
```

---

## 2. Security Documentation

### Authentication

- Passwords hashed with `password_hash()` using bcrypt
- Login verified with `password_verify()`
- Session regenerated on login to prevent session fixation
- Generic error messages on failed login — no user enumeration
- Session cookies use `HttpOnly` and `SameSite` flags

### Authorization

- Role-based access control — Admin and Health Worker are fully separated
- Every protected page checks both session ID and role
- Unauthorized access attempts are logged automatically

### Input Protection

- All database queries use PDO prepared statements — no SQL injection possible
- CSRF tokens on every POST form via `csrfInput()` and `verifyCsrfToken()`
- All output escaped with `htmlspecialchars()` — XSS prevented
- Server-side validation on all fields (required, email, date, password policy)
- File uploads validated by MIME type and limited to 2MB

### Password Policy

- Minimum 8 characters
- At least 1 uppercase, 1 lowercase, 1 number
- Cannot reuse current password on change

### Credentials and Secrets

- DB credentials live only in `.env` — never hardcoded in any PHP file
- `.htaccess` returns `403 Forbidden` if `.env` is accessed via browser
- PHP errors are logged silently — never shown to users

### Audit Logging

Every sensitive action is saved to the `audit_logs` table with the user, role, action, target, IP address, and timestamp.

Logged actions: `CREATE_USER` `UPDATE_USER` `DELETE_USER` `CHANGE_PASSWORD` `CREATE_HEALTH_RECORD` `UPDATE_HEALTH_RECORD`

---

## 3. API Documentation

The AI module runs as a local Flask API used by the PHP backend to generate nutrition recommendations.

**Base URL:** `http://localhost:5000/api`
**Format:** JSON
**Access:** Internal only — called by PHP, not the browser

### POST /predict_nutrition

**Request:**

```json
{
  "age": 25,
  "weight": 65,
  "height": 170,
  "gender": "Male"
}
```

**Response:**

```json
{
  "status": "Normal",
  "predicted_calories": 2200,
  "recommended_protein": 60,
  "recommendation": "Your BMI is in the healthy range...",
  "tip": "Stay active with at least 30 minutes of exercise daily..."
}
```

**Common Errors:**

- `400` — Missing or invalid fields
- `500` — Model or server error
- `503` — Flask server not running

### Starting the Flask API

```bash
cd NutriTrack/ml/environment/
pip install -r requirements.txt

cd ../api/
python app.py
```

> Keep this terminal open. AI recommendations will not work without it.

---

## 4. Deployment Guide

### Requirements

- XAMPP (Apache + MySQL)
- PHP 8.x or higher
- Python 3.8+ (for AI module)
- Any modern browser (Chrome, Firefox, Edge)

### Local Setup

#### Step 1 — Place the project

```text
C:\xampp\htdocs\NutriTrack\
```

#### Step 2 — Create the `.env` file

Create this in the project root:

```env
DB_HOST=127.0.0.1
DB_NAME=nutri_track_db
DB_USER=root
DB_PASS=
APP_ENV=local
```

#### Step 3 — Enable `.htaccess`

Open `C:\xampp\apache\conf\httpd.conf`, find the `<Directory "C:/xampp/htdocs">` block, and change:

```text
AllowOverride None  →  AllowOverride All
```

Restart Apache, then verify: visiting `localhost/NutriTrack/.env` should return `403 Forbidden`.

#### Step 4 — Set up the database

- Open `localhost/phpmyadmin`
- Create database `nutri_track_db` with `utf8mb4_general_ci` collation
- Import `database/nutri_track_db.sql`
- Confirm these tables exist: `admins`, `health_workers`, `residents`, `health_records`, `system_settings`, `audit_logs`

#### Step 5 — Access the system

- Admin: `http://localhost/NutriTrack/pages/admin/login_admin.php`
- Health Worker: `http://localhost/NutriTrack/pages/health_worker/login_health_worker.php`

### Production Checklist

- [ ] Upload to a secure server with HTTPS enabled
- [ ] Set `display_errors = Off` in `php.ini`
- [ ] Restrict write access to config files and `.env`
- [ ] Test all routes and forms before going live

---

## 5. Troubleshooting

### Cannot connect to database

Check that `.env` exists in the project root and that `DB_NAME`, `DB_USER`, `DB_PASS` match your MySQL setup.

### White screen or blank page

Open `C:\xampp\php\logs\php_error_log` to find the actual PHP error.

### Form not submitting

Make sure `<?= csrfInput() ?>` is inside the form and the session is still active.

### Login keeps redirecting back

Ensure the login page sets `$_SESSION['role']` correctly after verifying credentials.

### 403 error on all pages

`AllowOverride` is likely still set to `None`. Update `httpd.conf` and restart Apache.

### `.env` visible in browser

Same issue — `AllowOverride All` must be set and `.htaccess` must be in the project root.

### PDF or Excel export not working

Confirm `assets/js/residents_pdf.js` is loaded and all referenced images exist.

### AI recommendations not generating

Start the Flask server: `cd ml/api/ && python app.py`. Confirm it is running on port 5000.

### Undefined function error

Add `require_once __DIR__ . '/../../includes/functions.php';` at the top of the affected file.

### audit_logs table not found

Run the `CREATE TABLE audit_logs` SQL from the Category 3 docs in phpMyAdmin.

---

## 6. Maintenance Notes

### Weekly

Back up the database:

```bash
mysqldump -u root -p nutri_track_db > backup_YYYY-MM-DD.sql
```

### Monthly

Update XAMPP, PHP, and Python packages. Review audit logs for unusual activity:

```sql
SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 100;
```

### As Needed

- Retrain the AI model as new resident data is collected
- Rotate admin passwords regularly
- Remove outdated or duplicate health records

### Resetting a Lost Admin Password

Step 1 — Generate a new bcrypt hash:

```bash
php -r "echo password_hash('NewPassword123', PASSWORD_DEFAULT);"
```

Step 2 — Update in phpMyAdmin:

```sql
UPDATE admins SET password = '$2y$10$...' WHERE username = 'Admin';
```

> Never store plain text passwords. Always hash first.

---

NutriTrack v1.0 — Barangay San Miguel, Calasiao, Pangasinan — February 2026
