<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('admin');

$pdo = get_db();

// Filters from the query string -- '' is the "no filter" sentinel
// throughout this codebase (see admin/products.php), not the string 'all'.
// Id-typed filters are validated with ctype_digit() before binding, same
// convention as admin/products.php's own filter parsing.
$start_date = $_GET['start_date'] ?? null;
$end_date   = $_GET['end_date'] ?? null;
$status     = in_array($_GET['status'] ?? '', ['pending', 'accepted', 'completed', 'cancelled'], true) ? $_GET['status'] : '';
$institute  = ctype_digit((string) ($_GET['institute'] ?? '')) ? (int) $_GET['institute'] : 0;
$nuclide    = ctype_digit((string) ($_GET['nuclide'] ?? '')) ? (int) $_GET['nuclide'] : 0;
$product    = ctype_digit((string) ($_GET['product'] ?? '')) ? (int) $_GET['product'] : 0;
$chargeable = in_array($_GET['chargeable'] ?? '', ['0', '1'], true) ? $_GET['chargeable'] : '';

$dateRegex = '/^\d{4}-\d{2}-\d{2}$/';
$startInvalid = !$start_date || !preg_match($dateRegex, $start_date);
$endInvalid   = !$end_date || !preg_match($dateRegex, $end_date);
if ($startInvalid || $endInvalid) {
    // No JSON branch: nothing fetches this endpoint with an AJAX Accept
    // header -- the reports form validates client-side (initReportsForm(),
    // script.js) and submits a plain native GET.
    http_response_code(400);
    die('Please provide a valid date range.');
}

$start_datetime = $start_date . " 00:00:00";
$end_datetime   = $end_date . " 23:59:59";

// Download headers + output stream open BEFORE the query runs: rows are
// streamed to the client as they arrive, so memory stays O(1) no matter
// how large the date range is.
$filename = "pet_orders_report_" . date('Y-m-d') . ".csv";

header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

$output = fopen('php://output', 'w');

fputcsv($output, ['Order ID', 'Order Date (Y/M/D)', 'Institute', 'Nuclide', 'Product', 'Status', 'Chargeable', 'Cancellation Reason']);

// Base query joins through the current schema: orders -> customers
// (customer_id, not created_by) -> labs -> institutes for the institute
// name, and orders -> products -> nuclides (nuclide lives on nuclides now,
// joined via products.nuclide_id) for the nuclide/product names.
$sql = "SELECT
            o.order_id,
            o.created_at,
            o.status,
            i.name AS institute_name,
            n.name AS nuclide_name,
            p.name AS product_name,
            o.chargeable,
            o.cancellation_reason
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.user_id
        LEFT JOIN labs l ON c.lab_id = l.lab_id
        LEFT JOIN institutes i ON l.institute_id = i.institute_id
        LEFT JOIN products p ON o.product_id = p.product_id
        LEFT JOIN nuclides n ON p.nuclide_id = n.nuclide_id
        WHERE o.created_at BETWEEN :start_date AND :end_date";

// Dynamically append filters if they're set
if ($status !== '') {
    $sql .= " AND o.status = :status";
}
if ($institute > 0) {
    $sql .= " AND i.institute_id = :institute";
}
if ($nuclide > 0) {
    $sql .= " AND n.nuclide_id = :nuclide";
}
if ($product > 0) {
    $sql .= " AND p.product_id = :product";
}
if ($chargeable !== '') {
    $sql .= " AND o.chargeable = :chargeable";
}

$sql .= " ORDER BY o.created_at DESC";

// Unbuffered so mysqlnd hands rows over as they arrive instead of
// materializing the whole result set first. Only safe here because this
// is the script's last query and it exit()s -- an unbuffered handle can't
// run another query until it's fully consumed.
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

// Prepare and bind parameters
$stmt = $pdo->prepare($sql);
$stmt->bindParam(':start_date', $start_datetime);
$stmt->bindParam(':end_date', $end_datetime);

// Bind the optional parameters conditionally
if ($status !== '')     $stmt->bindParam(':status', $status);
if ($institute > 0)     $stmt->bindParam(':institute', $institute, PDO::PARAM_INT);
if ($nuclide > 0)       $stmt->bindParam(':nuclide', $nuclide, PDO::PARAM_INT);
if ($product > 0)       $stmt->bindParam(':product', $product, PDO::PARAM_INT);
if ($chargeable !== '') $stmt->bindParam(':chargeable', $chargeable);

$stmt->execute();

// Stream row-by-row: a fetchAll() here held the entire result set in
// memory (twice, with the buffered driver) and fataled on large ranges
// before a single byte reached the client.
$rowsWritten = 0;
while ($row = $stmt->fetch()) {
    // Format the date to Y/M/D exactly as requested
    $formatted_date = date('Y/m/d', strtotime($row['created_at']));

    // Convert the boolean/tinyint to Yes/No for easier reading
    $chargeable_text = ($row['chargeable'] == 1) ? 'Yes' : 'No';

    // Output the row
    fputcsv($output, [
        $row['order_id'],
        $formatted_date,
        csv_safe($row['institute_name'] ?? 'N/A'), // Fallback if no location found
        csv_safe($row['nuclide_name'] ?? 'N/A'),   // Fallback if no nuclide found
        csv_safe($row['product_name'] ?? 'N/A'),
        ucfirst($row['status']),
        $chargeable_text,
        csv_safe($row['cancellation_reason'])
    ]);

    if (++$rowsWritten % 500 === 0) {
        flush();
    }
}

// Close the file pointer
fclose($output);
exit();
?>