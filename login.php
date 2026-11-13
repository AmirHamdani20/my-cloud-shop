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
<section class="mx-auto grid max-w-4xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl md:grid-cols-[0.85fr_1.15fr]">
    <aside class="relative hidden overflow-hidden bg-slate-950 p-9 text-white md:flex md:flex-col md:justify-between lg:p-11">
        <div class="absolute -right-20 -top-20 h-56 w-56 rounded-full bg-indigo-600/40 blur-3xl"></div>
        <div class="relative">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-cyan-300">Your GameGrid account</p>
            <h2 class="mt-5 text-3xl font-black leading-tight">Your next great game is just a sign in away.</h2>
        </div>
        <div class="relative mt-10 rounded-2xl border border-white/10 bg-white/[0.06] p-5">
            <p class="text-sm leading-6 text-slate-300">Keep your saved games close and pick up where you left off.</p>
            <a href="index.php" class="mt-4 inline-flex text-sm font-bold text-cyan-300 hover:text-cyan-200">Browse games <span class="ml-1" aria-hidden="true">→</span></a>
        </div>
    </aside>
    <div class="p-7 sm:p-10 lg:p-12">
    <div class="mb-7">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-600">Player sign in</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950">Welcome back</h1>
        <p class="mt-2 text-sm text-slate-500">Sign in to manage your GameGrid account.</p>
    </div>

    <?php if ($notice !== ''): ?>
        <div role="<?= $noticeType === 'error' ? 'alert' : 'status' ?>" class="mb-5 rounded-xl border px-4 py-3 text-sm <?= $noticeType === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
            <?= e($notice) ?>
        </div>
    <?php endif; ?>

    <form action="login.php" method="post" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="redirect" value="<?= e($redirectTo) ?>">
        <div>
            <label for="email" class="mb-2 block text-sm font-semibold text-slate-700">Email address</label>
            <input id="email" name="email" type="email" autocomplete="email" required maxlength="255" value="<?= e($email) ?>" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-3 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100">
        </div>
        <div>
            <label for="password" class="mb-2 block text-sm font-semibold text-slate-700">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-3 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100">
        </div>
        <button type="submit" class="w-full rounded-xl bg-indigo-600 px-5 py-3.5 font-bold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200">
            Sign In
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-600">
        Don’t have an account?
        <a href="register.php" class="font-semibold text-indigo-700 hover:text-indigo-900">Register</a>
    </p>
    </div>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
