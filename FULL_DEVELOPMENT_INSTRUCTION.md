# **FULL DEVELOPMENT INSTRUCTION**

## **PHP \+ HTML \+ CSS \+ JAVASCRIPT \+ MYSQL \+ XAMPP SYSTEM**

I am going to provide/upload the **database schema for my system**.

You must use the uploaded database schema as the **primary source of truth** for the system architecture.

I want you to create the first working version of my system consisting of:

DATABASE SCHEMA ANALYSIS  
        ↓  
SECURE LOGIN  
        ↓  
SESSION AUTHENTICATION  
        ↓  
ROLE / USER TYPE IDENTIFICATION  
        ↓  
ADMIN DASHBOARD  
        ↓  
PERSISTENT SIDEBAR  
        ↓  
ONE COMPLETE CRUD MODULE  
        ↓  
SEARCH  
        ↓  
PRINT

The system must be a real working **PHP \+ MySQL application for XAMPP**, not a static frontend prototype.

---

# **1\. TECHNOLOGY REQUIREMENTS**

Build the system using:

* PHP 8+  
* MySQL  
* XAMPP  
* HTML5  
* CSS3  
* Vanilla JavaScript  
* PHP Sessions  
* PDO or MySQLi with prepared statements

Prefer:

* PDO  
* Vanilla JavaScript  
* Custom CSS  
* Semantic HTML5

Do NOT use:

* Laravel  
* CodeIgniter  
* Symfony  
* React  
* Vue  
* Angular  
* Node.js  
* npm  
* Tailwind as a required dependency  
* Bootstrap as a required dependency

I want the project to remain simple enough to run directly inside XAMPP.

The application should work by placing the project inside:

xampp/htdocs/

and starting:

Apache  
MySQL

from XAMPP.

---

# **2\. MY EXISTING PROJECT FOLDER**

My project folder is:

paswipe\_me/

inside XAMPP `htdocs`.

My preferred structure is:

paswipe\_me/  
│  
├── assets/  
│   ├── css/  
│   └── js/  
│  
├── auth/  
│  
├── config/  
│  
├── db/  
│  
├── includes/  
│  
└── pages/

You must respect this structure.

You may add subfolders/files when necessary, but do not completely redesign my folder organization without explaining why.

---

# **3\. VERY IMPORTANT — PHP \+ HTML \+ CSS \+ JAVASCRIPT**

This is a **PHP web application**.

PHP and HTML are expected to work together inside `.php` files.

For example:

pages/dashboard.php  
pages/departments/index.php  
auth/login.php

These files may contain:

* PHP  
* HTML

However, CSS and JavaScript should be separated into external files.

The architecture should be:

PHP \+ HTML  
     │  
     ├── Server-side logic  
     ├── Database operations  
     ├── Authentication  
     ├── Sessions  
     ├── CRUD  
     └── Page rendering  
       
CSS  
     │  
     └── Styling \+ responsive layout

JavaScript  
     │  
     └── Client-side interactions

MySQL  
     │  
     └── Persistent database

Do NOT create a static HTML-only system.

Do NOT create a frontend-only mockup.

---

# **4\. HTML REQUIREMENT**

HTML should be written inside the appropriate PHP pages.

For example:

\<?php  
// PHP logic  
?\>

\<\!DOCTYPE html\>  
\<html lang="en"\>  
\<head\>  
    ...  
\</head\>

\<body\>

    \<\!-- HTML \--\>

\</body\>  
\</html\>

or through reusable includes:

\<?php include '../../includes/header.php'; ?\>

\<main\>  
    \<\!-- page content \--\>  
\</main\>

\<?php include '../../includes/footer.php'; ?\>

Use semantic HTML5:

\<header\>  
\<nav\>  
\<main\>  
\<section\>  
\<article\>  
\<form\>  
\<table\>  
\<footer\>

---

# **5\. CSS REQUIREMENT**

All major CSS must be placed in:

assets/css/

For example:

assets/css/  
├── auth.css  
├── dashboard.css  
└── \[module\].css

Do not put hundreds of lines of CSS inside PHP files.

Avoid:

\<style\>  
    /\* huge stylesheet \*/  
\</style\>

inside the page.

Small inline styling should generally be avoided as well.

---

# **6\. JAVASCRIPT REQUIREMENT**

All significant JavaScript must be placed in:

assets/js/

For example:

assets/js/  
├── auth.js  
├── dashboard.js  
└── \[module\].js

Use JavaScript for:

* Show/hide password  
* Sidebar mobile toggle  
* Modal handling  
* Delete confirmation  
* UI interactions  
* Form enhancements  
* Search interaction where appropriate  
* Notifications  
* Loading states  
* Other client-side functionality

Do not put a huge `<script>` block inside the PHP page.

---

