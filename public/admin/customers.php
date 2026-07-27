<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('admin');

$pdo = get_db();

$q = trim($_GET['q'] ?? '');
$instituteId = $_GET['institute_id'] ?? '';
$labId = $_GET['lab_id'] ?? '';
$status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$pageSize = in_array((int) ($_GET['page_size'] ?? 0), PAGE_SIZE_OPTIONS, true)
    ? (int) $_GET['page_size'] : DEFAULT_PAGE_SIZE;

// Canonicalize so every link built via build_query() below (tabs,
// pagination) carries the real applied values forward -- same convention
// as staff/orders.php's status/page_size canonicalization.
canonicalize_get([
    'status' => $status,
    'page_size' => $pageSize,
]);

$where = [];
$params = [];

if ($q !== '') {
    // Escape LIKE wildcards in the search term itself so a customer
    // searching for a literal "%" or "_" doesn't get wildcard behavior.
    $like = like_contains($q);
    $where[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? ESCAPE '\\\\' OR u.username LIKE ? ESCAPE '\\\\')";
    $params[] = $like;
    $params[] = $like;
}
if ($instituteId !== '' && ctype_digit((string) $instituteId)) {
    $where[] = 'l.institute_id = ?';
    $params[] = (int) $instituteId;
}
if ($labId !== '' && ctype_digit((string) $labId)) {
    $where[] = 'c.lab_id = ?';
    $params[] = (int) $labId;
}

$whereSql = where_clause($where);

