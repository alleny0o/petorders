<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('admin'); // directory management is admin-only

$pdo = get_db();

// One-shot arrival-toast flags set by the PRG redirects below -- same
// convention as nuclides.php / institutes.php.
['created' => $justCreated, 'updated' => $justUpdated, 'activated' => $justActivated, 'deactivated' => $justDeactivated]
    = consume_arrival_flags(['created', 'updated', 'activated', 'deactivated']);

$q = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$pageSize = in_array((int) ($_GET['page_size'] ?? 0), PAGE_SIZE_OPTIONS, true)
    ? (int) $_GET['page_size'] : DEFAULT_PAGE_SIZE;

// Canonicalize so every link built via build_query() below carries the
// real applied values -- same convention as nuclides.php.
canonicalize_get([
    'status' => $status,
    'page' => $page,
    'page_size' => $pageSize,
]);

/**
 * Shared by create and edit. Name and email are required; email must
 * also be globally unique (pis.email carries a UNIQUE key -- see the
 * caller for the pre-check + try/catch backstop). Phone stays
 * format-checked only when non-empty (nullable column, no uniqueness).
 * The phone rule matches customer_detail.php's.
 */
function validate_pi_fields(string $piName, string $email, string $phone): array
{
    $errors = [];

    if ($piName === '') {
        $errors['pi_name'] = 'Name is required.';
    } elseif (mb_strlen($piName) > 100) {
        $errors['pi_name'] = 'Name must be 100 characters or fewer.';
    }

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'A valid email is required.';
    } elseif (mb_strlen($email) > 254) {
        $errors['email'] = 'Email must be 254 characters or fewer.';
    }

    if ($phone !== '') {
        if (mb_strlen($phone) > 20) {
            $errors['phone'] = 'Phone must be 20 characters or fewer.';
        } elseif (!preg_match('/^[0-9()+.\-\s]+$/', $phone) || !preg_match('/[0-9]/', $phone)) {
            $errors['phone'] = 'Phone must contain only digits, spaces, dashes, parentheses, and an optional leading +.';
        }
    }

    return $errors;
}

