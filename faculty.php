<?php
require __DIR__ . '/app.php'; requireRole(['admin']);
$college = collegeName($pdo); $error = ''; $success = '';
$facultyExists = (bool)$pdo->query("SHOW TABLES LIKE 'faculty'")->fetchColumn();
$departmentsExist = (bool)$pdo->query("SHOW TABLES LIKE 'departments'")->fetchColumn();
$designationsExist = (bool)$pdo->query("SHOW TABLES LIKE 'designations'")->fetchColumn();
$ready = $facultyExists && $departmentsExist && $designationsExist;
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) $error = 'Choose a valid faculty record.';
        else { $stmt = $pdo->prepare('DELETE FROM faculty WHERE id = ?'); $stmt->execute([$id]); $success = $stmt->rowCount() ? 'Faculty record deleted.' : 'Faculty record was not found.'; }
    } else {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
        $name = trim($_POST['faculty_name'] ?? ''); $shortName = trim($_POST['faculty_short_name'] ?? '');
        $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT) ?: 0;
        $designationId = filter_input(INPUT_POST, 'designation_id', FILTER_VALIDATE_INT) ?: 0;
        if ($name === '' || $shortName === '' || !$departmentId || !$designationId) $error = 'Fill in the faculty name, short name, department, and designation.';
        elseif (mb_strlen($name) > 150 || mb_strlen($shortName) > 80) $error = 'Faculty Name must be at most 150 characters and Short Name at most 80 characters.';
        else {
            $stmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM departments WHERE id = ?) + (SELECT COUNT(*) FROM designations WHERE id = ?)'); $stmt->execute([$departmentId, $designationId]);
            if ((int)$stmt->fetchColumn() !== 2) $error = 'Choose an existing Department and Designation.';
            else {
                try {
                    if ($id) { $stmt = $pdo->prepare('UPDATE faculty SET faculty_name = ?, faculty_short_name = ?, department_id = ?, designation_id = ? WHERE id = ?'); $stmt->execute([$name, $shortName, $departmentId, $designationId, $id]); $success = 'Faculty record updated.'; }
                    else { $stmt = $pdo->prepare('INSERT INTO faculty (faculty_name, faculty_short_name, department_id, designation_id) VALUES (?, ?, ?, ?)'); $stmt->execute([$name, $shortName, $departmentId, $designationId]); $success = 'Faculty record added.'; }
                } catch (PDOException $e) { if ($e->getCode() === '23000') $error = 'Could not save this faculty record. Check the selected Department and Designation.'; else throw $e; }
            }
        }
    }
}
$editing = null;
if ($ready && isset($_GET['edit'])) { $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT); if ($editId) { $stmt = $pdo->prepare('SELECT * FROM faculty WHERE id = ?'); $stmt->execute([$editId]); $editing = $stmt->fetch() ?: null; } }
$departments = $departmentsExist ? $pdo->query('SELECT id, department_name, department_code FROM departments ORDER BY department_name')->fetchAll() : [];
$designations = $designationsExist ? $pdo->query('SELECT id, designation_name, designation_short_name FROM designations ORDER BY designation_name')->fetchAll() : [];
$faculty = $ready ? $pdo->query('SELECT f.*, d.department_name, d.department_code, g.designation_name, g.designation_short_name FROM faculty f JOIN departments d ON d.id = f.department_id JOIN designations g ON g.id = f.designation_id ORDER BY f.faculty_name')->fetchAll() : [];
appLayoutStart('Faculty', $college, 'faculty'); ?>
<main class="dashboard narrow"><p class="eyebrow">People directory</p><h1>Faculty</h1><p class="muted">Maintain faculty names, departments, and designations.</p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="status"><?= h($success) ?></div><?php endif; ?>
<?php if (!$ready): ?><section class="panel"><h2>Database setup required</h2><p class="muted">Import <code>migrations/024_faculty_designations.sql</code> in phpMyAdmin, then reload.</p></section><?php elseif (!$departments || !$designations): ?><section class="panel"><h2>Setup incomplete</h2><p class="muted">Add at least one Department and one Designation before adding faculty.</p></section><?php else: ?>
<section class="panel"><h2><?= $editing ? 'Edit Faculty' : 'Add Faculty' ?></h2><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= h((string)($editing['id'] ?? '')) ?>">
<label>Name<input name="faculty_name" maxlength="150" value="<?= h($editing['faculty_name'] ?? '') ?>" required></label><label>Short Name<input name="faculty_short_name" maxlength="80" value="<?= h($editing['faculty_short_name'] ?? '') ?>" required></label>
<label>Department<select class="js-select2" data-placeholder="Select Department" name="department_id" required><option value="">Select Department</option><?php foreach ($departments as $department): ?><option value="<?= (int)$department['id'] ?>" <?= (string)($editing['department_id'] ?? '') === (string)$department['id'] ? 'selected' : '' ?>><?= h($department['department_name'] . ' · ' . $department['department_code']) ?></option><?php endforeach; ?></select></label>
<label>Designation<select class="js-select2" data-placeholder="Select Designation" name="designation_id" required><option value="">Select Designation</option><?php foreach ($designations as $designation): ?><option value="<?= (int)$designation['id'] ?>" <?= (string)($editing['designation_id'] ?? '') === (string)$designation['id'] ? 'selected' : '' ?>><?= h($designation['designation_name'] . ' · ' . $designation['designation_short_name']) ?></option><?php endforeach; ?></select></label>
<div class="form-actions"><button class="button" type="submit" name="action" value="save"><?= $editing ? 'Update Faculty' : 'Save Faculty' ?></button><?php if ($editing): ?><a class="button button-quiet" href="faculty.php">Cancel</a><?php endif; ?></div></form></section>
<section class="panel"><div class="section-heading"><h2>Faculty</h2><span class="count-badge"><?= count($faculty) ?></span></div><?php if (!$faculty): ?><p class="muted">No faculty records added yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Name</th><th>Short Name</th><th>Department</th><th>Designation</th><th>Actions</th></tr></thead><tbody><?php foreach ($faculty as $person): ?><tr><td><?= h($person['faculty_name']) ?></td><td><?= h($person['faculty_short_name']) ?></td><td><?= h($person['department_name'] . ' · ' . $person['department_code']) ?></td><td><?= h($person['designation_name'] . ' · ' . $person['designation_short_name']) ?></td><td class="row-actions"><a class="text-link" href="faculty.php?edit=<?= (int)$person['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this faculty record?')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$person['id'] ?>"><button class="text-button" type="submit" name="action" value="delete">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?></main><?php appLayoutEnd(); ?>
