<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('customer');

$pdo = get_db();
$myUserId = (int) $_SESSION['user_id'];

// Pre-setting $labId here means layout_customer.php's guarded lookup
// never re-queries; the save_details path also validates against it
// before the layout include runs.
$labId = current_customer_lab_id($pdo, $myUserId);

$orderId = ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0;

/**
 * Lab-scoped fetch: the c.lab_id join condition IS the access control
 * (any customer in the order's lab may view it, per the "view own lab's
 * orders" role permission) -- an id outside the viewer's lab simply
 * returns no row, indistinguishable from a nonexistent order.
 */
function fetch_order_for_lab(PDO $pdo, int $orderId, int $labId): ?array
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
                u.username AS placer_username
         FROM orders o
         JOIN customers c ON c.user_id = o.customer_id AND c.lab_id = ?
         JOIN products p  ON p.product_id = o.product_id
         JOIN nuclides n  ON n.nuclide_id = p.nuclide_id
         JOIN users u     ON u.user_id = o.customer_id
         LEFT JOIN lab_delivery_locations loc ON loc.location_id = o.location_id
         LEFT JOIN lab_product_users pu       ON pu.product_user_id = o.product_user_id
         WHERE o.order_id = ?'
    );
    $stmt->execute([$labId, $orderId]);
    $order = $stmt->fetch();

    return $order !== false ? $order : null;
}

$order = ($labId > 0 && $orderId > 0) ? fetch_order_for_lab($pdo, $orderId, $labId) : null;

$isOwnOrder = $order !== null && (int) $order['customer_id'] === $myUserId;
// First real consumer of can_edit_order_notes(). Staff/admin never reach
// this page (require_role customer), so the call resolves to "own order
// only" -- lab-mates get the read-only view.
$canEditNotes = $order !== null && can_edit_order_notes('customer', $isOwnOrder);
// Additional app-specific restriction layered on top of the shared
// permission check: notes stay editable only while the order is pending.
// Not folded into can_edit_order_notes() itself -- that helper models the
// general customer/lab permission, not this page's status rule. $order is
// guaranteed non-null here (short-circuits via $canEditNotes).
$notesEditable = $canEditNotes && $order['status'] === 'pending';
// Core-detail edits (nuclide/product, activity, schedule, location,
// recipient) share the same gate: own order, still pending. Lab-mates
// and non-pending orders keep the read-only view.
$detailsEditable = $isOwnOrder && $order['status'] === 'pending';

