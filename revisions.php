<?php
require __DIR__ . '/app.php'; requireRole(['admin']);
$college = collegeName($pdo); $error = ''; $success = '';
$exists = (bool)$pdo->query("SHOW TABLES LIKE 'revisions'")->fetchColumn();
if ($exists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            try {
                $stmt = $pdo->prepare('DELETE FROM revisions WHERE id = ?'); $stmt->execute([$id]);
                $success = $stmt->rowCount() ? 'Revision deleted.' : 'Revision was not found.';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') $error = 'This revision is used by a course type or course and cannot be deleted.';
                else throw $e;
            }
        } else $error = 'Invalid revision.';
    } else {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
        $name = trim($_POST['revision_name'] ?? '');
        $shortName = trim($_POST['revision_short_name'] ?? '');
        $year = trim($_POST['revision_year'] ?? '');
        if ($name === '' || $shortName === '' || $year === '') $error = 'Fill in all three revision fields.';
        elseif (mb_strlen($name) > 150 || mb_strlen($shortName) > 80 || mb_strlen($year) > 50) $error = 'One or more values exceed the allowed length.';
        else {
            if ($id) {
                $stmt = $pdo->prepare('UPDATE revisions SET revision_name = ?, revision_short_name = ?, revision_year = ? WHERE id = ?');
                $stmt->execute([$name, $shortName, $year, $id]); $success = 'Revision updated.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO revisions (revision_name, revision_short_name, revision_year) VALUES (?, ?, ?)');
                $stmt->execute([$name, $shortName, $year]); $success = 'Revision added.';
            }
        }
    }
}
$editing = null;
if ($exists && isset($_GET['edit'])) {
    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
    if ($editId) { $stmt = $pdo->prepare('SELECT * FROM revisions WHERE id = ?'); $stmt->execute([$editId]); $editing = $stmt->fetch() ?: null; }
}
$revisions = $exists ? $pdo->query('SELECT * FROM revisions ORDER BY revision_year DESC, revision_name')->fetchAll() : [];
appLayoutStart('Revisions', $college, 'revisions');
?>
<main class="dashboard narrow"><p class="eyebrow">Academic structure</p><h1>Revisions</h1><p class="muted">Maintain curriculum or syllabus revisions and their applicable year labels.</p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="status"><?= h($success) ?></div><?php endif; ?>
<?php if (!$exists): ?>
<section class="panel"><h2>Database setup required</h2><p class="muted">Import <code>migrations/004_create_revisions.sql</code> in phpMyAdmin, then reload this page.</p></section>
<?php else: ?>
<section class="panel"><h2><?= $editing ? 'Edit revision' : 'Add revision' ?></h2>
<form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= h((string)($editing['id'] ?? '')) ?>">
<label>Revision Name<input name="revision_name" maxlength="150" value="<?= h($editing['revision_name'] ?? '') ?>" required></label>
<label>Revision Short Name<input name="revision_short_name" maxlength="80" value="<?= h($editing['revision_short_name'] ?? '') ?>" required></label>
<label>Revision Year<input name="revision_year" maxlength="50" value="<?= h($editing['revision_year'] ?? '') ?>" placeholder="For example, 2025" required></label>
<div class="form-actions"><button class="button" type="submit" name="action" value="save"><?= $editing ? 'Update revision' : 'Save revision' ?></button><?php if ($editing): ?><a class="button button-quiet" href="revisions.php">Cancel</a><?php endif; ?></div></form></section>
<section class="panel"><div class="section-heading"><h2>Revisions</h2><span class="count-badge"><?= count($revisions) ?></span></div>
<?php if (!$revisions): ?><p class="muted">No revisions added yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Revision Name</th><th>Short Name</th><th>Year</th><th>Actions</th></tr></thead><tbody><?php foreach ($revisions as $revision): ?><tr><td><?= h($revision['revision_name']) ?></td><td><?= h($revision['revision_short_name']) ?></td><td><?= h($revision['revision_year']) ?></td><td class="row-actions"><a class="text-link" href="revisions.php?edit=<?= (int)$revision['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this revision?')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$revision['id'] ?>"><button class="text-button" type="submit" name="action" value="delete">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php endif; ?></main><?php appLayoutEnd(); ?>