$addErrors = [];
$addOld = ['pi_name' => '', 'email' => '', 'phone' => '', 'active' => '1'];
$editErrors = [];
$editOld = ['pi_id' => '', 'pi_name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $addOld['pi_name'] = trim($_POST['pi_name'] ?? '');
        $addOld['email'] = trim($_POST['email'] ?? '');
        $addOld['phone'] = trim($_POST['phone'] ?? '');
        $addOld['active'] = trim($_POST['active'] ?? '');

        $addErrors = validate_pi_fields($addOld['pi_name'], $addOld['email'], $addOld['phone']);

        if ($addOld['active'] !== '0' && $addOld['active'] !== '1') {
            $addErrors['active'] = 'Select a status.';
        }

        if (!$addErrors) {
            // Pre-check, same convention as accounts.php -- the catch
            // block below is the race-condition backstop.
            $stmt = $pdo->prepare('SELECT 1 FROM pis WHERE email = ?');
            $stmt->execute([$addOld['email']]);
            if ($stmt->fetchColumn()) {
                $addErrors['email'] = 'A PI with this email already exists.';
            }
        }

        if (!$addErrors) {
            try {
                $pdo->prepare('INSERT INTO pis (pi_name, email, phone, active) VALUES (?, ?, ?, ?)')
                    ->execute([
                        $addOld['pi_name'],
                        $addOld['email'],
                        $addOld['phone'] !== '' ? $addOld['phone'] : null,
                        (int) $addOld['active'],
                    ]);
                $dest = '/admin/pis.php?' . build_query(['created' => '1']);
                if (request_wants_json()) {
                    json_response(['ok' => true, 'redirect' => $dest]);
                }
                redirect($dest);
            } catch (PDOException $e) {
                $addErrors['email'] = 'A PI with this email already exists.';
            }
        }

        if ($addErrors && request_wants_json()) {
            json_response(['ok' => false, 'errors' => $addErrors], 422);
        }
    } elseif ($action === 'update') {
        // Free edit, same reasoning as nuclides.php's rename: these are
        // label/contact corrections, and everything displays PI data live.
        $editOld['pi_id'] = trim($_POST['pi_id'] ?? '');
        $editOld['pi_name'] = trim($_POST['pi_name'] ?? '');
        $editOld['email'] = trim($_POST['email'] ?? '');
        $editOld['phone'] = trim($_POST['phone'] ?? '');

        $piId = ctype_digit($editOld['pi_id']) ? (int) $editOld['pi_id'] : 0;

        if ($piId <= 0) {
            $editErrors['pi_id'] = 'Unknown PI.';
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM pis WHERE pi_id = ?');
            $stmt->execute([$piId]);
            if (!$stmt->fetchColumn()) {
                $editErrors['pi_id'] = 'Unknown PI.';
            }
        }

        $editErrors += validate_pi_fields($editOld['pi_name'], $editOld['email'], $editOld['phone']);

        if (!$editErrors) {
            $stmt = $pdo->prepare('SELECT 1 FROM pis WHERE email = ? AND pi_id <> ?');
            $stmt->execute([$editOld['email'], $piId]);
            if ($stmt->fetchColumn()) {
                $editErrors['email'] = 'A PI with this email already exists.';
            }
        }

        if (!$editErrors) {
            try {
                $pdo->prepare('UPDATE pis SET pi_name = ?, email = ?, phone = ? WHERE pi_id = ?')
                    ->execute([
                        $editOld['pi_name'],
                        $editOld['email'],
                        $editOld['phone'] !== '' ? $editOld['phone'] : null,
                        $piId,
                    ]);
                $dest = '/admin/pis.php?' . build_query(['updated' => '1']);
                if (request_wants_json()) {
                    json_response(['ok' => true, 'redirect' => $dest]);
                }
                redirect($dest);
            } catch (PDOException $e) {
                $editErrors['email'] = 'A PI with this email already exists.';
            }
        }

        if ($editErrors && request_wants_json()) {
            json_response(['ok' => false, 'errors' => $editErrors], 422);
        }
    } elseif ($action === 'toggle_active') {
        $piId = ctype_digit((string) ($_POST['pi_id'] ?? '')) ? (int) $_POST['pi_id'] : 0;
        if ($piId > 0) {
            $stmt = $pdo->prepare('SELECT active FROM pis WHERE pi_id = ?');
            $stmt->execute([$piId]);
            $currentActive = $stmt->fetchColumn();

            if ($currentActive !== false) {
                // Leaf flag: gates only new-registration selection (and
                // changed-to assignment in admin customer edit). No
                // lab_pis rows are written -- pairings persist, so
                // reactivating restores selectability with zero extra steps.
                $newActive = $currentActive ? 0 : 1;
                $pdo->prepare('UPDATE pis SET active = ? WHERE pi_id = ?')
                    ->execute([$newActive, $piId]);
                redirect('/admin/pis.php?' . build_query([$newActive ? 'activated' : 'deactivated' => '1']));
            }
        }
    }
}

$where = [];
$params = [];

if ($q !== '') {
    // Escape LIKE wildcards in the search term itself, same convention
    // as accounts.php / nuclides.php.
    $where[] = "p.pi_name LIKE ? ESCAPE '\\\\'";
    $params[] = like_contains($q);
}

$whereSql = where_clause($where);

