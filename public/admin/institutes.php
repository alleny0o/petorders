<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('admin'); // directory management is admin-only

$pdo = get_db();

// One-shot arrival-toast flags set by the PRG redirects below -- same
// convention as nuclides.php / lab_product_users.php (locals + $_GET strip
// here, petordersCleanArrivalFlags() near the bottom for the reload half).
['created' => $justCreated, 'updated' => $justUpdated, 'activated' => $justActivated, 'deactivated' => $justDeactivated]
    = consume_arrival_flags(['created', 'updated', 'activated', 'deactivated']);

$q = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$pageSize = in_array((int) ($_GET['page_size'] ?? 0), PAGE_SIZE_OPTIONS, true)
    ? (int) $_GET['page_size'] : DEFAULT_PAGE_SIZE;

// Canonicalize so every link built via build_query() below carries
// the real applied values -- same convention as nuclides.php.
canonicalize_get([
    'status' => $status,
    'page' => $page,
    'page_size' => $pageSize,
]);

/**
 * Shared by create and edit. The name and shorthand checks are the
 * app-level pre-checks for the DB's own uq_institutes_name /
 * uq_institutes_shorthand unique keys ($excludeId is 0 on create), with
 * the caller's try/catch as the race-condition backstop. Shorthand is
 * required -- it is the display text for every institute dropdown.
 */
function validate_institute_fields(PDO $pdo, string $name, string $shorthand, int $excludeId): array
{
    $errors = [];

    if ($name === '') {
        $errors['name'] = 'Name is required.';
    } elseif (mb_strlen($name) > 255) {
        $errors['name'] = 'Name must be 255 characters or fewer.';
    } else {
        $stmt = $pdo->prepare('SELECT 1 FROM institutes WHERE name = ? AND institute_id != ?');
        $stmt->execute([$name, $excludeId]);
        if ($stmt->fetchColumn()) {
            $errors['name'] = 'An institute with this name already exists.';
        }
    }

    if ($shorthand === '') {
        $errors['shorthand_name'] = 'Shorthand is required.';
    } elseif (mb_strlen($shorthand) > 10) {
        $errors['shorthand_name'] = 'Shorthand must be 10 characters or fewer.';
    } else {
        $stmt = $pdo->prepare('SELECT 1 FROM institutes WHERE shorthand_name = ? AND institute_id != ?');
        $stmt->execute([$shorthand, $excludeId]);
        if ($stmt->fetchColumn()) {
            $errors['shorthand_name'] = 'An institute with this shorthand already exists.';
        }
    }

    return $errors;
}

/**
 * Maps a duplicate-key PDOException from an institute write to the
 * offending field -- two unique keys exist, so the constraint name in
 * the driver message decides which field gets the error.
 */
function institute_duplicate_error(PDOException $e): array
{
    if (strpos($e->getMessage(), 'uq_institutes_shorthand') !== false) {
        return ['shorthand_name' => 'An institute with this shorthand already exists.'];
    }
    return ['name' => 'An institute with this name already exists.'];
}

