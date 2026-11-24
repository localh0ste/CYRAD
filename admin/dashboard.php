<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/lib/role_guard.php';
require_once __DIR__ . '/config/db.php';
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$org_id  = (int)($_SESSION['organization_id'] ?? 0);
$role    = $_SESSION['admin_role'] ?? 'admin';
$isSuper = ($role === 'super_admin');
$orgCond = $isSuper ? "1=1" : "organization_id = $org_id";
// $orgJoin is used after "JOIN radcheck c ON a.username=c.username" (plus the
// user_profiles shadow join prepended in each query), so AD users — who have no
// radcheck row because their credentials live in the domain — still attribute
// their RADIUS sessions to this org via the user_profiles shadow.
$orgJoin = $isSuper ? "1=1" : "(c.organization_id = $org_id OR p.organization_id = $org_id)";
$orgShadowJoin = "LEFT JOIN user_profiles p ON p.username = a.username";
$orgIdCond = $isSuper ? "1=1" : "id = $org_id";

// --- query helpers (defensive: never fatal on empty/missing) ---
$q1 = function($sql) use ($conn) {
    $r = @$conn->query($sql);
    if (!$r) return 0;
    $row = $r->fetch_assoc();
    return is_array($row) ? (float)reset($row) : 0;
};
$rows = function($sql) use ($conn) {
    $out = [];
    $r = @$conn->query($sql);
    if ($r) while ($x = $r->fetch_assoc()) $out[] = $x;
    return $out;
};
function fmtBytes($b) {
    if ($b >= 1099511627776) return round($b/1099511627776, 2) . ' TB';
    if ($b >= 1073741824)    return round($b/1073741824, 2) . ' GB';
    if ($b >= 1048576)       return round($b/1048576, 1) . ' MB';
    return round($b/1024) . ' KB';
}

// --- KPIs ---
$active    = (int)$q1("SELECT COUNT(*) FROM radacct a LEFT JOIN radcheck c ON a.username=c.username $orgShadowJoin WHERE $orgJoin AND (a.acctstoptime IS NULL OR a.acctstoptime > NOW()-INTERVAL 10 MINUTE)");
$users     = (int)$q1("SELECT COUNT(*) FROM (SELECT DISTINCT username FROM radcheck WHERE $orgCond UNION SELECT DISTINCT username FROM user_profiles WHERE $orgCond) u");
$bytes     = (float)$q1("SELECT COALESCE(SUM(COALESCE(a.acctinputoctets,0)+COALESCE(a.acctoutputoctets,0)),0) FROM radacct a LEFT JOIN radcheck c ON a.username=c.username $orgShadowJoin WHERE $orgJoin");
$usedDisp  = fmtBytes($bytes);
$blocked24 = 0;
$orgs      = (int)$q1("SELECT COUNT(*) FROM organizations WHERE $orgIdCond");

// Bandwidth used THIS MONTH (resets on the 1st), shown in the dashboard.
$monthBytes = (float)$q1(
  "SELECT COALESCE(SUM(COALESCE(a.acctinputoctets,0)+COALESCE(a.acctoutputoctets,0)),0)
   FROM radacct a LEFT JOIN radcheck c ON a.username=c.username $orgShadowJoin
   WHERE $orgJoin AND a.acctstarttime >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
);
$monthDisp = fmtBytes($monthBytes);

$upSec = 0;
$ur = @$conn->query("SHOW STATUS LIKE 'Uptime'");
if ($ur && $u = $ur->fetch_assoc()) $upSec = (int)$u['Value'];
$uptimeLabel = floor($upSec/86400) . 'd ' . floor(($upSec%86400)/3600) . 'h';

// --- 14-day usage ---
$usage_labels = []; $usage_values = [];
$ug = @$conn->query(
  "SELECT DATE(a.acctstarttime) d,
          ROUND(SUM(COALESCE(a.acctinputoctets,0)+COALESCE(a.acctoutputoctets,0))/1073741824, 3) gb
   FROM radacct a LEFT JOIN radcheck c ON a.username=c.username $orgShadowJoin
   WHERE $orgJoin AND a.acctstarttime >= CURDATE()-INTERVAL 14 DAY
   GROUP BY DATE(a.acctstarttime) ORDER BY d"
);
if ($ug) while ($r = $ug->fetch_assoc()) { $usage_labels[] = $r['d']; $usage_values[] = (float)$r['gb']; }

