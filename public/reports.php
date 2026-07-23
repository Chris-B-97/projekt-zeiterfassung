<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user    = require_login();
$isAdmin = (($user['role'] ?? '') === 'admin');

// Abrechnung in 0,5-Std-Schritten (pro Eintrag aufrunden)
function hours_billed($minutes) { return ceil(((int)$minutes) / 30) / 2; }
function fmt_hours($h) {
    $s = number_format((float)$h, 1, ',', '.');
    return rtrim(rtrim($s, '0'), ',') ?: '0';
}
function iso_week_key(DateTime $d) {
    return $d->format('o') . '-KW' . $d->format('W');
}
function period_key(DateTime $d, $view) {
    if ($view === 'year')  return $d->format('Y');
    if ($view === 'week')  return iso_week_key($d);
    return $d->format('Y-m');
}

$viewRaw = $_GET['view'] ?? 'month';
$view = in_array($viewRaw, ['week', 'month', 'year'], true) ? $viewRaw : 'month';
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to']   ?? ''));

// Sichtbare Projekte für den User
$sql = '
  SELECT e.project_id, e.minutes, e.created_at, p.name AS project_name
  FROM entries e
  JOIN projects p ON p.id = e.project_id
  WHERE (
    p.user_id = ?
    OR ? = \'admin\'
    OR EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id = p.id AND pu.user_id = ?)
  )';
$args = [$user['id'], $user['role'] ?? '', $user['id']];
if ($from !== '') { $sql .= ' AND e.created_at >= ?'; $args[] = $from . ' 00:00:00'; }
if ($to   !== '') { $sql .= ' AND e.created_at <= ?'; $args[] = $to   . ' 23:59:59'; }
$st = db()->prepare($sql);
$st->execute($args);
$entries = $st->fetchAll();

$perProject = [];
$perPeriod  = [];
$grandHours = 0.0;
$totalEntries = count($entries);

foreach ($entries as $e) {
    $h = hours_billed($e['minutes']);
    $grandHours += $h;
    $pid = (int)$e['project_id'];
    if (!isset($perProject[$pid])) $perProject[$pid] = ['name' => $e['project_name'], 'hours' => 0.0, 'count' => 0];
    $perProject[$pid]['hours'] += $h;
    $perProject[$pid]['count'] += 1;

    $k = period_key(new DateTime($e['created_at']), $view);
    if (!isset($perPeriod[$k])) $perPeriod[$k] = ['hours' => 0.0, 'count' => 0];
    $perPeriod[$k]['hours'] += $h;
    $perPeriod[$k]['count'] += 1;
}
uasort($perProject, fn($a,$b) => $b['hours'] <=> $a['hours']);
krsort($perPeriod);

$periodLabel = $view === 'week' ? 'Kalenderwoche' : ($view === 'year' ? 'Jahr' : 'Monat');

layout_start('Auswertung', $user);
?>
<div>
  <h1 class="text-3xl font-bold tracking-tight">Auswertung</h1>
  <p class="text-sm text-slate-500">Zeit- und Aktivitätsübersicht</p>
</div>

<form method="get" class="mt-4 flex flex-wrap items-center gap-2">
  <?php foreach ([['week','Woche'],['month','Monat'],['year','Jahr']] as $t): ?>
    <a href="?view=<?= $t[0] ?><?= $from?'&from='.e($from):'' ?><?= $to?'&to='.e($to):'' ?>"
       class="rounded-full px-4 py-1.5 text-sm font-medium <?= $view===$t[0]?'bg-gradient-primary text-white shadow-elegant':'border bg-white hover:bg-slate-50' ?>">
      <?= e($t[1]) ?>
    </a>
  <?php endforeach; ?>
</form>