// Built without the status condition -- reused for the tab counts and
// then extended below for the actual list, same pattern as nuclides.php.
$countsStmt = $pdo->prepare(
    "SELECT p.active, COUNT(*) AS c
     FROM pis p
     $whereSql
     GROUP BY p.active"
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
    $listWhere[] = 'p.active = 1';
} elseif ($status === 'inactive') {
    $listWhere[] = 'p.active = 0';
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
// the other admin lists. Lab pairings and supervised-customer counts
// feed the Labs/Customers columns.
$listStmt = $pdo->prepare(
    "SELECT p.pi_id, p.pi_name, p.email, p.phone, p.active,
            (SELECT COUNT(*) FROM lab_pis lp WHERE lp.pi_id = p.pi_id) AS lab_count,
            (SELECT COUNT(*) FROM customers c WHERE c.supervising_pi_id = p.pi_id) AS customer_count
     FROM pis p
     $listWhereSql
     ORDER BY p.pi_name
     LIMIT $offset, $pageSize"
);
$listStmt->execute($listParams);
$pisList = $listStmt->fetchAll();

$formAction = form_action('/admin/pis.php');

$rangeStart = $pagination['rangeStart'];
$rangeEnd = $pagination['rangeEnd'];
$hasFilters = $q !== '' || $status !== '';

$pageTitle = 'PIs';
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
                <h1>PIs</h1>
                <div class="page-header__actions">
                    <button type="button" class="btn btn--primary" id="add-pi-btn">+ PI</button>
                </div>
            </div>

            <?php if ($justCreated): ?>
                <?= toast_flash('success', 'PI added.') ?>
            <?php elseif ($justUpdated): ?>
                <?= toast_flash('success', 'PI updated.') ?>
            <?php elseif ($justActivated): ?>
                <?= toast_flash('success', 'PI activated.') ?>
            <?php elseif ($justDeactivated): ?>
                <?= toast_flash('success', 'PI deactivated.') ?>
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
                    <span class="table-card-title">Principal Investigators</span>
                    <form method="get" class="table-card-controls">
                        <input type="hidden" name="status" value="<?= e($status) ?>">
                        <input type="hidden" name="page_size" value="<?= e((string) $pageSize) ?>">
                        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search by name&hellip;">
                        <button type="submit" class="btn btn--secondary btn--sm">Search</button>
                    </form>
                </div>

                <?php if (!$pisList): ?>
                    <div class="empty-state">
                        <div class="empty-state__icon">
                            <?php if ($hasFilters): ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="10" cy="10" r="7"></circle>
                                    <line x1="21" y1="21" x2="15" y2="15"></line>
                                </svg>
                            <?php else: ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="9" cy="7" r="4"></circle>
                                </svg>
                            <?php endif; ?>
                        </div>
                        <div class="empty-state__title"><?= $hasFilters ? 'No PIs match these filters' : 'No PIs yet' ?></div>
                        <p class="empty-state__hint"><?= $hasFilters ? 'Try a different search or clear the filters.' : 'Add a PI so labs can list them on their roster.' ?></p>
                        <div class="empty-state__action">
                            <?php if ($hasFilters): ?>
                                <a href="/admin/pis.php" class="btn btn--secondary btn--sm">Clear filters</a>
                            <?php else: ?>
                                <button type="button" class="btn btn--primary btn--sm" id="add-pi-btn-empty">+ PI</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Labs</th>
                                    <th>Customers</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pisList as $p): ?>
                                    <tr>
                                        <td><?= e($p['pi_name']) ?></td>
                                        <td class="muted"><?= $p['email'] !== null && $p['email'] !== '' ? e($p['email']) : '&mdash;' ?></td>
                                        <td class="muted"><?= $p['phone'] !== null && $p['phone'] !== '' ? e($p['phone']) : '&mdash;' ?></td>
                                        <td class="muted"><?= (int) $p['lab_count'] ?: '&mdash;' ?></td>
                                        <td class="muted"><?= (int) $p['customer_count'] ?: '&mdash;' ?></td>
                                        <td><span class="badge badge--<?= $p['active'] ? 'active' : 'inactive' ?>"><?= $p['active'] ? 'Active' : 'Inactive' ?></span></td>
                                        <td>
                                            <div class="flex gap-2 justify-end">
                                                <button type="button" class="table-action"
                                                        data-edit-pi
                                                        data-pi-id="<?= (int) $p['pi_id'] ?>"
                                                        data-pi-name="<?= e($p['pi_name']) ?>"
                                                        data-pi-email="<?= e($p['email'] ?? '') ?>"
                                                        data-pi-phone="<?= e($p['phone'] ?? '') ?>">Edit</button>

                                                <?php if ($p['active']): ?>
                                                    <form method="post" action="<?= e($formAction) ?>"
                                                          data-confirm="Deactivate &ldquo;<?= e($p['pi_name']) ?>&rdquo;? New registrations can no longer select this PI. Existing customers they supervise are unaffected."
                                                          data-confirm-title="Deactivate PI"
                                                          data-confirm-verb="Deactivate"
                                                          data-confirm-danger>
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="pi_id" value="<?= (int) $p['pi_id'] ?>">
                                                        <button type="submit" class="btn btn--danger btn--sm">Deactivate</button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="post" action="<?= e($formAction) ?>"
                                                          data-confirm="Activate &ldquo;<?= e($p['pi_name']) ?>&rdquo;? They become selectable again on new registrations for their paired labs."
                                                          data-confirm-title="Activate PI"
                                                          data-confirm-verb="Activate">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="pi_id" value="<?= (int) $p['pi_id'] ?>">
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
                        'idPrefix' => 'pis-',
                        'itemLabel' => 'PIs',
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

            <!-- Add modal. No lab-pairing UI here on purpose: lab_pis is
                 managed exclusively from labs.php's PI roster. -->
            <div class="modal-overlay" id="add-pi-modal" hidden>
                <div class="modal" role="dialog" aria-modal="true" aria-labelledby="add-pi-modal-title">
                    <div class="modal__header">
                        <h2 class="modal__title" id="add-pi-modal-title">Add PI</h2>
                        <button type="button" class="modal__close" data-modal-close aria-label="Close">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                    <form method="post" action="<?= e($formAction) ?>" id="add-pi-form" novalidate data-ajax-submit>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create">
                        <div class="modal__body">
                            <div class="alert alert--error" data-error-banner-for="add-pi-form" <?= $addErrors ? '' : 'hidden' ?>>Please correct the errors below and resubmit.</div>
                            <div class="<?= field_class($addErrors, 'pi_name') ?>">
                                <label for="add-pi-name">Name <span class="required-mark">*</span></label>
                                <input type="text" id="add-pi-name" name="pi_name" maxlength="100" value="<?= e($addOld['pi_name']) ?>" required data-modal-focus>
                                <?= field_error($addErrors, 'pi_name') ?>
                            </div>
                            <div class="<?= field_class($addErrors, 'email') ?>">
                                <label for="add-pi-email">Email <span class="required-mark">*</span></label>
                                <input type="email" id="add-pi-email" name="email" maxlength="254" value="<?= e($addOld['email']) ?>">
                                <?= field_error($addErrors, 'email') ?>
                            </div>
                            <div class="<?= field_class($addErrors, 'phone') ?>">
                                <label for="add-pi-phone">Phone</label>
                                <input type="text" id="add-pi-phone" name="phone" maxlength="20" value="<?= e($addOld['phone']) ?>">
                                <?= field_error($addErrors, 'phone') ?>
                            </div>
                            <?php // No required-mark or required attr on Status: the
                                  // select has no empty option, so it always submits
                                  // a value -- same reasoning as nuclides.php. ?>
                            <div class="<?= field_class($addErrors, 'active') ?>">
                                <label for="add-pi-active">Status</label>
                                <select id="add-pi-active" name="active">
                                    <option value="1" <?= $addOld['active'] === '1' ? 'selected' : '' ?>>Active</option>
                                    <option value="0" <?= $addOld['active'] === '0' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                                <span class="field-hint">Inactive PIs can't be selected on new registrations.</span>
                                <?= field_error($addErrors, 'active') ?>
                            </div>
                            <p class="field-hint mb-0">To place this PI at a lab, edit that lab's roster under Directory &rsaquo; Labs.</p>
                        </div>
                        <div class="modal__footer modal__footer--split">
                            <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                            <button type="submit" class="btn btn--primary">Add PI</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit modal: single shared modal, populated via JS from the
                 clicked row's data-pi-* attributes (or $editOld on a failed
                 submit). Status changes go through the row's toggle action. -->
            <div class="modal-overlay" id="edit-pi-modal" hidden>
                <div class="modal" role="dialog" aria-modal="true" aria-labelledby="edit-pi-modal-title">
                    <div class="modal__header">
                        <h2 class="modal__title" id="edit-pi-modal-title">Edit PI</h2>
                        <button type="button" class="modal__close" data-modal-close aria-label="Close">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                    <form method="post" action="<?= e($formAction) ?>" id="edit-pi-form" novalidate data-ajax-submit>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="pi_id" id="edit-pi-id" value="<?= e($editOld['pi_id']) ?>">
                        <div class="modal__body">
                            <div class="alert alert--error" data-error-banner-for="edit-pi-form" <?= $editErrors ? '' : 'hidden' ?>>Please correct the errors below and resubmit.</div>
                            <div class="<?= field_class($editErrors, 'pi_name') ?>">
                                <label for="edit-pi-name">Name <span class="required-mark">*</span></label>
                                <input type="text" id="edit-pi-name" name="pi_name" maxlength="100" value="<?= e($editOld['pi_name']) ?>" required data-modal-focus>
                                <span class="field-hint">Renaming updates how this PI displays everywhere, including past orders.</span>
                                <?= field_error($editErrors, 'pi_name') ?>
                            </div>
                            <div class="<?= field_class($editErrors, 'email') ?>">
                                <label for="edit-pi-email">Email <span class="required-mark">*</span></label>
                                <input type="email" id="edit-pi-email" name="email" maxlength="254" value="<?= e($editOld['email']) ?>">
                                <?= field_error($editErrors, 'email') ?>
                            </div>
                            <div class="<?= field_class($editErrors, 'phone') ?>">
                                <label for="edit-pi-phone">Phone</label>
                                <input type="text" id="edit-pi-phone" name="phone" maxlength="20" value="<?= e($editOld['phone']) ?>">
                                <?= field_error($editErrors, 'phone') ?>
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
  // nuclides.php / lab_product_users.php -- copied inline per convention. ----
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
  var addModal = document.getElementById('add-pi-modal');
  var addForm = document.getElementById('add-pi-form');
  var addTracking = wireModalDirtyTracking(
    addModal,
    addForm,
    { title: 'Discard this PI?', message: 'Your entries will be discarded.' },
    function () { addForm.reset(); }
  );

  ['add-pi-btn', 'add-pi-btn-empty'].forEach(function (id) {
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
  var editModal = document.getElementById('edit-pi-modal');
  var editForm = document.getElementById('edit-pi-form');
  var editIdField = document.getElementById('edit-pi-id');
  var editNameField = document.getElementById('edit-pi-name');
  var editEmailField = document.getElementById('edit-pi-email');
  var editPhoneField = document.getElementById('edit-pi-phone');
  var editTracking = wireModalDirtyTracking(editModal, editForm, {
    title: 'Discard these changes?',
    message: 'Your edits to this PI will be discarded.'
  });

  function openEditModal(values, opener) {
    editIdField.value = values.pi_id;
    editNameField.value = values.pi_name;
    editEmailField.value = values.email;
    editPhoneField.value = values.phone;
    window.petordersOpenModal(editModal, { opener: opener || document.activeElement });
    editTracking.markPristine();
  }

  document.querySelectorAll('[data-edit-pi]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      openEditModal({
        pi_id: btn.dataset.piId,
        pi_name: btn.dataset.piName,
        email: btn.dataset.piEmail,
        phone: btn.dataset.piPhone
      }, e.currentTarget);
    });
  });

  <?php if ($editErrors): ?>
  openEditModal({
    pi_id: <?= json_encode($editOld['pi_id']) ?>,
    pi_name: <?= json_encode($editOld['pi_name']) ?>,
    email: <?= json_encode($editOld['email']) ?>,
    phone: <?= json_encode($editOld['phone']) ?>
  }, null);
  <?php endif; ?>

  // Strip one-time arrival-toast query flags from the URL bar once their
  // toast has been queued -- same fix as nuclides.php.
  window.petordersCleanArrivalFlags(['created', 'updated', 'activated', 'deactivated']);
});
</script>
</html>