$flash = null;
$notesErrors = [];
$notesOld = null;
$editErrors = [];
$editOld = null;
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
        // dead code. Same split as the Stage-1 CRUD pages.
        if ($notesErrors && request_wants_json()) {
            json_response(['ok' => false, 'errors' => $notesErrors], 422);
        }

        if (!$notesErrors) {
            // Ownership repeated in the WHERE clause -- the mutation
            // itself refuses a tampered id, not just the gate above.
            // Single shared overwritable field, last-write-wins: no
            // history row, and no audit entry (audit log is status-only).
            $pdo->prepare('UPDATE orders SET notes = ? WHERE order_id = ? AND customer_id = ?')
                ->execute([$notesOld !== '' ? $notesOld : null, $orderId, $myUserId]);
            // PRG: redirect after a successful save so a reload doesn't
            // hit the browser's resubmit-form prompt (confirming it would
            // silently re-save the note) -- same pattern as
            // cancel_order/save_details below. The AJAX path navigates to
            // the same destination itself, so the arrival-flag toast
            // works identically either way.
            $dest = '/customer/order_detail.php?id=' . $orderId . '&notes_updated=1';
            if (request_wants_json()) {
                json_response(['ok' => true, 'redirect' => $dest]);
            }
            redirect($dest);
        }
    } elseif ($action === 'save_details' && $detailsEditable) {
        // No notes key here: notes keeps its own separately-gated form
        // below (single shared field) -- this form never touches it.
        $editOld = [
            'nuclide_id'      => '',
            'product_id'      => '',
            'activity_mci'    => '',
            'requested_date'  => '',
            'requested_time'  => '',
            'location_id'     => '',
            'product_user_id' => '',
        ];
        foreach ($editOld as $key => $_) {
            $editOld[$key] = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
        }

        // Exact same validation chain as order creation (helpers.php);
        // notes is passed empty since this form has no notes field and
        // the UPDATE below never writes that column.
        $validation = validate_order_input($pdo, $editOld + ['notes' => ''], $labId);
        $editErrors = $validation['errors'];

        // Same AJAX/no-JS split as save_notes above.
        if ($editErrors && request_wants_json()) {
            json_response(['ok' => false, 'errors' => $editErrors], 422);
        }

        if (!$editErrors) {
            $values = $validation['values'];
            // rowCount() can't distinguish "order moved on" from a no-op
            // save (affected-rows semantics, no MYSQL_ATTR_FOUND_ROWS on
            // the connection), so ownership and status are verified
            // under a row lock first; the UPDATE keeps the full guard in
            // its WHERE anyway -- same defense-in-depth as notes/cancel.
            // No audit row: the audit log is status-only and an edit is
            // not a status change (updated_at bumps on its own).
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT status FROM orders WHERE order_id = ? AND customer_id = ? FOR UPDATE');
                $stmt->execute([$orderId, $myUserId]);

                if ($stmt->fetchColumn() === 'pending') {
                    $pdo->prepare(
                        "UPDATE orders
                         SET product_id = ?, location_id = ?, product_user_id = ?,
                             activity_mci = ?, requested_datetime = ?
                         WHERE order_id = ? AND customer_id = ? AND status = 'pending'"
                    )->execute([
                        $values['product_id'],
                        $values['location_id'],
                        $values['product_user_id'],
                        $values['activity_mci'],
                        $values['requested_datetime'],
                        $orderId,
                        $myUserId,
                    ]);
                    $pdo->commit();
                    // Query flag carries the toast across the redirect,
                    // mirroring ?placed=1 / ?cancelled=1.
                    $dest = '/customer/order_detail.php?id=' . $orderId . '&updated=1';
                    if (request_wants_json()) {
                        json_response(['ok' => true, 'redirect' => $dest]);
                    }
                    redirect($dest);
                }

                // The order moved on mid-request (e.g. staff accepted
                // it). Re-fetch AND re-derive the gates so the page
                // renders the real current state -- read-only, no edit
                // form -- rather than our stale copy. The AJAX path can
                // only surface this as an error toast ({ok:false,
                // message}); its page keeps the stale edit form, so the
                // re-fetch below is skipped for it.
                $pdo->rollBack();
                if (request_wants_json()) {
                    json_response(['ok' => false, 'message' => 'This order can no longer be edited.'], 422);
                }
                $flash = ['type' => 'error', 'message' => 'This order can no longer be edited.'];
                $order = fetch_order_for_lab($pdo, $orderId, $labId);
                $isOwnOrder = $order !== null && (int) $order['customer_id'] === $myUserId;
                $canEditNotes = $order !== null && can_edit_order_notes('customer', $isOwnOrder);
                $notesEditable = $canEditNotes && $order['status'] === 'pending';
                $detailsEditable = $isOwnOrder && $order['status'] === 'pending';
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    } elseif ($action === 'cancel_order' && $isOwnOrder) {
        // Customer-initiated pending -> cancelled, one of the now-designed
        // lifecycle transitions (CLAUDE.md) -- routed through the shared
        // transition_order_status() (src/helpers.php) so this path can't
        // drift from the staff accept/return/complete/cancel transitions.
        $cancelReasonOld = trim((string) ($_POST['cancellation_reason'] ?? ''));
        $result = transition_order_status($pdo, $orderId, 'cancelled', 'customer', $myUserId, $cancelReasonOld);

        if ($result['ok']) {
            // Query flag carries the toast across the redirect,
            // mirroring the ?placed=1 arrival pattern.
            $dest = '/customer/order_detail.php?id=' . $orderId . '&cancelled=1';
            if (request_wants_json()) {
                json_response(['ok' => true, 'redirect' => $dest]);
            }
            redirect($dest);
        }

        if ($result['reason'] === 'reason_required') {
            $cancelErrors['cancellation_reason'] = 'Enter a reason for cancelling this order (500 characters max).';
            // AJAX: the error renders inside the still-open modal -- no
            // full-page re-render + reopen flicker. Plain POST falls
            // through to the reopen-on-error script below.
            if (request_wants_json()) {
                json_response(['ok' => false, 'errors' => $cancelErrors], 422);
            }
        } else {
            if (request_wants_json()) {
                json_response(['ok' => false, 'message' => 'This order can no longer be cancelled.'], 422);
            }
            // The order moved on mid-request (e.g. staff accepted it).
            // Re-fetch AND re-derive the gates so the page renders the
            // real current state, not our stale copy -- same pattern as
            // save_details above.
            $flash = ['type' => 'error', 'message' => 'This order can no longer be cancelled.'];
            $order = fetch_order_for_lab($pdo, $orderId, $labId);
            $isOwnOrder = $order !== null && (int) $order['customer_id'] === $myUserId;
            $canEditNotes = $order !== null && can_edit_order_notes('customer', $isOwnOrder);
            $notesEditable = $canEditNotes && $order['status'] === 'pending';
            $detailsEditable = $isOwnOrder && $order['status'] === 'pending';
        }
    }
}

