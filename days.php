<?php
require __DIR__ . '/app.php'; requireRole(['admin']);
$college = collegeName($pdo); $error = ''; $success = '';
$exists = (bool)$pdo->query("SHOW TABLES LIKE 'days'")->fetchColumn();
if ($exists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) { $stmt = $pdo->prepare('DELETE FROM days WHERE day_id = ?'); $stmt->execute([$id]); $success = $stmt->rowCount() ? 'Day deleted.' : 'Day was not found.'; }
        else $error = 'Invalid Day ID.';
    } else {
        $id = filter_input(INPUT_POST, 'day_id', FILTER_VALIDATE_INT); $name1 = trim($_POST['day_name_1'] ?? ''); $name2 = trim($_POST['day_name_2'] ?? '');
        if (!$id || $name1 === '' || $name2 === '') $error = 'Fill in Day ID and both day names.';
        elseif (mb_strlen($name1) > 80 || mb_strlen($name2) > 80) $error = 'Day names must be 80 characters or fewer.';
        else {
            try {
                if (isset($_POST['original_id']) && $_POST['original_id'] !== '') {
                    $stmt = $pdo->prepare('UPDATE days SET day_id = ?, day_name_1 = ?, day_name_2 = ? WHERE day_id = ?');
                    $stmt->execute([$id, $name1, $name2, (int)$_POST['original_id']]); $success = 'Day updated.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO days (day_id, day_name_1, day_name_2) VALUES (?, ?, ?)');
                    $stmt->execute([$id, $name1, $name2]); $success = 'Day added.';
                }
            } catch (PDOException $e) { if ($e->getCode() === '23000') $error = 'That Day ID already exists.'; else throw $e; }
        }
    }
}
$editing = null;
if ($exists && isset($_GET['edit'])) { $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT); if ($editId) { $stmt = $pdo->prepare('SELECT * FROM days WHERE day_id = ?'); $stmt->execute([$editId]); $editing = $stmt->fetch() ?: null; } }
$rows = $exists ? $pdo->query('SELECT * FROM days ORDER BY day_id')->fetchAll() : [];
appLayoutStart('Days', $college, 'days'); ?>
<main class="dashboard narrow"><p class="eyebrow">Timetable setup</p><h1>Days</h1><p class="muted">Maintain the day identifiers and display names used in the timetable.</p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="alert" aria-live="assertive"><?= h($success) ?></div><?php endif; ?>
<?php if (!$exists): ?><section class="panel"><h2>Database setup required</h2><p class="muted">Import <code>migrations/011_create_hours_and_days.sql</code> in phpMyAdmin, then reload.</p></section><?php else: ?>
<section class="panel"><h2><?= $editing ? 'Edit Day' : 'Add Day' ?></h2><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="original_id" value="<?= h((string)($editing['day_id'] ?? '')) ?>">
<label>Day ID<input type="number" name="day_id" min="1" value="<?= h((string)($editing['day_id'] ?? '')) ?>" required></label><label>Day Name 1<input name="day_name_1" maxlength="80" value="<?= h($editing['day_name_1'] ?? '') ?>" required></label><label>Day Name 2<input name="day_name_2" maxlength="80" value="<?= h($editing['day_name_2'] ?? '') ?>" required></label>
<div class="form-actions"><button class="button" type="submit" name="action" value="save"><?= $editing ? 'Update Day' : 'Save Day' ?></button><?php if ($editing): ?><a class="button button-quiet" href="days.php">Cancel</a><?php endif; ?></div></form></section>
<section class="panel"><div class="section-heading"><h2>Days</h2><span class="count-badge"><?= count($rows) ?></span></div><?php if (!$rows): ?><p class="muted">No days added yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Day ID</th><th>Day Name 1</th><th>Day Name 2</th><th>Actions</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= (int)$row['day_id'] ?></td><td><?= h($row['day_name_1']) ?></td><td><?= h($row['day_name_2']) ?></td><td class="row-actions"><a class="text-link" href="days.php?edit=<?= (int)$row['day_id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this day?')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$row['day_id'] ?>"><button class="text-button" type="submit" name="action" value="delete">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?></main><?php appLayoutEnd(); ?>
