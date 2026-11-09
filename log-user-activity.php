<?php
declare(strict_types=1);
require __DIR__ . '/app.php';
requireRole(['admin', 'user']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $respond(405, ['success' => false, 'message' => 'POST required.']);
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) $respond(400, ['success' => false, 'message' => 'Invalid form token.']);
$event = (string)($_POST['action'] ?? '');
$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$departmentIds = array_values(array_unique(array_map('intval', $_SESSION['user']['department_ids'] ?? [])));
$timetableId = filter_var((string)($_POST['timetable_id'] ?? ''), FILTER_VALIDATE_INT);
$targetId = filter_var((string)($_POST['target_id'] ?? ''), FILTER_VALIDATE_INT);
$extraId = filter_var((string)($_POST['extra_id'] ?? ''), FILTER_VALIDATE_INT);
if (!$timetableId || !$targetId) $respond(422, ['success' => false, 'message' => 'Choose a valid report target.']);
$timetableStmt = $pdo->prepare('SELECT id FROM timetables WHERE id=?');
$timetableStmt->execute([(int)$timetableId]);
if (!$timetableStmt->fetchColumn()) $respond(404, ['success' => false, 'message' => 'Timetable not found.']);

if ($event === 'summary_view') {
    $sql = 'SELECT department_name,department_code FROM departments WHERE id=?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int)$targetId]);
    $department = $stmt->fetch();
    if (!$department || (!$isAdmin && !in_array((int)$targetId, $departmentIds, true))) $respond(403, ['success' => false, 'message' => 'Department is outside your access.']);
    logUserActivity('timetable_summary_view', 'Timetable summary viewed', 'Viewed course summary for ' . $department['department_name'] . ' · ' . $department['department_code'] . ' in timetable ' . (int)$timetableId . '.', 'department', (string)$targetId);
} elseif ($event === 'faculty_workload_view') {
    $stmt = $pdo->prepare('SELECT faculty_name,faculty_short_name FROM faculty WHERE id=?');
    $stmt->execute([(int)$targetId]);
    $faculty = $stmt->fetch();
    if (!$faculty) $respond(404, ['success' => false, 'message' => 'Faculty member not found.']);
    logUserActivity('faculty_workload_view', 'Faculty workload viewed', 'Viewed workload for ' . $faculty['faculty_name'] . ($faculty['faculty_short_name'] ? ' · ' . $faculty['faculty_short_name'] : '') . ' in timetable ' . (int)$timetableId . '.', 'faculty', (string)$targetId);
} elseif ($event === 'course_hours_view') {
    if (!$extraId) $respond(422, ['success' => false, 'message' => 'Choose a course.']);
    $sql = 'SELECT c.course_code,c.course_name,d.department_name,d.department_code FROM courses c JOIN departments d ON d.id=c.department_id WHERE c.id=? AND c.department_id=?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int)$extraId, (int)$targetId]);
    $course = $stmt->fetch();
    if (!$course || (!$isAdmin && !in_array((int)$targetId, $departmentIds, true))) $respond(403, ['success' => false, 'message' => 'Course is outside your access.']);
    logUserActivity('course_hours_view', 'Course hours viewed', 'Viewed assigned hours for ' . $course['course_code'] . ' · ' . $course['course_name'] . ' (' . $course['department_code'] . ') in timetable ' . (int)$timetableId . '.', 'course', (string)$extraId);
} else {
    $respond(400, ['success' => false, 'message' => 'Invalid activity.']);
}
$respond(200, ['success' => true]);
