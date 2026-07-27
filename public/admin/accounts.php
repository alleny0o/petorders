<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('admin');

$pdo = get_db();

$q = trim($_GET['q'] ?? '');
$role = $_GET['role'] ?? '';
$status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$pageSize = in_array((int) ($_GET['page_size'] ?? 0), PAGE_SIZE_OPTIONS, true)
    ? (int) $_GET['page_size'] : DEFAULT_PAGE_SIZE;

// Canonicalize so every link built via build_query() below (tabs,
// pagination, and the New Account form's action, which embeds the
// current view so a validation-error reopen lands back where the admin
// was) carries the real applied values forward -- same convention as
// staff/orders.php's status/page_size canonicalization.
canonicalize_get([
    'status' => $status,
    'page_size' => $pageSize,
]);

/**
 * Single-use temp password: doesn't need to satisfy the full strength
 * policy (validate_password_strength()) since it's never kept -- the
 * account is forced to change it on first login. Same helper as
 * registrations.php / customer_detail.php / account_detail.php; not
 * shared out to src/helpers.php.
 */
function generate_temp_password(): string
{
    return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(16))), 0, 16);
}

$fieldErrors = [];
$successReveal = null;

$old = [
    'email'      => '',
    'first_name' => '',
    'last_name'  => '',
    'phone'      => '',
    'role'       => 'staff',
];

// New Account: was its own page (account_create.php, deleted); now a
// modal opened from this page, same overlay/dirty-tracking/discard-confirm
// convention as the New Order modal and lab_product_users.php's Add
// modal. The temp password can only be shown once and can't safely
// round-trip through a redirect URL, so success flashes the reveal
// through the session instead (read-once + short TTL, consumed by the
// ?created=1 arrival below) and PRGs like every other modal --
// registrations.php's approve action still uses the older re-render-
// the-POST-response-inline approach.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $old['email'] = trim($_POST['email'] ?? '');
    $old['first_name'] = trim($_POST['first_name'] ?? '');
    $old['last_name'] = trim($_POST['last_name'] ?? '');
    $old['phone'] = trim($_POST['phone'] ?? '');
    $old['role'] = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'staff';

    if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = 'A valid email is required.';
    } elseif (mb_strlen($old['email']) > 50) {
        // The email becomes users.username, which is VARCHAR(50).
        $fieldErrors['email'] = 'Email must be 50 characters or fewer.';
    }

    if ($old['first_name'] === '') {
        $fieldErrors['first_name'] = 'First name is required.';
    } elseif (mb_strlen($old['first_name']) > 100) {
        $fieldErrors['first_name'] = 'First name must be 100 characters or fewer.';
    }
    if ($old['last_name'] === '') {
        $fieldErrors['last_name'] = 'Last name is required.';
    } elseif (mb_strlen($old['last_name']) > 100) {
        $fieldErrors['last_name'] = 'Last name must be 100 characters or fewer.';
    }
    if ($old['phone'] === '') {
        $fieldErrors['phone'] = 'Phone is required.';
    } elseif (!preg_match('/^[0-9()+.\-\s]+$/', $old['phone']) || !preg_match('/[0-9]/', $old['phone'])) {
        $fieldErrors['phone'] = 'Phone must contain only digits, spaces, dashes, parentheses, and an optional leading +.';
    } elseif (mb_strlen($old['phone']) > 20) {
        $fieldErrors['phone'] = 'Phone must be 20 characters or fewer.';
    }

    if (!$fieldErrors) {
        // Pre-check, same convention as register.php -- the transaction's
        // catch block below is the race-condition backstop, same as
        // registrations.php's approve action.
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? AND active = 1');
        $stmt->execute([$old['email']]);
        if ($stmt->fetchColumn()) {
            $fieldErrors['email'] = 'An account is already active for this email.';
        }
    }

    if (!$fieldErrors) {
        $pdo->beginTransaction();
        try {
            $tempPassword = generate_temp_password();
            $tempHash = password_hash($tempPassword, PASSWORD_BCRYPT);

            $pdo->prepare(
                'INSERT INTO users (username, password_hash, first_name, last_name, phone, must_change_password, active) VALUES (?, ?, ?, ?, ?, 1, 1)'
            )->execute([$old['email'], $tempHash, $old['first_name'], $old['last_name'], $old['phone']]);
            $newUserId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO staff (user_id) VALUES (?)')
                ->execute([$newUserId]);

            if ($old['role'] === 'admin') {
                $pdo->prepare('INSERT INTO admins (user_id) VALUES (?)')->execute([$newUserId]);
            }

            // No password_history seeding: the temp can't be reused as
            // the "new" password anyway (is_password_reused() checks the
            // current users.password_hash), and history holds outgoing
            // hashes only.

            $pdo->commit();

            // Session-flash the reveal across the PRG: plaintext lives
            // server-side only (never a URL/history/log), read-once with
            // a short TTL -- consumed by the ?created=1 arrival below.
            $_SESSION['account_created_reveal'] = [
                'user_id'      => $newUserId,
                'email'        => $old['email'],
                'first_name'   => $old['first_name'],
                'last_name'    => $old['last_name'],
                'role'         => $old['role'],
                'tempPassword' => $tempPassword,
                'at'           => time(),
            ];

            // build_query() carries the current search/role/status/page
            // view state forward, same as the other converted pages.
            $dest = '/admin/accounts.php?' . build_query(['created' => '1']);
            if (request_wants_json()) {
                json_response(['ok' => true, 'redirect' => $dest]);
            }
            redirect($dest);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $fieldErrors['email'] = 'Could not create the account. An account for this email may already exist.';
        }
    }

    // One check covers all three error sources (field validation, the
    // duplicate pre-check, and the transaction's race-condition catch) --
    // the success path has already exited above. AJAX gets the errors as
    // JSON for the still-open modal; a plain POST falls through to the
    // full-page re-render + reopen below, kept as the no-JS fallback.
    if ($fieldErrors && request_wants_json()) {
        json_response(['ok' => false, 'errors' => $fieldErrors], 422);
    }
}

