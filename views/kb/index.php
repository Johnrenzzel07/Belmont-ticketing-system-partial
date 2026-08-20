<?php
/**
 * Knowledge Base — Browse
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$pdo = db();

$categories = $pdo->query(
    "SELECT c.*, COUNT(a.id) AS article_count
     FROM kb_categories c
     LEFT JOIN kb_articles a ON a.kb_cat_id = c.id AND a.is_published = 1
     GROUP BY c.id ORDER BY c.sort_order ASC"
)->fetchAll();

$recent = $pdo->query(
    "SELECT a.id, a.title, a.slug, a.views,
            c.name AS cat_name, u.name AS author_name, a.created_at
     FROM kb_articles a
     LEFT JOIN kb_categories c ON c.id = a.kb_cat_id
     LEFT JOIN users u ON u.id = a.author_id
     WHERE a.is_published = 1
     ORDER BY a.created_at DESC
     LIMIT 6"
)->fetchAll();

$search   = trim($_GET['q'] ?? '');
$results  = [];
if ($search) {
    $like    = '%' . $search . '%';
    $stmt    = $pdo->prepare(
        "SELECT a.id, a.title, a.slug, a.views,
                c.name AS cat_name, SUBSTRING(a.body,1,160) AS excerpt
         FROM kb_articles a
         LEFT JOIN kb_categories c ON c.id = a.kb_cat_id
         WHERE a.is_published = 1 AND (a.title LIKE ? OR a.body LIKE ?)
         ORDER BY a.views DESC LIMIT 20"
    );
    $stmt->execute([$like, $like]);
    $results = $stmt->fetchAll();
}

$pageTitle = 'Knowledge Base';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Knowledge Base</h1>
        <p class="page-subtitle">Find answers to common questions and issues.</p>
    </div>
    <?php if (isStaff()): ?>
    <a href="<?= APP_URL ?>/views/admin/kb_edit.php" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>New Article
    </a>
    <?php endif; ?>
</div>

<!-- Search Bar -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="q" class="form-control" placeholder="Search articles..."
                   value="<?= e($search) ?>" autofocus>
            <button class="btn btn-primary px-4" type="submit">
                <i class="bi bi-search me-1"></i>Search
            </button>
            <?php if ($search): ?>
            <a href="?" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($search): ?>
<!-- AI Answer (filled asynchronously) -->
<div class="card mb-4 d-none" id="kbAiCard" style="border-left:4px solid var(--bs-primary,#0d6efd)">
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-robot text-primary"></i>
            <span class="fw-600">AI Answer</span>
            <span class="badge bg-success-subtle text-success" style="font-size:.62rem">Based on your knowledge base</span>
        </div>
        <div id="kbAiAnswer" style="white-space:pre-line;font-size:.9rem"></div>
        <div id="kbAiSources" class="mt-2" style="font-size:.78rem"></div>
    </div>
</div>
<div class="text-muted mb-3 d-none" id="kbAiLoading" style="font-size:.85rem">
    <span class="spinner-border spinner-border-sm me-1"></span>AI is reading the knowledge base...
</div>

<!-- Search Results -->
<div class="mb-4">
    <h5 class="mb-3" style="font-weight:600">
        <?= count($results) ?> result<?= count($results) != 1 ? 's' : '' ?> for "<?= e($search) ?>"
    </h5>
    <?php if (empty($results)): ?>
        <div class="alert alert-light text-muted">No articles matched your search. Try different keywords.</div>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($results as $a): ?>
        <div class="col-12">
            <a href="<?= APP_URL ?>/views/kb/article.php?slug=<?= urlencode($a['slug']) ?>"
               class="kb-result-card card">
                <div class="card-body">
                    <div class="kb-article-title"><?= e($a['title']) ?></div>
                    <div class="kb-article-excerpt"><?= e(rtrim($a['excerpt'],'.').'...') ?></div>
                    <div class="kb-article-meta">
                        <?= e($a['cat_name'] ?? 'General') ?> &middot; <?= (int)$a['views'] ?> views
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- Categories Grid -->
<div class="row g-3 mb-4">
    <?php foreach ($categories as $cat): ?>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="?cat=<?= $cat['id'] ?>" class="kb-cat-card card text-decoration-none">
            <div class="card-body text-center">
                <i class="bi <?= e($cat['icon'] ?? 'bi-folder') ?> kb-cat-icon"></i>
                <div class="kb-cat-name"><?= e($cat['name']) ?></div>
                <div class="kb-cat-count"><?= (int)$cat['article_count'] ?> article<?= $cat['article_count'] != 1 ? 's' : '' ?></div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- Category Filter Results -->
<?php if (isset($_GET['cat']) && (int)$_GET['cat']): ?>
<?php
    $catId   = (int)$_GET['cat'];
    $catInfo = $pdo->prepare("SELECT name FROM kb_categories WHERE id = ?");
    $catInfo->execute([$catId]);
    $catInfo = $catInfo->fetch();
    $catArts = $pdo->prepare(
        "SELECT a.id, a.title, a.slug, a.views, SUBSTRING(a.body,1,120) AS excerpt, a.created_at
         FROM kb_articles a WHERE a.kb_cat_id = ? AND a.is_published = 1 ORDER BY a.views DESC"
    );
    $catArts->execute([$catId]);
    $catArts = $catArts->fetchAll();
?>
<h5 class="mb-3" style="font-weight:600"><?= e($catInfo['name'] ?? '') ?></h5>
<div class="row g-3 mb-4">
    <?php foreach ($catArts as $a): ?>
    <div class="col-12 col-md-6">
        <a href="<?= APP_URL ?>/views/kb/article.php?slug=<?= urlencode($a['slug']) ?>"
           class="kb-result-card card">
            <div class="card-body">
                <div class="kb-article-title"><?= e($a['title']) ?></div>
                <div class="kb-article-excerpt"><?= e(mb_strimwidth($a['excerpt'],0,100,'...')) ?></div>
                <div class="kb-article-meta"><?= (int)$a['views'] ?> views</div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
    <?php if (empty($catArts)): ?>
    <div class="col-12"><p class="text-muted">No published articles in this category yet.</p></div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- Recent Articles -->
<h5 class="mb-3" style="font-weight:600">Recent Articles</h5>
<div class="row g-3">
    <?php foreach ($recent as $a): ?>
    <div class="col-12 col-md-6 col-lg-4">
        <a href="<?= APP_URL ?>/views/kb/article.php?slug=<?= urlencode($a['slug']) ?>"
           class="kb-result-card card">
            <div class="card-body">
                <div class="kb-cat-pill mb-2"><?= e($a['cat_name'] ?? 'General') ?></div>
                <div class="kb-article-title"><?= e($a['title']) ?></div>
                <div class="kb-article-meta mt-1"><?= e($a['author_name']) ?> &middot; <?= (int)$a['views'] ?> views</div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
    <?php if (empty($recent)): ?>
    <div class="col-12"><p class="text-muted">No published articles yet.</p></div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($search): ?>
<?php ob_start(); ?>
<script>
// Ask the AI for a direct answer based on the knowledge base (async, non-blocking)
(function() {
    const q = <?= json_encode($search) ?>;
    const $card    = document.getElementById('kbAiCard');
    const $loading = document.getElementById('kbAiLoading');
    $loading.classList.remove('d-none');

    $.post(APP_URL + '/api/kb.php', { action: 'ai_answer', q: q, _csrf: CSRF_TOKEN }, function(r) {
        $loading.classList.add('d-none');
        if (!r.success || !r.answer) return; // AI off or no answer — keyword results still shown
        document.getElementById('kbAiAnswer').textContent = r.answer;
        if (r.sources && r.sources.length) {
            document.getElementById('kbAiSources').innerHTML = 'Sources: ' + r.sources.map(function(s) {
                return '<a href="' + APP_URL + '/views/kb/article.php?slug=' + encodeURIComponent(s.slug) + '">'
                    + $('<span>').text(s.title).html() + '</a>';
            }).join(' &middot; ');
        }
        $card.classList.remove('d-none');
    }, 'json').fail(function() {
        $loading.classList.add('d-none');
    });
})();
</script>
<?php $extraScripts = ob_get_clean(); ?>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
