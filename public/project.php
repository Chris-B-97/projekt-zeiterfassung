<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user    = require_login();
$isAdmin = (($user['role'] ?? '') === 'admin');
$pid     = (int)($_GET['id'] ?? 0);

// Abrechnung in 0,5-Stunden-Schritten (immer aufrunden)
function hours_billed($minutes) { return ceil(((int)$minutes) / 30) / 2; }
function fmt_hours($h) { return rtrim(rtrim(number_format($h, 1, ',', '.'), '0'), ',') ?: '0'; }
function hours_input_value($minutes) { return number_format(((int)$minutes) / 60, 2, '.', ''); }
function post_hours_to_minutes($v) {
    $h = (float)str_replace(',', '.', (string)$v);
    if ($h < 0) $h = 0;
    $h = round($h * 2) / 2; // auf 0,5 snappen
    return (int)round($h * 60);
}

// Projekt + Zugriffsprüfung: Owner ODER Admin ODER Mitglied in project_users
$st = db()->prepare('
  SELECT p.* FROM projects p
  WHERE p.id = ?
    AND (
      p.user_id = ?
      OR ? = \'admin\'
      OR EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id = p.id AND pu.user_id = ?)
    )
');
$st->execute([$pid, $user['id'], $user['role'] ?? '', $user['id']]);
$project = $st->fetch();
if (!$project) { http_response_code(404); die('Projekt nicht gefunden oder kein Zugriff.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'create_entry') {
        $title    = trim((string)($_POST['title'] ?? ''));
        $desc     = trim((string)($_POST['description'] ?? ''));
        $cause    = trim((string)($_POST['cause'] ?? ''));
        $measure  = trim((string)($_POST['measure'] ?? ''));
        $link     = trim((string)($_POST['link'] ?? ''));
        $customer = trim((string)($_POST['customer'] ?? ''));
        $minutes  = post_hours_to_minutes($_POST['hours'] ?? 0);
        if ($desc === '') flash_set('error', 'Beschreibung erforderlich.');
        elseif ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) flash_set('error', 'Ungültige URL.');
        elseif ($minutes < 0 || $minutes > 6000000) flash_set('error', 'Stunden außerhalb des Bereichs.');
        else {
            db()->prepare('INSERT INTO entries (project_id, user_id, title, description, cause, measure, link, customer, minutes) VALUES (?,?,?,?,?,?,?,?,?)')
              ->execute([$pid, $user['id'],
                $title !== '' ? $title : null, $desc,
                $cause !== '' ? $cause : null, $measure !== '' ? $measure : null,
                $link !== '' ? $link : null, $customer !== '' ? $customer : null, $minutes]);
            flash_set('ok', 'Eintrag gespeichert.');
        }
    } elseif ($action === 'update_entry') {
        $eid      = (int)($_POST['id'] ?? 0);
        $title    = trim((string)($_POST['title'] ?? ''));
        $desc     = trim((string)($_POST['description'] ?? ''));
        $cause    = trim((string)($_POST['cause'] ?? ''));
        $measure  = trim((string)($_POST['measure'] ?? ''));
        $link     = trim((string)($_POST['link'] ?? ''));
        $customer = trim((string)($_POST['customer'] ?? ''));
        $minutes  = post_hours_to_minutes($_POST['hours'] ?? 0);
        if ($desc === '') flash_set('error', 'Beschreibung erforderlich.');
        elseif ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) flash_set('error', 'Ungültige URL.');
        elseif ($minutes < 0 || $minutes > 6000000) flash_set('error', 'Stunden außerhalb des Bereichs.');
        else {
            db()->prepare('UPDATE entries SET title=?, description=?, cause=?, measure=?, link=?, customer=?, minutes=? WHERE id=? AND (user_id=? OR ?=\'admin\')')
              ->execute([
                $title !== '' ? $title : null, $desc,
                $cause !== '' ? $cause : null, $measure !== '' ? $measure : null,
                $link !== '' ? $link : null, $customer !== '' ? $customer : null, $minutes,
                $eid, $user['id'], $user['role'] ?? ''
              ]);
            flash_set('ok', 'Eintrag aktualisiert.');
        }
    } elseif ($action === 'delete_entry') {
        $eid = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM entries WHERE id=? AND (user_id=? OR ?=\'admin\')')->execute([$eid, $user['id'], $user['role'] ?? '']);
        flash_set('ok', 'Eintrag gelöscht.');
    }
    header('Location: project.php?id=' . $pid . (!empty($_GET['q']) || !empty($_GET['from']) || !empty($_GET['to']) ? '&' . http_build_query(array_intersect_key($_GET, array_flip(['q','from','to']))) : ''));
    exit;
}

