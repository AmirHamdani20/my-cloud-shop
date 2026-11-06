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
<section class="mx-auto max-w-md rounded-2xl border border-slate-200 bg-white p-7 shadow-sm sm:p-9">
    <div class="mb-7 text-center">
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">Create your account</h1>
        <p class="mt-2 text-sm text-slate-500">Join CloudCart and keep track of your shopping.</p>
    </div>

    <?php if ($notice !== ''): ?>
        <div role="alert" class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?= e($notice) ?></div>
    <?php endif; ?>

    <form action="register.php" method="post" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <div>
            <label for="name" class="mb-2 block text-sm font-semibold text-slate-700">Full name</label>
            <input id="name" name="name" type="text" autocomplete="name" maxlength="100" required value="<?= e($name) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
        </div>
        <div>
            <label for="email" class="mb-2 block text-sm font-semibold text-slate-700">Email address</label>
            <input id="email" name="email" type="email" autocomplete="email" maxlength="255" required value="<?= e($email) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
        </div>
        <div>
            <label for="password" class="mb-2 block text-sm font-semibold text-slate-700">Password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
            <p class="mt-1 text-xs text-slate-500">Use at least 8 characters.</p>
        </div>
        <button type="submit" class="w-full rounded-lg bg-indigo-600 px-5 py-3 font-semibold text-white hover:bg-indigo-700">Create account</button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-600">
        Already have an account? <a href="login.php" class="font-semibold text-indigo-700 hover:text-indigo-900">Sign in</a>
    </p>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
