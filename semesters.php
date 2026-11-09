<?php
require __DIR__ . '/app.php'; requireRole(['admin']);
$college = collegeName($pdo); $error = ''; $success = '';
$exists = (bool)$pdo->query("SHOW TABLES LIKE 'semesters'")->fetchColumn();
if ($exists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            try {
                $stmt = $pdo->prepare('DELETE FROM semesters WHERE id = ?'); $stmt->execute([$id]);
                $success = $stmt->rowCount() ? 'Semester deleted.' : 'Semester was not found.';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') $error = 'This semester is used by a course and cannot be deleted.';
                else throw $e;
            }
        } else $error = 'Invalid semester.';
    } else {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
        $fullName = trim($_POST['semester_full_name'] ?? '');
        $shortName = trim($_POST['semester_short_name'] ?? '');
        $code = trim($_POST['semester_code'] ?? '');
        $year = trim($_POST['semester_year'] ?? '');
        $type = $_POST['semester_type'] ?? '';
        if ($fullName === '' || $shortName === '' || $code === '' || $year === '' || !in_array($type, ['Even', 'Odd'], true)) $error = 'Fill in all semester fields and choose Even or Odd Semester Type.';
        elseif (mb_strlen($fullName) > 150 || mb_strlen($shortName) > 80 || mb_strlen($code) > 30 || mb_strlen($year) > 50) $error = 'One or more values exceed the allowed length.';
        else {
            try {
                if ($id) {
                    $stmt = $pdo->prepare('UPDATE semesters SET semester_full_name = ?, semester_short_name = ?, semester_code = ?, semester_year = ?, semester_type = ? WHERE id = ?');
                    $stmt->execute([$fullName, $shortName, $code, $year, $type, $id]); $success = 'Semester updated.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO semesters (semester_full_name, semester_short_name, semester_code, semester_year, semester_type) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$fullName, $shortName, $code, $year, $type]); $success = 'Semester added.';
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') $error = 'That semester code is already in use.';
                else throw $e;
            }
        }
    }
}
$editing = null;
if ($exists && isset($_GET['edit'])) {
    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
    if ($editId) { $stmt = $pdo->prepare('SELECT * FROM semesters WHERE id = ?'); $stmt->execute([$editId]); $editing = $stmt->fetch() ?: null; }
}
$semesters = $exists ? $pdo->query('SELECT * FROM semesters ORDER BY semester_year, semester_full_name')->fetchAll() : [];
appLayoutStart('Semesters', $college, 'semesters');
?>
<main class="dashboard narrow"><p class="eyebrow">Academic structure</p><h1>Semesters</h1><p class="muted">Maintain the semester names, identifiers, and year labels used by the timetable system.</p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="status"><?= h($success) ?></div><?php endif; ?>
<?php if (!$exists): ?>
<section class="panel"><h2>Database setup required</h2><p class="muted">Import <code>migrations/003_create_semesters.sql</code> in phpMyAdmin, then reload this page.</p></section>
<?php else: ?>
<section class="panel"><h2><?= $editing ? 'Edit semester' : 'Add semester' ?></h2>
<form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= h((string)($editing['id'] ?? '')) ?>">
<label>Semester Full Name<input name="semester_full_name" maxlength="150" value="<?= h($editing['semester_full_name'] ?? '') ?>" required></label>
<label>Semester Short Name<input name="semester_short_name" maxlength="80" value="<?= h($editing['semester_short_name'] ?? '') ?>" required></label>
<label>Semester Code<input name="semester_code" maxlength="30" value="<?= h($editing['semester_code'] ?? '') ?>" required></label>
<label>Semester Year <span class="hint">For example, Year 1</span><input name="semester_year" maxlength="50" value="<?= h($editing['semester_year'] ?? '') ?>" placeholder="Year 1" required></label>
<label>Semester Type<select name="semester_type" required><option value="">Select semester type</option><option value="Even" <?= ($editing['semester_type'] ?? '') === 'Even' ? 'selected' : '' ?>>Even</option><option value="Odd" <?= ($editing['semester_type'] ?? '') === 'Odd' ? 'selected' : '' ?>>Odd</option></select></label>
<div class="form-actions"><button class="button" type="submit" name="action" value="save"><?= $editing ? 'Update semester' : 'Save semester' ?></button><?php if ($editing): ?><a class="button button-quiet" href="semesters.php">Cancel</a><?php endif; ?></div></form></section>
<section class="panel"><div class="section-heading"><h2>Semesters</h2><span class="count-badge"><?= count($semesters) ?></span></div>
<?php if (!$semesters): ?><p class="muted">No semesters added yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Full Name</th><th>Short Name</th><th>Code</th><th>Year</th><th>Type</th><th>Actions</th></tr></thead><tbody><?php foreach ($semesters as $semester): ?><tr><td><?= h($semester['semester_full_name']) ?></td><td><?= h($semester['semester_short_name']) ?></td><td><code><?= h($semester['semester_code']) ?></code></td><td><?= h($semester['semester_year']) ?></td><td><?= h($semester['semester_type'] ?? 'Not set') ?></td><td class="row-actions"><a class="text-link" href="semesters.php?edit=<?= (int)$semester['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this semester?')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$semester['id'] ?>"><button class="text-button" type="submit" name="action" value="delete">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php endif; ?></main><?php appLayoutEnd(); ?>
