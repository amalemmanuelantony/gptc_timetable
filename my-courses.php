<?php
require __DIR__ . '/app.php';
requireRole(['user']);
$college = collegeName($pdo);
$departmentIds = array_values(array_unique(array_filter(array_map('intval', $_SESSION['user']['department_ids'] ?? []), static fn($id) => $id > 0)));
$departments = []; $courses = [];
if ($departmentIds) {
    $placeholders = implode(',', array_fill(0, count($departmentIds), '?'));
    $stmt = $pdo->prepare("SELECT department_name, department_code FROM departments WHERE id IN ($placeholders) ORDER BY department_name");
    $stmt->execute($departmentIds); $departments = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT d.department_name, d.department_code, c.course_code, c.course_name, c.course_short_name, c.hours_per_week, s.semester_full_name, s.semester_code, cc.course_category, ct.course_type FROM courses c JOIN departments d ON d.id = c.department_id JOIN semesters s ON s.id = c.semester_id JOIN course_categories cc ON cc.id = c.course_category_id JOIN course_types ct ON ct.id = c.course_type_id WHERE c.department_id IN ($placeholders) ORDER BY d.department_name, s.semester_year, s.id, c.course_code");
    $stmt->execute($departmentIds); $courses = $stmt->fetchAll();
}
appLayoutStart('Courses', $college, 'user-courses'); ?>
<main class="dashboard"><p class="eyebrow">Department courses</p><h1>Courses</h1>
<?php if (!$departments): ?><section class="panel"><p class="muted">No Departments are assigned to your account. Contact an administrator.</p></section>
<?php else: ?><p class="muted">Assigned departments: <?= h(implode(', ', array_map(static fn($department) => $department['department_name'] . ' · ' . $department['department_code'], $departments))) ?></p><section class="panel"><div class="section-heading"><h2>Courses</h2><span class="count-badge"><?= count($courses) ?></span></div><?php if (!$courses): ?><p class="muted">No courses are available for your Departments.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Department</th><th>Semester</th><th>Course Code</th><th>Course Name</th><th>Course Short Name</th><th>Course Category</th><th>Course Type</th><th>Hours/Week</th></tr></thead><tbody><?php foreach ($courses as $course): ?><tr><td><?= h($course['department_name']) ?> · <?= h($course['department_code']) ?></td><td><?= h($course['semester_full_name']) ?></td><td><code><?= h($course['course_code']) ?></code></td><td><?= h($course['course_name']) ?></td><td><?= h($course['course_short_name'] ?? '—') ?></td><td><?= h($course['course_category']) ?></td><td><?= h($course['course_type']) ?></td><td><?= h((string)$course['hours_per_week']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?>
</main><?php appLayoutEnd(); ?>
