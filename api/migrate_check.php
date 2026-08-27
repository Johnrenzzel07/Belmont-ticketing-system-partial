<?php
/**
 * AJAX: Check if a legacy ticket number was already migrated.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/migration_helpers.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['exists' => false, 'error' => 'Forbidden']);
    exit;
}

$pdo = db();
ensureLegacyMigrationSchema($pdo);

$legacy = normalizeLegacyTicketNumber($_GET['legacy'] ?? '');
if ($legacy === '') {
    echo json_encode(['exists' => false]);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, ticket_code FROM tickets WHERE legacy_ticket_number = ? LIMIT 1'
);
$stmt->execute([$legacy]);
$row = $stmt->fetch();

echo json_encode([
    'exists'      => (bool)$row,
    'ticket_code' => $row['ticket_code'] ?? null,
    'ticket_id'   => $row ? (int)$row['id'] : null,
]);