// --- Top consumers ---
$consumers = $rows("SELECT a.username u, COALESCE(SUM(COALESCE(a.acctinputoctets,0)+COALESCE(a.acctoutputoctets,0)),0) used
                       FROM radacct a LEFT JOIN radcheck c ON a.username=c.username $orgShadowJoin WHERE $orgJoin
                       GROUP BY a.username ORDER BY used DESC LIMIT 6");
$maxUsed = 0; foreach ($consumers as $c) $maxUsed = max($maxUsed, (float)$c['used']);

// --- Org health ---
$orgHealth = $rows("SELECT o.org_name,
  (SELECT COALESCE(SUM(COALESCE(a.acctinputoctets,0)+COALESCE(a.acctoutputoctets,0)),0)
     FROM radacct a LEFT JOIN radcheck c ON a.username=c.username
     LEFT JOIN user_profiles p ON p.username=a.username
     WHERE c.organization_id=o.id OR p.organization_id=o.id) used,
  (SELECT COUNT(*) FROM radcheck WHERE organization_id=o.id) +
  (SELECT COUNT(*) FROM user_profiles WHERE organization_id=o.id AND NOT EXISTS (SELECT 1 FROM radcheck x WHERE x.username=user_profiles.username)) ucount,
  COALESCE(o.default_internet_limit,0) cap
  FROM organizations o WHERE " . ($isSuper ? "1=1" : "o.id=$org_id"));

// --- Policy ---
$allow = (int)$q1("SELECT COUNT(*) FROM dns_rules WHERE $orgCond AND action='allow' AND enabled=1");
$block = (int)$q1("SELECT COUNT(*) FROM dns_rules WHERE $orgCond AND action='block' AND enabled=1");

// --- Org health ---
$dbOk  = !$conn->connect_error;
$dnsRaw = trim(@shell_exec("systemctl is-active cyrad-dnsmon 2>/dev/null") ?: '');
$dnsState = ($dnsRaw === 'active') ? 'ok' : ((defined('CYRAD_DNS_ENFORCE') && CYRAD_DNS_ENFORCE) ? 'ok' : 'warn');

$svc = [
  ['DNS Blocker',   $dnsState],
  ['RADIUS',        'ok'],
  ['Database',      $dbOk ? 'ok' : 'warn'],
  ['Web Panel',     'ok'],
];
foreach ($svc as &$s) { $s['label'] = $s[1] === 'ok' ? 'OPERATIONAL' : 'CHECK'; }
unset($s);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CYRAD Control Panel — Enterprise Command Center</title>
  <link rel="icon" href="data:,">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><div>CYRAD<small>CONTROL PANEL</small></div></div>
    <?php require __DIR__ . '/lib/sidebar.php'; ?>
  </aside>
  <div class="main">
    <header class="topbar">
      <div><strong>Enterprise Command Center</strong></div>
      <div class="org">
        <i class="fas fa-user-shield" style="color:var(--cyan)"></i>
        <?= htmlspecialchars($_SESSION['admin_user'] ?? '') ?> · Org #<?= $org_id ?> · <?= $role ?>
        <span class="dot" style="background:var(--cyan);margin-left:10px"></span>
      </div>
    </header>
    <div class="content">

      <!-- KPI STRIP -->
      <div class="kpis">
        <a class="card stat glass" href="users.php" style="color:var(--text);text-decoration:none">
          <div class="num" id="kpiActive"><?= $active ?></div><div class="lbl"><i class="fas fa-signal"></i> Active Sessions</div></a>
        <a class="card stat glass" href="users.php" style="color:var(--text);text-decoration:none">
          <div class="num"><?= $users ?></div><div class="lbl"><i class="fas fa-users"></i> Total Users</div></a>
        <div class="card stat glass">
          <div class="num"><?= $usedDisp ?></div><div class="lbl"><i class="fas fa-arrow-up-right-dots"></i> Data Consumed</div></div>
        <div class="card stat glass">
          <div class="num"><?= $monthDisp ?></div><div class="lbl"><i class="fas fa-calendar-month"></i> Bandwidth This Month</div></div>
        <div class="card stat glass">
          <div class="num"><?= $orgs ?></div><div class="lbl"><i class="fas fa-building"></i> Organizations</div></div>
        <div class="card stat glass">
          <div class="num"><?= $uptimeLabel ?></div><div class="lbl"><i class="fas fa-server"></i> DB Uptime</div></div>
      </div>

      <!-- ROW A: usage chart + infra/policy -->
      <div class="split2">
        <div class="card glass">
          <h3><i class="fas fa-chart-bar" style="color:var(--cyan)"></i> Internet Usage — last 14 days (GB) &nbsp;<span class="muted-sm">+ live throughput</span></h3>
          <canvas id="usageChart" height="120"></canvas>
        </div>
        <div>
          <div class="card glass">
            <h3><i class="fas fa-heart-pulse" style="color:var(--cyan)"></i> Infrastructure Health</h3>
            <?php foreach ($svc as $s): $ok = $s[1]==='ok'; ?>
              <div class="svc">
                <span><span class="dot" style="background:<?= $ok?'var(--ok)':'var(--danger)' ?>"></span><?= $s[0] ?></span>
                <span class="pill <?= $ok?'allow':'block' ?>"><?= $s['label'] ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="card glass" style="margin-top:18px">
            <h3><i class="fas fa-shield-halved" style="color:var(--violet)"></i> DNS Policy Summary</h3>
            <div class="svc"><span><i class="fas fa-check" style="color:var(--ok)"></i> Allow rules</span><span class="pill allow"><?= $allow ?></span></div>
            <div class="svc"><span><i class="fas fa-ban" style="color:var(--danger)"></i> Block rules</span><span class="pill block"><?= $block ?></span></div>
          </div>
        </div>
      </div>

      <!-- ROW B: top consumers -->
      <div class="split2">
        <div class="card glass">
          <h3><i class="fas fa-trophy" style="color:var(--violet)"></i> Top Consumers <span class="muted-sm">(data used)</span></h3>
          <div class="feed">
            <?php if (empty($consumers)): ?><p class="muted-sm">No usage data.</p><?php endif; ?>
            <?php foreach ($consumers as $c): $w = $maxUsed>0 ? (float)$c['used']/$maxUsed*100 : 0; ?>
              <div class="feed-row">
                <span class="d" style="flex:0 0 120px"><?= htmlspecialchars($c['u']) ?></span>
                <span class="mbar"><i style="width:<?= $w ?>%"></i></span>
                <span class="who" style="min-width:64px;text-align:right"><?= fmtBytes((float)$c['used']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card glass">
          <h3><i class="fas fa-building-columns" style="color:var(--cyan)"></i> Organization Health</h3>
          <div class="feed">
            <?php if (empty($orgHealth)): ?><p class="muted-sm">No organizations.</p><?php endif; ?>
            <?php foreach ($orgHealth as $o):
              $cap = (float)$o['cap'] * max(1,(int)$o['ucount']);
              $used = (float)$o['used'];
              $pct = ($cap>0) ? min(100, $used/$cap*100) : 0;
              $capTxt = $cap>0 ? fmtBytes($cap) : 'no cap';
            ?>
              <div class="feed-row">
                <span class="d" style="flex:0 0 150px"><?= htmlspecialchars($o['org_name']) ?></span>
                <span class="mbar"><i style="width:<?= $pct ?>%"></i></span>
                <span class="who" style="min-width:130px;text-align:right"><?= fmtBytes($used) ?> / <?= $capTxt ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- QUICK ACTIONS -->
      <div class="card glass">
        <h3><i class="fas fa-bolt" style="color:var(--violet)"></i> Quick Actions</h3>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px">
          <a class="qa" href="add_user.php"><i class="fas fa-user-plus"></i> Add User (Manual)</a>
          <a class="qa" href="upload_users.php"><i class="fas fa-file-csv"></i> Add User (CSV)</a>
          <a class="qa" href="guest_tickets.php"><i class="fas fa-ticket"></i> Mint Guest Voucher</a>
          <a class="qa" href="wifi_profiles.php"><i class="fas fa-wifi"></i> WiFi Profiles</a>
          <a class="qa" href="active_sessions.php"><i class="fas fa-signal"></i> Active Sessions</a>
          <a class="qa" href="ipmap.php"><i class="fas fa-map-location-dot"></i> Live IP Map</a>
          <a class="qa" href="dns_rules.php"><i class="fas fa-shield-halved"></i> New DNS Rule</a>
        </div>
      </div>

    </div>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const cyradCtx = document.getElementById('usageChart');
if (cyradCtx) {
  new Chart(cyradCtx, {
    type: 'bar',
    data: {
      labels: <?= json_encode($usage_labels) ?>,
      datasets: [{
        label: 'GB used',
        data: <?= json_encode($usage_values) ?>,
        backgroundColor: 'rgba(255,77,94,0.65)',
        borderColor: '#ff4d5e',
        borderWidth: 1,
        borderRadius: 4,
        hoverBackgroundColor: 'rgba(255,138,61,0.85)'
      }]
    },
    options: {
          plugins: { legend: { labels: { color: '#fdeef0' } } },
          scales: {
            x: { ticks: { color: '#c79aa2' }, grid: { color: 'rgba(255,90,100,0.08)' } },
            y: { beginAtZero: true, ticks: { color: '#c79aa2' }, grid: { color: 'rgba(255,90,100,0.08)' } }
          }
        }
  });
}
</script>
<script>
// live Active Sessions count — authenticated via the admin session cookie
setInterval(async () => {
  try {
    const r = await fetch('api_user_status.php', { credentials: 'same-origin' });
    if (!r.ok) return;
    const d = await r.json();
    const n = (d.users || []).filter(u => u.online).length;
    const el = document.getElementById('kpiActive');
    if (el) el.textContent = n;
  } catch (e) {}
}, 15000);
</script>
</body>
</html>