$q    = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));

$sql  = 'SELECT e.*, u.display_name AS owner_name FROM entries e LEFT JOIN users u ON u.id=e.user_id WHERE e.project_id = ?';
$args = [$pid];
if ($q !== '') {
    $sql .= ' AND (e.title LIKE ? OR e.description LIKE ? OR e.cause LIKE ? OR e.measure LIKE ? OR e.customer LIKE ? OR u.display_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like, $like, $like);
}
if ($from !== '') { $sql .= ' AND e.created_at >= ?'; $args[] = $from . ' 00:00:00'; }
if ($to   !== '') { $sql .= ' AND e.created_at <= ?'; $args[] = $to   . ' 23:59:59'; }
$sql .= ' ORDER BY e.created_at DESC';
$st = db()->prepare($sql);
$st->execute($args);
$entries = $st->fetchAll();
$total = array_sum(array_column($entries, 'minutes'));
$total_hours = 0.0;
foreach ($entries as $en) { $total_hours += hours_billed($en['minutes']); }

layout_start($project['name'], $user);
flash_render();
?>
<a href="projects.php" class="text-sm text-slate-500 hover:text-indigo-600">← Projekte</a>

<div class="relative mt-3 overflow-hidden rounded-2xl border bg-gradient-card p-6 shadow-sm">
  <div class="absolute inset-x-0 top-0 h-1 bg-gradient-primary"></div>
  <div class="flex items-start justify-between gap-4">
    <div class="min-w-0">
      <h1 class="text-3xl font-bold tracking-tight"><?= e($project['name']) ?></h1>
      <?php if ($project['customer']): ?><p class="mt-1 text-sm"><span class="text-slate-500">Kunde:</span> <strong class="text-violet-700"><?= e($project['customer']) ?></strong></p><?php endif; ?>
      <?php if ($project['description']): ?><p class="mt-2 text-slate-600"><?= e($project['description']) ?></p><?php endif; ?>
    </div>
    <button type="button" onclick="document.getElementById('dlg-entry').showModal()" class="rounded-lg bg-gradient-primary px-4 py-2 text-white shadow-elegant">+ Neuer Eintrag</button>
  </div>
</div>

<form method="get" class="mt-4 mb-4 flex flex-wrap items-end gap-3 rounded-2xl border bg-white/60 p-4 backdrop-blur">
  <input type="hidden" name="id" value="<?= (int)$pid ?>">
  <label class="flex-1 min-w-[200px] text-xs">Suche
    <input name="q" value="<?= e($q) ?>" placeholder="🔍 Titel, Beschreibung, Ursache, Maßnahme…" class="mt-1 w-full rounded border bg-white px-3 py-2 text-sm">
  </label>
  <label class="text-xs">Von <input type="date" name="from" value="<?= e($from) ?>" class="mt-1 block rounded border bg-white px-2 py-2 text-sm"></label>
  <label class="text-xs">Bis <input type="date" name="to" value="<?= e($to) ?>" class="mt-1 block rounded border bg-white px-2 py-2 text-sm"></label>
  <button class="rounded border bg-white px-3 py-2 text-sm">Filtern</button>
  <a href="project.php?id=<?= (int)$pid ?>" class="rounded border bg-white px-3 py-2 text-sm">Reset</a>
  <div class="ml-auto rounded-lg bg-indigo-50 px-4 py-2 text-sm">
    Summe: <strong class="text-gradient-primary"><?= fmt_hours($total_hours) ?> Std</strong>
  </div>
