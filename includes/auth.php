<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';

function current_user(): ?array {
    start_secure_session();
    if (empty($_SESSION['uid'])) return null;
    static $cached = null;
    if ($cached !== null) return $cached;
    $st = db()->prepare('SELECT id, email, display_name, role FROM users WHERE id = ?');
    $st->execute([(int)$_SESSION['uid']]);
    $u = $st->fetch();
    $cached = $u ?: null;
    return $cached;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

function login_user(int $uid): void {
    start_secure_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
}

function logout_user(): void {
    start_secure_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
