<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('customer');

$pdo = get_db();
$myUserId = (int) $_SESSION['user_id'];

// Pre-setting $labId here means layout_customer.php's guarded lookup
// never re-queries -- same convention as orders.php / order_detail.php.
$labId = current_customer_lab_id($pdo, $myUserId);

// One-shot arrival-toast flags set by the PRG redirects below. Captured
// into locals then immediately stripped from $_GET so this render's own
// pagination/search links (built via build_query()) never carry a
// stale flag forward. That alone doesn't stop a manual reload of the
// arrived-at URL from re-sending the flag to the server, though -- the
// client-side petordersCleanArrivalFlags() call near the bottom of the page
// handles that half, same fix as order_detail.php's identical bug.
['created' => $justCreated, 'updated' => $justUpdated, 'activated' => $justActivated, 'deactivated' => $justDeactivated]
    = consume_arrival_flags(['created', 'updated', 'activated', 'deactivated']);

$q = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$pageSize = in_array((int) ($_GET['page_size'] ?? 0), PAGE_SIZE_OPTIONS, true)
    ? (int) $_GET['page_size'] : DEFAULT_PAGE_SIZE;

// Canonicalize so every link built via build_query() below (pagination,
// and every POST form's action, which embeds the current view so a
// create/edit/toggle redirects back to where the person was) carries the
// real applied values, never raw/invalid ones -- same convention as
// orders.php.
canonicalize_get([
    'status' => $status,
    'page' => $page,
    'page_size' => $pageSize,
]);

$addErrors = [];
$addOld = ['name' => '', 'room' => ''];
$editErrors = [];
$editOld = ['location_id' => '', 'name' => '', 'room' => ''];

function validate_location_fields(string $name, string $room): array
{
    $errors = [];

    if ($name === '') {
        $errors['name'] = 'Name is required.';
    } elseif (mb_strlen($name) > 100) {
        $errors['name'] = 'Name must be 100 characters or fewer.';
    }

    if (mb_strlen($room) > 20) {
        $errors['room'] = 'Room must be 20 characters or fewer.';
    }

    return $errors;
}

