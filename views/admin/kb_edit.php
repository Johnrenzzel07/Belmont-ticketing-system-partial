<?php
/**
 * Knowledge Base Article Editor (Staff/Admin)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
isStaff() || (header('Location: ' . APP_URL . '/index.php') && exit);

$pdo    = db();
$user   = currentUser();
$id     = (int)($_GET['id'] ?? 0);
$error  = '';
$done   = false;

// Load article for editing
$article = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM kb_articles WHERE id = ?");
    $stmt->execute([$id]);
    $article = $stmt->fetch();
}

$categories = $pdo->query("SELECT * FROM kb_categories ORDER BY sort_order ASC")->fetchAll();

function makeSlug(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9\s-]/', '', $s);
    $s = preg_replace('/[\s-]+/', '-', $s);
    return trim($s, '-');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title     = trim($_POST['title']       ?? '');
    $body      = trim($_POST['body']        ?? '');
    $catId     = (int)($_POST['kb_cat_id']  ?? 0) ?: null;
    $published = (int)($_POST['is_published'] ?? 0);
    $slug      = makeSlug($title);

    if (!$title || !$body) {
        $error = 'Title and body are required.';
    } else {
        if ($id) {
            // Ensure slug is unique excluding self
            $exists = $pdo->prepare("SELECT id FROM kb_articles WHERE slug = ? AND id != ?");
            $exists->execute([$slug, $id]);
            if ($exists->fetch()) $slug .= '-' . $id;

            $pdo->prepare(
                "UPDATE kb_articles SET title=?, slug=?, body=?, kb_cat_id=?, is_published=? WHERE id=?"
            )->execute([$title, $slug, $body, $catId, $published, $id]);
        } else {
            $exists = $pdo->prepare("SELECT id FROM kb_articles WHERE slug = ?");
            $exists->execute([$slug]);
            if ($exists->fetch()) $slug .= '-' . time();

            $pdo->prepare(
                "INSERT INTO kb_articles (author_id, title, slug, body, kb_cat_id, is_published)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$user['id'], $title, $slug, $body, $catId, $published]);
            $id = (int)$pdo->lastInsertId();
        }
        $done = true;
        header('Location: ' . APP_URL . '/views/kb/article.php?slug=' . $slug);
        exit;
    }
}

$pageTitle = ($article ? 'Edit' : 'New') . ' KB Article';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <nav aria-label="breadcrumb" class="mb-1">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/views/kb/index.php">Knowledge Base</a></li>
                <li class="breadcrumb-item active"><?= $article ? 'Edit Article' : 'New Article' ?></li>
            </ol>
        </nav>
        <h1 class="page-title"><?= $article ? 'Edit Article' : 'New KB Article' ?></h1>
    </div>
    <?php if ($article): ?>
    <a href="<?= APP_URL ?>/views/kb/article.php?slug=<?= urlencode($article['slug']) ?>"
       class="btn btn-outline-secondary btn-sm">Preview</a>
    <?php endif; ?>
</div>

<?php if ($error): ?>
<div class="alert alert-danger py-2 px-3 mb-3"><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form method="POST">
            <div class="card mb-3">
                <div class="card-header">Article Content</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label required">Title</label>
                        <input type="text" name="title" class="form-control"
                               value="<?= e($article['title'] ?? '') ?>"
                               placeholder="Article title" required maxlength="255">
                    </div>
                    <div class="mb-0">
                        <label class="form-label required">Body</label>
                        <div class="form-text mb-1">
                            Supports **bold**, numbered lists (1. item), and bullet lists (- item).
                        </div>
                        <textarea name="body" class="form-control" rows="18"
                                  placeholder="Write your article here..." required><?= e($article['body'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-save me-1"></i><?= $article ? 'Save Changes' : 'Publish Article' ?>
            </button>
            <a href="<?= APP_URL ?>/views/kb/index.php" class="btn btn-outline-secondary btn-lg ms-2">Cancel</a>
        </form>
    </div>
    <div class="col-lg-4">
        <div class="card mb-3 sticky-top" style="top:80px">
            <div class="card-header">Article Options</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Category</label>
                    <select name="kb_cat_id" form="<?= '' /* links to main form via JS */ ?>" class="form-select" id="kbCatSel">
                        <option value="">-- Uncategorised --</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>"
                            <?= ($article['kb_cat_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
                            <?= e($c['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-check mb-0">
                    <input type="checkbox" class="form-check-input" id="kbPublished" name="is_published"
                           value="1" <?= ($article['is_published'] ?? 0) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="kbPublished">Published (visible to all staff)</label>
                </div>
            </div>
        </div>

        <?php if ($article && isAdmin()): ?>
        <div class="card border-danger">
            <div class="card-body text-center">
                <small class="text-danger">Danger Zone</small>
                <button type="button" class="btn btn-sm btn-outline-danger w-100 mt-2" id="delArtBtn">
                    <i class="bi bi-trash me-1"></i>Delete Article
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Sync right-panel inputs to main form on submit
document.querySelector('[type="submit"]').closest('form').addEventListener('submit', function() {
    ['kb_cat_id','is_published'].forEach(name => {
        const src = document.querySelector(`[name="${name}"]:not([form])`);
        if (src && !this.querySelector(`[name="${name}"]`)) {
            const h = document.createElement('input');
            h.type = 'hidden'; h.name = name;
            h.value = (src.type === 'checkbox') ? (src.checked ? 1 : 0) : src.value;
            this.appendChild(h);
        }
    });
    document.querySelector('#kbCatSel')?.setAttribute('form','');
    document.querySelector('#kbPublished')?.setAttribute('form','');
});

<?php if ($article && isAdmin()): ?>
document.getElementById('delArtBtn')?.addEventListener('click', function() {
    if (!confirm('Delete this article permanently?')) return;
    $.post(APP_URL + '/api/kb.php', {
        action: 'delete', id: <?= $id ?>, _csrf: CSRF_TOKEN
    }, function(r) {
        if (r.success) window.location = APP_URL + '/views/kb/index.php';
        else showToast('danger', r.message);
    }, 'json');
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
