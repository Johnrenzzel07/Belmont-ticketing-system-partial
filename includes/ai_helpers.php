<?php
/**
 * AI / Automation Helpers
 * On-premise rule-based classification, similarity scoring & settings.
 * No external API required — works fully offline.
 */

/**
 * Classify the most likely department for a piece of text using
 * weighted keyword rules from the routing_rules table.
 *
 * @return array{department_id:?int, confidence:float, scores:array}
 */
function aiClassifyDepartment(PDO $pdo, string $text): array
{
    $text = mb_strtolower($text);
    $rules = $pdo->query(
        "SELECT department_id, keyword, weight FROM routing_rules WHERE is_active = 1"
    )->fetchAll();

    $scores = [];
    foreach ($rules as $rule) {
        if (mb_strpos($text, mb_strtolower($rule['keyword'])) !== false) {
            $deptId = (int)$rule['department_id'];
            $scores[$deptId] = ($scores[$deptId] ?? 0) + (int)$rule['weight'];
        }
    }

    if (empty($scores)) {
        return ['department_id' => null, 'confidence' => 0.0, 'scores' => []];
    }

    arsort($scores);
    $total   = array_sum($scores);
    $topDept = array_key_first($scores);
    $topScore = $scores[$topDept];

    // Confidence: share of the winning department's score over all matches,
    // boosted by the absolute score (more matched keywords = more certain).
    $share      = $total > 0 ? $topScore / $total : 0;
    $strength   = min(1.0, $topScore / 6); // 6+ weight points = full strength
    $confidence = round($share * 0.6 + $strength * 0.4, 2);

    return [
        'department_id' => $topDept,
        'confidence'    => (float)$confidence,
        'scores'        => $scores,
    ];
}

/**
 * Suggest a priority based on keyword rules.
 * Returns null when no rule matches.
 */
function aiSuggestPriority(PDO $pdo, string $text): ?string
{
    $text  = mb_strtolower($text);
    $rules = $pdo->query(
        "SELECT keyword, priority, weight FROM priority_rules WHERE is_active = 1"
    )->fetchAll();

    $scores = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
    $matched = false;
    foreach ($rules as $rule) {
        if (mb_strpos($text, mb_strtolower($rule['keyword'])) !== false) {
            $scores[$rule['priority']] += (int)$rule['weight'];
            $matched = true;
        }
    }
    if (!$matched) return null;

    // Highest-severity wins on ties (critical > high > medium > low)
    foreach (['critical', 'high', 'medium', 'low'] as $p) {
        if ($scores[$p] > 0 && $scores[$p] === max($scores)) {
            return $p;
        }
    }
    return null;
}

/**
 * Compute similarity (0-100) between two strings using a blend of
 * token overlap (Jaccard) and PHP's similar_text percentage.
 */
function aiSimilarity(string $a, string $b): float
{
    $a = mb_strtolower(trim($a));
    $b = mb_strtolower(trim($b));
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 100.0;

    $stop = ['the','a','an','is','are','was','were','to','of','in','on','at','for','and','or','not','with','my','our','this','that','it','be','has','have','had'];
    $tokensA = array_diff(array_filter(preg_split('/[^a-z0-9]+/', $a)), $stop);
    $tokensB = array_diff(array_filter(preg_split('/[^a-z0-9]+/', $b)), $stop);

    $jaccard = 0.0;
    if ($tokensA && $tokensB) {
        $intersect = count(array_intersect($tokensA, $tokensB));
        $union     = count(array_unique(array_merge($tokensA, $tokensB)));
        $jaccard   = $union > 0 ? ($intersect / $union) * 100 : 0;
    }

    similar_text($a, $b, $pct);

    return round($jaccard * 0.65 + $pct * 0.35, 2);
}

/**
 * Find the most similar open ticket for duplicate detection.
 * Searches the requester's own tickets plus open tickets in the same department.
 *
 * @return array|null ['ticket' => row, 'similarity' => float] when above threshold
 */
function aiFindDuplicate(PDO $pdo, int $userId, ?int $deptId, string $subject, float $threshold = 70.0): ?array
{
    $sql = "SELECT id, ticket_code, subject, status, created_at
            FROM tickets
            WHERE status NOT IN ('resolved','closed')
              AND (user_id = ?" . ($deptId ? " OR department_id = ?" : "") . ")
            ORDER BY created_at DESC
            LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($deptId ? [$userId, $deptId] : [$userId]);

    $best = null;
    $bestScore = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        $score = aiSimilarity($subject, $row['subject']);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $row;
        }
    }

    if ($best && $bestScore >= $threshold) {
        return ['ticket' => $best, 'similarity' => $bestScore];
    }
    return null;
}

/* ============================================================
 * LLM integration — multi-provider with automatic failover.
 * Tries each provider in the AI_PROVIDERS chain. If one returns
 * HTTP 429 (rate limit) or fails, the next provider is attempted.
 * When no provider succeeds, callers fall back to the keyword engine.
 * All providers use the OpenAI-compatible chat completions API.
 * ============================================================ */

/**
 * Get the configured provider chain (decoded once and cached).
 *
 * @return array[] list of provider configs with non-empty keys
 */
function llmProviders(): array
{
    static $providers = null;
    if ($providers === null) {
        $all = defined('AI_PROVIDERS') ? json_decode(AI_PROVIDERS, true) : [];
        $providers = is_array($all)
            ? array_values(array_filter($all, fn($p) => !empty($p['key']) && !empty($p['url'])))
            : [];
    }
    return $providers;
}

/**
 * Whether at least one LLM provider is available.
 */
function llmAvailable(): bool
{
    return defined('AI_ENABLED') && AI_ENABLED && count(llmProviders()) > 0;
}

/**
 * Run a single chat completion, trying each provider in order.
 * Automatically fails over on HTTP 429 (rate limit), 5xx errors,
 * or connection failures.
 *
 * @param string $prompt User prompt
 * @param string $system Optional system instruction
 * @param bool   $json   Ask for a JSON object response
 * @return string|null   Model response text, or null on all failures
 */
