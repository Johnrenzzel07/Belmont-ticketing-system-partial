<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> - Cebu Belmont, Inc. Internal Support System">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' | ' : '' ?><?= APP_NAME ?></title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">

    <?= $extraHead ?? '' ?>
</head>
<body class="app-body">
    <?php $flash = getFlashMessages(); ?>

    <!-- ===== TOP NAVBAR ===== -->
    <nav class="app-navbar" id="topNavbar">

        <!-- Brand spacer (mirrors sidebar width) -->
        <div class="navbar-brand-spacer">
            <button class="sidebar-toggle me-2" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
                <i class="bi bi-list"></i>
            </button>
            <a class="d-flex align-items-center text-decoration-none" href="<?= APP_URL ?>/index.php">
                <img src="<?= APP_URL ?>/public/images/logo.png"
                     alt="<?= APP_NAME ?>"
                     style="height:36px;width:auto;object-fit:contain;display:block;">
            </a>
        </div>

        <!-- Center: Search -->
        <div class="navbar-center">
            <?php if (isStaff()): ?>
            <form action="<?= APP_URL ?>/views/tickets/index.php" method="GET" class="navbar-search">
                <i class="bi bi-search search-icon"></i>
                <input type="text" name="q" class="form-control search-input"
                       placeholder="Search tickets, users, articles..."
                       value="<?= e($_GET['q'] ?? '') ?>" autocomplete="off">
                <div class="search-shortcuts">
                    <kbd>/</kbd>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <!-- Right: Actions -->
        <div class="navbar-right">

            <!-- Help -->
            <button class="btn-icon" title="Help &amp; Documentation" aria-label="Help">
                <i class="bi bi-question-circle"></i>
            </button>

            <!-- Notifications Bell -->
            <div class="dropdown notif-dropdown position-relative">
                <button class="btn-icon position-relative" id="notifBell"
                        data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                    <i class="bi bi-bell fs-5"></i>
                    <span class="notif-badge" id="notifCount" style="display:none"></span>
                </button>
                <div class="dropdown-menu dropdown-menu-end notif-menu p-0" id="notifPanel">
                    <div class="notif-header d-flex justify-content-between align-items-center">
                        <strong style="font-size:.82rem">Notifications</strong>
                        <a href="#" class="small text-primary" id="markAllRead" style="font-size:.75rem">Mark all read</a>
                    </div>
                    <div class="notif-list" id="notifList">
                        <div class="text-center py-4 text-muted small">
                            <i class="bi bi-bell-slash fs-4 d-block mb-1"></i>No notifications
                        </div>
                    </div>
                    <div class="notif-footer">
                        <a href="<?= APP_URL ?>/views/notifications.php" class="notif-footer-link">See all notifications</a>
                    </div>
                </div>
            </div>

            <div class="navbar-divider"></div>

            <!-- User Menu -->
            <div class="dropdown user-menu">
                <button class="user-btn" data-bs-toggle="dropdown" aria-expanded="false" id="userMenuBtn">
                    <div class="user-avatar">
                        <?php $u = currentUser(); ?>
                        <?php if ($u['avatar']): ?>
                            <img src="<?= e(resolveAvatarUrl($u['avatar'])) ?>" alt="avatar">
                        <?php else: ?>
                            <span><?= strtoupper(substr($u['name'], 0, 2)) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block text-start">
                        <div class="user-name"><?= e($u['name']) ?></div>
                        <div class="user-role-badge"><?= ucwords(str_replace('_', ' ', $u['role'])) ?></div>
                    </div>
                    <i class="bi bi-chevron-down d-none d-md-block" style="font-size:.7rem;color:var(--text-faint)"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:180px;font-size:.83rem">
                    <li class="dropdown-header text-muted" style="font-size:.75rem"><?= e($u['email']) ?></li>
                    <li><a class="dropdown-item d-flex align-items-center gap-2" href="<?= APP_URL ?>/views/profile/index.php">
                        <i class="bi bi-person"></i>My Profile
                    </a></li>
                    <?php if (isAdmin()): ?>
                    <li><a class="dropdown-item d-flex align-items-center gap-2" href="<?= APP_URL ?>/views/admin/settings.php">
                        <i class="bi bi-gear"></i>Settings
                    </a></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item d-flex align-items-center gap-2 text-danger" href="<?= APP_URL ?>/logout.php">
                        <i class="bi bi-box-arrow-right"></i>Sign Out
                    </a></li>
                </ul>
            </div>

        </div>
    </nav><!-- /.app-navbar -->

    <div class="app-layout">

        <!-- ===== SIDEBAR ===== -->
        <aside class="app-sidebar" id="appSidebar">

            <!-- New Ticket CTA -->
            <div class="sidebar-cta">
                <a href="<?= APP_URL ?>/views/tickets/create.php" class="btn-new-ticket">
                    <i class="bi bi-plus"></i>
                    <span>New Ticket</span>
                </a>
            </div>

            <nav class="sidebar-nav">
                <ul class="nav flex-column">

                    <!-- Main -->
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], 'index.php') !== false && !strpos($_SERVER['REQUEST_URI'], 'views') ? 'active' : '' ?>"
                           href="<?= APP_URL ?>/index.php">
                            <i class="bi bi-grid-1x2"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/tickets/index') !== false || (strpos($_SERVER['REQUEST_URI'], '/tickets/') !== false && strpos($_SERVER['REQUEST_URI'], 'dept_inbox') === false) ? 'active' : '' ?>"
                           href="<?= APP_URL ?>/views/tickets/index.php">
                            <i class="bi bi-ticket-detailed"></i>
                            <span>Tickets</span>
                            <?php
                            $navScope  = deptScopeId();
                            $openCount = db()->query(
                                "SELECT COUNT(*) FROM tickets WHERE status='open'"
                                . ($navScope !== null ? " AND department_id = {$navScope}" : '')
                            )->fetchColumn();
                            if ($openCount > 0): ?>
                            <span class="sidebar-badge ms-auto"><?= $openCount ?></span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <?php $navUser = currentUser(); if (!empty($navUser['dept_id'])): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], 'dept_inbox') !== false ? 'active' : '' ?>"
                           href="<?= APP_URL ?>/views/tickets/dept_inbox.php">
                            <i class="bi bi-inbox"></i>
                            <span>Department Inbox</span>
                            <?php
                            try {
                                $deptInboxStmt = db()->prepare(
                                    "SELECT COUNT(*) FROM tickets
                                     WHERE department_id = ?
                                       AND status NOT IN ('resolved','closed')
                                       AND (assigned_to IS NULL OR status = 'open')"
                                );
                                $deptInboxStmt->execute([(int)$navUser['dept_id']]);
                                $deptInboxCount = (int)$deptInboxStmt->fetchColumn();
                            } catch (Exception $e) { $deptInboxCount = 0; }
                            if ($deptInboxCount > 0): ?>
                            <span class="sidebar-badge ms-auto"><?= $deptInboxCount ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (isStaff()): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/reports/') !== false ? 'active' : '' ?>"
                           href="<?= APP_URL ?>/views/reports/index.php">
                            <i class="bi bi-bar-chart-line"></i>
                            <span>Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], 'admin/csat') !== false ? 'active' : '' ?>"
                           href="<?= APP_URL ?>/views/admin/csat.php">
                            <i class="bi bi-star-half"></i>
                            <span>CSAT Ratings</span>
                        </a>
                    </li>
                    <?php endif; ?>

                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/kb/') !== false ? 'active' : '' ?>"
                           href="<?= APP_URL ?>/views/kb/index.php">
                            <i class="bi bi-book-half"></i>
                            <span>Knowledge Base</span>
                        </a>
                    </li>

                    <?php if (isAdmin()): ?>
                    <hr class="sidebar-sep">

                    <li class="nav-section-label">Admin</li>

                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/users/') !== false ? 'active' : '' ?>"
                           href="<?= APP_URL ?>/views/users/index.php">
                            <i class="bi bi-people"></i>
                            <span>Users</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/departments') !== false ? 'active' : '' ?>"
                           href="<?= APP_URL ?>/views/admin/departments.php">
                            <i class="bi bi-building"></i>
                            <span>Departments</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/categories') !== false ? 'active' : '' ?>"
                           href="<?= APP_URL ?>/views/admin/categories.php">
                            <i class="bi bi-tags"></i>
                            <span>Categories</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/templates') !== false ? 'active' : '' ?>"
                           href="<?= APP_URL ?>/views/admin/templates.php">
                            <i class="bi bi-card-text"></i>
                            <span>Reply Templates</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/logs') !== false ? 'active' : '' ?>"
                           href="<?= APP_URL ?>/views/admin/logs.php">
                            <i class="bi bi-journal-text"></i>
                            <span>Activity Logs</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/migrate') !== false ? 'active' : '' ?>"
                           href="<?= APP_URL ?>/views/admin/migrate.php">
                            <i class="bi bi-box-arrow-in-down-right"></i>
                            <span>Migrate Tickets</span>
                        </a>
                    </li>
                    <?php endif; ?>

                </ul>
            </nav>

            <!-- Sidebar Footer links -->
            <div class="sidebar-footer">
                <a href="#" class="nav-link">
                    <i class="bi bi-activity"></i>
                    <span style="font-size:.75rem">System Status</span>
                    <span class="status-dot ms-auto"></span>
                </a>
                <a href="#" class="nav-link">
                    <i class="bi bi-book"></i>
                    <span style="font-size:.75rem">Documentation</span>
                </a>
            </div>

        </aside><!-- /.app-sidebar -->

        <!-- ===== MAIN CONTENT ===== -->
        <main class="app-main" id="appMain">
            <!-- Flash Messages -->
            <?php foreach ($flash as $msg): ?>
            <div class="alert alert-<?= e($msg['type']) ?> alert-dismissible fade show mx-4 mt-3 mb-0 alert-flash"
                 role="alert">
                <?= e($msg['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <div class="page-content">
