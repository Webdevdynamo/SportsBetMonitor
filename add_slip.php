<?php
/**
 * Gridiron Giga-Brains: Slip Entry Utility
 * Operational optimization: Direct JSON injection
 */

header('Content-Type: application/json');

$slips_file = __DIR__ . '/data/slips.json';

// Ensure the request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method']);
    exit;
}

// Get the raw POST data
$json_input = file_get_contents('php://input');
$new_slip = json_decode($json_input, true);

if (!$new_slip || !isset($new_slip['legs'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Slip Data']);
    exit;
}

// 1. Read existing slips
$current_slips = [];
if (file_exists($slips_file)) {
    $current_slips = json_decode(file_get_contents($slips_file), true) ?? [];
}

// 2. Generate a unique ID (Timestamp + Random)
$new_slip['slip_id'] = strtoupper(substr(uniqid(), -5));
$new_slip['created_at'] = date('Y-m-d H:i:s');

// 3. Append and Save
$current_slips[] = $new_slip;

if (file_put_contents($slips_file, json_encode($current_slips, JSON_PRETTY_PRINT))) {
    echo json_encode(['status' => 'success', 'slip_id' => $new_slip['slip_id']]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'FileSystem Write Failed']);
}