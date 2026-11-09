<?php
declare(strict_types=1);
require __DIR__ . '/app.php';
requireRole(['admin', 'user']);

$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$stmt = $pdo->prepare(
    'SELECT generation_id,source_timetable_id,output_type,is_public,snapshot_json,created_at
     FROM generated_timetables
     WHERE output_type=?' . ($isAdmin ? '' : ' AND is_public=1') . '
     ORDER BY created_at DESC,id DESC'
);
$stmt->execute(['master']);
$generatedMasters = [];
while ($record = $stmt->fetch()) {
    $snapshot = json_decode((string)$record['snapshot_json'], true);
    if (!is_array($snapshot)) continue;
    $semesterNames = implode(', ', array_map('strval', $snapshot['semesters'] ?? []));
    $labelParts = array_filter([
        (string)$record['generation_id'],
        (string)($snapshot['academic_year'] ?? ''),
        $semesterNames,
    ], static fn($part) => $part !== '');
    $generatedMasters[] = [
        'id' => (string)$record['generation_id'],
        'label' => implode(' · ', $labelParts),
        'snapshot' => $snapshot,
        'source_timetable_id' => (int)$record['source_timetable_id'],
    ];
}

$selectedId = trim((string)($_GET['generation_id'] ?? ''));
$selectedMaster = null;
if ($selectedId !== '' && preg_match('/^[A-Za-z0-9_-]{1,80}$/', $selectedId)) {
    foreach ($generatedMasters as $master) {
        if ($master['id'] === $selectedId) {
            $selectedMaster = $master;
            break;
        }
    }
}
$error = '';
if ($selectedId !== '' && !$selectedMaster) $error = 'The selected generated master timetable was not found or is not available to your account.';
$snapshot = $selectedMaster['snapshot'] ?? null;
$snapshotReady = is_array($snapshot)
    && !empty($snapshot['faculty_roster'])
    && isset($snapshot['faculty_assignments'], $snapshot['days'], $snapshot['hours'], $snapshot['departments']);
$backUrl = $selectedMaster
    ? 'generate-timetable.php?timetable_id=' . (int)$selectedMaster['source_timetable_id']
    : 'timetables.php';
$college = collegeName($pdo);
appLayoutStart('Faculties Not Engaged', $college, 'timetable');
?>
<main class="dashboard wide faculty-availability-page">
  <p class="eyebrow">Generated timetable report</p>
  <h1>Free Slots &amp; Faculties List</h1>
  <p class="muted">Find faculty members who have no assigned classes in the selected timetable periods.</p>
  <?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?>
  <section class="panel faculty-availability-selector">
    <form method="get" class="form-stack">
      <label>Generated Master Timetable
        <select name="generation_id" class="js-select2" data-placeholder="Select a generated master timetable" required>
          <option value="">Select a generated master timetable</option>
          <?php foreach ($generatedMasters as $master): ?><option value="<?= h($master['id']) ?>" <?= $selectedId === $master['id'] ? 'selected' : '' ?>><?= h($master['label']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <div class="form-actions"><button class="button" type="submit">View Free Faculty</button></div>
    </form>
    <?php if (!$generatedMasters): ?><p class="muted">No generated master timetables are available. Generate a master timetable report first.</p><?php endif; ?>
  </section>

  <?php if ($selectedMaster): ?>
    <?php if (!$snapshotReady): ?>
      <section class="panel"><h2>Faculty availability snapshot unavailable</h2><p class="muted">This master timetable predates the complete faculty and day/hour snapshot required for availability reporting. Generate a new master timetable report to use this page.</p></section>
    <?php else:
      $designationOptions = [];
      $facultyDepartmentOptions = [];
      foreach ($snapshot['faculty_roster'] as $person) {
          $designationId = (int)$person['designation_id'];
          $designationOptions[$designationId] = (string)$person['designation_name'];
          $departmentId = (int)$person['department_id'];
          $facultyDepartmentOptions[$departmentId] = [
              'name' => (string)$person['department_name'],
              'code' => (string)$person['department_code'],
          ];
      }
      asort($designationOptions, SORT_NATURAL | SORT_FLAG_CASE);
      uasort($facultyDepartmentOptions, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
      $availabilityData = [
          'roster' => $snapshot['faculty_roster'],
          'assignments' => $snapshot['faculty_assignments'],
      ];
    ?>
    <section class="panel faculty-availability-report">
      <div class="faculty-availability-toolbar">
        <div><p class="eyebrow">Selected snapshot</p><h2><?= h($selectedMaster['label']) ?></h2></div>
        <button class="button button-quiet" type="button" id="print-free-faculty">Print</button>
      </div>
      <div class="faculty-availability-filters">
        <label>Day<select class="js-select2" id="free-faculty-days" multiple data-placeholder="All days"><?php foreach ($snapshot['days'] as $day): ?><option value="<?= (int)$day['id'] ?>"><?= h($day['name']) ?></option><?php endforeach; ?></select></label>
        <label>Hour<select class="js-select2" id="free-faculty-hours" multiple data-placeholder="All hours"><?php foreach ($snapshot['hours'] as $hour): ?><option value="<?= (int)$hour['id'] ?>"><?= h($hour['name'] . ' · ' . $hour['time']) ?></option><?php endforeach; ?></select></label>
        <label>Department<select class="js-select2" id="free-faculty-departments" multiple data-placeholder="All departments"><?php foreach ($facultyDepartmentOptions as $departmentId => $department): ?><option value="<?= (int)$departmentId ?>"><?= h($department['name'] . ' · ' . $department['code']) ?></option><?php endforeach; ?></select></label>
        <label>Designation<select class="js-select2" id="free-faculty-designations" multiple data-placeholder="All designations"><?php foreach ($designationOptions as $designationId => $designationName): ?><option value="<?= (int)$designationId ?>"><?= h($designationName) ?></option><?php endforeach; ?></select></label>
      </div>
      <p class="muted faculty-availability-count" id="free-faculty-count" aria-live="polite"></p>
      <div class="table-wrap">
        <table class="faculty-availability-table">
          <thead><tr><th>Sl. No.</th><th>Faculty Name</th><th>Short Name</th><th>Department</th><th>Designation</th></tr></thead>
          <tbody id="free-faculty-results"><tr><td colspan="5" class="muted">Loading faculty availability…</td></tr></tbody>
        </table>
      </div>
      <script type="application/json" id="faculty-availability-data"><?= json_encode($availabilityData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
    </section>
    <?php endif; ?>
  <?php endif; ?>
  <p class="faculty-availability-back"><a class="back-link" href="<?= h($backUrl) ?>">← Back to Generate Reports</a></p>
</main>
<?php appLayoutEnd(); ?>
