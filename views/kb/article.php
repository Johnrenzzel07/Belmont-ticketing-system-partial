<?php
/**
 * Knowledge Base — Single Article
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$pdo  = db();
$slug = trim($_GET['slug'] ?? '');

if (!$slug) {
    header('Location: ' . APP_URL . '/views/kb/index.php');
    exit;
}

$article = $pdo->prepare(
    "SELECT a.*, c.name AS cat_name, u.name AS author_name
     FROM kb_articles a
     LEFT JOIN kb_categories c ON c.id = a.kb_cat_id
     LEFT JOIN users u ON u.id = a.author_id
     WHERE a.slug = ? AND a.is_published = 1
     LIMIT 1"
);
$article->execute([$slug]);
$article = $article->fetch();

if (!$article) {
    http_response_code(404);
    header('Location: ' . APP_URL . '/views/kb/index.php');
    exit;
}

// Increment view counter
$pdo->prepare("UPDATE kb_articles SET views = views + 1 WHERE id = ?")
    ->execute([$article['id']]);

// Related articles in same category
$related = [];
if ($article['kb_cat_id']) {
    $stmt = $pdo->prepare(
        "SELECT id, title, slug FROM kb_articles
         WHERE kb_cat_id = ? AND id != ? AND is_published = 1
         ORDER BY views DESC LIMIT 4"
    );
    $stmt->execute([$article['kb_cat_id'], $article['id']]);
    $related = $stmt->fetchAll();
}

$pageTitle = $article['title'] . ' — Knowledge Base';
include __DIR__ . '/../../includes/header.php';

// Convert plain text body to simple HTML (line breaks to paragraphs, **bold**)
function kbBodyToHtml(string $text): string {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    // **bold**
    $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
    // Numbered lists
    $text = preg_replace('/^(\d+\..+)$/m', '<li>$1</li>', $text);
    $text = preg_replace('/(<li>.*<\/li>)/s', '<ol>$1</ol>', $text);
    // Bullet lists
    $text = preg_replace('/^(-\s.+)$/m', '<li>$1</li>', $text);
    $text = preg_replace('/(<li>-\s.*<\/li>)/s', '<ul>$1</ul>', $text);
    // Paragraphs
    $paragraphs = preg_split('/\n\s*\n/', $text);
    $out = '';
    foreach ($paragraphs as $p) {
        $p = trim($p);
        if (!$p) continue;
        if (str_starts_with($p, '<ol>') || str_starts_with($p, '<ul>')) {
            $out .= $p;
        } else {
            $out .= '<p>' . nl2br($p) . '</p>';
        }
    }
    return $out;
}
?>

<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/views/kb/index.php">Knowledge Base</a></li>
        <?php if ($article['cat_name']): ?>
        <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/views/kb/index.php?cat=<?= $article['kb_cat_id'] ?>">
                <?= e($article['cat_name']) ?>
            </a>
        </li>
        <?php endif; ?>
        <li class="breadcrumb-item active"><?= e(mb_strimwidth($article['title'],0,40,'...')) ?></li>
    </ol>
</nav>

<div class="row g-3">
    <!-- Main Article -->
    <div class="col-lg-8">
        <div class="card kb-article-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <?php if ($article['cat_name']): ?>
                    <span class="kb-cat-pill me-2"><?= e($article['cat_name']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (isStaff()): ?>
                <a href="<?= APP_URL ?>/views/admin/kb_edit.php?id=<?= $article['id'] ?>"
                   class="btn btn-sm btn-outline-primary">Edit Article</a>
                <?php endif; ?>
            </div>
            <div class="card-body kb-body-wrap">
                <h1 style="font-size:1.35rem;font-weight:700;margin-bottom:.5rem"><?= e($article['title']) ?></h1>
                <div class="kb-article-meta mb-4">
                    By <?= e($article['author_name']) ?>
                    &middot; <?= date('M d, Y', strtotime($article['created_at'])) ?>
                    &middot; <?= (int)$article['views'] ?> views
                </div>
                <div class="kb-body-content">
                    <?= kbBodyToHtml($article['body']) ?>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <a href="<?= APP_URL ?>/views/kb/index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Back to KB
                </a>
                <a href="<?= APP_URL ?>/views/tickets/create.php" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-ticket me-1"></i>Still need help? Open a ticket
                </a>
            </div>
        </div>
    </div>

    <!-- Sidebar: Related -->
    <div class="col-lg-4">
        <?php if (!empty($related)): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-link-45deg me-2 text-primary"></i>Related Articles</div>
            <div class="list-group list-group-flush">
                <?php foreach ($related as $r): ?>
                <a href="<?= APP_URL ?>/views/kb/article.php?slug=<?= urlencode($r['slug']) ?>"
                   class="list-group-item list-group-item-action py-2"
                   style="font-size:.85rem">
                    <?= e($r['title']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body text-center" style="padding:1.25rem">
                <div style="font-weight:600;font-size:.88rem;margin-bottom:.5rem">Couldn't find your answer?</div>
                <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:.75rem">
                    Our support team is ready to help you.
                </p>
                <a href="<?= APP_URL ?>/views/tickets/create.php" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-ticket me-1"></i>Submit a Ticket
                </a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
