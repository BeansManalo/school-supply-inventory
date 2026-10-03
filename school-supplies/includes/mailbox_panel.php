<?php // The super admin's Mailbox on account.php: access requests waiting for an answer, and the switch that turns requests off for everyone. Its buttons post back to account.php. A live panel: drawn through Live::panel(). ?>
<?php $mail = Requests::pending(); $open = Requests::open(); ?>
<section class="card panel" id="mailbox">
    <div class="panel-head">
        <h2>Mailbox <span class="hint">(<?= count($mail) ?>)</span></h2>
        <form method="post">
            <input type="hidden" name="action" value="<?= $open ? 'requests_off' : 'requests_on' ?>">
            <button class="btn btn-secondary"><?= $open ? 'Turn requests off' : 'Turn requests on' ?></button>
        </form>
    </div>
    <p class="hint">
        <?= $open ? 'Accounts can ask for a bigger role, one request every 24 hours each.' : 'Requests are off: nobody sees the request button.' ?>
        Mute a sender to stop their future requests (undo it under Accounts).
    </p>
    <?php if (!$mail): ?>
        <p class="hint">No requests waiting.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="accounts">
                <thead><tr><th>From</th><th>Wants</th><th>Message</th><th>Sent</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($mail as $m): ?>
                    <tr>
                        <td><b><?= e($m['username']) ?></b></td>
                        <td><?= e(Auth::label($m['has'])) ?> &rarr; <b><?= e(Auth::label($m['want'])) ?></b></td>
                        <td class="note"><?= $m['note'] === '' ? '<span class="hint">(none)</span>' : e($m['note']) ?></td>
                        <td class="hint"><?= date('M j, H:i', strtotime($m['created_at'])) ?></td>
                        <td class="row-actions">
                            <?php foreach (['approve' => 'Approve', 'deny' => 'Deny', 'mute' => 'Mute sender'] as $action => $label): ?>
                                <form method="post"<?= $action === 'mute' ? ' data-confirm="Deny this request and mute ' . e($m['username']) . '? They cannot send more until you unmute them."' : '' ?>>
                                    <input type="hidden" name="action" value="<?= $action ?>">
                                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                    <button class="btn btn-link<?= $action === 'mute' ? ' danger' : '' ?>"><?= $label ?></button>
                                </form>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
