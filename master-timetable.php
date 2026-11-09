<?php
require __DIR__ . '/app.php';
requireRole(['admin', 'user']);
$college = collegeName($pdo);
$timetableId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$requiredTables = ['timetables', 'timetable_semesters', 'timetable_slot_courses', 'timetable_slot_course_faculty', 'academic_years', 'semesters', 'days', 'hours', 'departments', 'courses', 'batches'];
$existingTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$ready = count(array_diff($requiredTables, $existingTables)) === 0;
$viewing = null; $viewingSemesters = []; $days = []; $hours = []; $departments = []; $assignments = [];
if ($ready && $timetableId) {
    $stmt = $pdo->prepare('SELECT t.id, ay.ay_name_1 FROM timetables t JOIN academic_years ay ON ay.id = t.academic_year_id WHERE t.id = ?');
    $stmt->execute([$timetableId]); $viewing = $stmt->fetch() ?: null;
    if ($viewing) {
        $stmt = $pdo->prepare('SELECT s.id AS semester_id, s.semester_full_name, s.semester_code FROM timetable_semesters ts JOIN semesters s ON s.id = ts.semester_id WHERE ts.timetable_id = ? ORDER BY s.semester_year, s.id');
        $stmt->execute([$timetableId]); $viewingSemesters = $stmt->fetchAll();
        $days = $pdo->query('SELECT day_id, day_name_1, day_name_2 FROM days ORDER BY day_id')->fetchAll();
        $hours = $pdo->query('SELECT hour_id, hour_name_1, hour_name_2, hour_time_from, hour_time_to FROM hours ORDER BY hour_id')->fetchAll();
        $departments = $pdo->query("SELECT id, department_name, department_code FROM departments WHERE UPPER(department_code) NOT IN ('GS', 'WS') ORDER BY department_name")->fetchAll();
        $stmt = $pdo->prepare('SELECT a.assignment_id, a.day_id, a.hour_id, a.department_id, a.semester_id, c.course_short_name, c.course_code, c.course_name, b.batch_name1, GROUP_CONCAT(DISTINCT f.faculty_short_name ORDER BY COALESCE(g.faculty_priority, 100), f.faculty_name SEPARATOR ", ") AS faculty_names FROM timetable_slot_courses a JOIN courses c ON c.id = a.course_id LEFT JOIN batches b ON b.id = a.batch_id LEFT JOIN timetable_slot_course_faculty tscf ON tscf.assignment_id = a.assignment_id LEFT JOIN faculty f ON f.id = tscf.faculty_id LEFT JOIN designations g ON g.id = f.designation_id WHERE a.timetable_id = ? GROUP BY a.assignment_id, a.day_id, a.hour_id, a.department_id, a.semester_id, c.course_short_name, c.course_code, c.course_name, b.batch_name1 ORDER BY (b.batch_name1 IS NULL), b.batch_name1, c.course_name, c.course_code, a.assignment_id');
        $stmt->execute([$timetableId]);
        foreach ($stmt->fetchAll() as $row) {
            $key = implode(':', [$row['day_id'], $row['hour_id'], $row['department_id'], $row['semester_id']]);
            $assignments[$key][] = ['short_name' => $row['course_short_name'] ?: $row['course_code'], 'batch_name1' => $row['batch_name1'], 'faculty_names' => $row['faculty_names'] ?? ''];
        }
    }
}
pageStart('Master Timetable', $college);
?>
<main class="master-timetable-page">
  <?php if (!$ready): ?><section class="panel"><h1>Database setup required</h1><p class="muted">Import the timetable setup migrations in phpMyAdmin, then reload.</p></section>
  <?php elseif (!$viewing): ?><section class="panel"><h1>Timetable not found</h1><p class="muted">Return to <a href="timetables.php">Timetables</a> and open a valid timetable.</p></section>
  <?php else: ?>
    <header class="master-timetable-heading"><div><p class="eyebrow">Master timetable</p><h1><?= h($viewing['ay_name_1']) ?></h1><p class="muted"><?= h(implode(', ', array_column($viewingSemesters, 'semester_full_name'))) ?></p></div><a class="button button-quiet" href="timetables.php?view=<?= (int)$timetableId ?>">Back to Assign Courses</a></header>
    <?php if (!$days || !$hours || !$departments || !$viewingSemesters): ?><section class="panel"><p class="muted">Add at least one Day, Hour, Department, and Semester to display the master timetable.</p></section>
    <?php else: ?>
      <section class="panel timetable-grid-filters"><div class="section-heading"><h2>Show in Timetable Grid</h2></div><div class="timetable-filter-groups"><fieldset><legend>Departments</legend><div class="timetable-filter-options"><?php foreach ($departments as $department): ?><label><input type="checkbox" data-grid-filter="department" value="<?= (int)$department['id'] ?>" checked><span><?= h($department['department_name']) ?></span></label><?php endforeach; ?></div></fieldset><fieldset><legend>Semesters</legend><div class="timetable-filter-options"><?php foreach ($viewingSemesters as $semester): ?><label><input type="checkbox" data-grid-filter="semester" value="<?= (int)$semester['semester_id'] ?>" checked><span><?= h($semester['semester_full_name']) ?></span></label><?php endforeach; ?></div></fieldset><fieldset><legend>Days</legend><div class="timetable-filter-options"><?php foreach ($days as $day): ?><label><input type="checkbox" data-grid-filter="day" value="<?= (int)$day['day_id'] ?>" checked><span><?= h($day['day_name_1']) ?></span></label><?php endforeach; ?></div></fieldset></div></section>

      <section class="panel timetable-grid-panel"><div class="section-heading"><h2>Timetable Grid</h2></div><div class="table-wrap timetable-grid-wrap master-timetable-grid-wrap"><table class="timetable-grid master-timetable-colored-grid"><thead><tr><th>Day</th><th>Department</th><th>Semester</th><?php foreach ($hours as $hourIndex => $hour): ?><th class="master-hour-col-<?= $hourIndex % 6 ?>"><strong><?= h($hour['hour_name_1']) ?></strong><small><?= h($hour['hour_name_2']) ?></small><small><?= h(date('g:i A', strtotime($hour['hour_time_from'])) . ' – ' . date('g:i A', strtotime($hour['hour_time_to']))) ?></small></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach ($days as $dayIndex => $day): foreach ($departments as $departmentIndex => $department): foreach ($viewingSemesters as $semesterIndex => $semester): ?><tr class="<?= $semesterIndex < count($viewingSemesters) - 1 ? 'timetable-level-semester-end' : '' ?> <?= $semesterIndex === count($viewingSemesters) - 1 ? 'timetable-level-department-end' : '' ?> <?= $departmentIndex === count($departments) - 1 && $semesterIndex === count($viewingSemesters) - 1 ? 'timetable-level-day-end' : '' ?>" data-department-code="<?= h($department['department_code']) ?>" data-day-id="<?= (int)$day['day_id'] ?>" data-department-id="<?= (int)$department['id'] ?>" data-semester-id="<?= (int)$semester['semester_id'] ?>"><th class="timetable-day" data-watermark="<?= h($day['day_name_2']) ?>"><div class="day-watermarks" aria-hidden="true"></div><strong><?= h($day['day_name_1']) ?></strong></th><th class="timetable-department" title="<?= h($department['department_name']) ?>"><?= h($department['department_code']) ?></th><td class="timetable-semester" title="<?= h($semester['semester_full_name']) ?>"><?= h($semester['semester_code'] ?? '') ?></td><?php foreach ($hours as $hourIndex => $hour): $key = implode(':', [$day['day_id'], $hour['hour_id'], $department['id'], $semester['semester_id']]); $subjects = $assignments[$key] ?? []; ?><td class="timetable-empty-cell timetable-slot-cell master-hour-col-<?= $hourIndex % 6 ?>"><div class="timetable-readonly-cell"><?= $subjects ? h(implode("\n", array_map(static fn($subject) => ($subject['batch_name1'] ? $subject['batch_name1'] . ' - ' : '') . $subject['short_name'] . (!empty($subject['faculty_names']) ? ' (' . $subject['faculty_names'] . ')' : ''), $subjects))) : '' ?></div></td><?php endforeach; ?></tr><?php endforeach; endforeach; endforeach; ?>
      </tbody></table></div></section>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?php pageEnd(); ?>