</form>

<?php if (!$entries): ?>
  <div class="rounded-2xl border border-dashed bg-white/50 p-12 text-center text-slate-500">Keine Einträge.</div>
<?php else: foreach ($entries as $en): ?>
  <div class="group relative mb-3 overflow-hidden rounded-2xl border bg-gradient-card p-5 shadow-sm transition-all hover:shadow-elegant">
    <div class="absolute inset-y-0 left-0 w-1 bg-gradient-primary"></div>
    <div class="flex items-start justify-between gap-3 pl-2">
      <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2">
          <?php if ($en['title']): ?><h3 class="font-semibold"><?= e($en['title']) ?></h3><?php endif; ?>
          <span class="rounded-full bg-gradient-primary px-2.5 py-0.5 text-xs text-white">👤 <?= e($en['owner_name']) ?></span>
          <span class="text-xs text-slate-500"><?= e(date('d.m.Y H:i', strtotime($en['created_at']))) ?></span>
        </div>
        <p class="mt-2 whitespace-pre-wrap text-sm"><?= e($en['description']) ?></p>
        <div class="mt-3 flex flex-wrap gap-2 text-xs">
          <span class="rounded-full bg-indigo-50 px-2.5 py-1 font-medium text-indigo-700">⏱ <?= fmt_hours(hours_billed($en['minutes'])) ?> Std</span>
          <?php if ($en['customer']): ?><span class="rounded-full bg-violet-50 px-2.5 py-1 font-medium text-violet-700">🏷 <?= e($en['customer']) ?></span><?php endif; ?>
          <?php if ($en['link']): ?><a href="<?= e($en['link']) ?>" target="_blank" rel="noopener" class="rounded-full bg-slate-100 px-2.5 py-1 font-medium hover:bg-slate-200">↗ Link</a><?php endif; ?>
        </div>
        <?php if ($en['cause'] || $en['measure']): ?>
          <div class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
            <?php if ($en['cause']): ?><div class="rounded-lg border bg-white/60 p-3"><div class="text-xs font-semibold uppercase text-slate-500">Ursache</div><div class="mt-1 whitespace-pre-wrap"><?= e($en['cause']) ?></div></div><?php endif; ?>
            <?php if ($en['measure']): ?><div class="rounded-lg border bg-white/60 p-3"><div class="text-xs font-semibold uppercase text-slate-500">Maßnahme</div><div class="mt-1 whitespace-pre-wrap"><?= e($en['measure']) ?></div></div><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php if ((int)$en['user_id'] === (int)$user['id'] || $isAdmin): ?>
        <div class="flex flex-col gap-1">
          <button type="button" onclick="document.getElementById('dlg-edit-<?= (int)$en['id'] ?>').showModal()" class="rounded p-1 text-indigo-600 hover:bg-indigo-50" title="Bearbeiten">✏️</button>
          <form method="post" onsubmit="return confirm('Eintrag löschen?')" class="inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_entry">
            <input type="hidden" name="id" value="<?= (int)$en['id'] ?>">
            <button class="rounded p-1 text-rose-600 hover:bg-rose-50" title="Löschen">🗑️</button>
          </form>
        </div>

        <dialog id="dlg-edit-<?= (int)$en['id'] ?>" class="rounded-2xl p-0 backdrop:bg-black/40">
          <form method="post" class="w-[min(92vw,560px)] max-h-[90vh] space-y-3 overflow-y-auto p-5">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_entry">
            <input type="hidden" name="id" value="<?= (int)$en['id'] ?>">
            <h2 class="text-lg font-semibold">Eintrag bearbeiten</h2>
            <label class="block text-sm">Titel / Aufgabe<input name="title" maxlength="200" value="<?= e($en['title'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2"></label>
            <label class="block text-sm">Beschreibung / Details<textarea name="description" rows="3" required maxlength="2000" class="mt-1 w-full rounded border px-3 py-2"><?= e($en['description']) ?></textarea></label>
            <div class="grid grid-cols-2 gap-3">
              <label class="block text-sm">Kunde<input name="customer" value="<?= e($en['customer'] ?? '') ?>" maxlength="150" class="mt-1 w-full rounded border px-3 py-2"></label>
              <label class="block text-sm">Stunden (0,5er-Schritte)<input type="number" min="0" step="0.5" name="hours" value="<?= e(hours_input_value($en['minutes'])) ?>" class="mt-1 w-full rounded border px-3 py-2"></label>
            </div>
            <label class="block text-sm">Link<input type="url" name="link" maxlength="500" value="<?= e($en['link'] ?? '') ?>" class="mt-1 w-full rounded border px-3 py-2"></label>
            <label class="block text-sm">Ursache<textarea name="cause" rows="2" maxlength="2000" class="mt-1 w-full rounded border px-3 py-2"><?= e($en['cause'] ?? '') ?></textarea></label>
            <label class="block text-sm">Maßnahme<textarea name="measure" rows="2" maxlength="2000" class="mt-1 w-full rounded border px-3 py-2"><?= e($en['measure'] ?? '') ?></textarea></label>
            <div class="flex justify-end gap-2 pt-2">
              <button type="button" onclick="document.getElementById('dlg-edit-<?= (int)$en['id'] ?>').close()" class="rounded border px-3 py-2">Abbrechen</button>
              <button class="rounded bg-gradient-primary px-4 py-2 text-white">Speichern</button>
            </div>
          </form>
        </dialog>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; endif; ?>

