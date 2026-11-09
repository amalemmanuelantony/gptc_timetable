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

    $shortNames = $_POST['short_names'];
    if (!$shortNames) $respond(400, ['success' => false, 'message' => 'No faculty records were submitted.']);

    $updates = [];
    foreach ($shortNames as $rawId => $rawName) {
        $id = filter_var((string)$rawId, FILTER_VALIDATE_INT);
        $name = trim((string)$rawName);
        if (!$id || $name === '' || mb_strlen($name) > 80) {
            $respond(422, ['success' => false, 'message' => 'Enter a short name of 1 to 80 characters for every faculty member.']);
        }
        $updates[(int)$id] = $name;
    }

    try {
        $pdo->beginTransaction();
        $idPlaceholders = implode(',', array_fill(0, count($updates), '?'));
        $check = $pdo->prepare("SELECT id FROM faculty WHERE id IN ($idPlaceholders)");
        $check->execute(array_keys($updates));
        if (count($check->fetchAll(PDO::FETCH_COLUMN)) !== count($updates)) {
            $pdo->rollBack();
            $respond(400, ['success' => false, 'message' => 'One or more faculty records no longer exist. Reload the page.']);
        }
        $update = $pdo->prepare('UPDATE faculty SET faculty_short_name = ? WHERE id = ?');
        foreach ($updates as $id => $name) $update->execute([$name, $id]);
        $pdo->commit();
        $respond(200, ['success' => true, 'message' => count($updates) . ' faculty short names saved.']);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $respond(500, ['success' => false, 'message' => 'Could not save faculty short names. Please try again.']);
    }
}

$faculty = $pdo->query('SELECT f.id,f.faculty_name,f.faculty_short_name,d.department_name,d.department_code,g.designation_name FROM faculty f JOIN departments d ON d.id=f.department_id JOIN designations g ON g.id=f.designation_id ORDER BY d.department_name,f.faculty_name')->fetchAll();
appLayoutStart('Update Faculty Short Names', $college, 'faculty-short-names');
?>
<main class="dashboard wide faculty-short-name-page">
  <p class="eyebrow">Temporary faculty utility</p>
  <h1>Update Faculty Short Names</h1>
  <p class="muted">Update short names for all faculty in one place. Changes are saved without reloading this page.</p>
  <section class="panel">
    <div class="faculty-short-name-toolbar">
      <label for="faculty-short-name-search">Filter faculty</label>
      <input type="search" id="faculty-short-name-search" placeholder="Search name, department, or designation">
    </div>
    <?php if (!$faculty): ?><p class="muted">No faculty records found.</p><?php else: ?>
    <form id="faculty-short-names-form" method="post" action="update-faculty-short-names.php">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="action" value="save_short_names">
      <div class="table-wrap faculty-short-name-table-wrap"><table id="faculty-short-name-table">
        <thead><tr><th>No.</th><th>Faculty Name</th><th>Department</th><th>Designation</th><th>Short Name</th></tr></thead>
        <tbody>
        <?php foreach ($faculty as $index => $person): ?>
          <tr data-search="<?= h(strtolower($person['faculty_name'] . ' ' . $person['department_name'] . ' ' . $person['department_code'] . ' ' . $person['designation_name'])) ?>">
            <td><?= $index + 1 ?></td>
            <td><?= h($person['faculty_name']) ?></td>
            <td><?= h($person['department_name'] . ' · ' . $person['department_code']) ?></td>
            <td><?= h($person['designation_name']) ?></td>
            <td><input name="short_names[<?= (int)$person['id'] ?>]" value="<?= h($person['faculty_short_name']) ?>" maxlength="80" aria-label="Short name for <?= h($person['faculty_name']) ?>" required></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="faculty-short-name-actions">
        <button class="button" type="submit" id="save-faculty-short-names">Save Short Names</button>
        <span id="faculty-short-name-status" role="status" aria-live="polite"></span>
      </div>
    </form>
    <?php endif; ?>
  </section>
</main>
<script>
(() => {
  const form = document.querySelector('#faculty-short-names-form');
  const search = document.querySelector('#faculty-short-name-search');
  const status = document.querySelector('#faculty-short-name-status');
  const button = document.querySelector('#save-faculty-short-names');
  const rows = [...document.querySelectorAll('#faculty-short-name-table tbody tr')];
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
