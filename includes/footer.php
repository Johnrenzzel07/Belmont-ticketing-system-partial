            </div><!-- /.page-content -->
        </main><!-- /#appMain -->
    </div><!-- /.app-layout -->

    <!-- ===== BOTTOM FOOTER BAR ===== -->
    <footer class="app-footer" id="appFooter">
        <div class="footer-status">
            <span class="status-dot"></span>
            <span><?= APP_NAME ?> v<?= APP_VERSION ?> &mdash; System Operational</span>
        </div>
        <div class="footer-links">
            <a href="#">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">API Status</a>
        </div>
    </footer>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <!-- Custom JS -->
    <script src="<?= APP_URL ?>/assets/js/main.js"></script>

    <script>
    const APP_URL    = '<?= APP_URL ?>';
    const CSRF_TOKEN = '<?= getCsrfToken() ?>';
    const USER_ROLE  = '<?= currentUser()['role'] ?>';
    </script>

    <?= $extraScripts ?? '' ?>

    <?php if (!empty($_SESSION['user_id'])) include __DIR__ . '/ai_widget.php'; ?>

    <script>
    // Notifications polling
    function loadNotifications() {
        $.get(APP_URL + '/api/notifications.php', function(res) {
            if (res.count > 0) {
                $('#notifCount').text(res.count).show();
                let html = '';
                res.notifications.forEach(function(n) {
                    const icons = {
                        assigned:       'bi-person-check',
                        updated:        'bi-pencil-square',
                        replied:        'bi-chat-dots',
                        status_changed: 'bi-arrow-repeat',
                        new_ticket:     'bi-ticket',
                    };
                    const icon = icons[n.type] || 'bi-bell';
                    html += `<a class="notif-item text-decoration-none"
                                href="${APP_URL}/views/tickets/view.php?id=${n.ticket_id}"
                                data-notif-id="${n.id}">
                        <div class="notif-icon"><i class="bi ${icon}"></i></div>
                        <div class="flex-grow-1 min-w-0">
                            <div class="notif-msg">${n.message}</div>
                            <div class="notif-time">${n.created_at_ago ?? ''}</div>
                        </div>
                    </a>`;
                });
                $('#notifList').html(html);
            } else {
                $('#notifCount').hide();
                $('#notifList').html('<div class="text-center py-4 text-muted small"><i class="bi bi-bell-slash fs-4 d-block mb-1"></i>No notifications</div>');
            }
        }, 'json').fail(function(){});
    }

    $(document).ready(function() {
        loadNotifications();
        setInterval(loadNotifications, 30000);

        $('#markAllRead').on('click', function(e) {
            e.preventDefault();
            $.post(APP_URL + '/api/notifications.php', {action:'mark_read', _csrf: CSRF_TOKEN}, function() {
                loadNotifications();
            });
        });

        // Mark individual notification as read when clicked
        $(document).on('click', '.notif-item[data-notif-id]', function(e) {
            const notifId = $(this).data('notif-id');
            const href    = $(this).attr('href');
            e.preventDefault();
            $.post(APP_URL + '/api/notifications.php',
                {action: 'mark_one', id: notifId, _csrf: CSRF_TOKEN},
                function() { window.location.href = href; }
            ).fail(function() { window.location.href = href; });
        });
    });
    </script>
</body>
</html>
