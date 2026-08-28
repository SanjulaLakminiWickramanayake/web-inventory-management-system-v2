-- BRANCHES TABLE
CREATE TABLE branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(200) NOT NULL,
    type ENUM('main', 'outlet') NOT NULL DEFAULT 'outlet',
    contact_no VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- USERS TABLE
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'manager', 'cashier') NOT NULL DEFAULT 'cashier',
    branch_id INT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
);

-- CATEGORIES TABLE
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- PRODUCTS TABLE - image column එක add කරා
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    sku VARCHAR(50) UNIQUE,
    unit ENUM('kg','pcs','pack','tray') NOT NULL DEFAULT 'kg',
    cost_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    selling_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    reorder_level DECIMAL(10,2) NOT NULL DEFAULT 10.00,
    image VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

-- PRODUCTION TABLE
CREATE TABLE production (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    branch_id INT NOT NULL,
    quantity_produced DECIMAL(10,2) NOT NULL,
    production_date DATE NOT NULL,
    cost_per_unit DECIMAL(10,2),
    status ENUM('Pending','Completed','Rejected') DEFAULT 'Completed',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- STOCK TABLE
CREATE TABLE stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    branch_id INT NOT NULL,
    total_quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    reorder_level DECIMAL(10,2) NOT NULL DEFAULT 10.00,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    UNIQUE KEY unique_stock (product_id, branch_id)
);

-- STOCK BATCHES TABLE
CREATE TABLE stock_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    branch_id INT NOT NULL,
    production_id INT NULL,
    batch_no VARCHAR(50) NOT NULL,
    mfd_date DATE NOT NULL,
    exp_date DATE NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    remaining_quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    cost_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (production_id) REFERENCES production(id) ON DELETE SET NULL
);

-- SALES TABLE
CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    invoice_no VARCHAR(50) UNIQUE NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- SALE ITEMS TABLE
CREATE TABLE sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    batch_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    selling_price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (batch_id) REFERENCES stock_batches(id) ON DELETE CASCADE
);