// Edit mode renders in place of the read-only detail cards. Entered via
// ?edit=1 -- the edit form posts back to the same URL, so a failed
// validation naturally re-renders in edit mode with its errors, while a
// mid-request status change drops $detailsEditable (re-derived above)
// and with it the form.
$editing = $detailsEditable && ($_GET['edit'] ?? null) === '1';

if ($editing && $editOld === null) {
    // Pre-populate from the order's current values. A product (or
    // location/product user) that has since been deactivated or lost
    // institute access is absent from the select options -- the field
    // simply renders unselected and a currently-valid pick is required
    // to save, which is exactly what validation would enforce anyway.
    $requestedTs = strtotime($order['requested_datetime']);
    $editOld = [
        'nuclide_id'      => (string) (int) $order['nuclide_id'],
        'product_id'      => (string) (int) $order['product_id'],
        'activity_mci'    => format_activity_mci($order['activity_mci']),
        'requested_date'  => date('Y-m-d', $requestedTs),
        'requested_time'  => date('H:i', $requestedTs),
        'location_id'     => $order['location_id'] !== null ? (string) (int) $order['location_id'] : '',
        'product_user_id' => $order['product_user_id'] !== null ? (string) (int) $order['product_user_id'] : '',
    ];
}

// Fetched once, used by both the on-screen Cancellation Reason card and
// its print-document counterpart below -- only queried when there's
// actually a cancellation to explain.
$cancellationActor = ($order !== null && $order['status'] === 'cancelled')
    ? fetch_order_cancellation_actor($pdo, (int) $order['order_id'])
    : null;
$cancelledByLabel = null;
if ($cancellationActor !== null) {
    if ($cancellationActor['is_customer']) {
        $cancelledByLabel = customer_display_name($cancellationActor['first_name'], $cancellationActor['last_name'], $cancellationActor['username']);
        if ((int) $order['customer_id'] === $myUserId) {
            $cancelledByLabel .= ' (you)';
        }
    } else {
        $cancelledByLabel = 'Staff';
    }
}

// Recipient always resolves to someone: a lab_product_users row when
// attached, otherwise the placing customer (product_user_id NULL means
// exactly that, per the orders schema comment). Used by both the
// on-screen detail card and its print-document counterpart below. The
// placing customer's "email" is their username -- per CLAUDE.md, username
// already IS the NIH email address, no separate email column exists on
// users.
$recipientIsProductUser = $order !== null && $order['product_user_name'] !== null;
if ($order !== null) {
    $recipientName = $recipientIsProductUser
        ? $order['product_user_name']
        : customer_display_name($order['placer_first_name'], $order['placer_last_name'], $order['placer_username']);
    $recipientEmail = $recipientIsProductUser ? $order['product_user_email'] : $order['placer_username'];
    $recipientSuffix = $recipientIsProductUser ? '' : ($isOwnOrder ? ' (you — placing customer)' : ' (placing customer)');
}

$pageTitle = $order !== null ? 'Order #' . (int) $order['order_id'] : 'Order Not Found';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../../src/partials/head.php'; ?>
</head>
<!-- Body class scopes the @media print rules (order-page.css) to this
     page only -- printing any other page is untouched. -->
