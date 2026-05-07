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
    } elseif ($act === 'create') {
        $username  = trim((string)($_POST['username'] ?? ''));
        $email     = trim((string)($_POST['email'] ?? ''));
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $pw        = (string)($_POST['password'] ?? '');
        $role      = ($_POST['role'] ?? 'panel') === 'admin' ? 'admin' : 'panel';
        $code      = trim((string)($_POST['panel_code'] ?? ''));

        if ($username === '' || $full_name === '' || strlen($pw) < 4) {
            flash_set('Username, full name and a password of 4+ characters are required.', 'error');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('Email address is not valid.', 'error');
        } elseif (one('SELECT id FROM users WHERE username=?', [$username])) {
            flash_set('Username already exists.', 'error');
        } else {
            $panel_code = null; $panel_area = null;
            if ($role === 'panel' && $code !== '') {
                $p = one('SELECT code, area FROM panels WHERE code=?', [$code]);
                if (!$p) { flash_set('Selected panel does not exist.', 'error'); redirect('/phdportal/admin/users.php'); }
                $panel_code = $p['code']; $panel_area = $p['area'];
            }
            q('INSERT INTO users (username, email, password_hash, full_name, role, panel_code, panel_area, active)
               VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
                [$username, $email !== '' ? $email : null, password_hash($pw, PASSWORD_DEFAULT), $full_name, $role, $panel_code, $panel_area]);
            flash_set('User created.', 'success');
        }
    } elseif ($act === 'delete') {
        $id = (int)$_POST['id'];
        if ($id === (int)$u['id']) {
            flash_set('You cannot delete your own account.', 'error');
        } else {
            $marks = one('SELECT COUNT(*) c FROM interview_marks WHERE panel_user_id=?', [$id]);
            if ($marks && (int)$marks['c'] > 0) {
                flash_set('Cannot delete: this user has '.(int)$marks['c'].' interview mark(s) on file. Disable the account instead.', 'error');
            } else {
                q('DELETE FROM users WHERE id=?', [$id]);
                flash_set('User deleted.', 'success');
            }
        }
    } elseif ($act === 'assign_panel') {
        $id   = (int)$_POST['id'];
        $code = trim((string)($_POST['panel_code'] ?? ''));
        $target = one('SELECT id, role FROM users WHERE id=?', [$id]);
        if (!$target || $target['role'] !== 'panel') {
            flash_set('Only panel users can be assigned a panel.', 'error');
        } elseif ($code === '') {
            q('UPDATE users SET panel_code=NULL, panel_area=NULL WHERE id=?', [$id]);
            flash_set('Panel cleared.', 'success');
        } else {
            $p = one('SELECT code, area FROM panels WHERE code=?', [$code]);
            if (!$p) { flash_set('Panel not found.', 'error'); }
            else {
                q('UPDATE users SET panel_code=?, panel_area=? WHERE id=?', [$p['code'], $p['area'], $id]);
                flash_set('Panel assigned.', 'success');
            }
        }
    }
    redirect('/phdportal/admin/users.php');
}
$rows = all('SELECT * FROM users ORDER BY role DESC, username');
$panels = all('SELECT code, area FROM panels ORDER BY code');
render_header('Users', $u);
?>
<h1 class="text-2xl font-semibold mb-4">Users</h1>

<div class="card mb-4">
  <h2 class="font-semibold mb-3">Create User</h2>
  <form method="post" class="grid grid-cols-1 md:grid-cols-7 gap-3 items-end" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <label class="block">
      <span class="text-xs font-medium">Username</span>
      <input type="text" name="username" required class="mt-1 w-full text-sm" autocomplete="off">
    </label>
    <label class="block">
      <span class="text-xs font-medium">Email</span>
      <input type="email" name="email" class="mt-1 w-full text-sm" autocomplete="off">
    </label>
    <label class="block">
      <span class="text-xs font-medium">Full Name</span>
      <input type="text" name="full_name" required class="mt-1 w-full text-sm">
    </label>
    <label class="block">
      <span class="text-xs font-medium">Password</span>
      <input type="text" name="password" required minlength="4" class="mt-1 w-full text-sm" autocomplete="new-password">
    </label>
    <label class="block">
      <span class="text-xs font-medium">Role</span>
      <select name="role" id="newRole" class="mt-1 w-full text-sm">
        <option value="panel">panel</option>
        <option value="admin">admin</option>
      </select>
    </label>
    <label class="block" id="newPanelWrap">
      <span class="text-xs font-medium">Panel (optional)</span>
      <select name="panel_code" class="mt-1 w-full text-sm">
        <option value="">-- none --</option>
        <?php foreach ($panels as $p): ?>
          <option value="<?= h($p['code']) ?>"><?= h($p['code'].' — '.$p['area']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div>
      <button class="btn btn-primary w-full">Create</button>
    </div>
  </form>
  <script>
    $(function(){
      function syncPanelVisibility(){
        var isPanel = $('#newRole').val() === 'panel';
        $('#newPanelWrap').toggle(isPanel);
        if (!isPanel) $('#newPanelWrap select').val('');
      }
      $('#newRole').on('change', syncPanelVisibility);
      syncPanelVisibility();
    });
  </script>
</div>

<div class="card p-0 overflow-x-auto">
<table class="data-table w-full">
<thead><tr><th>Username</th><th>Email</th><th>Name</th><th>Role</th><th>Panel</th><th>Active</th><th>Reset Password</th><th></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
  <td class="font-mono text-xs"><?= h($r['username']) ?></td>
  <td class="text-xs"><?= $r['email'] ? h($r['email']) : '<span class="text-slate-400">—</span>' ?></td>
  <td><?= h($r['full_name']) ?></td>
  <td><span class="text-xs font-semibold uppercase <?= $r['role']==='admin'?'text-indigo-700':'text-slate-600' ?>"><?= h($r['role']) ?></span></td>
  <td>
    <?php if ($r['role'] === 'panel'): ?>
      <form method="post" class="flex gap-2 items-center">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="assign_panel">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <select name="panel_code" class="text-xs py-1" onchange="this.form.submit()">
          <option value="">-- none --</option>
          <?php foreach ($panels as $p): ?>
            <option value="<?= h($p['code']) ?>" <?= $r['panel_code'] === $p['code'] ? 'selected' : '' ?>>
              <?= h($p['code'].' — '.$p['area']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php else: ?>
      <span class="text-xs text-slate-400">—</span>
    <?php endif; ?>
  </td>
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
    <div class="flex gap-1">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn btn-secondary text-xs"><?= $r['active']?'Disable':'Enable' ?></button>
      </form>
      <?php if ((int)$r['id'] !== (int)$u['id']): ?>
      <form method="post" onsubmit="return confirm('Permanently delete user <?= h(addslashes($r['username'])) ?>? This cannot be undone.');">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn btn-danger text-xs">Delete</button>
      </form>
      <?php endif; ?>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php render_footer(); ?>
