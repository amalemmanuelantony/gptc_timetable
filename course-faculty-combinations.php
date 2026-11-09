<?php
declare(strict_types=1);
require __DIR__ . '/app.php';
requireRole(['admin', 'user']);

$college = collegeName($pdo);
$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$departmentIds = array_values(array_unique(array_filter(array_map('intval', $_SESSION['user']['department_ids'] ?? []), static fn($id) => $id > 0)));
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$timetableColumns = in_array('timetables', $tables, true) ? $pdo->query('SHOW COLUMNS FROM timetables')->fetchAll(PDO::FETCH_COLUMN) : [];
$schemaReady = in_array('course_faculty_combinations', $tables, true) && in_array('course_faculty_combination_faculty', $tables, true) && in_array('course_faculty_combinations_enabled', $timetableColumns, true);
$respond = static function (int $status, array $payload): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$courseForAccount = static function (int $courseId) use ($pdo, $isAdmin, $departmentIds): array|false {
    if (!$courseId) return false;
    $sql = 'SELECT c.id,c.department_id,c.semester_id,c.course_code,c.course_name,d.department_name,d.department_code,s.semester_full_name,s.semester_code FROM courses c JOIN departments d ON d.id=c.department_id JOIN semesters s ON s.id=c.semester_id WHERE c.id=?';
    $params = [$courseId];
    if (!$isAdmin) {
        if (!$departmentIds) return false;
        $sql .= ' AND c.department_id IN (' . implode(',', array_fill(0, count($departmentIds), '?')) . ')';
        array_push($params, ...$departmentIds);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$schemaReady) $respond(503, ['success' => false, 'message' => 'Import migrations/036_course_faculty_combinations.sql and migrations/038_timetable_course_faculty_combinations_permission.sql first.']);
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) $respond(400, ['success' => false, 'message' => 'Your form token expired. Reload and try again.']);
    if (!$isAdmin) {
        $timetableId = filter_var((string)($_POST['timetable_id'] ?? ''), FILTER_VALIDATE_INT);
        if (!$timetableId || $timetableId < 1) $respond(403, ['success' => false, 'message' => 'Course - Faculty Combinations are not enabled for this timetable.']);
        $permission = $pdo->prepare('SELECT course_faculty_combinations_enabled FROM timetables WHERE id=?');
        $permission->execute([(int)$timetableId]);
        if ((int)$permission->fetchColumn() !== 1) $respond(403, ['success' => false, 'message' => 'Course - Faculty Combinations are not enabled for this timetable.']);
    }
    $action = (string)($_POST['action'] ?? '');
    $id = filter_var((string)($_POST['id'] ?? ''), FILTER_VALIDATE_INT);
    if ($action === 'delete') {
        $lookup = $pdo->prepare('SELECT course_id,combination_code FROM course_faculty_combinations WHERE id=?');
        $lookup->execute([(int)$id]);
        $savedCombination = $lookup->fetch();
        if (!$id || !$savedCombination || !$courseForAccount((int)$savedCombination['course_id'])) $respond(404, ['success' => false, 'message' => 'Combination not found or outside your department access.']);
        $stmt = $pdo->prepare('DELETE FROM course_faculty_combinations WHERE id=?');
        $stmt->execute([(int)$id]);
        logUserActivity('course_faculty_combination_delete', 'Course-faculty combination deleted', 'Deleted combination code ' . $savedCombination['combination_code'] . '.', 'course_faculty_combination', (string)$id);
        $respond(200, ['success' => true, 'message' => 'Combination deleted.']);
    }
    if ($action !== 'save') $respond(400, ['success' => false, 'message' => 'Invalid request.']);
    $courseId = filter_var((string)($_POST['course_id'] ?? ''), FILTER_VALIDATE_INT);
    $code = trim((string)($_POST['combination_code'] ?? ''));
    $facultyInput = $_POST['faculty_ids'] ?? [];
    $courseRecord = $courseId ? $courseForAccount((int)$courseId) : false;
    if (!$courseId || !$courseRecord) $respond(403, ['success' => false, 'message' => 'Choose a course in one of your assigned departments.']);
    if ($code === '' || mb_strlen($code) > 60) $respond(422, ['success' => false, 'message' => 'Enter a combination code of 1 to 60 characters.']);
    if (!is_array($facultyInput) || !$facultyInput) $respond(422, ['success' => false, 'message' => 'Select at least one faculty member.']);
    $facultyIds = [];
    foreach ($facultyInput as $value) {
        $facultyId = filter_var((string)$value, FILTER_VALIDATE_INT);
        if (!$facultyId || $facultyId < 1) $respond(422, ['success' => false, 'message' => 'Select valid faculty members.']);
        $facultyIds[] = (int)$facultyId;
    }
    $facultyIds = array_values(array_unique($facultyIds));
    $placeholders = implode(',', array_fill(0, count($facultyIds), '?'));
    $checkFaculty = $pdo->prepare("SELECT COUNT(*) FROM faculty WHERE id IN ($placeholders)");
    $checkFaculty->execute($facultyIds);
    if ((int)$checkFaculty->fetchColumn() !== count($facultyIds)) $respond(422, ['success' => false, 'message' => 'One or more selected faculty members no longer exist.']);
    $existingId = ($id && (int)$id > 0) ? (int)$id : 0;
    if ($existingId) {
        $stmt = $pdo->prepare('SELECT course_id FROM course_faculty_combinations WHERE id=?');
        $stmt->execute([$existingId]);
        $existingCourseId = (int)$stmt->fetchColumn();
        if (!$existingCourseId || !$courseForAccount($existingCourseId)) $respond(404, ['success' => false, 'message' => 'Combination not found or outside your department access.']);
    }
    try {
        $pdo->beginTransaction();
        if ($existingId) {
            $stmt = $pdo->prepare('UPDATE course_faculty_combinations SET course_id=?,combination_code=? WHERE id=?');
            $stmt->execute([(int)$courseId, $code, $existingId]);
            $combinationId = $existingId;
            $pdo->prepare('DELETE FROM course_faculty_combination_faculty WHERE combination_id=?')->execute([$combinationId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO course_faculty_combinations (course_id,combination_code) VALUES (?,?)');
            $stmt->execute([(int)$courseId, $code]);
            $combinationId = (int)$pdo->lastInsertId();
        }
        $insertFaculty = $pdo->prepare('INSERT INTO course_faculty_combination_faculty (combination_id,faculty_id) VALUES (?,?)');
        foreach ($facultyIds as $facultyId) $insertFaculty->execute([$combinationId, $facultyId]);
        $pdo->commit();
        logUserActivity($existingId ? 'course_faculty_combination_edit' : 'course_faculty_combination_add', $existingId ? 'Course-faculty combination edited' : 'Course-faculty combination added', ($existingId ? 'Updated' : 'Added') . ' combination ' . $code . ' for ' . $courseRecord['course_code'] . ' (' . $courseRecord['course_name'] . ') with ' . count($facultyIds) . ' faculty member(s).', 'course_faculty_combination', (string)$combinationId);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($exception->getCode() === '23000') $respond(409, ['success' => false, 'message' => 'That combination code is already in use. Choose another code.']);
        $respond(500, ['success' => false, 'message' => 'Could not save this combination. Please try again.']);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $respond(500, ['success' => false, 'message' => 'Could not save this combination. Please try again.']);
    }
    $sql = 'SELECT cf.id,cf.combination_code,c.id AS course_id,c.course_code,c.course_name,c.department_id,d.department_name,d.department_code,c.semester_id,s.semester_full_name,s.semester_code,GROUP_CONCAT(f.id ORDER BY f.faculty_name SEPARATOR ",") AS faculty_ids,GROUP_CONCAT(CONCAT(f.faculty_name,CASE WHEN f.faculty_short_name IS NOT NULL AND f.faculty_short_name<>"" THEN CONCAT(" (",f.faculty_short_name,")") ELSE "" END) ORDER BY f.faculty_name SEPARATOR ", ") AS faculty_names FROM course_faculty_combinations cf JOIN courses c ON c.id=cf.course_id JOIN departments d ON d.id=c.department_id JOIN semesters s ON s.id=c.semester_id JOIN course_faculty_combination_faculty cff ON cff.combination_id=cf.id JOIN faculty f ON f.id=cff.faculty_id WHERE cf.id=? GROUP BY cf.id,cf.combination_code,c.id,c.course_code,c.course_name,c.department_id,d.department_name,d.department_code,c.semester_id,s.semester_full_name,s.semester_code';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$combinationId]);
    $item = $stmt->fetch();
    $item['id'] = (int)$item['id'];
    $item['course_id'] = (int)$item['course_id'];
    $item['department_id'] = (int)$item['department_id'];
    $item['semester_id'] = (int)$item['semester_id'];
    $item['faculty_ids'] = array_map('intval', explode(',', (string)$item['faculty_ids']));
    $item['course_label'] = $item['course_code'] . ' · ' . $item['course_name'];
    $item['department_label'] = $item['department_name'] . ' · ' . $item['department_code'];
    $item['semester_label'] = $item['semester_full_name'] . ' · ' . $item['semester_code'];
    $respond(200, ['success' => true, 'message' => 'Course-faculty combination saved.', 'item' => $item]);
}

$respond(405, ['success' => false, 'message' => 'Use the Course - Faculty Combinations window in Assign Courses.']);
