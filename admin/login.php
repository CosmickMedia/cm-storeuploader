<?php
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';

$errors = [];

// Ensure session is started
ensure_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (login($_POST['username'], $_POST['password'])) {
        // Check if there's a redirect URL
        $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
        unset($_SESSION['redirect_after_login']);

        // Make sure the redirect is relative and safe
        if (strpos($redirect, '/admin/') !== false) {
            header('Location: ' . $redirect);
        } else {
            header('Location: index.php');
        }
        exit;
    } else {
        $errors[] = 'Login failed - Invalid username or password';
    }
}

// Check if already logged in
if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

include __DIR__.'/login_header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="text-center">
            <img src="/assets/images/mediahub-admin-logo.png" alt="MediaHub Admin" class="login-logo">
        </div>
        <div class="card shadow">
            <div class="card-body">
                <h3 class="card-title text-center mb-4">Admin Login</h3>
                <?php foreach ($errors as $e) echo "<div class=\"alert alert-danger\">$e</div>"; ?>
                <form method="post">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" name="username" id="username" class="form-control form-control-lg" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" name="password" id="password" class="form-control form-control-lg" required>
                    </div>
                    <button class="btn btn-login btn-lg w-100" type="submit">Login</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__.'/login_footer.php'; ?>
