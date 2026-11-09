<?php
require __DIR__ . '/app.php'; requireRole(['admin']);
$college = collegeName($pdo); $error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $name = trim($_POST['value_name'] ?? ''); $value = trim($_POST['value_value'] ?? '');
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,99}$/', $name)) $error = 'Use a setting name beginning with a letter, followed by letters, numbers, or underscores.';
    elseif ($value === '') $error = 'Enter a value.';
    else {
        $stmt = $pdo->prepare('INSERT INTO details (value_name, value_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value_value = VALUES(value_value)');
        $stmt->execute([$name, $value]); $success = 'College detail saved.'; $college = collegeName($pdo);
    }
}
$details = $pdo->query('SELECT value_name, value_value, updated_at FROM details ORDER BY value_name')->fetchAll();
appLayoutStart('College Details', $college, 'college-details');
?>
<main class="dashboard narrow"><p class="eyebrow">College profile</p><h1>College Details</h1><p class="muted">Manage identifying information about the college. Use <code>college_name</code> for the name shown on the login page.</p>
<?php if ($error): ?><div class="alert" role="alert"><?= h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success" role="status"><?= h($success) ?></div><?php endif; ?>
<section class="panel"><h2>Add or update a college detail</h2><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><label>Value name<input name="value_name" placeholder="college_name" maxlength="100" required></label><label>Value value<textarea name="value_value" rows="3" required></textarea></label><button class="button" type="submit">Save college detail</button></form></section>
<section class="panel"><h2>Saved college details</h2><?php if (!$details): ?><p class="muted">No college details saved yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Value name</th><th>Value value</th><th>Updated</th></tr></thead><tbody><?php foreach ($details as $row): ?><tr><td><code><?= h($row['value_name']) ?></code></td><td><?= nl2br(h($row['value_value'])) ?></td><td><?= h($row['updated_at']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</main><?php appLayoutEnd(); ?>
