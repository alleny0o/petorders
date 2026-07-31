<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('admin'); // catalog management is admin-only; staff only process orders

$pdo = get_db();

// The three fixed delivery methods (products.delivery_method enum) --
// display labels always come from delivery_method_label() (helpers.php).
$deliveryMethods = ['radiopharmacy', 'pick_up', 'direct_delivery'];

// One-shot arrival-toast flags set by the PRG redirects below -- same
// convention as nuclides.php / lab_product_users.php (locals + $_GET strip
// here, petordersCleanArrivalFlags() near the bottom for the reload half).
['created' => $justCreated, 'updated' => $justUpdated, 'activated' => $justActivated, 'deactivated' => $justDeactivated]
    = consume_arrival_flags(['created', 'updated', 'activated', 'deactivated']);

// Get and clean the search query
$q = trim($_GET['q'] ?? '');

// Whitelist specific status values. Note that status is the DERIVED effective-availability state with
// active = p.active AND n.active
// unavailable = p.active but n.active off
// inactive = p.active off
$allowedStatuses = ['active', 'unavailable', 'inactive'];
$statusInput     = $_GET['status'] ?? '';
$status          = in_array($statusInput, $allowedStatuses, true) ? $statusInput : '';

// Filter the nuclide ID
$nuclideInput = $_GET['nuclide'] ?? '';
$nuclideFilter = ctype_digit((string) $nuclideInput) ? (int) $nuclideInput : 0;

// Filter the fulfillment
$fulfillmentInput = $_GET['fulfillment'] ?? '';
$fulfillmentFilter = in_array($fulfillmentInput, $deliveryMethods, true) ? statusInput : '';

// Ensure page numbers are a positive integer
$pageInput = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page      = ($pageInput !== false && $pageInput > 0) ? $pageInput : 1;

// Ensure page size matches one of our allowed options
$pageSizeInput = filter_var($_GET['page_size'] ?? 0, FILTER_VALIDATE_INT);
$pageSize      = in_array($pageSizeInput, PAGE_SIZE_OPTIONS, true) ? $pageSizeInput : DEFAULT_PAGE_SIZE;

// Canonicalize so every link built via build_query() below carries the
// real applied values -- same convention as accounts.php / nuclides.php.
canonicalize_get([
    'status' => $status,
    'nuclide' => $nuclideFilter > 0 ? $nuclideFilter : '',
    'fulfillment' => $fulfillmentFilter,
    'page' => $page,
    'page_size' => $pageSize,
]);

