<?php
/**
 * Dashboard layout - part 3 of 3. Closes the layout and loads JavaScript.
 *
 *   $pageScripts (string[]) - extra JS files, e.g. ['assets/js/categories.js']
 */

defined('APP_ROOT') or exit('No direct access.');

$pageScripts = $pageScripts ?? [];
?>
        </main>

        <footer class="app-footer no-print">
            <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All Rights Reserved.</span>
        </footer>
    </div>
</div>

<!-- Confirmations and messages are shown as sweet dialogs (assets/js/sweet.js). -->
<script src="<?= e(asset('assets/js/sweet.js')) ?>"></script>
<script src="<?= e(asset('assets/js/dashboard.js')) ?>"></script>
<script src="<?= e(asset('assets/js/modules.js')) ?>"></script>
<script src="<?= e(asset('assets/js/modal-form.js')) ?>"></script>
<?php foreach ($pageScripts as $script): ?>
    <script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
