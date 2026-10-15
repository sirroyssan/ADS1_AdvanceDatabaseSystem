<?php
/**
 * One way to decide everywhere: a record is shown read-only, and at the
 * bottom right sit Reject and Approve. Approve asks for a confirmation;
 * Reject opens a second modal (pages/decisions/reject.php) for the reason,
 * then saves. Nothing about the amount can be edited by the reviewer - the
 * borrower submits it, the owner / admin only approves or rejects it.
 *
 * Each kind names the handler that already does the real work (and checks
 * role, ownership and status again on the server).
 */

defined('APP_ROOT') or exit('No direct access.');

const DECISION_KINDS = [
    // A repayment of a card purchase reported by the borrower.
    'repayment'     => ['title' => 'Reject repayment', 'handler' => 'pages/transactions/repayment.php', 'reason' => 'reason',
                        'fixed' => ['action' => 'reject'], 'ids' => ['id', 'payment_id'],
                        'help' => 'The repayment stays in the history as rejected and the borrower is told why, so they can submit it again correctly.'],
    // A borrower payment (Borrower Payments).
    'payment'       => ['title' => 'Reject payment', 'handler' => 'pages/borrower_payments/review.php', 'reason' => 'reason',
                        'fixed' => ['decision' => 'rejected'], 'ids' => ['id'],
                        'help' => 'The payment stays in the history as rejected and the borrower is told why, so they can submit it again correctly.'],
    // A staff member's request to edit or void a record.
    'authorization' => ['title' => 'Reject request', 'handler' => 'pages/authorizations/decide.php', 'reason' => 'note',
                        'fixed' => ['decision' => 'reject'], 'ids' => ['id'],
                        'help' => 'Nothing changes. The staff member sees your reason.'],
    // A self-registered account (System → Approvals).
    'account'       => ['title' => 'Reject account', 'handler' => 'pages/users/approvals.php', 'reason' => 'note',
                        'fixed' => ['action' => 'reject_account'], 'ids' => ['id'],
                        'help' => 'The account is set to inactive (never deleted) and cannot sign in. The reason is kept in the activity log.'],
    // A password reset request (System → Approvals).
    'reset'         => ['title' => 'Reject password reset', 'handler' => 'pages/users/approvals.php', 'reason' => 'note',
                        'fixed' => ['action' => 'reject_reset'], 'ids' => ['id'],
                        'help' => 'The password is not changed. The user is told your reason.'],
];

/**
 * Reject + Approve, bottom right. $approve = [url, hidden fields, confirm
 * text, confirm title]; $reject = the kind's ids (e.g. ['id' => ..]).
 */
function decision_bar(array $approve, string $kind, array $reject, string $approveLabel = 'Approve'): string
{
    [$url, $hidden, $confirm, $confirmTitle] = $approve + [null, [], null, null];

    return '<div class="decision-bar no-print">'
        . '<a data-modal-form class="btn btn--outline btn--danger-outline" href="' . e(url('pages/decisions/reject.php', ['kind' => $kind] + $reject)) . '">'
        . icon('x') . ' <span>Reject</span></a>'
        . post_button($url, $hidden, $approveLabel, 'check', 'btn btn--primary', $confirm, false, $confirmTitle)
        . '</div>';
}
