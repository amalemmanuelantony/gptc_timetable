<?php
require __DIR__ . '/app.php';
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) { header('Location: setup.php'); exit; }
if (!empty($_SESSION['user'])) { header('Location: dashboard.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $stmt = $pdo->prepare('SELECT id, username, display_name, department_id, password_hash, role, is_active FROM users WHERE username = ?');
    $stmt->execute([trim($_POST['username'] ?? '')]);
    $user = $stmt->fetch();
    if ($user && (int)$user['is_active'] === 1 && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        session_regenerate_id(true);
        $departmentId = $user['department_id'] === null ? null : (int)$user['department_id'];
        $_SESSION['user'] = ['id' => $user['id'], 'username' => $user['username'], 'display_name' => $user['display_name'] ?: $user['username'], 'department_id' => $departmentId, 'department_ids' => userDepartmentIds($pdo, (int)$user['id'], $departmentId), 'role' => $user['role']];
        logUserActivity('login', 'Login', 'User signed in successfully.');
        header('Location: dashboard.php'); exit;
    }
    $error = 'The User ID or password is incorrect.';
}
$college = collegeName($pdo);
pageStart('Sign in', $college);
?>
<main class="login-shell">
  <section class="login-card">
    <img class="seal" src="assets/college-seal.png" alt="Government Polytechnic College, Kottayam seal">
    <p class="eyebrow"><?= h($college) ?></p>
    <h1>Timetable<br>Management System</h1>
    <p class="muted">Sign in to continue to your workspace.</p>
    <?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="form-stack">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <label>User ID<input name="username" autocomplete="username" required autofocus></label>
      <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
      <button class="button" type="submit">Sign in</button>
    </form>
    <p class="login-note">Use the account assigned to your role.</p>
  </section>
</main>
<?php pageEnd(); ?>
