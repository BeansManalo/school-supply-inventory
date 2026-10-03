<?php // The super admin's Database panel on account.php: save, load and delete the whole inventory. Its buttons post to manager.php, which refuses everyone else. ?>
<section class="card panel manager">
    <div class="panel-head">
        <h2>Database</h2>
    </div>
    <p class="hint">The inventory lives in the database, and a backup copy is refreshed on this computer every hour. Keep a copy, restore one, or start over.</p>
    <div class="mgr">
        <div>
            <strong>Save to file</strong>
            <p class="hint">Download the whole inventory as one file.</p>
        </div>
        <a class="btn" href="manager.php?save">Save</a>
    </div>
    <form class="mgr" method="post" action="manager.php" enctype="multipart/form-data"
          data-confirm="Load this file? It replaces the whole inventory in the database.">
        <div>
            <strong>Load from file</strong>
            <p class="hint">Replaces the whole inventory in the database with the file's content.</p>
            <input type="file" name="file" accept=".scinvent" required>
        </div>
        <button class="btn btn-secondary" name="load">Load</button>
    </form>
    <form class="mgr mgr-danger" method="post" action="manager.php"
          data-confirm="Delete the entire inventory and the backup copy? This cannot be undone.">
        <div>
            <strong>Delete inventory</strong>
            <p class="hint">Deletes all products, stock history and the backup copy. Save to file first if you might want it back.</p>
        </div>
        <button class="btn btn-danger" name="delete">Delete</button>
    </form>
</section>
