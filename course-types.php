<?php
require __DIR__ . '/app.php'; requireRole(['admin']);
$college = collegeName($pdo); $error = ''; $success = '';
$exists = (bool)$pdo->query("SHOW TABLES LIKE 'course_types'")->fetchColumn();
if ($exists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            try { $stmt = $pdo->prepare('DELETE FROM course_types WHERE id = ?'); $stmt->execute([$id]); $success = $stmt->rowCount() ? 'Course Type deleted.' : 'Course Type was not found.'; }
            catch (PDOException $e) { if ($e->getCode() === '23000') $error = 'This Course Type is used by a course and cannot be deleted.'; else throw $e; }
        } else $error = 'Invalid Course Type.';
    } else {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
        $type = trim($_POST['course_type'] ?? '');
        if ($type === '') $error = 'Enter a Course Type.';
        elseif (mb_strlen($type) > 100) $error = 'Course Type must be 100 characters or fewer.';
        else {
            try {
                if ($id) { $stmt = $pdo->prepare('UPDATE course_types SET course_type = ? WHERE id = ?'); $stmt->execute([$type, $id]); $success = 'Course Type updated.'; }
                else { $stmt = $pdo->prepare('INSERT INTO course_types (course_type) VALUES (?)'); $stmt->execute([$type]); $success = 'Course Type added.'; }
            } catch (PDOException $e) { if ($e->getCode() === '23000') $error = 'That Course Type already exists.'; else throw $e; }
        }
    }
}
$editing = null;
if ($exists && isset($_GET['edit'])) { $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT); if ($editId) { $stmt = $pdo->prepare('SELECT * FROM course_types WHERE id = ?'); $stmt->execute([$editId]); $editing = $stmt->fetch() ?: null; } }
$types = $exists ? $pdo->query('SELECT * FROM course_types ORDER BY course_type')->fetchAll() : [];
appLayoutStart('Course Types', $college, 'course-types');
?>
<main class="dashboard narrow"><p class="eyebrow">Courses</p><h1>Course Types</h1><p class="muted">Maintain the Course Type options available when adding a course.</p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="status"><?= h($success) ?></div><?php endif; ?>
<?php if (!$exists): ?><section class="panel"><h2>Database setup required</h2><p class="muted">Import <code>migrations/008_separate_course_types_and_categories.sql</code> in phpMyAdmin, then reload this page.</p></section>
<?php else: ?>
<section class="panel"><h2><?= $editing ? 'Edit Course Type' : 'Add Course Type' ?></h2><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= h((string)($editing['id'] ?? '')) ?>">
<label>Course Type<input name="course_type" maxlength="100" value="<?= h($_POST['course_type'] ?? ($editing['course_type'] ?? '')) ?>" required></label>
<div class="form-actions"><button class="button" type="submit" name="action" value="save"><?= $editing ? 'Update Course Type' : 'Save Course Type' ?></button><?php if ($editing): ?><a class="button button-quiet" href="course-types.php">Cancel</a><?php endif; ?></div></form></section>
<section class="panel"><div class="section-heading"><h2>Course Types</h2><span class="count-badge"><?= count($types) ?></span></div><?php if (!$types): ?><p class="muted">No Course Types added yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Course Type</th><th>Actions</th></tr></thead><tbody><?php foreach ($types as $row): ?><tr><td><?= h($row['course_type']) ?></td><td class="row-actions"><a class="text-link" href="course-types.php?edit=<?= (int)$row['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this Course Type?')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="text-button" type="submit" name="action" value="delete">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php endif; ?></main><?php appLayoutEnd(); ?>
