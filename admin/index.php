<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/helpers.php';
require_login();
$pdo = get_pdo();

$quick_errors = [];
$quick_success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_message'])) {
    $message = trim($_POST['quick_message']);
    $store_id = $_POST['quick_store_id'] ?? null;

    if ($message === '') {
        $quick_errors[] = 'Message cannot be empty';
    } else {
        if ($store_id === 'all' || empty($store_id)) {
            $store_id = null;
        }

        $stmt = $pdo->prepare("INSERT INTO store_messages (store_id, sender, message, created_at) VALUES (?, 'admin', ?, NOW())");
        $stmt->execute([$store_id, $message]);

        $emailSettings = [];
        $settingsQuery = $pdo->query("SELECT name, value FROM settings WHERE name IN ('email_from_name', 'email_from_address', 'store_message_subject')");
        while ($row = $settingsQuery->fetch()) {
            $emailSettings[$row['name']] = $row['value'];
        }

        $fromName = $emailSettings['email_from_name'] ?? 'Cosmick Media';
        $fromAddress = $emailSettings['email_from_address'] ?? 'noreply@cosmickmedia.com';
        $subjectTemplate = $emailSettings['store_message_subject'] ?? 'New message from Cosmick Media';

        $headers = "From: $fromName <$fromAddress>\r\n";
        $headers .= "Reply-To: $fromAddress\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['REQUEST_URI']));
        $loginUrl = $baseUrl . '/public/index.php';

        if ($store_id) {
            $stmt = $pdo->prepare('SELECT * FROM stores WHERE id = ?');
            $stmt->execute([$store_id]);
            $store = $stmt->fetch();

            if ($store && !empty($store['admin_email'])) {
                $subject = str_replace('{store_name}', $store['name'], $subjectTemplate);

                $body = "Dear {$store['name']},\n\n";
                $body .= "You have a new message from Cosmick Media:\n\n";
                $body .= "=====================================\n";
                $body .= $message . "\n";
                $body .= "=====================================\n\n";
                $body .= "To view this message and upload content, please visit:\n";
                $body .= $loginUrl . "\n\n";
                $body .= "Your PIN: {$store['pin']}\n\n";
                $body .= "Best regards,\n$fromName";

                mail($store['admin_email'], $subject, $body, $headers);
            }
        } else {
            $stores = $pdo->query('SELECT * FROM stores WHERE admin_email IS NOT NULL AND admin_email != ""')->fetchAll(PDO::FETCH_ASSOC);

            foreach ($stores as $store) {
                $subject = str_replace('{store_name}', $store['name'], $subjectTemplate);

                $body = "Dear {$store['name']},\n\n";
                $body .= "You have a new message from Cosmick Media:\n\n";
                $body .= "=====================================\n";
                $body .= $message . "\n";
                $body .= "=====================================\n\n";
                $body .= "To view this message and upload content, please visit:\n";
                $body .= $loginUrl . "\n\n";
                $body .= "Your PIN: {$store['pin']}\n\n";
                $body .= "Best regards,\n$fromName";

                mail($store['admin_email'], $subject, $body, $headers);
            }
        }

        $quick_success[] = 'Message posted successfully';
    }
}

// Get statistics
$stats = [];

// Total stores
$stmt = $pdo->query('SELECT COUNT(*) FROM stores');
$stats['total_stores'] = $stmt->fetchColumn();

// Total uploads
$stmt = $pdo->query('SELECT COUNT(*) FROM uploads');
$stats['total_uploads'] = $stmt->fetchColumn();

// Uploads today
$stmt = $pdo->query('SELECT COUNT(*) FROM uploads WHERE DATE(created_at) = CURDATE()');
$stats['uploads_today'] = $stmt->fetchColumn();

