<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$name = '';
$email = '';
$notice = '';
$noticeType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        $notice = 'Your request could not be verified. Please refresh the page and try again.';
    } elseif ($name === '' || mb_strlen($name) > 100) {
        $notice = 'Enter a name between 1 and 100 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        $notice = 'Enter a valid email address.';
    } elseif (strlen($password) < 8 || strlen($password) > 4096) {
        $notice = 'Your password must be at least 8 characters long.';
    } else {
        try {
            $pdo = cloudcartPdo();
            $insert = $pdo->prepare(
                "INSERT INTO dbo.shopusers (name, email, password, role, created_at)
                 VALUES (:name, :email, :password, 'customer', SYSUTCDATETIME())"
            );
            $insert->execute([
                'name' => $name,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            header('Location: login.php?registered=1');
            exit;
        } catch (PDOException $exception) {
            error_log('CloudCart registration database error: ' . $exception->getMessage());
            if ($exception->getCode() === '23000' || $exception->getCode() === '23505') {
                $notice = 'An account with that email address already exists.';
            } else {
                http_response_code(500);
                $notice = 'Registration is temporarily unavailable. Please try again later.';
            }
        } catch (Throwable $exception) {
            error_log('CloudCart registration error: ' . $exception->getMessage());
            http_response_code(500);
            $notice = 'Registration is temporarily unavailable. Please try again later.';
        }
    }
}

$pageTitle = 'Register';
require_once __DIR__ . '/header.php';
?>
<section class="mx-auto grid max-w-4xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl md:grid-cols-[0.85fr_1.15fr]">
    <aside class="relative hidden overflow-hidden bg-slate-950 p-9 text-white md:flex md:flex-col md:justify-between lg:p-11">
        <div class="absolute -right-20 -top-20 h-56 w-56 rounded-full bg-cyan-500/30 blur-3xl"></div>
        <div class="relative">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-cyan-300">Make it yours</p>
            <h2 class="mt-5 text-3xl font-black leading-tight">A little more personal. A lot more convenient.</h2>
        </div>
        <div class="relative mt-10 rounded-2xl border border-white/10 bg-white/[0.06] p-5">
            <p class="text-sm leading-6 text-slate-300">Create an account to save favourites and keep an eye on your orders.</p>
            <a href="index.php" class="mt-4 inline-flex text-sm font-bold text-cyan-300 hover:text-cyan-200">Browse the collection <span class="ml-1" aria-hidden="true">→</span></a>
        </div>
    </aside>
    <div class="p-7 sm:p-10 lg:p-12">
    <div class="mb-7">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-600">Join CloudCart</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950">Create your account</h1>
        <p class="mt-2 text-sm text-slate-500">Keep track of the products and orders that matter to you.</p>
    </div>

    <?php if ($notice !== ''): ?>
        <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?= e($notice) ?></div>
    <?php endif; ?>

    <form action="register.php" method="post" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <div>
            <label for="name" class="mb-2 block text-sm font-semibold text-slate-700">Full name</label>
            <input id="name" name="name" type="text" autocomplete="name" maxlength="100" required value="<?= e($name) ?>" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-3 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100">
        </div>
        <div>
            <label for="email" class="mb-2 block text-sm font-semibold text-slate-700">Email address</label>
            <input id="email" name="email" type="email" autocomplete="email" maxlength="255" required value="<?= e($email) ?>" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-3 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100">
        </div>
        <div>
            <label for="password" class="mb-2 block text-sm font-semibold text-slate-700">Password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-3 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100">
            <p class="mt-1 text-xs text-slate-500">Use at least 8 characters.</p>
        </div>
        <button type="submit" class="w-full rounded-xl bg-indigo-600 px-5 py-3.5 font-bold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200">Create account</button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-600">
        Already have an account? <a href="login.php" class="font-semibold text-indigo-700 hover:text-indigo-900">Sign in</a>
    </p>
    </div>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