$addErrors = [];
$addOld = ['name' => '', 'shorthand_name' => '', 'active' => '1'];
$editErrors = [];
$editOld = ['institute_id' => '', 'name' => '', 'shorthand_name' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $addOld['name'] = trim($_POST['name'] ?? '');
        $addOld['shorthand_name'] = trim($_POST['shorthand_name'] ?? '');
        $addOld['active'] = trim($_POST['active'] ?? '');

        $addErrors = validate_institute_fields($pdo, $addOld['name'], $addOld['shorthand_name'], 0);

        if ($addOld['active'] !== '0' && $addOld['active'] !== '1') {
            $addErrors['active'] = 'Select a status.';
        }

        if ($addErrors && request_wants_json()) {
            json_response(['ok' => false, 'errors' => $addErrors], 422);
        }

        if (!$addErrors) {
            try {
                $pdo->prepare('INSERT INTO institutes (name, shorthand_name, active) VALUES (?, ?, ?)')
                    ->execute([$addOld['name'], $addOld['shorthand_name'], (int) $addOld['active']]);
                $dest = '/admin/institutes.php?' . build_query(['created' => '1']);
                if (request_wants_json()) {
                    json_response(['ok' => true, 'redirect' => $dest]);
                }
                redirect($dest);
            } catch (PDOException $e) {
                $addErrors = institute_duplicate_error($e);
                if (request_wants_json()) {
                    json_response(['ok' => false, 'errors' => $addErrors], 422);
                }
            }
        }
    } elseif ($action === 'update') {
        // Free rename, same reasoning as nuclides.php: a rename is a
        // label correction, and everything (customer sidebars, order
        // pages) derives institute display live, so the corrected name
        // showing everywhere is desired.
        $editOld['institute_id'] = trim($_POST['institute_id'] ?? '');
        $editOld['name'] = trim($_POST['name'] ?? '');
        $editOld['shorthand_name'] = trim($_POST['shorthand_name'] ?? '');

        $instituteId = ctype_digit($editOld['institute_id']) ? (int) $editOld['institute_id'] : 0;

        if ($instituteId <= 0) {
            $editErrors['institute_id'] = 'Unknown institute.';
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM institutes WHERE institute_id = ?');
            $stmt->execute([$instituteId]);
            if (!$stmt->fetchColumn()) {
                $editErrors['institute_id'] = 'Unknown institute.';
            }
        }

        $editErrors += validate_institute_fields($pdo, $editOld['name'], $editOld['shorthand_name'], $instituteId);

        if ($editErrors && request_wants_json()) {
            json_response(['ok' => false, 'errors' => $editErrors], 422);
        }

        if (!$editErrors) {
            try {
                $pdo->prepare('UPDATE institutes SET name = ?, shorthand_name = ? WHERE institute_id = ?')
                    ->execute([$editOld['name'], $editOld['shorthand_name'], $instituteId]);
                $dest = '/admin/institutes.php?' . build_query(['updated' => '1']);
                if (request_wants_json()) {
                    json_response(['ok' => true, 'redirect' => $dest]);
                }
                redirect($dest);
            } catch (PDOException $e) {
                $editErrors = institute_duplicate_error($e);
                if (request_wants_json()) {
                    json_response(['ok' => false, 'errors' => $editErrors], 422);
                }
            }
        }
    } elseif ($action === 'toggle_active') {
        $instituteId = ctype_digit((string) ($_POST['institute_id'] ?? '')) ? (int) $_POST['institute_id'] : 0;
        if ($instituteId > 0) {
            $stmt = $pdo->prepare('SELECT active FROM institutes WHERE institute_id = ?');
            $stmt->execute([$instituteId]);
            $currentActive = $stmt->fetchColumn();

            if ($currentActive !== false) {
                // Effective availability is computed (lab.active AND
                // institute.active, checked live by register.php), so
                // this single flag flip is the whole operation -- no lab
                // rows are ever written here, and reactivating restores
                // the institute's active labs with zero extra steps.
                $newActive = $currentActive ? 0 : 1;
                $pdo->prepare('UPDATE institutes SET active = ? WHERE institute_id = ?')
                    ->execute([$newActive, $instituteId]);
                redirect('/admin/institutes.php?' . build_query([$newActive ? 'activated' : 'deactivated' => '1']));
            }
        }
    }
}

$where = [];
$params = [];

if ($q !== '') {
    // Escape LIKE wildcards in the search term itself, same convention
    // as accounts.php / nuclides.php.
    $where[] = "i.name LIKE ? ESCAPE '\\\\'";
    $params[] = like_contains($q);
}

$whereSql = where_clause($where);

// Built without the status condition -- reused for the tab counts and
// then extended below for the actual list, same pattern as nuclides.php.
$countsStmt = $pdo->prepare(
    "SELECT i.active, COUNT(*) AS c
     FROM institutes i
     $whereSql
     GROUP BY i.active"
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
    $listWhere[] = 'i.active = 1';
} elseif ($status === 'inactive') {
    $listWhere[] = 'i.active = 0';
}
$listWhereSql = where_clause($listWhere);

$pagination = paginate($totalCount, $page, $pageSize);
$page = $pagination['page'];
$totalPages = $pagination['totalPages'];
$offset = $pagination['offset'];
// Keep $_GET in sync with the clamped page so build_query() (and
// $formAction below) never echoes back an out-of-range page number.
canonicalize_get(['page' => $page]);

