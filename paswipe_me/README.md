# PA-SWIPE AKO — First Version (PHP + MySQL/MariaDB + XAMPP)

Secure login → session authentication → role check → admin dashboard with a persistent
sidebar → one complete CRUD module (**Categories**) with search, filters, pagination and print.

Plain PHP 8 + PDO, HTML5, custom CSS and vanilla JavaScript. No frameworks, no Node/npm, no CDN.

---

## PART 1 — Database schema analysis

Source of truth: `db/pa_swipe_ako_mariadb_10_6_v2.sql` (copied unchanged from the upload).

### Database

| Item | Value |
|---|---|
| Database name | **Not defined in the schema** (no `CREATE DATABASE`). This project uses `pa_swipe_ako`; change it in `config/database.php` if you want another name. |
| Engine | MariaDB 10.6+ (the schema header says so) |
| Objects | **41 tables, 4 views, 68 triggers** (UUID + `updated_at` triggers plus 2 payment-allocation validation triggers), 84 foreign keys (verified by importing into MariaDB 10.11) |
| Primary keys | Every table uses `id CHAR(36)` (UUID). If `id` is left empty, a `BEFORE INSERT` trigger fills it with `UUID()`. |

### Tables (by area)

| Area | Tables |
|---|---|
| Users / auth | `users`, `user_roles` |
| Borrowers | `borrowers`, `borrower_user_accounts`, `borrower_addresses`, `borrower_contacts` |
| Master data | `categories`, `merchants`, `payment_methods`, `penalty_rules` |
| Credit cards | `credit_cards`, `card_billing_cycles`, `credit_card_statements`, `credit_card_payments`, `bank_charges` |
| Transactions | `transactions`, `transaction_items`, `transaction_attachments` |
| Installments | `installment_plans`, `installment_schedules` |
| Cash loans | `cash_loans`, `cash_loan_schedules`, `cash_loan_allocations`, `cash_advances` |
| Obligations & payments | `borrower_obligations`, `borrower_payments`, `borrower_payment_allocations`, `payment_verifications`, `payment_verification_logs`, `payment_attachments`, `applied_penalties` |
| Documents | `documents`, `agreement_templates`, `agreements`, `agreement_signatories`, `agreement_documents`, `demand_letter_templates`, `demand_letters` |
| Notifications / audit | `notifications`, `notification_logs`, `activity_logs` |
| Views | `vw_borrower_balances`, `vw_pending_payment_approvals`, `vw_overdue_obligations`, `vw_credit_card_utilization` |

### Main relationships

- **users 1—N user_roles** (one user can hold several roles; `UNIQUE(user_id, role)`).
- **users 1—1 borrowers** through `borrower_user_accounts` (both FKs `UNIQUE`).
- **borrowers 1—N** addresses, contacts, transactions, obligations, payments, agreements, demand letters, payment methods.
- **categories 1—N categories** (self-reference `parent_id`), **categories 1—N merchants**, **categories 1—N transactions**.
- **credit_cards 1—N** billing cycles, statements, transactions, cash loans, cash advances, card payments, bank charges.
- **transactions 1—N transaction_items**, **1—1 installment_plans** (`transaction_id UNIQUE`) **1—N installment_schedules**.
- **Many-to-many**: transactions ↔ documents (`transaction_attachments`), borrower_payments ↔ documents (`payment_attachments`), agreements ↔ documents (`agreement_documents`), borrower_payments ↔ borrower_obligations (`borrower_payment_allocations`).
- Circular FK: `cash_loan_allocations.obligation_id` ↔ `borrower_obligations.cash_loan_allocation_id` (added with `ALTER TABLE`).
- `created_by` / `updated_by` / `reviewed_by` / `performed_by` columns point to `users`.

### Authentication structure

