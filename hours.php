<?php
require __DIR__ . '/app.php'; requireRole(['admin']);
$college = collegeName($pdo); $error = ''; $success = '';
$exists = (bool)$pdo->query("SHOW TABLES LIKE 'hours'")->fetchColumn();
if ($exists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) { $stmt = $pdo->prepare('DELETE FROM hours WHERE hour_id = ?'); $stmt->execute([$id]); $success = $stmt->rowCount() ? 'Hour deleted.' : 'Hour was not found.'; }
        else $error = 'Invalid Hour ID.';
    } else {
        $id = filter_input(INPUT_POST, 'hour_id', FILTER_VALIDATE_INT);
        $number1 = trim($_POST['hour_number_1'] ?? ''); $number2 = trim($_POST['hour_number_2'] ?? '');
        $name1 = trim($_POST['hour_name_1'] ?? ''); $name2 = trim($_POST['hour_name_2'] ?? '');
        $from = trim($_POST['hour_time_from'] ?? ''); $to = trim($_POST['hour_time_to'] ?? '');
        if (!$id || $number1 === '' || $number2 === '' || $name1 === '' || $name2 === '' || $from === '' || $to === '') $error = 'Fill in all Hour fields with a valid Hour ID.';
        elseif (mb_strlen($number1) > 20 || mb_strlen($number2) > 20 || mb_strlen($name1) > 80 || mb_strlen($name2) > 80) $error = 'One or more values exceed the allowed length.';
        elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $from) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $to)) $error = 'Enter valid times using 24-hour format (HH:MM).';
        else {
            try {
                if (isset($_POST['original_id']) && $_POST['original_id'] !== '') {
                    $stmt = $pdo->prepare('UPDATE hours SET hour_id = ?, hour_number_1 = ?, hour_number_2 = ?, hour_name_1 = ?, hour_name_2 = ?, hour_time_from = ?, hour_time_to = ? WHERE hour_id = ?');
                    $stmt->execute([$id, $number1, $number2, $name1, $name2, $from, $to, (int)$_POST['original_id']]); $success = 'Hour updated.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO hours (hour_id, hour_number_1, hour_number_2, hour_name_1, hour_name_2, hour_time_from, hour_time_to) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$id, $number1, $number2, $name1, $name2, $from, $to]); $success = 'Hour added.';
                }
            } catch (PDOException $e) { if ($e->getCode() === '23000') $error = 'That Hour ID already exists.'; else throw $e; }
        }
    }
}
$editing = null;
if ($exists && isset($_GET['edit'])) { $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT); if ($editId) { $stmt = $pdo->prepare('SELECT * FROM hours WHERE hour_id = ?'); $stmt->execute([$editId]); $editing = $stmt->fetch() ?: null; } }
$rows = $exists ? $pdo->query('SELECT * FROM hours ORDER BY hour_id')->fetchAll() : [];
appLayoutStart('Hours', $college, 'hours'); ?>
<main class="dashboard narrow"><p class="eyebrow">Timetable setup</p><h1>Hours</h1><p class="muted">Maintain hour numbers, labels, and time ranges.</p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="alert" aria-live="assertive"><?= h($success) ?></div><?php endif; ?>
<?php if (!$exists): ?><section class="panel"><h2>Database setup required</h2><p class="muted">Import <code>migrations/011_create_hours_and_days.sql</code> in phpMyAdmin, then reload.</p></section><?php else: ?>
<section class="panel"><h2><?= $editing ? 'Edit Hour' : 'Add Hour' ?></h2><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="original_id" value="<?= h((string)($editing['hour_id'] ?? '')) ?>">
<label>Hour ID<input type="number" name="hour_id" min="1" value="<?= h((string)($editing['hour_id'] ?? '')) ?>" required></label>
<label>Hour Number 1<input name="hour_number_1" maxlength="20" value="<?= h($editing['hour_number_1'] ?? '') ?>" required></label><label>Hour Number 2<input name="hour_number_2" maxlength="20" value="<?= h($editing['hour_number_2'] ?? '') ?>" required></label>
<label>Hour Name 1<input name="hour_name_1" maxlength="80" value="<?= h($editing['hour_name_1'] ?? '') ?>" required></label><label>Hour Name 2<input name="hour_name_2" maxlength="80" value="<?= h($editing['hour_name_2'] ?? '') ?>" required></label>
<label>Hour Time From<input type="time" name="hour_time_from" value="<?= h(substr($editing['hour_time_from'] ?? '', 0, 5)) ?>" required></label><label>Hour Time To<input type="time" name="hour_time_to" value="<?= h(substr($editing['hour_time_to'] ?? '', 0, 5)) ?>" required></label>
<div class="form-actions"><button class="button" type="submit" name="action" value="save"><?= $editing ? 'Update Hour' : 'Save Hour' ?></button><?php if ($editing): ?><a class="button button-quiet" href="hours.php">Cancel</a><?php endif; ?></div></form></section>
<section class="panel"><div class="section-heading"><h2>Hours</h2><span class="count-badge"><?= count($rows) ?></span></div><?php if (!$rows): ?><p class="muted">No hours added yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Hour ID</th><th>Number 1</th><th>Number 2</th><th>Name 1</th><th>Name 2</th><th>Time From</th><th>Time To</th><th>Actions</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= (int)$row['hour_id'] ?></td><td><?= h($row['hour_number_1']) ?></td><td><?= h($row['hour_number_2']) ?></td><td><?= h($row['hour_name_1']) ?></td><td><?= h($row['hour_name_2']) ?></td><td><?= h(substr($row['hour_time_from'], 0, 5)) ?></td><td><?= h(substr($row['hour_time_to'], 0, 5)) ?></td><td class="row-actions"><a class="text-link" href="hours.php?edit=<?= (int)$row['hour_id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this hour?')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$row['hour_id'] ?>"><button class="text-button" type="submit" name="action" value="delete">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?></main><?php appLayoutEnd(); ?>