-- TRANSFERS TABLE
CREATE TABLE transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_branch_id INT NOT NULL,
    to_branch_id INT NOT NULL,
    transfer_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'pending',
    created_by INT,
    approved_by INT,
    FOREIGN KEY (from_branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (to_branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- TRANSFER ITEMS TABLE
CREATE TABLE transfer_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transfer_id INT NOT NULL,
    product_id INT NOT NULL,
    batch_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (transfer_id) REFERENCES transfers(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (batch_id) REFERENCES stock_batches(id) ON DELETE CASCADE
);

-- REORDER REQUESTS TABLE
CREATE TABLE reorder_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    current_stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    requested_quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'pending',
    request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    requested_by INT,
    approved_by INT,
    approved_date DATETIME NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ALERTS TABLE
CREATE TABLE alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    product_id INT NULL,
    batch_id INT NULL,
    alert_type ENUM('low_stock', 'expiry', 'reorder') NOT NULL DEFAULT 'low_stock',
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (batch_id) REFERENCES stock_batches(id) ON DELETE SET NULL
);

-- DATA INSERTS

-- 1. BRANCHES TABLE DATA
INSERT INTO branches (id, name, location, type, contact_no) VALUES
(1, 'Main Branch - L.G.Farm Hingurakgoda. Shop', 'No 15 ,16, 4th Cross Street, Hingurakgoda.', 'main', '0272246328'),
(2, 'Outlet 01 - L.G.Farm Jayanthipura Shop', 'Jayanthipura 22 Town', 'outlet', '0272056090'),
(3, 'Outlet 02 - L.G.Farm Minneriya Shop', 'Katukaliyawa Road,C.P.pura, Minneriya', 'outlet', '0272057083'),
(4, 'Outlet 03 - L.G.Farm Medirigiriya Shop', 'Main Street, Medirigiriya', 'outlet', '0272057080'),
(5, 'Outlet 04 - L.G.Farm Kaduruwela Shop', 'Saw Mill Junction, Kaduruwela', 'outlet', '0272057082'),
(6, 'Outlet 05 - L.G.Farm Kanthale Shop', 'No 200, Main Street, Kanthale', 'outlet', '0262057085'),
(7, 'Outlet 06 - L.G.Farm Polonnaruwa Shop', 'Hospital Junction, Polonnaruwa', 'outlet', '0272057081'),
(8, 'Outlet 07 - L.G.Farm Polonnaruwa Newtown Shop', 'Opp. Royal Collage, New Town, Polonnaruwa Newtown', 'outlet', '0272057084'),
(9, 'Outlet 08 - L.G.Farm Habarana Shop', 'No 57, Main Street, Habarana', 'outlet', '0662058488'),
(10, 'Outlet 09 - L.G. Farm Kekirawa Shop', 'No 158, Main street, Kekirawa', 'outlet', '027 2056319'),
(11, 'Outlet 10 - L.G Farm Aralaganvila Shop', 'No 14/60, Aralaganvila, Kekirawa', 'outlet', '027 2056318'),
(12, 'Outlet 11 - L.G.Farm Dambulla Shop', 'No 609, Anuradhapura Road (Pending) Connection Dambulla', 'outlet', '0252263065');

-- 2. USERS TABLE DATA
INSERT INTO users (id, branch_id, username, password, full_name, role) VALUES
(1, 1, 'admin', '$2y$10$u3RYWYII1iHEwcDx5B5ECe7i195wdmgRkwbBC6bRLqX2vltUiT6gG', 'Farm Admin', 'admin'),
(2, 1, 'manager_main', '$2y$10$drzr2ohxjyqnJ9exrRcT5eIA5DaeG0jzXAr1lc.aW28Wg9.9XSKT6', 'Nimal Perera', 'manager'),
(3, 2, 'cashier_jayanthipura_1', '$2y$10$6ySLHq4w/7iQjLcOJD67dOKSk7iN/Ad1kx7GB0ue.ausiWkfCF51G', 'Sunil Silva', 'cashier'),
(4, 2, 'cashier_jayanthipura_2', '$2y$10$XdGohzMiXGCDLmulas3Tju7G6r0onF7kzDVfz0GXjJlAwUuLwx37a', 'Ruwan Jayasuriya', 'cashier'),
(5, 3, 'cashier_minneriya_1', '$2y$10$YU2vPEUyqRxBSxtW7Hebk.buS9CTMJQtDI/EDHPPoTDygPYsoOx72', 'Kumari Fernando', 'cashier'),
(6, 3, 'cashier_minneriya_2', '$2y$10$NC0Y.0GBVb50x9mB/zOTAOnvXjRrTThiDSya31HFW4oJDB/1XqIqi', 'Saman Kumara', 'cashier'),
(7, 4, 'cashier_medirigiriya_1', '$2y$10$N.s5j/WQvTl2OxR2yZy0KeGAIPq.z3B32yyRzTsi3v25WVqayau5W', 'Nadeesha Perera', 'cashier'),
(8, 4, 'cashier_medirigiriya_2', '$2y$10$wf7/.AiJqsXFsgo86LJWK.rJONxSi324wDmLrKbwymMc4eL1kBUYC', 'Chaminda Silva', 'cashier'),
(9, 5, 'cashier_kaduruwela_1', '$2y$10$tuw7OkDF81yh.p0o/ZnLd.1wB90MSEP3LD5/CrSVKAo92fCeeZiGC', 'Dilani Jayawardena', 'cashier'),
(10, 5, 'cashier_kaduruwela_2', '$2y$10$j5z0mxhCxh7BbUU2jliMfOFkJNLb0tw4Em/lkUbdehIoxalXEm17O', 'Lakmal Rathnayake', 'cashier'),
(11, 6, 'cashier_kanthale_1', '$2y$10$OX7qAdHNR7Zi5vruc9GbwO.C7fsKjvn4r3KO3x5H35AZdUmtS8fEq', 'Ishara Dissanayake', 'cashier'),
(12, 6, 'cashier_kanthale_2', '$2y$10$TfVVX5X1cY3Rf5vsrJKei.vUD6XoQKzT7vUPg1hGcl9LQ/5N7RNCfK', 'Pradeep Gunawardena', 'cashier'),
(13, 7, 'cashier_polonnaruwa_1', '$2y$10$tFcYesH7Olyz/UnOVlCNb.feKgbFiJG/QNFoDd6kiBBmQdrudiGeC', 'Sanduni Weerasinghe', 'cashier'),
(14, 7, 'cashier_polonnaruwa_2', '$2y$10$Jav.i6/IjyAEXcvxdQ2Q3.pa3NppcQKm.PMMCBIBfBevwUY.cu3ga', 'Asanka Bandara', 'cashier'),
(15, 8, 'cashier_newtown_1', '$2y$10$8qNSqhhcoXhsSY.mxkNKEOeMPyzuslDXBkiD8tXnzsBvcG', 'Hiruni Senanayake', 'cashier'),
(16, 8, 'cashier_newtown_2', '$2y$10$bwtrf23JNAikG/MjN4n0SOASV.5PdtsdZlkD8hi40./Gcl9tDf.TK', 'Tharindu Rajapaksha', 'cashier'),
(17, 9, 'cashier_habarana_1', '$2y$10$D.9zy9xfeyHM6uLe58nGMOZ94TtCFTnu5GuvFfFukDIKdsby9c8zi', 'Gayani Wickramasinghe', 'cashier'),
(18, 9, 'cashier_habarana_2', '$2y$10$e21SPfruU9BSa3dDJiEBIOdVz7dBbZfELdBT61A4wxGiGj/5rUAtO', 'Kasun Mendis', 'cashier'),
(19, 10, 'cashier_kekirawa_1', '$2y$10$ZqFYnCZTov1BqpYh9EWTMubgjpVWj220p973cK/6hCl7WnKackSOm', 'Nimali Abeysinghe', 'cashier'),
(20, 10, 'cashier_kekirawa_2', '$2y$10$g7ngwWb1tFjNRqSDC2FluOg6ba/UXn41VZX/2D8xm2CxZisgy', 'Dinesh Karunaratne', 'cashier'),
(21, 11, 'cashier_aralaganvila_1', '$2y$10$DEvqOIcRoz2EdUYlaxXAROzEpxkM/SynWIaoX7v9WYwYOX82iGG', 'Chathurika Jayawardena', 'cashier'),
(22, 11, 'cashier_aralaganvila_2', '$2y$10$VjL.ZQNY35kgSnLiFdNt..BFlnXoQKz.43ojGRfIuWILJGVHPognW', 'Sampath Ekanayake', 'cashier'),
(23, 12, 'cashier_dambulla_1', '$2y$10$8QZJ7e.//llvqOSuZXKqw.G.2XHKG5tneEq2p6BhuKJrv2xiuxe7q', 'Rashmika Herath', 'cashier'),
(24, 12, 'cashier_dambulla_2', '$2y$10$6QTuAK6G8sY.2FXDznIJ8OgBHTy3nX3TUBIR8XNt/pEbwE8Odyd9i', 'Janaka Perera', 'cashier');

-- 3. CATEGORIES TABLE DATA
INSERT INTO categories (id, name) VALUES
(1, 'Skin on Drumstick'),(2, 'Broiler Chicken Pieces'),(3, 'Chicken Breast Skin on'),
(4, 'Chicken Breast Skinless'),(5, 'Chefs Choice Catering Pack'),(6, 'Chicken Gizzard'),
(7, 'Mix Pack'),(8, 'Quarter Chicken'),(9, 'Skinless Chicken'),(10, 'Skinless Thigh'),
(11, 'Whole Wings'),(12, 'Whole Chicken'),(13, 'Chicken Leg Quarters'),
(15, 'Chicken Sausages'),(16, 'Eggs Red'),(17, 'Big Eggs White'),(18, 'Small Eggs White');

-- 4. PRODUCTS TABLE DATA - drumstick.jpg එක add කරා ID 1 එකට
INSERT INTO products (id, category_id, name, sku, unit, cost_price, selling_price, reorder_level, image) VALUES
(1, 1, 'Drumstick Skin On 500g Pack', 'DS-SO-500G', 'kg', 750.00, 950.00, 20.00, 'chicken-drumstick-with-skin-on-500g-pack-vacuum.jpeg'),
(2, 1, 'Drumstick Skin On 1kg Pack', 'DS-SO-1KG', 'kg', 1450.00, 1800.00, 30.00, 'chicken-drumstick-with-skin-on-1kg-pack-vacuum.jpeg'),
(3, 2, 'Broiler Curry Cut 1kg', 'BC-CURRY-1KG', 'kg', 1100.00, 1350.00, 50.00, 'broiler-chicken-curry-cut-pieces-1kg-pack-mixe.jpeg'),
(4, 2, 'Broiler Curry Cut 500g', 'BC-CURRY-500G', 'kg', 560.00, 700.00, 40.00, 'broiler-chicken-curry-cut-pieces-500g-pack-mix.jpeg'),
(5, 3, 'Breast Skin On 500g', 'BR-SO-500G', 'kg', 850.00, 1050.00, 20.00, 'Chicken Breast Skin On 500g packet.jpg'),
(6, 4, 'Breast Boneless 500g', 'BR-BL-500G', 'kg', 1050.00, 1300.00, 25.00, 'Breast Boneless 500g.jpg'),
(7, 4, 'Breast Boneless 1kg', 'BR-BL-1KG', 'kg', 2050.00, 2500.00, 20.00, 'Breast Boneless 1kg.jpg'),
(8, 5, 'Chefs Choice 5kg Pack', 'CC-5KG', 'pack', 5200.00, 6000.00, 10.00, 'Chefs Choice 5kg Pack.jpg'),
(9, 6, 'Gizzard 250g Pack', 'GIZZ-250G', 'kg', 300.00, 400.00, 15.00, 'Gizzard 250g Pack.jpg'),
(10, 7, 'Mix Pack 1kg', 'MIX-1KG', 'kg', 1000.00, 1250.00, 30.00, 'Mix Pack 1kg.jpg'),
(11, 8, 'Quarter Chicken Pack', 'QC-PACK', 'pcs', 350.00, 450.00, 40.00, 'Quarter Chicken Pack.jpg'),
(12, 9, 'Skinless Full Chicken 1.2kg', 'SC-FULL-1.2', 'kg', 1300.00, 1600.00, 25.00, 'Skinless Full Chicken 1.2kg.jpg'),
(13, 10, 'Thigh Boneless 500g', 'TH-BL-500G', 'kg', 950.00, 1200.00, 20.00, 'Thigh Boneless 500g.jpg'),
(14, 11, 'Whole Wings 500g', 'WW-500G', 'kg', 700.00, 900.00, 25.00, 'Whole Wings 500g.jpg'),
(15, 12, 'Whole Chicken Skin On 1.5kg', 'WC-SO-1.5', 'kg', 1600.00, 1950.00, 30.00, 'Whole Chicken Skin On 1.5kg.jpg'),
(16, 13, 'Leg Quarter 2pcs Pack', 'LQ-2PCS', 'pack', 650.00, 800.00, 30.00, 'Leg Quarter 2pcs Pack.jpg'),
(17, 15, 'Chicken Sausages 500g', 'SAUS-500G', 'pack', 850.00, 1100.00, 20.00, 'Chicken Sausages 500g.jpg'),
(18, 16, 'Red Eggs 10pcs Tray', 'EGG-RED-10', 'tray', 420.00, 500.00, 50.00, 'Red Eggs 10pcs Tray.jpg'),
(19, 17, 'White Eggs Large 10pcs', 'EGG-WL-10', 'tray', 400.00, 480.00, 50.00, 'White Eggs Large 10pcs.jpg'),
(20, 18, 'White Eggs Small 10pcs', 'EGG-WS-10', 'tray', 350.00, 420.00, 40.00, 'White Eggs Small 10pcs.jpg');

-- 5. PRODUCTION TABLE DATA
INSERT INTO production (id, branch_id, product_id, quantity_produced, production_date, cost_per_unit, created_by) VALUES
(1, 1, 1, 100.00, '2026-05-01', 750.00, 2),
(2, 1, 3, 150.00, '2026-05-01', 1100.00, 2),
(3, 1, 6, 80.00, '2026-05-02', 1050.00, 2),
(4, 1, 15, 50.00, '2026-05-02', 1600.00, 2);

-- 6. STOCK BATCHES TABLE DATA
INSERT INTO stock_batches (id, branch_id, product_id, production_id, batch_no, mfd_date, exp_date, quantity, remaining_quantity, cost_price) VALUES
(1, 1, 1, 1, 'DS-20260501-01', '2026-05-01', '2026-05-08', 100.00, 70.00, 750.00),
(2, 1, 3, 2, 'BC-20260501-01', '2026-05-01', '2026-05-08', 150.00, 100.00, 1100.00),
(3, 1, 6, 3, 'BR-20260502-01', '2026-05-02', '2026-05-09', 80.00, 60.00, 1050.00),
(4, 1, 15, 4, 'WC-20260502-01', '2026-05-02', '2026-05-07', 50.00, 50.00, 1600.00),
(5, 2, 1, 1, 'DS-20260501-01', '2026-05-01', '2026-05-08', 30.00, 28.00, 750.00),
(6, 2, 3, 2, 'BC-20260501-01', '2026-05-01', '2026-05-08', 50.00, 49.00, 1100.00),
(7, 2, 6, 3, 'BR-20260502-01', '2026-05-02', '2026-05-09', 20.00, 19.50, 1050.00);

-- 7. STOCK TABLE DATA
INSERT INTO stock (branch_id, product_id, total_quantity) VALUES
(1, 1, 70.00), (1, 3, 100.00), (1, 6, 60.00), (1, 15, 50.00),
(2, 1, 28.00), (2, 3, 49.00), (2, 6, 19.50);

-- 8. TRANSFERS TABLE DATA
INSERT INTO transfers (id, from_branch_id, to_branch_id, transfer_date, status, created_by) VALUES
(1, 1, 2, '2026-05-03 09:00:00', 'completed', 2);

-- 9. TRANSFER ITEMS TABLE DATA
INSERT INTO transfer_items (transfer_id, product_id, batch_id, quantity) VALUES
(1, 1, 1, 30.00), (1, 3, 2, 50.00), (1, 6, 3, 20.00);

-- 10. SALES TABLE DATA
INSERT INTO sales (id, branch_id, invoice_no, total_amount, sale_date, created_by) VALUES
(1, 2, 'INV-Jay-0001', 3900.00, '2026-05-03 10:15:00', 3),
(2, 5, 'INV-Kad-0001', 2500.00, '2026-05-03 11:30:00', 9);

-- 11. SALE ITEMS TABLE DATA
INSERT INTO sale_items (sale_id, product_id, batch_id, quantity, selling_price, subtotal) VALUES
(1, 1, 5, 2.00, 950.00, 1900.00),
(1, 3, 6, 1.00, 1350.00, 1350.00),
(1, 6, 7, 0.50, 1300.00, 650.00);

-- 12. REORDER REQUESTS TABLE DATA
INSERT INTO reorder_requests (branch_id, product_id, current_stock, requested_quantity, status, request_date, requested_by) VALUES
(2, 1, 28.00, 50.00, 'pending', '2026-05-03 14:00:00', 3),
(5, 15, 0.00, 20.00, 'pending', '2026-05-03 14:10:00', 9);

-- 13. ALERTS TABLE DATA
INSERT INTO alerts (branch_id, product_id, batch_id, alert_type, message, is_read, created_at) VALUES
(2, 6, NULL, 'low_stock', 'Breast Boneless in Jayanthipura Outlet is below 25kg. Current: 19.5kg', 0, '2026-05-03 14:05:00'),
(2, 1, 5, 'expiry', 'Batch DS-SO-500G in Jayanthipura expires on 2026-05-08. Qty: 28kg', 0, '2026-05-03 14:05:00'),
(1, 1, NULL, 'reorder', 'Jayanthipura Outlet requested 50kg of Drumstick Skin On', 0, '2026-05-03 14:01:00');