function llmGenerate(string $prompt, string $system = '', bool $json = false): ?string
{
    if (!llmAvailable()) return null;

    $messages = [];
    if ($system !== '') $messages[] = ['role' => 'system', 'content' => $system];
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $timeout = defined('AI_TIMEOUT') ? AI_TIMEOUT : 30;

    foreach (llmProviders() as $provider) {
        // JSON mode can fail on reasoning models; retry that provider without it.
        $jsonAttempts = $json ? [true, false] : [false];

        foreach ($jsonAttempts as $useJson) {
            $payload = [
                'model'       => $provider['model'],
                'messages'    => $messages,
                'temperature' => 0.4,
                'max_tokens'  => 1500,
            ];
            if ($useJson) {
                $payload['response_format'] = ['type' => 'json_object'];
            }

            $ch = curl_init($provider['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $provider['key'],
                ],
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $raw  = curl_exec($ch);
            $err  = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            // Rate-limited or server error — try the next provider
            if ($raw === false || $code === 429 || $code >= 500) {
                error_log("[AI:{$provider['name']}] HTTP {$code} — failover to next provider. " . ($err ?: mb_strimwidth((string)$raw, 0, 200)));
                break;
            }

            // JSON mode rejected — retry this provider without response_format
            if ($useJson && $code === 400 && stripos((string)$raw, 'json') !== false) {
                error_log("[AI:{$provider['name']}] JSON mode rejected, retrying without it.");
                continue;
            }

            // Other client errors (401 bad key, 404 unknown model) — skip this provider
            if ($code !== 200) {
                error_log("[AI:{$provider['name']}] HTTP {$code} " . ($err ?: mb_strimwidth((string)$raw, 0, 200)));
                break;
            }

            $data = json_decode($raw, true);
            $text = trim((string)($data['choices'][0]['message']['content'] ?? ''));
            if ($text !== '') return $text;

            // Empty response — try next provider
            error_log("[AI:{$provider['name']}] empty response, trying next provider.");
            break;
        }
    }

    // All providers exhausted
    return null;
}

/**
 * Extract a JSON object from an LLM response that may be wrapped in
 * code fences or surrounded by extra prose.
 */
function llmExtractJson(string $text): ?array
{
    // Strip markdown code fences if present
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text));

    $parsed = json_decode($text, true);
    if (is_array($parsed)) return $parsed;

    // Fall back to the outermost {...} block
    $start = mb_strpos($text, '{');
    $end   = mb_strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $parsed = json_decode(mb_substr($text, $start, $end - $start + 1), true);
        if (is_array($parsed)) return $parsed;
    }
    return null;
}

/**
 * Generate a complete, fully-classified ticket from the user's rough draft
 * using a free LLM via Groq. The model sees the real department and category
 * lists from the database and picks the best match, plus priority and a
 * realistic due date.
 *
 * @param string   $draft  What the user typed so far (subject and/or notes)
 * @param int|null $deptId Department the user already picked (optional hint)
 * @return array{subject:string, description:string, department_id:?int,
 *               category_id:?int, priority:?string, due_days:?int}|null
 */
function aiGenerateTicketTemplate(PDO $pdo, string $draft, ?int $deptId = null): ?array
{
    // Real departments and categories so the model classifies against actual data
    $departments = $pdo->query(
        "SELECT id, name FROM departments WHERE is_active = 1 ORDER BY id"
    )->fetchAll();
    $categories = $pdo->query(
        "SELECT id, name, department_id FROM categories WHERE is_active = 1 ORDER BY id"
    )->fetchAll();

    $deptList = implode("\n", array_map(
        fn($d) => "  {$d['id']} = {$d['name']}", $departments
    ));
    $catList = implode("\n", array_map(
        fn($c) => "  {$c['id']} = {$c['name']} (belongs to department {$c['department_id']})", $categories
    ));

    $hint = '';
    if ($deptId) {
        foreach ($departments as $d) {
            if ((int)$d['id'] === $deptId) { $hint = "The employee pre-selected department {$d['id']} ({$d['name']}) — keep it unless clearly wrong.\n"; break; }
        }
    }

    $system = 'You are an expert helpdesk dispatcher for Cebu Belmont, Inc. (a retail company in Cebu, Philippines). '
        . 'Given an employee\'s rough ticket draft, you must classify and write the complete ticket. '
        . 'Respond ONLY with a JSON object with exactly these keys:' . "\n"
        . '"subject": concise professional ticket subject, max 90 characters.' . "\n"
        . '"description": a clear, well-written ticket description in plain text (no markdown) with these labeled sections, each on its own line(s): '
        . '"Issue Summary:", "Details / What Happened:", "Error Message (if any):", "What I Already Tried:", "Impact / Urgency:". '
        . 'Write full helpful sentences for everything you can infer from the draft; only use a short [bracketed hint] for details you genuinely cannot know. '
        . 'Do not invent specific facts like names, room numbers, or error codes.' . "\n"
        . '"department_id": integer — the department whose STAFF will FIX or FULFILL this request, chosen from the department list. '
        . 'The CATEGORIES list is the authority on which department handles which kind of issue: first find the category that best matches the request, '
        . 'then route to that category\'s department. If no category clearly fits, route by who does the work '
        . '(office computer/software/email/account issues -> IT; payroll/leave/employee concerns -> HR; invoices/payments -> Accounting; '
        . 'store equipment/supplies/operations -> Store; product/stock/supplier matters -> Merchandising).' . "\n"
        . '"category_id": integer or null — the best category from the category list. It MUST belong to the chosen department; use null if none fits.' . "\n"
        . '"priority": one of "low", "medium", "high", "critical". '
        . 'critical = business stopped / many people blocked / security incident; high = one person fully blocked or urgent deadline; '
        . 'medium = degraded but workable; low = requests, supplies, nice-to-have.' . "\n"
        . '"due_days": integer 1-30 — realistic working days to resolve, based on priority and request type '
        . '(critical:1, high:2-3, medium:5-7, low:7-14 as a guide).';

    $prompt = "DEPARTMENTS:\n{$deptList}\n\nCATEGORIES:\n{$catList}\n\n"
        . $hint
        . "Employee's draft: \"{$draft}\"\n\n"
        . 'Generate the JSON now.';

    $out = llmGenerate($prompt, $system, true);
    if ($out === null) return null;

    $parsed = llmExtractJson($out);
    if (!is_array($parsed) || empty($parsed['subject']) || empty($parsed['description'])) {
        return null;
    }

    // ---- Validate classification against real data ----
    $validDeptIds = array_map(fn($d) => (int)$d['id'], $departments);
    $aiDept = (int)($parsed['department_id'] ?? 0);
    if (!in_array($aiDept, $validDeptIds, true)) $aiDept = $deptId ?: null;

    $aiCat = (int)($parsed['category_id'] ?? 0) ?: null;
    if ($aiCat !== null) {
        $catOk = false;
        foreach ($categories as $c) {
            if ((int)$c['id'] === $aiCat && (!$aiDept || (int)$c['department_id'] === $aiDept)) {
                $catOk = true; break;
            }
        }
        if (!$catOk) $aiCat = null;
    }

    $aiPriority = in_array($parsed['priority'] ?? '', ['low','medium','high','critical'], true)
        ? $parsed['priority'] : null;

    $aiDueDays = (int)($parsed['due_days'] ?? 0);
    if ($aiDueDays < 1 || $aiDueDays > 30) $aiDueDays = null;

    return [
        'subject'       => mb_strimwidth(trim((string)$parsed['subject']), 0, 255),
        'description'   => trim((string)$parsed['description']),
        'department_id' => $aiDept,
        'category_id'   => $aiCat,
        'priority'      => $aiPriority,
        'due_days'      => $aiDueDays,
    ];
}

/**
 * AI auto-assign: pick the best member of a department for a new ticket.
 * Chooses the active member with the FEWEST open tickets (load balancing),
 * preferring staff/admin roles on ties, and never the requester themselves.
 *
 * @return int|null user id of the chosen assignee
 */
