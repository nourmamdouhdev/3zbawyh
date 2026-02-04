# 3zbawyh POS System (PHP)

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-ES6-F7DF1E?logo=javascript&logoColor=000)
![HTML5](https://img.shields.io/badge/HTML5-E34F26?logo=html5&logoColor=white)
![CSS3](https://img.shields.io/badge/CSS3-1572B6?logo=css3&logoColor=white)
![Status](https://img.shields.io/badge/Status-Portfolio%20Project-2EA44F)

A full-featured Point of Sale (POS) web application built with PHP + MySQL. This project covers the entire sales flow from item selection to invoice printing, plus catalog management, customer accounts, reporting, barcode tools, and database backups.

The UI is Arabic-first (RTL), with role-based access and a workflow optimized for fast in-store selling.

---

**Languages & Tech**
- Backend: PHP (PDO)
- Database: MySQL
- Frontend: HTML, CSS, vanilla JavaScript
- PWA: Web App Manifest

---

**Key Features**
- POS workflow with cart, discounts, taxes, and stock checks
- Normal vs wholesale pricing (`unit_price` / `price_wholesale`)
- Multi-payment support (cash, visa, instapay, vodafone_cash)
- Mixed payment split with change calculation
- Credit sales (agel) with due date and notes
- Invoice management with full detail view and thermal printing
- Items, categories, subcategories, sub-subcategories CRUD
- Barcode/SKU generator and 40x20 label printer
- Customer profiles, credit limits, collectors, WhatsApp quick link
- Collections and debt tracking with audit log
- Sales reports with CSV export
- Database backup and restore UI
- Responsive UI for mobile

---

**POS Workflow (Quick Path)**
1. Login (`public/login.php`)
2. Select sale type (`public/order_type.php`)
3. Customer data (`public/customer_name.php`)
4. Choose categories and items (`public/select_items.php`)
5. Checkout & payments (`public/cart_checkout.php`)
6. Print invoice (`public/invoice_print.php`)

---

**POS API (JSON)**
`public/pos_api.php` powers live POS actions:
- `search_items`, `search_categories`, `search_subcategories`, `search_sub_subcategories`
- `cart_add`, `cart_update`, `cart_get`, `cart_clear`
- `save_invoice`, `save_invoice_multi_legacy`, `cart_checkout_multi_legacy`

---

**Core Modules**
- Auth & roles: `lib/auth.php`
- Helpers: `lib/helpers.php`
- Sales logic: `models/Sales.php`
- Items logic: `models/Items.php`
- Category management: `public/Categories.php`
- Items management: `public/items_manage.php`
- POS flow: `public/order_type.php`, `public/select_*`, `public/cart_checkout.php`
- Reports: `public/reports.php`, `public/invoices_details.php`
- Customers & collections: `public/clients.php`, `public/collector_sheet.php`
- Backup & restore: `public/backup.php`

---

**Database Model (Core Tables)**
- `users`, `roles`
- `items`, `categories`, `subcategories`, `sub_subcategories`
- `sales_invoices`, `sales_items`
- `customers`
- `client_payments`, `client_settings`, `audit_log`

---

**Project Structure**

| Path | Description |
| --- | --- |
| `app/config/db.php` | Database connection (PDO) |
| `lib/` | Auth and shared helpers |
| `models/` | Business logic layer |
| `public/` | Web pages, POS flow, APIs |
| `assets/` | CSS, icons, images |
| `uploads/` | Uploaded item images + backups |

---

**Local Setup (Optional)**
1. Create a MySQL database and update `app/config/db.php`.
2. Import a SQL backup if available in `uploads/backups/`.
3. Serve with Apache/XAMPP and open `public/login.php`.

---

**Security Notes**
- User passwords are stored in plain text (`lib/auth.php`).
- Intended for internal use or portfolio review. Do not deploy publicly without hardening.
- Some pages dynamically detect schema differences to stay compatible with varying DB structures.

---

**Project Status**
Portfolio / showcase project.

---

**License**
All Rights Reserved. For viewing and evaluation only.

---

**Author**
Nour Mamdouh
PHP Backend Developer
