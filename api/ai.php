<?php
/**
 * AI / Automation API
 * Actions: subject_suggest, ai_status, ai_template, classify, duplicate_check,
 *          reply_suggestions, kb_deflect
 * Template generation uses free open-source models (e.g. Llama 3.3 70B)
 * through Groq's free API when a key is configured, with the built-in
 * keyword matching / similarity engine as automatic fallback.
 * Free tier only — no paid API is ever called.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai_helpers.php';
requireLogin();

header('Content-Type: application/json');

$pdo    = db();
$user   = currentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ---- Smart subject suggestions (department-aware) ----
    case 'subject_suggest':
        $q      = mb_strtolower(trim($_GET['q'] ?? ''));
        $deptId = (int)($_GET['dept_id'] ?? 0) ?: (int)($user['dept_id'] ?? 0);

        $sql = "SELECT id, department_id, subject, description_template, keywords
                FROM subject_suggestions
                WHERE is_active = 1" . ($deptId ? " AND (department_id = ? OR department_id IS NULL)" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->execute($deptId ? [$deptId] : []);
        $all = $stmt->fetchAll();

        $results = [];
        foreach ($all as $row) {
            $subjectLower = mb_strtolower($row['subject']);
            $score = 0;
            if ($q === '') {
                $score = 1; // show department defaults when field is empty/focused
            } else {
                if (mb_strpos($subjectLower, $q) !== false) $score += 10;
                foreach (array_filter(preg_split('/[^a-z0-9]+/', $q)) as $word) {
                    if (mb_strlen($word) >= 3 && mb_strpos($subjectLower, $word) !== false) $score += 3;
                }
                // keyword column match
                $kw = mb_strtolower($row['keywords'] ?? '');
                foreach (array_filter(preg_split('/[^a-z0-9]+/', $q)) as $word) {
                    if (mb_strlen($word) >= 3 && $kw && mb_strpos($kw, $word) !== false) $score += 2;
                }
            }
            if ($score > 0) {
                $results[] = [
                    'id'       => (int)$row['id'],
                    'subject'  => $row['subject'],
                    'template' => $row['description_template'],
                    'score'    => $score,
                ];
            }
        }
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        jsonResponse(true, 'OK', ['suggestions' => array_slice($results, 0, 6)]);

    // ---- Is the LLM configured? Used by the UI to show the AI badge ----
    case 'ai_status':
        $providers = llmProviders();
        $activeProvider = !empty($providers) ? $providers[0] : null;
        jsonResponse(true, 'OK', [
            'llm'      => llmAvailable(),
            'model'    => $activeProvider ? $activeProvider['model'] : null,
            'provider' => $activeProvider ? $activeProvider['name'] : null,
            'fallback_count' => max(0, count($providers) - 1),
        ]);

    // ---- Generate a full ticket template with a free LLM (via Groq) ----
    case 'ai_template':
        validateCsrf();
        $draft  = trim($_POST['draft'] ?? '');
        $deptId = (int)($_POST['dept_id'] ?? 0) ?: (int)($user['dept_id'] ?? 0) ?: null;

        if (mb_strlen($draft) < 4) {
            jsonResponse(false, 'Type a few words about your issue first.');
        }

        $tpl = aiGenerateTicketTemplate($pdo, $draft, $deptId);
        if ($tpl) {
            // Resolve names so the UI can tell the user what was chosen
            $deptName = null; $catName = null;
            if ($tpl['department_id']) {
                $s = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
                $s->execute([$tpl['department_id']]);
                $deptName = $s->fetchColumn() ?: null;
            }
            if ($tpl['category_id']) {
                $s = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
                $s->execute([$tpl['category_id']]);
                $catName = $s->fetchColumn() ?: null;
            }
            $tpl['department_name'] = $deptName;
            $tpl['category_name']   = $catName;
            $tpl['due_date']        = $tpl['due_days']
                ? date('Y-m-d', strtotime("+{$tpl['due_days']} weekday"))
                : null;

            // AI auto-assign: least-loaded member of the chosen department
            $tpl['assignee_id']   = null;
            $tpl['assignee_name'] = null;
            if ($tpl['department_id'] && isStaff()) {
                $assigneeId = aiPickAssignee($pdo, (int)$tpl['department_id'], (int)$user['id']);
                if ($assigneeId) {
                    $s = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                    $s->execute([$assigneeId]);
                    $tpl['assignee_id']   = $assigneeId;
                    $tpl['assignee_name'] = $s->fetchColumn() ?: null;
                }
            }

            // Learn: store this template so it appears in the suggestion dropdown
            aiLearnTemplate($pdo, $tpl['subject'], $tpl['description'], $tpl['department_id']);

            logActivity($user['id'], null, 'ai_template_generated',
                'LLM template generated for draft: ' . mb_strimwidth($draft, 0, 80, '...'), getClientIp());
            jsonResponse(true, 'Template generated.', ['template' => $tpl, 'source' => 'llm']);
        }

        // ---- Fallback: best-matching keyword template from subject_suggestions ----
        $sql = "SELECT subject, description_template, keywords
                FROM subject_suggestions
                WHERE is_active = 1" . ($deptId ? " AND (department_id = ? OR department_id IS NULL)" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->execute($deptId ? [$deptId] : []);

        $best = null; $bestScore = 0;
        $qLower = mb_strtolower($draft);
        foreach ($stmt->fetchAll() as $row) {
            $score = 0;
            $hay = mb_strtolower($row['subject'] . ' ' . ($row['keywords'] ?? ''));
            foreach (array_filter(preg_split('/[^a-z0-9]+/', $qLower)) as $word) {
                if (mb_strlen($word) >= 3 && mb_strpos($hay, $word) !== false) $score += 2;
            }
            if ($score > $bestScore) { $bestScore = $score; $best = $row; }
        }
        if ($best) {
            jsonResponse(true, 'AI is offline — using closest built-in template.', [
                'template' => [
                    'subject'     => $best['subject'],
                    'description' => $best['description_template'],
                ],
                'source' => 'keyword',
            ]);
        }
        jsonResponse(false, 'AI assistant is not available and no matching template was found. '
            . 'Ask your administrator to configure at least one AI provider (Groq, Cerebras, or SambaNova).');

    // ---- Classify department + priority from text ----
    case 'classify':
        $text = trim(($_POST['subject'] ?? '') . ' ' . ($_POST['description'] ?? ''));
        if (mb_strlen($text) < 5) jsonResponse(true, 'OK', ['department' => null, 'priority' => null]);

        $deptResult = aiClassifyDepartment($pdo, $text);
        $priority   = aiSuggestPriority($pdo, $text);

        $deptName = null;
        if ($deptResult['department_id']) {
            $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
            $stmt->execute([$deptResult['department_id']]);
            $deptName = $stmt->fetchColumn() ?: null;
        }

        jsonResponse(true, 'OK', [
            'department' => $deptResult['department_id'] ? [
                'id'         => $deptResult['department_id'],
                'name'       => $deptName,
                'confidence' => $deptResult['confidence'],
            ] : null,
            'priority' => $priority,
        ]);

    // ---- Duplicate ticket detection ----
    case 'duplicate_check':
        $subject = trim($_POST['subject'] ?? '');
        $deptId  = (int)($_POST['department_id'] ?? 0) ?: null;
        if (mb_strlen($subject) < 6) jsonResponse(true, 'OK', ['duplicate' => null]);

        $dup = aiFindDuplicate($pdo, (int)$user['id'], $deptId, $subject);

        if ($dup) {
            $pdo->prepare(
                "INSERT INTO duplicate_checks (user_id, subject, matched_ticket_id, similarity, proceeded)
                 VALUES (?,?,?,?,0)"
            )->execute([$user['id'], $subject, $dup['ticket']['id'], $dup['similarity']]);

            jsonResponse(true, 'OK', ['duplicate' => [
                'check_id'    => (int)$pdo->lastInsertId(),
                'ticket_id'   => (int)$dup['ticket']['id'],
                'ticket_code' => $dup['ticket']['ticket_code'],
                'subject'     => $dup['ticket']['subject'],
                'status'      => $dup['ticket']['status'],
                'similarity'  => $dup['similarity'],
            ]]);
        }
        jsonResponse(true, 'OK', ['duplicate' => null]);

    // ---- Mark that the user proceeded despite a duplicate warning ----
    case 'duplicate_proceed':
        $checkId = (int)($_POST['check_id'] ?? 0);
        if ($checkId) {
            $pdo->prepare("UPDATE duplicate_checks SET proceeded = 1 WHERE id = ? AND user_id = ?")
                ->execute([$checkId, $user['id']]);
        }
        jsonResponse(true, 'OK');

    // ---- Smart reply suggestions for staff ----
    case 'reply_suggestions':
        if (!isStaff()) jsonResponse(false, 'Permission denied.', [], 403);
        $ticketId = (int)($_GET['ticket_id'] ?? 0);
        if (!$ticketId) jsonResponse(false, 'Invalid ticket.');

        $tk = $pdo->prepare("SELECT subject, description, category_id FROM tickets WHERE id = ?");
        $tk->execute([$ticketId]);
        $tk = $tk->fetch();
        if (!$tk) jsonResponse(false, 'Ticket not found.');

        $text = mb_strtolower($tk['subject'] . ' ' . $tk['description']);
        $rows = $pdo->query("SELECT id, category_id, keywords, title, body FROM reply_suggestions WHERE is_active = 1")->fetchAll();

        $scored = [];
        foreach ($rows as $row) {
            $score = 0;
            // Category match is a strong signal
            if ($row['category_id'] && (int)$row['category_id'] === (int)$tk['category_id']) $score += 5;
            // Keyword overlap
            foreach (array_filter(array_map('trim', explode(',', mb_strtolower($row['keywords'])))) as $kw) {
                if ($kw !== '' && mb_strpos($text, $kw) !== false) $score += 2;
            }
            if ($score > 0) {
                $scored[] = ['id' => (int)$row['id'], 'title' => $row['title'], 'body' => $row['body'], 'score' => $score];
            }
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Always return 3: pad with generic (category_id IS NULL) suggestions
        if (count($scored) < 3) {
            $haveIds = array_column($scored, 'id');
            foreach ($rows as $row) {
                if (count($scored) >= 3) break;
                if ($row['category_id'] === null && !in_array((int)$row['id'], $haveIds)) {
                    $scored[] = ['id' => (int)$row['id'], 'title' => $row['title'], 'body' => $row['body'], 'score' => 0];
                }
            }
        }
        jsonResponse(true, 'OK', ['suggestions' => array_slice($scored, 0, 3)]);

    // ---- KB deflection (user solved their issue from an article) ----
    case 'kb_deflect':
        validateCsrf();
        $articleId = (int)($_POST['kb_article_id'] ?? 0) ?: null;
        $subject   = trim($_POST['subject_typed'] ?? '');
        $pdo->prepare(
            "INSERT INTO kb_deflections (user_id, kb_article_id, subject_typed) VALUES (?,?,?)"
        )->execute([$user['id'], $articleId, $subject ?: null]);
        logActivity($user['id'], null, 'kb_deflection', 'User resolved issue via KB article #' . ($articleId ?? 'n/a'), getClientIp());
        jsonResponse(true, 'Glad we could help!');

    // ---- Floating AI assistant chat ----
    case 'assistant_chat':
        validateCsrf();
        $message = trim($_POST['message'] ?? '');
        if ($message === '' || mb_strlen($message) > 1000) {
            jsonResponse(false, 'Please type a question.');
        }

        $history = json_decode($_POST['history'] ?? '[]', true);
        if (!is_array($history)) $history = [];

        $contextTicketId = (int)($_POST['ticket_id'] ?? 0) ?: null;

        $result = aiChatAssistant($pdo, $history, $message, $user, $contextTicketId);
        if ($result === null) {
            jsonResponse(false, 'The AI assistant is offline right now. Please browse the Knowledge Base or create a ticket.');
        }
        jsonResponse(true, 'OK', [
            'answer'      => $result['answer'],
            'sources'     => $result['sources'],
            'ticket'      => $result['ticket'] ?? null,
            'reply_draft' => $result['reply_draft'] ?? null,
        ]);

    default:
        jsonResponse(false, 'Unknown action.', [], 400);
}