function aiPickAssignee(PDO $pdo, int $deptId, int $excludeUserId = 0): ?int
{
    if (!$deptId) return null;
    try {
        $stmt = $pdo->prepare(
            "SELECT u.id
             FROM users u
             LEFT JOIN (
                 SELECT assigned_to, COUNT(*) AS open_count
                 FROM tickets
                 WHERE status NOT IN ('resolved','closed') AND assigned_to IS NOT NULL
                 GROUP BY assigned_to
             ) tc ON tc.assigned_to = u.id
             WHERE u.department_id = ?
               AND u.role IN ('staff','user')
               AND u.is_active = 1" .
               ($excludeUserId ? " AND u.id != ?" : "") . "
             ORDER BY
               COALESCE(tc.open_count, 0) ASC,
               FIELD(u.role, 'staff', 'user')
             LIMIT 1"
        );
        $stmt->execute($excludeUserId ? [$deptId, $excludeUserId] : [$deptId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Learn a new template: save a subject + description into the
 * subject_suggestions table so it appears in the suggestion dropdown for
 * future tickets. Skips near-duplicates of existing templates.
 *
 * @return bool true when a new template was saved
 */
function aiLearnTemplate(PDO $pdo, string $subject, string $description, ?int $deptId = null): bool
{
    $subject = trim($subject);
    if (mb_strlen($subject) < 8 || mb_strlen(trim($description)) < 20) return false;

    try {
        // Skip if too similar to an existing template (avoid dropdown spam)
        $existing = $pdo->query(
            "SELECT subject FROM subject_suggestions WHERE is_active = 1
             ORDER BY created_at DESC LIMIT 300"
        )->fetchAll();
        foreach ($existing as $row) {
            if (aiSimilarity($subject, $row['subject']) >= 65) return false;
        }

        // Build keywords from the meaningful words of the subject
        $stop  = ['the','a','an','is','are','was','were','to','of','in','on','at','for','and','or','not','with','my','our','this','that','it','be','has','have','had','issue','problem','request','need','please','help'];
        $words = array_values(array_unique(array_diff(
            array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($subject)), fn($w) => mb_strlen($w) >= 3),
            $stop
        )));
        $keywords = mb_strimwidth(implode(',', array_slice($words, 0, 10)), 0, 255);

        $pdo->prepare(
            "INSERT INTO subject_suggestions (department_id, subject, description_template, keywords, is_active)
             VALUES (?,?,?,?,1)"
        )->execute([
            $deptId ?: null,
            mb_strimwidth($subject, 0, 255),
            mb_strimwidth($description, 0, 5000),
            $keywords ?: null,
        ]);
        return true;
    } catch (Exception $e) {
        return false; // learning must never break ticket flow
    }
}

/* ============================================================
 * AI Knowledge Base
 * - aiKbAnswer(): answers questions using YOUR published articles
 *   as the source of truth (retrieval-augmented generation).
 * - aiLearnKbFromTicket(): turns a resolved ticket's conversation
 *   into a new KB article so the system gets smarter over time.
 * ============================================================ */

/**
 * Answer a question using the knowledge base articles as context.
 *
 * @return array{answer:string, sources:array}|null
 */
function aiKbAnswer(PDO $pdo, string $question): ?array
{
    if (!llmAvailable() || mb_strlen(trim($question)) < 4) return null;

    // Retrieve the most relevant published articles (word-scored)
    $articles = $pdo->query(
        "SELECT id, title, slug, body FROM kb_articles WHERE is_published = 1"
    )->fetchAll();
    if (!$articles) return null;

    $words = array_filter(
        preg_split('/[^a-z0-9]+/', mb_strtolower($question)),
        fn($w) => mb_strlen($w) >= 3
    );

    $scored = [];
    foreach ($articles as $a) {
        $hayTitle = mb_strtolower($a['title']);
        $hayBody  = mb_strtolower($a['body']);
        $score = 0;
        foreach ($words as $w) {
            if (mb_strpos($hayTitle, $w) !== false) $score += 5;
            if (mb_strpos($hayBody, $w) !== false)  $score += 1;
        }
        if ($score > 0) { $a['score'] = $score; $scored[] = $a; }
    }
    usort($scored, fn($x, $y) => $y['score'] <=> $x['score']);
    $top = array_slice($scored, 0, 4);

    $context = '';
    foreach ($top as $i => $a) {
        $context .= "ARTICLE " . ($i + 1) . " — \"{$a['title']}\":\n"
            . mb_strimwidth($a['body'], 0, 1500, '...') . "\n\n";
    }

    $system = 'You are the knowledge base assistant for Cebu Belmont, Inc.\'s helpdesk. '
        . 'Answer the employee\'s question using ONLY the provided knowledge base articles. '
        . 'Be direct and practical: give numbered steps when explaining a procedure. '
        . 'If the articles do not contain the answer, say so honestly in one sentence and '
        . 'suggest submitting a ticket. Keep the answer under 200 words. Plain text only, no markdown.';

    $prompt = ($context !== ''
            ? "KNOWLEDGE BASE ARTICLES:\n{$context}"
            : "No matching articles were found in the knowledge base.\n")
        . "EMPLOYEE QUESTION: \"{$question}\"\n\nAnswer now.";

    $answer = llmGenerate($prompt, $system, false);
    if ($answer === null) return null;

    return [
        'answer'  => trim($answer),
        'sources' => array_map(fn($a) => [
            'id'    => (int)$a['id'],
            'title' => $a['title'],
            'slug'  => $a['slug'],
        ], $top),
    ];
}

/**
 * True when this ticket already produced a KB article.
 */
function ticketKbAlreadyLearned(PDO $pdo, int $ticketId): bool
{
    try {
        $st = $pdo->prepare('SELECT id FROM kb_articles WHERE source_ticket_id = ? LIMIT 1');
        $st->execute([$ticketId]);
        if ($st->fetchColumn()) return true;
    } catch (Exception $e) { /* column may not exist yet */ }

    try {
        $st = $pdo->prepare(
            "SELECT id FROM activity_logs WHERE ticket_id = ? AND action = 'kb_learned' LIMIT 1"
        );
        $st->execute([$ticketId]);
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Map a ticket category / department name to a kb_categories id.
 */
function mapTicketCategoryToKbCatId(PDO $pdo, ?string $ticketCatName, ?string $deptName): ?int
{
    $hay = mb_strtolower(trim(($ticketCatName ?? '') . ' ' . ($deptName ?? '')));
    $rules = [
        'network' => 'Network', 'wifi' => 'Network', 'internet' => 'Network', 'connectivity' => 'Network',
        'pos' => 'POS & Retail', 'retail' => 'POS & Retail',
        'password' => 'Account & Access', 'account' => 'Account & Access', 'access' => 'Account & Access',
        'printer' => 'Hardware & Devices', 'hardware' => 'Hardware & Devices', 'computer' => 'Hardware & Devices',
        'software' => 'Software & Systems', 'erp' => 'Software & Systems', 'netsuite' => 'Software & Systems',
    ];
    try {
        $kbCats = $pdo->query('SELECT id, name FROM kb_categories')->fetchAll();
        foreach ($rules as $needle => $kbName) {
            if (mb_strpos($hay, $needle) === false) continue;
            foreach ($kbCats as $kc) {
                if ($kc['name'] === $kbName) return (int)$kc['id'];
            }
        }
        foreach ($kbCats as $kc) {
            if ($kc['name'] === 'General FAQ') return (int)$kc['id'];
        }
    } catch (Exception $e) { /* non-fatal */ }
    return null;
}

/**
 * Insert a published KB article linked to its source ticket.
 */
function insertKbArticleFromTicket(
    PDO $pdo,
    int $ticketId,
    int $authorId,
    string $title,
    string $body,
    ?int $kbCatId
): ?int {
    $title = mb_strimwidth(trim($title), 0, 255);
    $body  = trim($body);
    if ($title === '' || $body === '') return null;

    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($title)), '-'));
    if ($slug === '') $slug = 'kb-article';

    $chk = $pdo->prepare('SELECT COUNT(*) FROM kb_articles WHERE slug = ?');
    $chk->execute([$slug]);
    if ((int)$chk->fetchColumn() > 0) $slug .= '-' . substr(uniqid(), -5);

    try {
        $pdo->prepare(
            'INSERT INTO kb_articles (kb_cat_id, author_id, source_ticket_id, title, slug, body, is_published)
             VALUES (?,?,?,?,?,?,1)'
        )->execute([$kbCatId, $authorId, $ticketId, $title, mb_strimwidth($slug, 0, 255), $body]);
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        // Older schema without source_ticket_id
        try {
            $pdo->prepare(
                'INSERT INTO kb_articles (kb_cat_id, author_id, title, slug, body, is_published)
                 VALUES (?,?,?,?,?,1)'
            )->execute([$kbCatId, $authorId, $title, mb_strimwidth($slug, 0, 255), $body]);
            return (int)$pdo->lastInsertId();
        } catch (Exception $e2) {
            return null;
        }
    }
}

/**
 * Strip greetings, signatures, and other chat noise from ticket text for KB use.
 */
function kbStripConversationNoise(string $text): string
{
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $out   = [];
    $skipPatterns = [
        '/^(hi|hello|hey|dear|good\s+(morning|afternoon|evening))\b/i',
        '/^thank(s| you)\b/i',
        '/^(best\s+)?regards\b/i',
        '/^(sincerely|cheers|thanks)\b/i',
        '/^please\s+let\s+(me|us)\s+know/i',
        '/^(it\s+)?department\b/i',
        '/^\[?(support|user|requester)\]?\s*$/i',
    ];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $drop = false;
        foreach ($skipPatterns as $pat) {
            if (preg_match($pat, $line)) {
                $drop = true;
                break;
            }
        }
        if ($drop) continue;

        // Drop short greeting-only lines like "Hi HR,"
        if (preg_match('/^(hi|hello|hey)\s+[a-z ,]+,?\s*$/i', $line)) continue;

        $out[] = $line;
    }

    return trim(implode("\n", $out));
}

