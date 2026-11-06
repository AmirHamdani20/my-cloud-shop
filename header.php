<?php
$pageTitle = $pageTitle ?? 'CloudCart';
$notice = $notice ?? '';
$noticeType = $noticeType ?? 'success';
$cartCount = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $quantity) {
        $cartCount += max(0, (int) $quantity);
    }
}
if (isset($_SESSION['flash_notice'])) {
    $notice = (string) $_SESSION['flash_notice'];
    unset($_SESSION['flash_notice']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | CloudCart</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
            <a href="index.php" class="text-2xl font-extrabold tracking-tight text-indigo-700">CloudCart</a>
            <nav aria-label="Main navigation" class="flex items-center gap-4 text-sm font-medium">
                <a href="index.php" class="text-slate-600 hover:text-indigo-700">Shop</a>
                <a href="cart.php" class="text-slate-600 hover:text-indigo-700">Cart <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-indigo-700"><?= e($cartCount) ?></span></a>
                <a href="wishlist.php" class="hidden text-slate-600 hover:text-indigo-700 sm:inline">Wishlist</a>
                <?php if (!empty($_SESSION['user_id'])): ?>
                    <span class="hidden text-slate-600 md:inline">Hi, <?= e($_SESSION['name'] ?? 'My Account') ?></span>
                    <form action="index.php" method="post" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="font-semibold text-indigo-700 hover:text-indigo-900">Logout</button>
                    </form>
                <?php else: ?>
                    <a href="login.php" class="font-semibold text-indigo-700 hover:text-indigo-900">Sign In</a>
                    <a href="register.php" class="rounded-lg bg-indigo-600 px-3 py-2 font-semibold text-white hover:bg-indigo-700">Register</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>
    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <?php if ($notice !== ''): ?>
            <div role="status" class="mb-6 rounded-lg border px-4 py-3 <?= $noticeType === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
                <?= e($notice) ?>
            </div>
        <?php endif; ?>
