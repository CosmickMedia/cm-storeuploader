<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/groundhogg.php';
require_once __DIR__.'/../lib/helpers.php';
require_login();
$pdo = get_pdo();

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare('SELECT * FROM stores WHERE id = ?');
$stmt->execute([$id]);
$store = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$store) {
    header('Location: stores.php');
    exit;
}

$errors = [];
$success = [];

// Fetch store users
$userStmt = $pdo->prepare('SELECT * FROM store_users WHERE store_id = ? ORDER BY email');
$userStmt->execute([$id]);
$store_users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_store'])) {
        $stmt = $pdo->prepare('SELECT id FROM stores WHERE pin = ? AND id <> ?');
        $stmt->execute([$_POST['pin'], $id]);
        if ($stmt->fetch()) {
            $errors[] = 'PIN already exists';
        } else {
            $update = $pdo->prepare('UPDATE stores SET name=?, pin=?, admin_email=?, drive_folder=?, hootsuite_token=?, hootsuite_campaign_tag=?, first_name=?, last_name=?, phone=?, address=?, city=?, state=?, zip_code=?, country=?, marketing_report_url=? WHERE id=?');
            $update->execute([
                $_POST['name'],
                $_POST['pin'],
                $_POST['email'],
                $_POST['folder'],
                $_POST['hootsuite_token'],
                $_POST['hootsuite_campaign_tag'] ?? null,
                $_POST['first_name'] ?? null,
                $_POST['last_name'] ?? null,
                format_mobile_number($_POST['phone'] ?? ''),
                $_POST['address'] ?? null,
                $_POST['city'] ?? null,
                $_POST['state'] ?? null,
                $_POST['zip_code'] ?? null,
                $_POST['country'] ?? null,
                $_POST['marketing_report_url'] ?? null,
                $id
            ]);
            $success[] = 'Store updated successfully';

            // If email is set, sync with Groundhogg
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
                    'opt_in_status'=> $optin,
                    'tags'         => groundhogg_get_default_tags(),
                    'store_id'     => (int)$id
                ];

                [$ghSuccess, $ghMessage] = groundhogg_send_contact($contact);
                if ($ghSuccess) {
                    $success[] = $ghMessage;
                } else {
                    $errors[] = 'Store updated but Groundhogg sync failed: ' . $ghMessage;
                }
            }

            $stmt = $pdo->prepare('SELECT * FROM stores WHERE id = ?');
            $stmt->execute([$id]);
            $store = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } elseif (isset($_POST['add_user'])) {
        $email = trim($_POST['user_email']);
        $first = trim($_POST['user_first_name'] ?? '');
        $last = trim($_POST['user_last_name'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $mobile = format_mobile_number($_POST['user_mobile_phone'] ?? '');
            $optin = $_POST['user_opt_in_status'] ?? 'confirmed';
            try {
                $stmt = $pdo->prepare('INSERT INTO store_users (store_id, email, first_name, last_name, mobile_phone, opt_in_status) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$id, $email, $first ?: null, $last ?: null, $mobile ?: null, $optin]);
                $insertId = $pdo->lastInsertId();
                $success[] = 'User added';
                $store_users[] = ['id' => $insertId, 'email' => $email, 'first_name' => $first, 'last_name' => $last, 'mobile_phone' => $mobile, 'opt_in_status' => $optin];

                // Send to Groundhogg
                $contact = [
                    'email'        => $email,
                    'first_name'   => $first,
                    'last_name'    => $last,
                    'mobile_phone' => $mobile,
                    'address'      => $store['address'] ?? '',
                    'city'         => $store['city'] ?? '',
                    'state'        => $store['state'] ?? '',
                    'zip'          => $store['zip_code'] ?? '',
                    'country'      => $store['country'] ?? '',
                    'company_name' => $store['name'] ?? '',
                    'user_role'    => 'Store Admin',
                    'lead_source'  => 'mediahub',
                    'opt_in_status'=> 'confirmed',
                    'tags'         => groundhogg_get_default_tags(),
                    'store_id'     => (int)$id
                ];

                [$ghSuccess, $ghMessage] = groundhogg_send_contact($contact);
                if ($ghSuccess) {
                    $success[] = 'User added and ' . $ghMessage;
                } else {
                    $errors[] = 'User added but Groundhogg sync failed: ' . $ghMessage;
                }
            } catch (PDOException $e) {
                $errors[] = 'User already exists for this store';
            }
        } else {
            $errors[] = 'Invalid email';
        }
    } elseif (isset($_POST['delete_user'])) {
        $stmt = $pdo->prepare('SELECT email FROM store_users WHERE id=? AND store_id=?');
        $stmt->execute([$_POST['user_id'], $id]);
        $email = $stmt->fetchColumn();

        $stmt = $pdo->prepare('DELETE FROM store_users WHERE id=? AND store_id=?');
        $stmt->execute([$_POST['user_id'], $id]);
        $success[] = 'User removed';

        if ($email) {
            [$delSuccess, $delMsg] = groundhogg_delete_contact($email);
            if (!$delSuccess) {
                $errors[] = 'Groundhogg delete failed: ' . $delMsg;
            }
        }

        // Refresh user list
        $userStmt->execute([$id]);
        $store_users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$active = 'stores';
include __DIR__.'/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4>Edit Store</h4>
    <a href="stores.php" class="btn btn-sm btn-outline-secondary">Back</a>
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
<form method="post">
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Store Details</h5></div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label for="name" class="form-label">Store Name *</label>
                <input type="text" name="name" id="name" class="form-control" required value="<?php echo htmlspecialchars($store['name']); ?>">
            </div>
            <div class="col-md-6">
                <label for="pin" class="form-label">PIN *</label>
                <input type="text" name="pin" id="pin" class="form-control" required value="<?php echo htmlspecialchars($store['pin']); ?>">
            </div>
            <div class="col-md-6">
                <label for="first_name" class="form-label">First Name</label>
                <input type="text" name="first_name" id="first_name" class="form-control" value="<?php echo htmlspecialchars($store['first_name']); ?>">
            </div>
            <div class="col-md-6">
                <label for="last_name" class="form-label">Last Name</label>
                <input type="text" name="last_name" id="last_name" class="form-control" value="<?php echo htmlspecialchars($store['last_name']); ?>">
            </div>
            <div class="col-md-6">
                <label for="email" class="form-label">Admin Email</label>
                <input type="email" name="email" id="email" class="form-control" value="<?php echo htmlspecialchars($store['admin_email']); ?>">
            </div>
            <div class="col-md-6">
                <label for="phone" class="form-label">Phone</label>
                <input type="text" name="phone" id="phone" class="form-control" value="<?php echo htmlspecialchars($store['phone']); ?>">
            </div>
            <div class="col-md-6">
                <label for="address" class="form-label">Address</label>
                <input type="text" name="address" id="address" class="form-control" value="<?php echo htmlspecialchars($store['address']); ?>">
            </div>
            <div class="col-md-6">
                <label for="city" class="form-label">City</label>
                <input type="text" name="city" id="city" class="form-control" value="<?php echo htmlspecialchars($store['city']); ?>">
            </div>
            <div class="col-md-6">
                <label for="state" class="form-label">State</label>
                <input type="text" name="state" id="state" class="form-control" value="<?php echo htmlspecialchars($store['state']); ?>">
            </div>
            <div class="col-md-6">
                <label for="zip_code" class="form-label">Zip Code</label>
                <input type="text" name="zip_code" id="zip_code" class="form-control" value="<?php echo htmlspecialchars($store['zip_code']); ?>">
            </div>
            <div class="col-md-6">
                <label for="country" class="form-label">Country</label>
                <input type="text" name="country" id="country" class="form-control" value="<?php echo htmlspecialchars($store['country']); ?>">
            </div>
            <div class="col-md-6">
                <label for="marketing_report_url" class="form-label">Marketing Report URL</label>
                <input type="url" name="marketing_report_url" id="marketing_report_url" class="form-control" value="<?php echo htmlspecialchars($store['marketing_report_url']); ?>">
            </div>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">API Settings</h5></div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label for="folder" class="form-label">Drive Folder ID</label>
                <input type="text" name="folder" id="folder" class="form-control" value="<?php echo htmlspecialchars($store['drive_folder']); ?>">
            </div>
            <input type="hidden" name="hootsuite_token" id="hootsuite_token" value="<?php echo htmlspecialchars($store['hootsuite_token']); ?>">
            <div class="col-md-6">
                <label for="hootsuite_campaign_tag" class="form-label">Hootsuite Tag</label>
                <input type="text" name="hootsuite_campaign_tag" id="hootsuite_campaign_tag" class="form-control" placeholder="do not put spaces and put lowercase, this must match the hootsuite tag" value="<?php echo htmlspecialchars($store['hootsuite_campaign_tag']); ?>">
            </div>
        </div>
    </div>
    <div class="text-end mb-4">
        <button class="btn btn-primary" name="save_store" type="submit">Save Changes</button>
    </div>
</form>

<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0">Store Users</h5>
    </div>
    <div class="card-body">
        <ul class="list-group mb-3">
            <?php foreach ($store_users as $u): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <?php echo htmlspecialchars(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))); ?>
                        <div class="text-muted small"><?php echo htmlspecialchars($u['email']); ?></div>
                    </div>
                    <div>
                        <a href="edit_store_user.php?store_id=<?php echo $id; ?>&id=<?php echo $u['id']; ?>" class="btn btn-sm btn-secondary me-1">Edit</a>
                        <form method="post" class="d-inline m-0">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <button class="btn btn-sm btn-danger" name="delete_user" onclick="return confirm('Remove this user?')">Remove</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
            <?php if (empty($store_users)): ?>
                <li class="list-group-item text-muted">No users</li>
            <?php endif; ?>
        </ul>
        <form method="post" class="row g-3">
            <div class="col-md-3">
                <input type="text" name="user_first_name" class="form-control" placeholder="First Name">
            </div>
            <div class="col-md-3">
                <input type="text" name="user_last_name" class="form-control" placeholder="Last Name">
            </div>
            <div class="col-md-3">
                <input type="email" name="user_email" class="form-control" placeholder="Email" required>
            </div>
            <div class="col-md-3">
                <input type="text" name="user_mobile_phone" class="form-control" placeholder="Mobile Phone">
            </div>
            <div class="col-md-4">
                <select name="user_opt_in_status" class="form-select">
                    <option value="confirmed" selected>Confirmed</option>
                    <option value="unconfirmed">Unconfirmed</option>
                    <option value="unsubscribed">Unsubscribed</option>
                    <option value="subscribed_weekly">Subscribed Weekly</option>
                    <option value="subscribed_monthly">Subscribed Monthly</option>
                    <option value="bounced">Bounced</option>
                    <option value="spam">Spam</option>
                    <option value="complained">Complained</option>
                    <option value="blocked">Blocked</option>
                </select>
            </div>
            <div class="col-12">
                <button class="btn btn-secondary" name="add_user" type="submit">Add User</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__.'/footer.php'; ?>
