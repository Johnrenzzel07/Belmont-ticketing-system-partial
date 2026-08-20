/* ============================================================
   main.js - Belmont Helpdesk
   ============================================================ */

$(document).ready(function () {

    /* ---- Sidebar Toggle ---- */
    const $body    = $('body');
    const $sidebar = $('#appSidebar');
    const $toggle  = $('#sidebarToggle');

    // Restore state
    if (localStorage.getItem('sidebarCollapsed') === '1' && window.innerWidth > 992) {
        $body.addClass('sidebar-collapsed');
    }

    $toggle.on('click', function () {
        if (window.innerWidth <= 992) {
            $body.toggleClass('sidebar-open');
        } else {
            $body.toggleClass('sidebar-collapsed');
            localStorage.setItem('sidebarCollapsed',
                $body.hasClass('sidebar-collapsed') ? '1' : '0');
        }
    });

    // Close sidebar overlay on click
    $body.on('click', function (e) {
        if ($(e.target).closest('#appSidebar, #sidebarToggle').length === 0) {
            $body.removeClass('sidebar-open');
        }
    });

    /* ---- Auto-dismiss flash alerts ---- */
    setTimeout(function () {
        $('.alert-flash').fadeOut(400, function () { $(this).remove(); });
    }, 5000);

    /* ---- AJAX Status Update ---- */
    $(document).on('change', '.ajax-status-select', function () {
        const $select   = $(this);
        const ticketId  = $select.data('ticket-id');
        const newStatus = $select.val();
        const $spinner  = $('<span class="spinner-border spinner-border-sm ms-2">');
        $select.after($spinner);

        $.ajax({
            url: APP_URL + '/api/tickets.php',
            method: 'POST',
            data: {
                action:    'update_status',
                ticket_id: ticketId,
                status:    newStatus,
                _csrf:     CSRF_TOKEN
            },
            dataType: 'json',
            success: function (res) {
                $spinner.remove();
                if (res.success) {
                    showToast('success', res.message);
                    const $badge = $select.closest('tr, .ticket-detail-header').find('.status-badge');
                    if ($badge.length) {
                        $badge.replaceWith(res.badge_html);
                    }
                } else {
                    showToast('danger', res.message || 'Update failed.');
                }
            },
            error: function () {
                $spinner.remove();
                showToast('danger', 'Network error. Please try again.');
            }
        });
    });

    /* ---- AJAX Priority Update ---- */
    $(document).on('change', '.ajax-priority-select', function () {
        const $select  = $(this);
        const ticketId = $select.data('ticket-id');
        const priority = $select.val();

        $.ajax({
            url: APP_URL + '/api/tickets.php',
            method: 'POST',
            data: { action: 'update_priority', ticket_id: ticketId, priority: priority, _csrf: CSRF_TOKEN },
            dataType: 'json',
            success: function (res) {
                showToast(res.success ? 'success' : 'danger', res.message);
            }
        });
    });

    /* ---- AJAX Assign Ticket ---- */
    $(document).on('change', '.ajax-assign-select', function () {
        const $select  = $(this);
        const ticketId = $select.data('ticket-id');
        const staffId  = $select.val();

        $.ajax({
            url: APP_URL + '/api/tickets.php',
            method: 'POST',
            data: { action: 'assign', ticket_id: ticketId, staff_id: staffId, _csrf: CSRF_TOKEN },
            dataType: 'json',
            success: function (res) {
                showToast(res.success ? 'success' : 'danger', res.message);
            }
        });
    });


    /* ---- File Upload Drag & Drop ---- */
    const $uploadArea = $('.upload-area');
    if ($uploadArea.length) {
        $uploadArea.on('dragover', function (e) {
            e.preventDefault();
            $(this).addClass('drag-over');
        }).on('dragleave drop', function () {
            $(this).removeClass('drag-over');
        }).on('click', function () {
            $(this).closest('.upload-wrapper').find('input[type="file"]').trigger('click');
        });
    }

    /* ---- Table Row Click ---- */
    $(document).on('click', '.clickable-row', function (e) {
        if (!$(e.target).closest('a, button, select, input, .dropdown').length) {
            const href = $(this).data('href');
            if (href) window.location.href = href;
        }
    });

    /* ---- Confirm Delete ---- */
    $(document).on('click', '.confirm-delete', function (e) {
        e.preventDefault();
        const href = $(this).attr('href') || $(this).data('href');
        const msg  = $(this).data('message') || 'Are you sure you want to delete this?';
        if (confirm(msg)) {
            window.location.href = href;
        }
    });

    /* ---- Search with debounce ---- */
    let searchTimer;
    $(document).on('input', '.table-search input', function () {
        clearTimeout(searchTimer);
        const $input = $(this);
        searchTimer = setTimeout(function () {
            const $form = $input.closest('form');
            if ($form.length) $form.submit();
        }, 500);
    });

    /* ---- Tooltip init ---- */
    const tooltipEls = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipEls.forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover' }));

    /* ---- Select2-like multi-select for filters ---- */
    // (using native Bootstrap)

    /* ---- Print support ---- */
    $(document).on('click', '#printTicket', function () {
        window.print();
    });

    /* ---- Copy ticket code ---- */
    $(document).on('click', '.copy-code', function () {
        const code = $(this).data('code');
        navigator.clipboard.writeText(code).then(function () {
            showToast('success', 'Ticket code copied!');
        });
    });

});

/* ---- Toast notification ---- */
function showToast(type, message) {
    const icons = {
        success: 'bi-check-circle-fill',
        danger:  'bi-x-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info:    'bi-info-circle-fill'
    };
    const colors = {
        success: '#16a34a',
        danger:  '#dc2626',
        warning: '#d97706',
        info:    '#0891b2'
    };
    const icon  = icons[type]  || icons.info;
    const color = colors[type] || colors.info;

    const $container = $('#toastContainer');
    if (!$container.length) {
        $('body').append('<div id="toastContainer" style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem;"></div>');
    }

    const id = 'toast_' + Date.now();
    const $toast = $(`
        <div id="${id}" style="
            background:#fff;
            border:1px solid #e2e8f0;
            border-left:4px solid ${color};
            border-radius:8px;
            padding:.75rem 1rem;
            display:flex;
            align-items:center;
            gap:.6rem;
            box-shadow:0 4px 16px rgba(0,0,0,.1);
            min-width:240px;
            max-width:340px;
            font-size:.83rem;
            font-family:'Inter',sans-serif;
            animation:toastIn .3s ease;
        ">
            <i class="bi ${icon}" style="color:${color};font-size:1.1rem;flex-shrink:0"></i>
            <span style="flex:1;color:#0f172a">${message}</span>
            <button onclick="$('#${id}').remove()" style="background:none;border:none;color:#94a3b8;cursor:pointer;padding:0;font-size:1rem;">&times;</button>
        </div>
    `);

    $('<style>.toast-in{}</style>').appendTo('head');
    $('#toastContainer').append($toast);

    setTimeout(function () {
        $toast.fadeOut(400, function () { $(this).remove(); });
    }, 4000);
}

/* ---- Chart.js defaults ---- */
if (typeof Chart !== 'undefined') {
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.font.size   = 12;
    Chart.defaults.color       = '#6b7280';
    Chart.defaults.plugins.legend.position = 'bottom';
    Chart.defaults.plugins.legend.labels.padding = 16;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
}
