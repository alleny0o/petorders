<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('customer');

$pdo = get_db();
$myUserId = (int) $_SESSION['user_id'];

// Pre-setting $labId here means layout_customer.php's guarded lookup
// never re-queries -- same convention as order_detail.php.
$labId = current_customer_lab_id($pdo, $myUserId);

// Shared with dashboard.php: previous last-seen marker for the row dots
// (null = first visit this session, no dots), and this visit becomes
// the new marker for whichever of the two pages loads next.
$lastOrdersSeen = mark_orders_seen();

$q = trim($_GET['q'] ?? '');
// Whitelisted against the real enums -- an unknown value behaves like "all".
$status = in_array($_GET['status'] ?? '', ['pending', 'accepted', 'completed', 'cancelled'], true)
    ? $_GET['status'] : '';
$fulfillment = in_array($_GET['fulfillment'] ?? '', ['radiopharmacy', 'pick_up', 'direct_delivery'], true)
    ? $_GET['fulfillment'] : '';
// Filters on requested_datetime (when tracer is needed), not created_at/
// placed-on -- that's the lens a customer scanning this list actually
// cares about. Strict Y-m-d match required; anything else (hand-edited
// URL, garbage input) is silently ignored rather than erroring, per
// Kris's keep-it-simple guidance -- an invalid "from"/"to" just behaves
// like the filter wasn't set.
$requestedFrom = isset($_GET['requested_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['requested_from'])
    ? $_GET['requested_from'] : '';
$requestedTo = isset($_GET['requested_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['requested_to'])
    ? $_GET['requested_to'] : '';
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? max(1, (int) $_GET['page']) : 1;
// Clamped against the fixed option set -- an out-of-set or non-numeric
// value (hand-edited URL) falls back to the default rather than driving
// LIMIT with an arbitrary number.
$pageSize = in_array((int) ($_GET['page_size'] ?? 0), PAGE_SIZE_OPTIONS, true)
    ? (int) $_GET['page_size'] : DEFAULT_PAGE_SIZE;

// Canonicalize $_GET to the validated/clamped values so every link built
// via build_query() below (pagination, filter changes) carries the real
// applied values forward, never raw/invalid ones.
canonicalize_get([
    'status' => $status,
    'page_size' => $pageSize,
    'requested_from' => $requestedFrom,
    'requested_to' => $requestedTo,
]);

$orders = [];
$totalCount = 0;
$totalPages = 1;
$offset = 0;
$rangeStart = 0;
$rangeEnd = 0;
$statusCounts = ['pending' => 0, 'accepted' => 0, 'completed' => 0, 'cancelled' => 0];
$allCount = 0;
$tabs = [];

if ($labId > 0) {
    // Lab-scoped: the c.lab_id join condition IS the access control (any
    // customer in the order's lab sees it here, matching order_detail.php's
    // fetch_order_for_lab -- "view own lab's orders"). lab_product_users is
    // LEFT-joined (product_user_id is a nullable, one-to-one FK, so this
    // can't multiply rows) to back the Product User column -- shared by
    // both the tab-counts query and the list query below since it never
    // affects row counts either way.
    $joins =
        'FROM orders o
         JOIN customers c ON c.user_id = o.customer_id AND c.lab_id = ?
         JOIN products p  ON p.product_id = o.product_id
         JOIN nuclides n  ON n.nuclide_id = p.nuclide_id
         JOIN users u     ON u.user_id = o.customer_id
         LEFT JOIN lab_product_users pu ON pu.product_user_id = o.product_user_id';

    // Built without the status condition -- reused for the tab counts
    // (each tab's count reflects the current search/fulfillment/date
    // scope, not global counts) and then extended with status below for
    // the actual list. Same split as staff/orders.php's queue.
    $filterWhere = [];
    $filterParams = [$labId];

    if ($q !== '') {
        // Escape LIKE wildcards in the search term itself, same convention
        // as accounts.php/customers.php. One box covers order ID, product
        // user name, nuclide name, and product name.
        // Matches the order's product user (falling back to the placing
        // customer when none is attached) -- the same COALESCE fallback
        // already used to render the Product User column, so the search
        // box matches whatever's actually displayed there.
        $filterWhere[] = "(CAST(o.order_id AS CHAR) LIKE ? ESCAPE '\\\\'
                     OR COALESCE(CONCAT(pu.first_name, ' ', pu.last_name), CONCAT(u.first_name, ' ', u.last_name)) LIKE ? ESCAPE '\\\\'
                     OR n.name LIKE ? ESCAPE '\\\\'
                     OR p.name LIKE ? ESCAPE '\\\\')";
        $like = like_contains($q);
        array_push($filterParams, $like, $like, $like, $like);
    }
    if ($fulfillment !== '') {
        $filterWhere[] = 'p.delivery_method = ?';
        $filterParams[] = $fulfillment;
    }
    if ($requestedFrom !== '') {
        $filterWhere[] = 'o.requested_datetime >= ?';
        $filterParams[] = $requestedFrom . ' 00:00:00';
    }
    if ($requestedTo !== '') {
        // From-after-to simply yields zero rows (no swap, no error UI) --
        // the existing filtered-empty state already covers it.
        $filterWhere[] = 'o.requested_datetime <= ?';
        $filterParams[] = $requestedTo . ' 23:59:59';
    }

    $filterWhereSql = where_clause($filterWhere);

    $countsStmt = $pdo->prepare("SELECT o.status, COUNT(*) AS c $joins $filterWhereSql GROUP BY o.status");
    $countsStmt->execute($filterParams);
    foreach ($countsStmt->fetchAll() as $row) {
        $statusCounts[$row['status']] = (int) $row['c'];
    }
    $allCount = array_sum($statusCounts);
    $totalCount = $status !== '' ? $statusCounts[$status] : $allCount;

    $tabs = [
        ['value' => '',          'label' => 'All',       'count' => $allCount],
        ['value' => 'pending',   'label' => 'Pending',   'count' => $statusCounts['pending']],
        ['value' => 'accepted',  'label' => 'Accepted',  'count' => $statusCounts['accepted']],
        ['value' => 'completed', 'label' => 'Completed', 'count' => $statusCounts['completed']],
        ['value' => 'cancelled', 'label' => 'Cancelled', 'count' => $statusCounts['cancelled']],
    ];

    $where = $filterWhere;
    $params = $filterParams;
    if ($status !== '') {
        $where[] = 'o.status = ?';
        $params[] = $status;
    }
    $whereSql = where_clause($where);

    $pagination = paginate($totalCount, $page, $pageSize);
    $page = $pagination['page'];
    $totalPages = $pagination['totalPages'];
    $offset = $pagination['offset'];
    $rangeStart = $pagination['rangeStart'];
    $rangeEnd = $pagination['rangeEnd'];
    canonicalize_get(['page' => $page]);

    // LIMIT/OFFSET are interpolated directly rather than bound: both are
    // fully server-computed ints at this point (page size is clamped
    // against a fixed option set, offset is derived from a clamped,
    // ctype_digit-checked page number), same convention as
    // accounts.php/customers.php.
    $listStmt = $pdo->prepare(
        "SELECT o.order_id, o.status, o.requested_datetime, o.updated_at, o.chargeable,
                p.name AS product_name, p.delivery_method,
                u.first_name, u.last_name, u.username,
                CONCAT(pu.first_name, ' ', pu.last_name) AS product_user_name
         $joins
         $whereSql
         ORDER BY o.requested_datetime DESC, o.order_id DESC
         LIMIT $offset, $pageSize"
    );
    $listStmt->execute($params);
    $orders = $listStmt->fetchAll();
}

// Status DOES count toward $hasFilters (unlike an earlier version of this
// page) -- a status tab with zero results is still a filtered-empty state,
// and treating it as "no filters" produced a misleading "your lab hasn't
// placed any orders yet" message on e.g. the Accepted tab even when other
// tabs had orders. $otherFiltersActive is tracked separately so the
// empty-state copy/actions below can tell "only the tab is empty" apart
// from "search/fulfillment/date filters are also narrowing it".
$otherFiltersActive = $q !== '' || $fulfillment !== '' || $requestedFrom !== '' || $requestedTo !== '';
$hasFilters = $status !== '' || $otherFiltersActive;

$pageTitle = 'Orders';
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
                <h1>Orders</h1>
                <div class="page-header__actions">
                    <button type="button" class="btn btn--primary" data-new-order-trigger>+ New Order</button>
                </div>
            </div>

            <?php if ($labId <= 0): ?>
                <div class="card">
                    <p class="muted">No lab assigned to your account yet &mdash; contact an administrator.</p>
                </div>
            <?php else: ?>
                <nav class="status-tabs" aria-label="Filter by status">
                    <?php foreach ($tabs as $tab): ?>
                        <a href="?<?= e(build_query(['status' => $tab['value'], 'page' => 1])) ?>" class="status-tabs__link <?= $status === $tab['value'] ? 'is-active' : '' ?>">
                            <?= e($tab['label']) ?> <span class="status-tabs__count"><?= $tab['count'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="table-card">
                    <div class="table-card-header">
                        <?php // Explicit Filter-button submit, never
                              // live-as-you-type -- filtering is a deliberate
                              // confirm action (spec), same idiom as
                              // accounts.php. Status itself is no longer a
                              // field here -- the tabs above are the status
                              // filter now, carried forward as a hidden field
                              // so a search/fulfillment/date submit doesn't
                              // lose the active tab. ?>
                        <form method="get" class="table-card-controls">
                            <input type="hidden" name="status" value="<?= e($status) ?>">
                            <?php // Preserves the current page size across a
                                  // filter-form submit -- that form has no
                                  // page_size field of its own, so without
                                  // this hidden input a filter change would
                                  // silently reset it to the default. ?>
                            <input type="hidden" name="page_size" value="<?= e((string) $pageSize) ?>">

                            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search # / product / nuclide / product user&hellip;">

                            <select name="fulfillment">
                                <option value="">All fulfillment</option>
                                <option value="radiopharmacy" <?= $fulfillment === 'radiopharmacy' ? 'selected' : '' ?>><?= e(delivery_method_label('radiopharmacy')) ?></option>
                                <option value="pick_up" <?= $fulfillment === 'pick_up' ? 'selected' : '' ?>><?= e(delivery_method_label('pick_up')) ?></option>
                                <option value="direct_delivery" <?= $fulfillment === 'direct_delivery' ? 'selected' : '' ?>><?= e(delivery_method_label('direct_delivery')) ?></option>
                            </select>

                            <label for="requested-from" class="sr-only">Requested from</label>
                            <input type="date" name="requested_from" id="requested-from" value="<?= e($requestedFrom) ?>" title="Requested from">

                            <label for="requested-to" class="sr-only">Requested to</label>
                            <input type="date" name="requested_to" id="requested-to" value="<?= e($requestedTo) ?>" title="Requested to">

                            <button type="submit" class="btn btn--secondary btn--sm">Filter</button>
                        </form>
                    </div>

                    <?php if (!$orders): ?>
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
                            <?php
                            // Three distinct empty states, not two: a status
                            // tab alone (no other filters) gets copy naming
                            // that status ("No accepted orders") rather than
                            // the generic filtered-empty message, since
                            // "these filters" reads oddly when the only thing
                            // narrowing the list is the tab someone clicked.
                            if (!$hasFilters) {
                                $emptyTitle = 'Your lab hasn\'t placed any orders yet';
                                $emptyHint = 'Orders placed by anyone in your lab will show up here.';
                            } elseif ($otherFiltersActive) {
                                $emptyTitle = 'No orders match these filters';
                                $emptyHint = 'Try a different search or clear the filters.';
                            } else {
                                $emptyTitle = 'No ' . $status . ' orders';
                                $emptyHint = 'Try a different status tab above.';
                            }
                            ?>
                            <div class="empty-state__title"><?= e($emptyTitle) ?></div>
                            <p class="empty-state__hint"><?= e($emptyHint) ?></p>
                            <div class="empty-state__action">
                                <?php if ($otherFiltersActive): ?>
                                    <?php // Preserves the active status tab -- only the
                                          // search/fulfillment/date filters clear. ?>
                                    <a href="?<?= e(build_query(['q' => null, 'fulfillment' => null, 'requested_from' => null, 'requested_to' => null, 'page' => 1])) ?>" class="btn btn--secondary btn--sm">Clear filters</a>
                                <?php elseif ($status !== ''): ?>
                                    <a href="?<?= e(build_query(['status' => null, 'page' => 1])) ?>" class="btn btn--secondary btn--sm">View all orders</a>
                                <?php else: ?>
                                    <button type="button" class="btn btn--primary btn--sm" data-new-order-trigger>+ New Order</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-scroll">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Requested</th>
                                        <th>Product</th>
                                        <th>Fulfillment</th>
                                        <th>Product User</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $o): ?>
                                        <?php $isUpdated = $lastOrdersSeen !== null && strtotime($o['updated_at']) > $lastOrdersSeen; ?>
                                        <tr>
                                            <td class="tabular">
                                                <span class="table-flag"><?php if ($isUpdated): ?><span class="dot dot--info" title="Updated since your last visit"></span><span class="sr-only">Updated since your last visit</span><?php endif; ?></span><?= (int) $o['order_id'] ?>
                                            </td>
                                            <td class="tabular"><?= e(date('M j, Y H:i', strtotime($o['requested_datetime']))) ?></td>
                                            <td><?= e($o['product_name']) ?></td>
                                            <td><?= e(delivery_method_label($o['delivery_method'])) ?></td>
                                            <?php // Same fallback rule as both order-detail pages: the
                                                  // attached product user, or the placing customer when
                                                  // none is attached. Label stays "Product User" --
                                                  // established naming, not renamed to "Recipient". ?>
                                            <td><?= e($o['product_user_name'] ?? customer_display_name($o['first_name'], $o['last_name'], $o['username'])) ?></td>
                                            <?php // Plain text, always rendered (not a second badge) --
                                                  // two stacked pills read badly. Chargeable is the
                                                  // default (muted); "Not chargeable" is the exception
                                                  // that reads at full weight. ?>
                                            <td>
                                                <div><span class="badge badge--<?= e($o['status']) ?>"><?= e(ucfirst($o['status'])) ?></span></div>
                                                <?php if ($o['chargeable']): ?>
                                                    <div class="muted text-sm">Chargeable</div>
                                                <?php else: ?>
                                                    <div class="text-sm">Not chargeable</div>
                                                <?php endif; ?>
                                            </td>
                                            <td><a href="/customer/order_detail.php?id=<?= (int) $o['order_id'] ?>" class="table-action">View</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php
                        $tablePagination = [
                            'idPrefix' => '',
                            'itemLabel' => 'Orders',
                            'hiddenFields' => [
                                'q' => $q,
                                'status' => $status,
                                'fulfillment' => $fulfillment,
                                'requested_from' => $requestedFrom,
                                'requested_to' => $requestedTo,
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
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
