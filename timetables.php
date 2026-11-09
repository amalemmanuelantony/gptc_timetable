<?php
require __DIR__ . '/app.php'; requireRole(['admin', 'user']);
$college = collegeName($pdo); $error = ''; $success = '';
$isAdmin = $_SESSION['user']['role'] === 'admin';
$userDepartmentIds = array_values(array_unique(array_filter(array_map('intval', $_SESSION['user']['department_ids'] ?? []), static fn($id) => $id > 0)));
$departmentScope = $userDepartmentIds ? implode(',', $userDepartmentIds) : '0';
$requiredTables = ['academic_years', 'semesters', 'timetables', 'timetable_semesters', 'timetable_slot_courses', 'timetable_slot_course_faculty', 'courses', 'batches', 'users', 'faculty'];
$existingTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$timetableColumns = in_array('timetables', $existingTables, true) ? $pdo->query('SHOW COLUMNS FROM timetables')->fetchAll(PDO::FETCH_COLUMN) : [];
$slotAssignmentColumns = in_array('timetable_slot_courses', $existingTables, true) ? $pdo->query('SHOW COLUMNS FROM timetable_slot_courses')->fetchAll(PDO::FETCH_COLUMN) : [];
$ready = count(array_diff($requiredTables, $existingTables)) === 0 && in_array('allow_editing', $timetableColumns, true) && in_array('is_published', $timetableColumns, true) && in_array('course_faculty_combinations_enabled', $timetableColumns, true) && in_array('assigned_by_user_id', $slotAssignmentColumns, true) && in_array('assigned_by_name', $slotAssignmentColumns, true);
$viewId = filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT) ?: 0;
$postedAction = $_POST['action'] ?? '';
if (in_array($postedAction, ['set_editing', 'set_published', 'set_combinations'], true)) $viewId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: $viewId;
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        if (!$isAdmin) $error = 'Only administrators can delete timetables.';
        else {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if ($id) { $stmt = $pdo->prepare('DELETE FROM timetables WHERE id = ?'); $stmt->execute([$id]); $success = $stmt->rowCount() ? 'Timetable deleted.' : 'Timetable was not found.'; if ($viewId === $id) $viewId = 0; }
            else $error = 'Invalid timetable.';
        }
    } elseif ($action === 'set_editing') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        if (!$isAdmin) $error = 'Only administrators can change timetable editing access.';
        elseif (!$id) $error = 'Invalid timetable.';
        else {
            $allowEditing = isset($_POST['allow_editing']) ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE timetables SET allow_editing = ? WHERE id = ?');
            $stmt->execute([$allowEditing, $id]);
            $success = $allowEditing ? 'Other users can now edit this timetable.' : 'Timetable editing is now restricted to administrators.';
        }
    } elseif ($action === 'set_published') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        if (!$isAdmin) $error = 'Only administrators can publish timetables.';
        elseif (!$id) $error = 'Invalid timetable.';
        else {
            $isPublished = isset($_POST['is_published']) ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE timetables SET is_published = ? WHERE id = ?');
            $stmt->execute([$isPublished, $id]);
            $success = $isPublished ? 'Timetable published. Other users can now generate downloadable copies.' : 'Timetable unpublished. Other users can no longer generate new copies.';
        }
    } elseif ($action === 'set_combinations') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        if (!$isAdmin) $error = 'Only administrators can change Course - Faculty Combinations access.';
        elseif (!$id) $error = 'Invalid timetable.';
        else {
            $enabled = isset($_POST['course_faculty_combinations_enabled']) ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE timetables SET course_faculty_combinations_enabled = ? WHERE id = ?');
            $stmt->execute([$enabled, $id]);
            $success = $enabled ? 'Course - Faculty Combinations are enabled for users.' : 'Course - Faculty Combinations are disabled for users.';
        }
    } elseif (!$isAdmin) {
        $error = 'Only administrators can create timetables.';
    } else {
        $academicYearId = filter_input(INPUT_POST, 'academic_year_id', FILTER_VALIDATE_INT) ?: 0;
        $semesterIds = $_POST['semester_ids'] ?? [];
        if (!is_array($semesterIds)) $semesterIds = [];
        $semesterIds = array_values(array_unique(array_filter(array_map('intval', $semesterIds), static fn($id) => $id > 0)));
        if (!$academicYearId || !$semesterIds) $error = 'Choose an Academic Year and at least one Semester.';
        else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM academic_years WHERE id = ?'); $stmt->execute([$academicYearId]);
            $validYear = (int)$stmt->fetchColumn() > 0;
            $placeholders = implode(',', array_fill(0, count($semesterIds), '?'));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM semesters WHERE id IN ($placeholders)"); $stmt->execute($semesterIds);
            $validSemesters = (int)$stmt->fetchColumn() === count($semesterIds);
            if (!$validYear || !$validSemesters) $error = 'Choose an existing Academic Year and Semester.';
            else {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare('INSERT INTO timetables (academic_year_id) VALUES (?)');
                    $stmt->execute([$academicYearId]);
                    $timetableId = (int)$pdo->lastInsertId();
                    $link = $pdo->prepare('INSERT INTO timetable_semesters (timetable_id, semester_id) VALUES (?, ?)');
                    foreach ($semesterIds as $semesterId) $link->execute([$timetableId, $semesterId]);
                    $pdo->commit();
                    $success = 'Timetable created.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }
        }
    }
}
$academicYears = $ready ? $pdo->query('SELECT * FROM academic_years ORDER BY id DESC')->fetchAll() : [];
$semesters = $ready ? $pdo->query('SELECT * FROM semesters ORDER BY semester_year, semester_full_name')->fetchAll() : [];
$list = $ready ? $pdo->query("SELECT t.id, t.academic_year_id, t.allow_editing, t.is_published, ay.ay_name_1, GROUP_CONCAT(s.semester_full_name ORDER BY s.semester_year, s.id SEPARATOR ', ') AS semester_labels FROM timetables t JOIN academic_years ay ON ay.id = t.academic_year_id JOIN timetable_semesters ts ON ts.timetable_id = t.id JOIN semesters s ON s.id = ts.semester_id GROUP BY t.id, t.academic_year_id, t.allow_editing, t.is_published, ay.ay_name_1 ORDER BY t.id DESC")->fetchAll() : [];
$viewing = null;
if ($ready && $viewId) { $stmt = $pdo->prepare('SELECT t.*, ay.ay_name_1 FROM timetables t JOIN academic_years ay ON ay.id = t.academic_year_id WHERE t.id = ?'); $stmt->execute([$viewId]); $viewing = $stmt->fetch() ?: null; }
$viewingSemesters = [];
if ($ready && $viewing) { $stmt = $pdo->prepare('SELECT s.id AS semester_id, s.semester_full_name, s.semester_short_name, s.semester_code, s.semester_year, s.semester_type FROM timetable_semesters ts JOIN semesters s ON s.id = ts.semester_id WHERE ts.timetable_id = ? ORDER BY s.semester_year, s.id'); $stmt->execute([$viewId]); $viewingSemesters = $stmt->fetchAll(); }
$gridTables = ['days', 'hours', 'departments'];
$gridReady = $ready && count(array_diff($gridTables, $existingTables)) === 0;
$comboSchemaReady = in_array('course_faculty_combinations', $existingTables, true) && in_array('course_faculty_combination_faculty', $existingTables, true);
$comboAccess = $isAdmin || (int)($viewing['course_faculty_combinations_enabled'] ?? 0) === 1;
$days = ($gridReady && $viewing) ? $pdo->query('SELECT day_id, day_name_1, day_name_2 FROM days ORDER BY day_id')->fetchAll() : [];
$hours = ($gridReady && $viewing) ? $pdo->query('SELECT hour_id, hour_number_1, hour_number_2, hour_name_1, hour_name_2, hour_time_from, hour_time_to FROM hours ORDER BY hour_id')->fetchAll() : [];
$departments = [];
$courseOptions = [];
$facultyOptions = [];
$courseFacultyCombinations = [];
$batches = ($gridReady && $viewing) ? $pdo->query('SELECT id, batch_name1, batch_name2 FROM batches ORDER BY id')->fetchAll() : [];
if ($gridReady && $viewing) {
    if ($isAdmin) $departments = $pdo->query('SELECT id, department_name, department_code FROM departments ORDER BY department_name')->fetchAll();
    else {
        $departments = $pdo->query("SELECT id, department_name, department_code FROM departments WHERE id IN ($departmentScope) ORDER BY department_name")->fetchAll();
    }
    $courseSql = 'SELECT c.id, c.department_id, c.semester_id, c.course_code, c.course_name, c.course_short_name, c.hours_per_week, d.department_code, s.semester_code FROM courses c JOIN departments d ON d.id=c.department_id JOIN semesters s ON s.id=c.semester_id';
    if (!$isAdmin) $courseSql .= ' WHERE c.department_id IN (' . $departmentScope . ')';
    $courseOptions = $pdo->query($courseSql . ' ORDER BY semester_id, course_code')->fetchAll();
    $facultySql = 'SELECT f.id, f.faculty_name, f.faculty_short_name, f.department_id FROM faculty f';
    $facultyOptions = $pdo->query($facultySql . ' ORDER BY f.faculty_name')->fetchAll();
    if ($comboSchemaReady && $comboAccess) {
        $combinationSql = 'SELECT cf.id,cf.combination_code,c.id AS course_id,c.department_id,d.department_name,d.department_code,c.semester_id,s.semester_full_name,s.semester_code,c.course_code,c.course_name,GROUP_CONCAT(f.id ORDER BY f.faculty_name SEPARATOR ",") AS faculty_ids,GROUP_CONCAT(CONCAT(f.faculty_name,CASE WHEN f.faculty_short_name IS NOT NULL AND f.faculty_short_name<>"" THEN CONCAT(" (",f.faculty_short_name,")") ELSE "" END) ORDER BY f.faculty_name SEPARATOR ", ") AS faculty_names FROM course_faculty_combinations cf JOIN courses c ON c.id=cf.course_id JOIN departments d ON d.id=c.department_id JOIN semesters s ON s.id=c.semester_id JOIN course_faculty_combination_faculty cff ON cff.combination_id=cf.id JOIN faculty f ON f.id=cff.faculty_id';
        $combinationParams = [];
        if (!$isAdmin) {
            if (!$userDepartmentIds) $combinationSql .= ' WHERE 1=0';
            else {
                $combinationSql .= ' WHERE c.department_id IN (' . implode(',', array_fill(0, count($userDepartmentIds), '?')) . ')';
                array_push($combinationParams, ...$userDepartmentIds);
            }
        }
        $combinationSql .= ' GROUP BY cf.id,cf.combination_code,c.id,c.department_id,d.department_name,d.department_code,c.semester_id,s.semester_full_name,s.semester_code,c.course_code,c.course_name ORDER BY cf.combination_code';
        $stmt = $pdo->prepare($combinationSql);
        $stmt->execute($combinationParams);
        foreach ($stmt->fetchAll() as $combination) {
            $combination['id'] = (int)$combination['id'];
            $combination['course_id'] = (int)$combination['course_id'];
            $combination['department_id'] = (int)$combination['department_id'];
            $combination['semester_id'] = (int)$combination['semester_id'];
            $combination['faculty_ids'] = array_map('intval', explode(',', (string)$combination['faculty_ids']));
            $combination['course_label'] = $combination['course_code'] . ' · ' . $combination['course_name'];
            $combination['department_label'] = $combination['department_name'] . ' · ' . $combination['department_code'];
            $combination['semester_label'] = $combination['semester_full_name'] . ' · ' . $combination['semester_code'];
            $courseFacultyCombinations[] = $combination;
        }
    }
}
$slotAssignments = [];
if ($gridReady && $viewing) {
    $assignmentSql = 'SELECT a.assignment_id, a.day_id, a.hour_id, a.department_id, a.semester_id, a.course_id, a.batch_id, a.assigned_by_user_id, a.assigned_by_name, c.course_short_name, c.course_code, c.course_name, b.batch_name1, b.batch_name2, GROUP_CONCAT(DISTINCT f.faculty_short_name ORDER BY COALESCE(g.faculty_priority, 100), f.faculty_name SEPARATOR ", ") AS faculty_names, GROUP_CONCAT(DISTINCT tscf.faculty_id ORDER BY COALESCE(g.faculty_priority, 100), f.faculty_name SEPARATOR ",") AS faculty_ids FROM timetable_slot_courses a JOIN courses c ON c.id = a.course_id LEFT JOIN batches b ON b.id = a.batch_id LEFT JOIN timetable_slot_course_faculty tscf ON tscf.assignment_id = a.assignment_id LEFT JOIN faculty f ON f.id = tscf.faculty_id LEFT JOIN designations g ON g.id = f.designation_id WHERE a.timetable_id = ?';
    if (!$isAdmin) $assignmentSql .= ' AND a.department_id IN (' . $departmentScope . ')';
    $assignmentSql .= ' GROUP BY a.assignment_id, a.day_id, a.hour_id, a.department_id, a.semester_id, a.course_id, a.batch_id, a.assigned_by_user_id, a.assigned_by_name, c.course_short_name, c.course_code, c.course_name, b.batch_name1, b.batch_name2';
    $stmt = $pdo->prepare($assignmentSql . ' ORDER BY (b.batch_name1 IS NULL), b.batch_name1, c.course_name, c.course_code, a.assignment_id');
    $stmt->execute([$viewId]);
    foreach ($stmt->fetchAll() as $assignment) {
        $key = implode(':', [$assignment['day_id'], $assignment['hour_id'], $assignment['department_id'], $assignment['semester_id']]);
        $slotAssignments[$key][] = ['assignment_id' => (int)$assignment['assignment_id'], 'course_id' => (int)$assignment['course_id'], 'course_code' => $assignment['course_code'], 'course_name' => $assignment['course_name'], 'short_name' => $assignment['course_short_name'] ?: $assignment['course_code'], 'batch_id' => $assignment['batch_id'] === null ? null : (int)$assignment['batch_id'], 'batch_name1' => $assignment['batch_name1'], 'batch_name2' => $assignment['batch_name2'], 'faculty_names' => $assignment['faculty_names'] ?? '', 'faculty_ids' => $assignment['faculty_ids'] ?? '', 'assigned_by_user_id' => $assignment['assigned_by_user_id'] === null ? null : (int)$assignment['assigned_by_user_id'], 'assigned_by_name' => $assignment['assigned_by_name']];
    }
}
$courseHourCountRows = [];
if ($gridReady && $viewing) {
    $countStmt = $pdo->prepare('SELECT course_id, department_id, semester_id, day_id, hour_id FROM timetable_slot_courses WHERE timetable_id = ?');
    $countStmt->execute([$viewId]);
    $courseHourCountRows = $countStmt->fetchAll();
}
$courseColorPalette = ['#e7f0ff', '#e6f5eb', '#fff1d8', '#fce8e6', '#f0eaff', '#ddf3f0', '#ffebd9', '#e8eef0', '#f8e6f1', '#edf2d5', '#e4eaff', '#f7eddf'];
$courseColors = [];
$semesterColorIndexes = [];
$colorCourses = $gridReady && $viewing ? $pdo->query('SELECT id, semester_id, course_code FROM courses ORDER BY semester_id, course_code')->fetchAll() : [];
foreach ($colorCourses as $courseOption) {
    $semesterId = (int)$courseOption['semester_id'];
    $index = $semesterColorIndexes[$semesterId] ?? 0;
    $courseColors[(int)$courseOption['id']] = $courseColorPalette[$index % count($courseColorPalette)];
    $semesterColorIndexes[$semesterId] = $index + 1;
}
$slotBackground = static function (array $assignments) use ($courseColors): string {
    $colors = [];
    foreach ($assignments as $assignment) {
        $courseId = (int)($assignment['course_id'] ?? 0);
        if (isset($courseColors[$courseId])) $colors[$courseId] = $courseColors[$courseId];
    }
    $colors = array_values($colors);
    if (!$colors) return '';
    if (count($colors) === 1) return $colors[0];
    $step = 100 / count($colors);
    $stops = [];
    foreach ($colors as $index => $color) {
        $start = $index * $step;
        $end = ($index + 1) * $step;
        $stops[] = $color . ' ' . $start . '% ' . $end . '%';
    }
    return 'linear-gradient(135deg, ' . implode(', ', $stops) . ')';
};
$summaryRows = [];
$facultyWorkloadRows = [];
$courseHoursCourses = [];
$courseHoursRows = [];
$facultySlotAvailability = [];
if ($gridReady && $viewing) {
    $summarySql = "SELECT c.department_id, s.semester_full_name, c.course_code, c.course_short_name, c.hours_per_week, COUNT(DISTINCT CONCAT(a.day_id, ':', a.hour_id, ':', a.department_id, ':', a.semester_id)) AS hours_assigned FROM courses c JOIN timetable_semesters ts ON ts.semester_id = c.semester_id AND ts.timetable_id = ? JOIN semesters s ON s.id = c.semester_id LEFT JOIN timetable_slot_courses a ON a.course_id = c.id AND a.timetable_id = ? AND a.department_id = c.department_id AND a.semester_id = c.semester_id";
    if (!$isAdmin) $summarySql .= ' WHERE c.department_id IN (' . $departmentScope . ')';
    $stmt = $pdo->prepare($summarySql . ' GROUP BY c.id, c.department_id, s.semester_full_name, c.course_code, c.course_short_name, c.hours_per_week, s.semester_year, s.id ORDER BY c.department_id, s.semester_year, s.id, c.course_code');
    $stmt->execute([$viewId, $viewId]);
    $summaryRows = $stmt->fetchAll();
    $workloadStmt = $pdo->prepare('SELECT f.id AS faculty_id, c.id AS course_id, s.semester_full_name, c.course_code, c.course_name, c.hours_per_week, a.day_id, a.hour_id, d.day_name_1 FROM timetable_slot_course_faculty tscf JOIN timetable_slot_courses a ON a.assignment_id = tscf.assignment_id JOIN faculty f ON f.id = tscf.faculty_id JOIN courses c ON c.id = a.course_id JOIN semesters s ON s.id = a.semester_id JOIN days d ON d.day_id = a.day_id WHERE a.timetable_id = ? ORDER BY f.faculty_name, s.semester_year, s.id, c.course_code, a.day_id, a.hour_id');
    $workloadStmt->execute([$viewId]);
    foreach ($workloadStmt->fetchAll() as $workloadRow) {
        $workloadKey = $workloadRow['faculty_id'] . ':' . $workloadRow['course_id'];
        if (!isset($facultyWorkloadRows[$workloadKey])) $facultyWorkloadRows[$workloadKey] = ['faculty_id' => (int)$workloadRow['faculty_id'], 'course_code' => $workloadRow['course_code'], 'course_name' => $workloadRow['course_name'], 'semester_full_name' => $workloadRow['semester_full_name'], 'hours_per_week' => $workloadRow['hours_per_week'], '_slots' => []];
        $slotKey = $workloadRow['day_id'] . ':' . $workloadRow['hour_id'];
        $facultyWorkloadRows[$workloadKey]['_slots'][$slotKey] = $workloadRow['day_name_1'] . ' - Hour ' . $workloadRow['hour_id'];
    }
    foreach ($facultyWorkloadRows as &$workloadRow) { $workloadRow['assigned_hours'] = count($workloadRow['_slots']); $workloadRow['assigned_slots'] = implode("\n", array_values($workloadRow['_slots'])); unset($workloadRow['_slots']); }
    unset($workloadRow);
    $facultyWorkloadRows = array_values($facultyWorkloadRows);
    $courseHoursCourseSql = 'SELECT c.id AS course_id, c.department_id, c.course_code, c.course_name, c.course_short_name FROM courses c JOIN timetable_semesters ts ON ts.semester_id = c.semester_id WHERE ts.timetable_id = ?';
    $courseHoursCourseParams = [$viewId];
    if (!$isAdmin) { $courseHoursCourseSql .= ' AND c.department_id IN (' . $departmentScope . ')'; }
    $courseHoursCourses = $pdo->prepare($courseHoursCourseSql . ' ORDER BY c.department_id, c.course_code');
    $courseHoursCourses->execute($courseHoursCourseParams); $courseHoursCourses = $courseHoursCourses->fetchAll();
    $courseHoursSql = "SELECT a.course_id, a.department_id, a.semester_id, s.semester_full_name, a.day_id, d.day_name_1, a.hour_id, h.hour_name_1, h.hour_name_2, TIME_FORMAT(h.hour_time_from, '%h:%i %p') AS time_from, TIME_FORMAT(h.hour_time_to, '%h:%i %p') AS time_to, GROUP_CONCAT(DISTINCT b.batch_name1 ORDER BY b.batch_name1 SEPARATOR ', ') AS batch_names, GROUP_CONCAT(DISTINCT CONCAT(f.faculty_name, CASE WHEN f.faculty_short_name IS NOT NULL AND f.faculty_short_name <> '' THEN CONCAT(' (', f.faculty_short_name, ')') ELSE '' END) ORDER BY COALESCE(g.faculty_priority, 100), f.faculty_name SEPARATOR ', ') AS faculty_names FROM timetable_slot_courses a JOIN courses c ON c.id = a.course_id JOIN semesters s ON s.id = a.semester_id JOIN days d ON d.day_id = a.day_id JOIN hours h ON h.hour_id = a.hour_id LEFT JOIN batches b ON b.id = a.batch_id LEFT JOIN timetable_slot_course_faculty tscf ON tscf.assignment_id = a.assignment_id LEFT JOIN faculty f ON f.id = tscf.faculty_id LEFT JOIN designations g ON g.id = f.designation_id WHERE a.timetable_id = ?";
    if (!$isAdmin) $courseHoursSql .= ' AND a.department_id IN (' . $departmentScope . ')';
    $courseHoursStmt = $pdo->prepare($courseHoursSql . ' GROUP BY a.course_id, a.department_id, a.semester_id, s.semester_full_name, a.day_id, d.day_name_1, a.hour_id, h.hour_name_1, h.hour_name_2, h.hour_time_from, h.hour_time_to ORDER BY a.department_id, a.course_id, a.day_id, a.hour_id');
    $courseHoursStmt->execute([$viewId]); $courseHoursRows = $courseHoursStmt->fetchAll();
    $availabilityStmt = $pdo->prepare('SELECT tscf.faculty_id, f.faculty_name, a.assignment_id, a.day_id, d.day_name_1, a.hour_id, h.hour_name_1, h.hour_name_2, a.department_id, dept.department_name, dept.department_code, a.semester_id, s.semester_full_name, s.semester_code, a.course_id, c.course_code, c.course_name, c.course_short_name, b.batch_name1, b.batch_name2 FROM timetable_slot_course_faculty tscf JOIN faculty f ON f.id=tscf.faculty_id JOIN designations g ON g.id=f.designation_id JOIN timetable_slot_courses a ON a.assignment_id=tscf.assignment_id JOIN days d ON d.day_id=a.day_id JOIN hours h ON h.hour_id=a.hour_id JOIN departments dept ON dept.id=a.department_id JOIN semesters s ON s.id=a.semester_id JOIN courses c ON c.id=a.course_id LEFT JOIN batches b ON b.id=a.batch_id WHERE a.timetable_id=? ORDER BY COALESCE(g.faculty_priority, 100), f.faculty_name, a.day_id, a.hour_id, dept.department_code, s.semester_code, c.course_code');
    $availabilityStmt->execute([$viewId]);
    $facultySlotAvailability = $availabilityStmt->fetchAll();
}
$summaryBySemester = [];
foreach ($summaryRows as $summaryRow) $summaryBySemester[$summaryRow['semester_full_name']][] = $summaryRow;
appLayoutStart('Timetable', $college, 'timetable'); ?>
<?php if ($gridReady && $viewing): ?><script type="application/json" id="timetable-slot-course-hour-counts"><?= json_encode($courseHourCountRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script><?php endif; ?>
<main class="dashboard timetable-dashboard"><p class="eyebrow">Timetable setup</p><h1><?= $viewing ? 'Timetable - Assign Courses' : 'Timetable' ?></h1><?php if (!$viewing): ?><p class="muted">Create timetable records for an Academic Year and one or more Semesters.</p><?php endif; ?>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="alert" aria-live="assertive"><?= h($success) ?></div><?php endif; ?>
<?php if (!$viewing && !$ready): ?><section class="panel"><h2>Database setup required</h2><p class="muted">Make sure Faculty tables are installed with <code>migrations/024_faculty_designations.sql</code>, then import <code>migrations/026_timetable_slot_faculty.sql</code> and <code>migrations/034_published_generated_timetables.sql</code> and <code>migrations/038_timetable_course_faculty_combinations_permission.sql</code> in phpMyAdmin and reload.</p></section>
<?php elseif (!$viewing && $isAdmin && (!$academicYears || !$semesters)): ?><section class="panel"><h2>Timetable setup incomplete</h2><p class="muted">Add at least one Academic Year and one Semester before creating a timetable.</p></section>
<?php elseif (!$viewing && $isAdmin): ?><section class="panel"><h2>Create Timetable</h2><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
<label>Academic Year<select class="js-select2" data-placeholder="Select Academic Year" name="academic_year_id" required><option value="">Select Academic Year</option><?php foreach ($academicYears as $year): ?><option value="<?= (int)$year['id'] ?>"><?= h($year['ay_name_1']) ?></option><?php endforeach; ?></select></label>
<label>Semester<select class="js-select2" data-placeholder="Select one or more Semesters" name="semester_ids[]" multiple required><?php foreach ($semesters as $semester): ?><option value="<?= (int)$semester['id'] ?>"><?= h($semester['semester_full_name']) ?></option><?php endforeach; ?></select></label>
<div class="form-actions"><button class="button" type="submit" name="action" value="save">Create Timetable</button></div></form></section><?php endif; ?>
<?php if ($viewing): ?>
<section class="panel"><div class="section-heading"><h2>Timetable Details</h2><a class="text-link" href="timetables.php">Back to Timetables</a></div><dl class="timetable-details"><dt>Academic Year</dt><dd><?= h($viewing['ay_name_1']) ?></dd><dt>Semesters</dt><dd><?= h(implode(', ', array_column($viewingSemesters, 'semester_full_name'))) ?></dd></dl>
<?php if ($isAdmin): ?><div class="timetable-permission-switches"><form method="post" class="permission-switch-form"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="set_editing"><input type="hidden" name="id" value="<?= (int)$viewing['id'] ?>"><label class="switch-control"><span>Allow Editing</span><input type="checkbox" class="js-auto-submit" name="allow_editing" value="1" <?= (int)$viewing['allow_editing'] === 1 ? 'checked' : '' ?>><span class="switch-slider" aria-hidden="true"></span></label></form><form method="post" class="permission-switch-form"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="set_published"><input type="hidden" name="id" value="<?= (int)$viewing['id'] ?>"><label class="switch-control"><span>Publish Timetable</span><input type="checkbox" class="js-auto-submit" name="is_published" value="1" <?= (int)$viewing['is_published'] === 1 ? 'checked' : '' ?>><span class="switch-slider" aria-hidden="true"></span></label></form><form method="post" class="permission-switch-form"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="set_combinations"><input type="hidden" name="id" value="<?= (int)$viewing['id'] ?>"><label class="switch-control"><span>Allow Course - Faculty Combinations</span><input type="checkbox" class="js-auto-submit" name="course_faculty_combinations_enabled" value="1" <?= (int)$viewing['course_faculty_combinations_enabled'] === 1 ? 'checked' : '' ?>><span class="switch-slider" aria-hidden="true"></span></label></form></div><?php elseif ((int)$viewing['allow_editing'] !== 1): ?><p class="muted read-only-notice">This timetable is currently read only.</p><?php endif; ?><?php if ($gridReady): ?><div class="timetable-report-actions"><button type="button" class="button button-small timetable-summary-open">View Summary</button><button type="button" class="button button-small timetable-course-hours-open">View Course Hours</button><button type="button" class="button button-small timetable-individual-timetable-open">View Individual Timetable</button><?php if ($comboAccess): ?><button type="button" class="button button-small timetable-course-faculty-combinations-open">Course - Faculty Combinations</button><?php endif; ?></div><?php endif; ?></section>
<?php if (!$gridReady): ?><section class="panel"><h2>Timetable grid setup required</h2><p class="muted">Make sure Faculty tables are installed with <code>migrations/024_faculty_designations.sql</code>, import <code>migrations/026_timetable_slot_faculty.sql</code> and <code>migrations/034_published_generated_timetables.sql</code> and <code>migrations/038_timetable_course_faculty_combinations_permission.sql</code>, and make sure the Days and Hours tables exist, then reload.</p></section>
<?php elseif (!$days || !$hours || !$departments || !$viewingSemesters): ?><section class="panel"><h2>Timetable grid setup incomplete</h2><p class="muted">Add at least one Day, Hour, Department, and Semester to this timetable.</p></section>
<?php else: ?><?php if ($summaryBySemester): ?><details class="panel timetable-course-summary"><summary>Course Assignment Summary</summary><div class="timetable-course-summary-columns"><?php foreach ($summaryBySemester as $semesterName => $semesterCourses): ?><section class="course-summary-semester"><h3><?= h($semesterName) ?></h3><div class="table-wrap"><table class="course-summary-table"><thead><tr><th>Sl No.</th><th>Code</th><th>Short Name</th><th>Total Hours</th><th>Hours Assigned</th></tr></thead><tbody><?php foreach ($semesterCourses as $rowIndex => $courseSummary): $assignedHours = (int)$courseSummary['hours_assigned']; $totalHours = (int)$courseSummary['hours_per_week']; ?><tr><td><?= $rowIndex + 1 ?></td><td><?= h($courseSummary['course_code']) ?></td><td><?= h($courseSummary['course_short_name'] ?: '—') ?></td><td><?= $totalHours ?></td><td class="<?= $assignedHours <= $totalHours ? 'course-hours-within' : 'course-hours-over' ?>"><?= $assignedHours ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endforeach; ?></div></details><?php endif; ?><section class="panel timetable-grid-panel"><div class="section-heading"><h2>Timetable Grid</h2></div><script type="application/json" id="timetable-course-colors"><?= json_encode($courseColors, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script><div class="table-wrap timetable-grid-wrap"><table class="timetable-grid"><thead><tr><th>Day</th><th>Department</th><th>Semester</th><?php foreach ($hours as $hour): ?><th><strong><?= h($hour['hour_name_1']) ?></strong><small><?= h($hour['hour_name_2']) ?></small><small><?= h(date('g:i A', strtotime($hour['hour_time_from'])) . ' – ' . date('g:i A', strtotime($hour['hour_time_to']))) ?></small><?php if ($isAdmin || ((int)$viewing['allow_editing'] === 1 && $userDepartmentIds)): ?><button type="button" class="timetable-bulk-clear" data-bulk-clear="column" data-timetable-id="<?= (int)$viewId ?>" data-hour-id="<?= (int)$hour['hour_id'] ?>" data-clear-label="<?= h($hour['hour_name_2']) ?>">Clear column</button><?php endif; ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($days as $dayIndex => $day): foreach ($departments as $departmentIndex => $department): foreach ($viewingSemesters as $semesterIndex => $semester): ?><tr class="<?= $semesterIndex < count($viewingSemesters) - 1 ? 'timetable-level-semester-end' : '' ?> <?= $semesterIndex === count($viewingSemesters) - 1 ? 'timetable-level-department-end' : '' ?> <?= $departmentIndex === count($departments) - 1 && $semesterIndex === count($viewingSemesters) - 1 ? 'timetable-level-day-end' : '' ?>" data-department-code="<?= h($department['department_code']) ?>" data-day-id="<?= (int)$day['day_id'] ?>" data-department-id="<?= (int)$department['id'] ?>" data-semester-id="<?= (int)$semester['semester_id'] ?>">
<th class="timetable-day" data-watermark="<?= h($day['day_name_2']) ?>"><div class="day-watermarks" aria-hidden="true"></div><strong><?= h($day['day_name_1']) ?></strong></th>
<th class="timetable-department" title="<?= h($department['department_name']) ?>"><?= h($department['department_code']) ?></th>
<td class="timetable-semester" title="<?= h($semester['semester_full_name']) ?>"><span><?= h($semester['semester_code']) ?></span><?php if ($isAdmin || ((int)$viewing['allow_editing'] === 1 && in_array((int)$department['id'], $userDepartmentIds, true))): ?><button type="button" class="timetable-bulk-clear timetable-row-clear" data-bulk-clear="row" data-timetable-id="<?= (int)$viewId ?>" data-day-id="<?= (int)$day['day_id'] ?>" data-department-id="<?= (int)$department['id'] ?>" data-semester-id="<?= (int)$semester['semester_id'] ?>" data-clear-label="<?= h($day['day_name_1'] . ' · ' . $department['department_code'] . ' · ' . $semester['semester_code']) ?>">Clear row</button><?php endif; ?></td>
<?php foreach ($hours as $hour): $slotKey = implode(':', [$day['day_id'], $hour['hour_id'], $department['id'], $semester['semester_id']]); $slotCourses = $slotAssignments[$slotKey] ?? []; $slotLabels = array_map(static fn($item) => ($item['batch_name1'] ? $item['batch_name1'] . ' - ' : '') . $item['short_name'] . (!empty($item['faculty_names']) ? ' (' . $item['faculty_names'] . ')' : ''), $slotCourses); $canEditSlot = $isAdmin || ((int)$viewing['allow_editing'] === 1 && in_array((int)$department['id'], $userDepartmentIds, true)); $background = $slotBackground($slotCourses); ?><td class="timetable-empty-cell timetable-slot-cell">
<?php if ($canEditSlot): ?><button type="button" class="timetable-slot-open<?= $slotCourses ? ' has-assignment' : '' ?>"<?= $background !== '' ? ' style="background:' . h($background) . '"' : '' ?> data-timetable-id="<?= (int)$viewId ?>" data-day-id="<?= (int)$day['day_id'] ?>" data-day-name="<?= h($day['day_name_1']) ?>" data-day-name2="<?= h($day['day_name_2']) ?>" data-hour-id="<?= (int)$hour['hour_id'] ?>" data-hour-name="<?= h($hour['hour_name_1'] . ' · ' . date('g:i A', strtotime($hour['hour_time_from'])) . ' – ' . date('g:i A', strtotime($hour['hour_time_to']))) ?>" data-hour-name2="<?= h($hour['hour_name_2']) ?>" data-department-id="<?= (int)$department['id'] ?>" data-department-name="<?= h($department['department_name']) ?>" data-department-code="<?= h($department['department_code']) ?>" data-semester-id="<?= (int)$semester['semester_id'] ?>" data-semester-name="<?= h($semester['semester_full_name']) ?>" data-semester-code="<?= h($semester['semester_code']) ?>" data-assignments="<?= h(json_encode($slotCourses, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"><?php if ($slotCourses): ?><?= h(implode("\n", $slotLabels)) ?><?php else: ?><span class="slot-empty-label">+ Assign subjects</span><?php endif; ?></button><?php else: ?><div class="timetable-readonly-cell"<?= $background !== '' ? ' style="background:' . h($background) . '"' : '' ?>><?= $slotCourses ? h(implode("\n", $slotLabels)) : '—' ?></div><?php endif; ?></td><?php endforeach; ?>
</tr><?php endforeach; endforeach; endforeach; ?>
</tbody></table></div></section><?php endif; ?>
<?php if ($gridReady && $days && $departments && $viewingSemesters): ?><section class="panel timetable-grid-filters"><div class="section-heading"><h2>Show in Timetable Grid</h2></div><div class="timetable-filter-groups">
<fieldset><legend>Departments</legend><div class="timetable-filter-options"><?php foreach ($departments as $department): ?><label><input type="checkbox" data-grid-filter="department" value="<?= (int)$department['id'] ?>" checked><span><?= h($department['department_name']) ?></span></label><?php endforeach; ?></div></fieldset>
<fieldset><legend>Semesters</legend><div class="timetable-filter-options"><?php foreach ($viewingSemesters as $semester): ?><label><input type="checkbox" data-grid-filter="semester" value="<?= (int)$semester['semester_id'] ?>" checked><span><?= h($semester['semester_full_name']) ?></span></label><?php endforeach; ?></div></fieldset>
<fieldset><legend>Days</legend><div class="timetable-filter-options"><?php foreach ($days as $day): ?><label><input type="checkbox" data-grid-filter="day" value="<?= (int)$day['day_id'] ?>" checked><span><?= h($day['day_name_1']) ?></span></label><?php endforeach; ?></div></fieldset>
</div></section><?php endif; ?>
<?php if ($gridReady): ?><?php if ($comboAccess): ?><div class="timetable-modal" id="timetable-course-faculty-combinations-modal" hidden><div class="timetable-modal-backdrop" data-modal-close></div><section class="timetable-modal-card timetable-combinations-card" role="dialog" aria-modal="true" aria-labelledby="timetable-course-faculty-combinations-title"><button type="button" class="timetable-modal-close" data-modal-close aria-label="Close">×</button><h2 id="timetable-course-faculty-combinations-title">Course - Faculty Combinations</h2><?php if (!$comboSchemaReady): ?><p class="muted">Import <code>migrations/036_course_faculty_combinations.sql</code> in phpMyAdmin to enable saved combinations.</p><?php elseif (!$courseOptions): ?><p class="muted">No courses are available for your assigned department(s).</p><?php else: ?><form id="timetable-combo-form" class="form-stack timetable-combo-form" method="post" action="course-faculty-combinations.php"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="timetable-combo-id"><input type="hidden" name="timetable_id" value="<?= (int)$viewId ?>"><label>Course<select class="js-select2" name="course_id" id="timetable-combo-course" data-placeholder="Select course" required><option value="">Select course</option><?php foreach ($courseOptions as $course): ?><option value="<?= (int)$course['id'] ?>"><?= h($course['department_code'] . ' · ' . $course['semester_code'] . ' · ' . $course['course_code'] . ' · ' . $course['course_name']) ?></option><?php endforeach; ?></select></label><label>Faculty <span class="hint">Select one or more</span><select class="js-select2" name="faculty_ids[]" id="timetable-combo-faculty" data-placeholder="Select faculty" multiple required><?php foreach ($facultyOptions as $person): ?><option value="<?= (int)$person['id'] ?>"><?= h($person['faculty_name'] . ($person['faculty_short_name'] ? ' · ' . $person['faculty_short_name'] : '')) ?></option><?php endforeach; ?></select></label><label>Unique Combination Code<input type="text" name="combination_code" id="timetable-combo-code" maxlength="60" required placeholder="e.g. EE-S4-POWER-LAB"></label><div class="form-actions"><button class="button" type="submit" id="timetable-combo-save">Save Combination</button><button class="button button-quiet" type="button" id="timetable-combo-cancel" hidden>Cancel Edit</button><span id="timetable-combo-status" role="status" aria-live="polite"></span></div></form><?php endif; ?><section class="slot-assignment-section"><h3>Saved Combinations</h3><?php if ($comboSchemaReady): ?><div class="table-wrap"><table class="summary-table"><thead><tr><th>Department</th><th>Semester</th><th>Course</th><th>Code</th><th>Faculty</th><th>Actions</th></tr></thead><tbody id="timetable-combo-list"><?php foreach ($courseFacultyCombinations as $combination): ?><tr data-combination-id="<?= (int)$combination['id'] ?>"><td><?= h($combination['department_label']) ?></td><td><?= h($combination['semester_label']) ?></td><td><?= h($combination['course_label']) ?></td><td><code><?= h($combination['combination_code']) ?></code></td><td><?= h($combination['faculty_names']) ?></td><td class="row-actions"><button type="button" class="combo-edit timetable-combo-edit" data-item="<?= h(json_encode($combination, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">Edit</button><button type="button" class="combo-delete timetable-combo-delete" data-id="<?= (int)$combination['id'] ?>">Delete</button></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section></section></div><?php endif; ?><script type="application/json" id="timetable-course-hours-courses"><?= json_encode($courseHoursCourses, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script><script type="application/json" id="timetable-course-hours-data"><?= json_encode($courseHoursRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script><script type="application/json" id="timetable-workload-data"><?= json_encode($facultyWorkloadRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<div class="timetable-modal" id="timetable-course-hours-modal" hidden><div class="timetable-modal-backdrop" data-modal-close></div><section class="timetable-modal-card timetable-summary-card" role="dialog" aria-modal="true" aria-labelledby="timetable-course-hours-title"><button type="button" class="timetable-modal-close" data-modal-close aria-label="Close">×</button><h2 id="timetable-course-hours-title">Course Hours</h2><div class="course-hours-filters"><label>Department<select class="js-select2" id="course-hours-department-select" data-placeholder="Select department"><option value="">Select department</option><?php foreach ($departments as $department): ?><option value="<?= (int)$department['id'] ?>"><?= h($department['department_name'] . ' · ' . $department['department_code']) ?></option><?php endforeach; ?></select></label><label>Course<select class="js-select2" id="course-hours-course-select" data-placeholder="Select course"><option value="">Select a department first</option></select></label></div><div class="table-wrap summary-table-wrap"><table class="summary-table"><thead><tr><th>Sl No.</th><th>Day</th><th>Hour</th><th>Time</th><th>Semester</th><th>Batch</th><th>Faculty</th></tr></thead><tbody id="course-hours-body"><tr><td colspan="7" class="muted">Select a department and course to view assigned hours.</td></tr></tbody></table></div></section></div>
<div class="timetable-modal" id="timetable-workload-modal" hidden><div class="timetable-modal-backdrop" data-modal-close></div><section class="timetable-modal-card timetable-summary-card" role="dialog" aria-modal="true" aria-labelledby="timetable-workload-title"><button type="button" class="timetable-modal-close" data-modal-close aria-label="Close">×</button><h2 id="timetable-workload-title">Faculty Workload</h2><label class="summary-department-label">Faculty<select class="js-select2" id="workload-faculty-select" data-placeholder="Select faculty"><option value="">Select faculty</option><?php foreach ($facultyOptions as $person): ?><option value="<?= (int)$person['id'] ?>"><?= h($person['faculty_name'] . ($person['faculty_short_name'] ? ' · ' . $person['faculty_short_name'] : '')) ?></option><?php endforeach; ?></select></label><div class="table-wrap summary-table-wrap"><table class="summary-table"><thead><tr><th>Sl No.</th><th>Course Code</th><th>Course Name</th><th>Semester</th><th>Course Hours</th><th>Number of Hours Assigned</th></tr></thead><tbody id="faculty-workload-body"><tr><td colspan="6" class="muted">Select a faculty member to view workload.</td></tr></tbody></table></div><p class="faculty-workload-total" id="faculty-workload-total">Grand total hours assigned: 0</p></section></div>
<script type="application/json" id="timetable-course-options"><?= json_encode($courseOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script><script type="application/json" id="timetable-course-faculty-combinations"><?= json_encode($courseFacultyCombinations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script><script type="application/json" id="timetable-faculty-options"><?= json_encode($facultyOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script><script type="application/json" id="timetable-faculty-slot-availability"><?= json_encode($facultySlotAvailability, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script><script type="application/json" id="timetable-hour-options"><?= json_encode($hours, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script><script type="application/json" id="timetable-day-options"><?= json_encode($days, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<div class="timetable-modal" id="timetable-slot-modal" hidden><div class="timetable-modal-backdrop" data-modal-close></div><section class="timetable-modal-card timetable-slot-card" role="dialog" aria-modal="true" aria-labelledby="timetable-slot-title"><button type="button" class="timetable-modal-close" data-modal-close aria-label="Close">×</button><h2 id="timetable-slot-title">Assign Courses</h2><div class="timetable-slot-context"><div class="timetable-slot-details" id="slot-modal-details" aria-label="Selected timetable slot"></div><?php if ($comboSchemaReady && $comboAccess): ?><label class="timetable-slot-combo-select"><span>Course - Faculty Combination</span><select class="js-select2" id="slot-course-faculty-combination" data-placeholder="Choose a saved combination"><option value="">Select a combination</option></select></label><?php endif; ?></div><form method="post" action="timetable-slot-course.php" class="form-stack timetable-slot-assignment-form" data-current-user-id="<?= (int)$_SESSION['user']['id'] ?>" data-user-role="<?= h($_SESSION['user']['role']) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="timetable_id" id="slot-timetable-id"><input type="hidden" name="day_id" id="slot-day-id"><input type="hidden" name="hour_id" id="slot-hour-id"><input type="hidden" name="department_id" id="slot-department-id"><input type="hidden" name="semester_id" id="slot-semester-id"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><label>Subject<select class="js-select2" data-placeholder="Select a subject" name="course_id" id="slot-course-select"><option value="">Select a subject</option></select></label><label><span class="batch-label-text">Batch <span class="muted batch-optional">(optional)</span></span><select class="js-select2" data-placeholder="Choose a batch" name="batch_id" id="slot-batch-select"><option value="none">No batch</option><?php foreach ($batches as $batch): ?><option value="<?= (int)$batch['id'] ?>"><?= h($batch['batch_name1'] . ' · ' . $batch['batch_name2']) ?></option><?php endforeach; ?></select></label><label class="slot-faculty-field">Faculty <span class="hint">Optional · select one or more</span><select class="js-select2" data-placeholder="Select faculty" name="faculty_ids[]" id="slot-faculty-select" multiple></select></label><div class="form-actions"><button class="button" type="submit">Add</button><button class="button button-quiet slot-edit-cancel" type="button" hidden>Cancel Edit</button></div></form><div class="slot-sweetalert" id="slot-sweetalert" hidden role="alertdialog" aria-modal="true" aria-labelledby="slot-alert-title" aria-describedby="slot-alert-message"><div class="slot-sweetalert-backdrop"></div><div class="slot-sweetalert-card"><div class="slot-sweetalert-icon" id="slot-alert-icon" aria-hidden="true"></div><h3 id="slot-alert-title"></h3><p id="slot-alert-message"></p><button type="button" class="button slot-sweetalert-ok">OK</button></div></div><section class="slot-assignment-section"><h3>Subjects in this slot</h3><div class="slot-assignment-list" id="slot-assignment-list"></div></section></section></div>
<script type="application/json" id="timetable-summary-data"><?= json_encode($summaryRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<div class="timetable-modal" id="timetable-summary-modal" hidden><div class="timetable-modal-backdrop" data-modal-close></div><section class="timetable-modal-card timetable-summary-card" role="dialog" aria-modal="true" aria-labelledby="timetable-summary-title"><button type="button" class="timetable-modal-close" data-modal-close aria-label="Close">×</button><h2 id="timetable-summary-title">Timetable Summary</h2><label class="summary-department-label">Department<select class="js-select2" id="summary-department-select" data-placeholder="Select Department"><option value="">Select Department</option><?php foreach ($departments as $department): ?><option value="<?= (int)$department['id'] ?>"><?= h($department['department_name']) ?></option><?php endforeach; ?></select></label><div class="table-wrap summary-table-wrap"><table class="summary-table"><thead><tr><th>Sl No.</th><th>Semester</th><th>Course Code</th><th>Course Short Name</th><th>Total Hours</th><th>Hours Assigned</th></tr></thead><tbody id="timetable-summary-body"><tr><td colspan="6" class="muted">Select a department to view its courses.</td></tr></tbody></table></div></section></div><?php endif; ?>
<?php endif; ?>
<?php if ($ready && !$viewing): ?><section class="panel"><div class="section-heading"><h2>Created Timetables</h2><span class="count-badge"><?= count($list) ?></span></div><?php if (!$list): ?><p class="muted">No timetables created yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Academic Year</th><th>Semesters</th><th>Editing</th><th>Published</th><th>Actions</th></tr></thead><tbody><?php foreach ($list as $row): ?><tr><td><?= h($row['ay_name_1']) ?></td><td style="white-space:pre-line"><?= h(str_replace(', ', "\n", $row['semester_labels'])) ?></td><td><?= (int)$row['allow_editing'] === 1 ? 'Allowed' : 'Admins only' ?></td><td><?= (int)$row['is_published'] === 1 ? 'Published' : 'Draft' ?></td><td class="row-actions timetable-actions"><a class="text-link" href="timetables.php?view=<?= (int)$row['id'] ?>">Assign Courses</a><a class="text-link" href="master-timetable.php?id=<?= (int)$row['id'] ?>" target="_blank" rel="noopener">View Master Timetable</a><?php if ($isAdmin || (int)$row['is_published'] === 1): ?><a class="text-link" href="generate-timetable.php?timetable_id=<?= (int)$row['id'] ?>">Generate Reports</a><?php endif; ?><?php if ($isAdmin): ?><form method="post" onsubmit="return confirm('Delete this timetable?')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="text-button" type="submit" name="action" value="delete">Delete</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?>
</main><?php appLayoutEnd(); ?>