<body class="order-detail-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../../src/partials/layout_customer.php'; ?>
        <main class="app-main">
            <?php if ($order !== null && ($_GET['placed'] ?? null) === '1'): ?>
                <?= toast_flash('success', 'Order placed.') ?>
            <?php elseif ($order !== null && ($_GET['cancelled'] ?? null) === '1'): ?>
                <?= toast_flash('success', 'Order cancelled.') ?>
            <?php elseif ($order !== null && ($_GET['updated'] ?? null) === '1'): ?>
                <?= toast_flash('success', 'Order updated.') ?>
            <?php elseif ($order !== null && ($_GET['notes_updated'] ?? null) === '1'): ?>
                <?= toast_flash('success', 'Notes saved.') ?>
            <?php endif; ?>

            <?php if ($labId <= 0): ?>
                <div class="page-header">
                    <div><h1>Order</h1></div>
                </div>
                <div class="card">
                    <p class="muted">No lab assigned to your account yet &mdash; contact an administrator.</p>
                </div>
            <?php elseif ($order === null): ?>
                <div class="page-header">
                    <div><h1>Order</h1></div>
                </div>
                <div class="card">
                    <p class="muted">This order doesn't exist, or it belongs to another lab.</p>
                    <a href="/customer/orders.php" class="btn btn--secondary">Back to Orders</a>
                </div>
            <?php else: ?>
                <div class="page-header">
                    <div>
                        <a href="/customer/orders.php" class="page-header__back mb-4">&larr; Back to Orders</a>
                        <span class="badge badge--<?= e($order['status']) ?> page-header__status"><?= e(ucfirst($order['status'])) ?></span>
                        <?php // Chargeable is the default -- quiet text; the
                              // exception gets the warning chip. ?>
                        <?php if ($order['chargeable']): ?>
                            <span class="muted text-sm">Chargeable</span>
                        <?php else: ?>
                            <span class="badge badge--not-chargeable">Not chargeable</span>
                        <?php endif; ?>
                        <h1>Order #<?= (int) $order['order_id'] ?></h1>
                    </div>
                    <div class="page-header__actions">
                        <button type="button" class="btn btn--ghost" id="print-order-btn">Print</button>
                        <?php if ($isOwnOrder && $order['status'] === 'pending'): ?>
                            <button type="button" class="btn btn--danger" id="cancel-order-trigger" aria-haspopup="dialog">Cancel Order</button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php // Cancel-with-reason modal -- single order on this page, so
                      // $orderId is hardcoded directly into the form rather than
                      // JS-populated (contrast with staff/orders.php's shared,
                      // multi-row version of this same modal). Modeled on the
                      // reject-with-reason modal on admin/registrations.php:
                      // required textarea, X-close + Cancel + Esc + backdrop all
                      // wired automatically by petordersOpenModal(), reopens itself
                      // below on a reason_required validation failure. ?>
                <?php if ($isOwnOrder && $order['status'] === 'pending'): ?>
                    <div class="modal-overlay" id="cancel-order-modal" hidden>
                        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="cancel-order-modal-title">
                            <form method="post" action="/customer/order_detail.php?id=<?= (int) $order['order_id'] ?>" novalidate data-ajax-submit>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel_order">
                                <div class="modal__header">
                                    <h2 class="modal__title" id="cancel-order-modal-title">Cancel order #<?= (int) $order['order_id'] ?>?</h2>
                                    <button type="button" class="modal__close" data-modal-close aria-label="Close">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <line x1="18" y1="6" x2="6" y2="18"></line>
                                            <line x1="6" y1="6" x2="18" y2="18"></line>
                                        </svg>
                                    </button>
                                </div>
                                <div class="modal__body">
                                    <p class="modal__message">This cannot be undone.</p>
                                    <div class="<?= field_class($cancelErrors, 'cancellation_reason', 'field mb-0') ?>">
                                        <label for="cancellation_reason">Cancellation reason <span class="required-mark">*</span></label>
                                        <textarea id="cancellation_reason" name="cancellation_reason" maxlength="500" required data-modal-focus><?= e($cancelReasonOld) ?></textarea>
                                        <?= field_error($cancelErrors, 'cancellation_reason') ?>
                                    </div>
                                </div>
                                <div class="modal__footer">
                                    <button type="button" class="btn btn--ghost" data-modal-close>Keep Order</button>
                                    <button type="submit" class="btn btn--danger-solid">Cancel Order</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($flash && $flash['type'] === 'error'): ?>
                    <div class="alert alert--error"><?= e($flash['message']) ?></div>
                <?php endif; ?>

                <?php if ($editing): ?>
                    <?php
                    // Same initial-paint honesty check as new_order_form.php:
                    // the Delivery section only renders visible when the
                    // (submitted or current) product's fixed delivery method
                    // is direct_delivery, and the fulfillment hint pre-paints
                    // alongside it. $petordersLayout['products']/['nuclides']/
                    // ['locations']/['product_users'] come from
                    // layout_customer.php's shared get_new_order_form_data()
                    // load -- the same lists backing the new-order modal.
                    $locationVisible = false;
                    $selectedDeliveryLabel = '';
                    foreach ($petordersLayout['products'] as $p) {
                        if ($editOld['product_id'] === (string) $p['product_id']) {
                            $locationVisible = $p['delivery_method'] === 'direct_delivery';
                            $selectedDeliveryLabel = delivery_method_label($p['delivery_method']);
                            break;
                        }
                    }
                    ?>
                    <div class="card order-edit-card">
                        <span class="card__title">Edit Order Details</span>

                        <?php // Always in the DOM (hidden when clean), same as the
                              // Stage-1 modal banners: the AJAX submit unhides it
                              // alongside the injected field errors, and
                              // initFieldErrorClearing() hides it again once the
                              // last invalid field clears -- both keyed off
                              // data-error-banner-for matching the form id. ?>
                        <div class="alert alert--error" data-error-banner-for="order-edit-form" <?= $editErrors ? '' : 'hidden' ?>>Please correct the errors below and resubmit.</div>

                        <?php // edit_-prefixed ids throughout: the new-order
                              // modal (included on every customer page) already
                              // owns #nuclide_id, #product_id, #location-field,
                              // etc. Field NAMES stay identical to creation so
                              // validate_order_input() reads both forms the
                              // same way. data-ajax-submit (the shared
                              // initAjaxForms handler), not the modal's
                              // bespoke fetch. Bare .field/.field-row rows
                              // (account_detail.php's plain-card form idiom),
                              // not the modal's .form-section groupings. ?>
                        <form method="post" action="/customer/order_detail.php?id=<?= (int) $order['order_id'] ?>&amp;edit=1" id="order-edit-form" novalidate data-ajax-submit
                              data-confirm="Save your changes to order #<?= (int) $order['order_id'] ?>?"
                              data-confirm-title="Save changes?"
                              data-confirm-verb="Save changes">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_details">

                            <div class="field-row">
                                <div class="<?= field_class($editErrors, 'nuclide_id') ?>">
                                    <label for="edit_nuclide_id">Nuclide <span class="required-mark">*</span></label>
                                    <select id="edit_nuclide_id" name="nuclide_id" required>
                                        <option value="">Select nuclide&hellip;</option>
                                        <?php foreach ($petordersLayout['nuclides'] as $n): ?>
                                            <option value="<?= (int) $n['nuclide_id'] ?>" <?= $editOld['nuclide_id'] === (string) $n['nuclide_id'] ? 'selected' : '' ?>><?= e($n['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?= field_error($editErrors, 'nuclide_id') ?>
                                </div>

                                <div class="<?= field_class($editErrors, 'product_id') ?>">
                                    <label for="edit_product_id">Product <span class="required-mark">*</span></label>
                                    <select id="edit_product_id" name="product_id" required>
                                        <option value="">Select nuclide first&hellip;</option>
                                        <?php // Same option markup as new_order_form.php:
                                              // every label carries its delivery method, and
                                              // the data attributes drive the shared cascade
                                              // (petordersInitOrderCascade in script.js). ?>
                                        <?php foreach ($petordersLayout['products'] as $p): ?>
                                            <option
                                                value="<?= (int) $p['product_id'] ?>"
                                                data-nuclide-id="<?= (int) $p['nuclide_id'] ?>"
                                                data-requires-location="<?= $p['delivery_method'] === 'direct_delivery' ? 1 : 0 ?>"
                                                data-delivery-label="<?= e(delivery_method_label($p['delivery_method'])) ?>"
                                                <?= $editOld['product_id'] === (string) $p['product_id'] ? 'selected' : '' ?>
                                            ><?= e($p['name']) ?> &mdash; <?= e(delivery_method_label($p['delivery_method'])) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="field-hint" id="edit-delivery-method-hint" <?= $selectedDeliveryLabel !== '' ? '' : 'hidden' ?>><?= $selectedDeliveryLabel !== '' ? 'Fulfillment: ' . e($selectedDeliveryLabel) : '' ?></span>
                                    <?= field_error($editErrors, 'product_id') ?>
                                </div>
                            </div>

                            <?php // Plain wrapper (id + hidden only) so the
                                  // shared cascade can keep toggling the whole
                                  // delivery-location block. ?>
                            <div id="edit-location-field" <?= $locationVisible ? '' : 'hidden' ?>>
                                <div class="<?= field_class($editErrors, 'location_id') ?>">
                                    <label for="edit_location_id">Delivery location <span class="required-mark">*</span></label>
                                    <select id="edit_location_id" name="location_id" <?= $locationVisible ? 'required' : 'disabled' ?>>
                                        <option value="">Select a location&hellip;</option>
                                        <?php foreach ($petordersLayout['locations'] as $loc): ?>
                                            <option value="<?= (int) $loc['location_id'] ?>" <?= $editOld['location_id'] === (string) $loc['location_id'] ? 'selected' : '' ?>><?= e($loc['name']) ?><?= $loc['room'] ? ' (' . e($loc['room']) . ')' : '' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!$petordersLayout['locations']): ?>
                                        <span class="field-hint">No delivery locations yet &mdash; <a href="/customer/lab_delivery_locations.php">add one</a>.</span>
                                    <?php endif; ?>
                                    <?= field_error($editErrors, 'location_id') ?>
                                </div>
                            </div>

                            <div class="field-row field-row--3">
                                <div class="<?= field_class($editErrors, 'activity_mci') ?>">
                                    <label for="edit_activity_mci">Activity (mCi) <span class="required-mark">*</span></label>
                                    <input type="number" step="0.01" min="0" id="edit_activity_mci" name="activity_mci" value="<?= e($editOld['activity_mci']) ?>" required>
                                    <?= field_error($editErrors, 'activity_mci') ?>
                                </div>
                                <div class="<?= field_class($editErrors, 'requested_date') ?>">
                                    <label for="edit_requested_date">Requested date <span class="required-mark">*</span></label>
                                    <input type="date" id="edit_requested_date" name="requested_date" value="<?= e($editOld['requested_date']) ?>" required>
                                    <?= field_error($editErrors, 'requested_date') ?>
                                </div>
                                <div class="<?= field_class($editErrors, 'requested_time') ?>">
                                    <label for="edit_requested_time">Requested time <span class="required-mark">*</span></label>
                                    <input type="text" id="edit_requested_time" name="requested_time" placeholder="HH:MM" maxlength="5" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" value="<?= e($editOld['requested_time']) ?>" required>
                                    <span class="field-hint">24-hour time, e.g. 14:30.</span>
                                    <?= field_error($editErrors, 'requested_time') ?>
                                </div>
                            </div>

                            <div class="<?= field_class($editErrors, 'product_user_id', 'field mb-0') ?>">
                                <label for="edit_product_user_id">Product user</label>
                                <select id="edit_product_user_id" name="product_user_id">
                                    <option value="">I'm the recipient&hellip;</option>
                                    <?php foreach ($petordersLayout['product_users'] as $pu): ?>
                                        <option value="<?= (int) $pu['product_user_id'] ?>" <?= $editOld['product_user_id'] === (string) $pu['product_user_id'] ? 'selected' : '' ?>><?= e($pu['first_name'] . ' ' . $pu['last_name']) ?><?= $pu['email'] ? ' (' . e($pu['email']) . ')' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?= field_error($editErrors, 'product_user_id') ?>
                            </div>

                            <div class="order-form-actions">
                                <a href="/customer/order_detail.php?id=<?= (int) $order['order_id'] ?>" class="btn btn--ghost">Discard Changes</a>
                                <button type="submit" class="btn btn--primary">Save Changes</button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                <div class="card">
                    <div class="card__header">
                        <span class="card__title">Order Details</span>
                        <?php // Edit trigger lives on the card it edits (its
                              // top-right corner), not up in the page-header
                              // actions with Print/Cancel. $editing is always
                              // false in this branch (read-only cards). ?>
                        <?php if ($detailsEditable): ?>
                            <a href="/customer/order_detail.php?id=<?= (int) $order['order_id'] ?>&amp;edit=1" class="btn btn--secondary btn--sm">Edit Order</a>
                        <?php endif; ?>
                    </div>
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
                            <span class="detail-list__value"><?= e(customer_display_name($order['placer_first_name'], $order['placer_last_name'], $order['placer_username'])) ?><?= $isOwnOrder ? ' (you)' : '' ?></span>
                        </div>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Product user</span>
                            <span class="detail-list__value"><?= e($recipientName) ?><?= e($recipientSuffix) ?></span>
                        </div>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Product user email</span>
                            <span class="detail-list__value"><?= $recipientEmail !== null ? e($recipientEmail) : '&mdash;' ?></span>
                        </div>
                        <?php // Chargeable as a real fact row, not just the
                              // header indicator -- no staff Billing card here
                              // (customers can't toggle it). Plain label/value
                              // like every other row in this card -- no badge. ?>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Chargeable</span>
                            <span class="detail-list__value"><?= $order['chargeable'] ? 'Yes' : 'No' ?></span>
                        </div>
                    </div>
                </div>

                <?php // Rendered ONLY for direct_delivery -- the other two
                      // fulfillment methods carry no location, so no empty
                      // section appears for them. ?>
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
                <?php endif; // $editing: edit form vs read-only detail cards ?>

                <?php // Shown whenever the order is cancelled, regardless of who
                      // cancelled it -- a customer already knows their own
                      // reason from entering it, but a staff-initiated cancel is
                      // the case this card actually exists for. Same
                      // detail-list row styling as the Order Details/Delivery
                      // cards above, not a bare paragraph. ?>
                <?php if ($order['status'] === 'cancelled' && $order['cancellation_reason'] !== null && $order['cancellation_reason'] !== ''): ?>
                    <div class="card">
                        <span class="card__title">Cancellation Reason</span>
                        <div class="detail-list">
                            <?php if ($cancelledByLabel !== null): ?>
                                <div class="detail-list__row">
                                    <span class="detail-list__label">Cancelled by</span>
                                    <span class="detail-list__value"><?= e($cancelledByLabel) ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="detail-list__row">
                                <span class="detail-list__label">Reason</span>
                                <span class="detail-list__value"><?= e($order['cancellation_reason']) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <span class="card__title">Notes</span>
                    <?php if ($notesEditable): ?>
                        <form method="post" action="/customer/order_detail.php?id=<?= (int) $order['order_id'] ?>" class="order-notes-form" novalidate data-ajax-submit>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_notes">
                            <div class="<?= field_class($notesErrors, 'notes', 'field mb-0') ?>">
                                <?php // id is order-notes, not notes: the
                                      // new-order modal (included on every
                                      // customer page) already owns #notes. ?>
                                <label for="order-notes" class="sr-only">Notes</label>
                                <?php $orderNotesValue = $notesOld !== null ? $notesOld : (string) $order['notes']; ?>
                                <textarea id="order-notes" name="notes" maxlength="500"><?= e($orderNotesValue) ?></textarea>
                                <span class="field-hint char-count" id="order-notes-char-count"><?= mb_strlen($orderNotesValue) ?>/500</span>
                                <?= field_error($notesErrors, 'notes') ?>
                            </div>
                            <div class="mt-2">
                                <button type="submit" class="btn btn--primary">Save Notes</button>
                            </div>
                        </form>
                    <?php elseif ($order['notes'] !== null && $order['notes'] !== ''): ?>
                        <p class="order-notes-text mb-0"><?= e($order['notes']) ?></p>
                    <?php else: ?>
                        <p class="muted mb-0">No notes.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <?php if ($order !== null): ?>
        <!-- Print-only document (order-page.css): hidden on screen; the
             only thing @media print renders. Sibling of .app-shell so
             hiding the shell wholesale can't take the document down with
             it. Classes only, no ids -- the on-screen page owns
             #print-order-btn, #order-notes, etc. No heading tags either,
             so the document outline keeps the on-screen page's single h1. -->
        <div class="order-print" aria-hidden="true">
            <div class="order-print__header">
                <div class="order-print__brand"><?= e(app_setting('app_name')) ?></div>
                <?php // Chargeable mirrors both of its on-screen placements --
                      // inline next to the status pill here, and as an Order
                      // Details field below (this page has no Billing card;
                      // that's staff-only). ?>
                <div class="order-print__identity">
                    <span class="order-print__title">Order #<?= (int) $order['order_id'] ?></span>
                    <span class="order-print__status-pill"><?= e(ucfirst($order['status'])) ?></span>
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
                <div class="order-print__field"><dt>Placed by</dt><dd><?= e(customer_display_name($order['placer_first_name'], $order['placer_last_name'], $order['placer_username'])) ?><?= $isOwnOrder ? ' (you)' : '' ?></dd></div>
            </dl>

            <?php if ($order['delivery_method'] === 'direct_delivery'): ?>
                <div class="order-print__section-title">Delivery</div>
                <dl class="order-print__grid">
                    <div class="order-print__field"><dt>Delivery location</dt><dd><?= $order['location_name'] !== null ? e($order['location_name']) . ($order['location_room'] ? ' (' . e($order['location_room']) . ')' : '') : '&mdash;' ?></dd></div>
                </dl>
            <?php endif; ?>

            <?php // Mirrors the on-screen Cancellation Reason card above --
                  // this print document is a separate hand-built block, not a
                  // styled copy of the on-screen cards, so every on-screen
                  // section needs its own explicit field here. ?>
            <?php if ($order['status'] === 'cancelled' && $order['cancellation_reason'] !== null && $order['cancellation_reason'] !== ''): ?>
                <div class="order-print__section-title">Cancellation Reason</div>
                <dl class="order-print__grid">
                    <?php if ($cancelledByLabel !== null): ?>
                        <div class="order-print__field"><dt>Cancelled by</dt><dd><?= e($cancelledByLabel) ?></dd></div>
                    <?php endif; ?>
                    <div class="order-print__field"><dt>Reason</dt><dd><?= e($order['cancellation_reason']) ?></dd></div>
                </dl>
            <?php endif; ?>

            <div class="order-print__section-title">Notes</div>
            <div class="order-print__notes"><?= $order['notes'] !== null && $order['notes'] !== '' ? e($order['notes']) : 'No notes.' ?></div>

            <div class="order-print__footer">Printed <?= e(date('M j, Y H:i')) ?></div>
        </div>
    <?php endif; ?>
</body>
<?php if ($order !== null): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Strip one-time arrival-toast query flags (placed/cancelled/
    // updated/notes_updated) from the URL bar once their toast has been
    // queued above, so a reload or back-navigation doesn't re-show a
    // toast for an action that already happened. This is separate from
    // the PRG pattern every POST handler above already uses -- PRG is
    // what stops the browser's resubmit-form prompt; this only stops a
    // stale success toast from replaying on a plain GET reload.
    window.petordersCleanArrivalFlags(['placed', 'cancelled', 'updated', 'notes_updated']);

    // Browsers' print dialog includes "Save as PDF", so one native
    // mechanism covers both print and PDF -- no libraries (CLAUDE.md).
    document.getElementById('print-order-btn').addEventListener('click', function () {
        window.print();
    });

    // ---- Cancel-order modal: opened from the page-header trigger; same
    // reopen-on-error convention as admin/registrations.php's reject modal. ----
    var cancelTrigger = document.getElementById('cancel-order-trigger');
    var cancelModal = document.getElementById('cancel-order-modal');
    if (cancelTrigger && cancelModal) {
        cancelTrigger.addEventListener('click', function (e) {
            window.petordersOpenModal(cancelModal, { opener: e.currentTarget });
        });
    }
    <?php if ($cancelErrors): ?>
    if (cancelModal) { window.petordersOpenModal(cancelModal); }
    <?php endif; ?>

    // ---- Live character counter for Notes: same behavior as the
    // new-order modal's counter (new_order_form.php's own inline
    // script) -- duplicated rather than shared since each is a few
    // lines inside an already page-specific script, and #order-notes
    // only exists here while $notesEditable. ----
    var orderNotesField = document.getElementById('order-notes');
    var orderNotesCounter = document.getElementById('order-notes-char-count');
    if (orderNotesField && orderNotesCounter) {
        var updateOrderNotesCounter = function () {
            orderNotesCounter.textContent = orderNotesField.value.length + '/' + orderNotesField.maxLength;
        };
        orderNotesField.addEventListener('input', updateOrderNotesCounter);
        updateOrderNotesCounter();
    }

    // Pending-order edit form (only in the DOM while editing): the same
    // shared cascade as the new-order modal (script.js), initialized
    // against the edit_-prefixed ids -- its first run keeps the order's
    // pre-selected product because the product's data-nuclide-id matches
    // the pre-selected nuclide.
    var editNuclide = document.getElementById('edit_nuclide_id');
    if (editNuclide) {
        window.petordersInitOrderCascade({
            nuclideSelect: editNuclide,
            productSelect: document.getElementById('edit_product_id'),
            locationField: document.getElementById('edit-location-field'),
            locationSelect: document.getElementById('edit_location_id'),
            deliveryHint: document.getElementById('edit-delivery-method-hint')
        });
    }
});
</script>
<?php endif; ?>
</html>
