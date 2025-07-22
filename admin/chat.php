<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/helpers.php';
require_login();
$pdo = get_pdo();

$errors = [];
$success = [];

// Handle sending messages
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $message = trim($_POST['message'] ?? '');
    $store_id = $_POST['store_id'] ?? null;

    if (empty($message) && empty($_FILES['file']['name'])) {
        $errors[] = 'Message cannot be empty';
    } elseif (empty($store_id)) {
        $errors[] = 'Please select a store';
    } else {
        $upload_id = null;
        if (!empty($_FILES['file']['name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            require_once __DIR__.'/../lib/drive.php';
            $tmp = $_FILES['file']['tmp_name'];
            $orig = $_FILES['file']['name'];
            $size = $_FILES['file']['size'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmp);
            finfo_close($finfo);
            $folderId = get_or_create_store_folder($store_id);
            $driveId = drive_upload($tmp, $mime, $orig, $folderId);
            $ins = $pdo->prepare('INSERT INTO uploads (store_id, filename, created_at, ip, mime, size, drive_id) VALUES (?, ?, NOW(), ?, ?, ?, ?)');
            $ins->execute([$store_id, $orig, $_SERVER['REMOTE_ADDR'] ?? '', $mime, $size, $driveId]);
            $upload_id = $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("INSERT INTO store_messages (store_id, sender, message, created_at, is_reply, read_by_admin, read_by_store, upload_id) VALUES (?, 'admin', ?, NOW(), 1, 1, 0, ?)");
        $stmt->execute([$store_id, $message, $upload_id]);
        $success[] = 'Message sent successfully';
    }
}

// Handle marking messages as read (if column exists)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $store_id = $_POST['store_id'] ?? null;
    if ($store_id) {
        $stmt = $pdo->prepare("UPDATE store_messages SET read_by_admin = 1 WHERE store_id = ? AND sender = 'store' AND read_by_admin = 0");
        $stmt->execute([$store_id]);
    }
}

// Get current store if selected
$current_store_id = $_GET['store_id'] ?? ($_POST['store_id'] ?? null);
$current_store = null;
if ($current_store_id) {
    $stmt = $pdo->prepare('SELECT * FROM stores WHERE id = ?');
    $stmt->execute([$current_store_id]);
    $current_store = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (isset($_GET['load']) && $current_store_id) {
    $stmt = $pdo->prepare("SELECT m.*, s.name as store_name, u.filename, u.drive_id, u.mime, u.id AS upload_id
        FROM store_messages m
        JOIN stores s ON m.store_id = s.id
        LEFT JOIN uploads u ON m.upload_id = u.id
        WHERE m.store_id = ?
        ORDER BY m.created_at ASC");
    $stmt->execute([$current_store_id]);
    $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pdo->prepare("UPDATE store_messages SET read_by_admin=1 WHERE store_id=? AND sender='store' AND read_by_admin=0")
        ->execute([$current_store_id]);
    foreach ($msgs as &$m) {
        $m['created_at'] = format_ts($m['created_at']);
    }
    header('Content-Type: application/json');
    echo json_encode($msgs);
    exit;
}

// Get all stores with message counts and latest message info
$stores_query = "
    SELECT s.*,
           SUM(CASE WHEN m.sender='store' AND m.read_by_admin=0 THEN 1 ELSE 0 END) as unread_count,
           COUNT(m.id) as total_messages,
           MAX(m.created_at) as last_message_time,
           (SELECT message FROM store_messages WHERE store_id = s.id ORDER BY created_at DESC LIMIT 1) as last_message,
           (SELECT sender FROM store_messages WHERE store_id = s.id ORDER BY created_at DESC LIMIT 1) as last_sender
    FROM stores s 
    LEFT JOIN store_messages m ON s.id = m.store_id 
    GROUP BY s.id 
    ORDER BY unread_count DESC, last_message_time DESC, s.name ASC
";
$stores = $pdo->query($stores_query)->fetchAll(PDO::FETCH_ASSOC);

// Get messages for current store
$messages = [];
if ($current_store_id) {
    $stmt = $pdo->prepare("
        SELECT m.*, s.name as store_name, u.filename, u.drive_id, u.mime, u.id AS upload_id
        FROM store_messages m
        JOIN stores s ON m.store_id = s.id
        LEFT JOIN uploads u ON m.upload_id = u.id
        WHERE m.store_id = ?
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$current_store_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare("UPDATE store_messages SET read_by_admin=1 WHERE store_id=? AND sender='store' AND read_by_admin=0");
    $upd->execute([$current_store_id]);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM store_messages WHERE store_id=? AND sender='store' AND read_by_admin=0");
    $cnt->execute([$current_store_id]);
    $current_unread = (int)$cnt->fetchColumn();
} else {
    $current_unread = 0;
}

// Calculate statistics
$stats = [];
$stats['total_conversations'] = $pdo->query("SELECT COUNT(DISTINCT store_id) FROM store_messages")->fetchColumn();
$stats['unread_messages'] = $pdo->query("SELECT COUNT(*) FROM store_messages WHERE sender='store' AND read_by_admin=0")->fetchColumn();
$stats['today_messages'] = $pdo->query("SELECT COUNT(*) FROM store_messages WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$stats['active_chats'] = $pdo->query("SELECT COUNT(DISTINCT store_id) FROM store_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

$active = 'chat';
include __DIR__.'/header.php';
?>

    <style>
        /* Page Header */
        .page-header {
            background: var(--primary-gradient);
            color: white;
            padding: 2rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            margin: 0.5rem 0 0 0;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
        }

        .stat-card .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            opacity: 0.9;
        }

        .stat-card.primary .stat-icon { color: #667eea; }
        .stat-card.success .stat-icon { color: #4facfe; }
        .stat-card.warning .stat-icon { color: #fa709a; }
        .stat-card.info .stat-icon { color: #4ade80; }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #2c3e50;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }

        .stat-bg {
            position: absolute;
            right: -20px;
            bottom: -20px;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            opacity: 0.1;
        }

        .stat-card.primary .stat-bg { background: var(--primary-gradient); }
        .stat-card.success .stat-bg { background: var(--success-gradient); }
        .stat-card.warning .stat-bg { background: var(--warning-gradient); }
        .stat-card.info .stat-bg { background: linear-gradient(135deg, #4ade80, #22c55e); }

        /* Chat Layout */
        .chat-container {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
            height: 70vh;
            min-height: 600px;
        }

        /* Store List */
        .stores-panel {
            background: white;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            background: #f8f9fa;
            padding: 1.5rem;
            border-bottom: 1px solid #e9ecef;
        }

        .panel-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #2c3e50;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .panel-title i {
            font-size: 1.1rem;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stores-list {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }

        .store-item {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            color: inherit;
            position: relative;
        }

        .store-item:hover {
            background: #f8f9fa;
            transform: translateX(5px);
            color: inherit;
        }

        .store-item.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            transform: translateX(10px);
        }

        .store-item:last-child {
            border-bottom: none;
        }

        .store-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--secondary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
            margin-right: 1rem;
            flex-shrink: 0;
        }

        .store-item.active .store-avatar {
            background: rgba(255, 255, 255, 0.2);
        }

        .store-info {
            flex: 1;
            min-width: 0;
        }

        .store-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .store-last-message {
            font-size: 0.875rem;
            opacity: 0.7;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .store-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.25rem;
        }

        .message-time {
            font-size: 0.75rem;
            opacity: 0.7;
            white-space: nowrap;
        }

        .unread-badge {
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .store-item.active .unread-badge {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Chat Panel */
        .chat-panel {
            background: white;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            background: #f8f9fa;
            padding: 1.5rem;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chat-store-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .chat-store-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .chat-store-details h5 {
            margin: 0;
            font-weight: 700;
            color: #2c3e50;
        }

        .chat-store-details small {
            color: #6c757d;
        }

        .chat-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-chat-action {
            padding: 0.5rem 1rem;
            border-radius: 10px;
            border: none;
            font-weight: 500;
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .btn-mark-read {
            background: #4ade80;
            color: white;
        }

        .btn-mark-read:hover {
            background: #22c55e;
            transform: translateY(-2px);
        }

        /* Messages Area */
        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            background: #fafafa;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .message {
            display: flex;
            margin-bottom: 1rem;
        }

        .message.admin {
            justify-content: flex-end;
        }

        .message.store {
            justify-content: flex-start;
        }

        .message-bubble {
            max-width: 70%;
            padding: 1rem 1.25rem;
            border-radius: 20px;
            position: relative;
            word-wrap: break-word;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .message.admin .message-bubble {
            background: var(--primary-gradient);
            color: white;
            border-bottom-right-radius: 8px;
        }

        .message.store .message-bubble {
            background: white;
            color: #2c3e50;
            border-bottom-left-radius: 8px;
            border: 2px solid #f0f0f0;
        }

        .message-content {
            margin-bottom: 0.5rem;
            line-height: 1.5;
        }

        .message-img, .message-video {
            max-width: 250px;
            border-radius: 8px;
            display: block;
        }

        .message-reactions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.25rem;
        }

        .reaction-button {
            background: none;
            border: none;
            padding: 0.25rem;
            cursor: pointer;
            transition: var(--transition);
            color: #6c757d;
            font-size: 1rem;
        }

        .reaction-button:hover { transform: scale(1.2); }
        .reaction-button.active { color: inherit; }
        .reaction-button.like.active { color: #0d6efd; }
        .reaction-button.love.active { color: #dc3545; }

        .message-time {
            font-size: 0.75rem;
            opacity: 0.8;
            text-align: right;
        }

        .message.store .message-time {
            text-align: left;
        }

        .message-sender {
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            opacity: 0.8;
        }

        /* Message Input */
        .message-input-container {
            padding: 1.5rem;
            border-top: 1px solid #e9ecef;
            background: white;
            position: relative;
        }

        .message-input-form {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
        }

        .message-input {
            flex: 1;
            border: 2px solid #e0e0e0;
            border-radius: 20px;
            padding: 0.75rem 1.25rem;
            resize: none;
            min-height: 45px;
            max-height: 120px;
            transition: var(--transition);
        }

        .message-input:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn-send {
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 50%;
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            font-size: 1.1rem;
        }

        .btn-send:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
            color: white;
        }

        .btn-send:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .btn-action {
            background: #f1f1f1;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.5rem;
            transition: var(--transition);
        }

        .btn-action:hover {
            background: #e2e2e2;
        }

        .file-preview {
            margin-top: 0.5rem;
        }

        .file-preview img {
            max-width: 100px;
            border-radius: 8px;
            cursor: pointer;
        }

        /* Emoji Picker */
        #emojiPicker {
            position: absolute;
            bottom: 100%;
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.1);
            padding: 1rem;
            margin-bottom: 0.5rem;
            display: none;
            width: 300px;
            max-height: 250px;
            overflow-y: auto;
        }

        #emojiPicker .emoji-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 0.25rem;
        }

        #emojiPicker .emoji-option {
            font-size: 1.5rem;
            padding: 0.5rem;
            cursor: pointer;
            border-radius: 8px;
            transition: var(--transition);
            text-align: center;
        }

        #emojiPicker .emoji-option:hover {
            background: #f8f9fa;
            transform: scale(1.2);
        }

        /* Empty States */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #6c757d;
            text-align: center;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .empty-state h4 {
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .no-stores {
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #6c757d;
            padding: 2rem;
        }

        /* Online Status Indicator */
        .online-indicator {
            width: 12px;
            height: 12px;
            background: #4ade80;
            border-radius: 50%;
            position: absolute;
            bottom: 2px;
            right: 2px;
            border: 2px solid white;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(74, 222, 128, 0); }
            100% { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0); }
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .chat-container {
                grid-template-columns: 300px 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                padding: 1.5rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .chat-container {
                grid-template-columns: 1fr;
                height: auto;
            }

            .stores-panel {
                height: 200px;
            }

            .chat-panel {
                height: 500px;
            }

            .message-bubble {
                max-width: 85%;
            }

            .chat-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
        }

        /* Auto-scroll to bottom */
        .messages-container::-webkit-scrollbar {
            width: 6px;
        }

        .messages-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .messages-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        .messages-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Typing indicator */
        .typing-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            font-style: italic;
            color: #6c757d;
            font-size: 0.875rem;
        }

        .typing-dots {
            display: flex;
            gap: 3px;
        }

        .typing-dots span {
            width: 6px;
            height: 6px;
            background: #6c757d;
            border-radius: 50%;
            animation: typing 1.4s infinite;
        }

        .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
        .typing-dots span:nth-child(3) { animation-delay: 0.4s; }

        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-10px); }
        }
    </style>

    <div class="animate__animated animate__fadeIn">
        <!-- Page Header -->
        <div class="page-header animate__animated animate__fadeInDown">
            <h1 class="page-title">Live Chat</h1>
            <p class="page-subtitle">Real-time communication with stores</p>
        </div>

        <!-- Alerts -->
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-danger alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php echo htmlspecialchars($e); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>

        <?php foreach ($success as $s): ?>
            <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($s); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card primary animate__animated animate__fadeInUp">
                <div class="stat-icon">
                    <i class="bi bi-chat-dots-fill"></i>
                </div>
                <div class="stat-number" data-count="<?php echo $stats['total_conversations']; ?>">0</div>
                <div class="stat-label">Total Conversations</div>
                <div class="stat-bg"></div>
            </div>

            <div class="stat-card warning animate__animated animate__fadeInUp" style="animation-delay: 0.1s">
                <div class="stat-icon">
                    <i class="bi bi-envelope-fill"></i>
                </div>
                <div class="stat-number" data-count="<?php echo $stats['unread_messages']; ?>">0</div>
                <div class="stat-label">Unread Messages</div>
                <div class="stat-bg"></div>
            </div>

            <div class="stat-card success animate__animated animate__fadeInUp" style="animation-delay: 0.2s">
                <div class="stat-icon">
                    <i class="bi bi-calendar-check-fill"></i>
                </div>
                <div class="stat-number" data-count="<?php echo $stats['today_messages']; ?>">0</div>
                <div class="stat-label">Today's Messages</div>
                <div class="stat-bg"></div>
            </div>

            <div class="stat-card info animate__animated animate__fadeInUp" style="animation-delay: 0.3s">
                <div class="stat-icon">
                    <i class="bi bi-activity"></i>
                </div>
                <div class="stat-number" data-count="<?php echo $stats['active_chats']; ?>">0</div>
                <div class="stat-label">Active Chats (24h)</div>
                <div class="stat-bg"></div>
            </div>
        </div>

        <!-- Chat Interface -->
        <div class="chat-container animate__animated animate__fadeIn" style="animation-delay: 0.4s">
            <!-- Stores List -->
            <div class="stores-panel">
                <div class="panel-header">
                    <h5 class="panel-title">
                        <i class="bi bi-shop"></i>
                        Stores
                    </h5>
                </div>
                <div class="stores-list">
                    <?php if (empty($stores)): ?>
                        <div class="no-stores">
                            <div>
                                <i class="bi bi-shop" style="font-size: 2rem; opacity: 0.3; margin-bottom: 0.5rem;"></i>
                                <p>No stores available</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($stores as $store): ?>
                            <a href="?store_id=<?php echo $store['id']; ?>"
                               class="store-item <?php echo $current_store_id == $store['id'] ? 'active' : ''; ?>"
                               data-id="<?php echo $store['id']; ?>"
                               data-name="<?php echo htmlspecialchars($store['name'], ENT_QUOTES); ?>"
                               data-email="<?php echo htmlspecialchars($store['admin_email'] ?? '', ENT_QUOTES); ?>"
                               data-phone="<?php echo htmlspecialchars($store['phone'] ?? '', ENT_QUOTES); ?>">
                                <div class="store-avatar" style="position: relative;">
                                    <?php echo strtoupper(substr($store['name'], 0, 2)); ?>
                                    <?php if ($store['unread_count'] > 0): ?>
                                        <div class="online-indicator"></div>
                                    <?php endif; ?>
                                </div>
                                <div class="store-info">
                                    <div class="store-name"><?php echo htmlspecialchars($store['name']); ?></div>
                                    <?php if (!empty($store['last_message'])): ?>
                                        <div class="store-last-message">
                                            <?php if ($store['last_sender'] === 'admin'): ?>
                                                <strong>You:</strong>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars(substr($store['last_message'], 0, 30)); ?>
                                            <?php echo strlen($store['last_message']) > 30 ? '...' : ''; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="store-last-message">No messages yet</div>
                                    <?php endif; ?>
                                </div>
                                <div class="store-meta">
                                    <?php if (!empty($store['last_message_time'])): ?>
                                        <div class="message-time">
                                            <?php echo format_ts($store['last_message_time']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($store['unread_count'] > 0): ?>
                                        <div class="unread-badge"><?php echo $store['unread_count']; ?></div>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Chat Area -->
            <div class="chat-panel">
                <?php if (!$current_store): ?>
                    <div class="empty-state">
                        <i class="bi bi-chat-left-dots"></i>
                        <h4>Select a Store</h4>
                        <p>Choose a store from the left panel to start chatting</p>
                    </div>
                <?php else: ?>
                    <!-- Chat Header -->
                    <div class="chat-header">
                        <div class="chat-store-info">
                            <div class="chat-store-avatar">
                                <?php echo strtoupper(substr($current_store['name'], 0, 2)); ?>
                            </div>
                            <div class="chat-store-details">
                                <h5><?php echo htmlspecialchars($current_store['name']); ?></h5>
                                <small>
                                    <?php if (!empty($current_store['admin_email'])): ?>
                                        <i class="bi bi-envelope me-1"></i>
                                        <?php echo htmlspecialchars($current_store['admin_email']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($current_store['phone'])): ?>
                                        <i class="bi bi-telephone ms-2 me-1"></i>
                                        <?php echo htmlspecialchars($current_store['phone']); ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                        <div class="chat-actions">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="store_id" value="<?php echo $current_store_id; ?>">
                                <button type="submit" name="mark_read" class="btn btn-chat-action btn-mark-read"<?php echo $current_unread ? '' : ' style="display:none;"'; ?>>
                                    <i class="bi bi-check-all me-1"></i>Mark Read
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Messages -->
                    <div class="messages-container" id="messagesContainer">
                        <?php if (empty($messages)): ?>
                            <div class="empty-state">
                                <i class="bi bi-chat"></i>
                                <h4>No messages yet</h4>
                                <p>Start the conversation by sending a message below</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $msg): ?>
                                <div class="message <?php echo $msg['sender'] === 'admin' ? 'admin' : 'store'; ?>">
                                    <div class="message-bubble">
                                        <?php if ($msg['sender'] !== 'admin'): ?>
                                            <div class="message-sender">
                                                <?php echo htmlspecialchars($msg['store_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($msg['filename'])): ?>
                                            <?php if (strpos($msg['mime'], 'image/') === 0): ?>
                                                <div class="mb-1"><a href="https://drive.google.com/uc?export=view&id=<?php echo $msg['drive_id']; ?>" target="_blank"><img src="thumbnail.php?id=<?php echo $msg['upload_id']; ?>&size=medium" alt="<?php echo htmlspecialchars($msg['filename']); ?>" class="message-img"></a></div>
                                            <?php elseif (strpos($msg['mime'], 'video/') === 0): ?>
                                                <div class="mb-1"><video src="https://drive.google.com/uc?export=view&id=<?php echo $msg['drive_id']; ?>" controls class="message-video"></video></div>
                                            <?php else: ?>
                                                <div class="mb-1"><a href="https://drive.google.com/file/d/<?php echo $msg['drive_id']; ?>/view" target="_blank"><?php echo htmlspecialchars($msg['filename']); ?></a></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <div class="message-content">
                                            <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                        </div>
                                        <?php if ($msg['sender'] === 'store'): ?>
                                            <div class="message-reactions">
                                                <button class="reaction-button like <?php echo ($msg['like_by_admin']||$msg['like_by_store']) ? 'active' : ''; ?>" data-id="<?php echo $msg['id']; ?>" data-type="like">
                                                    <i class="bi bi-hand-thumbs-up<?php echo ($msg['like_by_admin']||$msg['like_by_store']) ? '-fill' : ''; ?>"></i>
                                                </button>
                                                <button class="reaction-button love <?php echo ($msg['love_by_admin']||$msg['love_by_store']) ? 'active' : ''; ?>" data-id="<?php echo $msg['id']; ?>" data-type="love">
                                                    <i class="bi bi-heart<?php echo ($msg['love_by_admin']||$msg['love_by_store']) ? '-fill' : ''; ?>"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                        <div class="message-time">
                                            <?php echo format_ts($msg['created_at']); ?>
                                            <?php if ($msg['sender'] === 'admin' && ($msg['read_by_store'] ?? 0)): ?>
                                                <i class="bi bi-check2-all text-primary"></i>
                                            <?php elseif ($msg['sender'] === 'store' && ($msg['read_by_admin'] ?? 0)): ?>
                                                <i class="bi bi-check2-all text-primary"></i>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Message Input -->
                    <div class="message-input-container">
                        <form method="post" class="message-input-form" enctype="multipart/form-data" id="chatForm">
                            <input type="hidden" name="store_id" value="<?php echo $current_store_id; ?>">
                            <textarea name="message"
                                      class="message-input"
                                      placeholder="Type your message..."
                                      rows="1"
                                      id="messageInput"></textarea>
                            <input type="file" name="file" id="fileInput" class="d-none">
                            <div id="filePreview" class="file-preview d-none"></div>
                            <button type="button" class="btn-action" id="fileBtn" title="Upload file"><i class="bi bi-paperclip"></i></button>
                            <button type="button" class="btn-action" id="emojiBtn" title="Add emoji"><i class="bi bi-emoji-smile"></i></button>
                            <button type="submit" name="send_message" class="btn-send" id="sendButton">
                                <i class="bi bi-send-fill"></i>
                            </button>
                        </form>
                        <div id="emojiPicker"></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php $last_id = !empty($messages) ? end($messages)['id'] : null; ?>
    <script src="../assets/js/emoji-picker.js"></script>
    <script>
        // Auto-resize textarea
        const messageInput = document.getElementById('messageInput');
        const sendButton = document.getElementById('sendButton');
        const fileBtn = document.getElementById('fileBtn');
        const fileInput = document.getElementById('fileInput');
        const filePreview = document.getElementById('filePreview');
        const emojiBtn = document.getElementById('emojiBtn');
        const emojiPicker = document.getElementById('emojiPicker');
        const messagesContainer = document.getElementById('messagesContainer');

        let lastMessageId = <?php echo $last_id !== null ? (int)$last_id : 'null'; ?>;

        sendButton.disabled = messageInput.value.trim() === '' && !fileInput.files.length;

        const chatForm = document.getElementById('chatForm');
        document.querySelectorAll('.store-item').forEach(item => {
            item.addEventListener('click', e => {
                e.preventDefault();
                selectStore(item);
            });
        });

        function selectStore(item) {
            const id = item.dataset.id;
            if (!id) return;
            chatForm.querySelector('[name="store_id"]').value = id;
            document.querySelectorAll('.store-item').forEach(i => i.classList.remove('active'));
            item.classList.add('active');
            const avatar = document.querySelector('.chat-store-avatar');
            const nameEl = document.querySelector('.chat-store-details h5');
            const detailEl = document.querySelector('.chat-store-details small');
            if (avatar) avatar.textContent = item.dataset.name.substring(0,2).toUpperCase();
            if (nameEl) nameEl.textContent = item.dataset.name;
            if (detailEl) {
                detailEl.innerHTML = '';
                if (item.dataset.email) {
                    detailEl.innerHTML += `<i class="bi bi-envelope me-1"></i>${item.dataset.email}`;
                }
                if (item.dataset.phone) {
                    detailEl.innerHTML += ` <i class="bi bi-telephone ms-2 me-1"></i>${item.dataset.phone}`;
                }
            }
            refreshMessages();
            if (typeof checkNotifications === 'function') { checkNotifications(); }
        }

        if (messageInput) {
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';

                // Enable/disable send button
                sendButton.disabled = this.value.trim() === '' && !fileInput.files.length;
            });

            // Send message on Enter (shift+enter for newline)
            messageInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    chatForm.dispatchEvent(new Event('submit'));
                }
            });
        }

        if (fileBtn && fileInput) {
            fileBtn.addEventListener('click', () => fileInput.click());
            fileInput.addEventListener('change', () => {
                if (fileInput.files.length) {
                    previewAttachment(fileInput.files[0]);
                } else {
                    clearAttachment();
                }
                sendButton.disabled = messageInput.value.trim() === '' && !fileInput.files.length;
            });
        }

        function previewAttachment(file) {
            if (!filePreview) return;
            filePreview.innerHTML = '';
            let url = '';
            if (file.type.startsWith('image/')) {
                url = URL.createObjectURL(file);
                const img = document.createElement('img');
                img.src = url;
                img.alt = file.name;
                filePreview.appendChild(img);
                filePreview.onclick = () => window.open(url, '_blank');
            } else {
                filePreview.textContent = file.name;
                filePreview.onclick = null;
            }
            filePreview.classList.remove('d-none');
        }

        function clearAttachment() {
            if (!filePreview) return;
            filePreview.innerHTML = '';
            filePreview.classList.add('d-none');
            filePreview.onclick = null;
        }

        if (emojiBtn && emojiPicker && typeof initEmojiPicker === 'function') {
            initEmojiPicker(messageInput, emojiBtn, emojiPicker);
        }

        function refreshMessages() {
            const storeId = chatForm.querySelector('[name="store_id"]').value;
            if (!storeId) return;
            fetch(`chat.php?store_id=${storeId}&load=1`)
                .then(r => r.json())
                .then(data => {
                    if (!data.length) return;
                    const newLastId = data[data.length - 1].id;
                    if (newLastId === lastMessageId && data.length === messagesContainer.children.length) {
                        return;
                    }
                    lastMessageId = newLastId;

                    const prevScroll = messagesContainer.scrollTop;
                    const prevHeight = messagesContainer.scrollHeight;
                    const atBottom = prevHeight - prevScroll - messagesContainer.clientHeight < 50;
                    messagesContainer.innerHTML = '';
                    data.forEach(m => {
                        const wrap = document.createElement('div');
                        wrap.className = 'message ' + (m.sender === 'admin' ? 'admin' : 'store');
                        let html = '<div class="message-bubble">';
                        if (m.sender !== 'admin') {
                            html += `<div class="message-sender">${m.store_name}</div>`;
                        }
                        if (m.filename) {
                            if (m.mime && m.mime.startsWith('image/')) {
                                html += `<div class="mb-1"><a href="https://drive.google.com/uc?export=view&id=${m.drive_id}" target="_blank"><img src="thumbnail.php?id=${m.upload_id}&size=medium" class="message-img" alt="${m.filename}"></a></div>`;
                            } else if (m.mime && m.mime.startsWith('video/')) {
                                html += `<div class="mb-1"><video src="https://drive.google.com/uc?export=view&id=${m.drive_id}" class="message-video" controls></video></div>`;
                            } else {
                                html += `<div class="mb-1"><a href="https://drive.google.com/file/d/${m.drive_id}/view" target="_blank">${m.filename}</a></div>`;
                            }
                        }
                        html += `<div class="message-content">${m.message.replace(/\n/g,'<br>')}</div>`;
                        let readIcon = '';
                        if (m.sender === 'admin' && m.read_by_store) readIcon = ' <i class="bi bi-check2-all text-primary"></i>';
                        if (m.sender === 'store' && m.read_by_admin) readIcon = ' <i class="bi bi-check2-all text-primary"></i>';
                        if (m.sender === 'store') {
                            html += `<div class="message-reactions">`+
                                    `<button class="reaction-button like ${(m.like_by_admin || m.like_by_store)?'active':''}" data-id="${m.id}" data-type="like">`+
                                    `<i class="bi bi-hand-thumbs-up${(m.like_by_admin || m.like_by_store)?'-fill':''}"></i>`+
                                    `</button>`+
                                    `<button class="reaction-button love ${(m.love_by_admin || m.love_by_store)?'active':''}" data-id="${m.id}" data-type="love">`+
                                    `<i class="bi bi-heart${(m.love_by_admin || m.love_by_store)?'-fill':''}"></i>`+
                                    `</button>`+
                                    `</div>`;
                        }
                        html += `<div class="message-time">${m.created_at}${readIcon}</div></div>`;
                        wrap.innerHTML = html;
                        messagesContainer.appendChild(wrap);
                    });
                    const newHeight = messagesContainer.scrollHeight;
                    if (atBottom) {
                        messagesContainer.scrollTop = newHeight;
                    } else {
                        messagesContainer.scrollTop = prevScroll + (newHeight - prevHeight);
                    }
                    initReactions();
                    if (typeof checkNotifications === 'function') { checkNotifications(); }
                });
        }

        function initReactions() {
            document.querySelectorAll('.reaction-button').forEach(btn => {
                btn.addEventListener('click', () => {
                    const fd = new FormData();
                    fd.append('id', btn.dataset.id);
                    fd.append('type', btn.dataset.type);
                    fetch('../react.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(() => {
                            refreshMessages();
                            if (typeof checkNotifications === 'function') { checkNotifications(); }
                        });
                });
            });
        }

        if (chatForm) {
            chatForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const fd = new FormData(chatForm);
                const hasFile = fileInput.files.length > 0;
                const url = hasFile ? '../chat_upload.php' : 'send_message.php';
                fetch(url, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            chatForm.reset();
                            messageInput.style.height = 'auto';
                            clearAttachment();
                            sendButton.disabled = true;
                            refreshMessages();
                        } else if (res.error) {
                            alert(res.error);
                        } else {
                            alert('Send failed');
                        }
                    });
            });
        }

        // Auto-scroll to bottom of messages
        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        // Counter animations
        document.addEventListener('DOMContentLoaded', function() {
            const counters = document.querySelectorAll('[data-count]');
            counters.forEach(counter => {
                const target = parseInt(counter.getAttribute('data-count'));
                const duration = 1000;
                const step = target / (duration / 16);
                let current = 0;

                const timer = setInterval(() => {
                    current += step;
                    if (current >= target) {
                        counter.textContent = target;
                        clearInterval(timer);
                    } else {
                        counter.textContent = Math.floor(current);
                    }
                }, 16);
            });
        });

        // Periodically refresh messages
        setInterval(refreshMessages, 5000);
    </script>

<?php include __DIR__.'/footer.php'; ?>