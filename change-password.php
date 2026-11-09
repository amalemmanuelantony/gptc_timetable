<?php
require __DIR__ . '/app.php';
requireRole(['user']);
$college = collegeName($pdo); $error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user']['id']]);
    $passwordHash = $stmt->fetchColumn();
    if (!$passwordHash || !password_verify($currentPassword, $passwordHash)) $error = 'Current Password is incorrect.';
    elseif (strlen($newPassword) < 8) $error = 'New Password must be at least 8 characters.';
    elseif ($newPassword !== $confirmPassword) $error = 'The new passwords do not match.';
    elseif (password_verify($newPassword, $passwordHash)) $error = 'Choose a password different from your current password.';
    else {
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $_SESSION['user']['id']]);
        logUserActivity('password_change', 'Password changed', 'User changed their password.');
        session_regenerate_id(true);
        $success = 'Password changed successfully.';
    }
}
appLayoutStart('Change Password', $college, 'change-password'); ?>
<main class="dashboard narrow"><p class="eyebrow">Account security</p><h1>Change Password</h1><p class="muted">Enter your current password, then choose a new password of at least 8 characters.</p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="alert" aria-live="assertive"><?= h($success) ?></div><?php endif; ?>
<section class="panel"><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><label>Current Password<input type="password" name="current_password" autocomplete="current-password" required></label><label>New Password<input type="password" name="new_password" minlength="8" autocomplete="new-password" required></label><label>Confirm New Password<input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></label><div class="form-actions"><button class="button" type="submit" name="action" value="change_password">Change Password</button></div></form></section>
</main><?php appLayoutEnd(); ?>
