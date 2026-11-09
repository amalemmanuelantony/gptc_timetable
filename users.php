<?php
require __DIR__ . '/app.php'; requireRole(['admin']);
$college = collegeName($pdo); $error = ''; $success = '';
$usersTable = (bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
$departmentsTable = (bool)$pdo->query("SHOW TABLES LIKE 'departments'")->fetchColumn();
$userDepartmentsTable = (bool)$pdo->query("SHOW TABLES LIKE 'user_departments'")->fetchColumn();
$columns = $usersTable ? $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) : [];
$ready = $usersTable && $departmentsTable && $userDepartmentsTable && in_array('display_name', $columns, true) && in_array('department_id', $columns, true) && in_array('is_active', $columns, true);
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'delete' || $action === 'toggle_login') {
        $targetId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        if (!$targetId) $error = 'Select a valid user.';
        elseif ($targetId === (int)$_SESSION['user']['id']) $error = 'You cannot disable or delete your own account.';
        else {
            $stmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ?'); $stmt->execute([$targetId]); $target = $stmt->fetch();
            if (!$target) $error = 'User was not found.';
            elseif ($target['role'] === 'admin' && (int)$target['is_active'] === 1 && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn() <= 1) $error = 'The last active administrator cannot be disabled or deleted.';
            elseif ($action === 'delete') { $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?'); $stmt->execute([$targetId]); $success = 'User deleted.'; }
            else { $stmt = $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?'); $stmt->execute([$targetId]); $success = (int)$target['is_active'] ? 'Login access denied for this user.' : 'Login access restored for this user.'; }
        }
    } elseif ($action === 'reset_password') {
        $targetId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $password = $_POST['password'] ?? ''; $confirm = $_POST['confirm_password'] ?? '';
        if (!$targetId) $error = 'Select a valid user.';
        elseif (strlen($password) < 8) $error = 'Password must be at least 8 characters.';
        elseif ($password !== $confirm) $error = 'The passwords do not match.';
        else { $targetStmt = $pdo->prepare('SELECT username,display_name FROM users WHERE id=?'); $targetStmt->execute([$targetId]); $targetUser = $targetStmt->fetch(); $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?'); $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]); if ($targetUser) logUserActivity('password_reset', 'User password reset', 'Reset the password for ' . ($targetUser['display_name'] ?: $targetUser['username']) . ' (' . $targetUser['username'] . ').', 'user', (string)$targetId); $success = 'Password reset successfully.'; }
    } else {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
        $userId = trim($_POST['user_id'] ?? ''); $name = trim($_POST['user_name'] ?? '');
        $departmentIds = $_POST['department_ids'] ?? [];
        if (!is_array($departmentIds)) $departmentIds = [];
        $departmentIds = array_values(array_unique(array_filter(array_map('intval', $departmentIds), static fn($departmentId) => $departmentId > 0)));
        $departmentId = $departmentIds[0] ?? null;
        $role = $_POST['role'] ?? ''; $password = $_POST['password'] ?? '';
        if (!preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $userId)) $error = 'User ID must be 3–80 characters using letters, numbers, dot, underscore, or hyphen.';
        elseif ($name === '' || mb_strlen($name) > 150) $error = 'Enter a User Name (up to 150 characters).';
        elseif (!in_array($role, ['admin', 'user'], true)) $error = 'Choose Admin or User as the role.';
        elseif ($role === 'user' && !$departmentIds) $error = 'Select at least one Department for User accounts.';
        elseif ($departmentIds) { $placeholders = implode(',', array_fill(0, count($departmentIds), '?')); $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE id IN ($placeholders)"); $stmt->execute($departmentIds); if ((int)$stmt->fetchColumn() !== count($departmentIds)) $error = 'Choose existing Departments.'; }
        if (!$error && !$id && strlen($password) < 8) $error = 'Password must be at least 8 characters.';
        elseif (!$error && $password !== '' && strlen($password) < 8) $error = 'Password must be at least 8 characters.';
        if (!$error && $id && $id === (int)$_SESSION['user']['id'] && $role !== 'admin') $error = 'You cannot remove administrator access from your own account.';
        if (!$error && $id && $role !== 'admin') {
            $stmt = $pdo->prepare("SELECT role, is_active FROM users WHERE id = ?"); $stmt->execute([$id]); $oldUser = $stmt->fetch();
            if ($oldUser && $oldUser['role'] === 'admin' && (int)$oldUser['is_active'] === 1 && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn() <= 1) $error = 'The last active administrator cannot be changed to a User.';
        }
        if (!$error) {
            try {
                $pdo->beginTransaction();
                if ($id) {
                    if ($password !== '') { $stmt = $pdo->prepare('UPDATE users SET username = ?, display_name = ?, department_id = ?, role = ?, password_hash = ? WHERE id = ?'); $stmt->execute([$userId, $name, $departmentId, $role, password_hash($password, PASSWORD_DEFAULT), $id]); }
                    else { $stmt = $pdo->prepare('UPDATE users SET username = ?, display_name = ?, department_id = ?, role = ? WHERE id = ?'); $stmt->execute([$userId, $name, $departmentId, $role, $id]); }
                    $managedUserId = $id;
                    $success = 'User updated.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO users (username, display_name, department_id, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 1)');
                    $stmt->execute([$userId, $name, $departmentId, password_hash($password, PASSWORD_DEFAULT), $role]); $managedUserId = (int)$pdo->lastInsertId();
                    $success = 'User added.';
                }
                $pdo->prepare('DELETE FROM user_departments WHERE user_id = ?')->execute([$managedUserId]);
                if ($role === 'user' && $departmentIds) {
                    $link = $pdo->prepare('INSERT INTO user_departments (user_id, department_id) VALUES (?, ?)');
                    foreach ($departmentIds as $assignedDepartmentId) $link->execute([$managedUserId, $assignedDepartmentId]);
                }
                $pdo->commit();
            } catch (PDOException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); if ($e->getCode() === '23000') $error = 'That User ID is already in use or a selected Department is invalid.'; else throw $e; }
        }
    }
}
$editing = null; $resetUser = null;
if ($ready && isset($_GET['edit'])) { $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT); if ($editId) { $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $stmt->execute([$editId]); $editing = $stmt->fetch() ?: null; if ($editing) { $stmt = $pdo->prepare('SELECT department_id FROM user_departments WHERE user_id = ? ORDER BY department_id'); $stmt->execute([$editId]); $editing['department_ids'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)); } } }
if ($ready && isset($_GET['reset'])) { $resetId = filter_input(INPUT_GET, 'reset', FILTER_VALIDATE_INT); if ($resetId) { $stmt = $pdo->prepare('SELECT id, username, display_name FROM users WHERE id = ?'); $stmt->execute([$resetId]); $resetUser = $stmt->fetch() ?: null; } }
$departments = $departmentsTable ? $pdo->query('SELECT id, department_name, department_code FROM departments ORDER BY department_name')->fetchAll() : [];
$users = $ready ? $pdo->query("SELECT u.id, u.username, u.display_name, u.department_id, u.role, u.is_active, COALESCE(GROUP_CONCAT(DISTINCT d.department_name ORDER BY d.department_name SEPARATOR ', '), '—') AS department_names FROM users u LEFT JOIN user_departments ud ON ud.user_id = u.id LEFT JOIN departments d ON d.id = ud.department_id GROUP BY u.id, u.username, u.display_name, u.department_id, u.role, u.is_active ORDER BY u.username")->fetchAll() : [];
appLayoutStart('Users', $college, 'users'); ?>
<main class="dashboard narrow"><p class="eyebrow">Access management</p><h1>Users</h1><p class="muted">Create and manage user accounts, login access, and passwords.</p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="alert" aria-live="assertive"><?= h($success) ?></div><?php endif; ?>
<?php if (!$ready): ?><section class="panel"><h2>Database update required</h2><p class="muted">Import <code>migrations/023_multi_department_users.sql</code> in phpMyAdmin, then reload.</p></section>
<?php else: ?>
<?php if ($resetUser): ?><section class="panel"><h2>Reset Password: <?= h($resetUser['display_name'] ?: $resetUser['username']) ?></h2><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$resetUser['id'] ?>"><label>New Password<input type="password" name="password" minlength="8" autocomplete="new-password" required></label><label>Confirm Password<input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></label><div class="form-actions"><button class="button" type="submit" name="action" value="reset_password">Reset Password</button><a class="button button-quiet" href="users.php">Cancel</a></div></form></section><?php endif; ?>
<section class="panel"><h2><?= $editing ? 'Edit User' : 'Add User' ?></h2><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= h((string)($editing['id'] ?? '')) ?>">
<label>User ID<input name="user_id" maxlength="80" value="<?= h($editing['username'] ?? '') ?>" autocomplete="username" required></label><label>User Name<input name="user_name" maxlength="150" value="<?= h($editing['display_name'] ?? '') ?>" required></label>
<label>Departments <span class="hint">Select one or more for a User account</span><select class="js-select2" data-placeholder="Select Departments" name="department_ids[]" multiple><?php foreach ($departments as $department): ?><option value="<?= (int)$department['id'] ?>" <?= in_array((int)$department['id'], $editing['department_ids'] ?? [], true) ? 'selected' : '' ?>><?= h($department['department_name'] . ' · ' . $department['department_code']) ?></option><?php endforeach; ?></select></label>
<label>Password <span class="hint"><?= $editing ? 'Leave blank to keep the current password' : 'At least 8 characters' ?></span><input type="password" name="password" minlength="<?= $editing ? '0' : '8' ?>" autocomplete="new-password" <?= $editing ? '' : 'required' ?>></label>
<label>User Role<select name="role" required><option value="">Select role</option><option value="admin" <?= ($editing['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option><option value="user" <?= ($editing['role'] ?? '') === 'user' ? 'selected' : '' ?>>User</option></select></label>
<div class="form-actions"><button class="button" type="submit" name="action" value="save"><?= $editing ? 'Update User' : 'Save User' ?></button><?php if ($editing): ?><a class="button button-quiet" href="users.php">Cancel</a><?php endif; ?></div></form></section>
<section class="panel"><div class="section-heading"><h2>User Accounts</h2><span class="count-badge"><?= count($users) ?></span></div><?php if (!$users): ?><p class="muted">No users added yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>User ID</th><th>User Name</th><th>Departments</th><th>Role</th><th>Login</th><th>Actions</th></tr></thead><tbody><?php foreach ($users as $user): ?><tr><td><code><?= h($user['username']) ?></code></td><td><?= h($user['display_name'] ?: $user['username']) ?></td><td><?= h($user['department_names']) ?></td><td><?= h(ucfirst($user['role'])) ?></td><td><?= (int)$user['is_active'] ? 'Allowed' : 'Denied' ?></td><td class="row-actions"><a class="text-link" href="users.php?edit=<?= (int)$user['id'] ?>">Edit</a><a class="text-link" href="users.php?reset=<?= (int)$user['id'] ?>">Reset Password</a><form method="post"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$user['id'] ?>"><button class="text-button" type="submit" name="action" value="toggle_login" <?= (int)$user['id'] === (int)$_SESSION['user']['id'] ? 'disabled title="You cannot deny your own login"' : '' ?>><?= (int)$user['is_active'] ? 'Deny Login' : 'Allow Login' ?></button></form><form method="post" onsubmit="return confirm('Delete this user permanently?')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$user['id'] ?>"><button class="text-button" type="submit" name="action" value="delete" <?= (int)$user['id'] === (int)$_SESSION['user']['id'] ? 'disabled title="You cannot delete your own account"' : '' ?>>Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php endif; ?></main><?php appLayoutEnd(); ?>
