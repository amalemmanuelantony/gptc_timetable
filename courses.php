<?php
require __DIR__ . '/app.php'; requireRole(['admin']);
$college = collegeName($pdo); $error = ''; $success = '';
$options = [
    'add' => ['title' => 'Add Course', 'description' => 'Create a course offering with its department, revision, semester, category, type, code, and weekly hours.'],
    'assign' => ['title' => 'Assign Course', 'description' => 'Course assignment will be designed in a later step.'],
    'view' => ['title' => 'View Courses', 'description' => 'Browse and maintain saved course offerings.'],
];
$selected = $_GET['option'] ?? '';
if (!isset($options[$selected])) $selected = '';
$title = $selected ? $options[$selected]['title'] : 'Courses';
$formatLabShortName = static function (string $courseName, string $shortName): string {
    $shortName = trim($shortName);
    if (preg_match('/\b(?:lab|laboratory)\b/i', $courseName)
        && preg_match('/L$/i', $shortName)
        && !preg_match('/LAB$/i', $shortName)) {
        $shortName = substr($shortName, 0, -1) . ' LAB';
    }
    return $shortName;
};
$tables = ['departments', 'revisions', 'semesters', 'course_categories', 'course_types', 'courses'];
$existingTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$missingTables = array_values(array_diff($tables, $existingTables));
$oldCategoryTable = in_array('course_types', $existingTables, true);
$oldCategoryColumn = false;
if ($oldCategoryTable) { $oldColumns = $pdo->query('SHOW COLUMNS FROM course_types')->fetchAll(PDO::FETCH_COLUMN); $oldCategoryColumn = in_array('course_category', $oldColumns, true); }
$ready = !$missingTables;
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) { $stmt = $pdo->prepare('DELETE FROM courses WHERE id = ?'); $stmt->execute([$id]); $success = $stmt->rowCount() ? 'Course deleted.' : 'Course was not found.'; }
        else $error = 'Invalid course.';
    } else {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
        $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT) ?: 0;
        $revisionId = filter_input(INPUT_POST, 'revision_id', FILTER_VALIDATE_INT) ?: 0;
        $semesterId = filter_input(INPUT_POST, 'semester_id', FILTER_VALIDATE_INT) ?: 0;
        $categoryId = filter_input(INPUT_POST, 'course_category_id', FILTER_VALIDATE_INT) ?: 0;
        $courseTypeId = filter_input(INPUT_POST, 'course_type_id', FILTER_VALIDATE_INT) ?: 0;
        $code = trim($_POST['course_code'] ?? ''); $name = trim($_POST['course_name'] ?? ''); $shortName = $formatLabShortName($name, (string)($_POST['course_short_name'] ?? '')); $hours = trim($_POST['hours_per_week'] ?? '');
        if (!$departmentId || !$revisionId || !$semesterId || !$categoryId || !$courseTypeId || $code === '' || $name === '' || $shortName === '' || $hours === '') $error = 'Complete all course fields.';
        elseif (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $code)) $error = 'Course Code can contain letters, numbers, hyphens, or underscores (up to 50 characters).';
        elseif (mb_strlen($name) > 180) $error = 'Course Name must be 180 characters or fewer.';
        elseif (mb_strlen($shortName) > 80) $error = 'Course Short Name must be 80 characters or fewer.';
        elseif (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $hours) || (float)$hours <= 0 || (float)$hours > 99.99) $error = 'Hours Per Week must be greater than 0 and no more than 99.99.';
        else {
            $duplicateSql = 'SELECT COUNT(*) FROM courses WHERE department_id = ? AND course_code = ?' . ($id ? ' AND id <> ?' : '');
            $duplicateValues = [$departmentId, $code];
            if ($id) $duplicateValues[] = $id;
            $duplicateCheck = $pdo->prepare($duplicateSql);
            $duplicateCheck->execute($duplicateValues);
            if ((int)$duplicateCheck->fetchColumn() > 0) $error = 'This Course Code is already used by that department.';
            $checks = [
                ['SELECT COUNT(*) FROM departments WHERE id = ?', $departmentId],
                ['SELECT COUNT(*) FROM revisions WHERE id = ?', $revisionId],
                ['SELECT COUNT(*) FROM semesters WHERE id = ?', $semesterId],
                ['SELECT COUNT(*) FROM course_types WHERE id = ?', $courseTypeId],
                ['SELECT COUNT(*) FROM course_categories WHERE id = ? AND revision_id = ?', [$categoryId, $revisionId]],
            ];
            $valid = true;
            foreach ($checks as [$sql, $values]) { $stmt = $pdo->prepare($sql); $stmt->execute(is_array($values) ? $values : [$values]); if (!(int)$stmt->fetchColumn()) $valid = false; }
            if (!$error && !$valid) $error = 'Choose existing department, revision, semester, course category, and course type.';
            elseif (!$error) {
                if ($id) {
                    $stmt = $pdo->prepare('UPDATE courses SET department_id = ?, revision_id = ?, semester_id = ?, course_category_id = ?, course_type_id = ?, course_code = ?, course_name = ?, course_short_name = ?, hours_per_week = ? WHERE id = ?');
                    try { $stmt->execute([$departmentId, $revisionId, $semesterId, $categoryId, $courseTypeId, $code, $name, $shortName, $hours, $id]); $success = 'Course updated.'; }
                    catch (PDOException $e) { if ($e->getCode() === '23000') $error = 'This Course Code is already used by that department.'; else throw $e; }
                } else {
                    $stmt = $pdo->prepare('INSERT INTO courses (department_id, revision_id, semester_id, course_category_id, course_type_id, course_code, course_name, course_short_name, hours_per_week) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    try { $stmt->execute([$departmentId, $revisionId, $semesterId, $categoryId, $courseTypeId, $code, $name, $shortName, $hours]); $success = 'Course added.'; }
                    catch (PDOException $e) { if ($e->getCode() === '23000') $error = 'This Course Code is already used by that department.'; else throw $e; }
                }
            }
        }
    }
}
$departments = $ready ? $pdo->query('SELECT id, department_name, department_code FROM departments ORDER BY department_name')->fetchAll() : [];
$revisions = $ready ? $pdo->query('SELECT id, revision_name, revision_year FROM revisions ORDER BY revision_year DESC, revision_name')->fetchAll() : [];
$semesters = $ready ? $pdo->query('SELECT id, semester_full_name, semester_code, semester_year FROM semesters ORDER BY semester_year, semester_full_name')->fetchAll() : [];
$categories = $ready ? $pdo->query('SELECT id, revision_id, course_category FROM course_categories ORDER BY course_category')->fetchAll() : [];
$courseTypes = $ready ? $pdo->query('SELECT id, course_type FROM course_types ORDER BY course_type')->fetchAll() : [];
$editing = null;
if ($ready && $selected === 'add' && isset($_GET['edit'])) { $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT); if ($editId) { $stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ?'); $stmt->execute([$editId]); $editing = $stmt->fetch() ?: null; } }
$courses = $ready ? $pdo->query('SELECT c.*, d.department_name, r.revision_name, s.semester_full_name, s.semester_code, s.semester_year, cc.course_category, ct.course_type FROM courses c JOIN departments d ON d.id = c.department_id JOIN revisions r ON r.id = c.revision_id JOIN semesters s ON s.id = c.semester_id JOIN course_categories cc ON cc.id = c.course_category_id JOIN course_types ct ON ct.id = c.course_type_id ORDER BY d.department_name, s.semester_year, s.semester_full_name, c.course_code')->fetchAll() : [];
appLayoutStart($title, $college, 'courses');
?>
<main class="dashboard">
<?php if (!$selected): ?>
  <p class="eyebrow">Academic structure</p><h1>Courses</h1><p class="muted">Choose a course management option.</p>
  <section class="tile-grid course-options"><?php foreach ($options as $key => $option): ?><a class="tile" href="courses.php?option=<?= h($key) ?>"><span class="tile-icon"><?= $key === 'add' ? '+' : ($key === 'assign' ? '↗' : '≡') ?></span><strong><?= h($option['title']) ?></strong><span><?= h($option['description']) ?></span><span class="tile-link">Open <?= h($option['title']) ?> →</span></a><?php endforeach; ?></section>
<?php elseif ($selected === 'assign'): ?>
  <a class="back-link" href="courses.php">← Courses</a><p class="eyebrow">Courses</p><h1>Assign Course</h1><section class="panel"><h2>Course assignment</h2><p class="muted">This workflow will be designed in a later step.</p></section>
<?php elseif (!$ready && $oldCategoryTable && $oldCategoryColumn): ?>
  <a class="back-link" href="courses.php">← Courses</a><p class="eyebrow">Courses</p><h1><?= h($options[$selected]['title']) ?></h1><section class="panel"><h2>Database update required</h2><p class="muted">Run <code>migrations/008_separate_course_types_and_categories.sql</code> in phpMyAdmin. It preserves category records and creates the separate Course Types table.</p></section>
<?php elseif (!$ready): ?>
  <a class="back-link" href="courses.php">← Courses</a><p class="eyebrow">Courses</p><h1><?= h($options[$selected]['title']) ?></h1><section class="panel"><h2>Database setup required</h2><p class="muted">Import the required migrations in order: 005, 006, 007, then 008. Reload this page after setup.</p></section>
<?php elseif ($selected === 'add'): ?>
  <a class="back-link" href="courses.php">← Courses</a><p class="eyebrow">Courses</p><h1><?= $editing ? 'Edit Course' : 'Add Course' ?></h1>
  <?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="alert" aria-live="assertive"><?= h($success) ?></div><?php endif; ?>
  <?php if (!$departments || !$revisions || !$semesters || !$categories || !$courseTypes): ?><section class="panel"><h2>Course setup incomplete</h2><p class="muted">Add at least one department, revision, semester, course category, and course type before adding a course.</p></section><?php else: ?>
  <section class="panel"><form method="post" class="form-stack course-form-grid"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= h((string)($editing['id'] ?? '')) ?>">
    <label>Course Offering Department<select class="js-select2" data-placeholder="Select department" name="department_id" required><option value="">Select department</option><?php foreach ($departments as $item): $val=$_POST['department_id'] ?? $editing['department_id'] ?? ''; ?><option value="<?= (int)$item['id'] ?>" <?= (string)$val === (string)$item['id'] ? 'selected' : '' ?>><?= h($item['department_name'] . ' · ' . $item['department_code']) ?></option><?php endforeach; ?></select></label>
    <label>Revision<select class="js-select2" data-placeholder="Select revision" name="revision_id" id="course-revision" required><option value="">Select revision</option><?php foreach ($revisions as $item): $val=$_POST['revision_id'] ?? $editing['revision_id'] ?? ''; ?><option value="<?= (int)$item['id'] ?>" <?= (string)$val === (string)$item['id'] ? 'selected' : '' ?>><?= h($item['revision_name'] . ' · ' . $item['revision_year']) ?></option><?php endforeach; ?></select></label>
    <label>Semester<select class="js-select2" data-placeholder="Select semester" name="semester_id" required><option value="">Select semester</option><?php foreach ($semesters as $item): $val=$_POST['semester_id'] ?? $editing['semester_id'] ?? ''; ?><option value="<?= (int)$item['id'] ?>" <?= (string)$val === (string)$item['id'] ? 'selected' : '' ?>><?= h($item['semester_full_name'] . ' · ' . $item['semester_code'] . ' · Year ' . $item['semester_year']) ?></option><?php endforeach; ?></select></label>
    <label>Course Category<select class="js-select2" data-placeholder="Select course category" name="course_category_id" id="course-category-select" required><option value="">Select course category</option><?php foreach ($categories as $item): $val=$_POST['course_category_id'] ?? $editing['course_category_id'] ?? ''; ?><option value="<?= (int)$item['id'] ?>" data-revision="<?= (int)$item['revision_id'] ?>" <?= (string)$val === (string)$item['id'] ? 'selected' : '' ?>><?= h($item['course_category']) ?></option><?php endforeach; ?></select></label>
    <label>Course Type<select class="js-select2" data-placeholder="Select course type" name="course_type_id" required><option value="">Select course type</option><?php foreach ($courseTypes as $item): $val=$_POST['course_type_id'] ?? $editing['course_type_id'] ?? ''; ?><option value="<?= (int)$item['id'] ?>" <?= (string)$val === (string)$item['id'] ? 'selected' : '' ?>><?= h($item['course_type']) ?></option><?php endforeach; ?></select></label>
    <label>Course Code<input name="course_code" maxlength="50" value="<?= h($_POST['course_code'] ?? ($editing['course_code'] ?? '')) ?>" required></label>
    <label>Course Name<input name="course_name" maxlength="180" value="<?= h($_POST['course_name'] ?? ($editing['course_name'] ?? '')) ?>" required></label>
    <label>Course Short Name<input name="course_short_name" maxlength="80" value="<?= h($_POST['course_short_name'] ?? ($editing['course_short_name'] ?? '')) ?>" required></label>
    <label>Hours Per Week<input type="number" name="hours_per_week" min="0.01" max="99.99" step="0.01" value="<?= h((string)($_POST['hours_per_week'] ?? ($editing['hours_per_week'] ?? ''))) ?>" required></label>
    <div class="form-actions"><button class="button" type="submit" name="action" value="save"><?= $editing ? 'Update Course' : 'Save Course' ?></button><?php if ($editing): ?><a class="button button-quiet" href="courses.php?option=add">Cancel</a><?php endif; ?></div>
  </form></section><?php endif; ?>
<?php else: ?>
  <a class="back-link" href="courses.php">← Courses</a><p class="eyebrow">Courses</p><h1>View Courses</h1>
  <?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="status"><?= h($success) ?></div><?php endif; ?>
  <section class="panel"><div class="section-heading"><h2>Course offerings</h2><span class="count-badge" id="course-visible-count"><?= count($courses) ?></span></div>
  <?php if (!$courses): ?><p class="muted">No courses added yet.</p><?php else: ?>
  <div class="course-table-filters" aria-label="Filter courses">
    <label>Department<select class="js-select2 js-course-filter" data-placeholder="All departments" data-filter-key="department" multiple><?php foreach ($departments as $item): ?><option value="<?= (int)$item['id'] ?>"><?= h($item['department_name']) ?></option><?php endforeach; ?></select></label>
    <label>Revision<select class="js-select2 js-course-filter" data-placeholder="All revisions" data-filter-key="revision" multiple><?php foreach ($revisions as $item): ?><option value="<?= (int)$item['id'] ?>"><?= h($item['revision_name']) ?></option><?php endforeach; ?></select></label>
    <label>Semester<select class="js-select2 js-course-filter" data-placeholder="All semesters" data-filter-key="semester" multiple><?php foreach ($semesters as $item): ?><option value="<?= (int)$item['id'] ?>"><?= h($item['semester_full_name']) ?></option><?php endforeach; ?></select></label>
    <label>Course Category<select class="js-select2 js-course-filter" data-placeholder="All categories" data-filter-key="category" multiple><?php foreach ($categories as $item): ?><option value="<?= (int)$item['id'] ?>"><?= h($item['course_category']) ?></option><?php endforeach; ?></select></label>
    <label>Course Type<select class="js-select2 js-course-filter" data-placeholder="All course types" data-filter-key="type" multiple><?php foreach ($courseTypes as $item): ?><option value="<?= (int)$item['id'] ?>"><?= h($item['course_type']) ?></option><?php endforeach; ?></select></label>
  </div>
  <div class="table-wrap"><table id="courses-table"><thead><tr><th>No.</th><th>Department</th><th>Revision</th><th>Semester</th><th>Course Category</th><th>Course Type</th><th>Course Code</th><th>Course Name</th><th>Course Short Name</th><th>Hours/Week</th><th>Actions</th></tr></thead><tbody><?php foreach ($courses as $course): ?><tr data-department="<?= (int)$course['department_id'] ?>" data-revision="<?= (int)$course['revision_id'] ?>" data-semester="<?= (int)$course['semester_id'] ?>" data-category="<?= (int)$course['course_category_id'] ?>" data-type="<?= (int)$course['course_type_id'] ?>"><td class="course-row-number"></td><td><?= h($course['department_name']) ?></td><td><?= h($course['revision_name']) ?></td><td><?= h($course['semester_full_name']) ?></td><td><?= h($course['course_category']) ?></td><td><?= h($course['course_type']) ?></td><td><code><?= h($course['course_code']) ?></code></td><td><?= h($course['course_name']) ?></td><td><?= h($course['course_short_name'] ?? '') ?></td><td><?= h((string)$course['hours_per_week']) ?></td><td class="row-actions"><a class="text-link" href="courses.php?option=add&amp;edit=<?= (int)$course['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this course?')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$course['id'] ?>"><button class="text-button" type="submit" name="action" value="delete">Delete</button></form></td></tr><?php endforeach; ?><tr class="course-filter-empty" hidden><td colspan="11">No courses match the selected filters.</td></tr></tbody><tfoot><tr><th colspan="9">Grand Total Hours</th><th id="course-hours-grand-total"><?= h((string)array_sum(array_map(static fn($course) => (float)$course['hours_per_week'], $courses))) ?></th><td></td></tr></tfoot></table></div><?php endif; ?></section>
<?php endif; ?>
</main><?php appLayoutEnd(); ?>