| Item | Column |
|---|---|
| Table | `users` |
| Primary key | `id CHAR(36)` |
| Username / email | `username VARCHAR(100) UNIQUE NULL`, `email VARCHAR(255) UNIQUE NOT NULL` → login accepts **username or email** |
| Password | `password_hash TEXT NOT NULL` → `password_hash()` / `password_verify()` |
| Status | `status ENUM('pending','active','suspended','locked','inactive') DEFAULT 'pending'` → only `active` may sign in |
| Lockout | `failed_login_attempts INT`, `locked_until DATETIME` |
| Last login | `last_login_at DATETIME` |
| Role / user type | `user_roles.role ENUM('owner','admin','borrower')` |
| Profile | `first_name`, `middle_name`, `last_name`, `mobile` on `users` |

No legacy hashing (MD5/SHA1) exists: the schema has no users, so every account is created with `password_hash()`.

### Schema issues found

> **SCHEMA ISSUE FOUND — no initial account**
> Missing table: none. Missing column: none.
> Reason: the schema says *"No default users/passwords are created by this schema."*
> Impact: nobody can sign in after import.
> Recommended solution: run `db/create_admin.php` (command line only). **You** type the email/username/password; it inserts one row into `users` and one into `user_roles`. No account is invented and no table is changed.

> **SCHEMA ISSUE FOUND — MariaDB version vs XAMPP**
> Reason: the schema targets MariaDB **10.6+**, and declares foreign keys inline (`col CHAR(36) REFERENCES t(id)`). Older XAMPP builds ship **MariaDB 10.4**, which accepts inline `REFERENCES` but **does not create the foreign key**.
> Impact: on 10.4 the database will not block deleting referenced rows.
> Recommended solution: use a recent XAMPP (MariaDB 10.6+), or check the version in phpMyAdmin (home page → *Database server*). The application checks dependencies in PHP before every delete anyway, so Categories is protected either way.

> **Note — `UNIQUE(parent_id, name)` on categories**
> MariaDB treats `NULL` values as distinct, so two *top-level* categories with the same name would be allowed by the database. The app checks this itself with a null-safe comparison (`parent_id <=> :parent_id`).

> **Note — character set**
> The schema does not set a table charset. Create the database as `utf8mb4` (step 3 below) so names such as *Peñaflor* or emoji are stored correctly.

> **Note — `categories` has no `updated_at` column**, so the detail page shows *Created* only.

---

## PART 2 — Admin module analysis

| Module | Table(s) | Purpose | C | R | U | D | Search | Print |
|---|---|---|---|---|---|---|---|---|
| **Categories** ✅ built | `categories` | Classify merchants & transactions | ✓ | ✓ | ✓ | ✓ (soft via `is_active`) | ✓ | ✓ |
| Merchants | `merchants` | Stores/sellers where cards are swiped | ✓ | ✓ | ✓ | ✓ if unused | ✓ | ✓ |
| Payment Methods | `payment_methods` | GCash/Maya/bank accounts used to pay | ✓ | ✓ | ✓ | soft (`is_active`) | ✓ | ✓ |
| Penalty Rules | `penalty_rules`, `applied_penalties` | Late-payment rules | ✓ | ✓ | ✓ | soft (`is_active`) | ✓ | ✓ |
| Borrowers | `borrowers` + addresses, contacts, `borrower_user_accounts` | People who use the cards | ✓ | ✓ | ✓ | soft (`status='inactive'`) | ✓ | ✓ |
| Obligations | `borrower_obligations` | What each borrower owes | ✓ | ✓ | ✓ | cancel (`status`) | ✓ | ✓ |
| Borrower Payments | `borrower_payments`, allocations, verifications, logs, attachments | Review/approve submitted payments | – | ✓ | approve/reject | – | ✓ | ✓ |
| Credit Cards | `credit_cards`, `card_billing_cycles` | Owner's cards, limits, cycles | ✓ | ✓ | ✓ | soft (`status`) | ✓ | ✓ |
| Statements | `credit_card_statements` | Monthly bank statements | ✓ | ✓ | ✓ | ✓ if unused | ✓ | ✓ |
| Card Payments & Charges | `credit_card_payments`, `bank_charges` | Payments to the bank, bank fees | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Transactions | `transactions`, `transaction_items`, `transaction_attachments` | Each card swipe and its products | ✓ | ✓ | ✓ | cancel (`status`) | ✓ | ✓ |
| Installment Plans | `installment_plans`, `installment_schedules` | Installment terms and due dates | ✓ | ✓ | ✓ | – | ✓ | ✓ |
| Cash Loans & Advances | `cash_loans`, `cash_loan_schedules`, `cash_loan_allocations`, `cash_advances` | Bank cash loans and their split to borrowers | ✓ | ✓ | ✓ | cancel | ✓ | ✓ |
| Agreements | `agreement_templates`, `agreements`, signatories, documents | Borrower agreements | ✓ | ✓ | ✓ | cancel | ✓ | ✓ |
| Demand Letters | `demand_letter_templates`, `demand_letters` | Collection letters | ✓ | ✓ | ✓ | cancel | ✓ | ✓ |
| Document Files | `documents` | Uploaded receipts/statements | ✓ | ✓ | – | soft (`is_deleted`) | ✓ | – |
| Reports | the 4 `vw_*` views | Balances, overdue, pending, utilization | – | ✓ | – | – | ✓ | ✓ |
| Users & Roles | `users`, `user_roles` | Accounts and roles | ✓ | ✓ | ✓ | soft (`status`) | ✓ | ✓ |
| Activity Logs | `activity_logs` | Audit trail (append-only per schema notes) | – | ✓ | – | – | ✓ | ✓ |

