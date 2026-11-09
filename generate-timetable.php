<?php
declare(strict_types=1);
require __DIR__ . '/app.php';
requireRole(['admin', 'user']);

$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$userDepartmentIds = array_values(array_unique(array_map('intval', $_SESSION['user']['department_ids'] ?? [])));
$timetableTypes = [
    'master' => ['title' => 'Master Timetable', 'description' => 'Generate the full timetable for all departments and semesters.'],
    'department' => ['title' => 'Department Timetable', 'description' => 'Generate a timetable for one department across the selected semesters.'],
    'semester' => ['title' => 'Semester Timetable', 'description' => 'Generate a timetable for one semester across the departments.'],
    'department_semester' => ['title' => 'Department & Semester Timetable', 'description' => 'Generate one department’s timetable for one semester.'],
    'individual' => ['title' => 'Individual Timetable', 'description' => 'Generate separate timetable pages for selected faculty members.'],
];
$selectedType = (string)($_GET['type'] ?? '');
if (!array_key_exists($selectedType, $timetableTypes)) $selectedType = '';
$timetableId = filter_input(INPUT_GET, 'timetable_id', FILTER_VALIDATE_INT) ?: (filter_input(INPUT_POST, 'timetable_id', FILTER_VALIDATE_INT) ?: 0);
$error = '';
$success = '';
$timetable = null;
$departments = [];
$faculty = [];
$semesterOptions = [];
$generated = [];
$newGenerationIds = [];
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$hasSnapshotTable = false;
if (in_array('generated_timetables', $tables, true)) {
    $outputTypeColumn = $pdo->query("SHOW COLUMNS FROM generated_timetables LIKE 'output_type'")->fetch();
    $hasSnapshotTable = $outputTypeColumn && strpos((string)$outputTypeColumn['Type'], "'department_semester'") !== false;
}

if ($timetableId > 0) {
    $stmt = $pdo->prepare('SELECT t.id, t.is_published, ay.ay_name_1 FROM timetables t JOIN academic_years ay ON ay.id=t.academic_year_id WHERE t.id=?');
    $stmt->execute([$timetableId]);
    $timetable = $stmt->fetch() ?: null;
    if (!$timetable) {
        http_response_code(404);
        exit('Timetable not found.');
    }
    if (!$isAdmin && (int)$timetable['is_published'] !== 1) {
        http_response_code(403);
        exit('This timetable has not been published.');
    }
}

