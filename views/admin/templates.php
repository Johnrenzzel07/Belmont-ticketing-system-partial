<?php
/**
 * Reply Templates Manager (Staff/Admin)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
isStaff() || (header('Location: ' . APP_URL . '/index.php') && exit);

$pdo       = db();
$pageTitle = 'Reply Templates';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Reply Templates</h1>
        <p class="page-subtitle">Manage canned responses for the ticket reply box.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#templateModal" id="newTplBtn">
        <i class="bi bi-plus-lg me-1"></i>New Template
    </button>
</div>

<div class="card" id="templateList">
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Preview</th>
                    <th class="text-center">Scope</th>
                    <th class="text-center">Author</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody id="tplTbody">
                <tr><td colspan="5" class="text-center text-muted py-4">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tplModalTitle">New Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="tplId">
                <div class="mb-3">
                    <label class="form-label required">Template Title</label>
                    <input type="text" id="tplTitle" class="form-control" placeholder="e.g. Acknowledge Receipt" maxlength="120">
                </div>
                <div class="mb-3">
                    <label class="form-label required">Body</label>
                    <textarea id="tplBody" class="form-control" rows="7"
                              placeholder="Write your canned response here..."></textarea>
                    <div class="form-text">Supports plain text. Will be inserted as-is into the reply box.</div>
                </div>
                <?php if (isAdmin()): ?>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="tplGlobal" checked>
                    <label class="form-check-label" for="tplGlobal">Available to all staff (global)</label>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="tplSaveBtn">Save Template</button>
            </div>
        </div>
    </div>
</div>

<?php ob_start(); ?>
<script>
const API = APP_URL + '/api/templates.php';

function loadTemplates() {
    $.get(API + '?action=list', function(r) {
        if (!r.success) return;
        const rows = r.templates;
        if (!rows.length) {
            $('#tplTbody').html('<tr><td colspan="5" class="text-center text-muted py-4">No templates yet.</td></tr>');
            return;
        }
        let html = '';
        rows.forEach(t => {
            const scope = t.is_global
                ? '<span class="badge bg-primary">Global</span>'
                : '<span class="badge bg-secondary">Personal</span>';
            const preview = t.body.length > 80 ? t.body.slice(0, 80) + '…' : t.body;
            html += `<tr>
                <td><strong>${escHtml(t.title)}</strong></td>
                <td style="font-size:.8rem;color:#64748b;max-width:320px">${escHtml(preview)}</td>
                <td class="text-center">${scope}</td>
                <td class="text-center" style="font-size:.82rem">${escHtml(t.author)}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-primary me-1 tpl-edit"
                            data-id="${t.id}" data-title="${escAttr(t.title)}"
                            data-body="${escAttr(t.body)}" data-global="${t.is_global}">Edit</button>
                    <button class="btn btn-sm btn-outline-danger tpl-del" data-id="${t.id}">Delete</button>
                </td>
            </tr>`;
        });
        $('#tplTbody').html(html);
    }, 'json');
}

// New template
$('#newTplBtn').on('click', function() {
    $('#tplModalTitle').text('New Template');
    $('#tplId').val('');
    $('#tplTitle, #tplBody').val('');
    $('#tplGlobal').prop('checked', true);
});

// Edit
$(document).on('click', '.tpl-edit', function() {
    const $b = $(this);
    $('#tplModalTitle').text('Edit Template');
    $('#tplId').val($b.data('id'));
    $('#tplTitle').val($b.data('title'));
    $('#tplBody').val($b.data('body'));
    $('#tplGlobal').prop('checked', $b.data('global') == 1);
    new bootstrap.Modal(document.getElementById('templateModal')).show();
});

// Save
$('#tplSaveBtn').on('click', function() {
    const id    = $('#tplId').val();
    const title = $('#tplTitle').val().trim();
    const body  = $('#tplBody').val().trim();
    if (!title || !body) { showToast('warning', 'Title and body are required.'); return; }

    const action = id ? 'update' : 'create';
    $.post(API, {
        action, id, title, body,
        is_global: $('#tplGlobal').is(':checked') ? 1 : 0,
        _csrf: CSRF_TOKEN
    }, function(r) {
        if (r.success) {
            bootstrap.Modal.getInstance(document.getElementById('templateModal')).hide();
            showToast('success', r.message);
            loadTemplates();
        } else {
            showToast('danger', r.message);
        }
    }, 'json');
});

// Delete
$(document).on('click', '.tpl-del', function() {
    if (!confirm('Delete this template?')) return;
    $.post(API, { action: 'delete', id: $(this).data('id'), _csrf: CSRF_TOKEN }, function(r) {
        if (r.success) { showToast('success', 'Template deleted.'); loadTemplates(); }
        else showToast('danger', r.message);
    }, 'json');
});

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function escAttr(s) {
    return String(s).replace(/"/g,'&quot;').replace(/\n/g,'&#10;');
}

loadTemplates();
</script>
<?php $extraScripts = ob_get_clean(); ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
