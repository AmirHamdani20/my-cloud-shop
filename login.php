<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$allowedRedirects = ['index.php', 'cart.php', 'checkout.php', 'orders.php', 'wishlist.php', 'admin.php'];
$redirectTo = (string) ($_POST['redirect'] ?? $_GET['redirect'] ?? 'index.php');
if (!in_array($redirectTo, $allowedRedirects, true)) {
    $redirectTo = 'index.php';
}

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . $redirectTo);
    exit;
}

$notice = '';
$noticeType = 'error';
$email = '';
if (($_GET['registered'] ?? '') === '1') {
    $notice = 'Your account is ready. Sign in to continue.';
    $noticeType = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        $notice = 'Your request could not be verified. Please refresh the page and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $notice = 'Enter a valid email address and password.';
    } else {
        try {
            $pdo = cloudcartPdo();
            $query = $pdo->prepare(
                'SELECT id, name, email, password, role, mfa_enabled
                 FROM dbo.shopusers
                 WHERE email = :email'
            );
            $query->execute(['email' => $email]);
            $user = $query->fetch();

            if ($user && is_string($user['password']) && password_verify($password, $user['password'])) {
                if ((bool) $user['mfa_enabled']) {
                    $_SESSION['pending_mfa_user_id'] = (int) $user['id'];
                    $_SESSION['pending_mfa_started_at'] = time();
                    $_SESSION['pending_mfa_redirect'] = $redirectTo;
                    unset($_SESSION['user_id'], $_SESSION['name'], $_SESSION['email'], $_SESSION['role']);
                    header('Location: verify-mfa.php');
                    exit;
                }

                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['name'] = (string) $user['name'];
                $_SESSION['email'] = (string) $user['email'];
                $_SESSION['role'] = (string) $user['role'];
                header('Location: ' . $redirectTo);
                exit;
            }

            $notice = 'The email or password is incorrect.';
        } catch (Throwable $exception) {
            error_log('CloudCart sign-in error: ' . $exception->getMessage());
            http_response_code(500);
            $notice = 'Sign-in is temporarily unavailable. Please try again later.';
        }
    }
}

$pageTitle = 'Sign In';
require_once __DIR__ . '/header.php';
?>
<section class="mx-auto max-w-md rounded-2xl border border-slate-200 bg-white p-7 shadow-sm sm:p-9">
    <div class="mb-7 text-center">
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">Welcome back</h1>
        <p class="mt-2 text-sm text-slate-500">Sign in to manage your CloudCart account.</p>
    </div>

    <?php if ($notice !== ''): ?>
        <div role="<?= $noticeType === 'error' ? 'alert' : 'status' ?>" class="mb-5 rounded-lg border px-4 py-3 text-sm <?= $noticeType === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
            <?= e($notice) ?>
        </div>
    <?php endif; ?>

    <form action="login.php" method="post" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="redirect" value="<?= e($redirectTo) ?>">
        <div>
            <label for="email" class="mb-2 block text-sm font-semibold text-slate-700">Email address</label>
            <input id="email" name="email" type="email" autocomplete="email" required maxlength="255" value="<?= e($email) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
        </div>
        <div>
            <label for="password" class="mb-2 block text-sm font-semibold text-slate-700">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
        </div>
        <button type="submit" class="w-full rounded-lg bg-indigo-600 px-5 py-3 font-semibold text-white hover:bg-indigo-700">
            Sign In
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-600">
        Don’t have an account?
        <a href="register.php" class="font-semibold text-indigo-700 hover:text-indigo-900">Register</a>
    </p>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