/**
 * Turn a support question into a self-help troubleshooting step.
 */
function kbQuestionToStep(string $line): ?string
{
    $line = trim($line, " \t\n\r\0\x0B.?!");
    $line = preg_replace('/\s*(IT Staff|Support|IT Department)\s*$/i', '', $line);
    if ($line === '') return null;

    $map = [
        '/^is the issue affecting only your pc or multiple users/i'
            => 'Check whether the slow connection affects only your PC or multiple users on the same network',
        '/^are you connected via lan cable or wifi/i'
            => 'Confirm whether you are connected via LAN cable or WiFi',
        '/^did anything change recently/i'
            => 'Note any recent changes (new equipment, moved desk, new software, etc.)',
    ];
    foreach ($map as $pat => $step) {
        if (preg_match($pat, $line)) return $step;
    }

    if (preg_match('/^(is|are|did|do|can|have|was|were)\b/i', $line)) {
        return 'Verify: ' . lcfirst($line);
    }

    return null;
}

/**
 * Returns true if a line is conversational noise, not a fix step.
 */
function kbIsConversationOnlyLine(string $line): bool
{
    return (bool)preg_match(
        '/\b(thank you|thanks for|looking into|taken note|please let me know|best regards|hi\s+\w|hello\s+\w|we are looking into|get back to you|please confirm:?\s*$)\b/i',
        $line
    );
}

/**
 * Pull numbered/bullet action steps from staff replies (no chat transcript).
 *
 * @return string[] deduplicated step lines
 */
function kbExtractSolutionSteps(array $replies): array
{
    $steps = [];
    $seen  = [];

    foreach ($replies as $r) {
        if (!in_array($r['role'], ['admin', 'staff', 'dept_admin'], true)) continue;

        $msg = kbStripConversationNoise(trim($r['message']));
        if ($msg === '') continue;

        // Split numbered / bulleted blocks
        $parts = preg_split('/\n(?=\d+[\.\)]\s)|\n(?=[-*•]\s)/', $msg);
        if (count($parts) <= 1) {
            $parts = preg_split('/(?<=\.)\s+(?=\d+[\.\)]\s)/', $msg);
        }
        if (count($parts) <= 1) {
            $parts = preg_split('/\?\s*/', $msg);
        }

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || kbIsConversationOnlyLine($part)) continue;

            $part = preg_replace('/\s*(IT Staff|Support|IT Department)\s*$/i', '', $part);

            if (preg_match('/^\d+[\.\)]\s+(.+)/s', $part, $m)) {
                $step = trim($m[1]);
            } elseif (preg_match('/^[-*•]\s+(.+)/s', $part, $m)) {
                $step = trim($m[1]);
            } else {
                $step = $part;
            }

            $step = trim($step, " \t\n\r\0\x0B.,;?!");
            if ($step === '' || kbIsConversationOnlyLine($step)) continue;

            // Skip pure acknowledgments
            if (preg_match('/\btaken note\b/i', $step)) continue;

            // Rephrase questions as troubleshooting steps
            if (preg_match('/\?\s*$/', $step) || preg_match('/^(is|are|did|do|can|have|was|were)\b/i', $step)) {
                $converted = kbQuestionToStep($step);
                if ($converted === null) continue;
                $step = $converted;
            }

            // Keep lines with clear actions; drop vague status updates
            if (!preg_match('/\b(check|try|restart|reboot|confirm|verify|update|reset|disable|enable|connect|disconnect|clear|run|install|uninstall|replace|note|test|switch|move|contact|ensure|use|open|close|power)\b/i', $step)
                && mb_strlen($step) < 40) {
                continue;
            }

            $step = preg_replace('/\s+/', ' ', $step);
            if (mb_strlen($step) < 15) continue;

            $key = mb_strtolower($step);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $steps[] = $step;
        }
    }

    return $steps;
}

/**
 * Build a concise problem statement from the ticket subject + description.
 */
