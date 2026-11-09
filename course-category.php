<?php
require __DIR__ . '/app.php'; requireRole(['admin']);
$college = collegeName($pdo); $error = ''; $success = '';
$exists = (bool)$pdo->query("SHOW TABLES LIKE 'course_categories'")->fetchColumn();
$schemaReady = false;
if ($exists) { $columns = $pdo->query('SHOW COLUMNS FROM course_categories')->fetchAll(PDO::FETCH_COLUMN); $schemaReady = in_array('course_category', $columns, true); }
$revisions = $schemaReady ? $pdo->query('SELECT id, revision_name, revision_year FROM revisions ORDER BY revision_year DESC, revision_name')->fetchAll() : [];
if ($schemaReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            try { $stmt = $pdo->prepare('DELETE FROM course_categories WHERE id = ?'); $stmt->execute([$id]); $success = $stmt->rowCount() ? 'Course category deleted.' : 'Course category was not found.'; }
            catch (PDOException $e) { if ($e->getCode() === '23000') $error = 'This course category is used by a course and cannot be deleted.'; else throw $e; }
        } else $error = 'Invalid course category.';
    } else {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
        $revisionId = filter_input(INPUT_POST, 'revision_id', FILTER_VALIDATE_INT) ?: 0;
        $category = trim($_POST['course_category'] ?? '');
        if (!$revisionId || $category === '') $error = 'Choose a revision and enter a course category.';
        elseif (mb_strlen($category) > 100) $error = 'Course Category must be 100 characters or fewer.';
        else {
            try {
                if ($id) { $stmt = $pdo->prepare('UPDATE course_categories SET revision_id = ?, course_category = ? WHERE id = ?'); $stmt->execute([$revisionId, $category, $id]); $success = 'Course category updated.'; }
                else { $stmt = $pdo->prepare('INSERT INTO course_categories (revision_id, course_category) VALUES (?, ?)'); $stmt->execute([$revisionId, $category]); $success = 'Course category added.'; }
            } catch (PDOException $e) { if ($e->getCode() === '23000') $error = 'That course category already exists for this revision.'; else throw $e; }
        }
        $revisions = $pdo->query('SELECT id, revision_name, revision_year FROM revisions ORDER BY revision_year DESC, revision_name')->fetchAll();
    }
}
$editing = null;
if ($schemaReady && isset($_GET['edit'])) { $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT); if ($editId) { $stmt = $pdo->prepare('SELECT * FROM course_categories WHERE id = ?'); $stmt->execute([$editId]); $editing = $stmt->fetch() ?: null; } }
$categories = $schemaReady ? $pdo->query('SELECT cc.*, r.revision_name, r.revision_year FROM course_categories cc JOIN revisions r ON r.id = cc.revision_id ORDER BY r.revision_year DESC, r.revision_name, cc.course_category')->fetchAll() : [];
appLayoutStart('Course Category', $college, 'course-category');
?>
<main class="dashboard narrow"><p class="eyebrow">Courses</p><h1>Course Category</h1><p class="muted">Define course categories available for each revision.</p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="status"><?= h($success) ?></div><?php endif; ?>
<?php if (!$exists): ?><section class="panel"><h2>Database setup required</h2><p class="muted">Import <code>migrations/008_separate_course_types_and_categories.sql</code> in phpMyAdmin, then reload this page.</p></section>
<?php elseif (!$schemaReady): ?><section class="panel"><h2>Database update required</h2><p class="muted">Run <code>migrations/007_course_category_and_type.sql</code> in phpMyAdmin to preserve existing category names.</p></section>
<?php elseif (!$revisions): ?><section class="panel"><h2>Add a revision first</h2><p class="muted">Course categories are linked to a revision. Add a revision before creating categories.</p><a class="button" href="revisions.php">Open Revisions</a></section>
<?php else: ?>
<section class="panel"><h2><?= $editing ? 'Edit course category' : 'Add course category' ?></h2><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= h((string)($editing['id'] ?? '')) ?>">
<label>Revision<select class="js-select2" data-placeholder="Select revision" name="revision_id" required><option value="">Select revision</option><?php foreach ($revisions as $revision): $selectedRevision=$_POST['revision_id'] ?? $editing['revision_id'] ?? ''; ?><option value="<?= (int)$revision['id'] ?>" <?= (string)$selectedRevision === (string)$revision['id'] ? 'selected' : '' ?>><?= h($revision['revision_name'] . ' · ' . $revision['revision_year']) ?></option><?php endforeach; ?></select></label>
<label>Course Category<input name="course_category" maxlength="100" value="<?= h($_POST['course_category'] ?? ($editing['course_category'] ?? '')) ?>" required></label>
<div class="form-actions"><button class="button" type="submit" name="action" value="save"><?= $editing ? 'Update course category' : 'Save course category' ?></button><?php if ($editing): ?><a class="button button-quiet" href="course-category.php">Cancel</a><?php endif; ?></div></form></section>
<section class="panel"><div class="section-heading"><h2>Course Categories</h2><span class="count-badge"><?= count($categories) ?></span></div><?php if (!$categories): ?><p class="muted">No course categories added yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Revision</th><th>Year</th><th>Course Category</th><th>Actions</th></tr></thead><tbody><?php foreach ($categories as $row): ?><tr><td><?= h($row['revision_name']) ?></td><td><?= h($row['revision_year']) ?></td><td><?= h($row['course_category']) ?></td><td class="row-actions"><a class="text-link" href="course-category.php?edit=<?= (int)$row['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this course category?')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="text-button" type="submit" name="action" value="delete">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php endif; ?></main><?php appLayoutEnd(); ?>