// LIMIT/OFFSET interpolation: same server-computed-ints convention as
// nuclides.php. The lab counts feed the Labs column and the deactivate
// confirm's blast-radius copy.
$listStmt = $pdo->prepare(
    "SELECT i.institute_id, i.name, i.shorthand_name, i.active,
            (SELECT COUNT(*) FROM labs l WHERE l.institute_id = i.institute_id) AS lab_count,
            (SELECT COUNT(*) FROM labs l WHERE l.institute_id = i.institute_id AND l.active = 1) AS active_lab_count
     FROM institutes i
     $listWhereSql
     ORDER BY i.name
     LIMIT $offset, $pageSize"
);
$listStmt->execute($listParams);
$institutesList = $listStmt->fetchAll();

$formAction = form_action('/admin/institutes.php');

$rangeStart = $pagination['rangeStart'];
$rangeEnd = $pagination['rangeEnd'];
$hasFilters = $q !== '' || $status !== '';

$pageTitle = 'Institutes';
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
                <h1>Institutes</h1>
                <div class="page-header__actions">
                    <button type="button" class="btn btn--primary" id="add-institute-btn">+ Institute</button>
                </div>
            </div>

            <?php if ($justCreated): ?>
                <?= toast_flash('success', 'Institute added.') ?>
            <?php elseif ($justUpdated): ?>
                <?= toast_flash('success', 'Institute updated.') ?>
            <?php elseif ($justActivated): ?>
                <?= toast_flash('success', 'Institute activated.') ?>
            <?php elseif ($justDeactivated): ?>
                <?= toast_flash('success', 'Institute deactivated.') ?>
            <?php endif; ?>

            <nav class="status-tabs" aria-label="Filter by status">
                <?php foreach ($statusTabs as $tab): ?>
                    <a href="?<?= e(build_query(['status' => $tab['value'], 'page' => 1])) ?>" class="status-tabs__link <?= $status === $tab['value'] ? 'is-active' : '' ?>">
                        <?= e($tab['label']) ?> <span class="status-tabs__count"><?= $tab['count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="table-card">
                <div class="table-card-header">
                    <form method="get" class="table-card-controls">
                        <input type="hidden" name="status" value="<?= e($status) ?>">
                        <input type="hidden" name="page_size" value="<?= e((string) $pageSize) ?>">
                        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search by name&hellip;">
                        <button type="submit" class="btn btn--secondary btn--sm">Search</button>
                    </form>
                </div>

                <?php if (!$institutesList): ?>
                    <div class="empty-state">
                        <div class="empty-state__icon">
                            <?php if ($hasFilters): ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="10" cy="10" r="7"></circle>
                                    <line x1="21" y1="21" x2="15" y2="15"></line>
                                </svg>
                            <?php else: ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="3" y1="21" x2="21" y2="21"></line>
                                    <path d="M5 21V7l7-4 7 4v14"></path>
                                    <line x1="9" y1="9" x2="9" y2="9.01"></line>
                                    <line x1="15" y1="9" x2="15" y2="9.01"></line>
                                    <line x1="9" y1="13" x2="9" y2="13.01"></line>
                                    <line x1="15" y1="13" x2="15" y2="13.01"></line>
                                </svg>
                            <?php endif; ?>
                        </div>
                        <div class="empty-state__title"><?= $hasFilters ? 'No institutes match these filters' : 'No institutes yet' ?></div>
                        <p class="empty-state__hint"><?= $hasFilters ? 'Try a different search or clear the filters.' : 'Add an institute so labs can be created under it.' ?></p>
                        <div class="empty-state__action">
                            <?php if ($hasFilters): ?>
                                <a href="/admin/institutes.php" class="btn btn--secondary btn--sm">Clear filters</a>
                            <?php else: ?>
                                <button type="button" class="btn btn--primary btn--sm" id="add-institute-btn-empty">+ Institute</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Shorthand</th>
                                    <th>Labs</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($institutesList as $i): ?>
                                    <?php
                                    $labCount = (int) $i['lab_count'];
                                    $activeLabCount = (int) $i['active_lab_count'];
                                    // Blast-radius copy for the deactivate confirm:
                                    // no lab rows are written, but every active lab
                                    // under this institute drops out of registration.
                                    if ($activeLabCount > 0) {
                                        $deactivateCopy = 'Deactivate &ldquo;' . e($i['name']) . '&rdquo;? Its '
                                            . $activeLabCount . ' active lab' . ($activeLabCount === 1 ? '' : 's')
                                            . ' will become unavailable to new registrations until it is reactivated.';
                                    } else {
                                        $deactivateCopy = 'Deactivate &ldquo;' . e($i['name']) . '&rdquo;? New registrations will no longer be able to select it.';
                                    }
                                    ?>
                                    <tr>
                                        <td><?= e($i['name']) ?></td>
                                        <td class="muted"><?= e($i['shorthand_name']) ?></td>
                                        <td class="count-cell">
                                            <?php if ($labCount === 0): ?>
                                                <span class="muted">&mdash;</span>
                                            <?php else: ?>
                                                <?= $activeLabCount ?>
                                                <div class="text-sm muted"><?= $labCount ?> total</div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge badge--<?= $i['active'] ? 'active' : 'inactive' ?>"><?= $i['active'] ? 'Active' : 'Inactive' ?></span></td>
                                        <td>
                                            <div class="flex gap-2 justify-end">
                                                <button type="button" class="table-action"
                                                        data-edit-institute
                                                        data-institute-id="<?= (int) $i['institute_id'] ?>"
                                                        data-institute-name="<?= e($i['name']) ?>"
                                                        data-institute-shorthand="<?= e($i['shorthand_name']) ?>">Edit</button>

                                                <?php if ($i['active']): ?>
                                                    <form method="post" action="<?= e($formAction) ?>"
                                                          data-confirm="<?= $deactivateCopy ?>"
                                                          data-confirm-title="Deactivate institute"
                                                          data-confirm-verb="Deactivate"
                                                          data-confirm-danger>
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="institute_id" value="<?= (int) $i['institute_id'] ?>">
                                                        <button type="submit" class="btn btn--danger btn--sm">Deactivate</button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="post" action="<?= e($formAction) ?>"
                                                          data-confirm="Activate &ldquo;<?= e($i['name']) ?>&rdquo;? Its active labs become selectable on new registrations again automatically."
                                                          data-confirm-title="Activate institute"
                                                          data-confirm-verb="Activate">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="institute_id" value="<?= (int) $i['institute_id'] ?>">
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
                        'idPrefix' => 'institutes-',
                        'itemLabel' => 'Institutes',
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
                 nuclides.php / lab_product_users.php. -->
            <div class="modal-overlay" id="add-institute-modal" hidden>
                <div class="modal" role="dialog" aria-modal="true" aria-labelledby="add-institute-modal-title">
                    <div class="modal__header">
                        <h2 class="modal__title" id="add-institute-modal-title">Add institute</h2>
                        <button type="button" class="modal__close" data-modal-close aria-label="Close">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                    <form method="post" action="<?= e($formAction) ?>" id="add-institute-form" novalidate data-ajax-submit>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create">
                        <div class="modal__body">
                            <div class="alert alert--error" data-error-banner-for="add-institute-form" <?= $addErrors ? '' : 'hidden' ?>>Please correct the errors below and resubmit.</div>
                            <div class="<?= field_class($addErrors, 'name') ?>">
                                <label for="add-institute-name">Name <span class="required-mark">*</span></label>
                                <input type="text" id="add-institute-name" name="name" maxlength="255" value="<?= e($addOld['name']) ?>" required data-modal-focus>
                                <?= field_error($addErrors, 'name') ?>
                            </div>
                            <div class="<?= field_class($addErrors, 'shorthand_name') ?>">
                                <label for="add-institute-shorthand">Shorthand</label>
                                <input type="text" id="add-institute-shorthand" name="shorthand_name" maxlength="10" required value="<?= e($addOld['shorthand_name']) ?>">
                                <span class="field-hint">Abbreviation, e.g. &ldquo;NCI&rdquo;.</span>
                                <?= field_error($addErrors, 'shorthand_name') ?>
                            </div>
                            <?php // No required-mark or required attr on Status: the
                                  // select has no empty option, so it always submits
                                  // a value -- same reasoning as nuclides.php. ?>
                            <div class="<?= field_class($addErrors, 'active') ?>">
                                <label for="add-institute-active">Status</label>
                                <select id="add-institute-active" name="active">
                                    <option value="1" <?= $addOld['active'] === '1' ? 'selected' : '' ?>>Active</option>
                                    <option value="0" <?= $addOld['active'] === '0' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                                <span class="field-hint">While an institute is inactive, none of its labs can be selected on new registrations.</span>
                                <?= field_error($addErrors, 'active') ?>
                            </div>
                        </div>
                        <div class="modal__footer modal__footer--split">
                            <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                            <button type="submit" class="btn btn--primary">Add Institute</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit modal: single shared modal for every row, populated via
                 JS from the clicked row's data-institute-* attributes (or,
                 on a failed submit, from $editOld server-side). Status
                 changes go through the row's toggle action. -->
            <div class="modal-overlay" id="edit-institute-modal" hidden>
                <div class="modal" role="dialog" aria-modal="true" aria-labelledby="edit-institute-modal-title">
                    <div class="modal__header">
                        <h2 class="modal__title" id="edit-institute-modal-title">Edit institute</h2>
                        <button type="button" class="modal__close" data-modal-close aria-label="Close">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                    <form method="post" action="<?= e($formAction) ?>" id="edit-institute-form" novalidate data-ajax-submit>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="institute_id" id="edit-institute-id" value="<?= e($editOld['institute_id']) ?>">
                        <div class="modal__body">
                            <div class="alert alert--error" data-error-banner-for="edit-institute-form" <?= $editErrors ? '' : 'hidden' ?>>Please correct the errors below and resubmit.</div>
                            <div class="<?= field_class($editErrors, 'name') ?>">
                                <label for="edit-institute-name">Name <span class="required-mark">*</span></label>
                                <input type="text" id="edit-institute-name" name="name" maxlength="255" value="<?= e($editOld['name']) ?>" required data-modal-focus>
                                <span class="field-hint">Renaming updates how this institute displays everywhere, including past orders.</span>
                                <?= field_error($editErrors, 'name') ?>
                            </div>
                            <div class="<?= field_class($editErrors, 'shorthand_name') ?>">
                                <label for="edit-institute-shorthand">Shorthand</label>
                                <input type="text" id="edit-institute-shorthand" name="shorthand_name" maxlength="10" required value="<?= e($editOld['shorthand_name']) ?>">
                                <?= field_error($editErrors, 'shorthand_name') ?>
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
  // nuclides.php / lab_product_users.php -- copied inline per convention,
  // not shared into script.js. ----
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
  var addModal = document.getElementById('add-institute-modal');
  var addForm = document.getElementById('add-institute-form');
  var addTracking = wireModalDirtyTracking(
    addModal,
    addForm,
    { title: 'Discard this institute?', message: 'Your entries will be discarded.' },
    function () { addForm.reset(); }
  );

  ['add-institute-btn', 'add-institute-btn-empty'].forEach(function (id) {
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
  var editModal = document.getElementById('edit-institute-modal');
  var editForm = document.getElementById('edit-institute-form');
  var editIdField = document.getElementById('edit-institute-id');
  var editNameField = document.getElementById('edit-institute-name');
  var editShorthandField = document.getElementById('edit-institute-shorthand');
  var editTracking = wireModalDirtyTracking(editModal, editForm, {
    title: 'Discard these changes?',
    message: 'Your edits to this institute will be discarded.'
  });

  function openEditModal(values, opener) {
    editIdField.value = values.institute_id;
    editNameField.value = values.name;
    editShorthandField.value = values.shorthand_name;
    window.petordersOpenModal(editModal, { opener: opener || document.activeElement });
    editTracking.markPristine();
  }

  document.querySelectorAll('[data-edit-institute]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      openEditModal({
        institute_id: btn.dataset.instituteId,
        name: btn.dataset.instituteName,
        shorthand_name: btn.dataset.instituteShorthand
      }, e.currentTarget);
    });
  });

  <?php if ($editErrors): ?>
  openEditModal({
    institute_id: <?= json_encode($editOld['institute_id']) ?>,
    name: <?= json_encode($editOld['name']) ?>,
    shorthand_name: <?= json_encode($editOld['shorthand_name']) ?>
  }, null);
  <?php endif; ?>

  // Strip one-time arrival-toast query flags from the URL bar once their
  // toast has been queued -- same fix as nuclides.php /
  // lab_product_users.php.
  window.petordersCleanArrivalFlags(['created', 'updated', 'activated', 'deactivated']);
});
</script>
</html>
