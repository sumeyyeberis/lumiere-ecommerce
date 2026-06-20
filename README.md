# Lumière Beauty — Skincare E-Commerce Platform

**Author:** Sümeyye Beriş
**Institution:** Istanbul Medipol University

A fully functional, responsive e-commerce web application designed specifically for skincare products. Built with pure PHP and MySQL, this project focuses on robust backend architecture, data integrity, and a seamless user shopping experience.

## Technology Stack
* **Backend:** PHP 8+
* **Database:** MySQL (MariaDB) via XAMPP
* **Frontend:** HTML5, Vanilla CSS3, JavaScript (Minimal for Modals/Toasts)

## Key Features & Technical Highlights

* **Advanced Database Transactions:** Order processing utilizes MySQL `BEGIN`, `COMMIT`, and `ROLLBACK` transactions to ensure database integrity, preventing partial data writes or negative stock scenarios.
* **Historical Pricing Logic:** The `order_items` table stores a `price_at_purchase` snapshot, ensuring historical receipts remain accurate even if an administrator changes a product's price in the future.
* **Secure Authentication:** User registration and login utilizing `password_hash()` and `password_verify()` (bcrypt) for maximum security.
* **Dynamic Cart System:** Session-based cart allowing users to add items, update quantities, and remove products without database overhead until checkout.
* **Admin Dashboard:** A protected CRUD (Create, Read, Update, Delete) interface for administrators to manage product inventory and update customer order statuses.
* **Responsive UI/UX:** Custom CSS Grid and Flexbox layouts with media queries ensuring perfect display on desktop, tablet, and mobile devices.

## Screenshots
 ![Lumiere Homepage](images/homepage.png)

## Installation & Setup Instructions

1. **Environment Setup:** Ensure XAMPP is installed and running (Apache & MySQL).
2. **Project Files:** Move the `ecommerce` folder into your `C:\xampp\htdocs\` directory.
3. **Database Configuration:**
   * Open `http://localhost/phpmyadmin/` in your browser.
   * Create a new database named `ecommerce_db`.
   * Click the **Import** tab and upload the `ecommerce_db.sql` file provided in this repository.
4. **Run the Application:**
   * Navigate to `http://localhost/ecommerce/index.php` in your browser.

## Admin Access
To access the Admin Dashboard, log in with the following credentials (or update your own user role to 'admin' in the database):
* **Email:** admin@lumiere.com
* **Password:** *(Set via your database registration)*


## ⚠️ Disclaimer
This is an academic/portfolio project created strictly for educational and demonstrative purposes. 
"Lumiere Beauty" is a fictional brand created for this project.
No real financial transactions occur on this platform.
All product images, brands, and trademarks used within this repository belong to their respective original owners.
No commercial use is intended, and no copyright infringement is intended.