Why these are admin modules: they hold the owner's financial records (cards, swipes, loans), the
borrowers' obligations and the approval workflow for their payments. Borrower self-service
(`role = 'borrower'`, `borrower_user_accounts`) is a separate future portal.

In the sidebar, the modules that aren't built yet appear as **disabled items marked "Soon"**. They are
not links, so no placeholder pages exist.

---

## PART 3 — First CRUD module

**SELECTED FIRST CRUD MODULE: Categories**

| | |
|---|---|
| Database table | `categories` |
| Reason | Only depends on itself (no other required data), so it is safe for testing. It has a nullable self-FK (shows FK validation and dropdowns), a composite unique key (shows duplicate checks), a soft-delete flag `is_active` (shows status filter + deactivate), text fields for search, and is referenced by `merchants` and `transactions` (shows delete protection). Merchants and Transactions need categories first. |
| Primary key | `id CHAR(36)` (UUID, generated with `SELECT UUID()` before insert) |
| Fields | `parent_id`, `name` (VARCHAR 150, required), `description` (TEXT), `is_active` (TINYINT 1, default 1), `created_at` |
| Foreign keys | `parent_id → categories.id`; referenced by `categories.parent_id`, `merchants.category_id`, `transactions.category_id` |
| Searchable fields | `name`, `description`, parent `name` |
| Filters | status (active/inactive), parent (top-level / under a parent), sort, rows per page |
| CRUD operations | Create, list, view, edit, delete (only when unused), deactivate (when in use) |
| Print contents | System name, "Categories Report", printed date/time and user, active filters, record count, #, name, parent, description, status, usage count, created date |

---

## PART 4 — System architecture

