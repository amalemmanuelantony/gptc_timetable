<?php
declare(strict_types=1);

session_start();
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile) && !getenv('DB_HOST')) {
    http_response_code(500);
    exit('Configuration missing. Copy config.example.php to config.php and set your database credentials.');
}
$config = require (is_file($configFile) ? $configFile : __DIR__ . '/config.example.php');
$config['db_host'] = getenv('DB_HOST') ?: $config['db_host'];
$config['db_name'] = getenv('DB_NAME') ?: $config['db_name'];
$config['db_user'] = getenv('DB_USER') ?: $config['db_user'];
$config['db_pass'] = getenv('DB_PASS') !== false ? getenv('DB_PASS') : $config['db_pass'];
try {
    $pdo = new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'], $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Unable to connect to the database. Check config.php and confirm the database is running.');
}

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function collegeName(PDO $pdo): string {
    try {
        $value = $pdo->query("SELECT value_value FROM details WHERE value_name = 'college_name'")->fetchColumn();
        return $value ?: 'Government Polytechnic College, Kottayam';
    } catch (PDOException $e) { return 'Government Polytechnic College, Kottayam'; }
}
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function logUserActivity(string $actionKey, string $activity, string $details = '', ?string $targetType = null, ?string $targetId = null): void {
    global $pdo;
    $user = $_SESSION['user'] ?? [];
    if (empty($user['id'])) return;
    try {
        $stmt = $pdo->prepare('INSERT INTO user_logs (user_id,user_id_label,user_name,user_role,action_key,activity,details,target_type,target_id,ip_address,user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            (int)$user['id'],
            (string)($user['username'] ?? ''),
            (string)($user['display_name'] ?? $user['username'] ?? ''),
            (string)($user['role'] ?? ''),
            $actionKey,
            $activity,
            $details,
            $targetType,
            $targetId,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $exception) {
        // Logging must never interrupt a successful user action, including before the log migration is applied.
    }
}
function checkCsrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400); exit('Invalid form token. Refresh the page and try again.');
    }
}
function userDepartmentIds(PDO $pdo, int $userId, ?int $legacyDepartmentId = null): array {
    try {
        $stmt = $pdo->prepare('SELECT department_id FROM user_departments WHERE user_id = ? ORDER BY department_id');
        $stmt->execute([$userId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) return $ids;
    } catch (PDOException $e) { /* The migration may not yet be applied. */ }
    return $legacyDepartmentId ? [(int)$legacyDepartmentId] : [];
}
function requireRole(array $roles): void {
    global $pdo;
    if (empty($_SESSION['user']['id'])) {
        header('Location: index.php'); exit;
    }
    $stmt = $pdo->prepare('SELECT username, display_name, department_id, role, is_active FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user']['id']]);
    $account = $stmt->fetch();
    if (!$account || (int)$account['is_active'] !== 1) {
        $_SESSION = [];
        session_destroy();
        header('Location: index.php'); exit;
    }
    $_SESSION['user']['username'] = $account['username'];
    $_SESSION['user']['display_name'] = $account['display_name'] ?: $account['username'];
    $_SESSION['user']['department_id'] = $account['department_id'];
    $_SESSION['user']['department_ids'] = userDepartmentIds($pdo, (int)$_SESSION['user']['id'], $account['department_id'] === null ? null : (int)$account['department_id']);
    $_SESSION['user']['role'] = $account['role'];
    if (!in_array($account['role'], $roles, true)) { header('Location: dashboard.php'); exit; }
}
function pageStart(string $title, string $college): void {
    $select2Pages = ['courses.php', 'course-category.php', 'timetables.php', 'users.php', 'faculty.php', 'generate-timetable.php', 'faculty-not-engaged.php'];
    $usesSelect2 = in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $select2Pages, true);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($title) ?> | <?= h($college) ?></title>
