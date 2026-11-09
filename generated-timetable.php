<?php
declare(strict_types=1);
require __DIR__ . '/app.php';
requireRole(['admin', 'user']);

$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$userDepartmentIds = array_map('intval', $_SESSION['user']['department_ids'] ?? []);
$requestedIds = [];
if (isset($_GET['ids'])) {
    $requestedIds = array_values(array_filter(array_map('trim', explode(',', (string)$_GET['ids']))));
} elseif (isset($_GET['id'])) {
    $requestedIds = [trim((string)$_GET['id'])];
}
if (!$requestedIds || count($requestedIds) > 100) {
    http_response_code(400);
    exit('Enter one or more valid timetable IDs.');
}
foreach ($requestedIds as $requestedId) {
    if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', $requestedId)) {
        http_response_code(400);
        exit('Enter valid timetable IDs.');
    }
}
$fetch = $pdo->prepare('SELECT generation_id,output_type,is_public,target_department_id,access_department_id,snapshot_json,created_at FROM generated_timetables WHERE generation_id=?');
$items = [];
foreach ($requestedIds as $requestedId) {
    $fetch->execute([$requestedId]);
    $record = $fetch->fetch();
    if (!$record) { http_response_code(404); exit('Generated timetable not found: ' . h($requestedId)); }
    if (count($requestedIds) > 1 && $record['output_type'] !== 'individual') { http_response_code(400); exit('Combined views are only available for individual timetables.'); }
    if (!$isAdmin && (int)$record['is_public'] !== 1) { http_response_code(403); exit('This saved timetable was generated before publication and is only available to administrators.'); }
    if (!$isAdmin && $record['access_department_id'] !== null && !in_array((int)$record['access_department_id'], $userDepartmentIds, true)) { http_response_code(403); exit('You do not have access to one or more generated timetables.'); }
    $snapshot = json_decode($record['snapshot_json'], true);
    if (!is_array($snapshot) || empty($snapshot['rows']) || empty($snapshot['hours'])) { http_response_code(500); exit('A saved timetable snapshot is invalid.'); }
    $items[] = ['record' => $record, 'snapshot' => $snapshot];
}
$first = $items[0]['snapshot'];
appLayoutStart(count($items) > 1 ? 'Individual Timetables' : ($first['title'] ?? 'Generated Timetable'), (string)($first['college_name'] ?? 'College Timetable'), 'timetable');
foreach ($items as $itemIndex => $item):
    $record = $item['record'];
    $snapshot = $item['snapshot'];
    $generationId = (string)$record['generation_id'];
    $isMaster = ($record['output_type'] ?? '') === 'master';
    if ($isMaster) $snapshot['rows'] = array_values(array_filter($snapshot['rows'], static fn($row) => !in_array(strtoupper((string)($row['department'] ?? '')), ['GS', 'WS'], true)));
    $college = (string)($snapshot['college_name'] ?? 'College Timetable');
    $isIndividual = ($record['output_type'] ?? '') === 'individual';
    $headingTarget = (string)($snapshot['target_name'] ?? '');
    if ($isIndividual && !empty($snapshot['target_designation'])) $headingTarget .= ' · ' . $snapshot['target_designation'];
    $academicYear = (string)($snapshot['academic_year'] ?? '');
    $semesterNames = strtoupper(implode(', ', array_map('strval', $snapshot['semesters'] ?? [])));
    $headingTitle = match ($record['output_type']) {
        'master' => 'MASTER TIMETABLE - ' . $academicYear . ' - ' . $semesterNames,
        'department' => 'DEPARTMENT TIMETABLE - ' . $academicYear . ' - ' . $semesterNames,
        'individual' => 'INDIVIDUAL TIMETABLE - ' . $academicYear . ' - ' . $semesterNames,
        'semester', 'department_semester' => 'TIMETABLE - ' . $academicYear . ' - ' . $semesterNames,
        default => strtoupper((string)($snapshot['title'] ?? 'TIMETABLE')),
    };
    $hasAssignedReference = ($snapshot['reference_scope'] ?? '') === 'assigned_entries';
    $renderReferenceEntries = static function (array $entries): string {
        $formatted = [];
        foreach ($entries as $entry) {
            $parts = explode(' - ', (string)$entry, 2);
            $formatted[] = count($parts) === 2 ? '<strong>' . h(trim($parts[0])) . '</strong> - ' . h(trim($parts[1])) : h((string)$entry);
        }
        return implode(', ', $formatted);
    };
    $mergedRows = [];
    if (!$isIndividual) {
        foreach ($snapshot['rows'] as $index => $row) {
            $previous = $snapshot['rows'][$index - 1] ?? null;
            $showDay = !$previous || $previous['day'] !== $row['day'];
            $showDepartment = $showDay || $previous['department'] !== $row['department'];
            $daySpan = 0; $departmentSpan = 0;
            if ($showDay) for ($i = $index; $i < count($snapshot['rows']) && $snapshot['rows'][$i]['day'] === $row['day']; $i++) $daySpan++;
            if ($showDepartment) for ($i = $index; $i < count($snapshot['rows']) && $snapshot['rows'][$i]['day'] === $row['day'] && $snapshot['rows'][$i]['department'] === $row['department']; $i++) $departmentSpan++;
            $mergedRows[] = ['show_day' => $showDay, 'day_span' => $daySpan, 'show_department' => $showDepartment, 'department_span' => $departmentSpan];
        }
    }
    $sourceTimetableId = (int)($snapshot['source_timetable_id'] ?? 0);
