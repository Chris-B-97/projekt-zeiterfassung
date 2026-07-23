<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user    = require_login();
$isAdmin = (($user['role'] ?? '') === 'admin');

// Aktionen: anlegen / bearbeiten / löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'create' || $action === 'update') {
        $name      = trim((string)($_POST['name'] ?? ''));
        $desc      = trim((string)($_POST['description'] ?? ''));
        $customer  = trim((string)($_POST['customer'] ?? ''));
        $accessIds = array_values(array_unique(array_map('intval', (array)($_POST['users'] ?? []))));
        if ($name === '' || mb_strlen($name) > 150) {
            flash_set('error', 'Name erforderlich (max. 150).');
        } else {
            if ($action === 'create') {
                db()->prepare('INSERT INTO projects (user_id, name, description, customer) VALUES (?,?,?,?)')
                    ->execute([$user['id'], $name, $desc !== '' ? $desc : null, $customer !== '' ? $customer : null]);
                $newId = (int)db()->lastInsertId();

                // Ersteller immer mit Zugriff
                if (!in_array((int)$user['id'], $accessIds, true)) {
                    $accessIds[] = (int)$user['id'];
                }
                $ins = db()->prepare('INSERT IGNORE INTO project_users (project_id, user_id) VALUES (?,?)');} elseif ($action === 'delete_entry') {
                foreach ($accessIds as $uid) {
                    if ($uid > 0) $ins->execute([$newId, $uid]);
                }
                flash_set('ok', 'Projekt erstellt.');
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $upd = db()->prepare('UPDATE projects SET name=?, description=?, customer=? WHERE id=? AND (user_id=? OR ?=\'admin\')');
                $upd->execute([$name, $desc !== '' ? $desc : null, $customer !== '' ? $customer : null, $id, $user['id'], $user['role'] ?? '']);

                // Zugriffe IMMER aktualisieren, auch wenn keine Felder geändert wurden
                if (true) {
                    db()->prepare('DELETE FROM project_users WHERE project_id = ?')->execute([$id]);

                    // Ersteller (aktueller User) immer mit Zugriff
                    if (!in_array((int)$user['id'], $accessIds, true)) {
                        $accessIds[] = (int)$user['id'];
                    }
                    $ins = db()->prepare('INSERT IGNORE INTO project_users (project_id, user_id) VALUES (?,?)');
                    foreach ($accessIds as $uid) {
                        if ($uid > 0) $ins->execute([$id, $uid]);
                    }
                }
                flash_set('ok', 'Aktualisiert.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM projects WHERE id=? AND (user_id=? OR ?=\'admin\')')->execute([$id, $user['id'], $user['role'] ?? '']);
        flash_set('ok', 'Gelöscht.');
    }
    header('Location: projects.php' . (!empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : ''));
    exit;
}

// Filter / Sortierung
$search    = trim((string)($_GET['q'] ?? ''));
$fCust     = trim((string)($_GET['customer'] ?? ''));
$fUser     = (int)($_GET['user'] ?? 0);
$from      = trim((string)($_GET['from'] ?? ''));
$to        = trim((string)($_GET['to'] ?? ''));
$sort      = (string)($_GET['sort'] ?? 'activity');
$validSort = ['activity','created','name','minutes','entries'];
if (!in_array($sort, $validSort, true)) $sort = 'activity';

// Aggregierte Liste – Zugriffsschutz: Owner ODER Admin ODER Mitglied in project_users
$sql = "
  SELECT p.id, p.name, p.description, p.customer, p.user_id, p.created_at,
         u.display_name AS owner_name,
         COALESCE(a.total_minutes,0) AS total_minutes,
         COALESCE(a.total_hours,0)   AS total_hours,
         COALESCE(a.entry_count,0)   AS entry_count,
         a.last_activity
  FROM projects p
  LEFT JOIN users u ON u.id = p.user_id
  LEFT JOIN (
    SELECT project_id,
           SUM(minutes) AS total_minutes,
           SUM(CEIL(minutes/30.0)/2) AS total_hours,
           COUNT(*)     AS entry_count,
           MAX(created_at) AS last_activity
    FROM entries GROUP BY project_id
  ) a ON a.project_id = p.id
