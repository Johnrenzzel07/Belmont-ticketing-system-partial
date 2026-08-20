<?php
/**
 * Knowledge Base API
 * Actions: search, ai_answer, list_categories
 * Search uses per-word relevance scoring; ai_answer composes a direct
 * answer from the published articles using the LLM (RAG).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai_helpers.php';

requireLogin();
header('Content-Type: application/json');

$pdo    = db();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    case 'search':
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) jsonResponse(true, 'OK', ['results' => []]);

        // Word-based relevance scoring: title hits count 5x body hits,
        // exact phrase in the title counts most. Popular articles break ties.
        $articles = $pdo->query(
            "SELECT id, title, slug, body, views FROM kb_articles WHERE is_published = 1"
        )->fetchAll();

        $qLower = mb_strtolower($q);
        $words  = array_filter(
            preg_split('/[^a-z0-9]+/', $qLower),
            fn($w) => mb_strlen($w) >= 3
        );

        $scored = [];
        foreach ($articles as $a) {
            $titleLower = mb_strtolower($a['title']);
            $bodyLower  = mb_strtolower($a['body']);
            $score = 0;
            if (mb_strpos($titleLower, $qLower) !== false) $score += 20;
            foreach ($words as $w) {
                if (mb_strpos($titleLower, $w) !== false) $score += 5;
                if (mb_strpos($bodyLower, $w) !== false)  $score += 1;
            }
            if ($score > 0) {
                $scored[] = [
                    'id'      => (int)$a['id'],
                    'title'   => $a['title'],
                    'slug'    => $a['slug'],
                    'excerpt' => mb_strimwidth($a['body'], 0, 120, '...'),
                    'score'   => $score,
                    'views'   => (int)$a['views'],
                ];
            }
        }
        usort($scored, fn($x, $y) => [$y['score'], $y['views']] <=> [$x['score'], $x['views']]);
        jsonResponse(true, 'OK', ['results' => array_slice($scored, 0, 5)]);
        break;

    // ---- AI answer composed from the knowledge base (RAG) ----
    case 'ai_answer':
        $q = trim($_POST['q'] ?? $_GET['q'] ?? '');
        if (mb_strlen($q) < 4) jsonResponse(false, 'Question too short.');
        if (!llmAvailable())   jsonResponse(true, 'OK', ['answer' => null]);

        $result = aiKbAnswer($pdo, $q);
        if (!$result) jsonResponse(true, 'OK', ['answer' => null]);

        jsonResponse(true, 'OK', [
            'answer'  => $result['answer'],
            'sources' => $result['sources'],
        ]);
        break;

    case 'list_categories':
        $cats = $pdo->query(
            "SELECT c.*, COUNT(a.id) AS article_count
             FROM kb_categories c
             LEFT JOIN kb_articles a ON a.kb_cat_id = c.id AND a.is_published = 1
             GROUP BY c.id ORDER BY c.sort_order ASC"
        )->fetchAll();
        jsonResponse(true, 'OK', ['categories' => $cats]);
        break;

    default:
        jsonResponse(false, 'Unknown action.');
}