```
paswipe_me/
├── index.php                      → redirects to dashboard or login
├── setup_check.php                → installation checker (localhost only)
├── README.md
├── assets/
│   ├── css/
│   │   ├── base.css               → design tokens (:root variables), buttons, forms, alerts
│   │   ├── auth.css               → login / access-denied pages
│   │   ├── dashboard.css          → layout, sidebar, header, cards, tables, modal, print
│   │   └── categories.css         → module-only styles
│   └── js/
│       ├── auth.js                → show/hide password, validation, loading state
│       ├── dashboard.js           → sidebar drawer/collapse, dropdown, toasts, confirm modal, print
│       └── categories.js          → form validation, character counter, search shortcut
├── auth/
│   ├── login.php
│   ├── logout.php
│   └── access_denied.php          → 403 page / landing page for borrower accounts
├── config/
│   ├── .htaccess                  → blocks web access
│   ├── app.php                    → system name, roles, timeouts, time zone, optional base URL
│   └── database.php               → credentials + single shared PDO connection (db())
├── db/
│   ├── .htaccess                  → blocks web access
│   ├── pa_swipe_ako_mariadb_10_6_v2.sql   → the schema (unchanged)
│   ├── create_admin.php           → CLI tool to create your first admin account
│   └── test_data_categories.sql   → OPTIONAL, clearly labelled test rows
├── includes/
│   ├── .htaccess                  → blocks web access
│   ├── bootstrap.php              → loads config + helpers, starts the secure session
│   ├── auth_check.php             → require_login(), require_role(), require_admin()
│   ├── functions.php              → escaping, URLs, flash messages, pagination, formatting
│   ├── csrf.php                   → CSRF token helpers
│   ├── activity_log.php           → writes to activity_logs
│   ├── icons.php                  → inline SVG icons (no CDN)
│   ├── error_handler.php          → friendly error page + setup hints instead of blank 500
│   ├── header.php                 → <head>, top bar, opens layout (includes sidebar.php)
│   ├── sidebar.php                → navigation built from the schema's modules
│   └── footer.php                 → footer, shared confirm modal, JS
└── pages/
    ├── dashboard.php
    └── categories/
        ├── category_functions.php → queries, validation, dependency checks for this table
        ├── _form.php              → shared create/edit form
        ├── index.php              → list, search, filters, pagination, print
        ├── create.php
        ├── view.php
        ├── edit.php
        └── delete.php
```

Additions to your structure, and why: `bootstrap.php` (one place that loads everything),
`activity_log.php` (uses the schema's `activity_logs`), `icons.php` (icons without a CDN),
`access_denied.php`, the module's `category_functions.php` + `_form.php` (so the next module
copies one folder), and `.htaccess` files that block browsing to `config/`, `includes/` and `db/`.

**Paths:** nothing is hardcoded. `includes/functions.php → app_base_url()` works out the
project's web path (e.g. `/paswipe_me`) from the running script, and every link/asset uses
`url('assets/css/base.css')`. So files at any depth produce correct URLs, and the folder can
be renamed or moved. PHP includes use `__DIR__`, so they never depend on the current directory.
If auto-detection ever fails (unusual Apache aliases), set `APP_BASE_URL` in `config/app.php`.

### How to add the next CRUD module (e.g. Merchants)
1. Copy `pages/categories/` → `pages/merchants/`, and `categories.css/js` → `merchants.css/js`.
2. In `merchants/…_functions.php` change the table, fields, validation and dependency checks.
3. Adjust columns in `index.php`, the fields in `_form.php`, the details in `view.php`.
4. In `includes/sidebar.php` set `'path' => 'pages/merchants/index.php'` for Merchants.

---

## PART 5 — Authentication flow

```
login.php (GET)  → form with CSRF token
login.php (POST) → server-side validation (CSRF, required fields, length)
                 → SELECT user by email OR username (prepared statement)
                 → unknown user: dummy password_verify (same timing) → generic error
                 → locked_until in the future: "try again in N minutes"
                 → password_verify() fails: failed_login_attempts + 1
                       5th failure: locked_until = NOW() + 15 min
                 → status must be 'active' (pending/suspended/locked/inactive get their own message)
                 → user_roles must contain a role
                 → password_needs_rehash() → upgrade hash if needed
                 → reset counters, set last_login_at
                 → session_regenerate_id(true)  (prevents session fixation)
                 → $_SESSION: user_id, username, email, display_name, user_roles, user_role,
                              login_at, last_activity, fresh csrf_token  (never the password)
                 → owner/admin → pages/dashboard.php ; borrower → auth/access_denied.php
```

Every protected page starts with:

```php
require_once __DIR__ . '/../../includes/auth_check.php';
$currentUser = require_admin();
```

`require_admin()` → `require_login()`:
- sends `Cache-Control: no-store` (Back after logout reloads from the server and is rejected),
- not logged in → redirect to login,
- idle more than 30 min (`SESSION_IDLE_TIMEOUT`) → session destroyed → login,
- **re-reads the user and roles from the database on every request**, so a suspended user or
  a removed role takes effect immediately,
