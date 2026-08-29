<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('staff');

$pdo = get_db();
$staffUserId = (int) $_SESSION['user_id'];
// transition_order_status()/can_edit_order_notes() normalize 'admin' ->
// 'staff' internally, so the literal session role is passed through
// rather than hardcoding 'staff' -- same convention as staff/orders.php.
$staffRole = (string) $_SESSION['role'];

$orderId = ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0;

/**
 * Not lab-scoped -- staff can open any order regardless of lab ("any
 * staff, any order"), unlike customer/order_detail.php's
 * fetch_order_for_lab(). Lab/institute/PI are joined in (LEFT --
 * customers.lab_id/supervising_pi_id are both nullable) since staff
 * spans every lab and needs that context; the customer-facing page
 * omits them because they're always implicit there (the customer's own
 * lab).
 */
function fetch_order_for_staff(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT o.order_id, o.customer_id, o.status, o.activity_mci,
                o.requested_datetime, o.notes, o.created_at,
                o.product_id, o.location_id, o.product_user_id,
                o.chargeable, o.cancellation_reason,
                p.name AS product_name, p.delivery_method, p.nuclide_id,
                n.name AS nuclide_name,
                loc.name AS location_name, loc.room AS location_room,
                CONCAT(pu.first_name, \' \', pu.last_name) AS product_user_name,
                pu.email AS product_user_email,
                u.first_name AS placer_first_name, u.last_name AS placer_last_name,
                u.username AS placer_username,
                l.lab_name, i.name AS institute_name, i.shorthand_name AS institute_shorthand, pi.pi_name
         FROM orders o
         JOIN customers c ON c.user_id = o.customer_id
         JOIN products p  ON p.product_id = o.product_id
         JOIN nuclides n  ON n.nuclide_id = p.nuclide_id
         JOIN users u     ON u.user_id = o.customer_id
         LEFT JOIN lab_delivery_locations loc ON loc.location_id = o.location_id
         LEFT JOIN lab_product_users pu       ON pu.product_user_id = o.product_user_id
         LEFT JOIN labs l       ON l.lab_id = c.lab_id
         LEFT JOIN institutes i ON i.institute_id = l.institute_id
         LEFT JOIN pis pi       ON pi.pi_id = c.supervising_pi_id
         WHERE o.order_id = ?'
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    return $order !== false ? $order : null;
}

$order = $orderId > 0 ? fetch_order_for_staff($pdo, $orderId) : null;

// Staff/admin can always edit Notes, on any order, regardless of status
// (CLAUDE.md) -- no pending-only gate like the customer page's
// $notesEditable. $isOwnOrder is meaningless for staff (can_edit_order_notes()
// ignores it once role is staff/admin), passed false for clarity.
$notesEditable = $order !== null && can_edit_order_notes($staffRole, false);

$flash = null;
$notesErrors = [];
$notesOld = null;
$cancelErrors = [];
$cancelReasonOld = '';

if ($order !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_notes' && $notesEditable) {
        $notesOld = trim((string) ($_POST['notes'] ?? ''));

        if (mb_strlen($notesOld) > 500) {
            $notesErrors['notes'] = 'Notes must be 500 characters or fewer.';
        }

        // AJAX submits (script.js initAjaxForms) get the errors as JSON
        // and render them in place; a plain POST falls through to the
        // full-page re-render below -- kept as the no-JS fallback, not
        // dead code. Same split as customer/order_detail.php.
        if ($notesErrors && request_wants_json()) {
            json_response(['ok' => false, 'errors' => $notesErrors], 422);
        }

        if (!$notesErrors) {
            // No customer_id in the WHERE -- staff isn't the order's
            // owner, unlike the customer page's equivalent UPDATE.
            $pdo->prepare('UPDATE orders SET notes = ? WHERE order_id = ?')
                ->execute([$notesOld !== '' ? $notesOld : null, $orderId]);
            $dest = '/staff/order_detail.php?id=' . $orderId . '&notes_updated=1';
            if (request_wants_json()) {
                json_response(['ok' => true, 'redirect' => $dest]);
            }
            redirect($dest);
        }
    } elseif (in_array($action, ['accept', 'return', 'complete', 'reopen'], true)) {
        // Plain transitions with no extra data, routed through
        // transition_order_status() (src/helpers.php, Pass 1) -- the same
        // shared validation the customer-cancel path and this page's own
        // cancel branch below go through, so this page can't drift from
        // the state machine. 'return' (accepted -> pending) and 'reopen'
        // (canceled -> pending) are the same transition as far as
        // transition_order_status() is concerned -- only the copy here
        // (and which status the button renders under) distinguishes them.
        $toStatus = ['accept' => 'accepted', 'return' => 'pending', 'complete' => 'completed', 'reopen' => 'pending'][$action];
        // Past-tense flag names, distinct from $action itself, matching
        // $arrivalMessages' keys below (and 'canceled=1' from the cancel
        // branch further down, which already happened to be past-tense).
        $doneFlag = ['accept' => 'accepted', 'return' => 'returned', 'complete' => 'completed', 'reopen' => 'reopened'][$action];
        $verbPast = ['accept' => 'accepted', 'return' => 'returned to pending', 'complete' => 'completed', 'reopen' => 'reopened'][$action];
        $result = transition_order_status($pdo, $orderId, $toStatus, $staffRole, $staffUserId);

        if ($result['ok']) {
            redirect('/staff/order_detail.php?id=' . $orderId . '&' . $doneFlag . '=1');
        }

        // The order moved on mid-request (e.g. another staff member
        // already acted on it). Re-fetch so the page renders the real
        // current state, not our stale copy -- same pattern as
        // customer/order_detail.php's save_details/cancel branches.
        $flash = ['type' => 'error', 'message' => 'This order can no longer be ' . $verbPast . '.'];
        $order = fetch_order_for_staff($pdo, $orderId);
        $notesEditable = $order !== null && can_edit_order_notes($staffRole, false);
    } elseif ($action === 'cancel') {
        // Staff-initiated pending|accepted -> canceled. Same shared
        // reason-modal pattern as customer/order_detail.php -- on
        // failure, fall through to render instead of redirecting, so the
        // modal can reopen with the error.
        $cancelReasonOld = trim((string) ($_POST['cancellation_reason'] ?? ''));
        $result = transition_order_status($pdo, $orderId, 'canceled', $staffRole, $staffUserId, $cancelReasonOld);

        if ($result['ok']) {
            $dest = '/staff/order_detail.php?id=' . $orderId . '&canceled=1';
            if (request_wants_json()) {
                json_response(['ok' => true, 'redirect' => $dest]);
            }
            redirect($dest);
        }

        if ($result['reason'] === 'reason_required') {
            $cancelErrors['cancellation_reason'] = 'Enter a reason for canceling this order (500 characters max).';
            // AJAX: the error renders inside the still-open modal -- no
            // full-page re-render + reopen flicker. Plain POST falls
            // through to the reopen-on-error script below.
            if (request_wants_json()) {
                json_response(['ok' => false, 'errors' => $cancelErrors], 422);
            }
        } else {
            // AJAX can only surface this as an error toast; its page
            // keeps the stale state, so the re-fetch below is skipped.
            if (request_wants_json()) {
                json_response(['ok' => false, 'message' => 'This order can no longer be canceled.'], 422);
            }
            $flash = ['type' => 'error', 'message' => 'This order can no longer be canceled.'];
            $order = fetch_order_for_staff($pdo, $orderId);
            $notesEditable = $order !== null && can_edit_order_notes($staffRole, false);
        }
    } elseif ($action === 'toggle_chargeable') {
        // Freely toggleable regardless of order status, and NOT a status
        // transition -- no order_audit_log row, no confirm dialog, per
        // CLAUDE.md. $order is already loaded, so the current value is
        // read from there rather than a second SELECT.
        $newChargeable = $order['chargeable'] ? 0 : 1;
        $pdo->prepare('UPDATE orders SET chargeable = ? WHERE order_id = ?')
            ->execute([$newChargeable, $orderId]);
        redirect('/staff/order_detail.php?id=' . $orderId . '&chargeable_updated=1');
    }
}

