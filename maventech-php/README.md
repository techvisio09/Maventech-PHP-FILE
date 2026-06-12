# Maventech Software - Admin Panel (PHP + Bootstrap)

A comprehensive admin panel for managing software product sales, license keys, customers, orders and email delivery.

## Tech Stack
- **Backend**: PHP 7.4+ (PDO MySQL)
- **Frontend**: Bootstrap 5, Chart.js, Font Awesome 6
- **Database**: MySQL / MariaDB
- **Email**: PHP `mail()` by default; PHPMailer recommended for SMTP (see below)

## Folder Structure
```
maventech-php/
├── config.php           # DB credentials + app settings
├── schema.sql           # Database schema + seed data
├── auth_check.php       # Session guard for protected pages
├── functions.php        # Helper functions (logging, email, key gen, etc.)
├── header.php           # Top navbar + HTML head
├── sidebar.php          # Side navigation
├── footer.php           # Bottom HTML + scripts
├── login.php / logout.php
├── index.php            # Dashboard overview
├── sales.php            # Real-time sales stats + revenue reports
├── orders.php           # Order list + filters
├── order_view.php       # Single order details + invoice
├── products.php         # Product CRUD + image upload
├── categories.php       # Category CRUD
├── licenses.php         # License key tracking (filter / status)
├── customers.php        # Customer database + search
├── customer_view.php    # Customer profile + purchase history
├── inventory.php        # Stock levels + low-stock alerts
├── activity_logs.php    # Admin activity audit trail
├── admins.php           # RBAC: admin/manager users
├── email_send.php       # Send license email manually / view template
├── uploads/             # Product images
└── assets/
    ├── css/style.css
    ├── js/script.js
    └── img/logo.png
```

## Installation

### 1. Requirements
- PHP 7.4+ with PDO, PDO_MYSQL, mbstring, gd extensions
- MySQL 5.7+ or MariaDB 10+
- Apache/Nginx web server (XAMPP, WAMP, LAMP, cPanel)

### 2. Setup steps
1. Copy the `maventech-php` folder into your web root (e.g. `htdocs/maventech` or `/var/www/html/maventech`).
2. Create a database in MySQL (e.g. `maventech_admin`).
3. Import `schema.sql` into your database:
   ```
   mysql -u root -p maventech_admin < schema.sql
   ```
4. Open `config.php` and update:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'maventech_admin');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('COMPANY_STATEMENT_NAME', 'MAVENTECH SOFTWARE');
   define('SUPPORT_EMAIL', 'support@maventech.com');
   define('BASE_URL', 'http://localhost/maventech-php');
   ```
5. Ensure `uploads/` is writable: `chmod 775 uploads`.
6. Visit `http://localhost/maventech-php/login.php`.

### 3. Default Admin Credentials
- **Email**: `admin@maventech.com`
- **Password**: `Admin@123`

> Change immediately after first login (Admins page).

## Features Implemented
- Secure session-based admin authentication with bcrypt
- Role-based access control (super_admin / admin / manager)
- Dashboard: total sales, orders, customers, available/sold license keys, revenue chart, recent orders, low-stock alerts
- Sales: daily / weekly / monthly / yearly revenue reports + CSV export
- Orders: full order history, status update (pending/paid/refunded), transaction details
- Products: CRUD with image upload, pricing, description, status toggle (active/disabled)
- Categories: CRUD
- License Keys: bulk import, individual add, status tracking (available/assigned/sold/expired), customer assignment, filters
- Customers: searchable database, purchase history, assigned keys
- Inventory: per-product stock + configurable low-stock threshold + alert dashboard
- Automated Email Delivery: professional HTML template containing product name, license key, installation guide, description, amount, order details, company branding, support contact. Sent automatically on order creation; resendable from order view.
- Payment Info: shows transaction id, payment method, status, and the exact card-statement company name
- Activity Logs: every admin action (login, create/edit/delete) recorded with timestamp + IP

## SMTP / PHPMailer (optional but recommended)
The `functions.php` `send_license_email()` uses PHP `mail()` by default. For production:
1. `composer require phpmailer/phpmailer`
2. Replace the `mail()` call in `functions.php` with a PHPMailer SMTP block (SendGrid / Mailgun / Gmail SMTP).
3. Add SMTP credentials to `config.php`.

## Security Notes
- Passwords stored with `password_hash` (bcrypt)
- All SQL via PDO prepared statements
- CSRF token on all forms
- Session regeneration on login
- License keys stored encrypted-at-rest in DB column (AES-256 helper functions provided in `functions.php`)
- Output escaped with `e()` helper to prevent XSS

## License
Proprietary - Maventech Software © 2026
