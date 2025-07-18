<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/groundhogg.php';
require_once __DIR__.'/../lib/helpers.php';
require_login();
$pdo = get_pdo();

$errors = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        // Check if PIN already exists
        $stmt = $pdo->prepare('SELECT id FROM stores WHERE pin = ?');
        $stmt->execute([$_POST['pin']]);
        if ($stmt->fetch()) {
            $errors[] = 'PIN already exists';
        } else {
            $stmt = $pdo->prepare('INSERT INTO stores (name, pin, admin_email, drive_folder, hootsuite_campaign_tag, first_name, last_name, phone, address, city, state, zip_code, country, marketing_report_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $_POST['name'],
                $_POST['pin'],
                $_POST['email'],
                $_POST['folder'],
                $_POST['hootsuite_campaign_tag'] ?? null,
                $_POST['first_name'] ?? null,
                $_POST['last_name'] ?? null,
                format_mobile_number($_POST['phone'] ?? ''),
                $_POST['address'] ?? null,
                $_POST['city'] ?? null,
                $_POST['state'] ?? null,
                $_POST['zip_code'] ?? null,
                $_POST['country'] ?? null,
                $_POST['marketing_report_url'] ?? null
            ]);
            $storeId = $pdo->lastInsertId();
            $success[] = 'Store added successfully';

            // Send to Groundhogg if email is provided
            if (!empty($_POST['email'])) {
                $contact = [
                    'email'        => $_POST['email'],
                    'first_name'   => $_POST['first_name'] ?? '',
                    'last_name'    => $_POST['last_name'] ?? '',
                    'mobile_phone' => format_mobile_number($_POST['phone'] ?? ''),
                    'address'      => $_POST['address'] ?? '',
                    'city'         => $_POST['city'] ?? '',
                    'state'        => $_POST['state'] ?? '',
                    'zip'          => $_POST['zip_code'] ?? '',
                    'country'      => $_POST['country'] ?? '',
                    'company_name' => $_POST['name'] ?? '',
                    'user_role'    => 'Store Admin',
                    'lead_source'  => 'mediahub',
                    'opt_in_status'=> 'confirmed',
                    'tags'         => groundhogg_get_default_tags(),
                    'store_id'     => (int)$storeId
                ];

                [$ghSuccess, $ghMessage] = groundhogg_send_contact($contact);
                if ($ghSuccess) {
                    $success[] = $ghMessage;
                } else {
                    $errors[] = 'Store created but Groundhogg sync failed: ' . $ghMessage;
                }
            }
        }
    }
    if (isset($_POST['delete'])) {
        // Check if store has uploads
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM uploads WHERE store_id = ?');
        $stmt->execute([$_POST['id']]);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            $errors[] = 'Cannot delete store with existing uploads';
        } else {
            $emailStmt = $pdo->prepare('SELECT admin_email FROM stores WHERE id=?');
            $emailStmt->execute([$_POST['id']]);
            $storeEmail = $emailStmt->fetchColumn();

            $userStmt = $pdo->prepare('SELECT email FROM store_users WHERE store_id=?');
            $userStmt->execute([$_POST['id']]);
            $userEmails = $userStmt->fetchAll(PDO::FETCH_COLUMN);

            $stmt = $pdo->prepare('DELETE FROM stores WHERE id=?');
            $stmt->execute([$_POST['id']]);

            $stmt = $pdo->prepare('DELETE FROM store_users WHERE store_id=?');
            $stmt->execute([$_POST['id']]);

            $success[] = 'Store deleted successfully';

            if ($storeEmail) {
                [$delSuccess, $delMsg] = groundhogg_delete_contact($storeEmail);
                if (!$delSuccess) {
                    $errors[] = 'Groundhogg delete failed for main contact: ' . $delMsg;
                }
            }
            foreach ($userEmails as $email) {
                [$delSuccess, $delMsg] = groundhogg_delete_contact($email);
                if (!$delSuccess) {
                    $errors[] = 'Groundhogg delete failed for ' . $email . ': ' . $delMsg;
                }
            }
        }
    }
}