function kbBuildProblemText(string $subject, string $description): string
{
    $desc = kbStripConversationNoise(trim($description));
    $sub  = trim($subject);

    if ($desc === '') return $sub;

    // Avoid repeating the subject when description already covers it
    if ($sub !== '' && (mb_stripos($desc, $sub) === 0 || mb_stripos($sub, mb_strimwidth($desc, 0, 40)) === 0)) {
        return $desc;
    }
    if ($sub !== '' && mb_stripos($desc, $sub) === false) {
        return $desc;
    }
    return $desc;
}

/**
 * Format KB body with Problem + Solution only (no conversation transcript).
 */
function kbFormatProblemSolutionBody(string $problem, array $solutionSteps): string
{
    $problem = trim($problem);
    $body    = "Problem:\n" . $problem . "\n\nSolution:\n";

    if ($solutionSteps) {
        $n = 1;
        foreach ($solutionSteps as $step) {
            $body .= $n . '. ' . $step . "\n";
            $n++;
        }
    } else {
        $body .= "1. Restart your computer and network equipment (router/switch if applicable).\n"
            . "2. Test whether the issue affects only your device or multiple users.\n"
            . "3. Try a different connection (LAN cable vs WiFi) if possible.\n"
            . "4. If the problem continues, submit a new ticket with the steps you already tried.\n";
    }

    return trim($body);
}

/**
 * Rule-based KB article when the LLM is unavailable or returns skip.
 */
