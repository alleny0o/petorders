<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('staff');

$pdo = get_db();

// Pure triage list -- no actions, no POST handler. Every lifecycle
// action (accept/return/complete/cancel) and the chargeable toggle now
// live on staff/order_detail.php; this page only searches/filters/
// paginates and links into it, same shape as customer/orders.php.

// Not lab-scoped -- staff/admin may view any order regardless of lab
// ("any staff, any order"), unlike customer/orders.php.
$queueSearch = trim($_GET['q'] ?? '');
$queueStatus = in_array($_GET['status'] ?? '', ['pending', 'accepted', 'completed', 'cancelled'], true)
    ? $_GET['status'] : '';
$queueFulfillment = in_array($_GET['fulfillment'] ?? '', ['radiopharmacy', 'pick_up', 'direct_delivery'], true)
    ? $_GET['fulfillment'] : '';
$queueFrom = isset($_GET['requested_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['requested_from'])
    ? $_GET['requested_from'] : '';
$queueTo = isset($_GET['requested_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['requested_to'])
    ? $_GET['requested_to'] : '';
$queuePage = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$queuePageSize = in_array((int) ($_GET['page_size'] ?? 0), PAGE_SIZE_OPTIONS, true)
    ? (int) $_GET['page_size'] : DEFAULT_PAGE_SIZE;

// Canonicalize $_GET so every link built via build_query() below (tabs,
// pagination) carries the real applied values forward.
canonicalize_get([
    'status' => $queueStatus,
    'page_size' => $queuePageSize,
    'requested_from' => $queueFrom,
    'requested_to' => $queueTo,
]);

// Base join set: every alias the list query's SELECT columns need
// (c is the FK path to l; p, l, u are displayed). Invariant for every
// join set on this page: a query's join set must cover every alias its
// WHERE references -- the search WHERE below touches n/i/pi/pu, so the
// search-only fragment is appended whenever a search term is active.
$queueBaseJoins =
    'FROM orders o
     JOIN customers c ON c.user_id = o.customer_id
     JOIN products p  ON p.product_id = o.product_id
     JOIN users u     ON u.user_id = o.customer_id
     LEFT JOIN labs l       ON l.lab_id = c.lab_id';

// Search-only joins: lab/institute/PI (LEFT -- customers.lab_id/
// supervising_pi_id are both nullable) exist so the search box can
// cover them per CLAUDE.md's non-negotiable "Order search must cover
// ID, product, nuclide, date, and customer/lab/PI/institute" --
// customer/orders.php's own search doesn't need lab/PI/institute since
// that page is already lab-scoped, but this queue spans every lab.
// nuclides is joined for the same search reason even though the Nuclide
// column itself was dropped from the table (the product name already
// carries it, e.g. "[F18]FDG"). lab_product_users is joined so the
// search box can match the order's product user (falling back to the
// placer when none is attached) -- same fallback rule as
// customer/orders.php's search, even though this queue's own displayed
// column stays "Lab / Placed by" for now.
// Dependency order: n needs p, i needs l, pi needs c -- all in base.
$queueSearchJoins =
    '
     JOIN nuclides n  ON n.nuclide_id = p.nuclide_id
     LEFT JOIN institutes i ON i.institute_id = l.institute_id
     LEFT JOIN pis pi       ON pi.pi_id = c.supervising_pi_id
     LEFT JOIN lab_product_users pu ON pu.product_user_id = o.product_user_id';

$queueJoins = $queueBaseJoins . ($queueSearch !== '' ? $queueSearchJoins : '');

// Built without the status condition -- reused for the tab counts (each
// tab's count reflects the current search/fulfillment/date scope, not
// global counts) and then extended with status below for the actual list.
$queueFilterWhere = [];
$queueFilterParams = [];

if ($queueSearch !== '') {
    $queueFilterWhere[] = "(CAST(o.order_id AS CHAR) LIKE ? ESCAPE '\\\\'
                 OR COALESCE(CONCAT(pu.first_name, ' ', pu.last_name), CONCAT(u.first_name, ' ', u.last_name)) LIKE ? ESCAPE '\\\\'
                 OR n.name LIKE ? ESCAPE '\\\\'
                 OR p.name LIKE ? ESCAPE '\\\\'
                 OR l.lab_name LIKE ? ESCAPE '\\\\'
                 OR i.name LIKE ? ESCAPE '\\\\'
                 OR pi.pi_name LIKE ? ESCAPE '\\\\')";
    $like = like_contains($queueSearch);
    array_push($queueFilterParams, $like, $like, $like, $like, $like, $like, $like);
}
if ($queueFulfillment !== '') {
    $queueFilterWhere[] = 'p.delivery_method = ?';
    $queueFilterParams[] = $queueFulfillment;
}
if ($queueFrom !== '') {
    $queueFilterWhere[] = 'o.requested_datetime >= ?';
    $queueFilterParams[] = $queueFrom . ' 00:00:00';
}
if ($queueTo !== '') {
    $queueFilterWhere[] = 'o.requested_datetime <= ?';
    $queueFilterParams[] = $queueTo . ' 23:59:59';
}

$queueFilterWhereSql = where_clause($queueFilterWhere);

// Count-query join set: the tab counts group on o.status alone, so the
// only joins needed are the ones $queueFilterWhereSql references -- the
// full set when searching, products alone for the fulfillment filter,
// bare orders otherwise (date filters use only o. columns). Dropping
// the rest is count-neutral: every INNER join here is on a NOT-NULL FK
// to a PK and every LEFT join targets a PK (schema.sql), so no join
// can add or remove rows.
if ($queueSearch !== '') {
    $queueCountJoins = $queueJoins;
} elseif ($queueFulfillment !== '') {
    $queueCountJoins = 'FROM orders o JOIN products p ON p.product_id = o.product_id';
} else {
    $queueCountJoins = 'FROM orders o';
}

$queueCountsStmt = $pdo->prepare("SELECT o.status, COUNT(*) AS c $queueCountJoins $queueFilterWhereSql GROUP BY o.status");
$queueCountsStmt->execute($queueFilterParams);
$queueStatusCounts = ['pending' => 0, 'accepted' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($queueCountsStmt->fetchAll() as $row) {
    $queueStatusCounts[$row['status']] = (int) $row['c'];
}
$queueAllCount = array_sum($queueStatusCounts);
$queueTotalCount = $queueStatus !== '' ? $queueStatusCounts[$queueStatus] : $queueAllCount;

$queueTabs = [
    ['value' => '',          'label' => 'All',       'count' => $queueAllCount],
    ['value' => 'pending',   'label' => 'Pending',   'count' => $queueStatusCounts['pending']],
    ['value' => 'accepted',  'label' => 'Accepted',  'count' => $queueStatusCounts['accepted']],
    ['value' => 'completed', 'label' => 'Completed', 'count' => $queueStatusCounts['completed']],
    ['value' => 'cancelled', 'label' => 'Cancelled', 'count' => $queueStatusCounts['cancelled']],
];

// pending/accepted (actionable) sort soonest-due first -- "delivery
// timing is the whole game" for a radiotracer department.
// completed/cancelled (retrospective) sort most-recently-finished
// first, since requested_datetime is no longer the operative date once
// an order is done. All (a mixed bag of active and terminal orders) has
// no single meaningful due-date ordering, so it falls back to newest-
// placed-first, matching customer/orders.php's own default. The
// status-dependent sort is deliberate and queue-only: customer/
// orders.php keeps a fixed chronological sort on every tab (it's
// order history, not triage) -- not a bug, don't "align" them.
if (in_array($queueStatus, ['pending', 'accepted'], true)) {
    $queueOrderBy = 'o.requested_datetime ASC';
} elseif (in_array($queueStatus, ['completed', 'cancelled'], true)) {
    $queueOrderBy = 'o.updated_at DESC';
} else {
    $queueOrderBy = 'o.requested_datetime DESC';
}

$queueWhere = $queueFilterWhere;
$queueParams = $queueFilterParams;
if ($queueStatus !== '') {
    $queueWhere[] = 'o.status = ?';
    $queueParams[] = $queueStatus;
}
$queueWhereSql = where_clause($queueWhere);

$queuePagination = paginate($queueTotalCount, $queuePage, $queuePageSize);
$queuePage = $queuePagination['page'];
$queueTotalPages = $queuePagination['totalPages'];
$queueOffset = $queuePagination['offset'];
canonicalize_get(['page' => $queuePage]);

// LIMIT/OFFSET interpolated directly -- both are server-computed ints at
// this point (page size clamped against a fixed option set, offset
// derived from a clamped page number), same convention as
// customer/orders.php.
$queueListStmt = $pdo->prepare(
    "SELECT o.order_id, o.status, o.requested_datetime, o.updated_at, o.chargeable,
            p.name AS product_name, p.delivery_method,
            l.lab_name,
            u.first_name, u.last_name, u.username
     $queueJoins
     $queueWhereSql
     ORDER BY $queueOrderBy, o.order_id DESC
     LIMIT $queueOffset, $queuePageSize"
);
$queueListStmt->execute($queueParams);
$queueOrders = $queueListStmt->fetchAll();

$queueRangeStart = $queuePagination['rangeStart'];
$queueRangeEnd = $queuePagination['rangeEnd'];
// Status DOES count toward $queueHasFilters (unlike an earlier version of
// this page) -- a status tab with zero results is still a filtered-empty
// state, and treating it as "no filters" produced a misleading "no orders
// here" message on e.g. the Accepted tab even when other tabs had orders.
// $queueOtherFiltersActive is tracked separately so the empty-state
// copy/actions below can tell "only the tab is empty" apart from
// "search/fulfillment/date filters are also narrowing it".
$queueOtherFiltersActive = $queueSearch !== '' || $queueFulfillment !== '' || $queueFrom !== '' || $queueTo !== '';
$queueHasFilters = $queueStatus !== '' || $queueOtherFiltersActive;

$pageTitle = 'Order Queue';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../../src/partials/head.php'; ?>
</head>
<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../../src/partials/layout_staff.php'; ?>
        <main class="app-main">
            <div class="page-header">
                <h1>Order Queue</h1>
            </div>

            <nav class="status-tabs" aria-label="Filter by status">
                <?php foreach ($queueTabs as $tab): ?>
                    <a href="<?= e($_SERVER['PHP_SELF'] . build_query(['status' => $tab['value'], 'page' => 1])) ?>" class="status-tabs__link <?= $queueStatus === $tab['value'] ? 'is-active' : '' ?>">
                        <?= e($tab['label']) ?> <span class="status-tabs__count"><?= $tab['count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="table-card">
                <div class="table-card-header">
                    <?php // Explicit Filter-button submit, never live-as-you-type
                          // -- same idiom as customer/orders.php. Status itself
                          // is no longer a field here -- the tabs above are the
                          // status filter now. A GET form submit always resets
                          // to page 1 (no page field present), which is the
                          // right behavior for a changed filter. ?>
                    <form method="get" class="table-card-controls">
                        <input type="hidden" name="status" value="<?= e($queueStatus) ?>">
                        <?php if ($queuePageSize !== DEFAULT_PAGE_SIZE): ?>
                            <input type="hidden" name="page_size" value="<?= e((string) $queuePageSize) ?>">
                        <?php endif; ?>

                        <input type="text" name="q" value="<?= e($queueSearch) ?>" placeholder="Search # / product / nuclide / product user / lab / PI / institute&hellip;">

                        <select name="fulfillment">
                            <option value="">All fulfillment</option>
                            <option value="radiopharmacy" <?= $queueFulfillment === 'radiopharmacy' ? 'selected' : '' ?>><?= e(delivery_method_label('radiopharmacy')) ?></option>
                            <option value="pick_up" <?= $queueFulfillment === 'pick_up' ? 'selected' : '' ?>><?= e(delivery_method_label('pick_up')) ?></option>
                            <option value="direct_delivery" <?= $queueFulfillment === 'direct_delivery' ? 'selected' : '' ?>><?= e(delivery_method_label('direct_delivery')) ?></option>
                        </select>

                        <label for="queue-requested-from" class="sr-only">Requested from</label>
                        <input type="date" name="requested_from" id="queue-requested-from" value="<?= e($queueFrom) ?>" title="Requested from">

                        <label for="queue-requested-to" class="sr-only">Requested to</label>
                        <input type="date" name="requested_to" id="queue-requested-to" value="<?= e($queueTo) ?>" title="Requested to">

                        <button type="submit" class="btn btn--secondary btn--sm">Filter</button>
                    </form>
                </div>

                <?php if (!$queueOrders): ?>
                    <div class="empty-state">
                        <div class="empty-state__icon">
                            <?php if ($queueHasFilters): ?>
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
                        // Three distinct empty states, not two: a status tab
                        // alone (no other filters) gets copy naming that
                        // status ("No accepted orders") rather than the
                        // generic filtered-empty message, since "these
                        // filters" reads oddly when the only thing narrowing
                        // the list is the tab someone clicked.
                        if (!$queueHasFilters) {
                            $queueEmptyTitle = 'No orders here';
                            $queueEmptyHint = 'Orders will show up here once placed.';
                        } elseif ($queueOtherFiltersActive) {
                            $queueEmptyTitle = 'No orders match these filters';
                            $queueEmptyHint = 'Try a different search or clear the filters.';
                        } else {
                            $queueEmptyTitle = 'No ' . $queueStatus . ' orders';
                            $queueEmptyHint = 'Try a different status tab above.';
                        }
                        ?>
                        <div class="empty-state__title"><?= e($queueEmptyTitle) ?></div>
                        <p class="empty-state__hint"><?= e($queueEmptyHint) ?></p>
                        <?php if ($queueOtherFiltersActive): ?>
                            <div class="empty-state__action">
                                <?php // Preserves the active status tab -- only the
                                      // search/fulfillment/date filters clear, same
                                      // convention as customer/orders.php. ?>
                                <a href="<?= e($_SERVER['PHP_SELF'] . build_query(['q' => null, 'fulfillment' => null, 'requested_from' => null, 'requested_to' => null, 'page' => 1])) ?>" class="btn btn--secondary btn--sm">Clear filters</a>
                            </div>
                        <?php elseif ($queueStatus !== ''): ?>
                            <div class="empty-state__action">
                                <a href="<?= e($_SERVER['PHP_SELF'] . build_query(['status' => null, 'page' => 1])) ?>" class="btn btn--secondary btn--sm">View all orders</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Requested</th>
                                    <th>Product</th>
                                    <th>Lab / Placed by</th>
                                    <th>Fulfillment</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($queueOrders as $o): ?>
                                    <tr>
                                        <td class="tabular"><?= (int) $o['order_id'] ?></td>
                                        <td class="tabular nowrap"><?= e(date('M j, Y H:i', strtotime($o['requested_datetime']))) ?></td>
                                        <td><?= e($o['product_name']) ?></td>
                                        <td>
                                            <div><?= e($o['lab_name'] ?? '—') ?></div>
                                            <div class="muted text-sm"><?= e(customer_display_name($o['first_name'], $o['last_name'], $o['username'])) ?></div>
                                        </td>
                                        <td><?= e(delivery_method_label($o['delivery_method'])) ?></td>
                                        <?php // Plain text, always rendered (not a second badge) --
                                              // two stacked pills read badly, and an exception-only
                                              // tag leaves the common case looking blank. Chargeable
                                              // is the default, so it's the muted side; "Not
                                              // chargeable" is the exception that reads at full
                                              // weight. ?>
                                        <td>
                                            <div><span class="badge badge--<?= e($o['status']) ?>"><?= e(ucfirst($o['status'])) ?></span></div>
                                            <?php if ($o['chargeable']): ?>
                                                <div class="muted text-sm">Chargeable</div>
                                            <?php else: ?>
                                                <div class="text-sm">Not chargeable</div>
                                            <?php endif; ?>
                                        </td>
                                        <td><a href="/staff/order_detail.php?id=<?= (int) $o['order_id'] ?>" class="table-action">View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php
                    $tablePagination = [
                        'idPrefix' => 'queue-',
                        'itemLabel' => 'Orders',
                        'hiddenFields' => [
                            'q' => $queueSearch,
                            'status' => $queueStatus,
                            'fulfillment' => $queueFulfillment,
                            'requested_from' => $queueFrom,
                            'requested_to' => $queueTo,
                        ],
                        'page' => $queuePage,
                        'totalPages' => $queueTotalPages,
                        'pageSize' => $queuePageSize,
                        'rangeStart' => $queueRangeStart,
                        'rangeEnd' => $queueRangeEnd,
                        'totalCount' => $queueTotalCount,
                    ];
                    include __DIR__ . '/../../src/partials/table_pagination.php';
                    ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