// Get stores sorted by name
$stores = $pdo->query('SELECT s.*, COUNT(u.id) as upload_count,
                       (SELECT COUNT(*) FROM store_messages m WHERE m.store_id = s.id) as chat_count
                       FROM stores s
                       LEFT JOIN uploads u ON s.id = u.store_id
                       GROUP BY s.id
                       ORDER BY s.name ASC')->fetchAll(PDO::FETCH_ASSOC);

$active = 'stores';
include __DIR__.'/header.php';
?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>Store Management</h4>
        <span class="badge bg-secondary">Total Stores: <?php echo count($stores); ?></span>
    </div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($e); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endforeach; ?>

<?php foreach ($success as $s): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($s); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endforeach; ?>

    <div class="card mb-4">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                    <tr>
                        <th>Store Name</th>
                        <th>PIN</th>
                        <th>Admin Email</th>
                        <th>Drive Folder ID</th>
                        <th>Total Chats</th>
                        <th>Uploads</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stores as $s): ?>
                        <tr>
                            <td><strong><a href="edit_store.php?id=<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></a></strong></td>
                            <td><code><?php echo htmlspecialchars($s['pin']); ?></code></td>
                            <td><?php echo htmlspecialchars($s['admin_email']); ?></td>
                            <td>
                                <?php if ($s['drive_folder']): ?>
                                    <a href="https://drive.google.com/drive/folders/<?php echo $s['drive_folder']; ?>" target="_blank">
                                        <?php echo substr($s['drive_folder'], 0, 20); ?>...
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">Auto-create on first upload</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-info"><?php echo $s['chat_count']; ?></span>
                            </td>
                            <td>
                                <span class="badge bg-info"><?php echo $s['upload_count']; ?></span>
                            </td>
                            <td>
                                <a href="uploads.php?store_id=<?php echo $s['id']; ?>" class="btn btn-sm btn-primary">
                                    View Uploads
                                </a>
                                <a href="edit_store.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-secondary">
                                    Edit
                                </a>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                    <button name="delete" class="btn btn-sm btn-danger"
                                            onclick="return confirm('Delete this store? This cannot be undone.')">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Add New Store</h5>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Store Name *</label>
                    <input type="text" name="name" id="name" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label for="pin" class="form-label">PIN (Access Code) *</label>
                    <input type="text" name="pin" id="pin" class="form-control" required
                           pattern="[A-Za-z0-9]{4,}" title="At least 4 alphanumeric characters">
                    <div class="form-text">Unique code for store access</div>
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">Admin Email</label>
                    <input type="email" name="email" id="email" class="form-control">
                    <div class="form-text">For notifications specific to this store</div>
                </div>
                <div class="col-md-6">
                    <label for="phone" class="form-label">Phone</label>
                    <input type="text" name="phone" id="phone" class="form-control">
                </div>
                <div class="col-md-6">
                    <label for="first_name" class="form-label">First Name</label>
                    <input type="text" name="first_name" id="first_name" class="form-control">
                </div>
                <div class="col-md-6">
                    <label for="last_name" class="form-label">Last Name</label>
                    <input type="text" name="last_name" id="last_name" class="form-control">
                </div>
                <div class="col-md-6">
                    <label for="address" class="form-label">Address</label>
                    <input type="text" name="address" id="address" class="form-control">
                </div>
                <div class="col-md-6">
                    <label for="city" class="form-label">City</label>
                    <input type="text" name="city" id="city" class="form-control">
                </div>
                <div class="col-md-6">
                    <label for="state" class="form-label">State</label>
                    <input type="text" name="state" id="state" class="form-control">
                </div>
                <div class="col-md-6">
                    <label for="zip_code" class="form-label">Zip Code</label>
                    <input type="text" name="zip_code" id="zip_code" class="form-control">
                </div>
                <div class="col-md-6">
                    <label for="country" class="form-label">Country</label>
                    <input type="text" name="country" id="country" class="form-control">
                </div>
                <div class="col-md-6">
                    <label for="folder" class="form-label">Drive Folder ID</label>
                    <input type="text" name="folder" id="folder" class="form-control">
                    <div class="form-text">Leave blank to auto-create on first upload</div>
                </div>
                <div class="col-md-6">
                    <label for="hootsuite_campaign_tag" class="form-label">Hootsuite Tag</label>
                    <input type="text" name="hootsuite_campaign_tag" id="hootsuite_campaign_tag" class="form-control">
                </div>
                <div class="col-md-6">
                    <label for="marketing_report_url" class="form-label">Marketing Report URL</label>
                    <input type="url" name="marketing_report_url" id="marketing_report_url" class="form-control">
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" name="add" type="submit">Add Store</button>
                </div>
            </form>
        </div>
    </div>

<?php include __DIR__.'/footer.php'; ?>