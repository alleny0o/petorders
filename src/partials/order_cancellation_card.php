<?php
/**
 * Cancellation Reason card, shared by customer/order_detail.php and
 * staff/order_detail.php (was byte-identical between the two before
 * this extraction). Shown whenever the order is cancelled, regardless
 * of who cancelled it -- a customer already knows their own reason
 * from entering it, but a staff-initiated cancel is the case this card
 * actually exists for. Same detail-list row styling as the Order
 * Details/Delivery cards, not a bare paragraph.
 *
 * Included (not called): reads $order and $cancelledByLabel from the
 * caller's scope; assigns nothing into that scope. Renders nothing at
 * all unless the order is cancelled with a stored reason, so callers
 * include it unconditionally.
 */
?>
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
