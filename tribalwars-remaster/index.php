<?php
declare(strict_types=1);
session_start();
$configFile = __DIR__.'/config.local.php';
if (!is_file($configFile)) { header('Location: install.php'); exit; }
$config = require $configFile;
try {
    $pdo = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
} catch (Throwable $e) { http_response_code(500); exit('Databaseverbinding mislukt. Controleer config.local.php en de MySQL-service.'); }
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function json_out(array $data, int $status=200): void { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data); exit; }
$catalog = array(
 'main'=>array('name'=>'Hoofdgebouw','icon'=>'🏰','base'=>100,'desc'=>'Versnelt de bouw van je dorp.'),
 'wood'=>array('name'=>'Houthakker','icon'=>'🪓','base'=>80,'desc'=>'Levert hout voor je rijk.'),
 'clay'=>array('name'=>'Leemgroeve','icon'=>'🧱','base'=>80,'desc'=>'Levert leem voor gebouwen.'),
 'iron'=>array('name'=>'IJzermijn','icon'=>'⛏️','base'=>80,'desc'=>'Levert ijzer voor troepen en gebouwen.'),
 'farm'=>array('name'=>'Boerderij','icon'=>'🌾','base'=>120,'desc'=>'Verhoogt de voedselcapaciteit.'),
 'storage'=>array('name'=>'Pakhuis','icon'=>'📦','base'=>100,'desc'=>'Verhoogt de opslagcapaciteit.'),
 'barracks'=>array('name'=>'Kazerne','icon'=>'🛡️','base'=>150,'desc'=>'Trainingsgebouw voor infanterie.')
);
function finish_queue(PDO $pdo, int $villageId): void {
    $now = time();
    $q = $pdo->prepare('SELECT * FROM build_queue WHERE village_id=? AND completes_at<=? ORDER BY completes_at ASC');
    $q->execute(array($villageId,$now));
    foreach ($q->fetchAll() as $item) {
        $up = $pdo->prepare('INSERT INTO buildings(village_id,building_key,level) VALUES(?,?,?) ON DUPLICATE KEY UPDATE level=GREATEST(level,VALUES(level))');
        $up->execute(array($villageId,$item['building_key'],$item['target_level']));
        $del = $pdo->prepare('DELETE FROM build_queue WHERE id=?'); $del->execute(array($item['id']));
    }
}
function building_levels(PDO $pdo, int $villageId): array {
    $stmt=$pdo->prepare('SELECT building_key,level FROM buildings WHERE village_id=?'); $stmt->execute(array($villageId)); $levels=array();
    foreach($stmt->fetchAll() as $row) $levels[$row['building_key']]=(int)$row['level'];
    return $levels;
}
function building_cost(string $key, int $level): array {
    $base=array('main'=>100,'wood'=>80,'clay'=>80,'iron'=>80,'farm'=>120,'storage'=>100,'barracks'=>150)[$key] ?? 100;
    $mult=pow(1.26,max(0,$level-1));
    return array('wood'=>(int)ceil($base*$mult),'clay'=>(int)ceil($base*0.85*$mult),'iron'=>(int)ceil($base*0.7*$mult),'crop'=>(int)ceil($base*0.5*$mult));
}
function current_user(PDO $pdo): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $s=$pdo->prepare('SELECT id,username,email FROM users WHERE id=?'); $s->execute(array((int)$_SESSION['user_id'])); $u=$s->fetch();
    return $u ?: null;
}
if (isset($_GET['api'])) {
    $user=current_user($pdo); if (!$user) json_out(array('ok'=>false,'error'=>'Log eerst in.'),401);
    $s=$pdo->prepare('SELECT * FROM villages WHERE user_id=?'); $s->execute(array($user['id'])); $v=$s->fetch();
    if (!$v) json_out(array('ok'=>false,'error'=>'Geen dorp gevonden.'),404);
    finish_queue($pdo,(int)$v['id']);
    if ($_SERVER['REQUEST_METHOD']!=='POST') json_out(array('ok'=>false,'error'=>'POST vereist.'),405);
    $input=json_decode((string)file_get_contents('php://input'),true) ?: array();
    if (!hash_equals(csrf(),(string)($input['csrf'] ?? ''))) json_out(array('ok'=>false,'error'=>'Beveiligingstoken verlopen. Vernieuw de pagina.'),403);
    $action=(string)($input['action'] ?? '');
    if ($action==='upgrade') {
        $key=(string)($input['building'] ?? '');
        if (!isset($GLOBALS['catalog'][$key])) json_out(array('ok'=>false,'error'=>'Onbekend gebouw.'),400);
        $levels=building_levels($pdo,(int)$v['id']); $level=$levels[$key] ?? 0; $target=$level+1;
        $q=$pdo->prepare('SELECT COUNT(*) FROM build_queue WHERE village_id=?'); $q->execute(array($v['id']));
        if ((int)$q->fetchColumn()>=2) json_out(array('ok'=>false,'error'=>'Je bouwrij is vol (maximaal 2 opdrachten).'),400);
        $cost=building_cost($key,$target);
        foreach($cost as $resource=>$amount) if ((int)$v[$resource]<$amount) json_out(array('ok'=>false,'error'=>'Niet genoeg grondstoffen. Nodig: '.implode(', ',array_map(function($k,$n){return $n.' '.$k;},array_keys($cost),$cost)).'.'),400);
        $pdo->beginTransaction();
        try {
            $upd=$pdo->prepare('UPDATE villages SET wood=wood-?,clay=clay-?,iron=iron-?,crop=crop-? WHERE id=? AND wood>=? AND clay>=? AND iron>=? AND crop>=?');
            $upd->execute(array($cost['wood'],$cost['clay'],$cost['iron'],$cost['crop'],$v['id'],$cost['wood'],$cost['clay'],$cost['iron'],$cost['crop']));
            if ($upd->rowCount()!==1) throw new RuntimeException('Grondstoffen zijn gewijzigd; probeer opnieuw.');
            $duration=max(15,(int)(45*pow(1.55,$target-1)));
            $ins=$pdo->prepare('INSERT INTO build_queue(village_id,building_key,target_level,wood_cost,clay_cost,iron_cost,crop_cost,started_at,completes_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $ins->execute(array($v['id'],$key,$target,$cost['wood'],$cost['clay'],$cost['iron'],$cost['crop'],time(),time()+$duration));
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); json_out(array('ok'=>false,'error'=>$e->getMessage()),400); }
        json_out(array('ok'=>true,'message'=>$catalog[$key]['name'].' niveau '.$target.' wordt gebouwd.','refresh'=>true));
    }
    if ($action==='cancel') {
        $id=(int)($input['id'] ?? 0);
        $pdo->beginTransaction();
        $qs=$pdo->prepare('SELECT * FROM build_queue WHERE id=? AND village_id=? FOR UPDATE'); $qs->execute(array($id,$v['id'])); $item=$qs->fetch();
        if (!$item) { $pdo->rollBack(); json_out(array('ok'=>false,'error'=>'Bouwopdracht niet gevonden.'),404); }
        $refund=$pdo->prepare('UPDATE villages SET wood=wood+?,clay=clay+?,iron=iron+?,crop=crop+? WHERE id=?');
        $refund->execute(array((int)floor($item['wood_cost']*.5),(int)floor($item['clay_cost']*.5),(int)floor($item['iron_cost']*.5),(int)floor($item['crop_cost']*.5),$v['id']));
        $del=$pdo->prepare('DELETE FROM build_queue WHERE id=?'); $del->execute(array($id)); $pdo->commit();
        json_out(array('ok'=>true,'message'=>'Bouw geannuleerd; 50% van de kosten terugbetaald.','refresh'=>true));
    }
    json_out(array('ok'=>false,'error'=>'Onbekende actie.'),400);
}
$error=''; $message='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['auth_action'])) {
    if (!hash_equals(csrf(),(string)($_POST['csrf'] ?? ''))) $error='Beveiligingstoken verlopen. Vernieuw de pagina.';
    else {
        $action=(string)$_POST['auth_action']; $name=trim((string)($_POST['username'] ?? '')); $email=trim((string)($_POST['email'] ?? '')); $pass=(string)($_POST['password'] ?? '');
        if ($action==='register') {
            if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/',$name) || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pass)<10) $error='Gebruik een gebruikersnaam van 3–32 tekens, geldig e-mailadres en wachtwoord van minimaal 10 tekens.';
            else {
                try {
                    $pdo->beginTransaction(); $ins=$pdo->prepare('INSERT INTO users(username,email,password_hash) VALUES(?,?,?)'); $ins->execute(array($name,$email,password_hash($pass,PASSWORD_DEFAULT))); $uid=(int)$pdo->lastInsertId();
                    $v=$pdo->prepare('INSERT INTO villages(user_id,name,x,y,last_tick) VALUES(?,?,?,?,?)'); $v->execute(array($uid,'Thuisdorp',random_int(470,530),random_int(470,530),time())); $vid=(int)$pdo->lastInsertId();
                    $bi=$pdo->prepare('INSERT INTO buildings(village_id,building_key,level) VALUES(?,?,?)');
                    foreach(array('main'=>1,'wood'=>1,'clay'=>1,'iron'=>1,'farm'=>1,'storage'=>1,'barracks'=>0) as $key=>$level) $bi->execute(array($vid,$key,$level));
                    $pdo->commit(); session_regenerate_id(true); $_SESSION['user_id']=$uid; header('Location: ./'); exit;
                } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); $error='Registratie mislukt. Gebruikersnaam of e-mailadres is mogelijk al in gebruik.'; }
            }
        } elseif ($action==='login') {
            $s=$pdo->prepare('SELECT id,password_hash FROM users WHERE username=? OR email=? LIMIT 1'); $s->execute(array($name,$name)); $row=$s->fetch();
            if ($row && password_verify($pass,$row['password_hash'])) { session_regenerate_id(true); $_SESSION['user_id']=(int)$row['id']; header('Location: ./'); exit; }
            $error='Inloggen mislukt. Controleer je gegevens.';
        }
    }
}
if (isset($_GET['logout'])) { $_SESSION=array(); session_destroy(); header('Location: ./'); exit; }
$user=current_user($pdo);
if (!$user) {
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tribal Wars Remaster</title><link rel="stylesheet" href="style.css"></head><body class="auth"><main class="authbox"><div class="crest">⚔</div><p class="eyebrow">EEN RIJK WORDT GESMEED</p><h1>TRIBAL WARS <span>REMASTER</span></h1><p class="muted">Bouw je dorp. Verzamel grondstoffen. Bereid je voor op oorlog.</p><?php if($error!==''): ?><p class="notice"><?=h($error)?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label>Gebruikersnaam of e-mail</label><input name="username" required autocomplete="username"><label>E-mail (alleen nodig bij registreren)</label><input name="email" type="email" autocomplete="email"><label>Wachtwoord</label><input name="password" type="password" required autocomplete="current-password"><div class="authbuttons"><button name="auth_action" value="login">Inloggen</button><button name="auth_action" value="register" class="secondary">Account maken</button></div></form><small>Eigen fan-game prototype; geen officiële Tribal Wars-productie.</small></main></body></html><?php exit; }
$s=$pdo->prepare('SELECT * FROM villages WHERE user_id=?'); $s->execute(array($user['id'])); $v=$s->fetch();
if (!$v) { $ins=$pdo->prepare('INSERT INTO villages(user_id,name) VALUES(?,?)'); $ins->execute(array($user['id'],'Thuisdorp')); header('Location: ./'); exit; }
finish_queue($pdo,(int)$v['id']); $s->execute(array($user['id'])); $v=$s->fetch();
$tick=$pdo->prepare('UPDATE villages SET last_tick=? WHERE id=?'); $tick->execute(array($now,$v['id'])); $levels=building_levels($pdo,(int)$v['id']); $q=$pdo->prepare('SELECT * FROM build_queue WHERE village_id=? ORDER BY completes_at'); $q->execute(array($v['id'])); $queue=$q->fetchAll();
$rate=array('wood'=>30+($levels['wood']??0)*18,'clay'=>30+($levels['clay']??0)*18,'iron'=>20+($levels['iron']??0)*16,'crop'=>35+($levels['farm']??0)*22);
$now=time(); $cap=1000+($levels['storage']??1)*1000;
foreach(array('wood','clay','iron','crop') as $r) { $elapsed=max(0,$now-(int)$v['last_tick']); $amount=min($cap,(int)$v[$r]+(int)floor($rate[$r]*$elapsed/3600)); if($amount!==(int)$v[$r]) { $u=$pdo->prepare('UPDATE villages SET '.$r.'=? WHERE id=?'); $u->execute(array($amount,$v['id'])); $v[$r]=$amount; } }
$levels=building_levels($pdo,(int)$v['id']); $q->execute(array($v['id'])); $queue=$q->fetchAll();
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($v['name'])?> — Tribal Wars Remaster</title><link rel="stylesheet" href="style.css"></head><body>
<header class="topbar"><a class="brand" href="./"><span>⚔</span> TRIBAL WARS <b>REMASTER</b></a><div class="resources"><?php foreach(array('wood'=>'🪵','clay'=>'🧱','iron'=>'⛓','crop'=>'🌾') as $r=>$icon): ?><div class="resource"><span><?=$icon?></span><strong><?=number_format((int)$v[$r])?></strong><small>+<?=$rate[$r]?>/u</small></div><?php endforeach; ?></div><div class="usernav">👤 <?=h($user['username'])?> <a href="?logout=1">Uitloggen</a></div></header>
<nav class="mainnav"><a class="active" href="#village">Dorp</a><a href="#buildings">Gebouwen</a><a href="#queue">Bouwrij <em><?=count($queue)?></em></a><a href="#map">Kaart</a><a href="#army">Leger</a><a href="#tribe">Stam</a><a href="#reports">Rapporten</a></nav>
<main class="layout"><aside class="sidebar"><div class="profile"><div class="avatar">♜</div><div><b><?=h($v['name'])?></b><small>Coördinaten (<?= (int)$v['x'] ?>|<?= (int)$v['y'] ?>)</small></div></div><div class="sidehead">DORPSMENU</div><a class="sideactive" href="#village">🏰 Overzicht</a><a href="#buildings">🛠 Gebouwen</a><a href="#map">🗺 Wereldkaart</a><a href="#queue">⏳ Bouwrij</a><a href="#army">🛡 Troepen <span class="soon">binnenkort</span></a><a href="#tribe">⚑ Stam <span class="soon">binnenkort</span></a><div class="sidebarfoot">WERELD 1 <b>Speed ×1</b><small>PHP 7.4 · MySQL</small></div></aside>
<section class="content"><div class="welcome"><div><p class="eyebrow">WERELD 1 · NOORDRIJK</p><h1><?=h($v['name'])?></h1><p>Een nieuw rijk begint met één dorp.</p></div><div class="rank"><span>RANG</span><b>—</b><small>Beginnende heerser</small></div></div>
<div class="village-scene" id="village"><div class="scene-sky"><span class="cloud cloud1"></span><span class="cloud cloud2"></span><span class="sun"></span></div><div class="scene-hill hillback"></div><div class="scene-hill hillfront"></div><div class="scene-river"></div><div class="scene-road"></div><div class="castle"><div class="tower t1"></div><div class="tower t2"></div><div class="keep"><div class="flag">⚑</div><div class="gate"></div></div><div class="wall"></div></div><div class="village-label"><b><?=h($v['name'])?></b><span><?= (int)$v['x'] ?>|<?= (int)$v['y'] ?></span></div><div class="scene-caption">JOUW DORP <span>◆</span> NOORDRIJK</div></div>
<div class="section-title" id="buildings"><div><p class="eyebrow">ONTWIKKEL JE NEDERZETTING</p><h2>Gebouwen</h2></div><span class="subtle">Selecteer een gebouw om te upgraden</span></div>
<div class="building-grid"><?php foreach($catalog as $key=>$b): $level=$levels[$key]??0; $cost=building_cost($key,$level+1); $busy=false; foreach($queue as $item) if($item['building_key']===$key)$busy=true; ?><article class="building-card"><div class="building-art art-<?=h($key)?>"><span><?=$b['icon']?></span><i>Lv. <?=$level?></i></div><div class="building-info"><h3><?=h($b['name'])?></h3><p><?=h($b['desc'])?></p><div class="costs"><span>🪵 <?=$cost['wood']?></span><span>🧱 <?=$cost['clay']?></span><span>⛓ <?=$cost['iron']?></span></div><button class="upgrade" data-building="<?=h($key)?>" <?=($busy||count($queue)>=2)?'disabled':''?>><?=$busy?'In aanbouw':($level===0?'Bouwen':'Verbeteren')?> →</button></div></article><?php endforeach; ?></div>
<div class="section-title" id="queue"><div><p class="eyebrow">WERK IN UITVOERING</p><h2>Bouwrij</h2></div><span class="subtle">Max. 2 opdrachten</span></div><div class="queue-list"><?php if(!$queue): ?><div class="empty">⚒ <b>Geen bouwopdrachten</b><span>Kies een gebouw hierboven om je dorp te ontwikkelen.</span></div><?php else: foreach($queue as $item): $b=$catalog[$item['building_key']]??array('name'=>$item['building_key'],'icon'=>'⚒'); ?><div class="queue-item"><span class="queue-icon"><?=$b['icon']?></span><div class="queue-detail"><b><?=h($b['name'])?> niveau <?= (int)$item['target_level'] ?></b><small>Voltooid om <?=date('H:i:s',(int)$item['completes_at'])?></small><div class="progress"><i style="width:<?=max(0,min(100,(int)(100*(time()-(int)$item['started_at'])/max(1,(int)$item['completes_at']-(int)$item['started_at']))))?>%"></i></div></div><span class="timer" data-completes="<?=(int)$item['completes_at']?>">…</span><button class="cancel" data-id="<?=(int)$item['id']?>">Annuleren</button></div><?php endforeach; endif; ?></div>
<div class="section-title" id="map"><div><p class="eyebrow">DE WERELD LIGT OPEN</p><h2>Wereldkaart</h2></div><span class="subtle">Coördinaten <?= (int)$v['x'] ?>|<?= (int)$v['y'] ?></span></div><div class="map-panel"><div class="map-grid"><?php for($i=0;$i<81;$i++): $center=$i===40; ?><div class="map-tile <?=$center?'own':''?>"><?=$center?'♜':(in_array($i,array(6,16,24,56,65,74),true)?'🌲':(in_array($i,array(11,35,49,70),true)?'⛰':'·'))?></div><?php endfor; ?></div><div class="map-note"><b>De omgeving van <?=h($v['name'])?></b><p>Dit is een lokale kaartweergave. Multiplayer-dorpen en verovering worden in volgende ontwikkelfasen toegevoegd.</p></div></div>
<div class="future-grid"><div id="army"><b>🛡 Leger</b><span>Trainingsmechaniek in ontwikkeling</span></div><div id="tribe"><b>⚑ Stam</b><span>Stammen en diplomatie in ontwikkeling</span></div><div id="reports"><b>📜 Rapporten</b><span>Gevechtsrapporten in ontwikkeling</span></div></div>
<footer>TRIBAL WARS REMASTER · FANPROJECT · <?=date('Y')?> <span>Strategie · Economie · Oorlog</span></footer></section></main>
<div class="toast" id="toast" role="status"></div><script>const csrfToken=<?=json_encode(csrf())?>;const toast=document.getElementById('toast');function notify(t){toast.textContent=t;toast.classList.add('show');setTimeout(()=>toast.classList.remove('show'),3500)}async function action(data){try{const r=await fetch('?api=1',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...data,csrf:csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||'Actie mislukt');notify(j.message||'Gelukt');if(j.refresh)setTimeout(()=>location.reload(),650)}catch(e){notify(e.message)}}document.querySelectorAll('.upgrade').forEach(b=>b.addEventListener('click',()=>action({action:'upgrade',building:b.dataset.building})));document.querySelectorAll('.cancel').forEach(b=>b.addEventListener('click',()=>{if(confirm('Bouwopdracht annuleren? Je krijgt 50% van de kosten terug.'))action({action:'cancel',id:b.dataset.id})}));function timers(){document.querySelectorAll('[data-completes]').forEach(e=>{let n=Number(e.dataset.completes)-Math.floor(Date.now()/1000);e.textContent=n<=0?'klaar':Math.floor(n/60)+'m '+(n%60)+'s'})}timers();setInterval(timers,1000);setInterval(()=>location.reload(),30000);</script></body></html>