# **7\. DATABASE SCHEMA IS THE SOURCE OF TRUTH**

Before writing any implementation, inspect the uploaded schema carefully.

You must identify:

## **Tables**

List every table.

## **Columns**

For each relevant table determine:

* column name  
* data type  
* length  
* nullable  
* default  
* primary key  
* foreign key  
* unique constraint  
* auto increment  
* indexes

## **Relationships**

Determine:

* one-to-one  
* one-to-many  
* many-to-many  
* foreign-key dependencies

## **Authentication**

Determine which table(s) are responsible for:

* username  
* email  
* password  
* password hash  
* account status  
* user type  
* role  
* profile

## **Administrative data**

Determine which tables can reasonably be managed by administrators.

---

# **8\. NEVER INVENT DATABASE STRUCTURE**

This is extremely important.

Do NOT create random tables or fields just because they are convenient.

For example, do not automatically create:

admins  
users  
roles  
permissions  
admin\_users  
user\_profiles

unless those tables are already in the schema or there is a clearly identified requirement.

If the schema already contains the functionality, use it.

If something is missing, stop and tell me.

Use this format:

SCHEMA ISSUE FOUND

Missing table:  
Missing column:  
Reason:  
Impact:  
Recommended solution:

Do not silently modify the database.

---

# **9\. DATABASE ANALYSIS BEFORE CODING**

Before generating any code, give me a complete analysis.

Use this structure:

## **DATABASE**

Database Name:  
Tables:  
Relationships:

## **AUTHENTICATION TABLE**

Table:  
Primary Key:  
Username/Email:  
Password:  
Status:  
Role/User Type:

## **ADMIN-RELEVANT TABLES**

Create a table:

| Table | Purpose | Admin CRUD? | Related Tables |
| :---: | :---: | :---: | :---: |

---

# **10\. IDENTIFY ALL POSSIBLE ADMIN MODULES**

Based on the schema, determine which modules an administrator should have.

Do NOT simply create generic modules.

Use the actual database.

For example, if the database contains:

users  
departments  
programs  
courses  
students  
transactions  
categories

then determine which should appear in the administrator's navigation.

Potential categories:

Dashboard

MASTER DATA  
\- Departments  
\- Programs  
\- Courses

USER MANAGEMENT  
\- Users

TRANSACTIONS  
\- Transactions

REPORTS  
\- Reports

SYSTEM  
\- Profile  
\- Settings

But this is only an example.

The actual modules must be based on my schema.

---

# **11\. ADMIN MODULE ANALYSIS**

For every proposed module, explain:

| Module | Database Table | Purpose | Create | Read | Update | Delete | Search | Print |
| :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |

Also explain why the module belongs in the admin dashboard.

---

# **12\. FIRST CRUD MODULE**

For the first version, implement ONLY **ONE complete CRUD module**.

Do not implement all database modules yet.

Choose the best first module based on:

1. It exists in the database.  
2. It is relatively independent.  
3. It is safe for testing.  
4. It demonstrates the architecture.  
5. It has meaningful CRUD operations.  
6. It allows search.  
7. It can demonstrate printing.

Possible examples:

Departments  
Programs  
Courses  
Categories  
Users  
Products

But you MUST select based on my actual schema.

Explain:

SELECTED FIRST CRUD MODULE:  
Database table:  
Reason:  
Primary key:  
Fields:  
Foreign keys:  
Searchable fields:  
CRUD operations:  
Print contents:

---

# **13\. LOGIN SYSTEM**

Create a complete modern login system based on the actual authentication schema.

The login page should use the actual identifier available in the database.

It could be:

Username

or:

Email

or:

User ID

or a combination.

Do not assume.

Determine this from the schema.

---

# **14\. LOGIN UI**

Create a modern professional login interface.

It should include:

* System logo/branding area if appropriate  
* System name  
* System description/tagline if available  
* Username/email field  
* Password field  
* Show/Hide Password  
* Login button  
* Loading state  
* Error messages  
* Success state if appropriate

Do not create unnecessary registration functionality unless the schema and requirements call for it.

---

# **15\. LOGIN SECURITY**

Use:

password\_hash()  
password\_verify()

Never store or compare plain-text passwords.

Use prepared statements.

Regenerate the session ID after successful authentication:

session\_regenerate\_id(true);

Use secure session handling.

---

# **16\. LOGIN FLOW**

The authentication flow should be:

LOGIN PAGE  
     ↓  
INPUT CREDENTIALS  
     ↓  
SERVER-SIDE VALIDATION  
     ↓  
DATABASE QUERY  
     ↓  
VERIFY PASSWORD  
     ↓  
CHECK ACCOUNT STATUS  
     ↓  
CHECK USER ROLE  
     ↓  
CREATE SESSION  
     ↓  
REDIRECT TO APPROPRIATE DASHBOARD