<dialog id="dlg-entry" class="rounded-2xl p-0 backdrop:bg-black/40">
  <form method="post" class="w-[min(92vw,560px)] max-h-[90vh] space-y-3 overflow-y-auto p-5">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_entry">
    <h2 class="text-lg font-semibold">Neuer Eintrag / Aufgabe</h2>
    <label class="block text-sm">Titel / Aufgabe<input name="title" maxlength="200" class="mt-1 w-full rounded border px-3 py-2" placeholder="z. B. Bugfix Login-Formular"></label>
    <label class="block text-sm">Beschreibung / Details<textarea name="description" rows="3" required maxlength="2000" class="mt-1 w-full rounded border px-3 py-2"></textarea></label>
    <div class="grid grid-cols-2 gap-3">
      <label class="block text-sm">Kunde<input name="customer" value="<?= e($project['customer'] ?? '') ?>" maxlength="150" class="mt-1 w-full rounded border px-3 py-2"></label>
      <label class="block text-sm">Stunden (0,5er-Schritte)<input type="number" min="0" step="0.5" name="hours" value="0" class="mt-1 w-full rounded border px-3 py-2"></label>
    </div>
    <label class="block text-sm">Link (Projekt / Automatisierung)<input type="url" name="link" maxlength="500" placeholder="https://…" class="mt-1 w-full rounded border px-3 py-2"></label>
    <label class="block text-sm">Ursache<textarea name="cause" rows="2" maxlength="2000" class="mt-1 w-full rounded border px-3 py-2"></textarea></label>
    <label class="block text-sm">Maßnahme<textarea name="measure" rows="2" maxlength="2000" class="mt-1 w-full rounded border px-3 py-2"></textarea></label>
    <div class="flex justify-end gap-2 pt-2">
      <button type="button" onclick="document.getElementById('dlg-entry').close()" class="rounded border px-3 py-2">Abbrechen</button>
      <button class="rounded bg-gradient-primary px-4 py-2 text-white">Speichern</button>
    </div>
  </form>
</dialog>
<?php layout_end();
