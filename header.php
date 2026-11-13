<?php
$pageTitle = $pageTitle ?? 'CloudCart';
$notice = $notice ?? '';
$noticeType = $noticeType ?? 'success';
$currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
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
    <meta name="theme-color" content="#0f172a">
    <meta name="description" content="<?= e($pageTitle) ?> at CloudCart. Discover tech and everyday essentials.">
    <title><?= e($pageTitle) ?> | CloudCart</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <div class="bg-slate-950 px-4 py-2 text-center text-xs font-medium tracking-wide text-slate-200">
        <span class="text-cyan-300">CloudCart</span> <span class="mx-1.5 text-slate-600">/</span> A better way to shop for tech and everyday essentials
    </div>
    <header class="border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex min-h-[76px] flex-wrap items-center justify-between gap-3 py-3">
                <a href="index.php" class="group inline-flex items-center gap-2.5" aria-label="CloudCart home">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-sm transition group-hover:bg-indigo-700">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 2-1.6L22 8H6M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"/>
                        </svg>
                    </span>
                    <span class="text-xl font-black tracking-tight text-slate-950 sm:text-2xl">Cloud<span class="text-indigo-600">Cart</span></span>
                </a>
                <nav aria-label="Main navigation" class="order-3 flex w-full items-center gap-1 overflow-x-auto border-t border-slate-100 pt-2 text-sm font-semibold sm:order-none sm:w-auto sm:border-0 sm:pt-0">
                    <?php
                    $navLinks = [
                        ['index.php', 'Shop'],
                        ['wishlist.php', 'Wishlist'],
                        ['orders.php', 'Orders'],
                        ['cart.php', 'Cart'],
                    ];
                    foreach ($navLinks as [$href, $label]):
                        if (in_array($href, ['wishlist.php', 'orders.php'], true) && empty($_SESSION['user_id'])) {
                            continue;
                        }
                        $isActive = $currentPage === $href;
                    ?>
                        <a href="<?= e($href) ?>" <?= $isActive ? 'aria-current="page"' : '' ?> class="whitespace-nowrap rounded-lg px-3 py-2 transition <?= $isActive ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-50 hover:text-indigo-700' ?>">
                            <?= e($label) ?>
                            <?php if ($href === 'cart.php'): ?><span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700"><?= e($cartCount) ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="ml-auto flex items-center gap-2 sm:ml-0 sm:gap-3">
                    <?php if (!empty($_SESSION['user_id'])): ?>
                        <span class="hidden max-w-36 truncate text-sm font-medium text-slate-500 md:inline">Hi, <?= e($_SESSION['name'] ?? 'there') ?></span>
                        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                            <a href="admin.php" class="rounded-lg px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">Admin</a>
                        <?php endif; ?>
                        <form action="index.php" method="post" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">Log out</button>
                        </form>
                    <?php else: ?>
                        <a href="login.php" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Sign in</a>
                        <a href="register.php" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-indigo-700">Join CloudCart</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>
    <main class="mx-auto min-h-[62vh] max-w-7xl px-4 py-8 sm:px-6 sm:py-10 lg:px-8">
        <?php if ($notice !== ''): ?>
            <div role="<?= $noticeType === 'error' ? 'alert' : 'status' ?>" class="mb-6 flex items-start gap-3 rounded-xl border px-4 py-3.5 text-sm shadow-sm <?= $noticeType === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
                <span class="mt-0.5 font-bold" aria-hidden="true"><?= $noticeType === 'error' ? '!' : '✓' ?></span>
                <span><?= e($notice) ?></span>
            </div>
        <?php endif; ?>
