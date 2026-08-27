<?php
/**
 * Legacy ticket migration helpers (osTicket → Belmont Helpdesk)
 */

/**
 * Apply migration 008 columns if they are not present yet.
 */
function ensureLegacyMigrationSchema(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $col = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'legacy_ticket_number'")->fetch();
        if ($col) {
            $ready = true;
            return true;
        }

        $pdo->exec(
            "ALTER TABLE `tickets`
             ADD COLUMN `legacy_ticket_number` VARCHAR(20) DEFAULT NULL AFTER `ticket_code`,
             ADD COLUMN `is_legacy`              TINYINT(1) NOT NULL DEFAULT 0 AFTER `legacy_ticket_number`,
             ADD COLUMN `migrated_by`            INT UNSIGNED DEFAULT NULL AFTER `is_legacy`,
             ADD COLUMN `migrated_at`            DATETIME DEFAULT NULL AFTER `migrated_by`,
             ADD UNIQUE KEY `uq_legacy_ticket_number` (`legacy_ticket_number`),
             ADD KEY `idx_ticket_legacy` (`is_legacy`)"
        );

        try {
            $pdo->exec(
                "ALTER TABLE `tickets`
                 ADD CONSTRAINT `fk_ticket_migrated_by`
                 FOREIGN KEY (`migrated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL"
            );
        } catch (Exception $e) {
            // Non-fatal on hosts that already have the FK or cannot add it
        }

        $ready = true;
        return true;
    } catch (Exception $e) {
        $ready = false;
        return false;
    }
}

/**
 * Normalize old ticket numbers (#002469, 002469, etc.)
 */
function normalizeLegacyTicketNumber(string $raw): string
{
    $num = preg_replace('/[^0-9]/', '', $raw) ?? '';
    return ltrim($num, '0') ?: '0';
}

/**
 * Parse datetime-local or date input into MySQL datetime.
 */
function parseLegacyDateTime(?string $value, bool $endOfDay = false): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
        return date('Y-m-d H:i:s', strtotime($value));
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $endOfDay ? $value . ' 23:59:59' : $value . ' 00:00:00';
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/**
 * Check whether a legacy ticket number was already imported.
 */
function legacyTicketExists(PDO $pdo, string $legacyNumber, ?int $excludeId = null): bool
{
    $normalized = normalizeLegacyTicketNumber($legacyNumber);
    if ($normalized === '') {
        return false;
    }

    $sql = 'SELECT id FROM tickets WHERE legacy_ticket_number = ?';
    $params = [$normalized];
    if ($excludeId) {
        $sql .= ' AND id != ?';
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetch();
}

/**
 * Extract an uploaded file list from a standard or nested $_FILES entry.
 *
 * @param array|null $filesEntry e.g. $_FILES['ticket_files'] or $_FILES['thread_files']
 * @param int|string|null $subIndex index or sequence position if nested (e.g. 0, 1, 2 for thread rows)
 * @return array<int, array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function extractUploadedFileList(?array $filesEntry, $subIndex = null): array
{
    if (empty($filesEntry) || empty($filesEntry['name'])) {
        return [];
    }

    $results = [];

    if ($subIndex !== null) {
        $names = null;
        $types = null;
        $tmps  = null;
        $errs  = null;
        $sizes = null;

        // Try exact key first
        if (isset($filesEntry['name'][$subIndex])) {
            $names = $filesEntry['name'][$subIndex];
            $types = $filesEntry['type'][$subIndex] ?? [];
            $tmps  = $filesEntry['tmp_name'][$subIndex] ?? [];
            $errs  = $filesEntry['error'][$subIndex] ?? [];
            $sizes = $filesEntry['size'][$subIndex] ?? [];
        } elseif (is_numeric($subIndex)) {
            // Fallback to positional index if keys were re-indexed or gapped
            $nameVals = array_values($filesEntry['name']);
            $pos = (int)$subIndex;
            if (isset($nameVals[$pos])) {
                $names = $nameVals[$pos];
                $typeVals = isset($filesEntry['type']) ? array_values($filesEntry['type']) : [];
                $tmpVals  = isset($filesEntry['tmp_name']) ? array_values($filesEntry['tmp_name']) : [];
                $errVals  = isset($filesEntry['error']) ? array_values($filesEntry['error']) : [];
                $sizeVals = isset($filesEntry['size']) ? array_values($filesEntry['size']) : [];
                $types = $typeVals[$pos] ?? [];
                $tmps  = $tmpVals[$pos] ?? [];
                $errs  = $errVals[$pos] ?? [];
                $sizes = $sizeVals[$pos] ?? [];
            }
        }

        if ($names === null) {
            return [];
        }

        if (is_array($names)) {
            foreach ($names as $k => $name) {
                $err = $errs[$k] ?? UPLOAD_ERR_NO_FILE;
                $tmp = $tmps[$k] ?? '';
                if ($err === UPLOAD_ERR_OK && !empty($name) && !empty($tmp)) {
                    $results[] = [
                        'name'     => $name,
                        'type'     => $types[$k] ?? 'application/octet-stream',
                        'tmp_name' => $tmp,
                        'error'    => $err,
                        'size'     => (int)($sizes[$k] ?? 0),
                    ];
                }
            }
        } elseif (($errs ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && !empty($names) && !empty($tmps)) {
            $results[] = [
                'name'     => $names,
                'type'     => is_string($types) ? $types : 'application/octet-stream',
                'tmp_name' => is_string($tmps) ? $tmps : '',
                'error'    => is_int($errs) ? $errs : UPLOAD_ERR_OK,
                'size'     => is_numeric($sizes) ? (int)$sizes : 0,
            ];
        }
        return $results;
    }

    // Top-level files array (e.g. ticket_files[])
    if (is_array($filesEntry['name'])) {
        foreach ($filesEntry['name'] as $k => $name) {
            $err = $filesEntry['error'][$k] ?? UPLOAD_ERR_NO_FILE;
            $tmp = $filesEntry['tmp_name'][$k] ?? '';
            if ($err === UPLOAD_ERR_OK && !empty($name) && !empty($tmp)) {
                $results[] = [
                    'name'     => $name,
                    'type'     => $filesEntry['type'][$k] ?? 'application/octet-stream',
                    'tmp_name' => $tmp,
                    'error'    => $err,
                    'size'     => (int)($filesEntry['size'][$k] ?? 0),
                ];
            }
        }
    } elseif (($filesEntry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && !empty($filesEntry['name']) && !empty($filesEntry['tmp_name'])) {
        $results[] = [
            'name'     => $filesEntry['name'],
            'type'     => $filesEntry['type'] ?? 'application/octet-stream',
            'tmp_name' => $filesEntry['tmp_name'],
            'error'    => (int)$filesEntry['error'],
            'size'     => (int)$filesEntry['size'],
        ];
    }

    return $results;
}

/**
 * Save an uploaded file and create a record in `attachments`.
 *
 * @param PDO $pdo
 * @param int $ticketId
 * @param int|null $replyId
 * @param int $userId
 * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
 * @return int|null Inserted attachment ID or null
 */
function saveLegacyAttachmentRecord(
    PDO $pdo,
    int $ticketId,
    ?int $replyId,
    int $userId,
    array $file
): ?int {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    $maxSize = defined('UPLOAD_MAX_SIZE') ? UPLOAD_MAX_SIZE : (10 * 1024 * 1024);
    if ($file['size'] > $maxSize || $file['size'] <= 0) {
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg',
        'pdf',
        'doc', 'docx', 'rtf',
        'xls', 'xlsx', 'csv',
        'ppt', 'pptx',
        'txt', 'zip', 'rar', '7z', 'tar', 'gz'
    ];

    if (!in_array($ext, $allowedExts, true)) {
        return null;
    }

    $mime = $file['type'] ?? 'application/octet-stream';
    if ($mime === 'application/octet-stream' || empty($mime)) {
        $mimeMap = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'bmp'  => 'image/bmp',
            'svg'  => 'image/svg+xml',
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv'  => 'text/csv',
            'txt'  => 'text/plain',
            'zip'  => 'application/zip',
            'rar'  => 'application/x-rar-compressed',
            '7z'   => 'application/x-7z-compressed',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';
    }

    $uploadDir = rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads/'), '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $targetPath = $uploadDir . $stored;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $stmt = $pdo->prepare(
            "INSERT INTO attachments (ticket_id, reply_id, user_id, filename, stored_name, mime_type, file_size)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $ticketId,
            $replyId,
            $userId,
            $file['name'],
            $stored,
            $mime,
            $file['size'],
        ]);
        return (int)$pdo->lastInsertId();
    }

    return null;
}

/**
 * Normalize conversation rows posted from the migration form.
 *
 * @param array<int, array<string, mixed>> $raw
 * @return array<int, array{user_id:int,message:string,created_at:string,is_internal:int,files:array}>
 */
function normalizeThreadMessages(
    array $raw,
    int $defaultUserId,
    int $fallbackStaffId,
    string $defaultCreatedAt
): array {
    $messages = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $message = trim((string)($row['message'] ?? ''));
        if ($message === '') {
            continue;
        }
        $userId = (int)($row['user_id'] ?? 0);
        if (!$userId) {
            $userId = !empty($row['is_staff']) ? $fallbackStaffId : $defaultUserId;
        }
        if (!$userId) {
            $userId = $defaultUserId;
        }
        $messages[] = [
            'user_id'     => $userId,
            'message'     => $message,
            'created_at'  => parseLegacyDateTime($row['created_at'] ?? '') ?: $defaultCreatedAt,
            'is_internal' => !empty($row['is_internal']) ? 1 : 0,
            'files'       => !empty($row['files']) && is_array($row['files']) ? $row['files'] : [],
        ];
    }

    usort($messages, static fn($a, $b) => strcmp($a['created_at'], $b['created_at']));

    return $messages;
}

/**
 * Parse a pasted osTicket-style thread into message rows.
 * Blocks look like: [2024-05-24 10:37] Author name
 *
 * @return array<int, array{author:string,created_at:string,message:string}>
 */
function parsePastedThread(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') {
        return [];
    }

    $blocks = preg_split('/\n(?=\[[^\]]+\])/', $text) ?: [];
    $messages = [];
    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }
        if (preg_match('/^\[(.+?)\]\s*(.*?)(?:\n([\s\S]*))?$/', $block, $m)) {
            $messages[] = [
                'created_at' => trim($m[1]),
                'author'     => trim($m[2]),
                'message'    => trim($m[3] ?? ''),
            ];
        }
    }

    return $messages;
}

/**
 * Import one legacy ticket from validated form data.
 *
 * @return array{ticket_id:int,ticket_code:string}
 */
function importLegacyTicket(PDO $pdo, array $data, int $migratedBy): array
{
    $legacyNo = normalizeLegacyTicketNumber($data['legacy_ticket_number'] ?? '');
    if ($legacyNo === '') {
        throw new InvalidArgumentException('Old ticket number is required.');
    }
    if (legacyTicketExists($pdo, $legacyNo)) {
        throw new InvalidArgumentException('Ticket #' . str_pad($legacyNo, 6, '0', STR_PAD_LEFT) . ' was already migrated.');
    }

    $userId = (int)($data['user_id'] ?? 0);
    if (!$userId) {
        throw new InvalidArgumentException('Requester is required.');
    }

    $subject = trim((string)($data['subject'] ?? ''));
    if ($subject === '') {
        throw new InvalidArgumentException('Subject is required.');
    }

    $description = trim((string)($data['description'] ?? ''));
    if ($description === '') {
        throw new InvalidArgumentException('Description is required.');
    }

    $priority = in_array($data['priority'] ?? '', ['low', 'medium', 'high', 'critical'], true)
        ? $data['priority']
        : 'medium';

    $status = in_array($data['status'] ?? '', ['open', 'in_progress', 'pending', 'resolved', 'closed'], true)
        ? $data['status']
        : 'closed';

    $source = 'web';

    $createdAt = parseLegacyDateTime($data['created_at'] ?? '') ?: date('Y-m-d H:i:s');
    $closedAt  = parseLegacyDateTime($data['closed_at'] ?? '');
    if (in_array($status, ['resolved', 'closed'], true) && !$closedAt) {
        $closedAt = $createdAt;
    }

    $slaMap = ['critical' => 4, 'high' => 8, 'medium' => 24, 'low' => 48];
    $slaHours = $slaMap[$priority] ?? 24;
    $ticketCode = generateTicketCode();
    $deptId = (int)($data['department_id'] ?? 0) ?: null;
    $categoryId = resolveMigrationCategoryId(
        $pdo,
        (int)($data['category_id'] ?? 0) ?: null,
        (string)($data['category_name'] ?? ''),
        $deptId
    );

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO tickets
             (ticket_code, legacy_ticket_number, is_legacy, migrated_by, migrated_at,
              user_id, department_id, category_id, assigned_to,
              subject, description, priority, status, source, sla_hours, closed_at, created_at, updated_at)
             VALUES (?,?,1,?,NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $ticketCode,
            $legacyNo,
            $migratedBy,
            $userId,
            $deptId,
            $categoryId,
            (int)($data['assigned_to'] ?? 0) ?: null,
            $subject,
            $description,
            $priority,
            $status,
            $source,
            $slaHours,
            $closedAt,
            $createdAt,
            $closedAt ?: $createdAt,
        ]);

        $ticketId = (int)$pdo->lastInsertId();

        // Save ticket-level attachments (if any)
        if (!empty($data['ticket_files']) && is_array($data['ticket_files'])) {
            foreach ($data['ticket_files'] as $tf) {
                saveLegacyAttachmentRecord($pdo, $ticketId, null, $userId, $tf);
            }
        }

        $fallbackStaffId = (int)($data['assigned_to'] ?? 0) ?: $migratedBy;
        $threadRaw = $data['thread_messages'] ?? [];
        if (!is_array($threadRaw)) {
            $threadRaw = [];
        }

        // Backward compatibility with the old single-message fields
        if (empty($threadRaw)) {
            if (trim((string)($data['original_message'] ?? '')) !== '') {
                $threadRaw[] = [
                    'user_id'    => $userId,
                    'message'    => $data['original_message'],
                    'created_at' => $createdAt,
                ];
            }
            if (trim((string)($data['staff_response'] ?? '')) !== '') {
                $threadRaw[] = [
                    'user_id'    => (int)($data['response_by'] ?? 0) ?: $fallbackStaffId,
                    'message'    => $data['staff_response'],
                    'created_at' => $data['response_at'] ?? ($closedAt ?: $createdAt),
                    'is_staff'   => 1,
                ];
            }
        }

        $threadMessages = normalizeThreadMessages($threadRaw, $userId, $fallbackStaffId, $createdAt);
        $firstStaffReplyAt = null;

        foreach ($threadMessages as $reply) {
            $pdo->prepare(
                "INSERT INTO ticket_replies (ticket_id, user_id, message, is_internal, created_at)
                 VALUES (?,?,?,?,?)"
            )->execute([
                $ticketId,
                $reply['user_id'],
                $reply['message'],
                $reply['is_internal'],
                $reply['created_at'],
            ]);
            $replyId = (int)$pdo->lastInsertId();

            // Save reply-level attachments (if any)
            if (!empty($reply['files']) && is_array($reply['files'])) {
                foreach ($reply['files'] as $rf) {
                    saveLegacyAttachmentRecord($pdo, $ticketId, $replyId, (int)$reply['user_id'], $rf);
                }
            }

            if ($firstStaffReplyAt === null && (int)$reply['user_id'] !== $userId && !$reply['is_internal']) {
                $firstStaffReplyAt = $reply['created_at'];
            }
        }

        if ($firstStaffReplyAt) {
            try {
                $pdo->prepare('UPDATE tickets SET first_reply_at = ? WHERE id = ? AND first_reply_at IS NULL')
                    ->execute([$firstStaffReplyAt, $ticketId]);
            } catch (Exception $e) {
                // Column may not exist on older schemas
            }
        }

        if (in_array($status, ['resolved', 'closed'], true) && $closedAt) {
            try {
                $pdo->prepare('UPDATE tickets SET resolved_at = ? WHERE id = ? AND resolved_at IS NULL')
                    ->execute([$closedAt, $ticketId]);
            } catch (Exception $e) {
                // Column may not exist on older schemas
            }
        }

        $legacyLabel = '#' . str_pad($legacyNo, 6, '0', STR_PAD_LEFT);
        logActivity(
            $migratedBy,
            $ticketId,
            'legacy_migrated',
            "Imported legacy ticket {$legacyLabel} as {$ticketCode}",
            getClientIp()
        );

        $pdo->commit();
        return ['ticket_id' => $ticketId, 'ticket_code' => $ticketCode];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Recent migrated tickets for the admin list.
 */
function getMigratedTickets(PDO $pdo, int $limit = 50, ?string $search = null): array
{
    $sql = "SELECT t.id, t.ticket_code, t.legacy_ticket_number, t.subject, t.status, t.priority,
                   t.created_at, t.migrated_at, u.name AS requester_name, m.name AS migrated_by_name
            FROM tickets t
            LEFT JOIN users u ON u.id = t.user_id
            LEFT JOIN users m ON m.id = t.migrated_by
            WHERE t.is_legacy = 1";
    $params = [];
    if ($search) {
        $sql .= " AND (t.legacy_ticket_number LIKE ? OR t.ticket_code LIKE ? OR t.subject LIKE ?)";
        $like = '%' . $search . '%';
        $params = [$like, $like, $like];
    }
    $sql .= " ORDER BY t.migrated_at DESC, t.id DESC LIMIT " . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
