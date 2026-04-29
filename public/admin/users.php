<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'reset_pw') {
        $id = (int)$_POST['id']; $pw = $_POST['password'] ?? '';
        if (strlen($pw) >= 4) {
            q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            flash_set('Password updated', 'success');
        } else { flash_set('Password too short','error'); }
    } elseif ($act === 'toggle') {
        $id = (int)$_POST['id'];
        q('UPDATE users SET active = 1 - active WHERE id=?', [$id]);
        flash_set('User status toggled','success');
    }
    redirect('/phdportal/admin/users.php');
}
$rows = all('SELECT * FROM users ORDER BY role DESC, username');
render_header('Users', $u);
?>
<h1 class="text-2xl font-semibold mb-4">Users</h1>
<div class="card p-0 overflow-x-auto">
<table class="data-table w-full">
<thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Panel</th><th>Active</th><th>Reset Password</th><th></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
  <td class="font-mono text-xs"><?= h($r['username']) ?></td>
  <td><?= h($r['full_name']) ?></td>
  <td><span class="text-xs font-semibold uppercase <?= $r['role']==='admin'?'text-indigo-700':'text-slate-600' ?>"><?= h($r['role']) ?></span></td>
  <td><?= h($r['panel_code']) ?> <span class="text-xs text-slate-500"><?= h($r['panel_area']) ?></span></td>
  <td><?= $r['active'] ? '<span class="text-green-700">Active</span>' : '<span class="text-rose-600">Disabled</span>' ?></td>
  <td>
    <form method="post" class="flex gap-2">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="reset_pw">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="text" name="password" placeholder="new password" class="text-xs" style="max-width:160px">
      <button class="btn btn-secondary text-xs">Set</button>
    </form>
  </td>
  <td>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="toggle">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <button class="btn btn-secondary text-xs"><?= $r['active']?'Disable':'Enable' ?></button>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php render_footer(); ?>