$addErrors = [];
$addOld = ['name' => '', 'nuclide_id' => '', 'delivery_method' => '', 'active' => '1'];
$editErrors = [];
$editOld = ['product_id' => '', 'name' => '', 'nuclide_id' => '', 'delivery_method' => '', 'has_orders' => '0'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $addOld['name'] = trim($_POST['name'] ?? '');
        $addOld['nuclide_id'] = trim($_POST['nuclide_id'] ?? '');
        $addOld['delivery_method'] = trim($_POST['delivery_method'] ?? '');
        $addOld['active'] = trim($_POST['active'] ?? '');

        if ($addOld['name'] === '') {
            $addErrors['name'] = 'Name is required.';
        } elseif (mb_strlen($addOld['name']) > 150) {
            $addErrors['name'] = 'Name must be 150 characters or fewer.';
        }

        $nuclideId = ctype_digit($addOld['nuclide_id']) ? (int) $addOld['nuclide_id'] : 0;
        if ($nuclideId <= 0) {
            $addErrors['nuclide_id'] = 'Select a nuclide.';
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM nuclides WHERE nuclide_id = ? AND active = 1');
            $stmt->execute([$nuclideId]);
            if (!$stmt->fetchColumn()) {
                $addErrors['nuclide_id'] = 'Select a valid nuclide.';
            }
        }

        if (!in_array($addOld['delivery_method'], $deliveryMethods, true)) {
            $addErrors['delivery_method'] = 'Select a fulfillment.';
        }

        if ($addOld['active'] !== '0' && $addOld['active'] !== '1') {
            $addErrors['active'] = 'Select a status.';
        }

        // Duplicate pre-check: the schema's unique key on
        // (name, nuclide_id, delivery_method) would reject this insert
        // anyway, but as a fatal PDO exception -- caught here as a normal
        // field error instead. Same name+nuclide under a DIFFERENT
        // delivery method is legitimate (the dual-row convention).
        if (!isset($addErrors['name']) && !isset($addErrors['nuclide_id']) && !isset($addErrors['delivery_method'])) {
            $stmt = $pdo->prepare('SELECT 1 FROM products WHERE name = ? AND nuclide_id = ? AND delivery_method = ?');
            $stmt->execute([$addOld['name'], $nuclideId, $addOld['delivery_method']]);
            if ($stmt->fetchColumn()) {
                $addErrors['name'] = 'This product already exists for that nuclide and fulfillment.';
            }
        }

        if ($addErrors && request_wants_json()) {
            json_response(['ok' => false, 'errors' => $addErrors], 422);
        }

        if (!$addErrors) {
            $pdo->prepare('INSERT INTO products (nuclide_id, name, delivery_method, active) VALUES (?, ?, ?, ?)')
                ->execute([$nuclideId, $addOld['name'], $addOld['delivery_method'], (int) $addOld['active']]);
            $dest = '/admin/products.php' . build_query(['created' => '1']);
            if (request_wants_json()) {
                json_response(['ok' => true, 'redirect' => $dest]);
            }
            redirect($dest);
        }
    } elseif ($action === 'update') {
        $editOld['product_id'] = trim($_POST['product_id'] ?? '');
        $editOld['name'] = trim($_POST['name'] ?? '');
        $editOld['nuclide_id'] = trim($_POST['nuclide_id'] ?? '');
        $editOld['delivery_method'] = trim($_POST['delivery_method'] ?? '');

        $productId = ctype_digit($editOld['product_id']) ? (int) $editOld['product_id'] : 0;
        $current = false;
        if ($productId > 0) {
            $stmt = $pdo->prepare(
                'SELECT p.nuclide_id, p.delivery_method,
                        EXISTS(SELECT 1 FROM orders o WHERE o.product_id = p.product_id) AS has_orders
                 FROM products p WHERE p.product_id = ?'
            );
            $stmt->execute([$productId]);
            $current = $stmt->fetch();
        }
        if (!$current) {
            $editErrors['product_id'] = 'Unknown product.';
        }
        $hasOrders = $current ? (bool) $current['has_orders'] : false;
        $editOld['has_orders'] = $hasOrders ? '1' : '0';

        if ($editOld['name'] === '') {
            $editErrors['name'] = 'Name is required.';
        } elseif (mb_strlen($editOld['name']) > 150) {
            $editErrors['name'] = 'Name must be 150 characters or fewer.';
        }

        $nuclideId = ctype_digit($editOld['nuclide_id']) ? (int) $editOld['nuclide_id'] : 0;

        // Nuclide and fulfillment lock once any order references this
        // product row -- server-enforced regardless of the UI's disabled
        // selects, per the audit-trail rationale in schema.sql: change
        // them by creating a new product row and deactivating this one.
        if ($current) {
            if ($nuclideId <= 0) {
                $editErrors['nuclide_id'] = 'Select a nuclide.';
            } elseif ($nuclideId !== (int) $current['nuclide_id']) {
                if ($hasOrders) {
                    $editErrors['nuclide_id'] = 'This product is in use by existing orders — its nuclide can\'t be changed. Create a new product instead.';
                } else {
                    // A CHANGED nuclide must be active, same rule as create;
                    // keeping the current (possibly inactive) nuclide is
                    // always allowed so a name fix never forces a change.
                    $stmt = $pdo->prepare('SELECT 1 FROM nuclides WHERE nuclide_id = ? AND active = 1');
                    $stmt->execute([$nuclideId]);
                    if (!$stmt->fetchColumn()) {
                        $editErrors['nuclide_id'] = 'Select an active nuclide.';
                    }
                }
            }

            if (!in_array($editOld['delivery_method'], $deliveryMethods, true)) {
                $editErrors['delivery_method'] = 'Select a fulfillment.';
            } elseif ($hasOrders && $editOld['delivery_method'] !== $current['delivery_method']) {
                $editErrors['delivery_method'] = 'This product is in use by existing orders — its fulfillment can\'t be changed. Create a new product instead.';
            }
        }

        // Duplicate pre-check excluding this row, same reasoning as create.
        if ($current && !isset($editErrors['name']) && !isset($editErrors['nuclide_id']) && !isset($editErrors['delivery_method'])) {
            $stmt = $pdo->prepare('SELECT 1 FROM products WHERE name = ? AND nuclide_id = ? AND delivery_method = ? AND product_id != ?');
            $stmt->execute([$editOld['name'], $nuclideId, $editOld['delivery_method'], $productId]);
            if ($stmt->fetchColumn()) {
                $editErrors['name'] = 'This product already exists for that nuclide and fulfillment.';
            }
        }

        if ($editErrors && request_wants_json()) {
            json_response(['ok' => false, 'errors' => $editErrors], 422);
        }

        if (!$editErrors) {
            $pdo->prepare('UPDATE products SET name = ?, nuclide_id = ?, delivery_method = ? WHERE product_id = ?')
                ->execute([$editOld['name'], $nuclideId, $editOld['delivery_method'], $productId]);
            $dest = '/admin/products.php' . build_query(['updated' => '1']);
            if (request_wants_json()) {
                json_response(['ok' => true, 'redirect' => $dest]);
            }
            redirect($dest);
        }
    } elseif ($action === 'toggle_active') {
        $productId = ctype_digit((string) ($_POST['product_id'] ?? '')) ? (int) $_POST['product_id'] : 0;
        if ($productId > 0) {
            $stmt = $pdo->prepare('SELECT active FROM products WHERE product_id = ?');
            $stmt->execute([$productId]);
            $currentActive = $stmt->fetchColumn();

            if ($currentActive !== false) {
                $newActive = $currentActive ? 0 : 1;
                $pdo->prepare('UPDATE products SET active = ? WHERE product_id = ?')
                    ->execute([$newActive, $productId]);
                redirect('/admin/products.php' . build_query([$newActive ? 'activated' : 'deactivated' => '1']));
            }
        }
    }
}