// Server half of the arrival-flag convention: strips created from $_GET
// so build_query()/canonicalize_get() below never echo it back into the
// form action or pagination links (petordersCleanArrivalFlags() near the
// bottom handles the URL-bar half).
$arrival = consume_arrival_flags(['created']);

// Consume the flash: cleared on ANY accounts.php load that finds it
// (read-once hygiene -- a crashed redirect can't leave the plaintext
// sitting in the session past the next visit), shown only on a fresh
// ?created=1 arrival. Refreshing drops it, exactly as the banner warns.
if (isset($_SESSION['account_created_reveal'])) {
    $reveal = $_SESSION['account_created_reveal'];
    unset($_SESSION['account_created_reveal']);
    if ($arrival['created'] && time() - (int) $reveal['at'] <= 60) {
        $successReveal = $reveal;
    }
}

$where = [];
$params = [];

if ($q !== '') {
    // Escape LIKE wildcards in the search term itself, same convention
    // as customers.php -- matches either the staff member's name or
    // their username (email).
    $like = like_contains($q);
    $where[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? ESCAPE '\\\\' OR u.username LIKE ? ESCAPE '\\\\')";
    $params[] = $like;
    $params[] = $like;
}
if ($role === 'staff') {
    $where[] = 'a.user_id IS NULL';
} elseif ($role === 'admin') {
    $where[] = 'a.user_id IS NOT NULL';
}

$whereSql = where_clause($where);

// Built without the status condition -- reused for the tab counts (each
// tab's count reflects the current search/role scope, not global counts)
// and then extended with a status condition below for the actual list --
// same pattern as staff/orders.php's $queueStatusCounts.
$countsStmt = $pdo->prepare(
    "SELECT u.active, COUNT(*) AS c
     FROM staff s
     JOIN users u ON u.user_id = s.user_id
     LEFT JOIN admins a ON a.user_id = s.user_id
     $whereSql
     GROUP BY u.active"
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
    $listWhere[] = 'u.active = 1';
} elseif ($status === 'inactive') {
    $listWhere[] = 'u.active = 0';
}
$listWhereSql = where_clause($listWhere);

$pagination = paginate($totalCount, $page, $pageSize);
$page = $pagination['page'];
$totalPages = $pagination['totalPages'];
$offset = $pagination['offset'];
// Keep $_GET in sync with the clamped page so build_query() (and
// $formAction below, embedded in the New Account form's action) never
// echoes back an out-of-range page number.
canonicalize_get(['page' => $page]);

// LIMIT/OFFSET are interpolated directly rather than bound: both are
// fully server-computed ints at this point (page size is clamped against
// a fixed option set, offset is derived from a clamped, ctype_digit-checked
// page number), same convention as customers.php.
$listStmt = $pdo->prepare(
    "SELECT u.user_id, u.username, u.active,
            u.first_name, u.last_name,
            (a.user_id IS NOT NULL) AS is_admin
     FROM staff s
     JOIN users u ON u.user_id = s.user_id
     LEFT JOIN admins a ON a.user_id = s.user_id
     $listWhereSql
     ORDER BY u.username
     LIMIT $offset, $pageSize"
);
$listStmt->execute($listParams);
$accounts = $listStmt->fetchAll();

// Used by the New Account form's action so a validation-error reopen
// lands back on the exact view the admin was on.
$formAction = form_action('/admin/accounts.php');

$rangeStart = $pagination['rangeStart'];
$rangeEnd = $pagination['rangeEnd'];
$hasFilters = $q !== '' || $role !== '' || $status !== '';

