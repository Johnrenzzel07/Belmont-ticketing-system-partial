/**
 * Paste (and drop) images/files into ticket attachment inputs.
 */
(function (global) {
    const MAX_SIZE = 10 * 1024 * 1024;

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function uniquePastedName(file, index) {
        let ext = 'png';
        if (file.type && file.type.indexOf('/') !== -1) {
            ext = file.type.split('/')[1].replace(/[^a-z0-9]/gi, '') || 'png';
        }
        if (ext === 'jpeg') ext = 'jpg';
        return 'pasted-' + Date.now() + '-' + index + '.' + ext;
    }

    function appendFilesToInput(input, newFiles) {
        if (!input || !newFiles.length) return 0;

        const dt = new DataTransfer();
        const existing = input.files ? Array.from(input.files) : [];
        existing.forEach(function (f) {
            dt.items.add(f);
        });

        let added = 0;
        newFiles.forEach(function (file, i) {
            if (!file || file.size <= 0 || file.size > MAX_SIZE) return;

            let name = file.name || '';
            if (!name || name === 'image.png' || name === 'blob' || !/\./.test(name)) {
                name = uniquePastedName(file, i);
            }
            const toAdd = (file.name === name)
                ? file
                : new File([file], name, { type: file.type || 'application/octet-stream' });
            dt.items.add(toAdd);
            added++;
        });

        if (added) {
            input.files = dt.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        return added;
    }

    function extractImagesFromClipboard(clipboardData) {
        if (!clipboardData || !clipboardData.items) return [];
        const files = [];
        for (let i = 0; i < clipboardData.items.length; i++) {
            const item = clipboardData.items[i];
            if (item.kind === 'file' && item.type && item.type.indexOf('image/') === 0) {
                const f = item.getAsFile();
                if (f) files.push(f);
            }
        }
        return files;
    }

    function clipboardHasPlainText(clipboardData) {
        if (!clipboardData || !clipboardData.items) return false;
        for (let i = 0; i < clipboardData.items.length; i++) {
            const item = clipboardData.items[i];
            if (item.kind === 'string' && item.type === 'text/plain') return true;
        }
        return false;
    }

    function renderFileListPreview(input, container) {
        if (!container) return;
        if (!input || !input.files || !input.files.length) {
            container.innerHTML = '';
            return;
        }
        container.innerHTML = Array.from(input.files).map(function (file) {
            return '<div><i class="bi bi-file-earmark me-1"></i>' + escapeHtml(file.name) +
                ' <span class="text-muted">(' + (file.size / 1024).toFixed(1) + ' KB)</span></div>';
        }).join('');
    }

    function notifyPasted(count) {
        if (!count || typeof global.showToast !== 'function') return;
        const msg = count === 1
            ? 'Image pasted as attachment.'
            : count + ' images pasted as attachments.';
        global.showToast('success', msg);
    }

    function bindTextareaPaste(textarea, fileInput, previewEl) {
        if (!textarea || !fileInput || textarea.dataset.pasteBound === '1') return;
        textarea.dataset.pasteBound = '1';

        textarea.addEventListener('paste', function (e) {
            const images = extractImagesFromClipboard(e.clipboardData);
            if (!images.length) return;

            if (!clipboardHasPlainText(e.clipboardData)) {
                e.preventDefault();
            }

            const n = appendFilesToInput(fileInput, images);
            if (n > 0) {
                renderFileListPreview(fileInput, previewEl);
                notifyPasted(n);
            }
        });
    }

    function bindUploadArea(wrapper, fileInput, previewEl) {
        if (!wrapper || !fileInput || wrapper.dataset.pasteBound === '1') return;
        wrapper.dataset.pasteBound = '1';

        if (!wrapper.hasAttribute('tabindex')) {
            wrapper.setAttribute('tabindex', '0');
        }

        wrapper.addEventListener('paste', function (e) {
            const images = extractImagesFromClipboard(e.clipboardData);
            if (!images.length) return;
            e.preventDefault();
            const n = appendFilesToInput(fileInput, images);
            if (n > 0) {
                renderFileListPreview(fileInput, previewEl);
                notifyPasted(n);
            }
        });

        wrapper.addEventListener('dragover', function (e) {
            e.preventDefault();
            wrapper.classList.add('drag-over');
        });
        wrapper.addEventListener('dragleave', function () {
            wrapper.classList.remove('drag-over');
        });
        wrapper.addEventListener('drop', function (e) {
            e.preventDefault();
            wrapper.classList.remove('drag-over');
            const dropped = e.dataTransfer && e.dataTransfer.files
                ? Array.from(e.dataTransfer.files)
                : [];
            if (!dropped.length) return;
            const n = appendFilesToInput(fileInput, dropped);
            if (n > 0) {
                renderFileListPreview(fileInput, previewEl);
                notifyPasted(n);
            }
        });
    }

    function initThreadRowPaste(row) {
        if (!row) return;
        const ta = row.querySelector('textarea[name*="[message]"]');
        const fileInput = row.querySelector('.thread-files-input');
        const preview = row.querySelector('.thread-files-preview');
        if (ta && fileInput) {
            bindTextareaPaste(ta, fileInput, preview);
        }
    }

    function initAll() {
        document.querySelectorAll('[data-paste-target]').forEach(function (el) {
            const fileInput = document.getElementById(el.dataset.pasteTarget);
            if (!fileInput) return;
            const previewEl = el.dataset.pastePreview
                ? document.getElementById(el.dataset.pastePreview)
                : null;

            if (el.classList.contains('upload-wrapper')) {
                bindUploadArea(el, fileInput, previewEl);
            } else if (el.tagName === 'TEXTAREA') {
                bindTextareaPaste(el, fileInput, previewEl);
            }
        });

        document.querySelectorAll('.thread-row[data-thread-row]').forEach(initThreadRowPaste);
    }

    global.TicketPasteAttachments = {
        appendFilesToInput: appendFilesToInput,
        renderFileListPreview: renderFileListPreview,
        bindTextareaPaste: bindTextareaPaste,
        initThreadRowPaste: initThreadRowPaste,
        initAll: initAll,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})(window);
