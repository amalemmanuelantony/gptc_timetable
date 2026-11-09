<?php
require __DIR__ . '/app.php'; requireRole(['admin']);
$college = collegeName($pdo); $error = ''; $success = '';
$exists = (bool)$pdo->query("SHOW TABLES LIKE 'designations'")->fetchColumn();
$columns = $exists ? $pdo->query('SHOW COLUMNS FROM designations')->fetchAll(PDO::FETCH_COLUMN) : [];
$ready = $exists && in_array('faculty_priority', $columns, true);
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $priorities = $_POST['priority'] ?? [];
    if (!is_array($priorities)) $error = 'Invalid priority values.';
    else {
        $rows = $pdo->query('SELECT id FROM designations')->fetchAll(PDO::FETCH_COLUMN);
        $save = $pdo->prepare('UPDATE designations SET faculty_priority = ? WHERE id = ?');
        foreach ($rows as $id) {
            $value = filter_var($priorities[(string)$id] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value === null || $value < 1 || $value > 9999) { $error = 'Enter a priority from 1 to 9999 for every designation.'; break; }
        }
        if (!$error) {
            $pdo->beginTransaction();
            try {
                foreach ($rows as $id) $save->execute([(int)$priorities[(string)$id], (int)$id]);
                $pdo->commit(); $success = 'Faculty priorities saved.';
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        }
    }
}
$designations = $ready ? $pdo->query('SELECT id, designation_name, designation_short_name, faculty_priority FROM designations ORDER BY faculty_priority, designation_name')->fetchAll() : [];
appLayoutStart('Faculty Priority', $college, 'faculty-priority'); ?>
<main class="dashboard narrow"><p class="eyebrow">Faculty setup</p><h1>Faculty Priority</h1><p class="muted">Set designation order for faculty names shown in timetable slots. Smaller priority numbers appear first.</p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="status"><?= h($success) ?></div><?php endif; ?>
<?php if (!$exists): ?><section class="panel"><h2>Database setup required</h2><p class="muted">Create the Designation table first.</p></section><?php elseif (!$ready): ?><section class="panel"><h2>Database update required</h2><p class="muted">Import <code>migrations/040_designation_faculty_priority.sql</code> in phpMyAdmin, then reload.</p></section><?php elseif (!$designations): ?><section class="panel"><p class="muted">Add designations before setting faculty priorities.</p></section><?php else: ?>
<section class="panel"><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><div class="table-wrap"><table><thead><tr><th>Priority</th><th>Designation</th><th>Short Name</th></tr></thead><tbody><?php foreach ($designations as $row): ?><tr><td><input type="number" name="priority[<?= (int)$row['id'] ?>]" min="1" max="9999" value="<?= (int)$row['faculty_priority'] ?>" required aria-label="Priority for <?= h($row['designation_name']) ?>"></td><td><?= h($row['designation_name']) ?></td><td><?= h($row['designation_short_name']) ?></td></tr><?php endforeach; ?></tbody></table></div><div class="form-actions"><button class="button" type="submit">Save Priorities</button></div></form></section><?php endif; ?></main><?php appLayoutEnd(); ?>
