<?php
require_once __DIR__ . '/../src/db.php';

// ==============================================================================
// PET Ordering System - Database Stress Test Generator
// ==============================================================================

// 1. Database Configuration
$pdo = get_db();

// 2. Stress Test Settings
$records_to_generate = 10; // Change this number to generate more/less orders

echo "Starting Stress Test Generation for $records_to_generate orders...\n";

try {
    // ==========================================================================
    // STEP A: Fetch Valid Foreign Keys
    // We must pick existing IDs to prevent Foreign Key Constraint failures.
    // ==========================================================================
    
    // Fetch valid Customers (users who are customers)
    $stmt = $pdo->query("SELECT user_id FROM customers");
    $customers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Fetch valid Products
    $stmt = $pdo->query("SELECT product_id FROM products WHERE active = 1");
    $products = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Fetch valid Locations
    $stmt = $pdo->query("SELECT location_id FROM lab_delivery_locations WHERE active = 1");
    $locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Fetch valid Product Users (Patients/Recipients)
    $stmt = $pdo->query("SELECT product_user_id FROM lab_product_users WHERE active = 1");
    $product_users = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Safety Check: Ensure we have the minimum required data to build an order
    if (empty($customers) || empty($products)) {
        die("Error: Your database must have at least one Customer and one Product before generating test orders.");
    }

    // ==========================================================================
    // STEP B: Prepare the Insert Statements
    // Preparing statements outside the loop makes execution extremely fast.
    // ==========================================================================
    
    $insertOrder = $pdo->prepare("
        INSERT INTO orders (
            customer_id, product_id, location_id, product_user_id, 
            activity_mci, requested_datetime, notes, status, chargeable
        ) VALUES (
            :customer_id, :product_id, :location_id, :product_user_id, 
            :activity_mci, :requested_datetime, :notes, :status, :chargeable
        )
    ");

    $insertAudit = $pdo->prepare("
        INSERT INTO order_audit_log (
            order_id, status_from, status_to, changed_by_user_id
        ) VALUES (
            :order_id, NULL, 'pending', :changed_by_user_id
        )
    ");

    // ==========================================================================
    // STEP C: Begin Transaction and Generate Data
    // Wrapping the loop in a transaction means the DB saves it all in one chunk.
    // ==========================================================================
    
    $pdo->beginTransaction();

    $statuses = ['pending', 'accepted', 'completed', 'cancelled'];
    $sample_notes = [
        "Please rush if possible.", 
        "Standard morning delivery.", 
        "Call upon arrival.", 
        null, 
        "Patient arriving early.",
        "Requires specific shielding."
    ];

    for ($i = 0; $i < $records_to_generate; $i++) {
        
        // 1. Randomize Data
        $customer_id = $customers[array_rand($customers)];
        $product_id = $products[array_rand($products)];
        
        // Sometimes locations and product_users are NULL in your schema
        $location_id = (rand(0, 100) > 20 && !empty($locations)) ? $locations[array_rand($locations)] : null;
        $product_user_id = (rand(0, 100) > 30 && !empty($product_users)) ? $product_users[array_rand($product_users)] : null;
        
        $activity_mci = mt_rand(1000, 150000) / 1000; // Random decimal between 1.000 and 150.000
        $chargeable = (rand(0, 100) > 10) ? 1 : 0; // 90% chance to be chargeable
        $status = $statuses[array_rand($statuses)];
        #$notes = $sample_notes[array_rand($sample_notes)];
        $notes = "Auto-genereated by internal test";
        
        // Generate a random requested date within the last 30 days to next 30 days
        $random_timestamp = time() + rand(-2592000, 2592000); 
        $requested_datetime = date('Y-m-d H:i:s', $random_timestamp);

        // 2. Execute Order Insert
        $insertOrder->execute([
            ':customer_id' => $customer_id,
            ':product_id' => $product_id,
            ':location_id' => $location_id,
            ':product_user_id' => $product_user_id,
            ':activity_mci' => $activity_mci,
            ':requested_datetime' => $requested_datetime,
            ':notes' => $notes,
            ':status' => $status,
            ':chargeable' => $chargeable
        ]);

        $order_id = $pdo->lastInsertId();

        // 3. Execute Audit Log Insert (Initial Creation Event)
        // Per your schema comments, creation writes a row with status_from = NULL, status_to = 'pending'
        $insertAudit->execute([
            ':order_id' => $order_id,
            ':changed_by_user_id' => $customer_id // Assuming the customer created it
        ]);
    }

    // Commit all records to the database at once
    $pdo->commit();

    echo "Success! Inserted $records_to_generate orders and their audit logs into the database.\n";

} catch (\Exception $e) {
    // If anything goes wrong, rollback the transaction so we don't get partial/corrupted data
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "An error occurred during generation: " . $e->getMessage();
}
?>