- then `require_role(['owner','admin'])` → otherwise **HTTP 403 Access denied**.

So typing `/pages/categories/delete.php` by hand is checked exactly like clicking a link.

Session cookie: `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS, strict mode, cookie only.

Logout (`auth/logout.php`) only accepts **POST + CSRF token**. It clears `$_SESSION`, deletes the cookie,
destroys the session and redirects to login with *"You have been signed out."*

Both `owner` and `admin` count as administrators (`ADMIN_ROLES` in `config/app.php`). The
schema has no other roles, so none were added.

---

## PART 6 — Implementation

All files are in this folder. Every file is complete and contains no placeholders.

---

## PART 7 — Database configuration

`config/database.php`:

```php
const DB_HOST    = 'localhost';
const DB_PORT    = 3306;
const DB_NAME    = 'pa_swipe_ako';   // the name you create in phpMyAdmin
const DB_USER    = 'root';           // XAMPP default
const DB_PASS    = '';               // XAMPP default is empty; set yours if you changed it
```

These are XAMPP's out-of-the-box values. If you set a MySQL root password or created a separate
user, put those credentials here. The connection also sets the MySQL session time zone
to the PHP time zone in `config/app.php` (default `Asia/Manila`). That keeps `NOW()` and the dates PHP displays consistent.

---

## PART 8 — XAMPP setup (first run)

1. **Start Apache and MySQL** in the XAMPP Control Panel.
2. **Copy** the `paswipe_me` folder into `xampp/htdocs/` (Windows `C:\xampp\htdocs\`,
   macOS `/Applications/XAMPP/xamppfiles/htdocs/`, Linux `/opt/lampp/htdocs/`).
3. **Create the database:** open `http://localhost/phpmyadmin` → *SQL* tab → run
   ```sql
   CREATE DATABASE pa_swipe_ako CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
4. **Import the schema:** click `pa_swipe_ako` in the left panel → *Import* → choose
   `paswipe_me/db/pa_swipe_ako_mariadb_10_6_v2.sql` → *Import*. You should see 41 tables and 4 views.
   (Command line alternative: `mysql -u root pa_swipe_ako < db/pa_swipe_ako_mariadb_10_6_v2.sql`)
5. **Check `config/database.php`** (Part 7).
6. **Create your administrator** (the schema ships with no users). Open a terminal
   (XAMPP Control Panel → *Shell* on Windows) and run:
   ```
   php C:\xampp\htdocs\paswipe_me\db\create_admin.php
   ```
   Enter your email, optional username, names, role (`admin` or `owner`) and a password
   (min. 8 characters). It cannot be run from the browser.
7. *(Optional, test database only)* import `db/test_data_categories.sql` to get sample categories.
   Every row is marked `[TEST]`, and the file contains the SQL to remove them.
8. **Open** `http://localhost/paswipe_me/` and sign in with the username or email you entered.

### Troubleshooting (blank page / "HTTP ERROR 500")

1. Open **`http://localhost/paswipe_me/setup_check.php`** (works only from the same computer).
   It checks PHP version, `pdo_mysql`/`mbstring`, the session folder, the MySQL connection,
   the database, the imported tables and whether an admin account exists, and says how to fix each ✕.
2. Errors now show a "Something went wrong" page with the likely cause instead of a blank 500.
3. To see the exact technical error on screen, set `const APP_DEBUG = true;` in `config/app.php`
   (set it back to `false` afterwards). Details are always written to
   `xampp/php/logs/php_error_log` / `xampp/apache/logs/error.log`.

Common causes: schema imported into a different database than `DB_NAME`; MySQL not started;
a MySQL root password set but `DB_PASS` left empty; XAMPP with PHP older than 8.0.

---

## PART 9 — Testing checklist

Tested with PHP 8.3 + MariaDB 10.11 against the imported schema:
☑ means the check was run automatically during development. ☐ means it needs checking in a real browser and printer.

