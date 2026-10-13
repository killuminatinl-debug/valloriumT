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
- Basic troop training queue and stored unit counts; scouting marches with arrival reports.
- Tribe founding, player-to-player messages, inbox display, and reports.
- Installer schema for villages, buildings, queues, units, movements, reports, messages and tribes; it also upgrades the earlier village schema.

## Important status
This is the first playable foundation, **not yet a complete Tribal Wars replacement**. This remains a prototype rather than a complete game. Real attacks and combat resolution, returns/retreats beyond the simplified scouting loop, noble conquest, joining/inviting tribe members, tribe diplomacy, unread/read states, full world-map villages and interactions, multiple village management UI, ranking, quests, premium features, and extensive automated gameplay/security tests still need implementation. The current scout travel only demonstrates travel timing and a report; it does not simulate enemy villages or combat. It uses original placeholder artwork/CSS, not proprietary Tribal Wars assets. Do not expose it to the public internet before a security review.

## Requirements
PHP 7.4+, PDO MySQL extension, MySQL 5.7+/MariaDB, Apache with PHP enabled. No Node.js, WebSockets, or scheduled cron task is required for this foundation.
