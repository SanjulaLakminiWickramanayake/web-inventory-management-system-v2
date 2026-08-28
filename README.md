# Chicken Farm Inventory

Simple inventory and sales management system for a chicken farm and outlets.

## Overview

- PHP + MySQL application intended to run on XAMPP / LAMP.
- Tracks branches, products, stock batches, production, sales, transfers, alerts, and users.

## Requirements

- PHP 7.4+ with mysqli
- MySQL / MariaDB
- Apache (XAMPP recommended for local development)

## Setup

1. Import the database schema and sample data:

```sql
mysql -u root -p < db.sql
```

2. Configure database credentials in `config.php` (already present in the repo):

- DB_HOST: localhost
- DB_USER: root
- DB_PASS: (empty by default in this repo)
- DB_NAME: chicken_farm_inventory

3. Place the project folder inside your web server document root and open the application in your browser.

## Default Accounts (usernames)

The project includes seeded users in `db.sql`. Passwords are stored as bcrypt hashes and are not provided in plaintext here for security.

- admin — Farm Admin (role: admin)
- manager_main — Nimal Perera (role: manager)
- cashier_jayanthipura_1 — Sunil Silva (role: cashier)
- cashier_jayanthipura_2 — Ruwan Jayasuriya (role: cashier)
- cashier_minneriya_1 — Kumari Fernando (role: cashier)
- cashier_minneriya_2 — Saman Kumara (role: cashier)
- cashier_medirigiriya_1 — Nadeesha Perera (role: cashier)
- cashier_medirigiriya_2 — Chaminda Silva (role: cashier)
- cashier_kaduruwela_1 — Dilani Jayawardena (role: cashier)
- cashier_kaduruwela_2 — Lakmal Rathnayake (role: cashier)
- cashier_kanthale_1 — Ishara Dissanayake (role: cashier)
- cashier_kanthale_2 — Pradeep Gunawardena (role: cashier)
- cashier_polonnaruwa_1 — Sanduni Weerasinghe (role: cashier)
- cashier_polonnaruwa_2 — Asanka Bandara (role: cashier)
- cashier_newtown_1 — Hiruni Senanayake (role: cashier)
- cashier_newtown_2 — Tharindu Rajapaksha (role: cashier)
- cashier_habarana_1 — Gayani Wickramasinghe (role: cashier)
- cashier_habarana_2 — Kasun Mendis (role: cashier)
- cashier_kekirawa_1 — Nimali Abeysinghe (role: cashier)
- cashier_kekirawa_2 — Dinesh Karunaratne (role: cashier)
- cashier_aralaganvila_1 — Chathurika Jayawardena (role: cashier)
- cashier_aralaganvila_2 — Sampath Ekanayake (role: cashier)
- cashier_dambulla_1 — Rashmika Herath (role: cashier)
- cashier_dambulla_2 — Janaka Perera (role: cashier)

> Note: Passwords are hashed in `db.sql` (bcrypt). I will not display plaintext passwords.

## Resetting a user's password

To set a new password for a user, generate a bcrypt hash and update the database. Example using PHP CLI:

```bash
php -r "echo password_hash('NewSecurePassword123!', PASSWORD_DEFAULT) . PHP_EOL;"

# Then in MySQL:
UPDATE users SET password = '<paste_hash_here>' WHERE username = 'admin';
```

There is also a helper script `generate_hashes.php` in the repo that can output password hashes.

## Security recommendations

- Do not commit real production credentials to the repository. Move sensitive settings to environment variables or a non-committed config file.
- Use HTTPS in production.
- Force-rotate default accounts and use strong passwords.

## Files of interest

- `config.php` — database connection and helpers
- `db.sql` — schema and seeded data (contains hashed passwords)
- `generate_hashes.php` — helper for producing password hashes

## Support

If you want, I can:

- add instructions to create an admin user interactively,
- or redact/replace password hashes with placeholders and show how to set real passwords.

## Default Admin Credentials (provided)

Per your note, the project default admin account is:

- Username: admin
- Password: 123456

Important: this password is weak. Change it immediately after first login using the instructions in "Resetting a user's password" above.

## Additional Provided Credentials

Per your latest input, the following accounts and passwords were provided:

- Manager
	- Username: manager_main
	- Password: manager123

- Jayanthipura Branch Cashier 1
	- Username: cachier_jayanthipura_1
	- Password: Jay123

- Jayanthipura Branch Cashier 2
	- Username: cachier_jayanthipura_2
	- Password: Jay456

Important: These are plaintext credentials you provided. They are weak or common—change them immediately after first login. To apply them to the database, generate bcrypt hashes and update the `users` table (see "Resetting a user's password").