**Login**
- ☑ Login page loads
- ☑ Correct username accepted
- ☑ Correct email accepted
- ☑ Correct password accepted
- ☑ Incorrect password rejected
- ☑ Unknown user rejected with the same message
- ☑ Missing or invalid CSRF token rejected
- ☑ Pending account rejected
- ☑ Account with no role rejected
- ☑ Locked after 5 wrong passwords, even when the correct password is then entered
- ☑ Session created
- ☑ Session ID regenerated after login
- ☑ Logout works, and GET `logout.php` does nothing
- ☑ Protected pages redirect unauthenticated users to login
- ☑ Idle timeout signs the user out
- ☑ A user suspended mid-session is signed out on the next request
- ☑ Borrower gets HTTP 403 on admin pages, including a direct POST to `delete.php`

**Dashboard**
- ☑ Dashboard loads
- ☑ Sidebar, header and footer appear
- ☑ Active menu item is highlighted
- ☑ Statistics come from live queries and views
- ☑ Desktop layout works
- ☑ Collapsed sidebar works
- ☑ Mobile drawer works
- ☐ Tablet layout

**CRUD**
- ☑ List records
- ☑ Add record
- ☑ Required validation
- ☑ Length validation
- ☑ Duplicate validation (case- and space-insensitive, including top-level)
- ☑ Invalid parent rejected
- ☑ Category cannot become its own ancestor
- ☑ Edit record
- ☑ View record
- ☑ Delete with confirmation modal
- ☑ Delete blocked while the category is used by sub-categories, merchants or transactions
- ☑ Deactivate instead of delete
- ☑ Search treats `%` and `_` as literal characters
- ☑ Status and parent filters
- ☑ Pagination keeps the filters
- ☑ Invalid or tampered parameters are ignored safely
- ☑ HTML in names is escaped

**Print**
- ☑ Print button opens the print preview with the current filters and every matching row
- ☑ Print dialog opens automatically
- ☑ Sidebar, navigation, buttons, actions and search are hidden in print
- ☑ Report header shows the system name, title, date/time, user and filters
- ☐ Check the output on your own printer and paper size

---

## PART 10 — Security review

| Check | How |
|---|---|
| SQL injection | Every query with user input uses PDO prepared statements, with `ATTR_EMULATE_PREPARES = false`. Sort column, status, per-page and parent values come from whitelists. LIMIT/OFFSET are cast to `int`. LIKE input escapes `%` and `_`. |
| XSS | All output goes through `e()` = `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`. Confirmation text is set with `textContent`. |
| CSRF | Every state change (login, create, update, delete, deactivate, logout) is a POST with a session token checked by `hash_equals()` (`includes/csrf.php`). A new token is issued at login. |
| Passwords | `password_hash()` / `password_verify()`, automatic rehash, timing-safe dummy check, lockout after 5 failures. Passwords are never stored in the session or the logs. |
| Sessions | Strict mode, HttpOnly, SameSite=Lax, Secure on HTTPS, regenerated at login, idle timeout, full destroy on logout, `no-store` on protected pages. |
| Authorization | `require_admin()` runs server-side at the top of every admin page and re-reads status and roles from the database each request. Hiding a menu item is never the only protection. |
| Delete | POST only, CSRF token, confirmation modal (and a confirmation page when JavaScript is off). Dependencies are checked inside a transaction with `SELECT … FOR UPDATE`; when the category is in use, the user can deactivate it instead. |
| Validation | HTML5 + JS for quick feedback; PHP re-validates everything against the schema (NOT NULL, VARCHAR length, UNIQUE, FK, cycles). |
| Error exposure | `display_errors = 0`. PDO errors are logged with `error_log()` and users see friendly messages. A failed DB connection shows a generic notice. |
| File exposure | `.htaccess` denies `config/`, `includes/`, `db/`. Include files also exit when opened directly, and `create_admin.php` refuses to run outside the CLI. |
| Audit | Logins, failed logins, lockouts, access denials and every category change go to `activity_logs` with old/new values, IP and user agent. |
