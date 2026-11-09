<?php
declare(strict_types=1);
require __DIR__ . '/app.php';
requireRole(['admin']);
$college = collegeName($pdo);
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$logsReady = in_array('user_logs', $tables, true);
$logs = $logsReady ? $pdo->query('SELECT id,user_id_label,user_name,user_role,action_key,activity,details,target_type,target_id,ip_address,created_at FROM user_logs ORDER BY id DESC LIMIT 3000')->fetchAll() : [];
$users = [];
$activities = [];
foreach ($logs as $log) {
    $users[$log['user_id_label']] = $log['user_name'] . ' · ' . $log['user_id_label'];
    $activities[$log['action_key']] = $log['activity'];
}
appLayoutStart('User Log', $college, 'user-logs'); ?>
<main class="dashboard wide user-logs-page">
  <p class="eyebrow">Audit trail</p>
  <h1>User Log</h1>
  <p class="muted">Review sign-ins, timetable activity, report views, password changes, and timetable generations.</p>
  <?php if (!$logsReady): ?><section class="panel"><h2>Database setup required</h2><p class="muted">Import <code>migrations/037_user_logs.sql</code> in phpMyAdmin, then reload this page. Activities will be recorded after the migration is installed.</p></section>
  <?php else: ?>
  <section class="panel">
    <div class="user-log-filters">
      <label>Search<input type="search" id="user-log-search" placeholder="Search user, activity, or details"></label>
      <label>User<select id="user-log-user"><option value="">All users</option><?php foreach ($users as $userId => $label): ?><option value="<?= h($userId) ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
      <label>Activity<select id="user-log-activity"><option value="">All activities</option><?php foreach ($activities as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
      <label>From<input type="date" id="user-log-from"></label>
      <label>To<input type="date" id="user-log-to"></label>
    </div>
    <p class="user-log-result-count" id="user-log-result-count" role="status">Showing <?= count($logs) ?> recent activities.</p>
    <?php if (!$logs): ?><p class="muted">No activities have been recorded yet.</p><?php else: ?>
    <div class="table-wrap user-log-table-wrap"><table id="user-log-table"><thead><tr><th>Date &amp; Time</th><th>User ID</th><th>User Name</th><th>Role</th><th>Activity</th><th>Details</th><th>IP Address</th></tr></thead><tbody>
      <?php foreach ($logs as $log): ?><tr data-user="<?= h($log['user_id_label']) ?>" data-action="<?= h($log['action_key']) ?>" data-date="<?= h(substr($log['created_at'], 0, 10)) ?>" data-search="<?= h(mb_strtolower($log['user_id_label'] . ' ' . $log['user_name'] . ' ' . $log['activity'] . ' ' . $log['details'] . ' ' . $log['target_type'] . ' ' . $log['target_id'])) ?>"><td><?= h(date('d M Y, g:i A', strtotime($log['created_at']))) ?></td><td><code><?= h($log['user_id_label']) ?></code></td><td><?= h($log['user_name']) ?></td><td><?= h(ucfirst($log['user_role'])) ?></td><td><?= h($log['activity']) ?></td><td><?= h($log['details']) ?></td><td><?= h($log['ip_address']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="hint user-log-limit-note">Showing the latest <?= count($logs) ?> entries (up to 3,000). Filters apply to this list.</p>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</main>
<script>
(() => {
  const rows = [...document.querySelectorAll('#user-log-table tbody tr')];
  const search = document.querySelector('#user-log-search');
  const user = document.querySelector('#user-log-user');
  const activity = document.querySelector('#user-log-activity');
  const from = document.querySelector('#user-log-from');
  const to = document.querySelector('#user-log-to');
  const count = document.querySelector('#user-log-result-count');
  const filter = () => {
    const query = (search?.value || '').trim().toLowerCase(); let visible = 0;
    rows.forEach((row) => {
      const date = row.dataset.date || '';
      const show = (!query || row.dataset.search.includes(query)) && (!user?.value || row.dataset.user === user.value) && (!activity?.value || row.dataset.action === activity.value) && (!from?.value || date >= from.value) && (!to?.value || date <= to.value);
      row.hidden = !show; if (show) visible++;
    });
    if (count) count.textContent = `Showing ${visible} of ${rows.length} recent activities.`;
  };
  [search,user,activity,from,to].forEach((element) => element?.addEventListener('input', filter));
  [user,activity,from,to].forEach((element) => element?.addEventListener('change', filter));
})();
</script>
<?php appLayoutEnd(); ?>