if ($timetable && $hasSnapshotTable) {
    $semesterStmt = $pdo->prepare('SELECT s.id,s.semester_full_name,s.semester_code FROM timetable_semesters ts JOIN semesters s ON s.id=ts.semester_id WHERE ts.timetable_id=? ORDER BY s.semester_year,s.id');
    $semesterStmt->execute([$timetableId]);
    $semesterOptions = $semesterStmt->fetchAll();
    if ($isAdmin) {
        $departments = $pdo->query('SELECT id,department_name,department_code FROM departments ORDER BY department_name')->fetchAll();
        $faculty = $pdo->query('SELECT f.id,f.faculty_name,f.faculty_short_name,f.department_id,d.department_code,g.id AS designation_id,g.designation_name FROM faculty f JOIN departments d ON d.id=f.department_id JOIN designations g ON g.id=f.designation_id ORDER BY d.department_name,g.faculty_priority,g.designation_name,f.faculty_name')->fetchAll();
    } elseif ($userDepartmentIds) {
        $ids = implode(',', $userDepartmentIds);
        $departments = $pdo->query("SELECT id,department_name,department_code FROM departments WHERE id IN ($ids) ORDER BY department_name")->fetchAll();
        $faculty = $pdo->query("SELECT f.id,f.faculty_name,f.faculty_short_name,f.department_id,d.department_code,g.id AS designation_id,g.designation_name FROM faculty f JOIN departments d ON d.id=f.department_id JOIN designations g ON g.id=f.designation_id WHERE f.department_id IN ($ids) ORDER BY d.department_name,g.faculty_priority,g.designation_name,f.faculty_name")->fetchAll();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        checkCsrf();
        if (($_POST['action'] ?? '') === 'delete_generation') {
            if (!$isAdmin) {
                http_response_code(403);
                exit('Only administrators can delete saved timetables.');
            }
            $deleteId = trim((string)($_POST['generation_id'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', $deleteId)) {
                $error = 'Invalid saved timetable ID.';
            } else {
                $deleteStmt = $pdo->prepare('DELETE FROM generated_timetables WHERE generation_id=? AND source_timetable_id=?');
                $deleteStmt->execute([$deleteId, $timetableId]);
                $success = $deleteStmt->rowCount() ? 'Saved timetable deleted.' : 'Saved timetable was not found.';
            }
        } else {
            $generationId = trim((string)($_POST['generation_id'] ?? ''));
            $outputType = (string)($_POST['output_type'] ?? $selectedType);
            $selectedType = array_key_exists($outputType, $timetableTypes) ? $outputType : '';
            $targetDepartmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT) ?: null;
            $targetSemesterId = in_array($outputType, ['semester', 'department_semester'], true) ? (filter_input(INPUT_POST, 'semester_id', FILTER_VALIDATE_INT) ?: null) : null;
            $facultyInput = $_POST['faculty_ids'] ?? [];
            if (!is_array($facultyInput)) $facultyInput = [];
            $facultyIds = array_values(array_unique(array_filter(array_map('intval', $facultyInput), static fn($id) => $id > 0)));
            if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', $generationId)) {
                $error = 'Enter a unique ID using letters, numbers, hyphens, or underscores (up to 80 characters).';
            } elseif (!$selectedType) {
                $error = 'Choose a timetable type.';
            } elseif (in_array($outputType, ['department', 'department_semester'], true) && !$targetDepartmentId) {
                $error = 'Choose a department.';
            } elseif ($outputType === 'individual' && !$facultyIds) {
                $error = 'Select at least one faculty member.';
            } elseif (in_array($outputType, ['semester', 'department_semester'], true) && !$targetSemesterId) {
                $error = 'Choose a semester.';
            } elseif (!$isAdmin && in_array($outputType, ['department', 'department_semester'], true) && !in_array($targetDepartmentId, $userDepartmentIds, true)) {
                $error = 'You can generate a timetable only for your assigned departments.';
            } else {
                $targetDepartment = null;
                $selectedFaculty = [];
                if (in_array($outputType, ['department', 'department_semester'], true)) {
                    $stmt = $pdo->prepare('SELECT id,department_name,department_code FROM departments WHERE id=?');
                    $stmt->execute([$targetDepartmentId]);
                    $targetDepartment = $stmt->fetch() ?: null;
                    if (!$targetDepartment) $error = 'The selected department was not found.';
                }
                if (in_array($outputType, ['semester', 'department_semester'], true) && !in_array($targetSemesterId, array_map('intval', array_column($semesterOptions, 'id')), true)) $error = 'The selected semester is not part of this timetable.';
                if ($outputType === 'individual') {
                    $placeholders = implode(',', array_fill(0, count($facultyIds), '?'));
                    $stmt = $pdo->prepare("SELECT f.id,f.faculty_name,f.faculty_short_name,f.department_id,d.department_code,g.designation_name FROM faculty f JOIN departments d ON d.id=f.department_id JOIN designations g ON g.id=f.designation_id WHERE f.id IN ($placeholders) ORDER BY d.department_name,g.faculty_priority,g.designation_name,f.faculty_name");
                    $stmt->execute($facultyIds);
                    $selectedFaculty = $stmt->fetchAll();
                    if (count($selectedFaculty) !== count($facultyIds)) $error = 'One or more selected faculty members were not found.';
                    elseif (!$isAdmin && array_filter($selectedFaculty, static fn($person) => !in_array((int)$person['department_id'], $userDepartmentIds, true))) $error = 'You can generate individual timetables only for faculty in your assigned departments.';
                }
                if (!$error) {
                    $generationSpecs = [];
                    $multiFaculty = $outputType === 'individual' && count($selectedFaculty) > 1;
                    if ($outputType === 'individual') {
                        foreach ($selectedFaculty as $person) {
                            $personGenerationId = $multiFaculty ? $generationId . '-F' . (int)$person['id'] : $generationId;
                            if (strlen($personGenerationId) > 80) { $error = 'The base ID is too long for separate faculty timetable IDs. Use 65 characters or fewer.'; break; }
                            $generationSpecs[] = ['id' => $personGenerationId, 'faculty' => $person, 'target' => $person['faculty_name']];
                        }
                    } else {
                        $targetLabel = match ($outputType) {
                            'department' => (string)$targetDepartment['department_name'],
                            'semester' => (string)($semesterOptions[array_search($targetSemesterId, array_map('intval', array_column($semesterOptions, 'id')), true)]['semester_full_name'] ?? ''),
                            'department_semester' => (string)$targetDepartment['department_name'] . ' · ' . (string)($semesterOptions[array_search($targetSemesterId, array_map('intval', array_column($semesterOptions, 'id')), true)]['semester_full_name'] ?? ''),
                            default => 'All departments and semesters',
                        };
                        $generationSpecs[] = ['id' => $generationId, 'faculty' => null, 'target' => $targetLabel];
                    }
                    if (!$error) {
                        try {
                            $preparedGenerations = [];
                            foreach ($generationSpecs as $spec) {
                                $snapshot = buildTimetableSnapshot($pdo, $timetableId, collegeName($pdo), $spec['id'], $outputType, $targetDepartment, $spec['faculty'], $targetSemesterId, (string)$timetable['ay_name_1'], (string)($_SESSION['user']['display_name'] ?? $_SESSION['user']['username']));
                                $preparedGenerations[] = ['spec' => $spec, 'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)];
                            }
                            $pdo->beginTransaction();
                            $insert = $pdo->prepare('INSERT INTO generated_timetables (generation_id,source_timetable_id,output_type,is_public,target_department_id,target_faculty_id,access_department_id,snapshot_json,generated_by_user_id,generated_by_name) VALUES (?,?,?,?,?,?,?,?,?,?)');
                            foreach ($preparedGenerations as $prepared) {
                                $spec = $prepared['spec'];
                                $person = $spec['faculty'];
                                $accessDepartmentId = in_array($outputType, ['department', 'department_semester'], true) ? (int)$targetDepartment['id'] : ($person ? (int)$person['department_id'] : null);
                                $insert->execute([
                                    $spec['id'], $timetableId, $outputType, (int)$timetable['is_published'],
                                    in_array($outputType, ['department', 'department_semester'], true) ? (int)$targetDepartment['id'] : null,
                                    $person ? (int)$person['id'] : null,
                                    $accessDepartmentId, $prepared['snapshot'], (int)$_SESSION['user']['id'],
                                    (string)($_SESSION['user']['display_name'] ?? $_SESSION['user']['username']),
                                ]);
                            }
                            $pdo->commit();
                            foreach ($preparedGenerations as $prepared) {
                                $spec = $prepared['spec'];
                                logUserActivity('timetable_generated', 'Timetable generated', 'Generated ' . str_replace('_', ' ', $outputType) . ' timetable ID ' . $spec['id'] . ($spec['target'] !== '' ? ' for ' . $spec['target'] : '') . '.', 'generated_timetable', $spec['id']);
                                $newGenerationIds[] = $spec['id'];
                            }
                            if (count($newGenerationIds) === 1) {
                                header('Location: generated-timetable.php?id=' . rawurlencode($newGenerationIds[0]));
                                exit;
                            }
                            header('Location: generated-timetable.php?ids=' . rawurlencode(implode(',', $newGenerationIds)));
                            exit;
                        } catch (PDOException $exception) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            if ($exception->getCode() === '23000') $error = 'One or more generated timetable IDs are already in use. Choose a different base ID.';
                            else throw $exception;
                        } catch (Throwable $exception) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            throw $exception;
                        }
                    }
                }
            }
        }
    }

    if ($isAdmin) {
        $stmt = $pdo->prepare('SELECT generation_id,output_type,access_department_id,created_at,generated_by_name FROM generated_timetables WHERE source_timetable_id=? ORDER BY created_at DESC,id DESC');
        $stmt->execute([$timetableId]);
    } elseif ($userDepartmentIds) {
        $in = implode(',', array_fill(0, count($userDepartmentIds), '?'));
        $stmt = $pdo->prepare("SELECT generation_id,output_type,access_department_id,created_at,generated_by_name FROM generated_timetables WHERE source_timetable_id=? AND is_public=1 AND (output_type IN ('master','semester') OR access_department_id IN ($in)) ORDER BY created_at DESC,id DESC");
        $stmt->execute(array_merge([$timetableId], $userDepartmentIds));
    } else {
        $stmt = $pdo->prepare("SELECT generation_id,output_type,access_department_id,created_at,generated_by_name FROM generated_timetables WHERE source_timetable_id=? AND is_public=1 AND output_type IN ('master','semester') ORDER BY created_at DESC,id DESC");
        $stmt->execute([$timetableId]);
    }
    $generated = $stmt->fetchAll();
}