if ($labId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $addOld['name'] = trim($_POST['name'] ?? '');
        $addOld['room'] = trim($_POST['room'] ?? '');

        $addErrors = validate_location_fields($addOld['name'], $addOld['room']);

        // AJAX submits (script.js initAjaxForms) get the errors as JSON
        // and render them in the still-open modal; a plain POST falls
        // through to the full-page re-render + reopen below -- kept as
        // the no-JS fallback, not dead code.
        if ($addErrors && request_wants_json()) {
            json_response(['ok' => false, 'errors' => $addErrors], 422);
        }

        if (!$addErrors) {
            $pdo->prepare('INSERT INTO lab_delivery_locations (lab_id, name, room, active) VALUES (?, ?, ?, 1)')
                ->execute([$labId, $addOld['name'], $addOld['room'] !== '' ? $addOld['room'] : null]);
            // PRG: redirect after a successful save so a reload doesn't hit
            // the browser's resubmit-form prompt (confirming it would
            // silently re-create the location) -- same pattern as
            // order_detail.php's save_notes/save_details/cancel_order.
            // build_query() carries the current search/page state
            // forward so the person lands back where they were. The AJAX
            // path navigates to the same destination itself, so the
            // arrival-flag toast works identically either way.
            $dest = '/customer/lab_delivery_locations.php' . build_query(['created' => '1']);
            if (request_wants_json()) {
                json_response(['ok' => true, 'redirect' => $dest]);
            }
            redirect($dest);
        }
    } elseif ($action === 'update') {
        $editOld['location_id'] = trim($_POST['location_id'] ?? '');
        $editOld['name'] = trim($_POST['name'] ?? '');
        $editOld['room'] = trim($_POST['room'] ?? '');

        $locationId = ctype_digit($editOld['location_id']) ? (int) $editOld['location_id'] : 0;

        $editErrors = validate_location_fields($editOld['name'], $editOld['room']);

        if ($locationId <= 0) {
            $editErrors['location_id'] = 'Unknown delivery location.';
        } else {
            $stmt = $pdo->prepare('SELECT location_id FROM lab_delivery_locations WHERE location_id = ? AND lab_id = ?');
            $stmt->execute([$locationId, $labId]);
            if (!$stmt->fetchColumn()) {
                $editErrors['location_id'] = 'Unknown delivery location.';
            }
        }

        // Same AJAX/no-JS split as the create branch above. The
        // location_id error key has no field slot in the modal, so on
        // the AJAX path it surfaces as the summary banner alone
        // (renderFieldErrors' unknown-key behavior).
        if ($editErrors && request_wants_json()) {
            json_response(['ok' => false, 'errors' => $editErrors], 422);
        }

        if (!$editErrors) {
            $pdo->prepare('UPDATE lab_delivery_locations SET name = ?, room = ? WHERE location_id = ? AND lab_id = ?')
                ->execute([$editOld['name'], $editOld['room'] !== '' ? $editOld['room'] : null, $locationId, $labId]);
            $dest = '/customer/lab_delivery_locations.php' . build_query(['updated' => '1']);
            if (request_wants_json()) {
                json_response(['ok' => true, 'redirect' => $dest]);
            }
            redirect($dest);
        }
    } elseif ($action === 'toggle_active') {
        $locationId = ctype_digit((string) ($_POST['location_id'] ?? '')) ? (int) $_POST['location_id'] : 0;
        if ($locationId > 0) {
            $stmt = $pdo->prepare('SELECT active FROM lab_delivery_locations WHERE location_id = ? AND lab_id = ?');
            $stmt->execute([$locationId, $labId]);
            $currentActive = $stmt->fetchColumn();

            if ($currentActive !== false) {
                $newActive = $currentActive ? 0 : 1;
                $pdo->prepare('UPDATE lab_delivery_locations SET active = ? WHERE location_id = ? AND lab_id = ?')
                    ->execute([$newActive, $locationId, $labId]);
                redirect('/customer/lab_delivery_locations.php' . build_query([$newActive ? 'activated' : 'deactivated' => '1']));
            }
        }
    }
}

// Named $deliveryLocations (not $locations): layout_customer.php's
// New-Order-modal backing data now lives namespaced under
// $petordersLayout['locations'] (guarded on isset($petordersLayout['nuclides'])),
// so this name is no longer strictly required to avoid a collision --
// kept anyway since $deliveryLocations is the established name here and
// renaming it back is an unrelated cosmetic change. Historically, before
// that namespacing, a same-named $locations here would have been
// silently overwritten by get_new_order_form_data()'s active-only,
// no-active-column result after this point in the request -- this exact
// collision was the root cause of every earlier bug on this page.
$deliveryLocations = [];
$totalCount = 0;
$totalPages = 1;
$offset = 0;
$rangeStart = 0;
$rangeEnd = 0;

