<?php
require __DIR__ . '/includes/bootstrap.php';

// Everyone: their own account and password. The super admin also gets the Accounts and Database panels; the Accounts buttons post here
// (refused for anyone else) and the Database ones to manager.php.
$text = fn(string $key): string => is_string($_POST[$key] ?? null) ? $_POST[$key] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $text('action');
    if ($action === 'password') {
        $error = Auth::changePassword($text('current'), $text('new'), $text('confirm'));
        if (!$error) {
            session_regenerate_id(true);
            redirect('account.php', 'Password changed.');
        }
    } elseif ($action === 'request') {
        $problem = Requests::send($text('role'), $text('note'));
        redirect('account.php', $problem ?? 'Request sent. The super admin will see it in their mailbox.', $problem ? 'error' : 'ok');
    } elseif (Auth::isSuperAdmin()) {
        $id = (int) $text('id');
        if ($problem = $action === 'reset' ? Auth::passwordProblem($text('new'), $text('new')) : null) {
            redirect('account.php', $problem, 'error');
        }
        $ok = match ($action) {
            'role'   => Auth::setRole($id, $text('role')),
            'approve'      => Requests::approve($id),
            'deny'         => Requests::deny($id),
            'mute'         => Requests::muteSender($id),
            'unmute'       => Requests::setMuted($id, false),
            'requests_on'  => Requests::setOpen(true),
            'requests_off' => Requests::setOpen(false),
            'ban'    => Auth::ban($id, true),
            'unban'  => Auth::ban($id, false),
            'unlock' => Auth::unlock($id),
            'reset'  => Auth::resetPassword($id, $text('new')),
            'delete' => Auth::delete($id),
            default  => false,
        };
        $done = ['role' => 'Role changed.', 'approve' => 'Request approved. The role was changed.', 'deny' => 'Request denied.', 'mute' => 'Request denied and sender muted.', 'unmute' => 'Requests unmuted for that account.', 'requests_on' => 'Access requests are on.', 'requests_off' => 'Access requests are off. Nobody sees the button.', 'ban' => 'Account banned. It can no longer sign in.', 'unban' => 'Account unbanned.', 'unlock' => 'Account unlocked.', 'reset' => 'Password reset. Give it to them and ask them to change it under their account.', 'delete' => 'Account deleted.'];
        redirect('account.php', $ok ? $done[$action] : 'Nothing was changed.', $ok ? 'ok' : 'error');
    }
}

$can = ['View the dashboard, products, reports and history.'];
Auth::can('stock') && $can[] = 'Record stock in and out.';
Auth::can('products') && $can[] = 'Add, edit and delete products, and use the QR tools.';
Auth::isSuperAdmin() && $can[] = 'Manage accounts and the database (below).';

$title = 'Account';
include __DIR__ . '/includes/header.php';
?>
<div class="dash-grid account-top">
    <section class="card">
        <div class="profile">
            <span class="avatar big"><?= e(mb_strtoupper(mb_substr($_SESSION['user']['username'], 0, 1))) ?></span>
            <div>
                <div class="row-name"><?= e($_SESSION['user']['username']) ?></div>
                <span class="badge role"><?= e(Auth::label($_SESSION['user']['role'])) ?></span>
            </div>
        </div>
        <p class="hint">What you can do</p>
        <ul class="can">
            <?php foreach ($can as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
        </ul>
        <?= Auth::isSuperAdmin() ? '' : Live::panel('request') ?>
    </section>

    <section class="card form">
        <h2>Change password</h2>
        <form method="post">
            <input type="hidden" name="action" value="password">
            <label>Current password
                <input type="password" name="current" autocomplete="current-password" required>
            </label>
            <label>New password <span class="hint">(at least 8 characters)</span>
                <input type="password" name="new" minlength="8" autocomplete="new-password" required>
            </label>
            <label>Repeat new password
                <input type="password" name="confirm" minlength="8" autocomplete="new-password" required>
            </label>
            <button class="btn">Change password</button>
        </form>
    </section>
</div>

<?php if (Auth::isSuperAdmin()): ?>
    <h2 class="admin-head">Super admin</h2>
    <?= Live::panel('mailbox') ?>
    <?= Live::panel('accounts') ?>
    <?php include __DIR__ . '/includes/database_panel.php'; ?>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
