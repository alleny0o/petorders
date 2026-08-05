<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('admin');

$pdo = get_db();

// Lookups for the filter selects. Institutes/nuclides list every row
// (inactive suffixed below), matching Product's existing unfiltered
// behavior -- this report covers historical orders, which can reference
// an institute/nuclide that's since been deactivated.
$institutes = $pdo->query('SELECT institute_id, name, shorthand_name, active FROM institutes ORDER BY shorthand_name')->fetchAll(PDO::FETCH_ASSOC);
$nuclides = $pdo->query('SELECT nuclide_id, name, active FROM nuclides ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query('SELECT product_id, name FROM products ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Reports';
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
                <div>
                    <h1 class="mb-0">Order Reports</h1>
                    <span class="text-sm muted">Export past order data as a CSV</span>
                </div>
            </div>

            <div class="card">
                <form method="GET" action="export_csv.php" id="report-form" novalidate data-no-loading-guard>

                    <div class="alert alert--error" data-error-banner-for="report-form" hidden>Please provide a valid date range.</div>

                    <div class="field-row">
                        <div class="field">
                            <label for="start_date">From Date <span class="required-mark">*</span></label>
                            <input type="date" name="start_date" id="start_date" required>
                        </div>
                        <div class="field">
                            <label for="end_date">To Date <span class="required-mark">*</span></label>
                            <input type="date" name="end_date" id="end_date" required>
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label for="filter-institute">Institute</label>
                            <select name="institute" id="filter-institute">
                                <option value="">All Institutes</option>
                                <?php foreach ($institutes as $inst): ?>
                                    <option value="<?= (int) $inst['institute_id'] ?>" title="<?= e($inst['name']) ?>">
                                        <?= e($inst['shorthand_name']) ?><?= $inst['active'] ? '' : ' (inactive)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="filter-nuclide">Nuclide</label>
                            <select name="nuclide" id="filter-nuclide">
                                <option value="">All Nuclides</option>
                                <?php foreach ($nuclides as $nuc): ?>
                                    <option value="<?= (int) $nuc['nuclide_id'] ?>">
                                        <?= e($nuc['name']) ?><?= $nuc['active'] ? '' : ' (inactive)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label for="filter-product">Product</label>
                            <select name="product" id="filter-product">
                                <option value="">All Products</option>
                                <?php foreach ($products as $prod): ?>
                                    <option value="<?= (int) $prod['product_id'] ?>">
                                        <?= e($prod['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="filter-status">Status</label>
                            <select name="status" id="filter-status">
                                <option value="">All Statuses</option>
                                <option value="pending">Pending</option>
                                <option value="accepted">Accepted</option>
                                <option value="completed">Completed</option>
                                <option value="canceled">Canceled</option>
                            </select>
                        </div>
                    </div>

                    <div class="field">
                        <label for="filter-chargeable">Chargeable</label>
                        <select name="chargeable" id="filter-chargeable">
                            <option value="">All</option>
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="btn btn--primary">Download CSV Report</button>
                        <button type="button" class="btn btn--secondary" id="reset-dates">Reset Filters</button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