if ($labId > 0) {
    $where = ['lab_id = ?'];
    $params = [$labId];

    if ($q !== '') {
        // Escape LIKE wildcards in the search term itself, same convention
        // as orders.php/accounts.php/customers.php.
        $where[] = "name LIKE ? ESCAPE '\\\\'";
        $params[] = like_contains($q);
    }

    $whereSql = where_clause($where);

    // Built without the status condition -- reused for the tab counts
    // (each tab's count reflects the current search scope, not global
    // counts) and then extended with a status condition below for the
    // actual list -- same pattern as admin/nuclides.php.
    $countsStmt = $pdo->prepare(
        "SELECT active, COUNT(*) AS c
         FROM lab_delivery_locations
         $whereSql
         GROUP BY active"
    );
    $countsStmt->execute($params);
    $statusCounts = ['active' => 0, 'inactive' => 0];
    foreach ($countsStmt->fetchAll() as $row) {
        $statusCounts[$row['active'] ? 'active' : 'inactive'] = (int) $row['c'];
    }
    $allCount = $statusCounts['active'] + $statusCounts['inactive'];
    $totalCount = $status !== '' ? $statusCounts[$status] : $allCount;

    $statusTabs = [
        ['value' => '',         'label' => 'All',      'count' => $allCount],
        ['value' => 'active',   'label' => 'Active',   'count' => $statusCounts['active']],
        ['value' => 'inactive', 'label' => 'Inactive', 'count' => $statusCounts['inactive']],
    ];

    $listWhere = $where;
    if ($status === 'active') {
        $listWhere[] = 'active = 1';
    } elseif ($status === 'inactive') {
        $listWhere[] = 'active = 0';
    }
    $listWhereSql = where_clause($listWhere);

    $pagination = paginate($totalCount, $page, $pageSize);
    $page = $pagination['page'];
    $totalPages = $pagination['totalPages'];
    $offset = $pagination['offset'];
    $rangeStart = $pagination['rangeStart'];
    $rangeEnd = $pagination['rangeEnd'];
    // Keep $_GET in sync with the DB-verified page so build_query() (and
    // $formAction below, which embeds it into every POST form) never
    // echoes back an out-of-range page number.
    canonicalize_get(['page' => $page]);

    // LIMIT/OFFSET are interpolated directly rather than bound: both are
    // fully server-computed ints at this point (page size is clamped
    // against a fixed option set, offset is derived from a clamped,
    // ctype_digit-checked page number), same convention as orders.php.
    $listStmt = $pdo->prepare(
        "SELECT location_id, name, room, active FROM lab_delivery_locations
         $listWhereSql
         ORDER BY name
         LIMIT $offset, $pageSize"
    );
    $listStmt->execute($params);
    $deliveryLocations = $listStmt->fetchAll();
}

$formAction = form_action('/customer/lab_delivery_locations.php');
$hasFilters = $q !== '' || $status !== '';

