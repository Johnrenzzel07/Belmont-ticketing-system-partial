<?php
/**
 * Reply Templates API
 * Actions: list, create, update, delete
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
isStaff() || jsonResponse(false, 'Access denied.', [], 403);

header('Content-Type: application/json');

$pdo    = db();
$user   = currentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ---- List all global + own templates ----
    case 'list':
        $rows = $pdo->prepare(
            "SELECT t.id, t.title, t.body, t.is_global, u.name AS author
             FROM reply_templates t
             JOIN users u ON u.id = t.created_by
             WHERE t.is_global = 1 OR t.created_by = ?
             ORDER BY t.is_global DESC, t.title ASC"
        );
        $rows->execute([$user['id']]);
        jsonResponse(true, 'OK', ['templates' => $rows->fetchAll()]);
        break;

    // ---- Create ----
    case 'create':
        validateCsrf();
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body']  ?? '');
        if (!$title || !$body) jsonResponse(false, 'Title and body are required.');
        $isGlobal = isAdmin() ? (int)($_POST['is_global'] ?? 1) : 0;

        $pdo->prepare(
            "INSERT INTO reply_templates (created_by, title, body, is_global) VALUES (?,?,?,?)"
        )->execute([$user['id'], $title, $body, $isGlobal]);
        jsonResponse(true, 'Template saved.', ['id' => (int)$pdo->lastInsertId()]);
        break;

    // ---- Update ----
    case 'update':
        validateCsrf();
        $id    = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body']  ?? '');
        if (!$id || !$title || !$body) jsonResponse(false, 'Invalid data.');

        // Only creator or admin can edit
        $stmt = $pdo->prepare("SELECT created_by FROM reply_templates WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || ($row['created_by'] != $user['id'] && !isAdmin())) {
            jsonResponse(false, 'Permission denied.');
        }
        $isGlobal = isAdmin() ? (int)($_POST['is_global'] ?? 1) : 0;
        $pdo->prepare(
            "UPDATE reply_templates SET title=?, body=?, is_global=? WHERE id=?"
        )->execute([$title, $body, $isGlobal, $id]);
        jsonResponse(true, 'Template updated.');
        break;

    // ---- Delete ----
    case 'delete':
        validateCsrf();
        $id   = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT created_by FROM reply_templates WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || ($row['created_by'] != $user['id'] && !isAdmin())) {
            jsonResponse(false, 'Permission denied.');
        }
        $pdo->prepare("DELETE FROM reply_templates WHERE id = ?")->execute([$id]);
        jsonResponse(true, 'Template deleted.');
        break;

    default:
        jsonResponse(false, 'Unknown action.');
}