<form method="get" class="mt-4 flex flex-wrap items-end gap-3 rounded-2xl border bg-white/60 p-4 backdrop-blur">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <label class="text-xs">Von <input type="date" name="from" value="<?= e($from) ?>" class="mt-1 block rounded border bg-white px-2 py-2 text-sm"></label>
  <label class="text-xs">Bis <input type="date" name="to"   value="<?= e($to)   ?>" class="mt-1 block rounded border bg-white px-2 py-2 text-sm"></label>
  <button class="rounded bg-gradient-primary px-4 py-2 text-sm text-white">Anwenden</button>
  <a href="reports.php?view=<?= e($view) ?>" class="rounded border bg-white px-3 py-2 text-sm">Reset</a>
</form>

<div class="mt-4 grid gap-4 sm:grid-cols-3">
  <div class="relative overflow-hidden rounded-2xl border bg-gradient-card p-5 shadow-sm">
    <div class="absolute inset-x-0 top-0 h-1 bg-gradient-primary"></div>
    <p class="text-xs uppercase tracking-wide text-slate-500">Projekte</p>
    <p class="mt-1 text-3xl font-bold text-gradient-primary"><?= count($perProject) ?></p>
  </div>
  <div class="relative overflow-hidden rounded-2xl border bg-gradient-card p-5 shadow-sm">
    <div class="absolute inset-x-0 top-0 h-1 bg-gradient-primary"></div>
    <p class="text-xs uppercase tracking-wide text-slate-500">Einträge gesamt</p>
    <p class="mt-1 text-3xl font-bold text-gradient-primary"><?= (int)$totalEntries ?></p>
  </div>
  <div class="relative overflow-hidden rounded-2xl border bg-gradient-card p-5 shadow-sm">
    <div class="absolute inset-x-0 top-0 h-1 bg-gradient-primary"></div>
    <p class="text-xs uppercase tracking-wide text-slate-500">Zeit gesamt</p>
    <p class="mt-1 text-3xl font-bold text-gradient-primary"><?= fmt_hours($grandHours) ?> Stunden</p>
  </div>
</div>

<section class="mt-6">
  <h2 class="mb-3 text-lg font-semibold">Gesamtzeit pro Projekt</h2>
  <div class="overflow-hidden rounded-2xl border bg-white shadow-sm">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-left"><tr><th class="px-4 py-2">Projekt</th><th class="px-4 py-2">Einträge</th><th class="px-4 py-2">Stunden</th></tr></thead>
      <tbody>
      <?php if (!$perProject): ?>
        <tr><td colspan="3" class="px-4 py-4 text-center text-slate-500">Keine Daten.</td></tr>
      <?php else: foreach ($perProject as $r): ?>
        <tr class="border-t"><td class="px-4 py-2 font-medium"><?= e($r['name']) ?></td><td class="px-4 py-2"><?= (int)$r['count'] ?></td><td class="px-4 py-2"><?= fmt_hours($r['hours']) ?></td></tr>
      <?php endforeach; ?>
        <tr class="border-t bg-indigo-50 font-semibold"><td class="px-4 py-2">Gesamt</td><td></td><td class="px-4 py-2"><?= fmt_hours($grandHours) ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="mt-6">
  <h2 class="mb-3 text-lg font-semibold">Auswertung nach <?= e($periodLabel) ?></h2>
  <div class="overflow-hidden rounded-2xl border bg-white shadow-sm">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-left"><tr><th class="px-4 py-2"><?= e($periodLabel) ?></th><th class="px-4 py-2">Einträge</th><th class="px-4 py-2">Stunden</th></tr></thead>
      <tbody>
      <?php if (!$perPeriod): ?>
        <tr><td colspan="3" class="px-4 py-4 text-center text-slate-500">Keine Daten.</td></tr>
      <?php else: foreach ($perPeriod as $k => $r): ?>
        <tr class="border-t"><td class="px-4 py-2 font-medium"><?= e($k) ?></td><td class="px-4 py-2"><?= (int)$r['count'] ?></td><td class="px-4 py-2"><?= fmt_hours($r['hours']) ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>

<div class="mt-6"><a href="projects.php" class="text-sm text-slate-500 hover:text-indigo-600">← Zurück zu Projekte</a></div>
<?php layout_end();