$pageTitle = 'Delivery Locations';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../../src/partials/head.php'; ?>
</head>
<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../../src/partials/layout_customer.php'; ?>
        <main class="app-main">
            <div class="page-header">
                <h1>Delivery Locations</h1>
                <?php if ($labId > 0): ?>
                    <div class="page-header__actions">
                        <button type="button" class="btn btn--primary" id="add-location-btn">+ Add Location</button>
                    </div>
                <?php endif; ?>
            </div>

            <?php // Query flags carry the toast across the PRG redirect --
                  // same convention as order_detail.php's ?placed=1 /
                  // ?canceled=1 / ?updated=1 / ?notes_updated=1. ?>
            <?php if ($labId > 0 && $justCreated): ?>
                <?= toast_flash('success', 'Delivery location added.') ?>
            <?php elseif ($labId > 0 && $justUpdated): ?>
                <?= toast_flash('success', 'Delivery location updated.') ?>
            <?php elseif ($labId > 0 && $justActivated): ?>
                <?= toast_flash('success', 'Delivery location activated.') ?>
            <?php elseif ($labId > 0 && $justDeactivated): ?>
                <?= toast_flash('success', 'Delivery location deactivated.') ?>
            <?php endif; ?>

            <?php if ($labId <= 0): ?>
                <div class="card">
                    <p class="muted">No lab assigned to your account yet &mdash; contact an administrator.</p>
                </div>
            <?php else: ?>
                <nav class="status-tabs" aria-label="Filter by status">
                    <?php foreach ($statusTabs as $tab): ?>
                        <a href="<?= e($_SERVER['PHP_SELF'] . build_query(['status' => $tab['value'], 'page' => 1])) ?>" class="status-tabs__link <?= $status === $tab['value'] ? 'is-active' : '' ?>">
                            <?= e($tab['label']) ?> <span class="status-tabs__count"><?= $tab['count'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="table-card">
                    <div class="table-card-header">
                        <?php // Explicit Search-button submit, never
                              // live-as-you-type -- same idiom as
                              // orders.php's filter form. ?>
                        <form method="get" class="table-card-controls">
                            <input type="hidden" name="status" value="<?= e($status) ?>">
                            <?php // Preserves the current page size across a
                                  // search-form submit -- that form has no
                                  // page_size field of its own, so without
                                  // this hidden input a search would
                                  // silently reset it to the default. ?>
                            <?php if ($pageSize !== DEFAULT_PAGE_SIZE): ?>
                                <input type="hidden" name="page_size" value="<?= e((string) $pageSize) ?>">
                            <?php endif; ?>
                            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search by name&hellip;">
                            <button type="submit" class="btn btn--secondary btn--sm">Search</button>
                        </form>
                    </div>

                    <?php if (!$deliveryLocations): ?>
                        <div class="empty-state">
                            <div class="empty-state__icon">
                                <?php if ($hasFilters): ?>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="10" cy="10" r="7"></circle>
                                        <line x1="21" y1="21" x2="15" y2="15"></line>
                                    </svg>
                                <?php else: ?>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                        <circle cx="12" cy="10" r="3"></circle>
                                    </svg>
                                <?php endif; ?>
                            </div>
                            <div class="empty-state__title"><?= $hasFilters ? 'No delivery locations match this search' : 'No delivery locations yet' ?></div>
                            <p class="empty-state__hint"><?= $hasFilters ? 'Try a different search.' : 'Add a location so orders can be delivered directly to your lab.' ?></p>
                            <div class="empty-state__action">
                                <?php if ($hasFilters): ?>
                                    <a href="/customer/lab_delivery_locations.php" class="btn btn--secondary btn--sm">Clear filters</a>
                                <?php else: ?>
                                    <button type="button" class="btn btn--primary btn--sm" id="add-location-btn-empty">+ Add Location</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-scroll">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Room</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($deliveryLocations as $loc): ?>
                                        <tr>
                                            <td><?= e($loc['name']) ?></td>
                                            <td class="muted"><?= $loc['room'] !== null && $loc['room'] !== '' ? e($loc['room']) : '&mdash;' ?></td>
                                            <td><span class="badge badge--<?= $loc['active'] ? 'active' : 'inactive' ?>"><?= $loc['active'] ? 'Active' : 'Inactive' ?></span></td>
                                            <td>
                                                <div class="flex gap-2 justify-end">
                                                    <button type="button" class="table-action"
                                                            data-edit-location
                                                            data-location-id="<?= (int) $loc['location_id'] ?>"
                                                            data-location-name="<?= e($loc['name']) ?>"
                                                            data-location-room="<?= e($loc['room'] ?? '') ?>">Edit</button>

                                                    <?php if ($loc['active']): ?>
                                                        <form method="post" action="<?= e($formAction) ?>"
                                                              data-confirm="Deactivate &ldquo;<?= e($loc['name']) ?>&rdquo;? It will no longer be selectable on new orders."
                                                              data-confirm-title="Deactivate delivery location"
                                                              data-confirm-verb="Deactivate"
                                                              data-confirm-danger>
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="toggle_active">
                                                            <input type="hidden" name="location_id" value="<?= (int) $loc['location_id'] ?>">
                                                            <button type="submit" class="btn btn--danger btn--sm">Deactivate</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="post" action="<?= e($formAction) ?>"
                                                              data-confirm="Activate &ldquo;<?= e($loc['name']) ?>&rdquo;?"
                                                              data-confirm-title="Activate delivery location"
                                                              data-confirm-verb="Activate">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="toggle_active">
                                                            <input type="hidden" name="location_id" value="<?= (int) $loc['location_id'] ?>">
                                                            <button type="submit" class="btn btn--secondary btn--sm">Activate</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php
                        $tablePagination = [
                            'idPrefix' => 'location-',
                            'itemLabel' => 'Locations',
                            'hiddenFields' => ['q' => $q, 'status' => $status],
                            'page' => $page,
                            'totalPages' => $totalPages,
                            'pageSize' => $pageSize,
                            'rangeStart' => $rangeStart,
                            'rangeEnd' => $rangeEnd,
                            'totalCount' => $totalCount,
                        ];
                        include __DIR__ . '/../../src/partials/table_pagination.php';
                        ?>
                    <?php endif; ?>
                </div>

                <!-- Add modal: header (title + X close) / body / split
                     footer mirrors the New Order modal's visual language
                     (src/partials/new_order_modal.php), just sized for a
                     two-field form instead of copying its near-fullscreen
                     .modal--order treatment. -->
                <div class="modal-overlay" id="add-location-modal" hidden>
                    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="add-location-modal-title">
                        <div class="modal__header">
                            <h2 class="modal__title" id="add-location-modal-title">Add delivery location</h2>
                            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="18" y1="6" x2="6" y2="18"></line>
                                    <line x1="6" y1="6" x2="18" y2="18"></line>
                                </svg>
                            </button>
                        </div>
                        <form method="post" action="<?= e($formAction) ?>" id="add-location-form" novalidate data-ajax-submit>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create">
                            <div class="modal__body">
                                <?php // Always in the DOM (hidden when clean), same as the
                                      // New Order modal's banner: the AJAX submit unhides it
                                      // alongside the injected field errors; a no-JS POST
                                      // re-render shows it via the $addErrors check. ?>
                                <div class="alert alert--error" data-error-banner-for="add-location-form" <?= $addErrors ? '' : 'hidden' ?>>Please correct the errors below and resubmit.</div>
                                <div class="<?= field_class($addErrors, 'name') ?>">
                                    <label for="add-location-name">Name <span class="required-mark">*</span></label>
                                    <input type="text" id="add-location-name" name="name" maxlength="100" value="<?= e($addOld['name']) ?>" required data-modal-focus>
                                    <?= field_error($addErrors, 'name') ?>
                                </div>
                                <div class="<?= field_class($addErrors, 'room') ?>">
                                    <label for="add-location-room">Room</label>
                                    <input type="text" id="add-location-room" name="room" maxlength="20" value="<?= e($addOld['room']) ?>">
                                    <?= field_error($addErrors, 'room') ?>
                                </div>
                            </div>
                            <div class="modal__footer modal__footer--split">
                                <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                                <button type="submit" class="btn btn--primary">Add Location</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Edit modal: single shared modal for every row, populated
                     via JS from the clicked row's data-location-* attributes
                     (or, on a failed submit, from $editOld server-side). -->
                <div class="modal-overlay" id="edit-location-modal" hidden>
                    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="edit-location-modal-title">
                        <div class="modal__header">
                            <h2 class="modal__title" id="edit-location-modal-title">Edit delivery location</h2>
                            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="18" y1="6" x2="6" y2="18"></line>
                                    <line x1="6" y1="6" x2="18" y2="18"></line>
                                </svg>
                            </button>
                        </div>
                        <form method="post" action="<?= e($formAction) ?>" id="edit-location-form" novalidate data-ajax-submit>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="location_id" id="edit-location-id" value="<?= e($editOld['location_id']) ?>">
                            <div class="modal__body">
                                <div class="alert alert--error" data-error-banner-for="edit-location-form" <?= $editErrors ? '' : 'hidden' ?>>Please correct the errors below and resubmit.</div>
                                <div class="<?= field_class($editErrors, 'name') ?>">
                                    <label for="edit-location-name">Name <span class="required-mark">*</span></label>
                                    <input type="text" id="edit-location-name" name="name" maxlength="100" value="<?= e($editOld['name']) ?>" required data-modal-focus>
                                    <?= field_error($editErrors, 'name') ?>
                                </div>
                                <div class="<?= field_class($editErrors, 'room') ?>">
                                    <label for="edit-location-room">Room</label>
                                    <input type="text" id="edit-location-room" name="room" maxlength="20" value="<?= e($editOld['room']) ?>">
                                    <?= field_error($editErrors, 'room') ?>
                                </div>
                            </div>
                            <div class="modal__footer modal__footer--split">
                                <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                                <button type="submit" class="btn btn--primary">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
<?php if ($labId > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  function snapshotForm(form) {
    var values = {};
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name) return;
      values[el.name] = el.value;
    });
    return values;
  }

  // Dirty-tracking + discard-confirm-on-close wiring is shared:
  // window.petordersWireModalDirtyTracking (script.js). snapshotForm()
  // above stays page-local -- what counts as a field value varies per
  // page.

  // ---- Add modal ----
  var addModal = document.getElementById('add-location-modal');
  var addForm = document.getElementById('add-location-form');
  // Discard resets to blank: unlike the Edit modal, Add's fields are never
  // JS-populated -- their rendered value="" already IS the correct
  // pristine state (blank on a fresh load, the attempted values on a
  // validation-error reopen), so form.reset() is safe here.
  var addTracking = window.petordersWireModalDirtyTracking(
    addModal,
    addForm,
    snapshotForm,
    { title: 'Discard this location?', message: 'Your entries will be discarded.' },
    function () { addForm.reset(); }
  );

  ['add-location-btn', 'add-location-btn-empty'].forEach(function (id) {
    var btn = document.getElementById(id);
    if (btn) {
      btn.addEventListener('click', function (e) {
        window.petordersOpenModal(addModal, { opener: e.currentTarget });
        addTracking.markPristine();
      });
    }
  });

  <?php if ($addErrors): ?>
  window.petordersOpenModal(addModal);
  addTracking.markPristine();
  <?php endif; ?>

  // ---- Edit modal: population + dirty-tracking ----
  var editModal = document.getElementById('edit-location-modal');
  var editForm = document.getElementById('edit-location-form');
  var editIdField = document.getElementById('edit-location-id');
  var editNameField = document.getElementById('edit-location-name');
  var editRoomField = document.getElementById('edit-location-room');
  // No onDiscard reset here: Edit's fields are JS-populated per row, so
  // resetting to the rendered value="" (blank, or a previous error's
  // values) would show stale data instead of the row actually being
  // edited. The next real open always repopulates from fresh data anyway
  // (a row click or a validation-error reopen), so nothing needs undoing.
  var editTracking = window.petordersWireModalDirtyTracking(editModal, editForm, snapshotForm, {
    title: 'Discard these changes?',
    message: 'Your edits to this delivery location will be discarded.'
  });

  function openEditModal(values, opener) {
    editIdField.value = values.location_id;
    editNameField.value = values.name;
    editRoomField.value = values.room;
    window.petordersOpenModal(editModal, { opener: opener || document.activeElement });
    editTracking.markPristine();
  }

  document.querySelectorAll('[data-edit-location]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      openEditModal({
        location_id: btn.dataset.locationId,
        name: btn.dataset.locationName,
        room: btn.dataset.locationRoom
      }, e.currentTarget);
    });
  });

  <?php if ($editErrors): ?>
  openEditModal({
    location_id: <?= json_encode($editOld['location_id'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    name: <?= json_encode($editOld['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    room: <?= json_encode($editOld['room'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
  }, null);
  <?php endif; ?>

  // Strip one-time arrival-toast query flags (created/updated/
  // activated/deactivated) from the URL bar once their toast has been
  // queued above, so a reload or back-navigation doesn't re-show a toast
  // for an action that already happened. Same fix as order_detail.php's
  // identical bug -- PRG already stops the resubmit-form prompt; this
  // separately stops a stale success toast from replaying on a plain GET
  // reload.
  window.petordersCleanArrivalFlags(['created', 'updated', 'activated', 'deactivated']);
});
</script>
<?php endif; ?>
</html>