$pageTitle = 'Accounts';
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
                <h1>Accounts</h1>
                <div class="page-header__actions">
                    <button type="button" class="btn btn--primary" id="new-account-btn">+ Account</button>
                </div>
            </div>

            <?php if ($successReveal !== null): ?>
                <div class="temp-password-banner">
                    <div class="temp-password-banner__heading"><?= $successReveal['role'] === 'admin' ? 'Admin' : 'Staff' ?> account created for <?= e($successReveal['email']) ?></div>
                    <div>Give this to <?= e($successReveal['first_name'] . ' ' . $successReveal['last_name']) ?> via NIH email &mdash; it will not be shown again.</div>
                    <div class="temp-password-banner__row">
                        <span class="temp-password-banner__password" id="temp-password-value"><?= e($successReveal['tempPassword']) ?></span>
                        <button type="button" class="btn btn--secondary btn--sm" data-copy-target="#temp-password-value">Copy</button>
                    </div>
                    <div class="temp-password-banner__warning">Copy it now &mdash; this password will not be shown again.</div>
                    <div class="mt-2">Missed it? You can generate a new one anytime with Reset Password on <a href="/admin/account_detail.php?id=<?= (int) $successReveal['user_id'] ?>">the account's page</a>.</div>
                </div>
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
                    <span class="table-card-title">Staff &amp; Admins</span>
                    <?php // Status is no longer a field here -- the tabs above
                          // are the status filter now, same as staff/orders.php. ?>
                    <form method="get" class="table-card-controls">
                        <input type="hidden" name="status" value="<?= e($status) ?>">
                        <input type="hidden" name="page_size" value="<?= e((string) $pageSize) ?>">

                        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search email&hellip;">

                        <select name="role">
                            <option value="">All roles</option>
                            <option value="staff" <?= $role === 'staff' ? 'selected' : '' ?>>Staff only</option>
                            <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin only</option>
                        </select>

                        <button type="submit" class="btn btn--secondary btn--sm">Filter</button>
                    </form>
                </div>

                <?php if (!$accounts): ?>
                    <div class="empty-state">
                        <div class="empty-state__icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="10" cy="10" r="7"></circle>
                                <line x1="21" y1="21" x2="15" y2="15"></line>
                            </svg>
                        </div>
                        <div class="empty-state__title"><?= $hasFilters ? 'No accounts match these filters' : 'No staff or admin accounts yet' ?></div>
                        <p class="empty-state__hint"><?= $hasFilters ? 'Try a different search or clear the filters.' : 'Create the first staff or admin account to get started.' ?></p>
                        <div class="empty-state__action">
                            <?php if ($hasFilters): ?>
                                <a href="/admin/accounts.php" class="btn btn--secondary btn--sm">Clear filters</a>
                            <?php else: ?>
                                <button type="button" class="btn btn--primary btn--sm" id="new-account-btn-empty">+ Account</button>
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
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($accounts as $acc): ?>
                                    <tr>
                                        <td><?= e($acc['first_name'] . ' ' . $acc['last_name']) ?></td>
                                        <td><?= e($acc['username']) ?></td>
                                        <td><span class="badge badge--role-<?= $acc['is_admin'] ? 'admin' : 'staff' ?>"><?= $acc['is_admin'] ? 'Admin' : 'Staff' ?></span></td>
                                        <td><span class="badge badge--<?= $acc['active'] ? 'active' : 'inactive' ?>"><?= $acc['active'] ? 'Active' : 'Inactive' ?></span></td>
                                        <td><a href="/admin/account_detail.php?id=<?= (int) $acc['user_id'] ?>" class="table-action">View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php
                    $tablePagination = [
                        'idPrefix' => 'accounts-',
                        'itemLabel' => 'Accounts',
                        'hiddenFields' => ['q' => $q, 'role' => $role, 'status' => $status],
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

            <!-- New Account modal: header (title + X close) / body / split
                 footer mirrors the New Order modal's visual language
                 (src/partials/new_order_modal.php). .modal--wide (640px,
                 modals.css) rather than the default 440px or the
                 near-fullscreen .modal--order -- 4 fields including a
                 role radio-card-group benefit from more horizontal room
                 than lab_product_users.php's narrower Add modal. Replaces
                 the old standalone account_create.php page (deleted). -->
            <div class="modal-overlay" id="new-account-modal" hidden>
                <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="new-account-modal-title">
                    <div class="modal__header">
                        <h2 class="modal__title" id="new-account-modal-title">New account</h2>
                        <button type="button" class="modal__close" data-modal-close aria-label="Close">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                    <form method="post" action="<?= e($formAction) ?>" id="new-account-form" novalidate data-ajax-submit>
                        <?= csrf_field() ?>
                        <div class="modal__body">
                            <?php // Always in the DOM (hidden when clean), same as the
                                  // other converted modals: the AJAX submit unhides it
                                  // alongside the injected field errors; a no-JS POST
                                  // re-render shows it via the $fieldErrors check. ?>
                            <div class="alert alert--error" data-error-banner-for="new-account-form" <?= $fieldErrors ? '' : 'hidden' ?>>Please correct the errors below and resubmit.</div>
                            <div class="<?= field_class($fieldErrors, 'email') ?>">
                                <label for="new-account-email">Email <span class="required-mark">*</span></label>
                                <input type="email" id="new-account-email" name="email" value="<?= e($old['email']) ?>" required data-modal-focus>
                                <span class="field-hint">This will become their username.</span>
                                <?= field_error($fieldErrors, 'email') ?>
                            </div>

                            <div class="field-row">
                                <div class="<?= field_class($fieldErrors, 'first_name') ?>">
                                    <label for="new-account-first-name">First name <span class="required-mark">*</span></label>
                                    <input type="text" id="new-account-first-name" name="first_name" value="<?= e($old['first_name']) ?>" required>
                                    <?= field_error($fieldErrors, 'first_name') ?>
                                </div>
                                <div class="<?= field_class($fieldErrors, 'last_name') ?>">
                                    <label for="new-account-last-name">Last name <span class="required-mark">*</span></label>
                                    <input type="text" id="new-account-last-name" name="last_name" value="<?= e($old['last_name']) ?>" required>
                                    <?= field_error($fieldErrors, 'last_name') ?>
                                </div>
                            </div>

                            <div class="<?= field_class($fieldErrors, 'phone') ?>">
                                <label for="new-account-phone">Phone <span class="required-mark">*</span></label>
                                <input type="text" id="new-account-phone" name="phone" value="<?= e($old['phone']) ?>" required>
                                <?= field_error($fieldErrors, 'phone') ?>
                            </div>

                            <div class="field">
                                <span class="form-section__title">Role <span class="required-mark">*</span></span>
                                <div class="radio-card-group">
                                    <label class="radio-card">
                                        <input type="radio" name="role" value="staff" id="new-account-role-staff" <?= $old['role'] === 'staff' ? 'checked' : '' ?>>
                                        <span class="radio-card__title">Staff</span>
                                        <span class="radio-card__desc">Processes customer orders</span>
                                    </label>
                                    <label class="radio-card">
                                        <input type="radio" name="role" value="admin" id="new-account-role-admin" <?= $old['role'] === 'admin' ? 'checked' : '' ?>>
                                        <span class="radio-card__title">Admin</span>
                                        <span class="radio-card__desc">Everything staff can do, plus management &amp; approvals</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="modal__footer modal__footer--split">
                            <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                            <button type="submit" class="btn btn--primary">Create Account</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>
<script>
document.addEventListener('DOMContentLoaded', function () {
  // Strip the one-time ?created=1 arrival flag from the URL bar once the
  // temp-password banner (or nothing, if the flash was already consumed)
  // has rendered -- same convention as the order-detail pages.
  window.petordersCleanArrivalFlags(['created']);

  function snapshotForm(form) {
    var values = {};
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name) return;
      values[el.name] = el.value;
    });
    return values;
  }

  // ---- Shared dirty-tracking + discard-confirm-on-close wiring, same
  // isDirty() / petordersBeforeClose / petordersConfirm() pattern as the New
  // Order modal (src/partials/new_order_form.php) and
  // lab_product_users.php / lab_delivery_locations.php's Add/Edit
  // modals, scaled down to a plain POST form. markPristine() must be
  // called every time the modal's fields are (re)populated -- on open
  // and on a validation-error reopen -- so only edits made AFTER that
  // point ever count as dirty. ----
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

  // ---- New Account modal ----
  var newAccountModal = document.getElementById('new-account-modal');
  var newAccountForm = document.getElementById('new-account-form');
  // Discard resets to blank: the fields are never JS-populated -- their
  // rendered value="" already IS the correct pristine state (blank on a
  // fresh load, the attempted values on a validation-error reopen), so
  // form.reset() is safe here, same as lab_product_users.php's Add modal.
  var newAccountTracking = wireModalDirtyTracking(
    newAccountModal,
    newAccountForm,
    { title: 'Discard this account?', message: 'Your entries will be discarded.' },
    function () { newAccountForm.reset(); }
  );

  ['new-account-btn', 'new-account-btn-empty'].forEach(function (id) {
    var btn = document.getElementById(id);
    if (btn) {
      btn.addEventListener('click', function (e) {
        window.petordersOpenModal(newAccountModal, { opener: e.currentTarget });
        newAccountTracking.markPristine();
      });
    }
  });

  <?php if ($fieldErrors): ?>
  window.petordersOpenModal(newAccountModal);
  newAccountTracking.markPristine();
  <?php endif; ?>
});
</script>
</html>
