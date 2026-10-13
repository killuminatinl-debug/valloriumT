<?php
declare(strict_types=1);
session_start();

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim((string)($_POST['host'] ?? '127.0.0.1'));
    $port = (int)($_POST['port'] ?? 3306);
    $database = trim((string)($_POST['database'] ?? 'tribalwars_remaster'));
    $username = trim((string)($_POST['username'] ?? 'root'));
    $password = (string)($_POST['password'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $database) || $port < 1 || $port > 65535) {
        $error = 'Ongeldige databasenaam of poort.';
    } else {
        try {
            $pdo = new PDO('mysql:host='.$host.';port='.$port.';charset=utf8mb4', $username, $password, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ));
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('USE `'.$database.'`');
            $sql = array(
                "CREATE TABLE IF NOT EXISTS users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(32) NOT NULL UNIQUE, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS villages (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, name VARCHAR(80) NOT NULL DEFAULT 'Thuisdorp', x INT NOT NULL DEFAULT 500, y INT NOT NULL DEFAULT 500, wood INT NOT NULL DEFAULT 500, clay INT NOT NULL DEFAULT 500, iron INT NOT NULL DEFAULT 500, crop INT NOT NULL DEFAULT 500, storage_level INT NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(user_id), UNIQUE KEY one_home_per_user(user_id), CONSTRAINT fk_village_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS buildings (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, village_id INT UNSIGNED NOT NULL, building_key VARCHAR(32) NOT NULL, level INT NOT NULL DEFAULT 0, UNIQUE KEY village_building(village_id,building_key), CONSTRAINT fk_building_village FOREIGN KEY(village_id) REFERENCES villages(id) ON DELETE CASCADE) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS build_queue (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, village_id INT UNSIGNED NOT NULL, building_key VARCHAR(32) NOT NULL, target_level INT NOT NULL, wood_cost INT NOT NULL, clay_cost INT NOT NULL, iron_cost INT NOT NULL, crop_cost INT NOT NULL, started_at INT NOT NULL, completes_at INT NOT NULL, INDEX(village_id, completes_at), CONSTRAINT fk_queue_village FOREIGN KEY(village_id) REFERENCES villages(id) ON DELETE CASCADE) ENGINE=InnoDB"
            );
            foreach ($sql as $statement) { $pdo->exec($statement); }
            $config = "<?php\nreturn ".var_export(array('host'=>$host,'port'=>$port,'database'=>$database,'username'=>$username,'password'=>$password), true).";\n";
            $target = __DIR__.'/config.local.php';
            if (file_put_contents($target, $config, LOCK_EX) === false) {
                throw new RuntimeException('Kon config.local.php niet schrijven. Geef de map schrijfrechten en probeer opnieuw.');
            }
            $success = 'Installatie geslaagd. Open de game via de startpagina. Verwijder install.php na installatie.';
        } catch (Throwable $e) {
            $error = 'Installatie mislukt: '.$e->getMessage();
        }
    }
}
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tribal Wars Remaster — installatie</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#151b20;color:#e9dfc8;font:16px Arial,sans-serif;display:grid;place-items:center;min-height:100vh;padding:20px}.panel{width:min(620px,100%);background:#252c31;border:1px solid #927849;border-radius:10px;padding:28px;box-shadow:0 20px 60px #0008}h1{margin-top:0;color:#e8c980}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}label{display:block;font-size:13px;color:#c5b99f}input{width:100%;margin-top:6px;padding:11px;background:#151a1e;color:#fff;border:1px solid #5d625e;border-radius:5px}button{margin-top:18px;padding:12px 18px;background:#a77832;color:#fff;border:0;border-radius:5px;font-weight:bold;cursor:pointer}.note{color:#c9c0ae;font-size:13px;line-height:1.5}.msg{padding:12px;background:#25462e;border-radius:5px}.err{padding:12px;background:#602c28;border-radius:5px}@media(max-width:500px){.grid{grid-template-columns:1fr}}
</style></head><body><main class="panel"><h1>⚒ Tribal Wars Remaster</h1><p class="note">Database-installatie voor XAMPP of een PHP-host. Gebruik bij XAMPP meestal host 127.0.0.1, poort 3306 en gebruiker root.</p>
<?php if ($error !== ''): ?><p class="err"><?=h($error)?></p><?php endif; ?>
<?php if ($success !== ''): ?><p class="msg"><?=h($success)?></p><?php endif; ?>
<form method="post"><div class="grid">
<label>MySQL-host<input name="host" value="<?=h($_POST['host'] ?? '127.0.0.1')?>" required></label>
<label>Poort<input name="port" type="number" value="<?=h($_POST['port'] ?? '3306')?>" required></label>
<label>Databasenaam<input name="database" value="<?=h($_POST['database'] ?? 'tribalwars_remaster')?>" required></label>
<label>Gebruikersnaam<input name="username" value="<?=h($_POST['username'] ?? 'root')?>" required></label>
<label style="grid-column:1/-1">MySQL-wachtwoord<input name="password" type="password" value=""></label>
</div><button type="submit">Database installeren</button></form>
<p class="note">De installer schrijft database-instellingen naar config.local.php. Bescherm dit bestand en verwijder install.php zodra de installatie klaar is.</p></main></body></html>
