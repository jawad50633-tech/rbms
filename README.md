# Role-Based Management System (RBMS)
## School Portal — PHP + MySQL

A complete role-based web application supporting Super Admin, Admin (Admission), Teacher, and Student portals.

---

## 📂 File Structure

```
rbms/
├── config.php                    # Core config, DB, session, helpers
├── login.php                     # Login page (all roles)
├── logout.php                    # Session destroy + redirect
├── database.sql                  # Full DB schema + seed data
│
├── includes/
│   ├── header.php                # Sidebar + topbar layout (renderHeader)
│   └── footer.php                # Scripts + closing tags (renderFooter)
│
├── dashboards/
│   ├── superadmin_dashboard.php  # Super Admin — overview + stats
│   ├── superadmin_users.php      # Super Admin — full user CRUD
│   ├── superadmin_classes.php    # Super Admin — redirect to classes
│   ├── superadmin_submissions.php# Super Admin — all submissions
│   ├── superadmin_activity.php   # Super Admin — activity log
│   │
│   ├── admin_dashboard.php       # Admin — admission overview
│   ├── admin_students.php        # Admin — student CRUD + photo
│   ├── admin_classes.php         # Admin — class management
│   │
│   ├── teacher_dashboard.php     # Teacher — dashboard
│   ├── teacher_assignments.php   # Teacher — assignment CRUD
│   ├── teacher_submissions.php   # Teacher — view + grade submissions
│   │
│   ├── student_dashboard.php     # Student — welcome + status
│   ├── student_assignments.php   # Student — view + submit assignments
│   └── student_submissions.php   # Student — submission history
│
└── uploads/
    ├── .htaccess                 # Security: block PHP execution
    ├── photos/                   # Student profile photos
    └── assignments/              # Student uploaded assignment files
```

---

## ⚙️ Setup Instructions

### 1. Database Setup
```sql
-- Import the SQL file via phpMyAdmin or CLI:
mysql -u root -p < database.sql
```

### 2. Configure `config.php`
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'rbms_db');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('BASE_URL', 'https://yourdomain.com/rbms');
```

### 3. Create Upload Directories & Set Permissions
```bash
mkdir -p uploads/photos uploads/assignments
chmod 755 uploads/photos uploads/assignments
```
On InfinityFree, use the File Manager to create these folders.

### 4. Upload Files
Upload all files to your hosting `htdocs/rbms/` (InfinityFree) or `public_html/rbms/`.

### 5. First Login
Open `https://yourdomain.com/rbms/login.php`

| Role        | Username    | Password |
|-------------|-------------|----------|
| Super Admin | superadmin  | password |
| Admin       | admin       | password |
| Teacher     | teacher1    | password |
| Student     | student1    | password |

**⚠️ Change all passwords immediately after first login!**

---

## 🔐 Security Features
- CSRF tokens on every POST form
- Session regeneration on login
- Login rate limiting (5 attempts → 15-min lockout)
- Role-based access control (every page verifies role)
- Secure file upload (MIME type + extension validation)
- PHP execution blocked in uploads directory
- Prepared statements (PDO) for all DB queries
- Passwords hashed with bcrypt (cost 12)
- Idle session timeout (1 hour)
- XSS protection via `htmlspecialchars()` on all output

---

## 🧩 Role Capabilities

| Feature                  | Super Admin | Admin | Teacher | Student |
|--------------------------|:-----------:|:-----:|:-------:|:-------:|
| View all users           | ✅          | ❌    | ❌      | ❌      |
| Create/edit/delete users | ✅          | ❌    | ❌      | ❌      |
| Manage classes           | ✅          | ✅    | ❌      | ❌      |
| Enroll/manage students   | ✅          | ✅    | ❌      | ❌      |
| Upload student photos    | ✅          | ✅    | ❌      | ❌      |
| Create assignments       | ❌          | ❌    | ✅      | ❌      |
| Grade submissions        | ❌          | ❌    | ✅      | ❌      |
| View all submissions     | ✅          | ❌    | Own only| Own only|
| Submit assignments       | ❌          | ❌    | ❌      | ✅      |
| View activity log        | ✅          | ❌    | ❌      | ❌      |

---

## 🚀 InfinityFree Compatibility Notes
- Uses PDO (not deprecated mysql_* functions)
- No shell_exec or system calls
- Compatible with PHP 7.4+
- No composer dependencies — zero dependencies, pure PHP
- File permissions should be 644 for files, 755 for directories

---

## 📦 Extending the System
- Add new roles: update ROLE_* constants in `config.php`, add nav links in `getNavLinks()`, create dashboard file
- Add email notifications: use PHPMailer in config.php after composer setup
- Add pagination: wrap queries with `LIMIT ? OFFSET ?` and pass page number via GET