";
$where = []; $args = [];

// Zugriffsfilter
$where[] = '(p.user_id = ? OR ? = \'admin\' OR EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id = p.id AND pu.user_id = ?))';
array_push($args, $user['id'], $user['role'] ?? '', $user['id']);

if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.description LIKE ? OR p.customer LIKE ? OR u.display_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like, $like);
}
if ($fCust !== '') { $where[] = 'p.customer = ?'; $args[] = $fCust; }
if ($fUser > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM entries e WHERE e.project_id = p.id AND e.user_id = ?)';
    $args[] = $fUser;
}
if ($from !== '') { $where[] = 'a.last_activity >= ?'; $args[] = $from . ' 00:00:00'; }
if ($to   !== '') { $where[] = 'a.last_activity <= ?'; $args[] = $to   . ' 23:59:59'; }
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);

switch ($sort) {
    case 'name':     $sql .= ' ORDER BY p.name ASC'; break;
    case 'minutes':  $sql .= ' ORDER BY total_minutes DESC, p.created_at DESC'; break;
    case 'entries':  $sql .= ' ORDER BY entry_count DESC, p.created_at DESC'; break;
    case 'created':  $sql .= ' ORDER BY p.created_at DESC'; break;
    case 'activity':
    default:         $sql .= ' ORDER BY (a.last_activity IS NULL), a.last_activity DESC, p.created_at DESC';
}

$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

// Zugewiesene Benutzer pro Projekt (für Edit-Dialog)
$projectAccess = [];
if ($rows) {
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $pa  = db()->prepare("SELECT project_id, user_id FROM project_users WHERE project_id IN ($in)");
    $pa->execute($ids);
    foreach ($pa->fetchAll() as $row) {
        $projectAccess[(int)$row['project_id']][] = (int)$row['user_id'];
    }
}

// Optionen für Filter-Dropdowns
$customers    = db()->query('SELECT DISTINCT customer FROM projects WHERE customer IS NOT NULL AND customer <> "" ORDER BY customer')->fetchAll(PDO::FETCH_COLUMN);
$contributors = db()->query('SELECT DISTINCT u.id, u.display_name FROM users u JOIN entries e ON e.user_id = u.id ORDER BY u.display_name')->fetchAll();

// Alle Benutzer für Multi-Select beim Anlegen
$allUsers = db()->query('SELECT id, display_name FROM users ORDER BY display_name')->fetchAll();

layout_start('Projekte', $user);
flash_render();
?>
<div class="mb-6 flex items-end justify-between gap-4">
  <div>
    <h1 class="text-3xl font-bold tracking-tight">Projekte</h1>
    <p class="text-sm text-slate-500">Übersicht aller Projekte mit Zeitaufwand</p>
  </div>
  <button type="button" onclick="document.getElementById('dlg-new').showModal()" class="rounded-lg bg-gradient-primary px-4 py-2 font-medium text-white shadow-elegant">+ Neues Projekt</button>
</div>

