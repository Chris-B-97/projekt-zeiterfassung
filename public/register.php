<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

if (current_user()) { header('Location: projects.php'); exit; }

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    $name  = trim((string)($_POST['display_name'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Ungültige E-Mail.';
    elseif (strlen($pass) < 8) $err = 'Passwort min. 8 Zeichen.';
    elseif ($name === '' || mb_strlen($name) > 150) $err = 'Anzeigename erforderlich (max. 150).';
    else {
        try {
            $st = db()->prepare('INSERT INTO users (email, password_hash, display_name) VALUES (?,?,?)');
            $st->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $name]);
            login_user((int)db()->lastInsertId());
            header('Location: projects.php'); exit;
        } catch (PDOException $e) {
            $err = ($e->getCode() === '23000') ? 'E-Mail bereits registriert.' : 'Fehler bei Registrierung.';
        }
    }
}

layout_start('Registrieren', null);
?>
<div class="mx-auto max-w-md rounded-2xl border bg-white p-6 shadow-sm">
  <h1 class="text-2xl font-bold">Konto erstellen</h1>
  <?php if ($err): ?><p class="mt-3 rounded bg-rose-100 px-3 py-2 text-sm text-rose-800"><?= e($err) ?></p><?php endif; ?>
  <form method="post" class="mt-4 space-y-3">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div><label class="text-sm">Anzeigename</label><input name="display_name" required maxlength="150" class="mt-1 w-full rounded border px-3 py-2"></div>
    <div><label class="text-sm">E-Mail</label><input name="email" type="email" required class="mt-1 w-full rounded border px-3 py-2"></div>
    <div><label class="text-sm">Passwort (min. 8)</label><input name="password" type="password" required minlength="8" class="mt-1 w-full rounded border px-3 py-2"></div>
    <button class="w-full rounded bg-gradient-primary px-4 py-2 font-medium text-white shadow-elegant">Registrieren</button>
  </form>
  <p class="mt-3 text-sm text-slate-600">Schon Konto? <a href="login.php" class="text-indigo-600 hover:underline">Login</a></p>
</div>
<?php layout_end();
