<?php
require __DIR__ . '/app.php'; requireRole(['admin']);
$college = collegeName($pdo); $error = ''; $success = '';
$exists = (bool)$pdo->query("SHOW TABLES LIKE 'designations'")->fetchColumn();
if ($exists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) $error = 'Choose a valid designation.';
        else {
            try { $stmt = $pdo->prepare('DELETE FROM designations WHERE id = ?'); $stmt->execute([$id]); $success = $stmt->rowCount() ? 'Designation deleted.' : 'Designation was not found.'; }
            catch (PDOException $e) { if ($e->getCode() === '23000') $error = 'This designation is assigned to faculty and cannot be deleted.'; else throw $e; }
        }
    } else {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
        $name = trim($_POST['designation_name'] ?? ''); $shortName = trim($_POST['designation_short_name'] ?? '');
        if ($name === '' || $shortName === '') $error = 'Enter both the Designation Name and Designation Short Name.';
        elseif (mb_strlen($name) > 120 || mb_strlen($shortName) > 40) $error = 'Designation Name must be at most 120 characters and Short Name at most 40 characters.';
        else {
            try {
                if ($id) { $stmt = $pdo->prepare('UPDATE designations SET designation_name = ?, designation_short_name = ? WHERE id = ?'); $stmt->execute([$name, $shortName, $id]); $success = 'Designation updated.'; }
                else { $stmt = $pdo->prepare('INSERT INTO designations (designation_name, designation_short_name) VALUES (?, ?)'); $stmt->execute([$name, $shortName]); $success = 'Designation added.'; }
            } catch (PDOException $e) { if ($e->getCode() === '23000') $error = 'A designation with these details already exists.'; else throw $e; }
        }
    }
}
$editing = null;
if ($exists && isset($_GET['edit'])) { $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT); if ($editId) { $stmt = $pdo->prepare('SELECT * FROM designations WHERE id = ?'); $stmt->execute([$editId]); $editing = $stmt->fetch() ?: null; } }
$rows = $exists ? $pdo->query('SELECT * FROM designations ORDER BY designation_name')->fetchAll() : [];
appLayoutStart('Designation', $college, 'designations'); ?>
<main class="dashboard narrow"><p class="eyebrow">Faculty setup</p><h1>Designation</h1><p class="muted">Maintain the designations available for faculty records.</p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="status"><?= h($success) ?></div><?php endif; ?>
<?php if (!$exists): ?><section class="panel"><h2>Database setup required</h2><p class="muted">Import <code>migrations/024_faculty_designations.sql</code> in phpMyAdmin, then reload.</p></section><?php else: ?>
<section class="panel"><h2><?= $editing ? 'Edit Designation' : 'Add Designation' ?></h2><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= h((string)($editing['id'] ?? '')) ?>">
<label>Designation Name<input name="designation_name" maxlength="120" value="<?= h($editing['designation_name'] ?? '') ?>" required></label><label>Designation Short Name<input name="designation_short_name" maxlength="40" value="<?= h($editing['designation_short_name'] ?? '') ?>" required></label>
<div class="form-actions"><button class="button" type="submit" name="action" value="save"><?= $editing ? 'Update Designation' : 'Save Designation' ?></button><?php if ($editing): ?><a class="button button-quiet" href="designations.php">Cancel</a><?php endif; ?></div></form></section>
<section class="panel"><div class="section-heading"><h2>Designations</h2><span class="count-badge"><?= count($rows) ?></span></div><?php if (!$rows): ?><p class="muted">No designations added yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Designation Name</th><th>Short Name</th><th>Actions</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= h($row['designation_name']) ?></td><td><?= h($row['designation_short_name']) ?></td><td class="row-actions"><a class="text-link" href="designations.php?edit=<?= (int)$row['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this designation?')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="text-button" type="submit" name="action" value="delete">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?></main><?php appLayoutEnd(); ?>
