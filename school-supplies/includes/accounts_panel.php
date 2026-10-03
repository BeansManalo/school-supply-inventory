<?php // The super admin's Accounts panel on account.php: every account with its role, lockout and actions. Its buttons post back to account.php. A live panel: drawn through Live::panel(). ?>
<?php $accounts = Auth::all(); ?>
<section class="card panel">
    <div class="panel-head">
        <h2>Accounts <span class="hint">(<?= count($accounts) ?>)</span></h2>
    </div>
    <p class="hint">New sign-ups are viewers (view only). A stock clerk can also record stock in/out; an inventory manager can also add, edit and delete products and use the QR tools. A banned account cannot sign in, and keeps its role for when it is unbanned.</p>
    <div class="table-wrap">
        <table class="accounts">
            <thead><tr><th>Username</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($accounts as $a): ?>
                <?php $super = $a['role'] === 'super_admin'; ?>
                <tr>
                    <td><b><?= e($a['username']) ?></b><?= (int) $a['id'] === (int) $_SESSION['user']['id'] ? ' <span class="hint">(you)</span>' : '' ?><?= $a['requests_muted'] ? ' <span class="hint">(requests muted)</span>' : '' ?></td>
                    <td>
                        <?php if ($super): ?>
                            <?= e(Auth::label($a['role'])) ?>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="action" value="role">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <select name="role" aria-label="Role of <?= e($a['username']) ?>" onchange="this.form.submit()">
                                    <?php foreach (Auth::assignable() as $role): ?>
                                        <option value="<?= $role ?>" <?= $a['role'] === $role ? 'selected' : '' ?>><?= e(Auth::label($role)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($a['banned']): ?>
                            <span class="badge st-out">Banned</span>
                        <?php elseif ($a['locked']): ?>
                            <span class="badge st-out">Locked until <?= date('H:i', strtotime($a['locked_until'])) ?></span>
                        <?php else: ?>
                            <span class="badge st-ok">Active</span>
                        <?php endif; ?>
                    </td>
                    <td class="hint"><?= $a['last_login_at'] ? date('M j, H:i', strtotime($a['last_login_at'])) : 'Never' ?></td>
                    <td class="row-actions">
                        <?php if (!$super): ?>
                            <?php if ($a['locked']): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="unlock">
                                    <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                    <button class="btn btn-link">Unlock</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($a['requests_muted']): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="unmute">
                                    <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                    <button class="btn btn-link">Unmute requests</button>
                                </form>
                            <?php endif; ?>
                            <button type="button" class="btn btn-link" data-reset="<?= $a['id'] ?>" data-name="<?= e($a['username']) ?>">Reset password</button>
                            <form method="post"<?= $a['banned'] ? '' : ' data-confirm="Ban ' . e($a['username']) . '? They cannot sign in, and a session they have open ends."' ?>>
                                <input type="hidden" name="action" value="<?= $a['banned'] ? 'unban' : 'ban' ?>">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button class="btn btn-link"><?= $a['banned'] ? 'Unban' : 'Ban' ?></button>
                            </form>
                            <form method="post" data-confirm="Delete the account <?= e($a['username']) ?>? This cannot be undone.">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button class="btn btn-link danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<dialog id="reset-dialog" class="form">
    <h2>Reset password</h2>
    <p class="hint">New password for <b data-name></b>. Give it to them and ask them to change it under their account. It also removes a lockout.</p>
    <form method="post">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="id">
        <label>New password <span class="hint">(at least 8 characters)</span>
            <input type="text" name="new" minlength="8" autocomplete="off" required>
        </label>
        <div class="actions">
            <button class="btn">Reset password</button>
            <button class="btn btn-secondary" formmethod="dialog" formnovalidate>Cancel</button>
        </div>
    </form>
</dialog>