---

# **17\. ROLE-BASED AUTHENTICATION**

If the schema supports different user types/roles, detect them dynamically.

For example:

ADMIN  
PROGRAM CHAIR  
DEAN  
FACULTY  
STAFF  
USER

Do not invent roles that are not supported.

For the first implementation, fully implement the ADMIN flow.

Other roles can be prepared for future development.

---

# **18\. AUTHORIZATION**

Security must happen server-side.

Do not simply hide admin links.

For every admin page, verify:

User logged in?  
        ↓  
User has required role?  
        ↓  
Allow

Otherwise:

Not logged in → Login  
Wrong role → Access denied

A user must not be able to access:

/pages/admin-module/

just by manually typing the URL.

---

# **19\. SESSION MANAGEMENT**

Create reusable session/authentication handling.

For example:

includes/auth\_check.php

It should verify the session.

Create appropriate session variables based on the database.

For example:

\$\_SESSION\['user\_id'\]  
\$\_SESSION\['user\_role'\]  
\$\_SESSION\['username'\]

But only use fields actually supported by the schema.

---

# **20\. LOGOUT**

Create:

auth/logout.php

Logout should:

1. Unset session data.  
2. Destroy the session.  
3. Redirect to login.  
4. Prevent returning to protected pages using browser back/cache where practical.

---

# **21\. DASHBOARD ARCHITECTURE**

Create a reusable admin dashboard layout.

The overall structure should be:

┌─────────────────────────────────────────────────────┐  
│ HEADER                                              │  
├───────────────┬─────────────────────────────────────┤  
│               │                                     │  
│ SIDEBAR       │ MAIN CONTENT                       │  
│               │                                     │  
│ Dashboard     │ Dashboard Content                  │  
│ Module 1      │                                     │  
│ Module 2      │                                     │  
│ Module 3      │                                     │  
│ Reports       │                                     │  
│ Settings      │                                     │  
│ Logout        │                                     │  
│               │                                     │  
├───────────────┴─────────────────────────────────────┤  
│ FOOTER                                              │  
└─────────────────────────────────────────────────────┘

---

# **22\. PERSISTENT SIDEBAR — VERY IMPORTANT**

When I click a sidebar item:

Dashboard  
Module 1  
Module 2  
Reports  
Settings

the sidebar must remain part of the dashboard.

It should NOT disappear.

The layout must remain consistent.

For example:

HEADER  
────────────────────────────────────────

SIDEBAR │ MODULE CONTENT

SIDEBAR │ MODULE CONTENT

SIDEBAR │ MODULE CONTENT

────────────────────────────────────────  
FOOTER

Only the main content should change.

Use reusable PHP includes:

includes/  
├── header.php  
├── sidebar.php  
└── footer.php

Every dashboard page should use the same layout.

---

# **23\. DESKTOP SIDEBAR**

On desktop:

┌──────────────┬──────────────────────────┐  
│              │                          │  
│ SIDEBAR      │ MAIN CONTENT             │  
│              │                          │  
│ Dashboard    │                          │  
│ Module       │                          │  
│ Reports      │                          │  
│ Settings     │                          │  
│ Logout       │                          │  
│              │                          │  
└──────────────┴──────────────────────────┘

Sidebar should have:

* icons  
* labels  
* active state  
* hover state  
* clear grouping  
* good spacing  
* scroll support if necessary

---

# **24\. MOBILE SIDEBAR**

On mobile, the sidebar can become an off-canvas drawer.

For example:

☰

opens the sidebar.

The sidebar should not destroy the page layout.

When navigating, the system should still use the same dashboard structure.

---

# **25\. HEADER**

Create:

includes/header.php

It should contain appropriate information such as:

* System name  
* Page title  
* Logged-in user  
* User role  
* Profile menu  
* Notification area if appropriate  
* Mobile sidebar toggle

Do not display information that does not exist.

---

# **26\. FOOTER**

Create:

includes/footer.php

The footer should be reusable across all dashboard pages.

Example:

© 2026 \[System Name\]  
All Rights Reserved.

Use the actual system name if known.

---

# **27\. ADMIN DASHBOARD CONTENT**

The dashboard should use real database information.

Do not create fake statistics.

Possible cards:

TOTAL RECORDS  
ACTIVE RECORDS  
INACTIVE RECORDS  
TOTAL USERS

But only display metrics supported by the schema.

Example:

┌───────────────┐  
│ TOTAL USERS   │  
│      125      │  
└───────────────┘

Use actual database queries.

---

# **28\. DASHBOARD UI**

The dashboard should look modern and professional.

Use:

* cards  
* tables  
* statistics  
* icons  
* clear typography  
* whitespace  
* subtle shadows  
* consistent spacing  
* responsive layout

Avoid:

* excessive gradients  
* excessive animations  
* clutter  
* old-fashioned PHP admin design  
* giant buttons  
* unnecessary visual effects

---

# **29\. FIRST CRUD MODULE**

The first CRUD module must demonstrate the complete architecture.

Suppose the selected table is:

departments

The page might be:

pages/departments/index.php

But use the actual table selected from the schema.

---

# **30\. CRUD — CREATE**

Create an Add New button.

Example:

\[ \+ Add New \]

Clicking it should open either:

* a dedicated create page, or  
* a modal

Choose the approach that best fits the module.

The form should include the actual database fields.

Validate:

* required fields  
* data type  
* length  
* unique values  
* foreign keys  
* valid values

Use server-side validation.

---

# **31\. CRUD — READ**

Display records in a professional table.

Example:

┌────┬──────────────┬──────────────┬──────────────┐  
│ ID │ NAME         │ STATUS       │ ACTIONS      │  
├────┼──────────────┼──────────────┼──────────────┤  
│ 1  │ ...          │ Active       │ View Edit... │  
│ 2  │ ...          │ Active       │ View Edit... │  
└────┴──────────────┴──────────────┴──────────────┘

Use only actual database fields.

---

# **32\. CRUD — UPDATE**

Edit must:

1. Load the existing record.  
2. Populate the form.  
3. Validate submitted data.  
4. Update the correct record.  
5. Redirect/display success.

Use the primary key from the schema.

Do not allow a user to manipulate unrelated fields through the URL.

---

# **33\. CRUD — DELETE**

Implement safe deletion.

When clicking delete:

Are you sure you want to delete this record?

Provide:

Cancel  
Delete

Before deleting, check whether the record is referenced by other tables.

If there are foreign-key dependencies, do not blindly delete.

If the schema supports:

status \= inactive

or another soft-delete mechanism, consider using that instead.

---

# **34\. CRUD — VIEW**

If appropriate, provide a detailed record view.

Example:

Record Details

ID:  
Name:  
Status:  
Created:  
Updated:  
...

Only display actual fields.

---

# **35\. SEARCH**

The CRUD module must include search.

Example:

Search \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ 🔍

Search should use relevant columns.

For example:

name  
code  
description  
status

depending on the actual table.

Use prepared statements.

Do not concatenate raw user input into SQL.

---

# **36\. SEARCH UX**

Search should be easy to use.

Provide:

Search  
Clear

If appropriate.

When there are no results:

No records found.  
Try another search term.

Do not display a blank table with no explanation.

---

# **37\. FILTERS**

If the selected table has meaningful filterable data, include filters.

Examples:

Status  
Category  
Department  
Type  
Date

Only create filters that make sense for the actual schema.

---

# **38\. PAGINATION**

If the table can contain many records, implement pagination.

Example:

Showing 1–10 of 86 records

Previous  1  2  3  4  Next

Use server-side pagination when appropriate.

Do not load thousands of records unnecessarily.

---

# **39\. PRINT FUNCTION**

The first CRUD module MUST have a Print function.

Example:

\[ Add New \] \[ Search \] \[ Print \]

When Print is clicked, the user should receive a clean browser print preview.

The print output should contain:

* system name  
* module title  
* date/time printed  
* search/filter information if appropriate  
* relevant records  
* table headings

Hide:

* sidebar  
* header navigation  
* buttons  
* edit/delete actions  
* search controls  
* unnecessary UI

Use:

@media print {  
}

The print version should be professional and readable.

---

# **40\. PRINT CURRENT RESULTS**

If the administrator searches for:

ABC

and receives 5 results, the print function should preferably print the currently filtered result set.

Explain how this is implemented.

---

# **41\. RESPONSIVE DESIGN**

The system must be responsive.

Test mentally for:

### **Desktop**

Sidebar \+ Main Content

### **Tablet**

Adapted Sidebar \+ Main Content

### **Mobile**

Header  
☰  
Main Content

Tables should be responsive.

Forms should adapt.

Cards should stack appropriately.

Buttons should remain usable on touch devices.

---

# **42\. UI DESIGN SYSTEM**

Create a consistent visual system.

Define:

* primary color  
* secondary color  
* background  
* surface/card color  
* text colors  
* border colors  
* success  
* warning  
* danger  
* hover states

Use CSS variables where appropriate:

:root {  
    \--primary: ...;  
    \--background: ...;  
    \--surface: ...;  
    \--text: ...;  
}

Do not use excessive colors.

---

# **43\. TYPOGRAPHY**

Use a modern readable font stack.

If using an external font, make sure the application can still function if the font cannot load.

Prioritize:

* readability  
* hierarchy  
* accessibility  
* spacing

---

# **44\. ICONS**

You may use an appropriate icon library if it does not create unnecessary complexity.

If using external CDN resources, clearly identify them.

