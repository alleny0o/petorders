<?php if ($labId <= 0): ?>
    <div class="card">
        <p class="muted">No lab assigned to your account yet &mdash; contact an administrator.</p>
    </div>
<?php else: ?>
    <?php
    // Delivery location renders only while the selected product's fixed
    // delivery_method is direct_delivery -- hidden entirely otherwise,
    // not shown-as-optional. JS keeps this in sync on change; this
    // server-side check keeps the markup's initial paint honest for
    // whatever $old carries (always pristine-empty in the modal, the
    // only context that renders this partial -- submission is AJAX, so
    // no server re-render ever repopulates it). The selected product's
    // display label is captured alongside it for the delivery hint's
    // own pre-paint.
    $locationVisible = false;
    $selectedDeliveryLabel = '';
    foreach ($products as $p) {
        if ($old['product_id'] === (string) $p['product_id']) {
            $locationVisible = $p['delivery_method'] === 'direct_delivery';
            $selectedDeliveryLabel = delivery_method_label($p['delivery_method']);
            break;
        }
    }
    ?>
    <div class="card customer-order-form-card">
        <?php // Always in the DOM (hidden when clean) -- script.js's shared
              // AJAX pipeline (initAjaxForms -> renderFieldErrors) unhides
              // it via the data-error-banner-for hookup when the server
              // returns field errors, alongside the injected per-field
              // messages. ?>
        <div class="alert alert--error" id="order-form-error-banner" data-error-banner-for="order-form" <?= $fieldErrors ? '' : 'hidden' ?>>Please correct the errors below and resubmit.</div>

        <form method="post" action="/customer/new_order.php" novalidate id="order-form" data-ajax-submit>
            <?= csrf_field() ?>

            <div class="form-section">
                <div class="form-section__heading">
                    <span class="form-section__icon" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 3h6"></path>
                            <path d="M10 3v6l-5.37 8.6A2 2 0 0 0 6.33 21h11.34a2 2 0 0 0 1.7-3.06L14 9V3"></path>
                        </svg>
                    </span>
                    <span class="form-section__title">Nuclide and product</span>
                </div>

                <div class="<?= field_class($fieldErrors, 'nuclide_id') ?>">
                    <label for="nuclide_id">Nuclide <span class="required-mark">*</span></label>
                    <select id="nuclide_id" name="nuclide_id" required data-modal-focus>
                        <option value="">Select nuclide&hellip;</option>
                        <?php foreach ($nuclides as $n): ?>
                            <option value="<?= (int) $n['nuclide_id'] ?>" <?= $old['nuclide_id'] === (string) $n['nuclide_id'] ? 'selected' : '' ?>><?= e($n['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error($fieldErrors, 'nuclide_id') ?>
                </div>

                <div class="<?= field_class($fieldErrors, 'product_id', 'field mb-0') ?>">
                    <label for="product_id">Product <span class="required-mark">*</span></label>
                    <select id="product_id" name="product_id" required>
                        <option value="">Select nuclide first&hellip;</option>
                        <?php // Every option label carries its delivery method
                              // unconditionally ("Name — Method") -- the same
                              // product name+nuclide can legitimately appear
                              // twice with different methods, and always
                              // appending keeps the labels uniform either way.
                              // data-delivery-label reuses the PHP mapping so
                              // the JS hint never re-implements it. ?>
                        <?php foreach ($products as $p): ?>
                            <option
                                value="<?= (int) $p['product_id'] ?>"
                                data-nuclide-id="<?= (int) $p['nuclide_id'] ?>"
                                data-requires-location="<?= $p['delivery_method'] === 'direct_delivery' ? 1 : 0 ?>"
                                data-delivery-label="<?= e(delivery_method_label($p['delivery_method'])) ?>"
                                <?= $old['product_id'] === (string) $p['product_id'] ? 'selected' : '' ?>
                            ><?= e($p['name']) ?> &mdash; <?= e(delivery_method_label($p['delivery_method'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-hint" id="delivery-method-hint" <?= $selectedDeliveryLabel !== '' ? '' : 'hidden' ?>><?= $selectedDeliveryLabel !== '' ? 'Fulfillment: ' . e($selectedDeliveryLabel) : '' ?></span>
                    <?= field_error($fieldErrors, 'product_id') ?>
                </div>
            </div>

            <?php // The location field is this section's only content now
                  // (delivery method is a fixed property of the product, not
                  // chosen here), so the hidden toggle lives on the whole
                  // section -- otherwise the "Delivery" heading would render
                  // over nothing whenever the field is hidden. ?>
            <div class="form-section" id="location-field" <?= $locationVisible ? '' : 'hidden' ?>>
                <div class="form-section__heading">
                    <span class="form-section__icon" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="3" width="15" height="13"></rect>
                            <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                            <circle cx="5.5" cy="18.5" r="2.5"></circle>
                            <circle cx="18.5" cy="18.5" r="2.5"></circle>
                        </svg>
                    </span>
                    <span class="form-section__title">Delivery</span>
                </div>

                <div class="<?= field_class($fieldErrors, 'location_id', 'field mb-0') ?>">
                    <label for="location_id">Delivery location <span class="required-mark">*</span></label>
                    <select id="location_id" name="location_id" <?= $locationVisible ? 'required' : 'disabled' ?>>
                        <option value="">Select a location&hellip;</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= (int) $loc['location_id'] ?>" <?= $old['location_id'] === (string) $loc['location_id'] ? 'selected' : '' ?>><?= e($loc['name']) ?><?= $loc['room'] ? ' (' . e($loc['room']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$locations): ?>
                        <span class="field-hint">No delivery locations yet &mdash; <a href="/customer/lab_delivery_locations.php">add one</a>.</span>
                    <?php endif; ?>
                    <?= field_error($fieldErrors, 'location_id') ?>
                </div>
            </div>

            <div class="form-section form-section--full">
                <div class="form-section__heading">
                    <span class="form-section__icon" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
                            <rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>
                        </svg>
                    </span>
                    <span class="form-section__title">Order details</span>
                </div>

                <div class="field-row">
                    <div class="<?= field_class($fieldErrors, 'activity_mci', 'field mb-0') ?>">
                        <label for="activity_mci">Activity (mCi) <span class="required-mark">*</span></label>
                        <input type="number" step="0.01" min="0" max="99999.999" id="activity_mci" name="activity_mci" value="<?= e($old['activity_mci']) ?>" required>
                        <?= field_error($fieldErrors, 'activity_mci') ?>
                    </div>
                    <div class="<?= field_class($fieldErrors, 'requested_date', 'field mb-0') ?>">
                        <label for="requested_date">Requested date <span class="required-mark">*</span></label>
                        <input type="date" id="requested_date" name="requested_date" value="<?= e($old['requested_date']) ?>" required>
                        <?= field_error($fieldErrors, 'requested_date') ?>
                    </div>
                    <div class="<?= field_class($fieldErrors, 'requested_time', 'field mb-0') ?>">
                        <label for="requested_time">Requested time <span class="required-mark">*</span></label>
                        <input type="text" id="requested_time" name="requested_time" placeholder="HH:MM" maxlength="5" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" value="<?= e($old['requested_time']) ?>" required>
                        <span class="field-hint">24-hour time, e.g. 14:30.</span>
                        <?= field_error($fieldErrors, 'requested_time') ?>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section__heading">
                    <span class="form-section__icon" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                    </span>
                    <span class="form-section__title">Notes</span>
                </div>

                <div class="<?= field_class($fieldErrors, 'notes', 'field mb-0') ?>">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" maxlength="500"><?= e($old['notes']) ?></textarea>
                    <span class="field-hint">Do not include PHI (patient names, MRNs, or other protected health information) in this field.</span>
                    <span class="field-hint char-count" id="notes-char-count"><?= mb_strlen($old['notes']) ?>/500</span>
                    <?= field_error($fieldErrors, 'notes') ?>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section__heading">
                    <span class="form-section__icon" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </span>
                    <span class="form-section__title">Recipient</span>
                </div>

                <div class="<?= field_class($fieldErrors, 'product_user_id', 'field mb-0') ?>">
                    <label for="product_user_id">Product user</label>
                    <select id="product_user_id" name="product_user_id">
                        <option value="">I'm the recipient&hellip;</option>
                        <?php foreach ($productUsers as $pu): ?>
                            <option value="<?= (int) $pu['product_user_id'] ?>" <?= $old['product_user_id'] === (string) $pu['product_user_id'] ? 'selected' : '' ?>><?= e($pu['first_name'] . ' ' . $pu['last_name']) ?><?= $pu['email'] ? ' (' . e($pu['email']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error($fieldErrors, 'product_user_id') ?>
                </div>
            </div>

            <!-- No submit button here: new_order_modal.php renders it in
                 its pinned .modal__footer, associated via form="order-form".
                 The modal is the only context that renders this partial. -->
        </form>
    </div>
<?php endif; ?>
<?php if ($labId > 0): ?>
<script nonce="<?= e(csp_nonce()) ?>">
// DOMContentLoaded (app convention -- script.js, layout_customer.php): in
// the modal context the submit button lives in .modal__footer, which is
// rendered AFTER this script in the DOM, so immediate execution would
// find null.
document.addEventListener('DOMContentLoaded', function () {
    var nuclideSelect = document.getElementById('nuclide_id');
    var productSelect = document.getElementById('product_id');
    var locationField = document.getElementById('location-field');
    var locationSelect = document.getElementById('location_id');
    var deliveryHint = document.getElementById('delivery-method-hint');
    if (!nuclideSelect || !productSelect) return;

    // Cascade (nuclide -> product filter, conditional location,
    // fulfillment hint) lives in script.js (petordersInitOrderCascade),
    // shared with the pending-order edit form on order_detail.php. It
    // attaches the selects' direct change listeners and runs the
    // initial filter immediately.
    var cascade = window.petordersInitOrderCascade({
        nuclideSelect: nuclideSelect,
        productSelect: productSelect,
        locationField: locationField,
        locationSelect: locationSelect,
        deliveryHint: deliveryHint
    });

    // ---- Live character counter for Notes: plain input listener
    // writing a span's textContent, no library. Called once immediately
    // (not just on 'input') so a bfcache-restored value on browser
    // back/forward reflects the real current length, not just the
    // server-rendered pristine count. ----
    var notesField = document.getElementById('notes');
    var notesCounter = document.getElementById('notes-char-count');
    if (notesField && notesCounter) {
        var updateNotesCounter = function () {
            notesCounter.textContent = notesField.value.length + '/' + notesField.maxLength;
        };
        notesField.addEventListener('input', updateNotesCounter);
        updateNotesCounter();
    }

    // ---- Submit is always clickable (the app-wide convention -- no
    // disable-until-valid gating anywhere). The form is novalidate and
    // the server is authoritative: invalid input comes back as 422
    // field errors, rendered by the shared pipeline. ----
    var form = document.getElementById('order-form');
    var submitBtn = document.getElementById('order-submit');
    var overlay = document.getElementById('new-order-modal');

    // ---- Submission rides the app-standard AJAX pipeline: the form
    // tag's data-ajax-submit hands fetch, X-Requested-With, loading
    // state, the double-submit guard, 422 field-error injection (shared
    // renderFieldErrors + the banner's data-error-banner-for hookup),
    // redirect-following, and error toasts to script.js's
    // initAjaxForms. Only the unconditional "Place this order?" confirm
    // stays inline: it cannot ride the data-confirm attribute because
    // initConfirmForms opens its dialog through petordersOpenModal,
    // which would first try to CLOSE the already-open New Order modal
    // (single-active-modal rule) and trip the dirty-discard veto below;
    // petordersConfirm() stacks over an open modal instead. This
    // listener registers before script.js's (inline scripts register
    // DOMContentLoaded handlers during parse; defer runs after), so the
    // unconfirmed submit is preventDefault-ed before initAjaxForms sees
    // it -- the same dataset.confirmed contract initConfirmForms uses. ----
    form.addEventListener('submit', function (e) {
        if (form.dataset.confirmed === 'true') {
            form.dataset.confirmed = 'false'; // re-arm for the next submit
            return; // confirmed pass-through: initAjaxForms takes it from here
        }
        e.preventDefault();
        window.petordersConfirm({
            title: 'Place this order?',
            message: 'Your order will be submitted for processing.',
            verb: 'Place order'
        }).then(function (confirmed) {
            if (!confirmed) return;
            form.dataset.confirmed = 'true';
            if (typeof form.requestSubmit === 'function') {
                // Pass the footer button (associated via form="order-form",
                // so it lives OUTSIDE the <form> element) as the submitter:
                // initAjaxForms reads e.submitter for loading state, and
                // its in-form querySelector fallback can't find this one.
                form.requestSubmit(submitBtn);
            } else {
                form.submit();
            }
        });
    });

    function snapshotForm(f) {
        var values = {};
        Array.prototype.forEach.call(f.elements, function (el) {
            if (!el.name) return;
            values[el.name] = el.value;
        });
        return values;
    }

    // Confirmed discard resets to pristine rather than preserving stale
    // values for the next open: form.reset() restores the markup
    // defaults (all empty), then the cascade re-derives its state
    // (product select disabled, location hidden, hint cleared)
    // exactly as on first load.
    function resetFormToPristine() {
        form.reset();
        clearFieldErrors(form); // script.js's shared clear, banner included
        cascade.refresh();
    }

    // ---- Dirty tracking + discard-confirm on close: shared wiring
    // (window.petordersWireModalDirtyTracking, script.js), covering all
    // four close paths (Esc, backdrop, X, footer Cancel) via the
    // overlay's petordersBeforeClose hook. The pristine baseline is
    // captured once here, after the initial cascade run above, so it
    // reflects the form's real settled load state; the modal only ever
    // renders pristine-empty (submission is AJAX, no server re-render),
    // so unlike the CRUD Edit modals there is no reopen/repopulate
    // recapture. form.elements includes disabled controls (the
    // pre-cascade product select, the hidden location select), so their
    // values participate too -- their pristine value is '' either way. ----
    var tracking = window.petordersWireModalDirtyTracking(
        overlay,
        form,
        snapshotForm,
        { title: 'Discard this order?', message: 'Your entries will be discarded and the order will not be placed.' },
        resetFormToPristine
    );
    tracking.markPristine();

    // ---- Native reload/navigate-away warning: only while the order
    // modal is actually open AND holds unsaved changes. Skipped while a
    // submit is in flight (initAjaxForms sets form.dataset.submitting)
    // so our own navigations -- the success redirect, a session-expiry
    // bounce -- don't trigger the browser's generic prompt, which fires
    // on programmatic location changes too. ----
    window.addEventListener('beforeunload', function (e) {
        if (form.dataset.submitting === 'true' || overlay.hidden || !tracking.isDirty()) return;
        e.preventDefault();
        e.returnValue = '';
    });
});
</script>
<?php endif; ?>
