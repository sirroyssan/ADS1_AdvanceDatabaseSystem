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

<!-- Shared confirmation dialog (used for delete). -->
<div class="modal" id="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title"
     aria-describedby="confirm-message" hidden>
    <div class="modal__backdrop" data-modal-close></div>
    <div class="modal__panel" role="document">
        <div class="modal__icon"><?= icon('trash') ?></div>
        <h2 class="modal__title" id="confirm-title">Delete record?</h2>
        <p class="modal__message" id="confirm-message">Are you sure you want to delete this record?</p>
        <div class="modal__actions">
            <button type="button" class="btn btn--outline" data-modal-close>Cancel</button>
            <button type="button" class="btn btn--danger" id="confirm-accept" data-loading-text="Deleting…">
                <span class="btn__label">Delete</span>
            </button>
        </div>
    </div>
</div>

<script src="<?= e(asset('assets/js/dashboard.js')) ?>"></script>
<script src="<?= e(asset('assets/js/modules.js')) ?>"></script>
<?php foreach ($pageScripts as $script): ?>
    <script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
