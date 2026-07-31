<?php
/**
 * Notes card, shared by customer/order_detail.php and
 * staff/order_detail.php. Editable form (posts save_notes back to the
 * including page via PHP_SELF) when $notesEditable, read-only text when
 * notes exist, muted placeholder otherwise. The textarea id is
 * order-notes, not notes: the new-order modal (included on every
 * customer page) already owns #notes.
 *
 * Included (not called): reads $notesEditable, $notesErrors, $notesOld,
 * and $order from the caller's scope. RESERVED: assigns
 * $orderNotesValue into the caller's scope (the repopulate-on-error
 * value, also read by nothing else -- but don't reuse the name on
 * either order_detail page).
 */
?>
<div class="card">
    <span class="card__title">Notes</span>
    <?php if ($notesEditable): ?>
        <form method="post" action="<?= e($_SERVER['PHP_SELF']) ?>?id=<?= (int) $order['order_id'] ?>" class="order-notes-form" novalidate data-ajax-submit>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_notes">
            <div class="<?= field_class($notesErrors, 'notes', 'field mb-0') ?>">
                <label for="order-notes" class="sr-only">Notes</label>
                <?php $orderNotesValue = $notesOld !== null ? $notesOld : (string) $order['notes']; ?>
                <textarea id="order-notes" name="notes" maxlength="500"><?= e($orderNotesValue) ?></textarea>
                <span class="field-hint">Do not include PHI (patient names, MRNs, or other protected health information) in this field.</span>
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
