<?php
require __DIR__ . '/app.php'; requireRole(['admin']);
$college = collegeName($pdo); $error = ''; $success = '';
$exists = (bool)$pdo->query("SHOW TABLES LIKE 'academic_years'")->fetchColumn();
if ($exists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) { $stmt = $pdo->prepare('DELETE FROM academic_years WHERE id = ?'); $stmt->execute([$id]); $success = $stmt->rowCount() ? 'Academic Year deleted.' : 'Academic Year was not found.'; }
        else $error = 'Invalid Academic Year.';
    } else {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
        $name1 = trim($_POST['ay_name_1'] ?? ''); $name2 = trim($_POST['ay_name_2'] ?? ''); $name3 = trim($_POST['ay_name_3'] ?? '');
        if ($name1 === '' || $name2 === '' || $name3 === '') $error = 'Fill in all three Academic Year names.';
        elseif (mb_strlen($name1) > 100 || mb_strlen($name2) > 100 || mb_strlen($name3) > 100) $error = 'Each Academic Year name must be 100 characters or fewer.';
        else {
            if ($id) { $stmt = $pdo->prepare('UPDATE academic_years SET ay_name_1 = ?, ay_name_2 = ?, ay_name_3 = ? WHERE id = ?'); $stmt->execute([$name1, $name2, $name3, $id]); $success = 'Academic Year updated.'; }
            else { $stmt = $pdo->prepare('INSERT INTO academic_years (ay_name_1, ay_name_2, ay_name_3) VALUES (?, ?, ?)'); $stmt->execute([$name1, $name2, $name3]); $success = 'Academic Year added.'; }
        }
    }
}
$editing = null;
if ($exists && isset($_GET['edit'])) { $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT); if ($editId) { $stmt = $pdo->prepare('SELECT * FROM academic_years WHERE id = ?'); $stmt->execute([$editId]); $editing = $stmt->fetch() ?: null; } }
$rows = $exists ? $pdo->query('SELECT * FROM academic_years ORDER BY id DESC')->fetchAll() : [];
appLayoutStart('Academic Year', $college, 'academic-years'); ?>
<main class="dashboard narrow"><p class="eyebrow">Academic structure</p><h1>Academic Year</h1><p class="muted">Maintain the three Academic Year display names.</p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="alert" aria-live="assertive"><?= h($success) ?></div><?php endif; ?>
<?php if (!$exists): ?><section class="panel"><h2>Database setup required</h2><p class="muted">Import <code>migrations/013_create_academic_years.sql</code> in phpMyAdmin, then reload.</p></section><?php else: ?>
<section class="panel"><h2><?= $editing ? 'Edit Academic Year' : 'Add Academic Year' ?></h2><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= h((string)($editing['id'] ?? '')) ?>">
<label>AY Name 1<input name="ay_name_1" maxlength="100" value="<?= h($editing['ay_name_1'] ?? '') ?>" required></label><label>AY Name 2<input name="ay_name_2" maxlength="100" value="<?= h($editing['ay_name_2'] ?? '') ?>" required></label><label>AY Name 3<input name="ay_name_3" maxlength="100" value="<?= h($editing['ay_name_3'] ?? '') ?>" required></label>
<div class="form-actions"><button class="button" type="submit" name="action" value="save"><?= $editing ? 'Update Academic Year' : 'Save Academic Year' ?></button><?php if ($editing): ?><a class="button button-quiet" href="academic-years.php">Cancel</a><?php endif; ?></div></form></section>
<section class="panel"><div class="section-heading"><h2>Academic Years</h2><span class="count-badge"><?= count($rows) ?></span></div><?php if (!$rows): ?><p class="muted">No Academic Years added yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>AY Name 1</th><th>AY Name 2</th><th>AY Name 3</th><th>Actions</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= h($row['ay_name_1']) ?></td><td><?= h($row['ay_name_2']) ?></td><td><?= h($row['ay_name_3']) ?></td><td class="row-actions"><a class="text-link" href="academic-years.php?edit=<?= (int)$row['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this Academic Year?')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="text-button" type="submit" name="action" value="delete">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?></main><?php appLayoutEnd(); ?>
