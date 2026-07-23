<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';

function layout_start(string $title, ?array $user = null): void {
    $appName = 'Projektzeit';
    ?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> – <?= e($appName) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  :root {
    --gradient-primary: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #ec4899 100%);
    --gradient-card: linear-gradient(180deg, rgba(255,255,255,0.9), rgba(250,250,255,0.6));
  }
  body { background: linear-gradient(180deg,#f8f8ff,#fdf2f8); min-height:100vh; }
  .bg-gradient-primary { background-image: var(--gradient-primary); }
  .bg-gradient-card { background-image: var(--gradient-card); }
  .text-gradient-primary {
    background-image: var(--gradient-primary);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  .shadow-elegant { box-shadow: 0 10px 30px -10px rgba(99,102,241,0.35); }
</style>
</head>
<body class="text-slate-900">
<header class="border-b bg-white/80 backdrop-blur sticky top-0 z-10">
  <div class="mx-auto max-w-6xl px-4 py-3 flex items-center justify-between gap-4">
    <a href="projects.php" class="flex items-center gap-2">
      <img src="../logo.png" alt="Projektzeit" class="h-12 w-auto">
    </a>
    <nav class="flex items-center gap-1 text-sm">
      <?php if ($user): ?>
        <a href="projects.php" class="rounded-lg px-3 py-2 hover:bg-slate-100">Projekte</a>
        <a href="reports.php" class="rounded-lg px-3 py-2 hover:bg-slate-100">Auswertung</a>
        <span class="mx-2 hidden h-6 w-px bg-slate-200 sm:block"></span>
        <span class="hidden text-xs text-slate-500 sm:inline"><?= e($user['email']) ?></span>
        <form method="post" action="logout.php" class="inline">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-100 hover:text-slate-900" title="Abmelden">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Abmelden</span>
          </button>
        </form>
      <?php else: ?>
        <a href="login.php" class="rounded-lg px-3 py-2 hover:bg-slate-100">Login</a>
        <a href="register.php" class="rounded-lg px-3 py-2 bg-gradient-primary text-white shadow-elegant">Registrieren</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="mx-auto max-w-6xl px-4 py-8">
<?php
}

function layout_end(): void {
    ?></main></body></html><?php
}

function flash_set(string $type, string $msg): void {
    start_secure_session();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_render(): void {
    start_secure_session();
    if (empty($_SESSION['flash'])) return;
    $f = $_SESSION['flash']; unset($_SESSION['flash']);
    $color = $f['type'] === 'error' ? 'bg-rose-100 text-rose-800 border-rose-200' : 'bg-emerald-100 text-emerald-800 border-emerald-200';
    echo '<div class="mb-4 rounded-lg border px-4 py-2 text-sm '.$color.'">'.e($f['msg']).'</div>';
}