<form method="get" class="mb-4 space-y-3 rounded-2xl border bg-white/60 p-4 backdrop-blur">
  <input name="q" value="<?= e($search) ?>" placeholder="🔍 Projekte durchsuchen (Name, Kunde, Beschreibung, Bearbeiter)…" class="h-11 w-full rounded border bg-white px-3">
  <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
    <label class="text-xs">Sortierung
      <select name="sort" class="mt-1 w-full rounded border bg-white px-2 py-2 text-sm">
        <?php foreach ([
          'activity' => 'Letzte Aktivität',
          'created'  => 'Erstellt',
          'name'     => 'Name (A–Z)',
          'minutes'  => 'Stunden (meist)',
          'entries'  => 'Einträge (meist)',
        ] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $sort===$k?'selected':'' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-xs">Kunde
      <select name="customer" class="mt-1 w-full rounded border bg-white px-2 py-2 text-sm">
        <option value="">Alle Kunden</option>
        <?php foreach ($customers as $c): ?>
          <option value="<?= e($c) ?>" <?= $fCust===$c?'selected':'' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-xs">Bearbeiter
      <select name="user" class="mt-1 w-full rounded border bg-white px-2 py-2 text-sm">
        <option value="0">Alle Bearbeiter</option>
        <?php foreach ($contributors as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $fUser===(int)$c['id']?'selected':'' ?>><?= e($c['display_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-xs">Aktivität von
      <input type="date" name="from" value="<?= e($from) ?>" class="mt-1 w-full rounded border bg-white px-2 py-2 text-sm">
    </label>
    <label class="text-xs">Aktivität bis
      <input type="date" name="to" value="<?= e($to) ?>" class="mt-1 w-full rounded border bg-white px-2 py-2 text-sm">
    </label>
    <div class="flex items-end gap-2">
      <button class="w-full rounded border bg-white px-3 py-2 text-sm hover:bg-slate-50">Anwenden</button>
      <a href="projects.php" class="rounded border bg-white px-3 py-2 text-sm hover:bg-slate-50">Reset</a>
    </div>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="rounded-2xl border border-dashed bg-white/50 p-12 text-center text-slate-500">Keine Projekte gefunden.</div>
<?php else: ?>
  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <?php foreach ($rows as $r): ?>
      <div class="group relative overflow-hidden rounded-2xl border bg-gradient-card p-5 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-elegant">
        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-primary"></div>
        <div class="flex items-start justify-between gap-2">
          <a href="project.php?id=<?= (int)$r['id'] ?>" class="min-w-0 flex-1">
            <h3 class="truncate text-lg font-semibold group-hover:text-indigo-600"><?= e($r['name']) ?></h3>
            <?php if ($r['customer']): ?><p class="truncate text-xs font-medium text-violet-700">🏷 <?= e($r['customer']) ?></p><?php endif; ?>
          </a>
          <?php if ((int)$r['user_id'] === (int)$user['id'] || $isAdmin): ?>
            <div class="flex shrink-0 gap-1 opacity-0 transition-opacity group-hover:opacity-100">
              <button type="button" class="rounded p-1 hover:bg-slate-100" title="Bearbeiten"
                onclick='openEdit(<?= json_encode([
                  "id"=>$r["id"],"name"=>$r["name"],"description"=>$r["description"],"customer"=>$r["customer"],
                  "users"=>$projectAccess[(int)$r["id"]] ?? []
                ], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'>✏️</button>
              <form method="post" onsubmit="return confirm('Projekt und alle Einträge löschen?')" class="inline">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="rounded p-1 hover:bg-rose-50" title="Löschen">🗑️</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
        <?php if ($r['description']): ?>
          <p class="mt-2 line-clamp-2 text-sm text-slate-600"><?= e($r['description']) ?></p>
        <?php endif; ?>
        <div class="mt-3 text-xs text-slate-500">
          <?php if ($r['last_activity']): ?>
            Letzte Aktivität: <strong class="text-slate-800"><?= e(date('d.m.Y H:i', strtotime($r['last_activity']))) ?></strong>
          <?php else: ?>
            Erstellt: <strong class="text-slate-800"><?= e(date('d.m.Y', strtotime($r['created_at']))) ?></strong>
          <?php endif; ?>
        </div>
        <div class="mt-3 flex items-center justify-between border-t pt-3 text-xs">
          <div class="flex gap-3">
            <span><strong><?= (int)$r['entry_count'] ?></strong> <span class="text-slate-500">Einträge</span></span>
            <span><strong><?= rtrim(rtrim(number_format((float)$r['total_hours'], 1, ',', '.'), '0'), ',') ?: '0' ?></strong> <span class="text-slate-500">Std</span></span>
          </div>
          <span class="text-slate-500">von <?= e($r['owner_name']) ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<dialog id="dlg-new" class="rounded-2xl p-0 backdrop:bg-black/40">
  <form method="post" class="w-[min(92vw,460px)] space-y-3 p-5">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="create" id="dlg-action">
    <input type="hidden" name="id" value="" id="dlg-id">
    <h2 class="text-lg font-semibold" id="dlg-title">Neues Projekt</h2>
    <label class="block text-sm">Name<input name="name" id="f-name" required maxlength="150" class="mt-1 w-full rounded border px-3 py-2"></label>
    <label class="block text-sm">Kunde<input name="customer" id="f-cust" maxlength="150" class="mt-1 w-full rounded border px-3 py-2"></label>
    <label class="block text-sm">Beschreibung<textarea name="description" id="f-desc" rows="4" maxlength="2000" class="mt-1 w-full rounded border px-3 py-2"></textarea></label>
    <div class="block text-sm" id="f-users-wrap" data-me="<?= (int)$user['id'] ?>">
      <div class="mb-1 flex items-center justify-between">
        <span class="font-medium">Zugriff</span>
        <label class="flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" id="select-all-users">
          Alle auswählen
        </label>
      </div>
      <div class="max-h-48 overflow-y-auto rounded border bg-white p-2">
        <?php foreach ($allUsers as $u): ?>
          <label class="flex cursor-pointer items-center gap-2 rounded px-1 py-1 hover:bg-slate-50">
            <input type="checkbox" name="users[]" value="<?= (int)$u['id'] ?>"
              <?= (int)$u['id']===(int)$user['id'] ? 'checked' : '' ?>>
            <span>
              <?= e($u['display_name']) ?>
              <?= (int)$u['id']===(int)$user['id'] ? '<span class="text-xs text-slate-500">(du)</span>' : '' ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <span class="mt-1 block text-xs text-slate-500">Du wirst automatisch hinzugefügt.</span>
    </div>
    <div class="flex justify-end gap-2 pt-2">
      <button type="button" onclick="document.getElementById('dlg-new').close()" class="rounded border px-3 py-2">Abbrechen</button>
      <button class="rounded bg-gradient-primary px-4 py-2 text-white">Speichern</button>
    </div>
  </form>
</dialog>
<script>
function updateSelectAllState(){
  var cbs = document.querySelectorAll('input[name="users[]"]');
  var allChecked = cbs.length > 0 && Array.prototype.every.call(cbs, function(cb){ return cb.checked; });
  document.getElementById('select-all-users').checked = allChecked;
}
function setUserCheckboxes(ids){
  var set = (ids || []).map(function(x){ return parseInt(x,10); });
  document.querySelectorAll('input[name="users[]"]').forEach(function(cb){
    cb.checked = set.indexOf(parseInt(cb.value,10)) !== -1;
  });
  updateSelectAllState();
}
function openEdit(r){
  document.getElementById('dlg-title').textContent = 'Projekt bearbeiten';
  document.getElementById('dlg-action').value = 'update';
  document.getElementById('dlg-id').value = r.id;
  document.getElementById('f-name').value = r.name || '';
  document.getElementById('f-cust').value = r.customer || '';
  document.getElementById('f-desc').value = r.description || '';
  setUserCheckboxes(r.users || []);
  updateSelectAllState();
  document.getElementById('dlg-new').showModal();
}
document.querySelectorAll('input[name="users[]"]').forEach(function(cb){
  cb.addEventListener('change', updateSelectAllState);
});
document.getElementById('select-all-users').addEventListener('change', function(){
  var checked = this.checked;
  document.querySelectorAll('input[name="users[]"]').forEach(function(cb){
    cb.checked = checked;
  });
});
document.getElementById('dlg-new').addEventListener('close', function(){
  document.getElementById('dlg-title').textContent = 'Neues Projekt';
  document.getElementById('dlg-action').value = 'create';
  document.getElementById('dlg-id').value = '';
  document.getElementById('f-name').value = '';
  document.getElementById('f-cust').value = '';
  document.getElementById('f-desc').value = '';
  var meId = document.getElementById('f-users-wrap').getAttribute('data-me');
  document.querySelectorAll('input[name="users[]"]').forEach(function(cb){
    cb.checked = (cb.value === meId);
  });
  document.getElementById('select-all-users').checked = false;
});
updateSelectAllState();
</script>
<?php layout_end();
