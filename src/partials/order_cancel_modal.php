<?php
/**
 * Cancel-with-reason modal, shared by customer/order_detail.php and
 * staff/order_detail.php (single order per page, so the order id is
 * baked into the form rather than JS-populated). Modeled on the
 * reject-with-reason modal on admin/registrations.php: required
 * textarea, X-close + Cancel + Esc + backdrop all wired automatically
 * by petordersOpenModal(), reopened by the page's bottom script on a
 * reason_required validation failure.
 *
 * Included (not called), same convention as table_pagination.php:
 * reads $order, $cancelErrors, and $cancelReasonOld from the caller's
 * scope; assigns nothing into that scope. The page owns the render
 * guard (customer: own pending order only; staff: pending/accepted).
 *
 * Posts back to the including page (PHP_SELF). The action verb differs
 * per page -- 'cancel_order' (customer) vs 'cancel' (staff) -- a
 * pre-existing naming split kept as-is so neither POST handler
 * changes; branched on the session role here (admins cancel through
 * the staff page, so only 'customer' maps to the customer verb).
 */
?>
<div class="modal-overlay" id="cancel-order-modal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="cancel-order-modal-title">
        <form method="post" action="<?= e($_SERVER['PHP_SELF']) ?>?id=<?= (int) $order['order_id'] ?>" novalidate data-ajax-submit>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= ($_SESSION['role'] ?? null) === 'customer' ? 'cancel_order' : 'cancel' ?>">
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
