<?php
require __DIR__ . '/app.php'; requireRole(['admin']);
$college = collegeName($pdo); $error = ''; $success = '';
$exists = (bool)$pdo->query("SHOW TABLES LIKE 'departments'")->fetchColumn();
if ($exists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            try {
                $stmt = $pdo->prepare('DELETE FROM departments WHERE id = ?'); $stmt->execute([$id]);
                $success = $stmt->rowCount() ? 'Department deleted.' : 'Department was not found.';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') $error = 'This department is used by a course and cannot be deleted.';
                else throw $e;
            }
        } else $error = 'Invalid department.';
    } else {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
        $name = trim($_POST['department_name'] ?? '');
        $shortName = trim($_POST['department_short_name'] ?? '');
        $code = trim($_POST['department_code'] ?? '');
        $program = trim($_POST['department_program_name'] ?? '');
        if ($name === '' || $shortName === '' || $code === '') $error = 'Fill in Department Name, Department Short Name, and Department Code.';
        elseif (mb_strlen($name) > 150 || mb_strlen($shortName) > 80 || mb_strlen($code) > 30 || mb_strlen($program) > 150) $error = 'One or more values exceed the allowed length.';
        else {
            $programValue = $program === '' ? null : $program;
            try {
                if ($id) {
                    $stmt = $pdo->prepare('UPDATE departments SET department_name = ?, department_short_name = ?, department_code = ?, department_program_name = ? WHERE id = ?');
                    $stmt->bindValue(1, $name); $stmt->bindValue(2, $shortName); $stmt->bindValue(3, $code);
                    $stmt->bindValue(4, $programValue, $programValue === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $stmt->bindValue(5, (int)$id, PDO::PARAM_INT); $stmt->execute(); $success = 'Department updated.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO departments (department_name, department_short_name, department_code, department_program_name) VALUES (?, ?, ?, ?)');
                    $stmt->bindValue(1, $name); $stmt->bindValue(2, $shortName); $stmt->bindValue(3, $code);
                    $stmt->bindValue(4, $programValue, $programValue === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $stmt->execute(); $success = 'Department added.';
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') $error = 'That department code is already in use.';
                else throw $e;
            }
        }
    }
}
$editing = null;
if ($exists && isset($_GET['edit'])) {
    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
    if ($editId) { $stmt = $pdo->prepare('SELECT * FROM departments WHERE id = ?'); $stmt->execute([$editId]); $editing = $stmt->fetch() ?: null; }
}
$departments = $exists ? $pdo->query('SELECT * FROM departments ORDER BY department_name, department_code')->fetchAll() : [];
appLayoutStart('Departments', $college, 'departments');
?>
<main class="dashboard narrow"><p class="eyebrow">Department directory</p><h1>Departments</h1><p class="muted">Maintain the departments and program names used throughout the timetable system.</p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="status"><?= h($success) ?></div><?php endif; ?>
<?php if (!$exists): ?>
<section class="panel"><h2>Database setup required</h2><p class="muted">Import <code>migrations/001_create_departments.sql</code> and <code>migrations/002_department_program_optional.sql</code> in phpMyAdmin, then reload this page.</p></section>
<?php else: ?>
<section class="panel"><h2><?= $editing ? 'Edit department' : 'Add department' ?></h2>
<form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= h((string)($editing['id'] ?? '')) ?>">
<label>Department Name<input name="department_name" maxlength="150" value="<?= h($editing['department_name'] ?? '') ?>" required></label>
<label>Department Short Name<input name="department_short_name" maxlength="80" value="<?= h($editing['department_short_name'] ?? '') ?>" required></label>
<label>Department Code<input name="department_code" maxlength="30" value="<?= h($editing['department_code'] ?? '') ?>" required></label>
<label>Department Program Name <span class="hint">Optional</span><input name="department_program_name" maxlength="150" value="<?= h($editing['department_program_name'] ?? '') ?>"></label>
<div class="form-actions"><button class="button" type="submit" name="action" value="save"><?= $editing ? 'Update department' : 'Save department' ?></button><?php if ($editing): ?><a class="button button-quiet" href="departments.php">Cancel</a><?php endif; ?></div></form></section>
<section class="panel"><div class="section-heading"><h2>Departments</h2><span class="count-badge"><?= count($departments) ?></span></div>
<?php if (!$departments): ?><p class="muted">No departments added yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Department Name</th><th>Short Name</th><th>Code</th><th>Program Name</th><th>Actions</th></tr></thead><tbody><?php foreach ($departments as $department): ?><tr><td><?= h($department['department_name']) ?></td><td><?= h($department['department_short_name']) ?></td><td><code><?= h($department['department_code']) ?></code></td><td><?= h($department['department_program_name'] ?? '—') ?></td><td class="row-actions"><a class="text-link" href="departments.php?edit=<?= (int)$department['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this department?')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$department['id'] ?>"><button class="text-button" type="submit" name="action" value="delete">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php endif; ?></main><?php appLayoutEnd(); ?>