// Built without the status condition -- reused for the tab counts (each
// tab's count reflects the current search/institute/lab scope, not
// global counts) and then extended with a status condition below for the
// actual list -- same pattern as staff/orders.php's $queueStatusCounts.
$countsStmt = $pdo->prepare(
    "SELECT u.active, COUNT(*) AS c
     FROM customers c
     JOIN users u ON u.user_id = c.user_id
     LEFT JOIN labs l ON l.lab_id = c.lab_id
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
// Keep $_GET in sync with the clamped page so build_query() never echoes
// back an out-of-range page number.
canonicalize_get(['page' => $page]);

// LIMIT/OFFSET are interpolated directly rather than bound: both are
// fully server-computed ints at this point (page size is clamped against
// a fixed option set, offset is derived from a clamped, ctype_digit-checked
// page number), same convention as PASSWORD_HISTORY_LIMIT in src/auth.php.
$listStmt = $pdo->prepare(
    "SELECT u.user_id, u.username, u.active,
            u.first_name, u.last_name,
            l.lab_name, i.name AS institute_name, i.shorthand_name AS institute_shorthand, p.pi_name
     FROM customers c
     JOIN users u ON u.user_id = c.user_id
     LEFT JOIN labs l ON l.lab_id = c.lab_id
     LEFT JOIN institutes i ON i.institute_id = l.institute_id
     LEFT JOIN pis p ON p.pi_id = c.supervising_pi_id
     $listWhereSql
     ORDER BY u.last_name, u.first_name
     LIMIT $offset, $pageSize"
);
$listStmt->execute($listParams);
$customers = $listStmt->fetchAll();

// Filter dropdowns intentionally include inactive institutes/labs too --
// this is a search context, not data entry, and a customer can belong to
// a lab that's since gone inactive; hiding it would make them unfindable.
$institutes = $pdo->query('SELECT institute_id, name, shorthand_name FROM institutes ORDER BY shorthand_name')->fetchAll();
$labs = $pdo->query('SELECT lab_id, institute_id, lab_name FROM labs ORDER BY lab_name')->fetchAll();

$rangeStart = $pagination['rangeStart'];
$rangeEnd = $pagination['rangeEnd'];
$hasFilters = $q !== '' || $instituteId !== '' || $labId !== '' || $status !== '';

$pageTitle = 'Customers';
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
                <h1>Customers</h1>
            </div>

            <nav class="status-tabs" aria-label="Filter by status">
                <?php foreach ($statusTabs as $tab): ?>
                    <a href="<?= e($_SERVER['PHP_SELF'] . build_query(['status' => $tab['value'], 'page' => 1])) ?>" class="status-tabs__link <?= $status === $tab['value'] ? 'is-active' : '' ?>">
                        <?= e($tab['label']) ?> <span class="status-tabs__count"><?= $tab['count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="table-card">
                <div class="table-card-header">
                    <?php // Status is no longer a field here -- the tabs above
                          // are the status filter now, same as staff/orders.php. ?>
                    <form method="get" class="table-card-controls">
                        <input type="hidden" name="status" value="<?= e($status) ?>">
                        <?php if ($pageSize !== DEFAULT_PAGE_SIZE): ?>
                            <input type="hidden" name="page_size" value="<?= e((string) $pageSize) ?>">
                        <?php endif; ?>

                        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search name or email&hellip;">

                        <select name="institute_id" id="filter_institute_id">
                            <option value="">All institutes</option>
                            <?php foreach ($institutes as $institute): ?>
                                <option value="<?= (int) $institute['institute_id'] ?>" title="<?= e($institute['name']) ?>" <?= (string) $institute['institute_id'] === (string) $instituteId ? 'selected' : '' ?>><?= e($institute['shorthand_name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select name="lab_id" id="filter_lab_id">
                            <option value="">All labs</option>
                            <?php foreach ($labs as $lab): ?>
                                <option value="<?= (int) $lab['lab_id'] ?>" data-institute-id="<?= (int) $lab['institute_id'] ?>" <?= (string) $lab['lab_id'] === (string) $labId ? 'selected' : '' ?>><?= e($lab['lab_name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="btn btn--secondary btn--sm">Filter</button>
                    </form>
                </div>

                <?php if (!$customers): ?>
                    <div class="empty-state">
                        <div class="empty-state__icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="10" cy="10" r="7"></circle>
                                <line x1="21" y1="21" x2="15" y2="15"></line>
                            </svg>
                        </div>
                        <div class="empty-state__title"><?= $hasFilters ? 'No customers match these filters' : 'No customers yet' ?></div>
                        <p class="empty-state__hint"><?= $hasFilters ? 'Try a different search or clear the filters.' : 'Customers appear here once their registration requests are approved.' ?></p>
                        <div class="empty-state__action">
                            <?php if ($hasFilters): ?>
                                <a href="/admin/customers.php" class="btn btn--secondary btn--sm">Clear filters</a>
                            <?php else: ?>
                                <a href="/admin/registrations.php" class="btn btn--primary btn--sm">Review Registrations</a>
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
                                    <th>Institute</th>
                                    <th>Lab</th>
                                    <th>PI</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $c): ?>
                                    <tr>
                                        <td><?= e($c['first_name'] . ' ' . $c['last_name']) ?></td>
                                        <td><?= e($c['username']) ?></td>
                                        <td><?= e($c['institute_shorthand'] ?? '—') ?><?= $c['institute_shorthand'] !== null ? '<div class="text-sm muted">' . e($c['institute_name']) . '</div>' : '' ?></td>
                                        <td><?= e($c['lab_name'] ?? '—') ?></td>
                                        <td><?= e($c['pi_name'] ?? '—') ?></td>
                                        <td><span class="badge badge--<?= $c['active'] ? 'active' : 'inactive' ?>"><?= $c['active'] ? 'Active' : 'Inactive' ?></span></td>
                                        <td><a href="/admin/customer_detail.php?id=<?= (int) $c['user_id'] ?>" class="table-action">View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php
                    $tablePagination = [
                        'idPrefix' => 'customers-',
                        'itemLabel' => 'Customers',
                        'hiddenFields' => [
                            'q' => $q,
                            'institute_id' => (string) $instituteId,
                            'lab_id' => (string) $labId,
                            'status' => $status,
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
        </main>
    </div>
</body>
<script>
(function () {
  var instituteSelect = document.getElementById('filter_institute_id');
  var labSelect = document.getElementById('filter_lab_id');
  if (!instituteSelect || !labSelect) return;

  var labOptions = Array.prototype.slice.call(labSelect.querySelectorAll('option[data-institute-id]'));

  function filterLabs() {
    var instituteId = instituteSelect.value;
    labOptions.forEach(function (opt) {
      var matches = !instituteId || opt.dataset.instituteId === instituteId;
      opt.hidden = !matches;
      opt.disabled = !matches;
    });
    if (labSelect.selectedOptions[0] && labSelect.selectedOptions[0].hidden) {
      labSelect.value = '';
    }
  }

  instituteSelect.addEventListener('change', filterLabs);
  filterLabs();
})();
</script>
</html>