function fallbackKbFromTicket(PDO $pdo, int $ticketId): ?int
{
    if (ticketKbAlreadyLearned($pdo, $ticketId)) return null;

    try {
        $tk = $pdo->prepare(
            "SELECT t.subject, t.description, t.user_id, t.assigned_to,
                    c.name AS cat_name, d.name AS dept_name
             FROM tickets t
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN departments d ON d.id = t.department_id
             WHERE t.id = ?"
        );
        $tk->execute([$ticketId]);
        $tk = $tk->fetch();
        if (!$tk) return null;

        // Skip if a KB article with a very similar title already exists
        $existing = $pdo->prepare(
            'SELECT title FROM kb_articles WHERE source_ticket_id IS NULL OR source_ticket_id != ? ORDER BY created_at DESC LIMIT 300'
        );
        $existing->execute([$ticketId]);
        foreach ($existing->fetchAll() as $row) {
            if (aiSimilarity($tk['subject'], $row['title']) >= 75) return null;
        }

        $replies = $pdo->prepare(
            "SELECT r.message, u.role
             FROM ticket_replies r JOIN users u ON u.id = r.user_id
             WHERE r.ticket_id = ? AND r.is_internal = 0
             ORDER BY r.created_at ASC LIMIT 12"
        );
        $replies->execute([$ticketId]);
        $replies = $replies->fetchAll();

        $title   = mb_strimwidth(trim($tk['subject']), 0, 90);
        $problem = kbBuildProblemText($tk['subject'], $tk['description']);
        $steps   = kbExtractSolutionSteps($replies);
        $body    = kbFormatProblemSolutionBody($problem, $steps);

        $kbCatId  = mapTicketCategoryToKbCatId($pdo, $tk['cat_name'] ?? null, $tk['dept_name'] ?? null);
        $authorId = (int)($tk['assigned_to'] ?: $tk['user_id']);

        return insertKbArticleFromTicket($pdo, $ticketId, $authorId, $title, $body, $kbCatId);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Rebuild an existing ticket-sourced KB article (Problem + Solution only).
 */
function refreshKbArticleFromTicket(PDO $pdo, int $ticketId): bool
{
    try {
        $st = $pdo->prepare('SELECT id FROM kb_articles WHERE source_ticket_id = ? LIMIT 1');
        $st->execute([$ticketId]);
        $kbId = (int)$st->fetchColumn();
        if (!$kbId) return false;

        $tk = $pdo->prepare(
            "SELECT t.subject, t.description, t.user_id, t.assigned_to,
                    c.name AS cat_name, d.name AS dept_name
             FROM tickets t
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN departments d ON d.id = t.department_id
             WHERE t.id = ?"
        );
        $tk->execute([$ticketId]);
        $tk = $tk->fetch();
        if (!$tk) return false;

        $replies = $pdo->prepare(
            "SELECT r.message, u.role
             FROM ticket_replies r JOIN users u ON u.id = r.user_id
             WHERE r.ticket_id = ? AND r.is_internal = 0
             ORDER BY r.created_at ASC LIMIT 12"
        );
        $replies->execute([$ticketId]);
        $replies = $replies->fetchAll();

        $title   = mb_strimwidth(trim($tk['subject']), 0, 90);
        $problem = kbBuildProblemText($tk['subject'], $tk['description']);
        $steps   = kbExtractSolutionSteps($replies);
        $body    = kbFormatProblemSolutionBody($problem, $steps);
        $kbCatId = mapTicketCategoryToKbCatId($pdo, $tk['cat_name'] ?? null, $tk['dept_name'] ?? null);

        $pdo->prepare(
            'UPDATE kb_articles SET title = ?, body = ?, kb_cat_id = COALESCE(?, kb_cat_id) WHERE id = ?'
        )->execute([$title, $body, $kbCatId, $kbId]);

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Create a KB article from a resolved/closed ticket (LLM first, rule-based fallback).
 * Safe to call multiple times — only runs once per ticket.
 */
function triggerTicketKbLearning(PDO $pdo, int $ticketId, ?int $actorUserId = null): ?int
{
    if (ticketKbAlreadyLearned($pdo, $ticketId)) return null;

    try {
        $st = $pdo->prepare('SELECT status, ticket_code FROM tickets WHERE id = ?');
        $st->execute([$ticketId]);
        $row = $st->fetch();
        if (!$row || !in_array($row['status'], ['resolved', 'closed'], true)) {
            return null;
        }

        $newKbId = aiLearnKbFromTicket($pdo, $ticketId);
        if (!$newKbId) {
            $newKbId = fallbackKbFromTicket($pdo, $ticketId);
        }

        if ($newKbId && $actorUserId) {
            logActivity($actorUserId, $ticketId, 'kb_learned',
                "KB article #{$newKbId} auto-generated from ticket {$row['ticket_code']}", getClientIp());
        }

        return $newKbId;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Learn from a resolved ticket: turn its conversation into a published
 * KB article (skipping near-duplicates), so the next person with the same
 * problem finds the solution instantly.
 *
 * @return int|null new kb_articles id when an article was created
 */
function aiLearnKbFromTicket(PDO $pdo, int $ticketId): ?int
{
    if (ticketKbAlreadyLearned($pdo, $ticketId)) return null;

    try {
        $tk = $pdo->prepare(
            "SELECT t.subject, t.description, t.user_id, t.assigned_to,
                    c.name AS cat_name, d.name AS dept_name
             FROM tickets t
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN departments d ON d.id = t.department_id
             WHERE t.id = ?"
        );
        $tk->execute([$ticketId]);
        $tk = $tk->fetch();
        if (!$tk) return null;

        // The resolution lives in the replies — require at least one
        $replies = $pdo->prepare(
            "SELECT r.message, u.role
             FROM ticket_replies r JOIN users u ON u.id = r.user_id
             WHERE r.ticket_id = ? AND r.is_internal = 0
             ORDER BY r.created_at ASC LIMIT 12"
        );
        $replies->execute([$ticketId]);
        $replies = $replies->fetchAll();
        if (!$replies) return null;

        // Skip near-duplicate titles from other tickets
        $existing = $pdo->prepare(
            'SELECT title FROM kb_articles WHERE source_ticket_id IS NULL OR source_ticket_id != ? ORDER BY created_at DESC LIMIT 300'
        );
        $existing->execute([$ticketId]);
        foreach ($existing->fetchAll() as $row) {
            if (aiSimilarity($tk['subject'], $row['title']) >= 75) return null;
        }

        if (!llmAvailable()) return null;

        $kbCats = $pdo->query("SELECT id, name FROM kb_categories ORDER BY id")->fetchAll();
        $catList = implode("\n", array_map(fn($c) => "  {$c['id']} = {$c['name']}", $kbCats));

        $thread = "PROBLEM REPORTED:\n{$tk['subject']}\n{$tk['description']}\n\nCONVERSATION:\n";
        foreach ($replies as $r) {
            $who = in_array($r['role'], ['admin', 'staff', 'dept_admin'], true) ? 'Support' : 'Requester';
            $thread .= "[{$who}] " . mb_strimwidth($r['message'], 0, 500, '...') . "\n";
        }

        $system = 'You write knowledge base articles for Cebu Belmont, Inc.\'s internal helpdesk. '
            . 'Given a resolved or closed support ticket, write a short self-help article with ONLY the problem and fix steps. '
            . 'Do NOT paste the ticket conversation, reply quotes, greetings, signatures, or back-and-forth chat. '
            . 'Extract the underlying issue and turn support guidance into clear numbered solution steps. '
            . 'NEVER include personal names, emails, ticket numbers, or one-off details. Generalize the issue. '
            . 'Respond ONLY with a JSON object with exactly these keys:' . "\n"
            . '"title": clear, searchable how-to title, max 90 chars (no "How to Fix:" prefix).' . "\n"
            . '"kb_cat_id": integer — best category from the list.' . "\n"
            . '"body": plain text (no markdown) with EXACTLY two sections:' . "\n"
            . '  "Problem:" — 1-3 sentences describing the issue in general terms.' . "\n"
            . '  "Solution:" — numbered steps (1. 2. 3.) that an employee can follow to fix it.' . "\n"
            . 'No other sections. No chat transcript. Do NOT respond with {"skip": true} unless there is zero useful information.';

        $prompt = "KB CATEGORIES:\n{$catList}\n\n{$thread}\nGenerate the JSON now.";

        $out = llmGenerate($prompt, $system, true);
        if ($out === null) return null;

        $parsed = llmExtractJson($out);
        if (!is_array($parsed) || !empty($parsed['skip'])
            || empty($parsed['title']) || empty($parsed['body'])) {
            return null;
        }

        $catId = (int)($parsed['kb_cat_id'] ?? 0);
        if (!in_array($catId, array_map(fn($c) => (int)$c['id'], $kbCats), true)) {
            $catId = mapTicketCategoryToKbCatId($pdo, $tk['cat_name'] ?? null, $tk['dept_name'] ?? null);
        }

        $authorId = (int)($tk['assigned_to'] ?: $tk['user_id']);
        return insertKbArticleFromTicket(
            $pdo,
            $ticketId,
            $authorId,
            trim($parsed['title']),
            trim($parsed['body']),
            $catId
        );
    } catch (Exception $e) {
        return null; // learning must never break the resolve flow
    }
}

/**
 * Settings helpers (key/value table).
 */
function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function setSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([$key, $value]);
}

/**
 * Floating AI assistant chat.
 * Answers questions about the ticketing system using the knowledge base,
 * the user's own recent tickets, and built-in product knowledge.
 *
 * @param array $history  Previous turns: [['role'=>'user'|'assistant','content'=>string], ...]
 * @param int|null $contextTicketId  When the user is viewing a ticket, pass its id for thread-aware help
 * @return array|null     ['answer'=>string, 'sources'=>[['id','title','slug'],...]] or null if LLM offline
 */
function aiChatAssistant(PDO $pdo, array $history, string $message, array $user, ?int $contextTicketId = null): ?array
{
    $message = trim($message);
    if (!llmAvailable() || $message === '') return null;

    // ---- Retrieve relevant KB articles (word-scored) ----
    $sources = [];
    $kbContext = '';
    try {
        $articles = $pdo->query(
            "SELECT id, title, slug, body FROM kb_articles WHERE is_published = 1"
        )->fetchAll();

        $words = array_filter(
            preg_split('/[^a-z0-9]+/', mb_strtolower($message)),
            fn($w) => mb_strlen($w) >= 3
        );

        $scored = [];
        foreach ($articles as $a) {
            $hayTitle = mb_strtolower($a['title']);
            $hayBody  = mb_strtolower($a['body']);
            $score = 0;
            foreach ($words as $w) {
                if (mb_strpos($hayTitle, $w) !== false) $score += 5;
                if (mb_strpos($hayBody, $w) !== false)  $score += 1;
            }
            if ($score > 0) { $a['score'] = $score; $scored[] = $a; }
        }
        usort($scored, fn($x, $y) => $y['score'] <=> $x['score']);
        // Require at least one title-word hit (score 5+) so weak body-only
        // matches don't pollute the context with unrelated articles
        $top = array_slice(array_filter($scored, fn($a) => $a['score'] >= 5), 0, 3);
        if (!$top) $top = array_slice($scored, 0, 1); // keep the single best as a fallback

        foreach (array_values($top) as $i => $a) {
            $kbContext .= "ARTICLE " . ($i + 1) . " — \"{$a['title']}\":\n"
                . mb_strimwidth($a['body'], 0, 1200, '...') . "\n\n";
            $sources[] = ['id' => (int)$a['id'], 'title' => $a['title'], 'slug' => $a['slug']];
        }
    } catch (Exception $e) { /* KB optional */ }

    // ---- The user's recent tickets (so the AI can answer "my ticket status") ----
    $ticketContext = '';
    try {
        $stmt = $pdo->prepare(
            "SELECT t.ticket_code, t.subject, t.status, t.priority, t.created_at,
                    a.name AS assignee_name, d.name AS dept_name
             FROM tickets t
             LEFT JOIN users a ON a.id = t.assigned_to
             LEFT JOIN departments d ON d.id = t.department_id
             WHERE t.user_id = ?
             ORDER BY t.created_at DESC
             LIMIT 5"
        );
        $stmt->execute([(int)$user['id']]);
        foreach ($stmt->fetchAll() as $t) {
            $ticketContext .= "- {$t['ticket_code']} \"{$t['subject']}\" | status: {$t['status']}"
                . " | priority: {$t['priority']} | dept: " . ($t['dept_name'] ?: 'n/a')
                . " | assigned to: " . ($t['assignee_name'] ?: 'unassigned')
                . " | created: {$t['created_at']}\n";
        }
    } catch (Exception $e) { /* non-fatal */ }

    // ---- Current ticket thread (when user is on a ticket page or asks about a reply) ----
    $threadContext = '';
    if ($contextTicketId) {
        try {
            $tk = $pdo->prepare(
                "SELECT t.id, t.ticket_code, t.subject, t.status, t.user_id, t.department_id
                 FROM tickets t WHERE t.id = ?"
            );
            $tk->execute([$contextTicketId]);
            $tkRow = $tk->fetch();
            if ($tkRow) {
                $canSee = isStaff()
                    || (int)$tkRow['user_id'] === (int)$user['id']
                    || (!empty($user['dept_id']) && (int)$tkRow['department_id'] === (int)$user['dept_id']);
                if ($canSee) {
                    $threadContext .= "CURRENT TICKET ON SCREEN: {$tkRow['ticket_code']} \"{$tkRow['subject']}\" (status: {$tkRow['status']})\n";
                    $repStmt = $pdo->prepare(
                        "SELECT r.message, r.is_internal, r.created_at, u.name AS author_name, u.role AS author_role
                         FROM ticket_replies r
                         JOIN users u ON u.id = r.user_id
                         WHERE r.ticket_id = ?
                         ORDER BY r.created_at DESC
                         LIMIT 4"
                    );
                    $repStmt->execute([$contextTicketId]);
                    $replies = array_reverse($repStmt->fetchAll());
                    foreach ($replies as $r) {
                        if ($r['is_internal'] && !isStaff()) continue;
                        $threadContext .= "- {$r['author_name']} ({$r['author_role']}): "
                            . mb_strimwidth(str_replace("\n", ' ', $r['message']), 0, 400) . "\n";
                    }
                }
            }
        } catch (Exception $e) { /* non-fatal */ }
    }

    // ---- Real departments & categories so the model can draft classified tickets ----
    $departments = $pdo->query(
        "SELECT id, name FROM departments WHERE is_active = 1 ORDER BY id"
    )->fetchAll();
    $categories = $pdo->query(
        "SELECT id, name, department_id FROM categories WHERE is_active = 1 ORDER BY id"
    )->fetchAll();
    $deptList = implode("\n", array_map(fn($d) => "  {$d['id']} = {$d['name']}", $departments));
    $catList  = implode("\n", array_map(
        fn($c) => "  {$c['id']} = {$c['name']} (belongs to department {$c['department_id']})", $categories
    ));

    $system = 'You are "Belmont Assist", the friendly AI helper inside the Belmont Online Ticketing System '
        . 'of Cebu Belmont, Inc. You are a general workplace guide, but your main purpose is helping employees '
        . 'use the helpdesk: create tickets, check status, reply in conversation threads, use the Knowledge Base, '
        . 'and understand notifications and departments.' . "\n\n"
        . 'PRODUCT KNOWLEDGE — use these EXACT labels when guiding users (never invent different button names):' . "\n"
        . '- Sidebar: Dashboard, Tickets, Department Inbox, New Ticket, Knowledge Base, My Profile.' . "\n"
        . '- On a ticket page: "Conversation Thread" shows messages; below it is the "Add Reply" card.' . "\n"
        . '- Reply form: "Message" field (required), optional file attachment, blue "Send Reply" button.' . "\n"
        . '- Staff also see green "Resolve & Reply" and an "Internal Note" checkbox (hidden from regular users).' . "\n"
        . '- Ticket statuses: open, in progress, pending, resolved, closed. SLA: critical 4h, high 8h, medium 24h, low 48h.' . "\n"
        . '- "Write with AI" on New Ticket helps draft a ticket. CSAT rating after resolution. Notification toggles on Profile.' . "\n\n"
        . 'REPLYING TO STAFF / HELP WITH A CONVERSATION THREAD:' . "\n"
        . 'When the employee asks how to reply, help responding to staff/IT, pastes a staff message, or says "reply this":' . "\n"
        . '- ALWAYS include a full "reply_draft" they can copy — a professional email-style reply in plain text.' . "\n"
        . '- Format the reply_draft exactly like this structure:' . "\n"
        . '  Greeting (e.g. "Hi IT Team,")' . "\n"
        . '  Blank line + short thank-you sentence' . "\n"
        . '  Blank line + numbered answers (1. 2. 3.) — one line per staff question' . "\n"
        . '  Blank line + "Please let me know if you need any additional information."' . "\n"
        . '  Blank line + "Best regards," + sign-off name (employee first name or department)' . "\n"
        . '- Answer each specific question IT/staff asked. When the employee did not specify an answer, use polite '
        . 'professional defaults like the example: "only my PC", "WiFi (Change to LAN cable if applicable.)", '
        . '"No recent changes have been made to my setup." — NOT vague placeholders like "(number) of users".' . "\n"
        . '- In "answer", keep it SHORT (2-3 sentences max, under 60 words): ONLY tell them to scroll to Add Reply, '
        . 'use the suggested reply card below, edit anything in parentheses, then click Send Reply. '
        . 'Do NOT put the reply text itself inside "answer" — that goes ONLY in "reply_draft".' . "\n"
        . '- Set "reply_draft" to null only when the question is NOT about replying to a ticket thread.' . "\n\n"
        . "Respond ONLY with a JSON object with exactly these keys:\n"
        . '"answer": your conversational reply (see rules above). '
        . 'Use the provided knowledge base articles and the employee\'s recent tickets when relevant. '
        . 'FORMATTING: whenever you list steps, examples, options or tips, put EACH item on its own line '
        . 'starting with "1.", "2.", ... (or "-" for non-sequential items), and put a blank line between '
        . 'your intro sentence and the list, and before any closing sentence. Use real line breaks (\n in the JSON string). '
        . 'Never cram a list into one paragraph. '
        . 'Under 150 words, plain text only — no markdown symbols like ** or #.' . "\n"
        . '"sources_used": array of the ARTICLE numbers (e.g. [1, 3]) you actually used to write the answer. '
        . 'ONLY include an article if it genuinely addresses the employee\'s question — return [] when none of them are relevant.' . "\n"
        . '"reply_draft": null, OR the full copy-paste reply text for staff (see REPLYING TO STAFF rules above). '
        . 'Use \\n for line breaks inside the string.' . "\n"
        . '"ticket": null in most cases. ONLY include a ticket draft object when the employee EXPLICITLY asks to '
        . 'create, file, submit, or report a ticket, or asks you to draft/write a ticket for them '
        . '(e.g. "create a ticket for me", "I want to report this", "help me write a ticket about..."). '
        . 'Do NOT include a ticket when they merely describe a problem, ask how to do something, ask for advice, '
        . 'or ask what to write — just answer the question. The draft object has keys:' . "\n"
        . '  "subject": concise professional ticket subject, max 90 characters.' . "\n"
        . '  "description": the ticket description written as 1-2 natural, professional paragraphs in plain text, '
        . 'in first person from the employee\'s perspective. Describe what is happening, since when (if known), '
        . 'what is affected, and how it impacts their work — flowing complete sentences only. '
        . 'NO labels, NO bullet points, NO [bracketed placeholders]. '
        . 'STRICT: include ONLY facts the employee explicitly stated in this conversation. '
        . 'Never claim they already tried troubleshooting steps, never say colleagues are affected, '
        . 'and never add symptoms or timings they did not mention.' . "\n"
        . '  "department_id": integer from DEPARTMENTS — the department whose staff will fix this. '
        . 'The CATEGORIES list is the authority: find the best-matching category first, then use that category\'s department.' . "\n"
        . '  "category_id": integer from CATEGORIES (must belong to the chosen department) or null.' . "\n"
        . '  "priority": "low", "medium", "high" or "critical" (critical = business stopped; high = person fully blocked; '
        . 'medium = degraded but workable; low = requests/supplies).' . "\n"
        . '  "due_days": integer 1-30 realistic working days (critical:1, high:2-3, medium:5-7, low:7-14).' . "\n"
        . 'When you include a ticket, ALSO mention in "answer" that you prepared a ticket draft they can use. '
        . 'When they describe a problem but did not ask for a ticket, help them solve it and optionally end your '
        . 'answer by offering: tell them they can ask you to create a ticket if the problem persists — but keep "ticket" null.';

    // Flatten short conversation history into the prompt
    $convo = '';
    foreach (array_slice($history, -8) as $turn) {
        $role = ($turn['role'] ?? '') === 'assistant' ? 'ASSISTANT' : 'EMPLOYEE';
        $text = trim((string)($turn['content'] ?? ''));
        if ($text !== '') $convo .= "{$role}: " . mb_strimwidth($text, 0, 500, '...') . "\n";
    }

    // Employee sign-off (first name or department)
    $signOff = $user['name'];
    if (mb_strpos($signOff, ' ') !== false) {
        $signOff = trim(mb_substr($signOff, 0, mb_strpos($signOff, ' ')));
    }
    try {
        if (!empty($user['dept_id'])) {
            $dn = $pdo->prepare('SELECT name FROM departments WHERE id = ?');
            $dn->execute([(int)$user['dept_id']]);
            $deptName = $dn->fetchColumn();
            if ($deptName) $signOff = (string)$deptName;
        }
    } catch (Exception $e) { /* non-fatal */ }

    $prompt = "DEPARTMENTS:\n{$deptList}\n\nCATEGORIES:\n{$catList}\n\n"
        . ($kbContext     !== '' ? "KNOWLEDGE BASE ARTICLES:\n{$kbContext}" : '')
        . ($ticketContext !== '' ? "EMPLOYEE'S RECENT TICKETS:\n{$ticketContext}\n" : '')
        . ($threadContext !== '' ? "TICKET THREAD CONTEXT (employee is viewing this ticket now — say scroll down on this page):\n{$threadContext}\n" : '')
        . "EMPLOYEE NAME: {$user['name']} | ROLE: {$user['role']} | SIGN-OFF AS: {$signOff}\n"
        . ($convo !== '' ? "\nCONVERSATION SO FAR:\n{$convo}" : '')
        . "\nEMPLOYEE: \"{$message}\"\n\nReply now as Belmont Assist. JSON only.";

    $out = llmGenerate($prompt, $system, true);
    if ($out === null) return null;

    $parsed = llmExtractJson($out);
    // Fall back to treating the raw output as a plain answer if JSON parsing fails
    if (!is_array($parsed) || empty($parsed['answer'])) {
        return ['answer' => trim($out), 'sources' => [], 'ticket' => null, 'reply_draft' => null];
    }

    // ---- Keep only the sources the model says it actually used ----
    if (array_key_exists('sources_used', $parsed)) {
        $used = is_array($parsed['sources_used']) ? array_map('intval', $parsed['sources_used']) : [];
        $sources = array_values(array_filter(
            $sources,
            fn($s, $i) => in_array($i + 1, $used, true),
            ARRAY_FILTER_USE_BOTH
        ));
    }

    // ---- Validate the optional ticket draft against real data ----
    $ticket = null;
    $t = $parsed['ticket'] ?? null;
    if (is_array($t) && !empty($t['subject']) && !empty($t['description'])) {
        $validDeptIds = array_map(fn($d) => (int)$d['id'], $departments);
        $deptId = (int)($t['department_id'] ?? 0);
        if (!in_array($deptId, $validDeptIds, true)) $deptId = 0;

        $catId = (int)($t['category_id'] ?? 0);
        $catOk = false;
        foreach ($categories as $c) {
            if ((int)$c['id'] === $catId && (!$deptId || (int)$c['department_id'] === $deptId)) { $catOk = true; break; }
        }
        if (!$catOk) $catId = 0;

        $priority = in_array($t['priority'] ?? '', ['low', 'medium', 'high', 'critical'], true)
            ? $t['priority'] : 'medium';
        $dueDays = max(1, min(30, (int)($t['due_days'] ?? 5)));

        $deptName = $catName = null;
        foreach ($departments as $d) if ((int)$d['id'] === $deptId) $deptName = $d['name'];
        foreach ($categories  as $c) if ((int)$c['id'] === $catId)  $catName  = $c['name'];

        $ticket = [
            'subject'         => mb_strimwidth(trim($t['subject']), 0, 200),
            'description'     => trim($t['description']),
            'department_id'   => $deptId ?: null,
            'department_name' => $deptName,
            'category_id'     => $catId ?: null,
            'category_name'   => $catName,
            'priority'        => $priority,
            'due_date'        => date('Y-m-d', strtotime("+{$dueDays} days")),
        ];
    }

    $replyDraft = null;
    $rd = trim((string)($parsed['reply_draft'] ?? ''));
    if ($rd !== '') {
        $replyDraft = $rd;
        // Keep "answer" as brief instructions only — not a duplicate of the draft
        $ans = trim($parsed['answer']);
        if (preg_match('/\n\s*1\.\s/m', $ans)) {
            $ans = trim(preg_replace('/\n\s*1\..*$/s', '', $ans));
        }
        $parsed['answer'] = $ans;
    }

    return ['answer' => trim($parsed['answer']), 'sources' => $sources, 'ticket' => $ticket, 'reply_draft' => $replyDraft];
}

/**
 * Notify all watchers of a ticket (excluding the actor).
 */
function notifyWatchers(PDO $pdo, int $ticketId, int $actorId, string $type, string $message): void
{
    try {
        $stmt = $pdo->prepare(
            "SELECT user_id FROM ticket_watchers WHERE ticket_id = ? AND user_id != ?"
        );
        $stmt->execute([$ticketId, $actorId]);
        foreach ($stmt->fetchAll() as $w) {
            createNotification((int)$w['user_id'], $ticketId, $type, $message);
        }
    } catch (Exception $e) {
        // Watchers table may not exist yet — non-fatal
    }
}