Otherwise, use simple CSS/text/icon solutions.

Do not require Node.js or npm just to display icons.

---

# **45\. NOTIFICATIONS**

Use modern notification messages.

For example:

✓ Record successfully added.

or:

✕ Unable to delete record.

Notifications should be:

* clear  
* readable  
* dismissible where appropriate

Do not expose raw SQL errors.

---

# **46\. ERROR HANDLING**

Do not show users raw database errors such as:

SQLSTATE\[23000\]

Instead show:

Unable to save the record because it is being used by another record.

For development, errors can be logged appropriately.

---

# **47\. DATABASE CONNECTION**

Create:

config/database.php

Use one reusable database connection.

Example:

\$host \= 'localhost';  
\$dbname \= 'DATABASE\_NAME';  
\$username \= 'root';  
\$password \= '';

But determine the database name from the uploaded schema if available.

Do not invent credentials.

Clearly tell me what I need to configure.

---

# **48\. PDO**

Prefer PDO.

Example architecture:

try {  
    \$pdo \= new PDO(...);  
    \$pdo-\>setAttribute(  
        PDO::ATTR\_ERRMODE,  
        PDO::ERRMODE\_EXCEPTION  
    );  
} catch (PDOException \$e) {  
    ...  
}

Do not duplicate the connection in every page.

---

# **49\. SECURITY — SQL INJECTION**

Every database query involving user input must use prepared statements.

Bad:

$sql="SELECT*FROMusersWHEREusername='$username'";

Do NOT do this.

Use:

\$stmt \= \$pdo-\>prepare(  
    "SELECT \* FROM users WHERE username \= :username"  
);

\$stmt-\>execute(\[  
    ':username' \=\> \$username  
\]);

---

# **50\. SECURITY — XSS**

Escape output appropriately.

For example:

htmlspecialchars(\$value, ENT\_QUOTES, 'UTF-8')

Do not blindly print database values into HTML.

---

# **51\. SECURITY — CSRF**

Implement CSRF protection for state-changing actions where appropriate.

At minimum, protect:

Create  
Update  
Delete

Explain how the CSRF token works.

---

# **52\. SECURITY — AUTHORIZATION**

Every protected page must check permissions server-side.

Do not rely on:

sidebar hidden

as a security measure.

A user who manually enters:

/pages/module/delete.php

must still be denied if unauthorized.

---

# **53\. SECURITY — SESSION**

Use:

session\_start();

where appropriate.

After successful login:

session\_regenerate\_id(true);

Destroy session on logout.

Do not store the password in the session.

---

# **54\. SECURITY — PASSWORD**

Use:

password\_hash()  
password\_verify()

Do not use:

MD5  
SHA1  
plain-text password

for password storage.

If the existing database uses a legacy password mechanism, identify it before implementation and explain the migration/security implications rather than silently breaking authentication.

---

# **55\. FOLDER STRUCTURE**

Start with this structure and adjust only when necessary:

paswipe\_me/  
│  
├── assets/  
│   │  
│   ├── css/  
│   │   ├── auth.css  
│   │   ├── dashboard.css  
│   │   └── \[first-module\].css  
│   │  
│   └── js/  
│       ├── auth.js  
│       ├── dashboard.js  
│       └── \[first-module\].js  
│  
├── auth/  
│   ├── login.php  
│   └── logout.php  
│  
├── config/  
│   └── database.php  
│  
├── db/  
│   └── \[schema/database files if needed\]  
│  
├── includes/  
│   ├── auth\_check.php  
│   ├── header.php  
│   ├── sidebar.php  
│   ├── footer.php  
│   ├── functions.php  
│   └── csrf.php  
│  
├── pages/  
│   ├── dashboard.php  
│   │  
│   └── \[first-module\]/  
│       ├── index.php  
│       ├── create.php  
│       ├── view.php  
│       ├── edit.php  
│       └── delete.php  
│  
└── index.php

You may modify this if the schema or architecture requires it.

---

# **56\. FILE PATHS**

Make sure all paths are correct.

My application may be located at:

htdocs/paswipe\_me/

Do not hardcode:

/Applications/XAMPP/xamppfiles/htdocs/paswipe\_me/

inside the application.

Do not assume the project is directly under `htdocs`.

Use relative paths or a configurable base URL.

---

# **57\. IMPORTANT — NO BROKEN RELATIVE PATHS**

Because files may exist at different directory depths:

auth/login.php  
pages/dashboard.php  
pages/module/index.php  
includes/header.php

carefully calculate relative paths.

For example:

../../assets/css/module.css

may be required from a nested module page.

Do not blindly copy:

../assets/

everywhere.

Verify the path for each file.

---

# **58\. REUSABLE INCLUDE FILES**

Create reusable components.

### **header.php**

Responsible for:

* document header  
* page title  
* CSS loading  
* header structure