function buildTimetableSnapshot(PDO $pdo, int $timetableId, string $college, string $generationId, string $outputType, ?array $targetDepartment, ?array $targetFaculty, ?int $targetSemesterId, string $academicYear, string $generatedBy): array {
    $days = $pdo->query('SELECT day_id,day_name_1,day_name_2 FROM days ORDER BY day_id')->fetchAll();
    $hours = $pdo->query('SELECT hour_id,hour_name_1,hour_name_2,hour_time_from,hour_time_to FROM hours ORDER BY hour_id')->fetchAll();
    $facultyRoster = $pdo->query('SELECT f.id,f.faculty_name,f.faculty_short_name,f.department_id,d.department_name,d.department_code,f.designation_id,g.designation_name,g.designation_short_name,g.faculty_priority FROM faculty f JOIN departments d ON d.id=f.department_id JOIN designations g ON g.id=f.designation_id ORDER BY d.department_name,g.faculty_priority,g.designation_name,f.faculty_name')->fetchAll();
    $daySnapshot = array_map(static fn($day) => ['id' => (int)$day['day_id'], 'name' => $day['day_name_1'], 'short_name' => $day['day_name_2']], $days);
    $semStmt = $pdo->prepare('SELECT s.id,s.semester_full_name,s.semester_short_name,s.semester_code,s.semester_type FROM timetable_semesters ts JOIN semesters s ON s.id=ts.semester_id WHERE ts.timetable_id=? ORDER BY s.semester_year,s.id');
    $semStmt->execute([$timetableId]);
    $semesters = $semStmt->fetchAll();
    if ($targetSemesterId) $semesters = array_values(array_filter($semesters, static fn($semester) => (int)$semester['id'] === $targetSemesterId));
    $departments = in_array($outputType, ['department', 'department_semester'], true)
        ? [$targetDepartment]
        : (in_array($outputType, ['master', 'semester'], true)
            ? $pdo->query("SELECT id,department_name,department_code FROM departments WHERE UPPER(department_code) NOT IN ('GS','WS') ORDER BY department_name")->fetchAll()
            : $pdo->query('SELECT id,department_name,department_code FROM departments ORDER BY department_name')->fetchAll());
    $assignmentSql = "SELECT a.assignment_id,a.day_id,a.hour_id,a.department_id,a.semester_id,a.course_id,a.batch_id,d.department_code,d.department_name,s.semester_code,s.semester_full_name,c.course_code,c.course_name,c.course_short_name,b.batch_name1,GROUP_CONCAT(DISTINCT f.id ORDER BY COALESCE(g.faculty_priority,100),f.faculty_name SEPARATOR ',') AS faculty_ids,GROUP_CONCAT(DISTINCT COALESCE(NULLIF(f.faculty_short_name,''),f.faculty_name) ORDER BY COALESCE(g.faculty_priority,100),f.faculty_name SEPARATOR ', ') AS faculty_names,GROUP_CONCAT(DISTINCT CASE WHEN f.id IS NOT NULL THEN CONCAT(f.department_id, '::', COALESCE(NULLIF(f.faculty_short_name,''),''), '::', f.faculty_name, '::', fd.department_name, '::', fd.department_code) END ORDER BY COALESCE(g.faculty_priority,100),f.faculty_name SEPARATOR '|||') AS faculty_expansions FROM timetable_slot_courses a JOIN departments d ON d.id=a.department_id JOIN semesters s ON s.id=a.semester_id JOIN courses c ON c.id=a.course_id LEFT JOIN batches b ON b.id=a.batch_id LEFT JOIN timetable_slot_course_faculty tscf ON tscf.assignment_id=a.assignment_id LEFT JOIN faculty f ON f.id=tscf.faculty_id LEFT JOIN designations g ON g.id=f.designation_id LEFT JOIN departments fd ON fd.id=f.department_id WHERE a.timetable_id=?";
    $params = [$timetableId];
    if (in_array($outputType, ['department', 'department_semester'], true)) { $assignmentSql .= ' AND a.department_id=?'; $params[] = (int)$targetDepartment['id']; }
    if (in_array($outputType, ['master', 'semester'], true)) {
        $visibleDepartmentIds = array_map('intval', array_column($departments, 'id'));
        if ($visibleDepartmentIds) $assignmentSql .= ' AND a.department_id IN (' . implode(',', $visibleDepartmentIds) . ')';
    }
    if ($targetSemesterId) { $assignmentSql .= ' AND a.semester_id=?'; $params[] = $targetSemesterId; }
    if ($outputType === 'individual') { $assignmentSql .= ' AND EXISTS (SELECT 1 FROM timetable_slot_course_faculty chosen WHERE chosen.assignment_id=a.assignment_id AND chosen.faculty_id=?)'; $params[] = (int)$targetFaculty['id']; }
    $assignmentSql .= ' GROUP BY a.assignment_id,a.day_id,a.hour_id,a.department_id,a.semester_id,a.course_id,a.batch_id,d.department_code,d.department_name,s.semester_code,s.semester_full_name,c.course_code,c.course_name,c.course_short_name,b.batch_name1 ORDER BY d.department_code,s.semester_code,(b.batch_name1 IS NULL),b.batch_name1,c.course_name';
    $stmt = $pdo->prepare($assignmentSql);
    $stmt->execute($params);
    $cellMap = [];
    $referenceMap = [];
    $facultyAssignments = [];
    foreach ($stmt->fetchAll() as $entry) {
        $key = implode(':', [$entry['day_id'], $entry['hour_id'], $entry['department_id'], $entry['semester_id']]);
        foreach (array_filter(explode(',', (string)($entry['faculty_ids'] ?? ''))) as $facultyId) $facultyAssignments[$key][(int)$facultyId] = true;
        $courseName = $entry['course_short_name'] ?: $entry['course_code'];
        $line = ($entry['batch_name1'] ? $entry['batch_name1'] . ' - ' : '') . $courseName;
        if ($entry['faculty_names']) $line .= ' (' . $entry['faculty_names'] . ')';
        if ($outputType === 'individual') $line = $entry['department_code'] . ' · ' . $entry['semester_code'] . ' · ' . $line;
        $cellMap[$key][] = $line;
        $departmentId = (int)$entry['department_id'];
        $referenceMap[$departmentId]['department'] = $entry['department_name'] . ' · ' . $entry['department_code'];
        $courseShort = trim((string)($entry['course_short_name'] ?? '')) ?: (string)$entry['course_code'];
        $referenceMap[$departmentId]['courses'][$courseShort . ' - ' . $entry['course_name']] = true;
        foreach (array_filter(explode('|||', (string)($entry['faculty_expansions'] ?? ''))) as $facultyEntry) {
            [$facultyDepartmentId, $short, $fullName, $facultyDepartmentName, $facultyDepartmentCode] = array_pad(explode('::', $facultyEntry, 5), 5, '');
            $facultyExpansion = trim($short) !== '' ? trim($short) . ' - ' . trim($fullName) : trim($fullName);
            if ($facultyExpansion !== '') {
                $facultyDepartmentId = (int)$facultyDepartmentId;
                $referenceMap[$facultyDepartmentId]['department'] = $facultyDepartmentName . ' · ' . $facultyDepartmentCode;
                $referenceMap[$facultyDepartmentId]['faculty'][$facultyExpansion] = true;
            }
        }
    }
    $hoursSnapshot = array_map(static fn($hour) => [
        'id' => (int)$hour['hour_id'],
        'name' => $hour['hour_name_2'] ?: $hour['hour_name_1'],
        'time' => date('g:i A', strtotime($hour['hour_time_from'])) . ' – ' . date('g:i A', strtotime($hour['hour_time_to'])),
    ], $hours);
    $rows = [];
    $semNames = array_column($semesters, 'semester_full_name');
    $semesterTypes = array_values(array_unique(array_map(static fn($semester) => strtoupper(trim((string)$semester['semester_type'])), $semesters)));
    $semesterTerm = count($semesterTypes) === 1 ? $semesterTypes[0] . ' SEMESTER' : 'ALL SEMESTERS';
    if ($outputType === 'individual') {
        foreach ($days as $day) {
            $cells = [];
            foreach ($hours as $hour) {
                $lines = [];
                foreach ($departments as $dept) {
                    foreach ($semesters as $semester) {
                        $key = implode(':', [$day['day_id'], $hour['hour_id'], $dept['id'], $semester['id']]);
                        array_push($lines, ...($cellMap[$key] ?? []));
                    }
                }
                $cells[] = $lines;
            }
            $rows[] = ['day' => 'Day ' . (int)$day['day_id'], 'cells' => $cells];
        }
        $title = 'Individual Timetable';
        $targetLabel = $targetFaculty['faculty_name'] . ($targetFaculty['faculty_short_name'] ? ' · ' . $targetFaculty['faculty_short_name'] : '');
    } else {
        foreach ($days as $day) {
            foreach ($departments as $dept) {
                foreach ($semesters as $semester) {
                    $cells = [];
                    foreach ($hours as $hour) {
                        $key = implode(':', [$day['day_id'], $hour['hour_id'], $dept['id'], $semester['id']]);
                        $cells[] = $cellMap[$key] ?? [];
                    }
                    $dayLabel = 'Day ' . (int)$day['day_id'];
                    $rows[] = ['day' => $dayLabel, 'department' => $dept['department_code'], 'semester' => $semester['semester_code'], 'cells' => $cells];
                }
            }
        }
        $title = match ($outputType) {
            'master' => 'Master Timetable',
            'department' => 'Department Timetable',
            'semester' => 'Semester Wise Timetable',
            'department_semester' => 'Department + Semester Timetable',
            default => 'Timetable',
        };
        $targetLabel = in_array($outputType, ['department', 'department_semester'], true)
            ? $targetDepartment['department_name'] . ' · ' . $targetDepartment['department_code']
            : ($outputType === 'semester' ? ($semesters[0]['semester_full_name'] ?? '') : 'All departments');
    }
    $reference = [];
    foreach ($referenceMap as $departmentReference) {
        $reference[] = [
            'department' => $departmentReference['department'],
            'faculty' => array_keys($departmentReference['faculty'] ?? []),
            'courses' => array_keys($departmentReference['courses'] ?? []),
        ];
    }
    $facultyAssignmentRows = [];
    foreach ($facultyAssignments as $key => $assignedFacultyIds) {
        [$dayId, $hourId, $departmentId, $semesterId] = array_map('intval', explode(':', $key));
        $facultyAssignmentRows[] = [
            'day_id' => $dayId,
            'hour_id' => $hourId,
            'department_id' => $departmentId,
            'semester_id' => $semesterId,
            'faculty_ids' => array_map('intval', array_keys($assignedFacultyIds)),
        ];
    }
    return [
        'generation_id' => $generationId,
        'title' => $title,
        'output_type' => $outputType,
        'target_label' => $targetLabel,
        'target_name' => $outputType === 'individual' ? $targetFaculty['faculty_name'] : (in_array($outputType, ['department', 'department_semester'], true) ? $targetDepartment['department_name'] : ''),
        'target_designation' => $outputType === 'individual' ? ($targetFaculty['designation_name'] ?? '') : '',
        'college_name' => $college,
        'academic_year' => $academicYear,
        'semesters' => $semNames,
        'semester_term' => $semesterTerm,
        'days' => $daySnapshot,
        'hours' => $hoursSnapshot,
        'departments' => array_map(static fn($department) => [
            'id' => (int)$department['id'],
            'name' => $department['department_name'],
            'code' => $department['department_code'],
        ], $departments),
        'rows' => $rows,
        'faculty_roster' => $facultyRoster,
        'faculty_assignments' => $facultyAssignmentRows,
        'reference' => $reference,
        'reference_scope' => 'assigned_entries',
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_by' => $generatedBy,
        'source_timetable_id' => $timetableId,
    ];
}

