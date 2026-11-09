<?php
require __DIR__ . '/app.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
checkCsrf(); logUserActivity('logout', 'Logout', 'User signed out.'); $_SESSION = [];
if (ini_get('session.use_cookies')) { $params = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']); }
session_destroy(); header('Location: index.php'); exit;
