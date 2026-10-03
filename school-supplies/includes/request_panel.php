<?php // "Request more access", inside the profile card on account.php, for every account that has a bigger role to ask for. Posts to account.php (Requests::send). A live panel: drawn through Live::panel(). ?>
<?php $mine = Requests::mine(); $last = $mine['last']; ?>
<?php if ($mine['options']): ?>
<div class="request">
    <h2>Request more access</h2>
    <?php if ($mine['off']): ?>
        <p class="hint">Access requests are turned off.</p>
    <?php elseif ($last && $last['status'] === 'pending'): ?>
        <p class="hint">Your request for <b><?= e(Auth::label($last['role'])) ?></b> is waiting for the super admin.</p>
    <?php elseif ($last && $last['waiting']): ?>
        <p class="hint">Your request for <b><?= e(Auth::label($last['role'])) ?></b> was <?= e($last['status']) ?>. You can send another after <?= date('M j, H:i', strtotime($last['next'])) ?>.</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="action" value="request">
            <label>Role
                <select name="role">
                    <?php foreach ($mine['options'] as $role): ?><option value="<?= $role ?>"><?= e(Auth::label($role)) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Message <span class="hint">(optional)</span>
                <input type="text" name="note" maxlength="200" placeholder="What do you need it for?">
            </label>
            <button class="btn">Send request</button>
            <p class="hint">One request every 24 hours.</p>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>
