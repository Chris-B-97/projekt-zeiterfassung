<?php
require_once __DIR__ . '/../includes/auth.php';
header('Location: ' . (current_user() ? 'projects.php' : 'login.php'));
exit;
