<?php
$pageTitle = $pageTitle ?? 'GameGrid';
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
    <meta name="description" content="<?= e($pageTitle) ?> at GameGrid. Find your next game across the platforms you play.">
    <title><?= e($pageTitle) ?> | GameGrid</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <div class="bg-violet-950 px-4 py-2 text-center text-xs font-bold tracking-[0.14em] text-violet-100">
        <span class="text-fuchsia-300">GAMEGRID</span><span class="mx-2 text-violet-700">/</span>PLAYSTATION · XBOX · NINTENDO · PC &amp; MORE
    </div>
    <header class="border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex min-h-[76px] flex-wrap items-center justify-between gap-3 py-3">
                <a href="index.php" class="group inline-flex items-center gap-2.5" aria-label="GameGrid home">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-700 text-white shadow-sm transition group-hover:bg-violet-800">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7.5 8.5 6 8a3.5 3.5 0 0 0-4.4 2.4l-.7 2.4A2.5 2.5 0 0 0 3.3 16h1.1a2 2 0 0 0 1.6-.8l1-1.2h6l1 1.2a2 2 0 0 0 1.6.8h1.1a2.5 2.5 0 0 0 2.4-3.2l-.7-2.4A3.5 3.5 0 0 0 14 8l-1.5.5h-5ZM5 10v3m-1.5-1.5h3m8-.5h.01M17 13h.01"/>
                        </svg>
                    </span>
                    <span class="text-xl font-black tracking-tight text-slate-950 sm:text-2xl">GAME<span class="text-violet-700">GRID</span></span>
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
                        <a href="<?= e($href) ?>" <?= $isActive ? 'aria-current="page"' : '' ?> class="whitespace-nowrap rounded-lg px-3 py-2 transition <?= $isActive ? 'bg-violet-50 text-violet-700' : 'text-slate-600 hover:bg-slate-50 hover:text-violet-700' ?>">
                            <?= e($label) ?>
                            <?php if ($href === 'cart.php'): ?><span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700"><?= e($cartCount) ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="ml-auto flex items-center gap-2 sm:ml-0 sm:gap-3">
                    <?php if (!empty($_SESSION['user_id'])): ?>
                        <span class="hidden max-w-36 truncate text-sm font-medium text-slate-500 md:inline">Hi, <?= e($_SESSION['name'] ?? 'there') ?></span>
                        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                            <a href="admin.php" class="rounded-lg px-3 py-2 text-sm font-semibold text-violet-700 hover:bg-violet-50">Admin</a>
                        <?php endif; ?>
                        <form action="index.php" method="post" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">Log out</button>
                        </form>
                    <?php else: ?>
                        <a href="login.php" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Sign in</a>
                        <a href="register.php" class="rounded-lg bg-violet-700 px-3 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-violet-800">Join GameGrid</a>
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
