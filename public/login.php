<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

if (current_user()) { header('Location: projects.php'); exit; }

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $st = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if ($u && password_verify($pass, $u['password_hash'])) {
        login_user((int)$u['id']);
        header('Location: projects.php'); exit;
    }
    $err = 'Ungültige Zugangsdaten.';
}

layout_start('Login', null);
?>
<div class="mx-auto max-w-md rounded-2xl border bg-white p-6 shadow-sm">
  <h1 class="text-2xl font-bold">Anmelden</h1>
  <?php if ($err): ?><p class="mt-3 rounded bg-rose-100 px-3 py-2 text-sm text-rose-800"><?= e($err) ?></p><?php endif; ?>
  <form method="post" class="mt-4 space-y-3">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div><label class="text-sm">E-Mail</label><input name="email" type="email" required class="mt-1 w-full rounded border px-3 py-2"></div>
    <div><label class="text-sm">Passwort</label><input name="password" type="password" required class="mt-1 w-full rounded border px-3 py-2"></div>
    <button class="w-full rounded bg-gradient-primary px-4 py-2 font-medium text-white shadow-elegant">Login</button>
  </form>
  <p class="mt-3 text-sm text-slate-600">Neu? <a href="register.php" class="text-indigo-600 hover:underline">Konto erstellen</a></p>
</div>
<?php layout_end();