// Fetched once, used by the Cancellation Reason card below -- byte-for-
// byte the same derivation as customer/order_detail.php (shared
// fetch_order_cancellation_actor(), src/helpers.php), since that page's
// display is what this one is told to reuse. "Staff" stays generic here
// too, even though the Activity card below (a separate feature) names
// the actual staff member -- this card is deliberately the same output
// as the customer-facing one.
$cancellationActor = ($order !== null && $order['status'] === 'canceled')
    ? fetch_order_cancellation_actor($pdo, (int) $order['order_id'])
    : null;
$canceledByLabel = null;
if ($cancellationActor !== null) {
    if ($cancellationActor['is_customer']) {
        $canceledByLabel = customer_display_name($cancellationActor['first_name'], $cancellationActor['last_name'], $cancellationActor['username']);
    } else {
        $canceledByLabel = 'Staff';
    }
}

// Full history, oldest first (fetch_order_audit_trail()'s natural
// order) -- reversed at render time for a newest-first feed. Unlike the
// Cancellation Reason card above, actor names here are the real name,
// not collapsed to "Staff" -- knowing which colleague did what is the
// whole point of this internal trail.
$auditTrail = $order !== null ? fetch_order_audit_trail($pdo, (int) $order['order_id']) : [];

// Recipient always resolves to someone: a lab_product_users row when
// attached, otherwise the placing customer (product_user_id NULL means
// exactly that, per the orders schema comment) -- same derivation as
// customer/order_detail.php, minus the "(you)" case (staff is never the
// placing customer). The placing customer's "email" is their username --
// per CLAUDE.md, username already IS the NIH email address.
$recipientIsProductUser = $order !== null && $order['product_user_name'] !== null;
if ($order !== null) {
    $recipientName = $recipientIsProductUser
        ? $order['product_user_name']
        : customer_display_name($order['placer_first_name'], $order['placer_last_name'], $order['placer_username']);
    $recipientEmail = $recipientIsProductUser ? $order['product_user_email'] : $order['placer_username'];
    $recipientSuffix = $recipientIsProductUser ? '' : ' (placing customer)';
}

