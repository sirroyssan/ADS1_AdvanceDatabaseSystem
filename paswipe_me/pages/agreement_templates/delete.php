<?php
/**
 * Agreement Templates - delete (unused only) or deactivate.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/template_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/agreement_templates/index.php');
}

$template = find_or_redirect(template_find(request_id()), 'pages/agreement_templates/index.php', 'Template');
require_csrf('pages/agreement_templates/view.php?id=' . $template['id']);

if (input_string($_POST, 'action') === 'deactivate') {
    db_update('agreement_templates', ['is_active' => 0], $template['id']);
    log_activity('deactivate', 'agreement_template', $template['id'], 'Deactivated agreement template "' . $template['name'] . '"');
    flash('success', 'Template "' . $template['name'] . '" was deactivated.');
    redirect('pages/agreement_templates/view.php', ['id' => $template['id']]);
}

try {
    db_transaction(function () use ($template) {
        db_value('SELECT id FROM agreement_templates WHERE id = :id FOR UPDATE', [':id' => $template['id']]);
        $usage = template_usage($template['id']);
        if ($usage['total'] > 0) {
            throw new DomainException('This template cannot be deleted because it is used by ' . usage_text($usage) . '. Deactivate it instead.');
        }
        db_exec('DELETE FROM agreement_templates WHERE id = :id', [':id' => $template['id']]);
    });

    log_activity('delete', 'agreement_template', $template['id'], 'Deleted agreement template "' . $template['name'] . '"');
    flash('success', 'Template "' . $template['name'] . '" was deleted.');
    redirect('pages/agreement_templates/index.php');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('pages/agreement_templates/view.php', ['id' => $template['id']]);
