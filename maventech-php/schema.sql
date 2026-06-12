-- ============================================================
-- Maventech Software - Admin Panel Database Schema
-- MySQL 5.7+ / MariaDB 10+
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------- ADMIN USERS ----------
DROP TABLE IF EXISTS admins;
CREATE TABLE admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin','admin','manager') NOT NULL DEFAULT 'admin',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- CATEGORIES ----------
DROP TABLE IF EXISTS categories;
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(160) NOT NULL UNIQUE,
    description TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- PRODUCTS ----------
DROP TABLE IF EXISTS products;
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NULL,
    name VARCHAR(200) NOT NULL,
    sku VARCHAR(80) NOT NULL UNIQUE,
    description TEXT,
    installation_guide TEXT,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    image_path VARCHAR(255) NULL,
    license_type ENUM('single_use','multi_use','subscription','lifetime') NOT NULL DEFAULT 'single_use',
    low_stock_threshold INT NOT NULL DEFAULT 10,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- CUSTOMERS ----------
DROP TABLE IF EXISTS customers;
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(180) NOT NULL UNIQUE,
    phone VARCHAR(40),
    country VARCHAR(80),
    company VARCHAR(150),
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- LICENSE KEYS ----------
DROP TABLE IF EXISTS license_keys;
CREATE TABLE license_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    license_key VARCHAR(255) NOT NULL UNIQUE,
    status ENUM('available','assigned','sold','expired') NOT NULL DEFAULT 'available',
    customer_id INT NULL,
    order_id INT NULL,
    assigned_at DATETIME NULL,
    expires_at DATETIME NULL,
    notes VARCHAR(255),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- ORDERS ----------
DROP TABLE IF EXISTS orders;
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(40) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency VARCHAR(8) NOT NULL DEFAULT 'USD',
    payment_method VARCHAR(50) DEFAULT 'card',
    payment_status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    transaction_id VARCHAR(120) NULL,
    card_statement_name VARCHAR(120) NULL,
    order_status ENUM('processing','completed','cancelled') NOT NULL DEFAULT 'processing',
    email_sent TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_payment_status (payment_status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- ORDER ITEMS ----------
DROP TABLE IF EXISTS order_items;
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    license_key_id INT NULL,
    product_name VARCHAR(200) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    line_total DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (license_key_id) REFERENCES license_keys(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- ACTIVITY LOGS ----------
DROP TABLE IF EXISTS activity_logs;
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NULL,
    admin_name VARCHAR(120),
    action VARCHAR(120) NOT NULL,
    entity VARCHAR(80),
    entity_id INT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin (admin_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Default super admin: email = admin@maventech.com, password = Admin@123
INSERT INTO admins (name, email, password_hash, role) VALUES
('Super Admin', 'admin@maventech.com', '$2y$10$wH8C2j5o3QwQyKxqB1Lz5uJj.6sQ1jLPbZb1A4kZ4ZQy2hKfY3xLi', 'super_admin');

INSERT INTO categories (name, slug, description) VALUES
('Operating Systems', 'operating-systems', 'OS license keys'),
('Office & Productivity', 'office-productivity', 'Office suites and productivity software'),
('Security', 'security', 'Antivirus and security software'),
('Design & Creativity', 'design-creativity', 'Design and creative tools');

INSERT INTO products (category_id, name, sku, description, installation_guide, price, license_type, low_stock_threshold) VALUES
(1, 'Windows 11 Pro', 'WIN11-PRO', 'Microsoft Windows 11 Pro Retail License', '1. Visit microsoft.com/software-download/windows11\n2. Download the Media Creation Tool\n3. Run setup and enter the license key when prompted\n4. Complete installation and activate', 99.99, 'lifetime', 5),
(2, 'Office 2021 Pro Plus', 'OFFICE21-PP', 'Microsoft Office 2021 Professional Plus', '1. Download from setup.office.com\n2. Sign in and enter your license key\n3. Install the suite and activate', 149.00, 'lifetime', 5),
(3, 'Maventech AV Suite', 'MAV-AV-2026', 'Maventech Antivirus + Firewall Suite (1 year)', '1. Download installer from maventech.com/av\n2. Run installer\n3. Activate using the provided license key', 39.99, 'subscription', 10),
(4, 'PixelStudio Pro', 'PIX-STD-PRO', 'Professional design and photo editing software', '1. Download from pixelstudio.com/download\n2. Install and launch\n3. Enter license key in Help > Activate', 79.00, 'lifetime', 8);

INSERT INTO customers (name, email, phone, country, company) VALUES
('John Smith', 'john.smith@example.com', '+1-555-0101', 'USA', 'Acme Corp'),
('Priya Sharma', 'priya@example.in', '+91-9876543210', 'India', 'TechWave'),
('Lukas Müller', 'lukas@example.de', '+49-30-1234567', 'Germany', 'Bauer GmbH');

INSERT INTO license_keys (product_id, license_key, status) VALUES
(1, 'WIN11-XXXX1-AAAA1-BBBB1-CCCC1', 'available'),
(1, 'WIN11-XXXX2-AAAA2-BBBB2-CCCC2', 'available'),
(1, 'WIN11-XXXX3-AAAA3-BBBB3-CCCC3', 'available'),
(2, 'OFC21-YYYY1-DDDD1-EEEE1-FFFF1', 'available'),
(2, 'OFC21-YYYY2-DDDD2-EEEE2-FFFF2', 'available'),
(3, 'MAV-AV-ZZZ1-1111-2222-3333', 'available'),
(3, 'MAV-AV-ZZZ2-4444-5555-6666', 'available'),
(4, 'PIX-PRO-AAA1-XX11-YY22-ZZ33', 'available');
