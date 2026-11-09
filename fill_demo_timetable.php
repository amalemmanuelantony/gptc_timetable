<?php
declare(strict_types=1);

// Run from the project root with:
//   php tools/fill_demo_timetable.php [--timetable=ID] [--apply]
// Without --apply, this only previews the assignments and makes no database changes.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app.php';

$timetableId = null;
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') {
        $apply = true;
    } elseif (str_starts_with($argument, '--timetable=')) {
        $timetableId = filter_var(substr($argument, strlen('--timetable=')), FILTER_VALIDATE_INT) ?: null;
    } else {
        fwrite(STDERR, "Usage: php tools/fill_demo_timetable.php [--timetable=ID] [--apply]\n");
        exit(2);
    }
}

try {
    if ($timetableId === null) {
        $timetableId = (int)$pdo->query('SELECT id FROM timetables ORDER BY id DESC LIMIT 1')->fetchColumn();
    }
    if ($timetableId < 1) {
        throw new RuntimeException('No timetable exists. Create a timetable first.');
    }

    $stmt = $pdo->prepare('SELECT t.id, ay.ay_name_1 FROM timetables t JOIN academic_years ay ON ay.id=t.academic_year_id WHERE t.id=?');
    $stmt->execute([$timetableId]);
    $timetable = $stmt->fetch();
    if (!$timetable) {
        throw new RuntimeException("Timetable {$timetableId} was not found.");
    }

    $semesterStmt = $pdo->prepare('SELECT s.id, s.semester_full_name FROM timetable_semesters ts JOIN semesters s ON s.id=ts.semester_id WHERE ts.timetable_id=? ORDER BY s.semester_year, s.id');
    $semesterStmt->execute([$timetableId]);
    $semesters = $semesterStmt->fetchAll();
    $days = $pdo->query('SELECT day_id, day_name_1 FROM days ORDER BY day_id')->fetchAll();
    $hours = $pdo->query('SELECT hour_id, hour_name_2 FROM hours ORDER BY hour_id')->fetchAll();
    $departments = $pdo->query('SELECT id, department_code FROM departments ORDER BY id')->fetchAll();
    $batches = array_map('intval', $pdo->query('SELECT id FROM batches ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $admin = $pdo->query("SELECT id, COALESCE(NULLIF(display_name,''),username) AS display_name FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetch();
    if (!$semesters || !$days || !$hours || !$departments) {
        throw new RuntimeException('This timetable needs at least one semester, day, hour, and department.');
    }
    if (!$admin) {
        throw new RuntimeException('An admin account is required to record the demo assignments.');
    }

    $courseStmt = $pdo->prepare('SELECT id, department_id, semester_id, course_code, course_name, hours_per_week FROM courses WHERE semester_id IN (' . implode(',', array_fill(0, count($semesters), '?')) . ') ORDER BY id');
    $courseStmt->execute(array_map(static fn($row) => (int)$row['id'], $semesters));
    $coursesBySection = [];
    foreach ($courseStmt->fetchAll() as $course) {
        $key = (int)$course['department_id'] . ':' . (int)$course['semester_id'];
        $coursesBySection[$key][] = $course;
    }

    $facultyStmt = $pdo->query('SELECT id, department_id, faculty_name FROM faculty ORDER BY faculty_name');
    $facultyByDepartment = [];
    foreach ($facultyStmt->fetchAll() as $person) {
        $facultyByDepartment[(int)$person['department_id']][] = $person;
    }

    $existingStmt = $pdo->prepare('SELECT a.assignment_id, a.day_id, a.hour_id, a.department_id, a.semester_id, a.course_id, a.batch_id, tscf.faculty_id FROM timetable_slot_courses a LEFT JOIN timetable_slot_course_faculty tscf ON tscf.assignment_id=a.assignment_id WHERE a.timetable_id=?');
    $existingStmt->execute([$timetableId]);
    $existing = $existingStmt->fetchAll();
    $occupiedCells = [];
    $existingCoursePeriods = [];
    $facultyBusy = [];
    $batchBusy = [];
    foreach ($existing as $row) {
        $period = (int)$row['day_id'] . ':' . (int)$row['hour_id'];
        $cell = $period . ':' . (int)$row['department_id'] . ':' . (int)$row['semester_id'];
        $section = (int)$row['department_id'] . ':' . (int)$row['semester_id'];
        $occupiedCells[$cell] = true;
        if ($row['course_id'] !== null) {
            $existingCoursePeriods[$section . ':' . (int)$row['course_id']][$period] = true;
        }
        if ($row['faculty_id'] !== null) {
            $facultyBusy[$period][(int)$row['faculty_id']] = true;
        }
        if ($row['batch_id'] !== null) {
            $batchBusy[$period][(int)$row['batch_id']] = true;
        }
    }

    $totalCells = count($days) * count($hours) * count($departments) * count($semesters);
    $allSlots = [];
    foreach ($days as $day) {
        foreach ($hours as $hour) {
            $allSlots[] = ['day_id' => (int)$day['day_id'], 'hour_id' => (int)$hour['hour_id']];
        }
    }
    $plan = [];
    $alreadyFilled = count($occupiedCells);
    $missingSections = [];
    $excludedSections = [];

    // Build a randomized list of course periods per class, using weekly hours as
    // weights. Existing courses in a populated cell count once toward weekly hours.
    foreach ($departments as $department) {
        $departmentId = (int)$department['id'];
        foreach ($semesters as $semester) {
            $semesterId = (int)$semester['id'];
            $sectionKey = $departmentId . ':' . $semesterId;
            $openSlots = array_values(array_filter($allSlots, static function ($slot) use ($occupiedCells, $departmentId, $semesterId): bool {
                $cell = $slot['day_id'] . ':' . $slot['hour_id'] . ':' . $departmentId . ':' . $semesterId;
                return !isset($occupiedCells[$cell]);
            }));
            if (!$openSlots) {
                continue;
            }
            if (in_array($department['department_code'], ['GS', 'WS'], true)) {
                $excludedSections[] = [
                    'department_code' => $department['department_code'],
                    'semester_name' => $semester['semester_full_name'],
                    'empty_cells' => count($openSlots),
                ];
                continue;
            }
            $sectionCourses = $coursesBySection[$sectionKey] ?? [];
            if (!$sectionCourses) {
                $missingSections[] = [
                    'department_code' => $department['department_code'],
                    'semester_name' => $semester['semester_full_name'],
                    'empty_cells' => count($openSlots),
                ];
                continue;
            }
            if (empty($facultyByDepartment[$departmentId])) {
                throw new RuntimeException('Cannot assign faculty for department ' . $department['department_code'] . ': its Faculty list is empty.');
            }

            $courseTokens = [];
            foreach ($sectionCourses as $course) {
                $courseKey = $sectionKey . ':' . (int)$course['id'];
                $alreadyAssignedHours = count($existingCoursePeriods[$courseKey] ?? []);
                $targetHours = max(0, (int)round((float)$course['hours_per_week']) - $alreadyAssignedHours);
                for ($i = 0; $i < $targetHours; $i++) {
                    $courseTokens[] = $course;
                }
            }
            shuffle($courseTokens);
            while (count($courseTokens) < count($openSlots)) {
                $courseTokens[] = $sectionCourses[array_rand($sectionCourses)];
            }
            if (count($courseTokens) > count($openSlots)) {
                $courseTokens = array_slice($courseTokens, 0, count($openSlots));
            }
            shuffle($openSlots);
            foreach ($openSlots as $index => $slot) {
                $course = $courseTokens[$index];
                $plan[] = [
                    'day_id' => $slot['day_id'], 'hour_id' => $slot['hour_id'],
                    'department_id' => $departmentId, 'department_code' => $department['department_code'],
                    'semester_id' => $semesterId, 'semester_name' => $semester['semester_full_name'],
                    'course_id' => (int)$course['id'], 'course_code' => $course['course_code'],
                    'course_name' => $course['course_name'],
                ];
            }
        }
    }

    // Allocate one distinct faculty member for each simultaneous class in a
    // department. The existing assignment map also reserves faculty already busy.
    $tasksByPeriod = [];
    foreach ($plan as $index => $task) {
        $period = $task['day_id'] . ':' . $task['hour_id'];
        $tasksByPeriod[$period][] = $index;
    }
    foreach ($tasksByPeriod as $period => $taskIndexes) {
        shuffle($taskIndexes);
        $busy = $facultyBusy[$period] ?? [];
        foreach ($taskIndexes as $index) {
            $task = $plan[$index];
            $facultyChoices = $facultyByDepartment[$task['department_id']] ?? [];
            shuffle($facultyChoices);
            $chosen = null;
            foreach ($facultyChoices as $person) {
                $facultyId = (int)$person['id'];
                if (!isset($busy[$facultyId])) {
                    $chosen = $person;
                    $busy[$facultyId] = true;
                    break;
                }
            }
            if (!$chosen) {
                throw new RuntimeException('Not enough clash-free faculty for ' . $task['department_code'] . ' during day/hour ' . $period . '. No assignments were written.');
            }
            $plan[$index]['faculty_id'] = (int)$chosen['id'];
            $plan[$index]['faculty_name'] = $chosen['faculty_name'];
        }
    }

    // Add batches to a random subset of slots, ensuring a batch is never assigned
    // twice in the same timetable day/hour, including against existing assignments.
    foreach ($plan as &$task) {
        $period = $task['day_id'] . ':' . $task['hour_id'];
        $task['batch_id'] = null;
        if ($batches && mt_rand(1, 100) <= 30) {
            $batchChoices = $batches;
            shuffle($batchChoices);
            foreach ($batchChoices as $batchId) {
                if (!isset($batchBusy[$period][$batchId])) {
                    $task['batch_id'] = $batchId;
                    $batchBusy[$period][$batchId] = true;
                    break;
                }
            }
        }
    }
    unset($task);

    $totalToFill = $totalCells - $alreadyFilled;
    fwrite(STDOUT, "Timetable {$timetableId} · {$timetable['ay_name_1']}\n");
    fwrite(STDOUT, "Grid cells: {$totalCells}; already populated: {$alreadyFilled}; empty cells: {$totalToFill}\n");
    fwrite(STDOUT, 'Planned assignments: ' . count($plan) . '; with batches: ' . count(array_filter($plan, static fn($task) => $task['batch_id'] !== null)) . "\n");
    fwrite(STDOUT, "Every planned slot has a department faculty member, with timetable-hour clash checks applied.\n");
    if ($missingSections) {
        fwrite(STDOUT, "Sections with empty cells but no courses:\n");
        foreach ($missingSections as $missing) {
            fwrite(STDOUT, '  ' . $missing['department_code'] . ' · ' . $missing['semester_name'] . ' (' . $missing['empty_cells'] . " cells)\n");
        }
    }
    if ($excludedSections) {
        fwrite(STDOUT, "Sections explicitly excluded (no demo assignments will be added):\n");
        foreach ($excludedSections as $excluded) {
            fwrite(STDOUT, '  ' . $excluded['department_code'] . ' · ' . $excluded['semester_name'] . ' (' . $excluded['empty_cells'] . " cells)\n");
        }
    }
    if (!$apply) {
        fwrite(STDOUT, "Preview only. Add --apply to write the plan; it will stop if any non-excluded sections lack courses.\n");
        exit(0);
    }
    if ($missingSections) {
        throw new RuntimeException('Cannot fill the complete timetable: add courses for every listed department and semester first. No assignments were written.');
    }

    $pdo->beginTransaction();
    $occupiedCheck = $pdo->prepare('SELECT COUNT(*) FROM timetable_slot_courses WHERE timetable_id=? AND day_id=? AND hour_id=? AND department_id=? AND semester_id=?');
    $facultyCheck = $pdo->prepare('SELECT COUNT(*) FROM timetable_slot_course_faculty tscf JOIN timetable_slot_courses a ON a.assignment_id=tscf.assignment_id WHERE a.timetable_id=? AND a.day_id=? AND a.hour_id=? AND tscf.faculty_id=?');
    $batchCheck = $pdo->prepare('SELECT COUNT(*) FROM timetable_slot_courses WHERE timetable_id=? AND day_id=? AND hour_id=? AND batch_id=?');
    $insert = $pdo->prepare('INSERT INTO timetable_slot_courses (timetable_id,day_id,hour_id,department_id,semester_id,course_id,batch_id,assigned_by_user_id,assigned_by_name) VALUES (?,?,?,?,?,?,?,?,?)');
    $insertFaculty = $pdo->prepare('INSERT INTO timetable_slot_course_faculty (assignment_id,faculty_id) VALUES (?,?)');
    foreach ($plan as $task) {
        $occupiedCheck->execute([$timetableId, $task['day_id'], $task['hour_id'], $task['department_id'], $task['semester_id']]);
        $facultyCheck->execute([$timetableId, $task['day_id'], $task['hour_id'], $task['faculty_id']]);
        if ((int)$occupiedCheck->fetchColumn() > 0 || (int)$facultyCheck->fetchColumn() > 0) {
            throw new RuntimeException('The timetable changed while the demo schedule was being prepared. Rerun the preview; no data was committed.');
        }
        if ($task['batch_id'] !== null) {
            $batchCheck->execute([$timetableId, $task['day_id'], $task['hour_id'], $task['batch_id']]);
            if ((int)$batchCheck->fetchColumn() > 0) {
                throw new RuntimeException('A selected batch became busy while the demo schedule was being prepared. Rerun the preview; no data was committed.');
            }
        }
        $insert->execute([$timetableId, $task['day_id'], $task['hour_id'], $task['department_id'], $task['semester_id'], $task['course_id'], $task['batch_id'], (int)$admin['id'], $admin['display_name']]);
        $insertFaculty->execute([(int)$pdo->lastInsertId(), $task['faculty_id']]);
    }
    $pdo->commit();
    fwrite(STDOUT, 'Completed. Added ' . count($plan) . " demo assignments.\n");
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Could not fill timetable: ' . $error->getMessage() . "\n");
    exit(1);
}
