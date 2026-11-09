<?php
require __DIR__ . '/app.php';
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) { header('Location: index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $username = trim($_POST['username'] ?? ''); $password = $_POST['password'] ?? ''; $confirm = $_POST['confirm'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username)) $error = 'Username must be 3–80 characters: letters, numbers, dot, underscore, or hyphen.';
    elseif (strlen($password) < 12) $error = 'Choose a password with at least 12 characters.';
    elseif ($password !== $confirm) $error = 'The passwords do not match.';
    else {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        session_regenerate_id(true); $_SESSION['user'] = ['id' => (int)$pdo->lastInsertId(), 'username' => $username, 'role' => 'admin'];
        header('Location: dashboard.php'); exit;
    }
}
$college = collegeName($pdo); pageStart('Create administrator', $college);
?>
<main class="login-shell"><section class="login-card setup-card">
  <img class="seal" src="assets/college-seal.png" alt="College seal">
  <p class="eyebrow">Initial setup</p><h1>Create administrator</h1>
  <p class="muted">Create the first account. This one-time setup closes after the account is saved.</p>
  <?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
    <label>Administrator User ID<input name="username" autocomplete="username" required></label>
    <label>Password <span class="hint">12 characters minimum</span><input type="password" name="password" autocomplete="new-password" required minlength="12"></label>
    <label>Confirm password<input type="password" name="confirm" autocomplete="new-password" required minlength="12"></label>
    <button class="button" type="submit">Create administrator</button>
  </form>
</section></main><?php pageEnd(); ?>