?>
<main class="generated-timetable-page<?= count($items) > 1 ? ' generated-individual-batch' : '' ?>">
  <div class="generated-actions"><a class="text-link" href="generate-timetable.php?timetable_id=<?= $sourceTimetableId ?>">← Back to generated timetables</a><?php if ($itemIndex === 0): ?><button type="button" class="button" id="download-generated-pdf">Download PDF</button><?php endif; ?></div>
  <header class="generated-heading"><p class="generated-college-name"><?= h($snapshot['college_name'] ?? $college) ?></p><p class="generated-master-label"><?= h($headingTitle) ?></p><?php if ($headingTarget !== ''): ?><p class="generated-heading-target"><?= h($headingTarget) ?></p><?php endif; ?></header>
  <section class="generated-table-wrap">
  <?php if ($isIndividual): ?>
    <table class="generated-timetable-grid generated-colored-grid individual-generated-grid generated-day-colored-grid"><thead><tr class="print-timetable-heading"><th colspan="<?= count($snapshot['hours']) + 1 ?>"><span class="print-college-name"><?= h($snapshot['college_name'] ?? $college) ?></span><span class="print-timetable-title"><?= h($headingTitle) ?></span><?php if ($headingTarget !== ''): ?><span class="print-timetable-target"><?= h($headingTarget) ?></span><?php endif; ?></th></tr><tr><th>Day</th><?php foreach ($snapshot['hours'] as $hourIndex => $hour): ?><th class="generated-hour-col-<?= $hourIndex % 6 ?>"><?= h($hour['name']) ?><small><?= h($hour['time']) ?></small></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($snapshot['rows'] as $rowIndex => $row): preg_match('/\d+/', (string)$row['day'], $dayColorMatch); $dayColor = $dayColorMatch ? (((int)$dayColorMatch[0] - 1) % 6) + 1 : 1; ?><tr class="timetable-level-day-end" data-day-color="<?= $dayColor ?>"><th><?= h($row['day']) ?></th><?php foreach ($row['cells'] as $hourIndex => $cell): ?><td class="generated-hour-col-<?= $hourIndex % 6 ?>"><?php foreach ($cell as $line): ?><div><?= h($line) ?></div><?php endforeach; ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table>
  <?php else: ?>
    <table class="generated-timetable-grid generated-colored-grid <?= $isMaster ? 'generated-master-grid' : '' ?> <?= in_array($record['output_type'], ['department', 'individual', 'department_semester'], true) ? 'generated-day-colored-grid' : '' ?>"><thead><tr class="print-timetable-heading"><th colspan="<?= count($snapshot['hours']) + 3 ?>"><span class="print-college-name"><?= h($snapshot['college_name'] ?? $college) ?></span><span class="print-timetable-title"><?= h($headingTitle) ?></span><?php if ($headingTarget !== ''): ?><span class="print-timetable-target"><?= h($headingTarget) ?></span><?php endif; ?></th></tr><tr><th>Day</th><th>Department</th><th>Semester</th><?php foreach ($snapshot['hours'] as $hourIndex => $hour): ?><th class="generated-hour-col-<?= $hourIndex % 6 ?>"><?= h($hour['name']) ?><small><?= h($hour['time']) ?></small></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($snapshot['rows'] as $rowIndex => $row): $merge = $mergedRows[$rowIndex] ?? null; $nextRow = $snapshot['rows'][$rowIndex + 1] ?? null; $boundaryClass = !$nextRow || $nextRow['day'] !== $row['day'] ? 'timetable-level-day-end' : ($nextRow['department'] !== $row['department'] ? 'timetable-level-department-end' : 'timetable-level-semester-end'); $afterDepartment = $snapshot['rows'][$rowIndex + $merge['department_span']] ?? null; $departmentGroupClass = !$afterDepartment || $afterDepartment['day'] !== $row['day'] ? 'timetable-group-day-end' : 'timetable-group-department'; preg_match('/\d+/', (string)$row['day'], $dayColorMatch); $dayColor = $dayColorMatch ? (((int)$dayColorMatch[0] - 1) % 6) + 1 : 1; ?><tr class="<?= $boundaryClass ?>" data-department-code="<?= h($row['department']) ?>" data-day-color="<?= $dayColor ?>"><?php if ($merge['show_day']): ?><th class="timetable-group-day" rowspan="<?= (int)$merge['day_span'] ?>"><?= h($row['day']) ?></th><?php endif; ?><?php if ($merge['show_department']): ?><th class="<?= $departmentGroupClass ?>" rowspan="<?= (int)$merge['department_span'] ?>"><?= h($row['department']) ?></th><?php endif; ?><td class="timetable-group-semester"><?= h($row['semester']) ?></td><?php foreach ($row['cells'] as $hourIndex => $cell): ?><td class="generated-hour-col-<?= $hourIndex % 6 ?>"><?php foreach ($cell as $line): ?><div><?= h($line) ?></div><?php endforeach; ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table>
  <?php endif; ?>
  <section class="generated-master-reference"><h2>Faculty and Course Reference</h2><?php if (!$hasAssignedReference): ?><p class="muted">This saved timetable predates assigned-only references. Generate a new snapshot to include them.</p><?php elseif (empty($snapshot['reference'])): ?><p class="muted">No courses or faculty are assigned in this timetable.</p><?php else: ?><div class="generated-reference-wrap"><table><thead><tr><th>Department</th><th>Faculty</th><th>Courses</th></tr></thead><tbody><?php foreach ($snapshot['reference'] as $department): ?><tr><th><?= h($department['department']) ?></th><td><?= $renderReferenceEntries($department['faculty'] ?? []) ?></td><td><?= $renderReferenceEntries($department['courses'] ?? []) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
  </section>
  <footer class="generated-footer"><span>Saved timetable ID: <?= h($generationId) ?></span><span>Generated <?= h($snapshot['generated_at'] ?? $record['created_at']) ?> by <?= h($snapshot['generated_by'] ?? '') ?></span></footer>
</main>
<?php endforeach; ?>
<script>document.querySelector('#download-generated-pdf')?.addEventListener('click', () => window.print());</script>
<?php appLayoutEnd(); ?>