// Base filters (search/nuclide/fulfillment), WITHOUT the status condition
// -- reused for the tab counts so each tab's count reflects the other
// active filters, then extended below for the actual list.
$where = [];
$params = [];

if ($q !== '') {
    // Escape LIKE wildcards in the search term itself, same convention
    // as accounts.php / customers.php.
    $where[] = "p.name LIKE ? ESCAPE '\\\\'";
    $params[] = like_contains($q);
}
if ($nuclideFilter > 0) {
    $where[] = 'p.nuclide_id = ?';
    $params[] = $nuclideFilter;
}
if ($fulfillmentFilter !== '') {
    $where[] = 'p.delivery_method = ?';
    $params[] = $fulfillmentFilter;
}

$whereSql = where_clause($where);

// Three-way DERIVED status: a product with active = 1 under a deactivated
// nuclide is "unavailable" -- effectively hidden from customers by the
// computed-availability rule (see get_new_order_form_data()), but not
// "inactive", which always means an admin turned the product itself off.
$derivedStatusSql = "CASE WHEN p.active = 0 THEN 'inactive'
                          WHEN n.active = 0 THEN 'unavailable'
                          ELSE 'active' END";

$countsStmt = $pdo->prepare(
    "SELECT $derivedStatusSql AS derived_status, COUNT(*) AS c
     FROM products p
     JOIN nuclides n ON n.nuclide_id = p.nuclide_id
     $whereSql
     GROUP BY derived_status"
);
$countsStmt->execute($params);
$statusCounts = ['active' => 0, 'unavailable' => 0, 'inactive' => 0];
foreach ($countsStmt->fetchAll() as $row) {
    $statusCounts[$row['derived_status']] = (int) $row['c'];
}
$allCount = array_sum($statusCounts);
$totalCount = $status !== '' ? $statusCounts[$status] : $allCount;

