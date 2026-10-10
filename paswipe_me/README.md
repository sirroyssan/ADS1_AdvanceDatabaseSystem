# PA-KASKAS — Administrative System (PHP + MySQL/MariaDB + XAMPP)

> The system was renamed from **PA-SWIPE AKO** to **PA-KASKAS** (`APP_NAME` in `config/app.php`). The database name `pa_swipe_ako`, the schema file and the `paswipe_me` folder are unchanged.

Secure login → session authentication → role check → admin dashboard with a persistent
sidebar → **all administrative modules** of the schema with CRUD (where appropriate), search,
filters, pagination, print and reports.

> **Version 2 (complete system)** is documented in the section
> [COMPLETE SYSTEM — ALL MODULES](#complete-system--all-modules) at the end of this file.
> Parts 1–10 below describe the original foundation (login, dashboard, Categories), which is unchanged.

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

All of these modules are now built and linked from the sidebar (see the final section).

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


---

# COMPLETE SYSTEM — ALL MODULES

## A. Completed modules

| # | Module | Table(s) | Create | Read | Update | Delete | Search | Print | Notes |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Login / Logout / Session | users, user_roles | – | – | – | – | – | – | unchanged foundation |
| 2 | Dashboard | views + live counts | – | ✓ | – | – | – | ✓ | links to modules added |
| 3 | Categories | categories | ✓ | ✓ | ✓ | ✓ unused / deactivate | ✓ | ✓ | first module, unchanged |
| 4 | Merchants | merchants | ✓ | ✓ | ✓ | ✓ unused only | ✓ | ✓ | filter: category, type |
| 5 | Payment Methods | payment_methods | ✓ | ✓ | ✓ | ✓ unused / deactivate | ✓ | ✓ | owner **or** borrower |
| 6 | Penalty Rules | penalty_rules | ✓ | ✓ | ✓ | ✓ unused / deactivate | ✓ | ✓ | percentage or fixed, cap |
| 7 | Agreement Templates | agreement_templates | ✓ | ✓ | ✓ | ✓ unused / deactivate | ✓ | ✓ | {{placeholders}} |
| 8 | Demand Letter Templates | demand_letter_templates | ✓ | ✓ | ✓ | ✓ unused / deactivate | ✓ | ✓ | {{placeholders}} |
| 9 | Users & Roles | users, user_roles | ✓ | ✓ | ✓ | status only | ✓ | ✓ | roles as checkboxes, unlock, reset password |
| 10 | My Profile | users | – | ✓ | ✓ | – | – | – | change password (current password required) |
| 11 | Borrowers | borrowers, borrower_addresses, borrower_contacts, borrower_user_accounts | ✓ | ✓ | ✓ | ✓ no history / inactive | ✓ | ✓ | addresses, contacts, sign-in link on the page |
| 12 | Credit Cards | credit_cards, card_billing_cycles | ✓ | ✓ | ✓ | ✓ no history / inactive | ✓ | ✓ | billing cycles on the page |
| 13 | Statements | credit_card_statements | ✓ | ✓ | ✓ | ✓ unlinked only | ✓ | ✓ | total auto-computed, date range |
| 14 | Card Payments | credit_card_payments | ✓ | ✓ | ✓ | ✗ | ✓ | ✓ | card → statement dependent dropdown |
| 15 | Bank Charges | bank_charges | ✓ | ✓ | ✓ | ✗ | ✓ | ✓ | |
| 16 | Cash Advances | cash_advances | ✓ | ✓ | ✓ | cancel | ✓ | ✓ | |
| 17 | Transactions | transactions, transaction_items, transaction_attachments | ✓ | ✓ | ✓ | drafts only / cancel | ✓ | ✓ | items + attachments + status |
| 18 | Installment Plans | installment_plans, installment_schedules | ✓ | ✓ | ✓ | before payments only | ✓ | ✓ | schedule generated automatically |
| 19 | Cash Loans | cash_loans, cash_loan_schedules, cash_loan_allocations | ✓ | ✓ | ✓ | untaken offers only | ✓ | ✓ | split to borrowers → obligations |
| 20 | Obligations | borrower_obligations, applied_penalties | ✓ | ✓ | ✓ | ✗ cancel / write off | ✓ | ✓ | penalties on the page |
| 21 | Borrower Payments | borrower_payments, borrower_payment_allocations, payment_verifications, payment_verification_logs, payment_attachments | ✓ | ✓ | ✓ while open | ✗ cancel / reject | ✓ | ✓ | review → approve → allocate |
| 22 | Document Files | documents | ✓ upload | ✓ | ✓ | archive / restore | ✓ | ✓ | secure download, preview |
| 23 | Agreements | agreements, agreement_signatories, agreement_documents | ✓ | ✓ | ✓ | drafts only / cancel | ✓ | ✓ | printable text, signatures |
| 24 | Demand Letters | demand_letters | ✓ | ✓ | ✓ draft/approved | drafts only / cancel | ✓ | ✓ | fill from template, workflow |
| 25 | Notifications | notifications, notification_logs | ✓ send | ✓ | read / cancel | ✗ | ✓ | ✓ | header bell shows unread |
| 26 | Activity Logs | activity_logs | – | ✓ | – | – | ✓ | ✓ | read-only audit |
| 27 | Reports (6) | 4 views + live queries | – | ✓ | – | – | ✓ | ✓ | date ranges, totals |

Reports: Borrower Balances (vw_borrower_balances), Overdue Obligations with aging (vw_overdue_obligations),
Pending Payment Approvals (vw_pending_payment_approvals), Collections (approved payments by period and
method), Transactions (by period, card, borrower, payment type), Credit Card Utilization (vw_credit_card_utilization).

### Module dependencies (build order)

```
categories ─► merchants ─┐
users/roles ─► credit_cards ─► statements ─► card payments / bank charges / cash advances
borrowers ─► payment_methods            │
          └──────────────► transactions ─► items, attachments, installment plans
                                  └──────► obligations ◄── cash loan allocations ◄── cash_loans
penalty_rules ───────────────────────────► applied penalties (on obligations)
borrower_payments ─► review ─► allocations ─► obligations (total_paid / outstanding)
documents ─► attachments (transactions, payments, agreements, statements, letters)
templates ─► agreements ─► signatories ;  templates ─► demand letters
```

## B. Modules without full CRUD — and why

| Module | No Create | No Update | No Delete |
|---|---|---|---|
| Activity Logs | written automatically by the app | an audit trail must not change (schema note 6: append-only) | same |
| Reports | they summarise other data | – | – |
| Card Payments, Bank Charges | – | – | financial history and the tables have **no status/void column**; mistakes are corrected with Edit (logged with old/new values) |
| Obligations | – | total due can't drop below what was paid | referenced by payments, penalties, agreements, letters → **cancel** (only before payments) or **write off** |
| Borrower Payments | – | only while *submitted* / *correction required* | money received is history → **cancel** or **reject**; allocations can't be removed |
| Cash Advances | – | not after cancel | **cancel** (status) |
| Users | – | own status/admin role protected | referenced as created_by/reviewed_by/actor everywhere → status *inactive/suspended* |
| Documents | – | file content never replaced | proof for other records → **archive** (`is_deleted = 1`) |
| Notifications | – | a sent message is not edited | history → **cancel** (if pending) / mark read |
| Transactions / Agreements / Demand Letters | – | locked when cancelled/completed | only **drafts**; otherwise cancel |

Junction / child tables managed inside their parent page (no ugly standalone CRUD):
`user_roles` (user form checkboxes), `borrower_addresses`, `borrower_contacts`, `borrower_user_accounts`
(borrower page), `card_billing_cycles` (card page), `transaction_items`, `transaction_attachments`
(transaction page), `installment_schedules` (plan page), `cash_loan_schedules`, `cash_loan_allocations`
(loan page), `applied_penalties` (obligation page), `borrower_payment_allocations`,
`payment_verifications`, `payment_verification_logs`, `payment_attachments` (payment page),
`agreement_signatories`, `agreement_documents` (agreement page), `notification_logs` (notification page).

**Roles** are an ENUM in `user_roles.role` (owner, admin, borrower), so there is no role CRUD.
**Settings:** the schema has no settings table, so there is no Settings page; configuration stays in
`config/app.php` and `config/database.php`.

## Key business rules implemented

- **Reference numbers** (`TXN-`, `OBL-`, `PAY-`, `BRW-`, `CL-`, `AGR-`, `DL-` + date + sequence) are generated when left blank; uniqueness is checked before saving.
- **Dependent dropdowns** (no AJAX needed): card → statement, borrower → transaction / obligation / agreement / payment method, obligation → installment.
- **Payment workflow:** submitted → under review → approved / rejected / correction required; cancelled. Each decision writes `payment_verifications` (latest) and `payment_verification_logs` (history) in one DB transaction. Reasons are required for reject/correction/cancel.
- **Allocation:** only approved payments; amount ≤ unallocated and ≤ outstanding; updates the obligation (total paid, outstanding, overdue, status *partially paid*/*paid*, completed date), optionally an installment row, and the payment's allocation status — all in one transaction with row locks. The schema trigger is a second guard. *Auto-allocate* pays the oldest open obligations first.
- **Schedules:** amounts split evenly with the last row absorbing rounding; monthly dates keep the day, clamped to month end (Jan 31 → Feb 28). Plans/loans complete automatically when every row is paid or waived.
- **Cash loans:** allocations can't exceed the cash received (or accepted amount); an allocation becomes a borrower obligation with both links set.
- **Credit card usage** — see the section *Credit card usage (one source of truth)* below.
- **Penalties:** amount from the rule (percentage of base or fixed, capped by maximum charge) unless typed.
- **Deletes** always re-check dependencies inside a transaction with `SELECT … FOR UPDATE`, because MariaDB 10.4 may not enforce the inline foreign keys.
- **Documents:** extension whitelist + real MIME check (fileinfo), 10 MB limit, stored outside the web (`storage/` has `Require all denied`), served only by `download.php` after the admin check with `nosniff`.

## Credit card usage (one source of truth)

**Root cause of the old "Used ₱0 / 0 %":** every screen calculated *used = credit limit − available credit*, and `credit_cards.available_credit` was only the number typed on the card form. Purchases, cash advances, bank charges and card payments never changed it. Cash loans changed it only while their status was Accepted/Disbursed/Active, and new loans start as **Offered**. The dashboard and the report read the schema view `vw_credit_card_utilization`, which reads the same typed column.

**Fix:** `includes/card_usage.php` calculates usage from the stored records. The dashboard, card list, card page, cash loan page and the utilization report all use it (`card_usage()`, `card_usage_sql()`, `card_utilization_sql()`):

```
used        = opening balance
            + purchases          transactions with status posted, active, completed, disputed or refunded
            + cash loans         principal (accepted, else approved, else offered amount) of
                                 accepted, disbursed, active and completed loans
            + cash advances      amount of active and completed advances
            + interest / fees    cash loan: (total repayment − principal) + processing/other fees
                                            not deducted from the cash received
                                 cash advance: fee + interest
                                 installment plan: interest + fees
                                 bank charges (except the opening balance)
            − payments           credit_card_payments
            − credits / refunds  refunded transactions, waived installments
available   = credit limit − used
utilization = used ÷ credit limit × 100
```

- **Not counted:** draft and cancelled transactions; offered, cancelled and rejected loans; cancelled advances.
- **Counted once:** a transaction with payment type *cash loan* or *cash advance* is the same charge as a cash loan or advance when its reference number equals that record's reference on the same card. In that case only the cash loan or advance is counted.
- **Payments:**
  - Only card payments (payments to the bank) lower the used credit.
  - When an installment is paid on a cash loan or installment plan, the *Also a card payment* box (ticked by default) saves the same amount in Card Payments. Its reference is `CL-… #n` or `TXN-… #n`.
  - Untick the box if you record the whole statement payment in Card Payments instead. This avoids counting the payment twice.
- **Opening balance:**
  - This is the balance a card already had before it was recorded here.
  - It is entered on the card form and stored as a bank charge of type *Opening balance*.
  - Cards saved before this update get it once, automatically: the typed balance minus what the records explain. No figure is lost.
- **Stored copy:**
  - `available_credit` is recalculated after every save that affects a card (`card_usage_apply`, in the same transaction, with the card row locked). Changes are logged as `credit_recalc`.
  - Because of this, the schema view and phpMyAdmin show the same figure as the pages.
  - The stored copy stays within the schema CHECK range (0 … limit). The pages show the real value, including when the card is over its limit.
- **Over-limit check:** a cash loan that would push the card over its limit is refused.
- **No schema change.**

## Borrower portal (`portal/`)

Users with the **Borrower** role sign in to their own portal instead of the admin panel. The admin panel stays administrators-only.

**Setup (admin), once per borrower:**
1. In Users & Roles, create a user with the *Borrower* role.
2. Open the borrower's record (Borrowers → View) and link that user under *Sign-in account*.
3. Until the account is linked, the borrower sees "Your account is not linked yet".

| Page | What the borrower can do |
|---|---|
| `portal/index.php` My Account | See their remaining balance, overdue amount, next due date, total paid, open items and recent payments. Alerts appear for corrections and new notices. |
| `portal/obligations.php`, `obligation.php` | See what they owe (search, filter, print): payments applied, items purchased, installment schedule and late charges |
| `portal/pay.php` | Submit a payment with method, date, amount, reference and proof file. Proof is required unless paid in cash. A duplicate reference is refused. They can fix a payment the owner sent back. |
| `portal/payments.php`, `payment.php`, `payment_cancel.php` | See their payments, the status, the owner's reason, proof files, where the payment was applied and its history. They can cancel a payment before review starts. |
| `portal/agreements.php`, `agreement.php` | Read their agreements (not drafts) with text, files and signature status |
| `portal/letters.php`, `letter.php` | Read the notices sent to them and acknowledge them (sent → acknowledged) |
| `portal/notifications.php` | See their in-app notifications. Opening one marks it read and goes to the record. |
| `pages/profile/*` | Edit their profile and change their password (shown in the portal layout) |

**Security:**
- `require_borrower()` re-checks the role and the linked borrower on every request.
- Every portal query is filtered by that borrower's id, and ids from the URL are re-checked (`portal_find`).
- Files are served by `portal/download.php` only when they are attached to the borrower's own payment, agreement or sent notice.
- Admin pages return 403 for borrowers. CSRF is required on every POST.

**Notifications** (`notifications` table, in-app):
- **To the owner and admins:** when a borrower submits, corrects or cancels a payment, or acknowledges a notice.
- **To the borrower:** when a payment is reviewed (under review, approved, rejected, correction required, cancelled) or applied, when a notice is sent, and when a new obligation is created.

No schema change.

## Penalties and payment order (`includes/penalties.php`)

**Rule assignment:**
- When an obligation is created (manually, or from a cash loan allocation), every **active** penalty rule in effect on the obligation date is assigned at once. The assignment is logged (`assign_penalty_rules`) and shown on the obligation page.
- Anything already overdue (a back-dated obligation) is charged immediately.
- Superseded: rules are now assigned by bank. See *Penalty rules by bank* below.

**Automatic monthly penalties:**
- **Which items:** every open obligation past its due date. An installment purchase with one obligation is charged per overdue installment.
- **When:** one charge per rule on the first day after the grace period, then every month on the same day. A rule with frequency *once* charges only once.
- **How much:** the percentage of the chosen basis, or the fixed amount, capped by the maximum charge.
- **No double charges:** each charge is unique per obligation + rule + installment + date, so re-running never charges twice.
- **When it runs:**
  - automatically once a day, on the first page load;
  - with *Run penalty check now* (Penalty Rules list, or per obligation);
  - or by a scheduler running `php db/run_penalties.php`.
- **Missed months:** they are back-filled from the later of the first overdue day and the rule's *effective from* date. Use *effective from* to limit how far back charges go.

**Payment order:**
- Every allocation (manual or auto) is split, strictly:
  1. unpaid penalties, oldest first (allocation type `penalty`)
  2. interest and fees (`agreed_interest + agreed_fees`, type `interest`)
  3. principal (type `principal`)
- Penalties are paid only through payments. The old "mark penalty paid" button was removed.

**Waive or delete:**
- Owners and administrators only (`require_admin()` plus a check inside `penalty_terminate`). A reason of at least 10 characters is required.
- *Waive* keeps the row, sets the unpaid amount to 0 and appends "WAIVED … by … on …: reason" to its notes.
- *Delete* removes an unpaid penalty. The full row, the reason and its key go to `activity_logs` (`delete_penalty`), so the monthly check never charges that period again.

**Proof of payment by relationship:**
- The borrower's *Relationship* is a dropdown.
  - **Family:** sister, brother, parent, child, spouse, cousin, other relative. Proof is **optional** in the portal.
  - **Not related:** friend, colleague, neighbor, other, or not set. Proof is **mandatory**.
- Older typed-in values are classified by keyword (`relationship_is_family()`).

## Payment amount override (owner only) — payments are never deleted

- There is no delete for borrower payments (no page and no `DELETE` statement). A wrong payment is cancelled or rejected, or its amount is overridden.
- **Override amount** (`pages/borrower_payments/override.php`, button on the payment page):
  - Only users with the **Owner** role can use it (`require_role(['owner'])` plus a check in `payment_override_amount`). Administrators can't.
  - It works in any status except cancelled, including after approval and allocation. A reason of at least 10 characters is required.
- **Raising the amount** leaves the difference unallocated, ready to apply.
- **Lowering it below what is already applied** takes the excess back from the newest allocations: principal first, then interest, then penalties (the reverse of the payment order). The obligation balance, the installment and the penalties are restored, and a paid obligation is re-opened.
- **Records:** every override is written to the payment history (`payment_verification_logs`, action `amount_override`) and to `activity_logs` with the old amount, new amount and reason. The borrower gets a notification.
- **Regular Edit form:** the amount is read-only for non-owners.
- **Owner role:** only an owner can give or remove it (an administrator may appoint the first owner), and the last owner cannot be removed.

## Cash advances given to borrowers

On a cash advance page, **Give to a borrower** turns all or part of the withdrawn cash into a borrower obligation. The form takes the date given, amount, fee, interest per month (%), number of monthly payments and first due date.

- **Total owed** = amount given + fee + add-on interest (amount × monthly rate × months), split into equal monthly payments (`build_schedule`). The last payment absorbs any rounding.
- **How it is stored, without a schema change:** `cash_advances` has no borrower column, so each share uses:
  - a `transactions` row (payment type *cash_advance*, the borrower, and the advance's card and reference number). A reference number `CA-YYYYMMDD-####` is generated if the advance had none.
  - an `installment_plans` row with its `installment_schedules` (the borrower's monthly payments)
  - a `borrower_obligations` row (`original_amount` = amount given, `agreed_fees` = fee, `agreed_interest` = interest)
- **Card usage:** it counts the withdrawal once, from `cash_advances`. The linked borrower transaction and its plan's interest and fees are not added to the card. Only the bank's own fee and interest on the advance are.
- **Penalties:** the rules are assigned immediately and late charges apply per overdue monthly payment. Payments follow the normal order (penalties → interest/fees → principal).
- **Limits:** you can't give more than was withdrawn. An advance given to borrowers who still owe cannot be cancelled. Its card and reference number are locked, and the withdrawn amount can't be lowered below what was given.
- **Notifications:** the borrower is notified and sees the item as "Cash advance" with its payment schedule in the portal.

## Penalty rules by bank, changes apply from the day they are made

**Approved schema change:** one nullable column, `penalty_rules.applies_to_issuer VARCHAR(150)` (the rule's bank).
- It is added automatically on the first page load after updating (`penalty_schema_upgrade`, marker `storage/upgrade_penalty_rule_bank.done`). The same SQL is in `db/upgrade_penalty_rule_bank.sql`.
- During the upgrade, each existing rule's bank is guessed from its name (e.g. "BPI Late Payment" → BPI) when a card with that issuer exists.

**Which items a rule charges:**
- Obligations that come from a card of the rule's bank (`credit_cards.issuer`): that bank's purchases, installments, cash advances and cash loans.
- A rule with no bank, or an obligation without a card, is never charged automatically. *Apply penalty* still works by hand.

**When charges start:**
- A rule charges only from the later of *effective from* and the day it was last saved.
- Earlier months are never back-filled, and changing a rate does not recalculate past charges. The next monthly charge simply uses the new rate.

**One-time cleanup:**
- Automatic penalties with nothing paid on them were removed if they were for another bank, for a rule without a bank, or for a date before the rule took effect.
- Each removal is in the activity log (`delete_penalty`) with its reason and is never re-charged. Partly paid and manual penalties were kept.

## One bank list for cards and penalty rules; bulk waive/delete

**Bank list** (`BANKS` in `includes/lookups.php`):
- The credit card's **Issuer (bank)** and the penalty rule's **Bank** are dropdowns from the same list.
- The list holds the Philippine card issuers plus any bank already used on a card. Banks you have a card with are marked.
- **Matching:** a rule matches a card through `same_bank()`, which also understands older typed-in names ("Bank of the Philippine Islands" = BPI, "Unionbank"/"union bank" = UnionBank).
- **Visibility:** the card page lists the penalty rules for its bank.
- **Validation:** a value outside the list is refused.
- **Adding a bank:** add it to `BANKS`.

**Bulk waive/delete** (obligation page):
- Each penalty has a checkbox, and the header box selects all.
- Choose *Waive selected* or *Delete selected* and give one reason; it is saved on every penalty and in the activity log.
- **Same rules as one at a time:** owners and administrators only, reason of 10 or more characters, and a penalty with something paid can only be waived. Those are reported as skipped.
- The button stays disabled until something is ticked, and deleting asks for confirmation.

## Sweet alert dialogs (`assets/js/sweet.js`)

- **Messages:** flash messages (saved, deleted, errors, notes) appear in one centred dialog with an animated icon: green check, red ✕, amber !, or blue i. Several messages are listed together, and the dialog takes the most serious type.
  - Success messages close by themselves after 3.5 seconds; the timer pauses while the mouse is over them.
  - Errors and notes wait for OK.
- **Confirmations:** every confirmation (`data-confirm` forms and the bulk penalty action) uses `Sweet.confirm`. Delete-type confirmations show a red trash icon; others show a question or warning icon.
- **Accessibility:** focus starts on Cancel for confirmations and stays inside the dialog. Esc and a click outside cancel, and focus returns afterwards. Reduced-motion settings are respected, and the dialog is hidden in print.
- **No external library**, so it works offline. Without JavaScript the inline alerts are still shown.

## Outstanding + penalties = total to pay (everywhere)

Shared SQL in `includes/penalties.php`: `penalty_totals_join()`, `borrower_totals_sql()` and `collection_totals()`. Cancelled and written-off items are not counted.

- **Borrower portal home:**
  - an *Outstanding + Penalties = Total to pay* banner
  - *What I owe* with Outstanding / Penalties / Total per item and a total row
  - *My penalties to pay*: every unpaid penalty with date, item, charge, days late, amount, paid and to pay
- **Borrower portal pages:**
  - *Late Charges* (`portal/penalties.php`, in the sidebar): all penalties (still to pay / paid / waived) with search, date filters and print
  - *What I Owe* list and item page: the same three figures
- **Admin dashboard:** a *Total to collect* card (outstanding + penalties) and a *What borrowers owe* panel per borrower with an all-borrowers total.
- **Obligations list:** Penalties and Total-to-pay columns, sort by total, and footer totals. *With outstanding balance* also lists items that only have penalties left.
- **Borrower page:** Unpaid penalties and Total to pay figures, plus penalties per obligation.
- **Borrower Balances report:** Penalties and Total-to-collect columns, figures and sorting.

## Automatic payment application and date-based statuses

- **Approving a borrower payment applies it at once** (`payment_auto_allocate`).
  - It goes to the oldest-due open obligations first.
  - Within each obligation: penalties → interest/fees → principal.
  - The balance part pays the obligation's installments oldest first, with one allocation row per installment, so an owner override reverses it exactly.
  - The *Auto-allocate* button uses the same logic.
  - The daily run also applies any approved payment that still has an unallocated amount.
- **Statuses follow today's date** (`schedule_refresh_statuses`, on every signed-in page load):
  - an unpaid installment (bank plan or cash loan) past its due date is **Overdue**; one due today is **Due**;
  - an obligation is **Overdue** while it, or one of its installments, is past due, and goes back to Active / Partially paid once caught up.
- **The installment plan's Pay button** is now only for amounts that were not recorded as a borrower payment.
- **Card payments** (paying the bank) are still recorded separately.

## Schedules follow borrower payments (no Pay button) — `includes/schedule_sync.php`

- **No more Pay button.** The Pay and Waive buttons were removed from installment plan and cash loan schedules, and the old actions now return a message.
- **Which schedule a payment fills.** Each obligation's schedule is either:
  - its transaction's installment plan (installment purchase, or a cash advance given to the borrower), when it is the only obligation on the transaction; or
  - its cash loan's repayment schedule, which is shared by all of that loan's borrowers.
- **How the paid amounts are worked out.** They are always derived from the borrowers' applied payments, spread oldest installment first, and recalculated whenever a payment is applied or taken back (owner override). The plan or loan completes automatically when everything is paid.
- **Card payments.** The amount that reaches a bank installment (card installment purchase or cash loan) is recorded automatically as a card payment of type *Borrower payment (auto)*, referenced by the borrower payment number. The card's used credit goes down with it, and an override takes it back.
- **Overdue.** It is calculated, never typed: *what was due by today − what the borrower paid* (a cash loan uses the borrower's share). It is updated immediately when payments change and on every page load. The field is read-only on the obligation form.
- **One-time upgrade.** Existing schedules were re-derived from borrower payments, replacing amounts entered with the old Pay button (marker `storage/upgrade_schedule_sync.done`). Card payments recorded earlier were kept.

## Borrower chooses what a payment is for (approved table `borrower_payment_selections`)

- **Borrower (Submit Payment):** ticks one or more items or installments and types an amount for each. The installments of an installment purchase are listed separately, plus "late charges / any part of this item"; a cash loan share or a single-due item appears as one line.
  - An amount can't be more than what that line owes, and the total chosen can't be more than the amount paid.
  - Choosing is optional. Anything not chosen is left to the owner/admin.
- **Owner/admin:** the payment page shows *What the borrower wants to pay* and the amount not assigned.
  - **Approving** applies the payment exactly as chosen. Each item pays its late charges first, then interest, then principal. An installment choice is credited to that installment, even if older ones are still open.
  - **The rest stays unallocated** until the owner/admin posts it with **Allocate**, choosing the item and optionally the installment.
- **Removed:** "Auto-allocate" and the daily automatic application of leftovers.
- **Schedules:** amounts aimed at a specific installment are credited there first; the rest of an item's payments fill its installments oldest first.
- **Upgrade:** the table is created automatically on first load (marker `storage/upgrade_payment_selections.done`); the same SQL is in `db/upgrade_payment_selections.sql`.

## Monthly schedule on every obligation (no schema change)

- When an owner, admin or borrower opens an obligation, they see the amount to pay each month. This is on `pages/obligations/view.php` and `portal/obligation.php`. There is no Pay button.
- **Installment purchases:** the obligation shows its installment plan (`installment_schedules`), as before.
- **Everything else** (cash loans, cash advances, straight purchases, manual items) gets a monthly schedule from `obligation_monthly_rows()` in `includes/schedule_sync.php`:
  - **Amounts:** the obligation's own total (principal + agreed interest + agreed fees) is split evenly. Interest or fees added later on Edit Obligation are included, and the last month takes the rounding.
  - **Dates:** the cash loan's installment dates are used when the loan ends on the obligation's due date. Otherwise there is one date a month, counted back from the due date to the month after the obligation date (e.g. obligation Oct 9, 2026, due Oct 16, 2028 → 24 months from Nov 16, 2026).
  - **Paid and status:** the borrower's approved payments fill the oldest month first. Each month's status (Paid / Overdue / Partially paid / Due / Upcoming) follows its due date automatically. Nothing is stored.
- **Overdue amount:** what was due by today minus what was paid. An obligation becomes **Overdue** as soon as one month is missed, not only after the final due date.
- **Submit Payment:** the open months are listed under each such item.
- **Penalties:** unchanged. They are still charged per installment on installment purchases, and on the whole balance after the final due date otherwise.

## "Add" forms open in a modal (all modules)

Every add/create action now opens one shared modal on the current page instead of going to a separate create page.

| Where | Buttons |
|---|---|
| List pages (`render_list_header` / `render_empty_state`) | Add New / Add User / Record Payment / Upload File / Send Notification in Categories, Merchants, Payment Methods, Penalty Rules, Borrowers, Obligations, Borrower Payments, Credit Cards, Statements, Card Payments, Bank Charges, Transactions, Installment Plans, Cash Loans, Cash Advances, Agreements, Agreement Templates, Demand Letters, Letter Templates, Documents, Notifications, Users |
| Detail pages (links) | Borrower → Add payment method / obligation / Record payment, Create user; Transaction → Create plan / Add obligation / Add item; Credit card → Add cash loan / statement; Statement → Record payment; Obligation → Record payment, Create a rule; Category → Add sub-category |
| Detail pages (inline add panels) | Borrower → Add address / contact; Agreement → Add signatory; Credit card → Add cycle; Obligation → Apply penalty; Cash loan → Add allocation; Cash advance → Give to a borrower |
| Borrower portal | Submit a payment |

How it works (no duplicated forms, the existing pages and handlers do the work):

- **Triggers:** `a[data-modal-form]` loads that page's form into the modal, and `[data-modal-panel="id"]` moves the inline add panel into the modal. All of this is in `assets/js/modal-form.js`.
- **Requests:** they carry `X-Modal-Form: 1`. For such requests `redirect()` (`includes/functions.php`) answers `{ok, redirect, error}` instead of a redirect.
- **Validation:**
  - Required fields are checked in the browser (`data-validate`).
  - Server validation errors re-render the form inside the modal, and what was typed is kept.
  - An error message from a handler (e.g. "Only ₱X of the loan is not yet allocated") is shown in the modal, which stays open.
- **Saved:** the modal closes, the page content (list, totals, related tables) is reloaded in place without a full page load, and the success message appears.
- **Closing:** use the X, Cancel, Esc or the backdrop. If something was typed, a confirmation ("Discard what you entered?") prevents accidental loss.
- **Behaviour:**
  - Focus stays inside the modal.
  - Save/Cancel stay visible at the bottom.
  - The modal is full-screen on phones.
- **Form only:** modals show just the form. Side panels (`.detail-side`) are hidden and layouts become one column. Submit Payment no longer has the "Where to pay" / "What happens next" side panels; the owner's accounts are still listed in "How did you pay?".
- **Shared scripts:** `dashboard.js`, `modules.js`, `categories.js` and `portal-pay.js` now register their set-up with `App.register()`, so they also work on content added later.
- **Unchanged:** Edit, View, Delete and other actions work as before. The create pages still work on their own (e.g. without JavaScript).
- **Fixed on the way:** the sidebar loop variable `$item` overwrote the item page's own `$item`, so Transaction → Add item showed "Edit item" and could not save.

## Edit forms in the modal (all modules)

Every Edit button (list row pencil icons, detail page "Edit", Transaction → edit item, the borrower's "Edit / Fix and resubmit") opens the record's existing edit form in the shared modal, prefilled. Validation errors keep the modal open; saving refreshes the page content in place. `icon_link(…, 'edit', …)` adds `data-modal-form` automatically.

## Statements: billing periods from your records + the bank's SOA (`includes/statement_engine.php`)

**What the Statements module does now:**

- It shows every registered card (issuer, last four, what is left to pay). For the chosen card it lists the billing periods (cutoff day from the card), newest first and paginated.
- Each period lists the records already in the database by their own dates: purchases, installment charges (one schedule row per period), cash advances and fees, bank charges (annual fee, interest, late fees), payments and credits.
- Nothing is generated or saved while you look.
- **Record SOA** (modal, card and period prefilled) saves only what comes from the bank: actual SOA amount, minimum amount due, payment due date, and optionally the statement date, number, file and notes. One SOA per card and billing period (validation + unique key).
- **The SOA amount is kept exactly as entered.** The statement view compares it with the records: previous recorded SOA + charges − payments and credits. It shows the difference and a warning, and **Add missing transaction** (purchase / bank charge / cash advance / payment, in a modal) lists the record in the right period. The SOA amount is never changed by it.
- **Pay** (modal, from the statement list, the statement, the card page or the dashboard) shows the SOA amount, minimum, due date, paid and remaining. It saves one row in `credit_card_payments` through the Card Payments module's own fields, validation and saving, linked to the card and the statement. A one-time form token stops double submissions.

**Payment status of a recorded SOA:**

| Status | Meaning |
|---|---|
| Paid | Payments cover the SOA amount. |
| Overdue | Due date passed and something is still unpaid. |
| Partially paid | Some payment recorded, a balance is left. |
| Pending | Nothing paid yet. |
| Unrecorded SOA | A closed period with no SOA. |

"Due soon" is added for unpaid statements due within 7 days (`STATEMENT_UPCOMING_DAYS`).

**Dashboard → Credit card payments:**

- **Summary cards** (each opens the matching list): registered cards, pending payments (statements to pay, and on how many cards), partially paid, total outstanding statement balance, upcoming (7 days), overdue.
- **Table** of upcoming and overdue statements with View and Pay.
- **Figures** come from one grouped query over the recorded SOAs and their payments; each statement is counted once.

**Why the Statements page was slow (fixed at the source):**

- **Cause:** the previous version generated and recalculated statements on every visit, for every card, from the card's first record. A card with an old opening date (e.g. 2008) produced about 224 statements, each recalculated with about 10 queries on each page load, plus writes. A long first run could pass PHP's time limit, roll back, and start over on the next load, so the page never finished.
- **Removed:** all automatic generation and the writes on page load.
- **Periods are now computed in memory:**
  - the records of the periods shown are read in one pass (about 8 queries per page, whatever the number of periods);
  - payments are summed in one grouped subquery, with no query per row;
  - pagination applies to both periods and statements.
- **Indexes added:** (card, date) on transactions, bank charges, card payments and cash advances.
- **Measured** with 20,000 transactions and 36 SOAs on one card: the Statements page loads in about 0.1 s.

**Database changes (`db/upgrade_statements_v2.sql`, applied automatically):**

- `credit_card_statements.notes`, the unique key (card, cutoff date), and the date-range indexes above.
- **One-time cleanup** (logged in the activity log):
  - generated statements with an SOA you had entered became that SOA (your bank figures);
  - generated statements you edited or linked to something were kept as records;
  - generated statements with no input from you were removed, with the billing cycles created for them.
- The earlier SOA comparisons (`statement_reconciliations`) stay as read-only history on the statement.

**View buttons in the modal:** every View (eye icons, record links in lists, the dashboard, reports and the borrower portal) opens the record in the shared modal. The record's own actions (Edit, Pay, Approve, …) appear at the top of the modal; Back and Print stay on the full page. Add, Edit and View now all use the modal.

## DATABASE LIMITATIONS (no schema changes were made)

| Feature | Missing information | Affected module | Recommended schema change |
|---|---|---|---|
| Voiding a card payment or bank charge | no `status` / `voided_at` column | Card Payments, Bank Charges | add `status ENUM('posted','void') DEFAULT 'posted'` |
| Deactivating a merchant | no `is_active` column | Merchants | add `is_active TINYINT(1) NOT NULL DEFAULT 1` |
| Penalties counted in balances | `applied_penalties` is not part of `vw_borrower_balances` | Obligations, Reports | add penalty totals to the view, or allocate payments to penalties |
| Email / SMS / push delivery | no provider settings table | Notifications | add a settings table or configure a mail provider; queued items stay *pending* |
| Settings page | no settings table | System | add a `settings` key/value table |
| Stored agreement text | `agreements` has no body column | Agreements | the text is generated from the template; store a PDF via *Generated copy* |

Waiting for your approval before any structural database change.

## C. Files created (version 2)

```
includes/db_helpers.php      includes/form_fields.php     includes/list_helpers.php
includes/lookups.php         includes/schedules.php        assets/js/modules.js
storage/.htaccess  storage/.gitignore  storage/documents/.gitkeep
pages/merchants/            merchant_functions, index, create, edit, view, delete
pages/payment_methods/      payment_method_functions, index, create, edit, view, delete
pages/penalty_rules/        penalty_rule_functions, index, create, edit, view, delete
pages/agreement_templates/  template_functions, index, create, edit, view, delete
pages/letter_templates/     template_functions, index, create, edit, view, delete
pages/users/                user_functions, _form, index, create, edit, view, actions
pages/profile/              index, password
pages/borrowers/            borrower_functions, index, create, edit, view, related, delete
pages/credit_cards/         credit_card_functions, index, create, edit, view, related, delete
pages/statements/           statement_functions, index, create, edit, view, delete
pages/card_payments/        card_payment_functions, index, create, edit, view
pages/bank_charges/         bank_charge_functions, index, create, edit, view
pages/cash_advances/        cash_advance_functions, index, create, edit, view
pages/transactions/         transaction_functions, index, create, edit, view, item, related, delete
pages/installment_plans/    plan_functions, index, create, edit, view, related, delete
pages/cash_loans/           cash_loan_functions, index, create, edit, view, related, delete
pages/obligations/          obligation_functions, index, create, edit, view, related
pages/borrower_payments/    payment_functions, index, create, edit, view, review, related
pages/documents/            document_functions, index, create, edit, view, download, delete
pages/agreements/           agreement_functions, index, create, edit, view, related, delete
pages/demand_letters/       letter_functions, index, create, edit, view, delete
pages/notifications/        notification_functions, index, create, view
pages/activity_logs/        index, view
pages/reports/              index, borrower_balances, overdue, pending_payments, collections, transactions, card_utilization
```

(`*_functions.php` holds the module's fields, validation and queries; `related.php` handles actions on child rows.)

## D. Files modified

| File | Reason |
|---|---|
| includes/sidebar.php | all modules linked, "Soon" placeholders removed, new groups |
| includes/header.php | bell links to unread notifications; profile/password in the menu |
| includes/footer.php | loads shared `modules.js` |
| includes/bootstrap.php | loads the new helper files |
| includes/functions.php | `fill_template()` for templates |
| includes/auth_check.php, includes/functions.php | `never` → `void` (PHP 8.0 XAMPP compatibility) |
| pages/dashboard.php | links from cards and panels to modules |
| assets/css/base.css | date/tel/url inputs styled like other inputs |
| assets/css/dashboard.css | shared module styles (forms, figures, sub-panels, schedules, documents, reports) |
| assets/js/dashboard.js | confirmation dialog supports custom title/button (cancel, approve…) |

Login, authentication, database connection, layout and the Categories module were **not rebuilt**.

## E. Database tables used — all 41

users, user_roles, borrowers, borrower_user_accounts, borrower_addresses, borrower_contacts, categories,
merchants, credit_cards, card_billing_cycles, documents, credit_card_statements, transactions,
transaction_items, transaction_attachments, installment_plans, installment_schedules, cash_loans,
cash_loan_schedules, cash_advances, cash_loan_allocations, borrower_obligations, payment_methods,
borrower_payments, borrower_payment_allocations, payment_verifications, payment_verification_logs,
payment_attachments, credit_card_payments, bank_charges, penalty_rules, applied_penalties,
agreement_templates, agreements, agreement_signatories, agreement_documents, demand_letter_templates,
demand_letters, notifications, notification_logs, activity_logs — plus the 4 views in Reports/Dashboard.

## F. Testing checklist (run against MariaDB 10.11 with the imported schema)

**Authentication** — ☑ login ☑ logout ☑ session/idle timeout ☑ borrower gets 403 on every new module (including POST actions and downloads) ☑ anonymous → login.

**Every module** — ☑ list ☑ create ☑ validation (required, length, number, date, unique, foreign key, cross-record ownership) ☑ view ☑ edit ☑ delete/cancel rules ☑ search ☑ filters ☑ pagination with filters kept ☑ print preview of filtered rows. An automated crawl of every list, print, create, view and edit page returned no PHP warnings or errors.

**Workflows** — ☑ transaction → items → attachment → installment plan → schedule payment ☑ obligation → penalty (cap applied) → overdue status ☑ payment: allocate blocked before approval → approve → allocate to installment → auto-allocate → fully allocated, obligation partially paid ☑ cash loan → schedule → allocation cap → obligation created and linked ☑ agreement → signatory → signed ☑ demand letter → fill from template → approve → sent (invalid jump rejected) ☑ document upload (fake PDF rejected), preview, archive/restore ☑ notification delivered / queued, bell count.

**Regression** — ☑ Categories create/duplicate/edit/search/print/delete protection ☑ login/logout.

**UI** — ☑ desktop ☑ tablet (820px) ☑ mobile (390px, card tables, drawer) ☑ modals ☑ notifications ☐ please check printing on your own printer.

## Security audit (all modules)

☑ prepared statements everywhere (identifiers only from code, checked by pattern) ☑ whitelisted sort/filter values
☑ output escaped with `e()` ☑ CSRF on every POST (create, update, delete, cancel, approve, allocate, upload, status)
☑ `require_admin()` on every page, re-reading status and roles ☑ password hashing / current-password check
☑ safe delete with dependency checks in transactions ☑ friendly errors only (`db_error_message()`)
☑ uploads validated and stored outside the web root folder rules ☑ no `SELECT *` on list pages; one PDO connection; server-side pagination (print capped at 5,000 rows).
