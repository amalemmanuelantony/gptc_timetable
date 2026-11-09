<?php
declare(strict_types=1);
require __DIR__ . '/app.php';
requireRole(['admin']);

$college = collegeName($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $respond = static function (int $status, array $payload): never {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        $respond(400, ['success' => false, 'message' => 'Your form token expired. Reload the page and try again.']);
    }
    if (($_POST['action'] ?? '') !== 'save_short_names' || !is_array($_POST['short_names'] ?? null)) {
        $respond(400, ['success' => false, 'message' => 'Invalid save request.']);
    }
    $updates = [];
    foreach ($_POST['short_names'] as $rawId => $rawName) {
        $id = filter_var((string)$rawId, FILTER_VALIDATE_INT);
        $name = trim((string)$rawName);
        if (!$id || $name === '' || mb_strlen($name) > 80) {
            $respond(422, ['success' => false, 'message' => 'Enter a course short name of 1 to 80 characters for every course.']);
        }
        $updates[(int)$id] = $name;
    }
    if (!$updates) $respond(400, ['success' => false, 'message' => 'No courses were submitted.']);

    try {
        $pdo->beginTransaction();
        $placeholders = implode(',', array_fill(0, count($updates), '?'));
        $check = $pdo->prepare("SELECT id FROM courses WHERE id IN ($placeholders)");
        $check->execute(array_keys($updates));
        if (count($check->fetchAll(PDO::FETCH_COLUMN)) !== count($updates)) {
            $pdo->rollBack();
            $respond(400, ['success' => false, 'message' => 'One or more courses no longer exist. Reload the page.']);
        }
        $update = $pdo->prepare('UPDATE courses SET course_short_name=? WHERE id=?');
        foreach ($updates as $id => $name) $update->execute([$name, $id]);
        $pdo->commit();
        $respond(200, ['success' => true, 'message' => count($updates) . ' course short names saved.']);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $respond(500, ['success' => false, 'message' => 'Could not save course short names. Please try again.']);
    }
}

$courses = $pdo->query('SELECT c.id,c.course_code,c.course_name,c.course_short_name,d.department_name,d.department_code,s.semester_year,s.semester_full_name,s.semester_code FROM courses c JOIN departments d ON d.id=c.department_id JOIN semesters s ON s.id=c.semester_id ORDER BY d.department_name,s.semester_year,s.id,c.course_code')->fetchAll();
appLayoutStart('Update Course Short Names', $college, 'course-short-names');
?>
<main class="dashboard wide course-short-name-page">
  <p class="eyebrow">Course utility</p>
  <h1>Update Course Short Names</h1>
  <p class="muted">Courses are sorted by department and semester. Save all short-name updates without reloading the page.</p>
  <section class="panel">
    <div class="course-short-name-toolbar">
      <label for="course-short-name-search">Filter courses</label>
      <input type="search" id="course-short-name-search" placeholder="Search code, course, department, or semester">
    </div>
    <?php if (!$courses): ?><p class="muted">No courses found.</p><?php else: ?>
    <form id="course-short-names-form" method="post" action="update-course-short-names.php">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="action" value="save_short_names">
      <div class="table-wrap course-short-name-table-wrap"><table class="course-short-name-table" id="course-short-name-table">
        <thead><tr><th>No.</th><th>Department</th><th>Semester</th><th>Course Code</th><th>Course Name</th><th>Short Name</th></tr></thead>
        <tbody>
        <?php foreach ($courses as $index => $course): ?>
          <tr data-search="<?= h(strtolower($course['course_code'] . ' ' . $course['course_name'] . ' ' . $course['department_name'] . ' ' . $course['department_code'] . ' ' . $course['semester_full_name'] . ' ' . $course['semester_code'])) ?>">
            <td><?= $index + 1 ?></td>
            <td><?= h($course['department_name'] . ' · ' . $course['department_code']) ?></td>
            <td><?= h($course['semester_full_name'] . ' · ' . $course['semester_code']) ?></td>
            <td><code><?= h($course['course_code']) ?></code></td>
            <td><?= h($course['course_name']) ?></td>
            <td><input name="short_names[<?= (int)$course['id'] ?>]" value="<?= h($course['course_short_name'] ?? '') ?>" maxlength="80" aria-label="Short name for <?= h($course['course_name']) ?>" required></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="faculty-short-name-actions">
        <button class="button" type="submit" id="save-course-short-names">Save Short Names</button>
        <span id="course-short-name-status" role="status" aria-live="polite"></span>
      </div>
    </form>
    <?php endif; ?>
  </section>
</main>
<script>
(() => {
  const form = document.querySelector('#course-short-names-form');
  const search = document.querySelector('#course-short-name-search');
  const status = document.querySelector('#course-short-name-status');
  const button = document.querySelector('#save-course-short-names');
  const rows = [...document.querySelectorAll('#course-short-name-table tbody tr')];
  search?.addEventListener('input', () => {
    const query = search.value.trim().toLowerCase();
    rows.forEach((row) => { row.hidden = query !== '' && !row.dataset.search.includes(query); });
  });
  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = 'Saving…';
    status.textContent = '';
    status.className = '';
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'fetch', 'Accept': 'application/json'}
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Save failed.');
      status.textContent = result.message;
      status.className = 'success';
    } catch (error) {
      status.textContent = error.message || 'Could not save short names.';
      status.className = 'alert';
    } finally {
      button.disabled = false;
      button.textContent = originalText;
    }
  });
})();
</script>
<?php appLayoutEnd(); ?>