$statusTabs = [
    ['value' => '',            'label' => 'All',         'count' => $allCount],
    ['value' => 'active',      'label' => 'Active',      'count' => $statusCounts['active']],
    ['value' => 'unavailable', 'label' => 'Unavailable', 'count' => $statusCounts['unavailable']],
    ['value' => 'inactive',    'label' => 'Inactive',    'count' => $statusCounts['inactive']],
];

$listWhere = $where;
$listParams = $params;
if ($status === 'active') {
    $listWhere[] = 'p.active = 1 AND n.active = 1';
} elseif ($status === 'unavailable') {
    $listWhere[] = 'p.active = 1 AND n.active = 0';
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

// Full management list: joins nuclides unfiltered on purpose (unlike the
// customer order form's active-only view) and pulls n.active for the
// Unavailable treatment plus has_orders for the edit modal's
// nuclide/fulfillment lock. LIMIT/OFFSET interpolation: same server-
// computed-ints convention as accounts.php / nuclides.php.
$listStmt = $pdo->prepare(
    "SELECT p.product_id, p.name, p.delivery_method, p.active,
            p.nuclide_id, n.name AS nuclide_name, n.active AS nuclide_active,
            EXISTS(SELECT 1 FROM orders o WHERE o.product_id = p.product_id) AS has_orders
     FROM products p
     JOIN nuclides n ON n.nuclide_id = p.nuclide_id
     $listWhereSql
     ORDER BY p.name, p.delivery_method
     LIMIT $offset, $pageSize"
);
$listStmt->execute($listParams);
$products = $listStmt->fetchAll();

// Backing data for the filter select and both modals. The Add modal only
// offers ACTIVE nuclides (creating a product that's unavailable from birth
// is almost always a mistake); the filter and Edit selects list ALL
// nuclides, inactive ones suffixed -- Edit must be able to show a
// product's current nuclide even when that nuclide is inactive, and a
// CHANGED nuclide is re-checked server-side against the active-only rule.
$allNuclides = $pdo->query('SELECT nuclide_id, name, active FROM nuclides ORDER BY name')->fetchAll();
$activeNuclides = array_values(array_filter($allNuclides, fn($n) => $n['active']));

$formAction = form_action('/admin/products.php');

$rangeStart = $pagination['rangeStart'];
$rangeEnd = $pagination['rangeEnd'];
$hasFilters = $q !== '' || $status !== '' || $nuclideFilter > 0 || $fulfillmentFilter !== '';

$pageTitle = 'Products';
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
                <h1>Products</h1>
                <div class="page-header__actions">
                    <button type="button" class="btn btn--primary" id="add-product-btn">+ Product</button>
                </div>
            </div>

            <?php if ($justCreated): ?>
                <?= toast_flash('success', 'Product added.') ?>
            <?php elseif ($justUpdated): ?>
                <?= toast_flash('success', 'Product updated.') ?>
            <?php elseif ($justActivated): ?>
                <?= toast_flash('success', 'Product activated.') ?>
            <?php elseif ($justDeactivated): ?>
                <?= toast_flash('success', 'Product deactivated.') ?>
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

                        <select name="nuclide">
                            <option value="">All nuclides</option>
                            <?php foreach ($allNuclides as $n): ?>
                                <option value="<?= (int) $n['nuclide_id'] ?>" <?= $nuclideFilter === (int) $n['nuclide_id'] ? 'selected' : '' ?>><?= e($n['name']) ?><?= $n['active'] ? '' : ' (inactive)' ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select name="fulfillment">
                            <option value="">All fulfillments</option>
                            <?php foreach ($deliveryMethods as $method): ?>
                                <option value="<?= e($method) ?>" <?= $fulfillmentFilter === $method ? 'selected' : '' ?>><?= e(delivery_method_label($method)) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="btn btn--secondary btn--sm">Filter</button>
                    </form>
                </div>

                <?php if (!$products): ?>
                    <div class="empty-state">
                        <div class="empty-state__icon">
                            <?php if ($hasFilters): ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="10" cy="10" r="7"></circle>
                                    <line x1="21" y1="21" x2="15" y2="15"></line>
                                </svg>
                            <?php else: ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                                    <line x1="12" y1="22.08" x2="12" y2="12"></line>
                                </svg>
                            <?php endif; ?>
                        </div>
                        <div class="empty-state__title"><?= $hasFilters ? 'No products match these filters' : 'No products yet' ?></div>
                        <p class="empty-state__hint"><?= $hasFilters ? 'Try a different search or clear the filters.' : 'Add a product so customers can order it.' ?></p>
                        <div class="empty-state__action">
                            <?php if ($hasFilters): ?>
                                <a href="/admin/products.php" class="btn btn--secondary btn--sm">Clear filters</a>
                            <?php else: ?>
                                <button type="button" class="btn btn--primary btn--sm" id="add-product-btn-empty">+ Product</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Nuclide</th>
                                    <th>Fulfillment</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $p): ?>
                                    <tr>
                                        <td><?= e($p['name']) ?></td>
                                        <td class="muted"><?= e($p['nuclide_name']) ?><?= $p['nuclide_active'] ? '' : ' <span class="text-sm">(inactive)</span>' ?></td>
                                        <td class="muted"><?= e(delivery_method_label($p['delivery_method'])) ?></td>
                                        <?php // Three-way derived status. "Unavailable" =
                                              // this product's own flag is still on, but its
                                              // nuclide is off -- distinct from "Inactive"
                                              // (admin turned the product itself off), so
                                              // nobody misreads a nuclide-wide outage as a
                                              // per-product decision. ?>
                                        <td>
                                            <?php if (!$p['active']): ?>
                                                <span class="badge badge--inactive">Inactive</span>
                                            <?php elseif (!$p['nuclide_active']): ?>
                                                <div><span class="badge badge--unavailable">Unavailable</span></div>
                                                <div class="muted text-sm">Nuclide inactive</div>
                                            <?php else: ?>
                                                <span class="badge badge--active">Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="flex gap-2 justify-end">
                                                <button type="button" class="table-action"
                                                        data-edit-product
                                                        data-product-id="<?= (int) $p['product_id'] ?>"
                                                        data-product-name="<?= e($p['name']) ?>"
                                                        data-product-nuclide-id="<?= (int) $p['nuclide_id'] ?>"
                                                        data-product-delivery-method="<?= e($p['delivery_method']) ?>"
                                                        data-product-has-orders="<?= $p['has_orders'] ? '1' : '0' ?>">Edit</button>

                                                <?php if ($p['active']): ?>
                                                    <form method="post" action="<?= e($formAction) ?>"
                                                          data-confirm="Deactivate &ldquo;<?= e($p['name']) ?> &mdash; <?= e(delivery_method_label($p['delivery_method'])) ?>&rdquo;? Customers will no longer be able to select it on new orders."
                                                          data-confirm-title="Deactivate product"
                                                          data-confirm-verb="Deactivate"
                                                          data-confirm-danger>
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="product_id" value="<?= (int) $p['product_id'] ?>">
                                                        <button type="submit" class="btn btn--danger btn--sm">Deactivate</button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="post" action="<?= e($formAction) ?>"
                                                          data-confirm="Activate &ldquo;<?= e($p['name']) ?> &mdash; <?= e(delivery_method_label($p['delivery_method'])) ?>&rdquo;?<?= $p['nuclide_active'] ? '' : ' Its nuclide is currently inactive, so it will stay unavailable to customers until the nuclide is reactivated.' ?>"
                                                          data-confirm-title="Activate product"
                                                          data-confirm-verb="Activate">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="product_id" value="<?= (int) $p['product_id'] ?>">
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
                        'idPrefix' => 'products-',
                        'itemLabel' => 'Products',
                        'hiddenFields' => [
                            'q' => $q,
                            'status' => $status,
                            'nuclide' => $nuclideFilter > 0 ? $nuclideFilter : '',
                            'fulfillment' => $fulfillmentFilter,
                        ],
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
            <div class="modal-overlay" id="add-product-modal" hidden>
                <div class="modal" role="dialog" aria-modal="true" aria-labelledby="add-product-modal-title">
                    <div class="modal__header">
                        <h2 class="modal__title" id="add-product-modal-title">Add product</h2>
                        <button type="button" class="modal__close" data-modal-close aria-label="Close">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                    <form method="post" action="<?= e($formAction) ?>" id="add-product-form" novalidate data-ajax-submit>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create">
                        <div class="modal__body">
                            <div class="alert alert--error" data-error-banner-for="add-product-form" <?= $addErrors ? '' : 'hidden' ?>>Please correct the errors below and resubmit.</div>
                            <div class="<?= field_class($addErrors, 'nuclide_id') ?>">
                                <label for="add-product-nuclide">Nuclide <span class="required-mark">*</span></label>
                                <select id="add-product-nuclide" name="nuclide_id" required data-modal-focus>
                                    <option value="">Select nuclide&hellip;</option>
                                    <?php foreach ($activeNuclides as $n): ?>
                                        <option value="<?= (int) $n['nuclide_id'] ?>" <?= $addOld['nuclide_id'] === (string) $n['nuclide_id'] ? 'selected' : '' ?>><?= e($n['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?= field_error($addErrors, 'nuclide_id') ?>
                            </div>
                            <div class="<?= field_class($addErrors, 'name') ?>">
                                <label for="add-product-name">Name <span class="required-mark">*</span></label>
                                <input type="text" id="add-product-name" name="name" maxlength="150" value="<?= e($addOld['name']) ?>" required>
                                <?= field_error($addErrors, 'name') ?>
                            </div>
                            <div class="<?= field_class($addErrors, 'delivery_method') ?>">
                                <label for="add-product-delivery-method">Fulfillment <span class="required-mark">*</span></label>
                                <select id="add-product-delivery-method" name="delivery_method" required>
                                    <option value="">Select fulfillment&hellip;</option>
                                    <?php foreach ($deliveryMethods as $method): ?>
                                        <option value="<?= e($method) ?>" <?= $addOld['delivery_method'] === $method ? 'selected' : '' ?>><?= e(delivery_method_label($method)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="field-hint">Fixed per product &mdash; to offer a product a second way, add another row with the other fulfillment.</span>
                                <?= field_error($addErrors, 'delivery_method') ?>
                            </div>
                            <?php // No required-mark or required attr on Status: the
                                  // select has no empty option, so it always submits a
                                  // value -- an asterisk here would just be noise next
                                  // to the three genuinely-required fields above. ?>
                            <div class="<?= field_class($addErrors, 'active') ?>">
                                <label for="add-product-active">Status</label>
                                <select id="add-product-active" name="active">
                                    <option value="1" <?= $addOld['active'] === '1' ? 'selected' : '' ?>>Active</option>
                                    <option value="0" <?= $addOld['active'] === '0' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                                <span class="field-hint">Inactive products can't be selected on new orders.</span>
                                <?= field_error($addErrors, 'active') ?>
                            </div>
                        </div>
                        <div class="modal__footer modal__footer--split">
                            <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                            <button type="submit" class="btn btn--primary">Add Product</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit modal: single shared modal for every row, populated via
                 JS from the clicked row's data-product-* attributes (or, on
                 a failed submit, from $editOld server-side). Nuclide +
                 Fulfillment lock (disabled selects + hint) once any order
                 references the row; the lock is also server-enforced above.
                 The nuclide select lists ALL nuclides -- unlike Add's
                 active-only list -- so a product's current, possibly
                 inactive nuclide can render as selected; changing TO an
                 inactive one is rejected server-side. Status stays out of
                 this modal: activate/deactivate is the row action. -->
            <div class="modal-overlay" id="edit-product-modal" hidden>
                <div class="modal" role="dialog" aria-modal="true" aria-labelledby="edit-product-modal-title">
                    <div class="modal__header">
                        <h2 class="modal__title" id="edit-product-modal-title">Edit product</h2>
                        <button type="button" class="modal__close" data-modal-close aria-label="Close">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                    <form method="post" action="<?= e($formAction) ?>" id="edit-product-form" novalidate data-ajax-submit>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="product_id" id="edit-product-id" value="<?= e($editOld['product_id']) ?>">
                        <div class="modal__body">
                            <div class="alert alert--error" data-error-banner-for="edit-product-form" <?= $editErrors ? '' : 'hidden' ?>>Please correct the errors below and resubmit.</div>
                            <div class="<?= field_class($editErrors, 'name') ?>">
                                <label for="edit-product-name">Name <span class="required-mark">*</span></label>
                                <input type="text" id="edit-product-name" name="name" maxlength="150" value="<?= e($editOld['name']) ?>" required data-modal-focus>
                                <?= field_error($editErrors, 'name') ?>
                            </div>
                            <div class="<?= field_class($editErrors, 'nuclide_id') ?>">
                                <label for="edit-product-nuclide">Nuclide <span class="required-mark">*</span></label>
                                <select id="edit-product-nuclide" name="nuclide_id" required>
                                    <option value="">Select nuclide&hellip;</option>
                                    <?php foreach ($allNuclides as $n): ?>
                                        <option value="<?= (int) $n['nuclide_id'] ?>" <?= $editOld['nuclide_id'] === (string) $n['nuclide_id'] ? 'selected' : '' ?>><?= e($n['name']) ?><?= $n['active'] ? '' : ' (inactive)' ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?= field_error($editErrors, 'nuclide_id') ?>
                            </div>
                            <div class="<?= field_class($editErrors, 'delivery_method') ?>">
                                <label for="edit-product-delivery-method">Fulfillment <span class="required-mark">*</span></label>
                                <select id="edit-product-delivery-method" name="delivery_method" required>
                                    <option value="">Select fulfillment&hellip;</option>
                                    <?php foreach ($deliveryMethods as $method): ?>
                                        <option value="<?= e($method) ?>" <?= $editOld['delivery_method'] === $method ? 'selected' : '' ?>><?= e(delivery_method_label($method)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?= field_error($editErrors, 'delivery_method') ?>
                            </div>
                            <?php // Shown (and the two selects above disabled, with
                                  // same-named hidden mirrors enabled instead) only
                                  // when the product has orders -- see the JS lock
                                  // wiring below. ?>
                            <p class="field-hint" id="edit-product-lock-hint" hidden>In use by existing orders &mdash; to change fulfillment or nuclide, create a new product and deactivate this one.</p>
                            <input type="hidden" id="edit-product-nuclide-locked" name="nuclide_id" value="" disabled>
                            <input type="hidden" id="edit-product-delivery-locked" name="delivery_method" value="" disabled>
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
      // Skipping disabled elements is a deliberate departure from the
      // other pages' copies of this helper: the Edit form pairs each
      // lockable select with a same-named disabled/enabled hidden mirror,
      // and the mirror sits later in the DOM -- without this skip it
      // would always win the snapshot slot and mask real select edits.
      if (!el.name || el.disabled) return;
      values[el.name] = el.value;
    });
    return values;
  }

  // Dirty-tracking + discard-confirm-on-close wiring is shared:
  // window.petordersWireModalDirtyTracking (script.js). snapshotForm()
  // above stays page-local -- what counts as a field value varies per
  // page (this one skips disabled locked-mirror fields).

  // ---- Add modal ----
  var addModal = document.getElementById('add-product-modal');
  var addForm = document.getElementById('add-product-form');
  var addTracking = window.petordersWireModalDirtyTracking(
    addModal,
    addForm,
    snapshotForm,
    { title: 'Discard this product?', message: 'Your entries will be discarded.' },
    function () { addForm.reset(); }
  );

  ['add-product-btn', 'add-product-btn-empty'].forEach(function (id) {
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

  // ---- Edit modal: population + nuclide/fulfillment lock + dirty-tracking ----
  var editModal = document.getElementById('edit-product-modal');
  var editForm = document.getElementById('edit-product-form');
  var editIdField = document.getElementById('edit-product-id');
  var editNameField = document.getElementById('edit-product-name');
  var editNuclideSelect = document.getElementById('edit-product-nuclide');
  var editDeliverySelect = document.getElementById('edit-product-delivery-method');
  var editNuclideLocked = document.getElementById('edit-product-nuclide-locked');
  var editDeliveryLocked = document.getElementById('edit-product-delivery-locked');
  var editLockHint = document.getElementById('edit-product-lock-hint');

  var editTracking = window.petordersWireModalDirtyTracking(editModal, editForm, snapshotForm, {
    title: 'Discard these changes?',
    message: 'Your edits to this product will be discarded.'
  });

  // Once orders reference a product, nuclide + fulfillment lock: the
  // selects go disabled (so they read as fixed and don't submit) and the
  // same-named hidden mirrors carry the current values instead -- exactly
  // one control per name is enabled at any time. The server re-enforces
  // this regardless, so the lock is presentation, not the security boundary.
  function applyLockState(locked, nuclideId, deliveryMethod) {
    editNuclideSelect.disabled = locked;
    editDeliverySelect.disabled = locked;
    editNuclideLocked.disabled = !locked;
    editDeliveryLocked.disabled = !locked;
    editNuclideLocked.value = nuclideId;
    editDeliveryLocked.value = deliveryMethod;
    editLockHint.hidden = !locked;
  }

  function openEditModal(values, opener) {
    editIdField.value = values.product_id;
    editNameField.value = values.name;
    editNuclideSelect.value = values.nuclide_id;
    editDeliverySelect.value = values.delivery_method;
    applyLockState(values.has_orders === '1', values.nuclide_id, values.delivery_method);
    window.petordersOpenModal(editModal, { opener: opener || document.activeElement });
    editTracking.markPristine();
  }

  document.querySelectorAll('[data-edit-product]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      openEditModal({
        product_id: btn.dataset.productId,
        name: btn.dataset.productName,
        nuclide_id: btn.dataset.productNuclideId,
        delivery_method: btn.dataset.productDeliveryMethod,
        has_orders: btn.dataset.productHasOrders
      }, e.currentTarget);
    });
  });

  <?php if ($editErrors): ?>
  openEditModal({
    product_id: <?= json_encode($editOld['product_id'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    name: <?= json_encode($editOld['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    nuclide_id: <?= json_encode($editOld['nuclide_id'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    delivery_method: <?= json_encode($editOld['delivery_method'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    has_orders: <?= json_encode($editOld['has_orders'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
  }, null);
  <?php endif; ?>

  // Strip one-time arrival-toast query flags from the URL bar once their
  // toast has been queued -- same fix as nuclides.php /
  // lab_product_users.php.
  window.petordersCleanArrivalFlags(['created', 'updated', 'activated', 'deactivated']);
});
</script>
</html>
