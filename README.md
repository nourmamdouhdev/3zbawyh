# 3zbawyh (POS)

PHP/MySQL Point-of-Sale (POS) web application for managing products, customers, and sales invoices with a simple admin workflow.

> Repo: nourmamdouhdev/3zbawyh :contentReference[oaicite:0]{index=0}

---

## Key Features
- **Authentication & Users** (login/logout, session-based access).
- **Roles & Permissions** (role-based pages and access control).
- **Products / Items** (CRUD, pricing, basic categorization).
- **Customers** (customer profile + linking to invoices).
- **Sales Invoices**
  - Create invoice, apply discount/tax, calculate totals
  - Payment method, paid amount, change due
  - Print-friendly invoice view
- **Uploads** for item images/assets (stored under uploads).

> Feature list is based on the repo’s structure and observed invoice/query patterns in the project. :contentReference[oaicite:1]{index=1}

---

## Tech Stack
- **Backend:** PHP
- **Database:** MySQL/MariaDB
- **Frontend:** HTML/CSS (and any lightweight JS where needed)

GitHub language breakdown shows the project is primarily PHP. :contentReference[oaicite:2]{index=2}

---

## Project Structure
Top-level folders in this repository: :contentReference[oaicite:3]{index=3}

- `app/config/`  
  App configuration (typically DB connection, app settings).
- `lib/`  
  Shared libraries (auth/helpers/utilities).
- `models/`  
  Data access layer / models.
- `public/`  
  Public entry pages (login/dashboard/modules).
- `assets/`  
  Static assets (CSS, images, JS).
- `uploads/items/`  
  Uploaded item images/files.

---

## Getting Started (Local Setup)

### 1) Clone
```bash
git clone https://github.com/nourmamdouhdev/3zbawyh.git
cd 3zbawyh