### **sidebar.php**

Responsible for:

* navigation  
* active page  
* role-aware menu items

### **footer.php**

Responsible for:

* footer  
* JavaScript loading

### **auth\_check.php**

Responsible for:

* checking authentication  
* checking role

### **functions.php**

Responsible for reusable helper functions.

Do not put unrelated code into one helper file.

---

# **59\. SIDEBAR ACTIVE STATE**

When I am inside:

Departments

the sidebar should visually indicate:

Departments ← ACTIVE

When I navigate to:

Dashboard

Dashboard becomes active.

This should be generated dynamically based on the current page.

---

# **60\. SIDEBAR COLLAPSE**

On desktop, optionally support:

Expanded:

\[icon\] Dashboard  
\[icon\] Departments  
\[icon\] Users

Collapsed:

\[icon\]  
\[icon\]  
\[icon\]

If implemented, the main content should resize smoothly.

The sidebar should not disappear entirely unless the user intentionally collapses it.

On mobile, use an off-canvas drawer.

---

# **61\. ADMIN DASHBOARD MODULE NAVIGATION**

The sidebar should be generated from the actual modules identified from the schema.

Example:

DASHBOARD

MASTER DATA  
    Departments  
    Programs  
    Courses

USER MANAGEMENT  
    Users

REPORTS  
    Reports

SYSTEM  
    Profile  
    Settings

Logout

Again, this is an example.

Use the actual database.

---

# **62\. DO NOT IMPLEMENT ALL MODULES YET**

Although you should identify all modules, only implement:

LOGIN  
ADMIN DASHBOARD  
ONE CRUD MODULE  
SEARCH  
PRINT

The other modules should only appear as:

* planned modules  
* disabled menu items  
* or not appear yet

depending on what you recommend.

Do not create fake unfinished pages.

---

# **63\. CRUD MODULE SHOULD BE A TEMPLATE**

The first CRUD module should be coded in a way that I can later copy its architecture.

For example:

pages/first\_module/

should demonstrate:

index.php  
create.php  
view.php  
edit.php  
delete.php

and:

assets/css/first\_module.css  
assets/js/first\_module.js

The next CRUD module should be easy to create based on this pattern.

---

# **64\. SEARCH IMPLEMENTATION**

Prefer server-side search.

Example:

GET  
?search=keyword

or an appropriate POST implementation.

Make sure search does not create SQL injection vulnerabilities.

Preserve search parameters when moving through pagination if appropriate.

---

# **65\. CRUD SUCCESS FLOW**

After creating:

CREATE  
 ↓  
VALIDATE  
 ↓  
INSERT  
 ↓  
SUCCESS MESSAGE  
 ↓  
RETURN TO LIST

After editing:

EDIT  
 ↓  
VALIDATE  
 ↓  
UPDATE  
 ↓  
SUCCESS MESSAGE  
 ↓  
RETURN TO LIST

After deleting:

DELETE  
 ↓  
CHECK DEPENDENCIES  
 ↓  
DELETE / SOFT DELETE  
 ↓  
SUCCESS MESSAGE  
 ↓  
RETURN TO LIST

---

# **66\. PRG PATTERN**

Where appropriate, use the Post/Redirect/Get pattern.

For example:

POST create.php  
       ↓  
INSERT  
       ↓  
REDIRECT  
       ↓  
index.php

This prevents accidental duplicate form submissions when refreshing the page.

---

# **67\. VALIDATION**

Implement both:

### **Client-side validation**

Using HTML/JavaScript for immediate feedback.

### **Server-side validation**

PHP must validate everything again.

Never trust JavaScript validation alone.

---

# **68\. DATABASE VALIDATION**

Respect:

* NOT NULL  
* UNIQUE  
* foreign keys  
* enum values if applicable  
* field lengths  
* data types

Do not let the frontend submit invalid values simply because the database rejects them later.

---

# **69\. EMPTY STATES**

If there are no records:

No records available.

\[ \+ Add New \]

If there are no search results:

No matching records found.

\[ Clear Search \]

Make the UI informative.

---

# **70\. LOADING STATES**

Use appropriate loading indicators for actions that may take time.

Do not make the system appear frozen.

---

# **71\. PRINT CSS**

Create proper print styles.

For example:

@media print {

    .sidebar,  
    .header,  
    .footer,  
    .action-buttons,  
    .search-controls,  
    .no-print {  
        display: none \!important;  
    }

    .main-content {  
        margin: 0;  
        width: 100%;  
    }

}

Adjust according to the actual layout.

---

# **72\. PRINT HEADER**

The printed report should look professional.

Example:

\------------------------------------------------  
SYSTEM NAME  
FIRST MODULE REPORT

Printed: October 7, 2026  
\------------------------------------------------

TABLE DATA

