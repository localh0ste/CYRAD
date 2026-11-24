<?php
/**
 * CYRAD — Access Blocked page.
 * Served by the web server's catch-all vhost when a blocked domain resolves to
 * the CYRAD web host (CYRAD_BLOCK_MODE = 'page').
 *   - content blocks (dns_rules)     -> "ACCESS BLOCKED" (HTTP 200)
 */
require_once __DIR__ . '/config.php';

$host = $_SERVER['HTTP_HOST'] ?? 'this site';
$uri  = $_SERVER['REQUEST_URI'] ?? '';
$site = $host . ($uri && $uri !== '/' ? $uri : '');

$badge = '&#128683;';
$title = 'ACCESS BLOCKED';
$sub   = 'This site is restricted by your network administrator.';
$reason = 'The domain above matched a <b style="color:var(--danger)">block rule</b> configured for your '
        . 'organization in the <b>CYRAD Control Panel</b>. If you believe this is a mistake, contact your network administrator.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Access Blocked — CYRAD</title>
<style>
  :root{
    --bg:#14070a; --panel:rgba(30,10,14,.72); --border:rgba(255,90,100,.65);
    --cyan:#ff4d5e; --violet:#ff8a3d; --text:#fdeef0; --muted:#c79aa2; --danger:#ff5d73;
  }
  *{box-sizing:border-box;}
  html,body{height:100%;margin:0;}
  body{
    font-family:"Segoe UI",Roboto,system-ui,sans-serif;color:var(--text);
    display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;
    background:
      linear-gradient(180deg,rgba(20,7,10,.84) 0%,rgba(20,7,10,.6) 45%,rgba(20,7,10,.92) 100%),
      url('admin/assets/bg.jpg') center/cover no-repeat fixed,
      var(--bg);
  }
  .card{
    width:min(560px,100%);background:var(--panel);border:1px solid var(--border);
    border-radius:18px;padding:40px 36px;backdrop-filter:blur(14px);
    box-shadow:0 10px 40px rgba(0,0,0,.5);text-align:center;
  }
  .badge{
    width:84px;height:84px;margin:0 auto 18px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;font-size:42px;
    background:radial-gradient(circle at 30% 30%,rgba(255,93,115,.4),rgba(255,138,61,.18));
    border:1px solid rgba(255,93,115,.5);
  }
  h1{margin:0 0 6px;font-size:26px;letter-spacing:.5px;
     background:linear-gradient(135deg,var(--cyan),var(--violet));-webkit-background-clip:text;background-clip:text;color:transparent;}
  .sub{color:var(--muted);font-size:14px;margin:0 0 22px;}
  .site{
    font-family:ui-monospace,Menlo,Consolas,monospace;font-size:18px;color:var(--cyan);
    background:rgba(8,13,24,.6);border:1px solid rgba(94,234,255,.25);border-radius:10px;
    padding:12px 14px;word-break:break-all;margin:0 0 20px;
  }
  .reason{color:var(--muted);font-size:13px;line-height:1.6;margin:0 0 24px;}
  .bar{height:4px;border-radius:4px;background:linear-gradient(90deg,var(--cyan),var(--violet));margin:0 0 22px;}
  .foot{color:var(--muted);font-size:12px;}
  .foot b{color:var(--text);}
</style>
</head>
<body>
  <div class="card">
    <div class="badge"><?= $badge ?></div>
    <h1><?= $title ?></h1>
    <p class="sub"><?= $sub ?></p>
    <div class="site"><?= htmlspecialchars($site, ENT_QUOTES) ?></div>
    <div class="bar"></div>
    <p class="reason"><?= $reason ?></p>
    <p class="foot">Enforced by <b>CYRAD</b> &middot; RADIUS &amp; DNS Control Panel</p>
  </div>
</body>
</html>