<?php if ($usesSelect2): ?><link rel="stylesheet" href="assets/vendor/select2/select2.min.css"><?php endif; ?>
<link rel="stylesheet" href="assets/style.css?v=86">
<?php if ($usesSelect2): ?><script src="assets/vendor/jquery/jquery.min.js" defer></script><script src="assets/vendor/select2/select2.min.js" defer></script><?php endif; ?>
<script src="assets/app.js?v=63" defer></script></head><body>
<?php }
function pageEnd(): void { ?></body></html><?php }
function appLayoutStart(string $title, string $college, string $active): void {
    $role = $_SESSION['user']['role'] ?? 'staff';
    $roleLabels = ['admin' => 'Administrator', 'user' => 'User', 'coordinator' => 'Coordinator', 'staff' => 'Staff'];
    pageStart($title, $college); ?>
    <div class="app-layout">
      <aside class="sidebar">
        <a class="sidebar-brand" href="dashboard.php"><img src="assets/college-seal.png" alt=""><span><?= h($college) ?><small>Timetable Management</small></span></a>
        <p class="sidebar-caption">WORKSPACE</p>
        <nav class="sidebar-nav" aria-label="Main navigation">
          <a class="nav-link <?= $active === 'dashboard' ? 'active' : '' ?>" href="dashboard.php"><span class="nav-icon">⌂</span>Dashboard</a>
          <a class="nav-link <?= $active === 'timetable' ? 'active' : '' ?>" href="timetables.php"><span class="nav-icon">▦</span>Timetable</a>
          <?php if ($role === 'admin'): ?><a class="nav-link <?= $active === 'user-logs' ? 'active' : '' ?>" href="user-logs.php"><span class="nav-icon">≡</span>User Log</a><?php endif; ?>
          <?php if ($role === 'admin'): ?>
          <a class="nav-link <?= $active === 'faculty' ? 'active' : '' ?>" href="faculty.php"><span class="nav-icon">♙</span>Faculty</a>
          <a class="nav-link <?= $active === 'faculty-priority' ? 'active' : '' ?>" href="faculty-priority.php"><span class="nav-icon">⇅</span>Faculty Priority</a>
          <a class="nav-link <?= $active === 'faculty-short-names' ? 'active' : '' ?>" href="update-faculty-short-names.php"><span class="nav-icon">Aa</span>Faculty Short Names</a>
          <a class="nav-link <?= $active === 'designations' ? 'active' : '' ?>" href="designations.php"><span class="nav-icon">▤</span>Designation</a>
          <?php endif; ?>
          <?php if ($role === 'user'): ?>
          <a class="nav-link <?= $active === 'user-courses' ? 'active' : '' ?>" href="my-courses.php"><span class="nav-icon">▤</span>Courses</a>
          <a class="nav-link <?= $active === 'change-password' ? 'active' : '' ?>" href="change-password.php"><span class="nav-icon">⌑</span>Change Password</a>
          <?php endif; ?>
          <?php if ($role === 'admin'): ?>
          <a class="nav-link <?= $active === 'users' ? 'active' : '' ?>" href="users.php"><span class="nav-icon">♙</span>Users</a>
          <a class="nav-link <?= $active === 'courses' ? 'active' : '' ?>" href="courses.php"><span class="nav-icon">▤</span>Courses</a>
          <a class="nav-link <?= $active === 'course-short-names' ? 'active' : '' ?>" href="update-course-short-names.php"><span class="nav-icon">Aa</span>Course Short Names</a>
          <a class="nav-link <?= $active === 'hours' ? 'active' : '' ?>" href="hours.php"><span class="nav-icon">◷</span>Hours</a>
          <a class="nav-link <?= $active === 'days' ? 'active' : '' ?>" href="days.php"><span class="nav-icon">▦</span>Days</a>
          <a class="nav-link <?= $active === 'departments' ? 'active' : '' ?>" href="departments.php"><span class="nav-icon">⌂</span>Departments</a>
          <a class="nav-link <?= $active === 'semesters' ? 'active' : '' ?>" href="semesters.php"><span class="nav-icon">◷</span>Semesters</a>
          <a class="nav-link <?= $active === 'academic-years' ? 'active' : '' ?>" href="academic-years.php"><span class="nav-icon">▣</span>Academic Year</a>
          <a class="nav-link <?= $active === 'revisions' ? 'active' : '' ?>" href="revisions.php"><span class="nav-icon">▤</span>Revisions</a>
          <a class="nav-link <?= $active === 'course-category' ? 'active' : '' ?>" href="course-category.php"><span class="nav-icon">⊞</span>Course Category</a>
          <a class="nav-link <?= $active === 'course-types' ? 'active' : '' ?>" href="course-types.php"><span class="nav-icon">⊞</span>Course Types</a>
          <a class="nav-link <?= $active === 'college-details' ? 'active' : '' ?>" href="details.php"><span class="nav-icon">✳</span>College Details</a>
          <?php endif; ?>
        </nav>
        <div class="sidebar-foot"><span class="role-dot"></span><?= h($roleLabels[$role] ?? 'Staff') ?> workspace</div>
      </aside>
      <section class="app-main">
        <header class="topbar"><div class="page-context"><?= h($title) ?></div><div class="account"><span><?= h($_SESSION['user']['display_name'] ?? $_SESSION['user']['username']) ?> · <?= h($roleLabels[$role] ?? 'User') ?></span><form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><button class="button button-small button-quiet" type="submit">Sign out</button></form></div></header>
<?php }
function appLayoutEnd(): void { ?></section></div><?php pageEnd(); }
