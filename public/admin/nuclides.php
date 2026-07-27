<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('admin'); // catalog management is admin-only; staff only process orders

$pdo = get_db();

// One-shot arrival-toast flags set by the PRG redirects below. Captured
// into locals then immediately stripped from $_GET so this render's own
// pagination/tab links (built via build_query()) never carry a stale
// flag forward; the client-side petordersCleanArrivalFlags() near the bottom
// handles the reload half -- same convention as lab_product_users.php.
['created' => $justCreated, 'updated' => $justUpdated, 'activated' => $justActivated, 'deactivated' => $justDeactivated]
    = consume_arrival_flags(['created', 'updated', 'activated', 'deactivated']);

$q = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$pageSize = in_array((int) ($_GET['page_size'] ?? 0), PAGE_SIZE_OPTIONS, true)
    ? (int) $_GET['page_size'] : DEFAULT_PAGE_SIZE;

// Canonicalize so every link built via build_query() below (tabs,
// pagination, and every POST form's action, which embeds the current
// view so create/edit/toggle land back where the admin was) carries the
// real applied values -- same convention as accounts.php.
canonicalize_get([
    'status' => $status,
    'page' => $page,
    'page_size' => $pageSize,
]);

/**
 * Shared by create and edit. App-level pre-check for the DB's own
 * uq_nuclides_name unique key, so a collision surfaces as a normal field
 * error instead of a fatal PDO exception. $excludeId is 0 on create.
 */
function validate_nuclide_name(PDO $pdo, string $name, int $excludeId): array
{
    $errors = [];

    if ($name === '') {
        $errors['name'] = 'Name is required.';
    } elseif (mb_strlen($name) > 50) {
        $errors['name'] = 'Name must be 50 characters or fewer.';
    } else {
        $stmt = $pdo->prepare('SELECT 1 FROM nuclides WHERE name = ? AND nuclide_id != ?');
        $stmt->execute([$name, $excludeId]);
        if ($stmt->fetchColumn()) {
            $errors['name'] = 'A nuclide with this name already exists.';
        }
    }

    return $errors;
}

$addErrors = [];
$addOld = ['name' => '', 'active' => '1'];
$editErrors = [];
$editOld = ['nuclide_id' => '', 'name' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $addOld['name'] = trim($_POST['name'] ?? '');
        $addOld['active'] = trim($_POST['active'] ?? '');

        $addErrors = validate_nuclide_name($pdo, $addOld['name'], 0);

        if ($addOld['active'] !== '0' && $addOld['active'] !== '1') {
            $addErrors['active'] = 'Select a status.';
        }

        if ($addErrors && request_wants_json()) {
            json_response(['ok' => false, 'errors' => $addErrors], 422);
        }

        if (!$addErrors) {
            $pdo->prepare('INSERT INTO nuclides (name, active) VALUES (?, ?)')
                ->execute([$addOld['name'], (int) $addOld['active']]);
            $dest = '/admin/nuclides.php' . build_query(['created' => '1']);
            if (request_wants_json()) {
                json_response(['ok' => true, 'redirect' => $dest]);
            }
            redirect($dest);
        }
    } elseif ($action === 'update') {
        // Free rename, deliberately allowed even once orders exist: a
        // rename is a label correction, and historical orders displaying
        // the corrected name is desired (per the project lead) -- unlike
        // products.php's nuclide/fulfillment lock.
        $editOld['nuclide_id'] = trim($_POST['nuclide_id'] ?? '');
        $editOld['name'] = trim($_POST['name'] ?? '');

        $nuclideId = ctype_digit($editOld['nuclide_id']) ? (int) $editOld['nuclide_id'] : 0;

        if ($nuclideId <= 0) {
            $editErrors['nuclide_id'] = 'Unknown nuclide.';
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM nuclides WHERE nuclide_id = ?');
            $stmt->execute([$nuclideId]);
            if (!$stmt->fetchColumn()) {
                $editErrors['nuclide_id'] = 'Unknown nuclide.';
            }
        }

        $editErrors += validate_nuclide_name($pdo, $editOld['name'], $nuclideId);

        if ($editErrors && request_wants_json()) {
            json_response(['ok' => false, 'errors' => $editErrors], 422);
        }

        if (!$editErrors) {
            $pdo->prepare('UPDATE nuclides SET name = ? WHERE nuclide_id = ?')
                ->execute([$editOld['name'], $nuclideId]);
            $dest = '/admin/nuclides.php' . build_query(['updated' => '1']);
            if (request_wants_json()) {
                json_response(['ok' => true, 'redirect' => $dest]);
            }
            redirect($dest);
        }
    } elseif ($action === 'toggle_active') {
        $nuclideId = ctype_digit((string) ($_POST['nuclide_id'] ?? '')) ? (int) $_POST['nuclide_id'] : 0;
        if ($nuclideId > 0) {
            $stmt = $pdo->prepare('SELECT active FROM nuclides WHERE nuclide_id = ?');
            $stmt->execute([$nuclideId]);
            $currentActive = $stmt->fetchColumn();

            if ($currentActive !== false) {
                // Effective availability is computed (product.active AND
                // nuclide.active), so this single flag flip is the whole
                // operation -- no product rows are ever written here, and
                // reactivating restores the nuclide's active products with
                // zero extra steps.
                $newActive = $currentActive ? 0 : 1;
                $pdo->prepare('UPDATE nuclides SET active = ? WHERE nuclide_id = ?')
                    ->execute([$newActive, $nuclideId]);
                redirect('/admin/nuclides.php' . build_query([$newActive ? 'activated' : 'deactivated' => '1']));
            }
        }
    }
}