$pageTitle = $order !== null ? 'Order #' . (int) $order['order_id'] : 'Order Not Found';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../../src/partials/head.php'; ?>
</head>
<!-- Body class scopes the @media print rules (order-page.css) to this
     page only -- same generic class customer/order_detail.php uses,
     reused as-is rather than a staff-specific variant since the print
     rules it drives (.app-shell/.toast-region/.modal-overlay hidden,
     .order-print shown) are page-shape rules, not customer-specific. -->
<body class="order-detail-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../../src/partials/layout_staff.php'; ?>
        <main class="app-main">
            <?php
            $arrivalMessages = [
                'accepted'           => 'Order accepted.',
                'returned'           => 'Order returned to pending.',
                'completed'          => 'Order completed.',
                'canceled'           => 'Order canceled.',
                'reopened'           => 'Order reopened.',
                'chargeable_updated' => 'Chargeable status updated.',
                'notes_updated'      => 'Notes saved.',
            ];
            $arrivalFlag = null;
            foreach ($arrivalMessages as $flagName => $message) {
                if (($_GET[$flagName] ?? null) === '1') {
                    $arrivalFlag = $flagName;
                    break;
                }
            }
            ?>
            <?php if ($order !== null && $arrivalFlag !== null): ?>
                <?= toast_flash('success', $arrivalMessages[$arrivalFlag]) ?>
            <?php endif; ?>

            <?php if ($order === null): ?>
                <div class="page-header">
                    <div><h1>Order</h1></div>
                </div>
                <div class="card">
                    <p class="muted">This order doesn't exist.</p>
                    <a href="/staff/orders.php" class="btn btn--secondary">Back to Order Queue</a>
                </div>
            <?php else: ?>
                <div class="page-header">
                    <div>
                        <a href="/staff/orders.php" class="page-header__back mb-4">&larr; Back to Order Queue</a>
                        <span class="badge badge--<?= e($order['status']) ?> page-header__status"><?= e(order_status_label($order['status'])) ?></span>
                        <?php // Chargeable is the default -- quiet text; the
                              // exception gets the warning chip. ?>
                        <?php if ($order['chargeable']): ?>
                            <span class="muted text-sm">Chargeable</span>
                        <?php else: ?>
                            <span class="badge badge--not-chargeable">Not chargeable</span>
                        <?php endif; ?>
                        <h1>Order #<?= (int) $order['order_id'] ?></h1>
                    </div>
                    <?php // All lifecycle actions live here, not in the queue table --
                          // one clear set of buttons per current status. Accept/
                          // Return/Complete are plain data-confirm forms (the app's
                          // existing dialog pattern); Cancel opens the reason modal
                          // below. ?>
                    <div class="page-header__actions">
                        <button type="button" class="btn btn--ghost" id="print-order-btn">Print</button>
                        <?php if ($order['status'] === 'pending'): ?>
                            <form method="post" action="/staff/order_detail.php?id=<?= (int) $order['order_id'] ?>"
                                  data-confirm="Accept order #<?= (int) $order['order_id'] ?>?"
                                  data-confirm-title="Accept order"
                                  data-confirm-verb="Accept">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="accept">
                                <button type="submit" class="btn btn--primary">Accept</button>
                            </form>
                            <button type="button" class="btn btn--danger" id="cancel-order-trigger" aria-haspopup="dialog">Cancel Order</button>
                        <?php elseif ($order['status'] === 'accepted'): ?>
                            <form method="post" action="/staff/order_detail.php?id=<?= (int) $order['order_id'] ?>"
                                  data-confirm="Return order #<?= (int) $order['order_id'] ?> to pending?"
                                  data-confirm-title="Return order"
                                  data-confirm-verb="Return">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="return">
                                <button type="submit" class="btn btn--secondary">Return</button>
                            </form>
                            <form method="post" action="/staff/order_detail.php?id=<?= (int) $order['order_id'] ?>"
                                  data-confirm="Mark order #<?= (int) $order['order_id'] ?> as completed? This cannot be undone."
                                  data-confirm-title="Complete order"
                                  data-confirm-verb="Complete">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="complete">
                                <button type="submit" class="btn btn--primary">Complete</button>
                            </form>
                            <button type="button" class="btn btn--danger" id="cancel-order-trigger" aria-haspopup="dialog">Cancel Order</button>
                        <?php elseif ($order['status'] === 'canceled'): ?>
                            <?php // Same weight as Return (btn--secondary, plain
                                  // data-confirm) -- reopening needs no
                                  // justification, unlike canceling, so no
                                  // reason modal here. ?>
                            <form method="post" action="/staff/order_detail.php?id=<?= (int) $order['order_id'] ?>"
                                  data-confirm="Reopen order #<?= (int) $order['order_id'] ?>? This returns it to pending."
                                  data-confirm-title="Reopen order"
                                  data-confirm-verb="Reopen">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reopen">
                                <button type="submit" class="btn btn--secondary">Reopen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php // Cancel-with-reason modal: shared partial (see its header
                      // for the contract); the render guard stays page-owned
                      // (staff can cancel pending OR accepted, unlike customer). ?>
                <?php if (in_array($order['status'], ['pending', 'accepted'], true)): ?>
                    <?php include __DIR__ . '/../../src/partials/order_cancel_modal.php'; ?>
                <?php endif; ?>

                <?php if ($flash && $flash['type'] === 'error'): ?>
                    <div class="alert alert--error"><?= e($flash['message']) ?></div>
                <?php endif; ?>

                <div class="card">
                    <span class="card__title">Order Details</span>
                    <div class="detail-list">
                        <div class="detail-list__row">
                            <span class="detail-list__label">Product</span>
                            <span class="detail-list__value"><?= e($order['product_name']) ?></span>
                        </div>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Nuclide</span>
                            <span class="detail-list__value"><?= e($order['nuclide_name']) ?></span>
                        </div>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Fulfillment</span>
                            <span class="detail-list__value"><?= e(delivery_method_label($order['delivery_method'])) ?></span>
                        </div>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Activity</span>
                            <span class="detail-list__value tabular"><?= e(format_activity_mci($order['activity_mci'])) ?> mCi</span>
                        </div>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Requested</span>
                            <span class="detail-list__value tabular"><?= e(date('M j, Y H:i', strtotime($order['requested_datetime']))) ?></span>
                        </div>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Placed</span>
                            <span class="detail-list__value tabular"><?= e(date('M j, Y H:i', strtotime($order['created_at']))) ?></span>
                        </div>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Placed by</span>
                            <span class="detail-list__value"><?= e(customer_display_name($order['placer_first_name'], $order['placer_last_name'], $order['placer_username'])) ?></span>
                        </div>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Product user</span>
                            <span class="detail-list__value"><?= e($recipientName) ?><?= e($recipientSuffix) ?></span>
                        </div>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Product user email</span>
                            <span class="detail-list__value"><?= $recipientEmail !== null ? e($recipientEmail) : '&mdash;' ?></span>
                        </div>
                        <?php // Lab/Institute/PI: implicit on the customer page (always
                              // the viewer's own), but staff spans every lab, so this
                              // context belongs here. ?>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Lab</span>
                            <span class="detail-list__value"><?= e($order['lab_name'] ?? '—') ?></span>
                        </div>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Institute</span>
                            <span class="detail-list__value"><?= $order['institute_name'] !== null ? e($order['institute_name']) . ' (' . e($order['institute_shorthand']) . ')' : '—' ?></span>
                        </div>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Supervising PI</span>
                            <span class="detail-list__value"><?= e($order['pi_name'] ?? '—') ?></span>
                        </div>
                    </div>
                </div>

                <?php // Rendered ONLY for direct_delivery -- same as the customer page. ?>
                <?php if ($order['delivery_method'] === 'direct_delivery'): ?>
                    <div class="card">
                        <span class="card__title">Delivery</span>
                        <div class="detail-list">
                            <div class="detail-list__row">
                                <span class="detail-list__label">Delivery location</span>
                                <span class="detail-list__value"><?= $order['location_name'] !== null ? e($order['location_name']) . ($order['location_room'] ? ' (' . e($order['location_room']) . ')' : '') : '&mdash;' ?></span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php // Clear label of what the flag means, not a bare toggle
                      // button -- current state as a fact, one sentence of
                      // explanation, one action. ?>
                <div class="card">
                    <span class="card__title">Billing</span>
                    <div class="detail-list">
                        <div class="detail-list__row">
                            <span class="detail-list__label">Chargeable</span>
                            <span class="detail-list__value">
                                <?php if ($order['chargeable']): ?>
                                    Chargeable
                                <?php else: ?>
                                    <span class="badge badge--not-chargeable">Not chargeable</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <p class="field-hint mt-2 mb-2">Flags this order for billing follow-up. Independent of the order's status, freely toggleable, and not recorded in the audit trail.</p>
                    <form method="post" action="/staff/order_detail.php?id=<?= (int) $order['order_id'] ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_chargeable">
                        <button type="submit" class="btn btn--secondary btn--sm"><?= $order['chargeable'] ? 'Remove Chargeable Flag' : 'Mark as Chargeable' ?></button>
                    </form>
                </div>

                <?php // Cancellation Reason + Notes cards: shared partials (see
                      // their headers for the variable contracts; the
                      // cancellation card self-guards on canceled+reason). ?>
                <?php include __DIR__ . '/../../src/partials/order_cancellation_card.php'; ?>

                <?php include __DIR__ . '/../../src/partials/order_notes_card.php'; ?>

                <?php // order_audit_log history, newest first. Reuses
                      // .comment-list/.comment-item (order-page.css) -- fully
                      // styled already but unused anywhere until now (leftover
                      // from the dropped comment-thread design), a good fit for
                      // a timestamped event feed. ?>
                <div class="card">
                    <span class="card__title">Activity</span>
                    <?php if (!$auditTrail): ?>
                        <p class="muted mb-0">No activity recorded.</p>
                    <?php else: ?>
                        <ul class="comment-list">
                            <?php foreach (array_reverse($auditTrail) as $entry): ?>
                                <?php
                                $entryActorName = $entry['is_customer']
                                    ? customer_display_name($entry['first_name'], $entry['last_name'], $entry['username'])
                                    : ($entry['first_name'] . ' ' . $entry['last_name']);
                                if ((int) $entry['changed_by_user_id'] === $staffUserId) {
                                    $entryActorName .= ' (you)';
                                }
                                ?>
                                <li class="comment-item">
                                    <div class="comment-item__meta">
                                        <span class="comment-item__author"><?= e($entryActorName) ?></span>
                                        <span class="comment-item__timestamp"><?= e(date('M j, Y H:i', strtotime($entry['changed_at']))) ?></span>
                                    </div>
                                    <div class="comment-item__body"><?= e(describe_order_transition($entry['status_from'], $entry['status_to'])) ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <?php if ($order !== null): ?>
        <!-- Print-only document (order-page.css): hidden on screen; the
             only thing @media print renders. Sibling of .app-shell, same
             pattern as customer/order_detail.php's version -- a hand-built
             document, not a patched/hidden copy of the on-screen cards, so
             action buttons/back link/the chargeable-toggle form are simply
             never placed here rather than hidden by exception. Splits the
             on-screen Order Details card's fields into two print sections
             (Order Details -> Recipient & Lab) -> Delivery -> Cancellation
             Reason -> Notes -> Activity -> Printed footer. No separate
             Billing section (unlike the on-screen Billing card) --
             chargeable already shows in the header annotation and as an
             Order Details field, so a third section would just repeat the
             same fact again. Each field section is a 2-column grid
             (.order-print__grid) of compact label/value pairs rather than
             one field per line, and Activity uses a compact 2-column
             print-only list (.order-print__activity) instead of
             .comment-list, which is sized for screen. -->
        <div class="order-print" aria-hidden="true">
            <div class="order-print__header">
                <div class="order-print__brand"><?= e(app_setting('app_name')) ?></div>
                <div class="order-print__identity">
                    <span class="order-print__title">Order #<?= (int) $order['order_id'] ?></span>
                    <span class="order-print__status-pill"><?= e(order_status_label($order['status'])) ?></span>
                    <span><?= $order['chargeable'] ? 'Chargeable' : 'Not chargeable' ?></span>
                </div>
            </div>

            <div class="order-print__section-title">Order Details</div>
            <dl class="order-print__grid">
                <div class="order-print__field"><dt>Product</dt><dd><?= e($order['product_name']) ?></dd></div>
                <div class="order-print__field"><dt>Nuclide</dt><dd><?= e($order['nuclide_name']) ?></dd></div>
                <div class="order-print__field"><dt>Fulfillment</dt><dd><?= e(delivery_method_label($order['delivery_method'])) ?></dd></div>
                <div class="order-print__field"><dt>Activity</dt><dd><?= e(format_activity_mci($order['activity_mci'])) ?> mCi</dd></div>
                <div class="order-print__field"><dt>Chargeable</dt><dd><?= $order['chargeable'] ? 'Yes' : 'No' ?></dd></div>
                <div class="order-print__field"><dt>Requested</dt><dd><?= e(date('M j, Y H:i', strtotime($order['requested_datetime']))) ?></dd></div>
                <div class="order-print__field"><dt>Placed</dt><dd><?= e(date('M j, Y H:i', strtotime($order['created_at']))) ?></dd></div>
            </dl>

            <div class="order-print__section-title">Recipient &amp; Lab</div>
            <dl class="order-print__grid">
                <div class="order-print__field"><dt>Product user</dt><dd><?= e($recipientName) ?><?= e($recipientSuffix) ?></dd></div>
                <div class="order-print__field"><dt>Product user email</dt><dd><?= $recipientEmail !== null ? e($recipientEmail) : '&mdash;' ?></dd></div>
                <div class="order-print__field"><dt>Placed by</dt><dd><?= e(customer_display_name($order['placer_first_name'], $order['placer_last_name'], $order['placer_username'])) ?></dd></div>
                <div class="order-print__field"><dt>Lab</dt><dd><?= e($order['lab_name'] ?? '—') ?></dd></div>
                <div class="order-print__field"><dt>Institute</dt><dd><?= $order['institute_name'] !== null ? e($order['institute_name']) . ' (' . e($order['institute_shorthand']) . ')' : '—' ?></dd></div>
                <div class="order-print__field"><dt>Supervising PI</dt><dd><?= e($order['pi_name'] ?? '—') ?></dd></div>
            </dl>

            <?php if ($order['delivery_method'] === 'direct_delivery'): ?>
                <div class="order-print__section-title">Delivery</div>
                <dl class="order-print__grid">
                    <div class="order-print__field"><dt>Delivery location</dt><dd><?= $order['location_name'] !== null ? e($order['location_name']) . ($order['location_room'] ? ' (' . e($order['location_room']) . ')' : '') : '&mdash;' ?></dd></div>
                </dl>
            <?php endif; ?>

            <?php if ($order['status'] === 'canceled' && $order['cancellation_reason'] !== null && $order['cancellation_reason'] !== ''): ?>
                <div class="order-print__section-title">Cancellation Reason</div>
                <dl class="order-print__grid">
                    <?php if ($canceledByLabel !== null): ?>
                        <div class="order-print__field"><dt>Canceled by</dt><dd><?= e($canceledByLabel) ?></dd></div>
                    <?php endif; ?>
                    <div class="order-print__field"><dt>Reason</dt><dd><?= e($order['cancellation_reason']) ?></dd></div>
                </dl>
            <?php endif; ?>

            <div class="order-print__section-title">Notes</div>
            <div class="order-print__notes"><?= $order['notes'] !== null && $order['notes'] !== '' ? e($order['notes']) : 'No notes.' ?></div>

            <?php // Lean/compact -- full history, but one 2-column row per
                  // entry rather than .comment-list's screen-sized spacing,
                  // so it doesn't eat pages. Newest first, same as the
                  // on-screen Activity card. ?>
            <div class="order-print__section-title">Activity</div>
            <?php if (!$auditTrail): ?>
                <p>No activity recorded.</p>
            <?php else: ?>
                <ul class="order-print__activity">
                    <?php foreach (array_reverse($auditTrail) as $entry): ?>
                        <?php
                        $printEntryActorName = $entry['is_customer']
                            ? customer_display_name($entry['first_name'], $entry['last_name'], $entry['username'])
                            : ($entry['first_name'] . ' ' . $entry['last_name']);
                        ?>
                        <li>
                            <span><?= e(date('M j, Y H:i', strtotime($entry['changed_at']))) ?> &mdash; <?= e($printEntryActorName) ?></span>
                            <span><?= e(describe_order_transition($entry['status_from'], $entry['status_to'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="order-print__footer">Printed <?= e(date('M j, Y H:i')) ?></div>
        </div>
    <?php endif; ?>
</body>
<?php if ($order !== null): ?>
<script nonce="<?= e(csp_nonce()) ?>">
document.addEventListener('DOMContentLoaded', function () {
    // Strip one-time arrival-toast query flags once their toast has
    // been queued above -- same convention as customer/order_detail.php.
    window.petordersCleanArrivalFlags(['accepted', 'returned', 'completed', 'canceled', 'reopened', 'chargeable_updated', 'notes_updated']);

    // Browsers' print dialog includes "Save as PDF", so one native
    // mechanism covers both print and PDF -- no libraries (CLAUDE.md).
    document.getElementById('print-order-btn').addEventListener('click', function () {
        window.print();
    });

    // ---- Cancel-order modal: opened from the page-header trigger; same
    // reopen-on-error convention as customer/order_detail.php's version. ----
    var cancelTrigger = document.getElementById('cancel-order-trigger');
    var cancelModal = document.getElementById('cancel-order-modal');
    var cancelTracking = null;
    if (cancelModal) {
        // Dirty-tracking + discard-confirm-on-close, shared wiring
        // (script.js) -- same shape as admin/registrations.php's reject
        // modal. onDiscard clears the reason so a confirmed discard
        // doesn't linger into a reopen.
        var cancelReasonField = document.getElementById('cancellation_reason');
        cancelTracking = window.petordersWireModalDirtyTracking(
            cancelModal,
            cancelModal.querySelector('form'),
            function (form) {
                var values = {};
                Array.prototype.forEach.call(form.elements, function (el) {
                    if (!el.name) return;
                    values[el.name] = el.value;
                });
                return values;
            },
            { title: 'Discard this cancellation?', message: 'The reason you typed will be discarded.' },
            function () { cancelReasonField.value = ''; }
        );

        // Live character counter for the reason: same behavior as the
        // Notes counter below and the reject-reason counter on
        // admin/registrations.php.
        var cancelReasonCounter = document.getElementById('cancellation-reason-char-count');
        if (cancelReasonField && cancelReasonCounter) {
            var updateCancelReasonCounter = function () {
                cancelReasonCounter.textContent = cancelReasonField.value.length + '/' + cancelReasonField.maxLength;
            };
            cancelReasonField.addEventListener('input', updateCancelReasonCounter);
            updateCancelReasonCounter();
        }
    }
    if (cancelTrigger && cancelModal) {
        cancelTrigger.addEventListener('click', function (e) {
            window.petordersOpenModal(cancelModal, { opener: e.currentTarget });
            cancelTracking.markPristine();
        });
    }
    <?php if ($cancelErrors): ?>
    // markPristine() on the error reopen too: the repopulated reason is
    // the baseline, so closing without further edits shouldn't prompt.
    if (cancelModal) { window.petordersOpenModal(cancelModal); cancelTracking.markPristine(); }
    <?php endif; ?>

    // ---- Live character counter for Notes: same behavior as
    // customer/order_detail.php's version. ----
    var orderNotesField = document.getElementById('order-notes');
    var orderNotesCounter = document.getElementById('order-notes-char-count');
    if (orderNotesField && orderNotesCounter) {
        var updateOrderNotesCounter = function () {
            orderNotesCounter.textContent = orderNotesField.value.length + '/' + orderNotesField.maxLength;
        };
        orderNotesField.addEventListener('input', updateOrderNotesCounter);
        updateOrderNotesCounter();
    }
});
</script>
<?php endif; ?>
</html>