// Uploads this week
$stmt = $pdo->query('SELECT COUNT(*) FROM uploads WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
$stats['uploads_week'] = $stmt->fetchColumn();

// Articles statistics
try {
    $stmt = $pdo->query('SELECT COUNT(*) FROM articles');
    $stats['total_articles'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM articles WHERE status = 'submitted'");
    $stats['pending_articles'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    // Table might not exist yet
    $stats['total_articles'] = 0;
    $stats['pending_articles'] = 0;
}

// Store users (main + additional)
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM stores WHERE admin_email IS NOT NULL AND admin_email <> ''");
    $stats['store_users'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    $stats['store_users'] = 0;
}
try {
    $stmt = $pdo->query('SELECT COUNT(*) FROM store_users');
    $stats['store_users'] += $stmt->fetchColumn();
} catch (PDOException $e) {
    // Table might not exist yet
}

// Recent uploads
$stmt = $pdo->query('
    SELECT u.*, s.name as store_name, u.mime, u.drive_id,
           us.name AS status_name, us.color AS status_color
    FROM uploads u
    JOIN stores s ON u.store_id = s.id
    LEFT JOIN upload_statuses us ON u.status_id = us.id
    ORDER BY u.created_at DESC, u.id DESC
    LIMIT 5
');
$recent_uploads = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent articles
$recent_articles = [];
try {
    $stmt = $pdo->query('
        SELECT a.*, s.name as store_name 
        FROM articles a 
        JOIN stores s ON a.store_id = s.id 
        ORDER BY a.created_at DESC 
        LIMIT 5
    ');
    $recent_articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist yet
}

// Recent broadcast messages
$stmt = $pdo->query("SELECT message, created_at FROM store_messages WHERE store_id IS NULL ORDER BY created_at DESC LIMIT 5");
$recent_broadcasts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Active messages count
try {
    $stmt = $pdo->query('SELECT COUNT(*) FROM store_messages WHERE is_reply = 0 OR is_reply IS NULL');
    $stats['active_messages'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    // Fallback if is_reply column doesn't exist
    $stmt = $pdo->query('SELECT COUNT(*) FROM store_messages');
    $stats['active_messages'] = $stmt->fetchColumn();
}

$stores = $pdo->query('SELECT id, name FROM stores ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$active = 'dashboard';
include __DIR__.'/header.php';
?>


    <div class="animate__animated animate__fadeIn">
        <!-- Welcome Section -->
        <div class="welcome-section animate__animated animate__fadeInDown">
            <h1 class="welcome-title">Admin Dashboard</h1>
            <p class="welcome-subtitle">MediaHub Content Management System</p>
            <p class="welcome-time"><?php echo date('l, F j, Y'); ?></p>
        </div>

        <!-- Statistics Grid -->
        <div class="stats-grid">
            <a href="stores.php" class="stat-card stores animate__animated animate__fadeInUp">
                <div class="stat-icon">
                    <i class="bi bi-shop"></i>
                </div>
                <div class="stat-number" data-count="<?php echo $stats['total_stores']; ?>">0</div>
                <div class="stat-label">Total Stores</div>
                <div class="stat-bg"></div>
            </a>

            <a href="uploads.php" class="stat-card uploads animate__animated animate__fadeInUp delay-10">
                <div class="stat-icon">
                    <i class="bi bi-cloud-upload"></i>
                </div>
                <div class="stat-number" data-count="<?php echo $stats['total_uploads']; ?>">0</div>
                <div class="stat-label">Total Uploads</div>
                <div class="stat-bg"></div>
            </a>

            <a href="articles.php" class="stat-card articles animate__animated animate__fadeInUp delay-20">
                <div class="stat-icon">
                    <i class="bi bi-file-text"></i>
                </div>
                <div>
                    <span class="stat-number" data-count="<?php echo $stats['total_articles']; ?>">0</span>
                    <?php if ($stats['pending_articles'] > 0): ?>
                        <span class="stat-extra">(<?php echo $stats['pending_articles']; ?> pending)</span>
                    <?php endif; ?>
                </div>
                <div class="stat-label">Articles</div>
                <div class="stat-bg"></div>
            </a>

            <a href="broadcasts.php" class="stat-card messages animate__animated animate__fadeInUp delay-30">
                <div class="stat-icon">
                    <i class="bi bi-chat-dots"></i>
                </div>
                <div class="stat-number" data-count="<?php echo $stats['active_messages']; ?>">0</div>
                <div class="stat-label">Active Messages</div>
                <div class="stat-bg"></div>
            </a>

            <a href="stores.php" class="stat-card users animate__animated animate__fadeInUp delay-40">
                <div class="stat-icon">
                    <i class="bi bi-people"></i>
                </div>
                <div class="stat-number" data-count="<?php echo $stats['store_users']; ?>">0</div>
                <div class="stat-label">Store Users</div>
                <div class="stat-bg"></div>
            </a>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <div>
                <!-- Recent Uploads -->
                <div class="content-card mb-4 animate__animated animate__fadeIn delay-50">
                    <div class="card-header-modern">
                        <h5 class="card-title-modern">
                            <i class="bi bi-clock-history"></i>
                            Recent Uploads
                        </h5>
                    </div>
                    <div class="card-body-modern">
                        <?php if (empty($recent_uploads)): ?>
                            <p class="text-muted text-center py-3">No recent uploads</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-modern table-sm">
                                    <thead>
                                    <tr>
                                        <th><i class="bi bi-image"></i></th>
                                        <th>Date</th>
                                        <th>Store</th>
                                        <th>File</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($recent_uploads as $upload): ?>
                                        <tr>
                                            <td>
                                                <?php $thumb = !empty($upload['thumb_path']) ? '/public/' . ltrim($upload['thumb_path'], '/') : 'thumbnail.php?id=' . $upload['id'] . '&size=small'; ?>
                                                <img src="<?php echo htmlspecialchars($thumb); ?>"
                                                     class="preview-img-sm"
                                                     alt="<?php echo htmlspecialchars($upload['filename']); ?>"
                                                     loading="lazy">
                                            </td>
                                            <td><?php echo format_ts($upload['created_at']); ?></td>
                                            <td><?php echo htmlspecialchars($upload['store_name']); ?></td>
                                            <td><?php echo htmlspecialchars(shorten_filename($upload['filename'])); ?></td>
                                            <td>
                                                <?php if ($upload['status_name']): ?>
                                                    <span class="badge" style="background-color: <?php echo htmlspecialchars($upload['status_color']); ?>;">
                                                    <?php echo htmlspecialchars($upload['status_name']); ?>
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="actions-cell">
                                                <a href="https://drive.google.com/file/d/<?php echo $upload['drive_id']; ?>/view"
                                                   target="_blank" class="btn btn-action btn-action-primary" title="View">
                                                    <i class="bi bi-search"></i>
                                                </a>
                                                <form class="d-inline delete-upload-form" data-id="<?php echo $upload['id']; ?>" onsubmit="return false;">
                                                    <button type="submit" class="btn btn-action btn-action-danger" title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-center mt-3">
                                <a href="uploads.php" class="btn btn-modern btn-modern-primary">
                                    <i class="bi bi-arrow-right"></i> View All Uploads
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Articles -->
                <?php if (!empty($recent_articles)): ?>
                    <div class="content-card mb-4 animate__animated animate__fadeIn delay-60">
                        <div class="card-header-modern">
                            <h5 class="card-title-modern">
                                <i class="bi bi-file-text"></i>
                                Recent Articles
                            </h5>
                        </div>
                        <div class="card-body-modern">
                            <div class="table-responsive">
                                <table class="table table-modern table-sm">
                                    <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Store</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($recent_articles as $article): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(substr($article['title'], 0, 40)) . '...'; ?></td>
                                            <td><?php echo htmlspecialchars($article['store_name']); ?></td>
                                            <td>
                                                <?php
                                                $statusClass = [
                                                    'draft' => 'bg-secondary',
                                                    'submitted' => 'bg-info',
                                                    'approved' => 'bg-success',
                                                    'rejected' => 'bg-danger'
                                                ][$article['status']] ?? 'bg-secondary';
                                                ?>
                                                <span class="badge <?php echo $statusClass; ?>">
                                                <?php echo ucfirst($article['status']); ?>
                                            </span>
                                            </td>
                                            <td><?php echo format_ts($article['created_at']); ?></td>
                                            <td>
                                                <a href="articles.php" class="btn btn-action btn-action-primary" title="Review">
                                                    <i class="bi bi-pencil-square"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-center mt-3">
                                <a href="articles.php" class="btn btn-modern btn-modern-primary">
                                    <i class="bi bi-arrow-right"></i> View All Articles
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Recent Broadcasts -->
                <div class="content-card animate__animated animate__fadeIn delay-70">
                    <div class="card-header-modern">
                        <h5 class="card-title-modern">
                            <i class="bi bi-megaphone"></i>
                            Recent Broadcast Messages
                        </h5>
                    </div>
                    <div class="card-body-modern">
                        <?php if (empty($recent_broadcasts)): ?>
                            <p class="text-muted text-center py-3">No recent messages</p>
                        <?php else: ?>
                            <ul class="activity-list">
                                <?php foreach ($recent_broadcasts as $bm): ?>
                                    <li class="activity-item">
                                        <div class="activity-content">
                                            <?php echo htmlspecialchars($bm['message']); ?>
                                        </div>
                                        <small class="activity-time"><?php echo format_ts($bm['created_at']); ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div>
                <!-- Quick Actions -->
                <div class="content-card mb-4 animate__animated animate__fadeIn delay-80">
                    <div class="card-header-modern">
                        <h5 class="card-title-modern">
                            <i class="bi bi-lightning-charge"></i>
                            Quick Actions
                        </h5>
                    </div>
                    <div class="card-body-modern">
                        <div class="quick-actions">
                            <a href="stores.php" class="action-card">
                                <div class="action-icon">
                                    <i class="bi bi-shop"></i>
                                </div>
                                <div class="action-content">
                                    <h6 class="action-title">Manage Stores</h6>
                                </div>
                            </a>
                            <a href="uploads.php" class="action-card">
                                <div class="action-icon">
                                    <i class="bi bi-cloud-upload"></i>
                                </div>
                                <div class="action-content">
                                    <h6 class="action-title">Review Uploads</h6>
                                </div>
                            </a>
                            <a href="articles.php" class="action-card">
                                <div class="action-icon">
                                    <i class="bi bi-file-text"></i>
                                </div>
                                <div class="action-content">
                                    <h6 class="action-title">
                                        Review Articles
                                        <?php if ($stats['pending_articles'] > 0): ?>
                                            <span class="badge bg-danger"><?php echo $stats['pending_articles']; ?></span>
                                        <?php endif; ?>
                                    </h6>
                                </div>
                            </a>
                            <a href="broadcasts.php" class="action-card">
                                <div class="action-icon">
                                    <i class="bi bi-chat-dots"></i>
                                </div>
                                <div class="action-content">
                                    <h6 class="action-title">Post Messages</h6>
                                </div>
                            </a>
                            <a href="settings.php" class="action-card">
                                <div class="action-icon">
                                    <i class="bi bi-gear"></i>
                                </div>
                                <div class="action-content">
                                    <h6 class="action-title">Settings</h6>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Quick Broadcast -->
                <div class="content-card mb-4 animate__animated animate__fadeIn delay-90">
                    <div class="card-header-modern">
                        <h5 class="card-title-modern">
                            <i class="bi bi-send"></i>
                            Quick Broadcast
                        </h5>
                    </div>
                    <div class="card-body-modern">
                        <?php foreach ($quick_errors as $e): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?php echo htmlspecialchars($e); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($quick_success as $s): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?php echo htmlspecialchars($s); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endforeach; ?>
                        <form method="post" class="broadcast-form">
                            <div class="mb-3">
                                <select name="quick_store_id" class="form-select">
                                    <option value="all">All Stores</option>
                                    <?php foreach ($stores as $s): ?>
                                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <textarea name="quick_message" class="form-control" rows="3" placeholder="Enter your message..." required></textarea>
                            </div>
                            <button class="btn btn-send w-100" type="submit">
                                <i class="bi bi-send"></i> Send Message
                            </button>
                        </form>
                    </div>
                </div>

                <!-- This Week Activity -->
                <div class="content-card animate__animated animate__fadeIn delay-100">
                    <div class="card-header-modern">
                        <h5 class="card-title-modern">
                            <i class="bi bi-graph-up"></i>
                            This Week
                        </h5>
                    </div>
                    <div class="card-body-modern">
                        <p class="mb-2">
                            <strong><?php echo $stats['uploads_week']; ?></strong> uploads
                        </p>
                        <div class="progress-modern">
                            <?php
                            $avg_per_day = $stats['uploads_week'] / 7;
                            $progress = min(100, ($avg_per_day / 10) * 100); // Assuming 10 uploads/day is 100%
                            ?>
                            <div class="progress-bar" style="width: <?php echo $progress; ?>%"></div>
                        </div>
                        <small class="text-muted">
                            Average: <?php echo number_format($avg_per_day, 1); ?> per day
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.querySelectorAll('.delete-upload-form').forEach(form => {
        form.addEventListener('submit', () => {
            if(!confirm('Delete this file?')) return;
            const id = form.dataset.id;
            const fd = new FormData();
            fd.append('id', id);
            fetch('delete_upload.php', {method:'POST', body: fd})
                .then(r => r.json())
                .then(res => {
                    if(res.success){
                        form.closest('tr').remove();
                    }
                });
        });
    });
    </script>

<?php include __DIR__.'/footer.php'; ?>