$where = [];
$params = [];

if ($q !== '') {
    // Escape LIKE wildcards in the search term itself, same convention
    // as accounts.php / customers.php.
    $where[] = "n.name LIKE ? ESCAPE '\\\\'";
    $params[] = like_contains($q);
}

$whereSql = where_clause($where);

// Built without the status condition -- reused for the tab counts (each
// tab's count reflects the current search scope, not global counts) and
// then extended with a status condition below for the actual list --
// same pattern as accounts.php.
$countsStmt = $pdo->prepare(
    "SELECT n.active, COUNT(*) AS c
     FROM nuclides n
     $whereSql
     GROUP BY n.active"
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
$listParams = $params;
if ($status === 'active') {
    $listWhere[] = 'n.active = 1';
} elseif ($status === 'inactive') {
    $listWhere[] = 'n.active = 0';
}
$listWhereSql = where_clause($listWhere);

$pagination = paginate($totalCount, $page, $pageSize);
$page = $pagination['page'];
$totalPages = $pagination['totalPages'];
$offset = $pagination['offset'];
// Keep $_GET in sync with the clamped page so build_query() (and
// $formAction below) never echoes back an out-of-range page number.
canonicalize_get(['page' => $page]);

// LIMIT/OFFSET are interpolated directly rather than bound: both are
// fully server-computed ints at this point (page size is clamped against
// a fixed option set, offset is derived from a clamped, ctype_digit-checked
// page number), same convention as accounts.php. The product counts feed
// the Products column and the deactivate confirm's blast-radius copy.
$listStmt = $pdo->prepare(
    "SELECT n.nuclide_id, n.name, n.active,
            (SELECT COUNT(*) FROM products p WHERE p.nuclide_id = n.nuclide_id) AS product_count,
            (SELECT COUNT(*) FROM products p WHERE p.nuclide_id = n.nuclide_id AND p.active = 1) AS active_product_count
     FROM nuclides n
     $listWhereSql
     ORDER BY n.name
     LIMIT $offset, $pageSize"
);
$listStmt->execute($listParams);
$nuclidesList = $listStmt->fetchAll();

$formAction = form_action('/admin/nuclides.php');

$rangeStart = $pagination['rangeStart'];
$rangeEnd = $pagination['rangeEnd'];
$hasFilters = $q !== '' || $status !== '';

$pageTitle = 'Nuclides';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../../src/partials/head.php'; ?>
</head>
<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../../src/partials/layout_admin.php'; ?>
        <main class="app-main">
            <div class="page-header">
                <h1>Nuclides</h1>
                <div class="page-header__actions">
                    <button type="button" class="btn btn--primary" id="add-nuclide-btn">+ Nuclide</button>
                </div>
            </div>

            <?php if ($justCreated): ?>
                <?= toast_flash('success', 'Nuclide added.') ?>
            <?php elseif ($justUpdated): ?>
                <?= toast_flash('success', 'Nuclide updated.') ?>
            <?php elseif ($justActivated): ?>
                <?= toast_flash('success', 'Nuclide activated.') ?>
            <?php elseif ($justDeactivated): ?>
                <?= toast_flash('success', 'Nuclide deactivated.') ?>
            <?php endif; ?>

            <nav class="status-tabs" aria-label="Filter by status">
                <?php foreach ($statusTabs as $tab): ?>
                    <a href="<?= e($_SERVER['PHP_SELF'] . build_query(['status' => $tab['value'], 'page' => 1])) ?>" class="status-tabs__link <?= $status === $tab['value'] ? 'is-active' : '' ?>">
                        <?= e($tab['label']) ?> <span class="status-tabs__count"><?= $tab['count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="table-card">
                <div class="table-card-header">
                    <form method="get" class="table-card-controls">
                        <input type="hidden" name="status" value="<?= e($status) ?>">
                        <?php if ($pageSize !== DEFAULT_PAGE_SIZE): ?>
                            <input type="hidden" name="page_size" value="<?= e((string) $pageSize) ?>">
                        <?php endif; ?>
                        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search by name&hellip;">
                        <button type="submit" class="btn btn--secondary btn--sm">Search</button>
                    </form>
                </div>

                <?php if (!$nuclidesList): ?>
                    <div class="empty-state">
                        <div class="empty-state__icon">
                            <?php if ($hasFilters): ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="10" cy="10" r="7"></circle>
                                    <line x1="21" y1="21" x2="15" y2="15"></line>
                                </svg>
                            <?php else: ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            <?php endif; ?>
                        </div>
                        <div class="empty-state__title"><?= $hasFilters ? 'No nuclides match these filters' : 'No nuclides yet' ?></div>
                        <p class="empty-state__hint"><?= $hasFilters ? 'Try a different search or clear the filters.' : 'Add a nuclide so products can be created under it.' ?></p>
                        <div class="empty-state__action">
                            <?php if ($hasFilters): ?>
                                <a href="/admin/nuclides.php" class="btn btn--secondary btn--sm">Clear filters</a>
                            <?php else: ?>
                                <button type="button" class="btn btn--primary btn--sm" id="add-nuclide-btn-empty">+ Nuclide</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Products</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($nuclidesList as $n): ?>
                                    <?php
                                    $productCount = (int) $n['product_count'];
                                    $activeProductCount = (int) $n['active_product_count'];
                                    // Blast-radius copy for the deactivate confirm:
                                    // deactivating never writes to product rows, but it
                                    // does make every active product under this nuclide
                                    // unavailable -- say so up front.
                                    if ($activeProductCount > 0) {
                                        $deactivateCopy = 'Deactivate &ldquo;' . e($n['name']) . '&rdquo;? Its '
                                            . $activeProductCount . ' active product' . ($activeProductCount === 1 ? '' : 's')
                                            . ' will become unavailable to customers until it is reactivated.';
                                    } else {
                                        $deactivateCopy = 'Deactivate &ldquo;' . e($n['name']) . '&rdquo;? Customers will no longer see it on new orders.';
                                    }
                                    ?>
                                    <tr>
                                        <td><?= e($n['name']) ?></td>
                                        <td class="count-cell">
                                            <?php if ($productCount === 0): ?>
                                                <span class="muted">&mdash;</span>
                                            <?php else: ?>
                                                <?= $activeProductCount ?>
                                                <div class="text-sm muted"><?= $productCount ?> total</div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge badge--<?= $n['active'] ? 'active' : 'inactive' ?>"><?= $n['active'] ? 'Active' : 'Inactive' ?></span></td>
                                        <td>
                                            <div class="flex gap-2 justify-end">
                                                <button type="button" class="table-action"
                                                        data-edit-nuclide
                                                        data-nuclide-id="<?= (int) $n['nuclide_id'] ?>"
                                                        data-nuclide-name="<?= e($n['name']) ?>">Edit</button>

                                                <?php if ($n['active']): ?>
                                                    <form method="post" action="<?= e($formAction) ?>"
                                                          data-confirm="<?= $deactivateCopy ?>"
                                                          data-confirm-title="Deactivate nuclide"
                                                          data-confirm-verb="Deactivate"
                                                          data-confirm-danger>
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="nuclide_id" value="<?= (int) $n['nuclide_id'] ?>">
                                                        <button type="submit" class="btn btn--danger btn--sm">Deactivate</button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="post" action="<?= e($formAction) ?>"
                                                          data-confirm="Activate &ldquo;<?= e($n['name']) ?>&rdquo;? Its active products become orderable again automatically."
                                                          data-confirm-title="Activate nuclide"
                                                          data-confirm-verb="Activate">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="nuclide_id" value="<?= (int) $n['nuclide_id'] ?>">
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
                        'idPrefix' => 'nuclides-',
                        'itemLabel' => 'Nuclides',
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

            <!-- Add modal: same header/body/split-footer shell as
                 lab_product_users.php's Add modal. -->
            <div class="modal-overlay" id="add-nuclide-modal" hidden>
                <div class="modal" role="dialog" aria-modal="true" aria-labelledby="add-nuclide-modal-title">
                    <div class="modal__header">
                        <h2 class="modal__title" id="add-nuclide-modal-title">Add nuclide</h2>
                        <button type="button" class="modal__close" data-modal-close aria-label="Close">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                    <form method="post" action="<?= e($formAction) ?>" id="add-nuclide-form" novalidate data-ajax-submit>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create">
                        <div class="modal__body">
                            <div class="alert alert--error" data-error-banner-for="add-nuclide-form" <?= $addErrors ? '' : 'hidden' ?>>Please correct the errors below and resubmit.</div>
                            <div class="<?= field_class($addErrors, 'name') ?>">
                                <label for="add-nuclide-name">Name <span class="required-mark">*</span></label>
                                <input type="text" id="add-nuclide-name" name="name" maxlength="50" value="<?= e($addOld['name']) ?>" required data-modal-focus>
                                <?= field_error($addErrors, 'name') ?>
                            </div>
                            <?php // No required-mark or required attr on Status: the
                                  // select has no empty option, so it always submits
                                  // a value -- same reasoning as products.php. ?>
                            <div class="<?= field_class($addErrors, 'active') ?>">
                                <label for="add-nuclide-active">Status</label>
                                <select id="add-nuclide-active" name="active">
                                    <option value="1" <?= $addOld['active'] === '1' ? 'selected' : '' ?>>Active</option>
                                    <option value="0" <?= $addOld['active'] === '0' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                                <span class="field-hint">While a nuclide is inactive, none of its products can be selected on new orders.</span>
                                <?= field_error($addErrors, 'active') ?>
                            </div>
                        </div>
                        <div class="modal__footer modal__footer--split">
                            <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                            <button type="submit" class="btn btn--primary">Add Nuclide</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit modal: single shared modal for every row, populated via
                 JS from the clicked row's data-nuclide-* attributes (or, on
                 a failed submit, from $editOld server-side). Rename only --
                 status changes go through the row's toggle action. -->
            <div class="modal-overlay" id="edit-nuclide-modal" hidden>
                <div class="modal" role="dialog" aria-modal="true" aria-labelledby="edit-nuclide-modal-title">
                    <div class="modal__header">
                        <h2 class="modal__title" id="edit-nuclide-modal-title">Edit nuclide</h2>
                        <button type="button" class="modal__close" data-modal-close aria-label="Close">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                    <form method="post" action="<?= e($formAction) ?>" id="edit-nuclide-form" novalidate data-ajax-submit>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="nuclide_id" id="edit-nuclide-id" value="<?= e($editOld['nuclide_id']) ?>">
                        <div class="modal__body">
                            <div class="alert alert--error" data-error-banner-for="edit-nuclide-form" <?= $editErrors ? '' : 'hidden' ?>>Please correct the errors below and resubmit.</div>
                            <div class="<?= field_class($editErrors, 'name') ?>">
                                <label for="edit-nuclide-name">Name <span class="required-mark">*</span></label>
                                <input type="text" id="edit-nuclide-name" name="name" maxlength="50" value="<?= e($editOld['name']) ?>" required data-modal-focus>
                                <span class="field-hint">Renaming updates how this nuclide displays everywhere, including past orders.</span>
                                <?= field_error($editErrors, 'name') ?>
                            </div>
                        </div>
                        <div class="modal__footer modal__footer--split">
                            <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                            <button type="submit" class="btn btn--primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>
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

  // ---- Shared dirty-tracking + discard-confirm-on-close wiring, same
  // isDirty() / petordersBeforeClose / petordersConfirm() pattern as
  // lab_product_users.php / accounts.php -- copied inline per convention,
  // not shared into script.js. markPristine() must be called every time
  // the modal's fields are (re)populated. ----
  function wireModalDirtyTracking(overlay, form, discardCopy, onDiscard) {
    var pristineValues = {};

    function isDirty() {
      var now = snapshotForm(form);
      return Object.keys(pristineValues).some(function (name) {
        return now[name] !== pristineValues[name];
      });
    }

    overlay.petordersBeforeClose = function () {
      if (!isDirty()) return true;
      window.petordersConfirm({
        title: discardCopy.title,
        message: discardCopy.message,
        verb: 'Discard',
        danger: true
      }).then(function (discard) {
        if (!discard) return;
        if (onDiscard) onDiscard();
        window.petordersCloseModal(true);
      });
      return false;
    };

    return {
      markPristine: function () { pristineValues = snapshotForm(form); }
    };
  }

  // ---- Add modal ----
  var addModal = document.getElementById('add-nuclide-modal');
  var addForm = document.getElementById('add-nuclide-form');
  // Discard resets to blank: Add's fields are never JS-populated, so
  // form.reset() is safe -- same reasoning as lab_product_users.php.
  var addTracking = wireModalDirtyTracking(
    addModal,
    addForm,
    { title: 'Discard this nuclide?', message: 'Your entries will be discarded.' },
    function () { addForm.reset(); }
  );

  ['add-nuclide-btn', 'add-nuclide-btn-empty'].forEach(function (id) {
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
  var editModal = document.getElementById('edit-nuclide-modal');
  var editForm = document.getElementById('edit-nuclide-form');
  var editIdField = document.getElementById('edit-nuclide-id');
  var editNameField = document.getElementById('edit-nuclide-name');
  // No onDiscard reset: Edit's fields are JS-populated per row, so the
  // next real open always repopulates from fresh data -- same reasoning
  // as lab_product_users.php.
  var editTracking = wireModalDirtyTracking(editModal, editForm, {
    title: 'Discard these changes?',
    message: 'Your edits to this nuclide will be discarded.'
  });

  function openEditModal(values, opener) {
    editIdField.value = values.nuclide_id;
    editNameField.value = values.name;
    window.petordersOpenModal(editModal, { opener: opener || document.activeElement });
    editTracking.markPristine();
  }

  document.querySelectorAll('[data-edit-nuclide]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      openEditModal({
        nuclide_id: btn.dataset.nuclideId,
        name: btn.dataset.nuclideName
      }, e.currentTarget);
    });
  });

  <?php if ($editErrors): ?>
  openEditModal({
    nuclide_id: <?= json_encode($editOld['nuclide_id']) ?>,
    name: <?= json_encode($editOld['name']) ?>
  }, null);
  <?php endif; ?>

  // Strip one-time arrival-toast query flags from the URL bar once their
  // toast has been queued, so a reload doesn't re-show a toast for an
  // action that already happened -- same fix as lab_product_users.php.
  window.petordersCleanArrivalFlags(['created', 'updated', 'activated', 'deactivated']);
});
</script>
</html>
