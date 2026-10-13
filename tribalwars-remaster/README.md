# Tribal Wars Remaster — PHP 7.4 / MySQL foundation

This is a **separate, isolated development folder** added on branch `tribalwars-remaster-foundation`. It does not overwrite the existing Vallorium files.

## Local installation (XAMPP)
1. Copy the `tribalwars-remaster` folder to `C:\\xampp\\htdocs\\tribalwars-remaster\\`.
2. Start Apache and MySQL in XAMPP.
3. Open `http://localhost/tribalwars-remaster/install.php`.
4. Enter the MySQL server credentials. The installer creates the database and tables and writes `config.local.php`.
5. Open `http://localhost/tribalwars-remaster/` and register an account.

## Included in this foundation
- PHP 7.4-compatible PHP and PDO/MySQL.
- Registration/login with password hashing and session IDs regenerated on login.
- A responsive medieval village overview, resource bar, buildings, build queue, map, and navigation.
- Server-side resource costs, upgrade validation, build timers, completion processing on page/API requests (no cron required), and CSRF validation.
- Database schema created by the installer.

## Important status
This is the first playable foundation, **not yet a complete Tribal Wars replacement**. Troop training, travel/arrival combat resolution, noble conquest, tribes, alliances, messages, reports, premium features, and full world-map interactions still need implementation and testing. It uses original placeholder artwork/CSS, not proprietary Tribal Wars assets. Do not expose it to the public internet before a security review.

## Requirements
PHP 7.4+, PDO MySQL extension, MySQL 5.7+/MariaDB, Apache with PHP enabled. No Node.js, WebSockets, or scheduled cron task is required for this foundation.