\------------------------------------------------

Do not include unnecessary interface elements.

---

# **73\. RESPONSIVE TABLE**

On mobile, tables should remain usable.

Possible approaches:

* horizontal scrolling  
* responsive card layout  
* compact columns

Choose the best approach based on the module.

Do not make the table unusable on mobile.

---

# **74\. ACCESSIBILITY**

Use:

* labels for inputs  
* meaningful button text  
* appropriate contrast  
* keyboard-friendly controls  
* focus states  
* ARIA attributes where appropriate

Do not rely solely on color to communicate meaning.

---

# **75\. BROWSER COMPATIBILITY**

The system should work in modern:

* Chrome  
* Edge  
* Firefox  
* Safari

Do not use experimental browser features unnecessarily.

---

# **76\. NO FAKE DATA**

Do not insert fake production records automatically.

If testing records are necessary, clearly label them as test data and provide a separate SQL test script.

---

# **77\. DATABASE IMPORT**

If the uploaded schema contains a complete SQL dump, explain:

1. How to create/import the database.  
2. How to import the SQL file using phpMyAdmin.  
3. How to configure `database.php`.  
4. How to start Apache/MySQL.  
5. How to access the application.

For example:

http\://localhost/paswipe\_me/

But do not hardcode this inside the application.

---

# **78\. FIRST RUN**

Provide instructions like:

STEP 1  
Start Apache and MySQL.

STEP 2  
Open phpMyAdmin.

STEP 3  
Create/import the database.

STEP 4  
Configure config/database.php.

STEP 5  
Place paswipe\_me inside htdocs.

STEP 6  
Open the application.

STEP 7  
Login using an existing database account.

Use the actual schema to explain the login credentials.

Do not invent an admin account unless I request one.

---

# **79\. TESTING CHECKLIST**

After coding, provide a detailed testing checklist.

## **LOGIN**

\[ \] Login page loads  
\[ \] Correct username/email accepted  
\[ \] Correct password accepted  
\[ \] Incorrect password rejected  
\[ \] Unknown user rejected  
\[ \] Inactive account rejected  
\[ \] Session created  
\[ \] Session regenerated  
\[ \] Logout works  
\[ \] Protected pages reject unauthenticated users  
\[ \] Non-admin cannot access admin pages

## **DASHBOARD**

\[ \] Dashboard loads  
\[ \] Sidebar appears  
\[ \] Header appears  
\[ \] Footer appears  
\[ \] Sidebar remains consistent  
\[ \] Active menu works  
\[ \] Dashboard statistics are real  
\[ \] Responsive layout works

## **CRUD**

\[ \] List records  
\[ \] Add record  
\[ \] Required validation  
\[ \] Duplicate validation  
\[ \] Edit record  
\[ \] View record  
\[ \] Delete record  
\[ \] Delete confirmation  
\[ \] Foreign key protection  
\[ \] Search  
\[ \] Filters if applicable  
\[ \] Pagination if applicable

## **PRINT**

\[ \] Print button works  
\[ \] Print preview opens  
\[ \] Sidebar hidden  
\[ \] Navigation hidden  
\[ \] Buttons hidden  
\[ \] Correct data printed  
\[ \] Search result can be printed  
\[ \] Report is readable

---

# **80\. SECURITY REVIEW AFTER CODING**

After generating all files, review your own implementation.

Check:

\[ \] SQL injection protected  
\[ \] XSS protected  
\[ \] CSRF protected  
\[ \] Passwords hashed  
\[ \] Sessions protected  
\[ \] Authorization enforced  
\[ \] Unauthorized URLs blocked  
\[ \] Delete protected  
\[ \] Input validated  
\[ \] Database errors not exposed

If you find a problem, correct the code before presenting the final implementation.

---

# **81\. CODE PRESENTATION**

I want the implementation delivered **file by file**.

Do NOT give me one massive code block containing the entire application.

Use this exact style:

FILE 1  
Path:  
paswipe\_me/config/database.php

Then:

\[COMPLETE CODE\]

Then:

FILE 2  
Path:  
paswipe\_me/auth/login.php

Then:

\[COMPLETE CODE\]

Continue until all required files are provided.

---

# **82\. DO NOT SAY "REMAINING FILES ARE SIMILAR"**

This is important.

Do not say:

> Create the other files similarly.

I need the complete implementation.

If the system needs:

header.php  
sidebar.php  
footer.php  
auth\_check.php  
functions.php  
csrf.php

provide all of them.

If it needs:

auth.css  
dashboard.css  
module.css

provide all of them.

If it needs:

auth.js  
dashboard.js  
module.js

provide all of them.

---

# **83\. CODE MUST BE COPY-PASTE READY**

Every code file must be complete.

I should be able to copy:

paswipe\_me/assets/css/dashboard.css

into:

assets/css/dashboard.css

and similarly for all files.

Do not omit required imports/includes.

Do not use pseudo-code.

Do not use:

// rest of code here

or:

...

inside the implementation.

---

# **84\. COMMENTS**

Use comments where they improve maintainability.

For example:

// Verify administrator authentication

But do not fill every line with unnecessary comments.

The code should be understandable.

---

# **85\. FUTURE EXPANSION**

The architecture should make it easy for me to later say:

> "Create the next CRUD module."

You should then be able to follow the same architecture.

For example:

pages/  
├── dashboard.php  
├── departments/  
├── programs/  
├── courses/  
└── users/

and:

assets/css/  
├── dashboard.css  
├── departments.css  
├── programs.css  
├── courses.css  
└── users.css

and:

assets/js/  
├── dashboard.js  
├── departments.js  
├── programs.js  
├── courses.js  
└── users.js

---

# **86\. DO NOT OVERENGINEER**

This is a manually maintained PHP/XAMPP application.

Keep the architecture:

* organized  
* secure  
* modular  
* understandable  
* maintainable  
* scalable enough for future modules

But do not introduce unnecessary enterprise architecture.

I want to understand and maintain the code myself.

---

# **87\. FINAL REQUIRED OUTPUT ORDER**

Your response must follow this order:

## **PART 1 — DATABASE SCHEMA ANALYSIS**

Explain:

* database  
* tables  
* columns  
* keys  
* relationships  
* authentication structure

## **PART 2 — ADMIN MODULE ANALYSIS**

Show all recommended modules based on the schema.

## **PART 3 — FIRST CRUD MODULE**

Explain which module you selected and why.

## **PART 4 — SYSTEM ARCHITECTURE**

Show the folder structure.

## **PART 5 — AUTHENTICATION FLOW**

Explain login/session/authorization.

## **PART 6 — IMPLEMENTATION**

Provide every required file.

## **PART 7 — DATABASE CONFIGURATION**

Explain how to configure the database.

## **PART 8 — XAMPP SETUP**

Explain how to run the system.

## **PART 9 — TESTING**

Provide the complete test checklist.

## **PART 10 — SECURITY REVIEW**

Explain what security measures were implemented.

---

# **88\. CRITICAL RULE — DO NOT CODE BEFORE SCHEMA ANALYSIS**

Again:

**DO NOT START GENERATING THE PHP CODE UNTIL YOU HAVE READ AND ANALYZED THE DATABASE SCHEMA I UPLOAD.**

The schema determines:

* login fields  
* users  
* roles  
* permissions  
* modules  
* relationships  
* CRUD fields  
* search fields  
* status fields  
* print data

Do not guess.

---

# **89\. FINAL SYSTEM REQUIREMENT**

The final first version must be a working:

PHP \+ HTML \+ CSS \+ JAVASCRIPT \+ MYSQL

application.

The complete flow should be:

                   ┌──────────────┐  
                    │    LOGIN     │  
                    └──────┬───────┘  
                           │  
                           ▼  
                 ┌──────────────────┐  
                 │ AUTHENTICATION   │  
                 │ PHP SESSION      │  
                 └────────┬─────────┘  
                          │  
                          ▼  
                 ┌──────────────────┐  
                 │ ROLE VALIDATION  │  
                 └────────┬─────────┘  
                          │  
                          ▼  
        ┌─────────────────────────────────┐  
        │        ADMIN DASHBOARD          │  
        │                                 │  
        │ ┌─────────┐ ┌────────────────┐ │  
        │ │ SIDEBAR │ │ MAIN CONTENT    │ │  
        │ │         │ │                │ │  
        │ │Dashboard│ │ Statistics     │ │  
        │ │Module   │ │ Tables         │ │  
        │ │Reports  │ │ Data           │ │  
        │ │Settings │ │                │ │  
        │ │Logout   │ │                │ │  
        │ └─────────┘ └────────────────┘ │  
        └─────────────────────────────────┘  
                          │  
                          ▼  
                 ┌──────────────────┐  
                 │ FIRST CRUD MODULE│  
                 └────────┬─────────┘  
                          │  
             ┌────────────┼────────────┐  
             ▼            ▼            ▼  
           CREATE       UPDATE       DELETE  
             │            │            │  
             └────────────┼────────────┘  
                          ▼  
                     DATABASE  
                          │  
                          ▼  
                       SEARCH  
                          │  
                          ▼  
                        PRINT

The application must be:

**MODERN \+ RESPONSIVE \+ SECURE \+ MODULAR \+ PHP-BASED \+ XAMPP-COMPATIBLE \+ DATABASE-DRIVEN.**

Most importantly, the first CRUD module must be a **real working CRUD implementation**, not a visual demonstration.

Once this first version is working, the same architecture will be used to build the remaining modules.