$college = collegeName($pdo);
appLayoutStart('Generate Reports', $college, 'timetable');
?>
<main class="dashboard wide generated-builder"><p class="eyebrow">Timetable snapshots</p><h1>Generate Reports</h1>
<?php if (!$hasSnapshotTable): ?><section class="panel"><h2>Database setup required</h2><p class="muted">Import <code>migrations/034_published_generated_timetables.sql</code> and <code>migrations/035_generated_timetable_semester_types.sql</code> in phpMyAdmin first.</p></section>
<?php elseif (!$timetable): ?><section class="panel"><h2>Timetable not found</h2><p class="muted">Return to <a href="timetables.php">Created Timetables</a> and choose a timetable to generate.</p></section>
<?php else: ?>
<p class="muted">Source: <?= h($timetable['ay_name_1']) ?> · Timetable <?= (int)$timetableId ?><?= (int)$timetable['is_published'] ? ' · Published' : ' · Draft (admin access)' ?></p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if ($selectedType === ''): ?>
<section class="generated-type-grid" aria-label="Timetable output types">
<?php foreach ($timetableTypes as $typeValue => $typeInfo): ?><a class="generated-type-card" href="generate-timetable.php?timetable_id=<?= (int)$timetableId ?>&amp;type=<?= h($typeValue) ?>"><span class="generated-type-icon" aria-hidden="true"><?= ['master'=>'▦','department'=>'⌂','semester'=>'◷','department_semester'=>'⊞','individual'=>'♙'][$typeValue] ?></span><strong><?= h($typeInfo['title']) ?></strong><span><?= h($typeInfo['description']) ?></span><span class="generated-type-link">Choose options →</span></a><?php endforeach; ?>
<a class="generated-type-card" href="faculty-not-engaged.php"><span class="generated-type-icon" aria-hidden="true">◷</span><strong>Faculties Not Engaged</strong><span>Find faculty members who are free in a saved master timetable.</span><span class="generated-type-link">View report →</span></a>
</section>
<?php else: ?>
<a class="back-link generated-type-back" href="generate-timetable.php?timetable_id=<?= (int)$timetableId ?>">← All timetable types</a>
<section class="panel generated-type-form-panel"><div class="section-heading"><div><p class="eyebrow">Generate type</p><h2><?= h($timetableTypes[$selectedType]['title']) ?></h2></div></div><p class="muted"><?= h($timetableTypes[$selectedType]['description']) ?></p>
<form method="post" class="form-stack generated-type-form" id="generate-timetable-form"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="timetable_id" value="<?= (int)$timetableId ?>"><input type="hidden" name="output_type" value="<?= h($selectedType) ?>">
<label class="generated-id-field">Unique Timetable ID<input name="generation_id" maxlength="<?= $selectedType === 'individual' ? '65' : '80' ?>" pattern="[A-Za-z0-9_-]+" placeholder="For example, AY26-27-<?= strtoupper(h($selectedType)) ?>-01" required value="<?= h((string)($_POST['generation_id'] ?? '')) ?>"><span class="hint"><?php if ($selectedType === 'individual'): ?>For multiple selections, each saved page gets a unique ID by appending its faculty ID (for example, BASE-F12).<?php else: ?>This ID is saved with the generated timetable and can be used to retrieve it later.<?php endif; ?></span></label>
<?php if (in_array($selectedType, ['department','department_semester'], true)): ?><label>Department<select name="department_id" required><option value="">Select department</option><?php foreach ($departments as $dept): ?><option value="<?= (int)$dept['id'] ?>" <?= (string)($_POST['department_id'] ?? '') === (string)$dept['id'] ? 'selected' : '' ?>><?= h($dept['department_name'] . ' · ' . $dept['department_code']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<?php if (in_array($selectedType, ['semester','department_semester'], true)): ?><label>Semester<select name="semester_id" required><option value="">Select semester</option><?php foreach ($semesterOptions as $semester): ?><option value="<?= (int)$semester['id'] ?>" <?= (string)($_POST['semester_id'] ?? '') === (string)$semester['id'] ? 'selected' : '' ?>><?= h($semester['semester_full_name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<?php if ($selectedType === 'individual'): $postedFacultyIds = array_map('strval', is_array($_POST['faculty_ids'] ?? null) ? $_POST['faculty_ids'] : []); ?>
<div class="generated-faculty-filters"><label>Filter departments<select class="js-select2" id="faculty-filter-departments" multiple data-placeholder="All departments"><?php foreach ($departments as $dept): ?><option value="<?= (int)$dept['id'] ?>"><?= h($dept['department_name'] . ' · ' . $dept['department_code']) ?></option><?php endforeach; ?></select></label><label>Filter designations<select class="js-select2" id="faculty-filter-designations" multiple data-placeholder="All designations"><?php $designationOptions = []; foreach ($faculty as $person) $designationOptions[(int)$person['designation_id']] = $person['designation_name']; asort($designationOptions); foreach ($designationOptions as $designationId => $designationName): ?><option value="<?= (int)$designationId ?>"><?= h($designationName) ?></option><?php endforeach; ?></select></label><label class="generated-faculty-select-visible"><input type="checkbox" id="select-visible-faculty"><span>Select visible faculty</span></label></div>
<div class="table-wrap generated-faculty-table-wrap"><table id="generated-faculty-table"><thead><tr><th>Select</th><th>Faculty Name</th><th>Short Name</th><th>Department</th><th>Designation</th></tr></thead><tbody><?php foreach ($faculty as $person): ?><tr data-department="<?= (int)$person['department_id'] ?>" data-designation="<?= (int)$person['designation_id'] ?>"><td><input type="checkbox" name="faculty_ids[]" value="<?= (int)$person['id'] ?>" <?= in_array((string)$person['id'], $postedFacultyIds, true) ? 'checked' : '' ?> aria-label="Select <?= h($person['faculty_name']) ?>"></td><td><?= h($person['faculty_name']) ?></td><td><?= h($person['faculty_short_name']) ?></td><td><?= h($person['department_code']) ?></td><td><?= h($person['designation_name']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<div class="form-actions"><button class="button" type="submit"><?= $selectedType === 'individual' ? 'Generate Selected Timetables' : 'Generate Timetable' ?></button></div></form></section>
<?php endif; ?>
<?php if ($success): ?><div class="success" role="status"><?= h($success) ?></div><?php endif; ?>
<?php if ($newGenerationIds): ?><section class="panel"><h2>Generated faculty timetable pages</h2><ul class="generated-result-links"><?php foreach ($newGenerationIds as $generationId): ?><li><code><?= h($generationId) ?></code> <a class="text-link" href="generated-timetable.php?id=<?= rawurlencode($generationId) ?>" target="_blank" rel="noopener">Open / Download PDF</a></li><?php endforeach; ?></ul></section><?php endif; ?>
<section class="panel"><div class="section-heading"><h2>Saved generations</h2><span class="count-badge"><?= count($generated) ?></span></div><?php if (!$generated): ?><p class="muted">No saved generations for this timetable yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Timetable ID</th><th>Type</th><th>Created</th><th>Created By</th><th>Actions</th></tr></thead><tbody><?php foreach ($generated as $item): $typeNames = ['master'=>'Master Timetable','department'=>'Department Timetable','individual'=>'Individual Timetable','semester'=>'Semester Timetable','department_semester'=>'Department & Semester Timetable']; ?><tr><td><code><?= h($item['generation_id']) ?></code></td><td><?= h($typeNames[$item['output_type']] ?? ucfirst($item['output_type'])) ?></td><td><?= h($item['created_at']) ?></td><td><?= h($item['generated_by_name']) ?></td><td class="row-actions"><a class="text-link" href="generated-timetable.php?id=<?= rawurlencode($item['generation_id']) ?>">View / Download PDF</a><?php if ($isAdmin): ?><form method="post" onsubmit="return confirm('Delete saved timetable <?= h($item['generation_id']) ?>? This cannot be undone.')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="timetable_id" value="<?= (int)$timetableId ?>"><input type="hidden" name="generation_id" value="<?= h($item['generation_id']) ?>"><button class="text-button" type="submit" name="action" value="delete_generation">Delete</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<p><a class="back-link" href="timetables.php?view=<?= (int)$timetableId ?>">← Back to Assign Courses</a></p>
<?php endif; ?></main>
<?php appLayoutEnd(); ?>
