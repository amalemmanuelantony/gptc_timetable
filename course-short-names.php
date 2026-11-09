<?php
require __DIR__ . '/app.php';
requireRole(['admin']);
$college = collegeName($pdo);
$error = '';
$success = '';
$columns = $pdo->query('SHOW COLUMNS FROM courses')->fetchAll(PDO::FETCH_COLUMN);
$ready = in_array('course_short_name', $columns, true);
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $courseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT) ?: 0;
    $shortName = trim($_POST['course_short_name'] ?? '');
    if (!$courseId) $error = 'Choose a valid course.';
    elseif ($shortName === '') $error = 'Enter a Course Short Name.';
    elseif (mb_strlen($shortName) > 80) $error = 'Course Short Name must be 80 characters or fewer.';
    else {
        $stmt = $pdo->prepare('UPDATE courses SET course_short_name = ? WHERE id = ?');
        $stmt->execute([$shortName, $courseId]);
        $success = $stmt->rowCount() ? 'Course Short Name saved.' : 'Course Short Name is already up to date.';
    }
}
$missing = $ready ? $pdo->query("SELECT c.id, c.course_code, c.course_name, c.course_short_name, d.department_name, s.semester_full_name FROM courses c JOIN departments d ON d.id = c.department_id JOIN semesters s ON s.id = c.semester_id WHERE c.course_short_name IS NULL OR TRIM(c.course_short_name) = '' ORDER BY d.department_name, s.semester_year, c.course_code")->fetchAll() : [];
appLayoutStart('Course Short Names', $college, 'course-short-names');
?>
<main class="dashboard narrow">
  <p class="eyebrow">Courses</p><h1>Course Short Names</h1>
  <p class="muted">Add a short name to courses already in the system. Courses disappear from this list after a short name is saved.</p>
  <?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="success" role="alert" aria-live="assertive"><?= h($success) ?></div><?php endif; ?>
  <?php if (!$ready): ?>
    <section class="panel"><h2>Database update required</h2><p class="muted">Import <code>migrations/010_course_short_name.sql</code> in phpMyAdmin, then reload this page.</p></section>
  <?php elseif (!$missing): ?>
    <section class="panel"><h2>All courses are up to date</h2><p class="muted">Every course has a Course Short Name.</p></section>
  <?php else: ?>
    <section class="panel"><div class="section-heading"><h2>Courses needing a short name</h2><span class="count-badge"><?= count($missing) ?></span></div>
      <div class="table-wrap"><table><thead><tr><th>Department</th><th>Semester</th><th>Course Code</th><th>Course Name</th><th>Course Short Name</th></tr></thead><tbody>
      <?php foreach ($missing as $course): ?><tr><td><?= h($course['department_name']) ?></td><td><?= h($course['semester_full_name']) ?></td><td><code><?= h($course['course_code']) ?></code></td><td><?= h($course['course_name']) ?></td><td><form method="post" class="short-name-row-form"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>"><input name="course_short_name" maxlength="80" aria-label="Course Short Name for <?= h($course['course_name']) ?>" required><button class="button button-small" type="submit" name="action" value="save">Save</button></form></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </section>
  <?php endif; ?>
</main><?php appLayoutEnd(); ?>
