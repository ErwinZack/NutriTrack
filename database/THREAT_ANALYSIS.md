# NutriTrack — Threat Analysis

**Project:** NutriTrack Nutrition Monitoring System  
**Version:** 1.0  
**Date:** 2025

---

## Overview

This document identifies the key security threats facing the NutriTrack system,
their risk levels, and the mitigation strategies applied across the codebase.

---

## Threat 1 — SQL Injection
- **Risk Level:** 🔴 High
- **Affected Pages:**
  - `pages/health_worker/add_resident.php`
  - `pages/health_worker/edit_resident.php`
  - `pages/health_worker/add_health_record.php`
  - `pages/health_worker/edit_health_record.php`
  - `pages/health_worker/login_health_worker.php`
  - `pages/admin/login_admin.php`
- **Mitigation:** PDO prepared statements used on all database queries.

---

## Threat 2 — Cross-Site Scripting (XSS)
- **Risk Level:** 🔴 High
- **Affected Pages:**
  - All pages that display user-submitted data
- **Mitigation:** `htmlspecialchars()` applied on all output; input sanitized via `includes/functions.php`.

---

## Threat 3 — Brute Force Attack
- **Risk Level:** 🔴 High
- **Affected Pages:**
  - `pages/health_worker/login_health_worker.php`
  - `pages/admin/login_admin.php`
- **Mitigation:** Rate limiting enforced; account lockout after repeated failed attempts.

---

## Threat 4 — Session Hijacking
- **Risk Level:** 🟠 Medium
- **Affected Pages:**
  - `includes/auth_health_worker.php`
  - `includes/auth_admin.php`
- **Mitigation:** HttpOnly and SameSite=Strict cookies set; session ID regenerated after every login.

---

## Threat 5 — Broken Authentication
- **Risk Level:** 🟠 Medium
- **Affected Pages:**
  - `pages/health_worker/verify_email_otp_health_worker.php`
  - `pages/admin/verify_email_otp_admin.php`
  - `includes/email_otp_functions.php`
- **Mitigation:** MFA via Email OTP enforced on every login; OTP is single-use and time-limited.

---

## Threat 6 — CSRF (Cross-Site Request Forgery)
- **Risk Level:** 🟠 Medium
- **Affected Pages:**
  - `pages/health_worker/add_resident.php`
  - `pages/health_worker/edit_resident.php`
  - `pages/health_worker/add_health_record.php`
  - `pages/health_worker/edit_health_record.php`
  - `pages/health_worker/reset_password_health_worker.php`
  - `pages/admin/add_user.php`
  - `pages/admin/edit_user.php`
  - `pages/admin/reset_password_admin.php`
- **Mitigation:** CSRF tokens generated and validated on every form submission.

---

## Threat 7 — Sensitive Data Exposure
- **Risk Level:** 🟠 Medium
- **Affected Files:**
  - `config/config.php`
  - `database/backup.php`
  - `.env`
- **Mitigation:** Credentials stored in `.env` file; database backups encrypted with AES-256.

---

## Threat 8 — Insecure Password Storage
- **Risk Level:** 🔴 High
- **Affected Pages:**
  - `pages/health_worker/register_health_worker.php`
  - `pages/health_worker/reset_password_health_worker.php`
  - `pages/admin/reset_password_admin.php`
- **Mitigation:** All passwords hashed using `password_hash()` with `PASSWORD_BCRYPT`.

---

## Threat 9 — Privilege Escalation
- **Risk Level:** 🟠 Medium
- **Affected Pages:**
  - `includes/auth_health_worker.php`
  - `includes/auth_admin.php`
  - `pages/admin/user_management.php`
- **Mitigation:** Role-based access control enforced; auth guards applied on every protected page.

---

## Threat 10 — Insecure File Handling
- **Risk Level:** 🟡 Low
- **Affected Files:**
  - `database/backup.php`
  - `database/backups/`
- **Mitigation:** Backups stored as `.enc` encrypted files; backup folder is not publicly accessible.

---

## Risk Summary

| Risk Level   | Count |
|--------------|-------|
| 🔴 High      | 4     |
| 🟠 Medium    | 5     |
| 🟡 Low       | 1     |
| **Total**    | **10**|

---

> All identified threats have corresponding mitigations implemented across the
> NutriTrack codebase. Ongoing security reviews are recommended as the system evolves.