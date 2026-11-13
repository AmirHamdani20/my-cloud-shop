<?php
declare(strict_types=1);

session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$notice = '';
$noticeType = 'success';
$showCartLink = false;
$products = [];
$categories = [];
$platformOptions = require __DIR__ . '/game-platforms.php';
$databaseError = false;

$search = trim((string) ($_GET['q'] ?? ''));
$selectedCategory = trim((string) ($_GET['category'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'newest');
$inStockOnly = (string) ($_GET['in_stock'] ?? '') === '1';
$search = mb_substr($search, 0, 100);
$selectedCategory = mb_substr($selectedCategory, 0, 50);
$sortOptions = [
    'newest' => 'created_at DESC, id DESC',
    'price_low' => 'price ASC, id DESC',
    'price_high' => 'price DESC, id DESC',
    'name' => 'name ASC, id DESC',
];
if (!isset($sortOptions[$sort])) {
    $sort = 'newest';
}

$serverName = getenv('DB_SERVER') ?: 'tcp:amir-sql-server2.database.windows.net,1433';
$databaseName = getenv('DB_NAME') ?: 'AmirDatabase';
$databaseUser = getenv('DB_USER') ?: 'AdminAmirA';
$databasePassword = getenv('DB_PASSWORD');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $notice = 'Your request could not be verified. Please refresh the page and try again.';
        $noticeType = 'error';
    } elseif (($_POST['action'] ?? '') === 'logout') {
        unset(
            $_SESSION['user_id'],
            $_SESSION['name'],
            $_SESSION['email'],
            $_SESSION['role'],
            $_SESSION['pending_mfa_user_id'],
            $_SESSION['pending_mfa_started_at'],
            $_SESSION['pending_mfa_redirect'],
            $_SESSION['pending_mfa_attempts']
        );
        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    }
}

if (!$databasePassword) {
    error_log('CloudCart database password is not configured.');
    $databaseError = true;
} else {
    $dsn = sprintf(
        'sqlsrv:Server=%s;Database=%s;Encrypt=true;TrustServerCertificate=false',
        $serverName,
        $databaseName
    );

    try {
        $pdo = new PDO($dsn, $databaseUser, $databasePassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $notice === '') {
            $action = (string) ($_POST['action'] ?? '');
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);

            if ($action !== 'add_to_cart' || !$productId || $productId < 1) {
                http_response_code(400);
                $notice = 'The requested game action is invalid.';
                $noticeType = 'error';
            } else {
                $productLookup = $pdo->prepare('SELECT id, stock_quantity, category FROM dbo.products WHERE id = :id');
                $productLookup->execute(['id' => $productId]);
                $requestedProduct = $productLookup->fetch();

                if (!$requestedProduct || !in_array((string) $requestedProduct['category'], $platformOptions, true)) {
                    http_response_code(404);
                    $notice = 'That game could not be found.';
                    $noticeType = 'error';
                } elseif ($action === 'add_to_cart') {
                    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
                        $_SESSION['cart'] = [];
                    }
                    $currentQuantity = max(0, (int) ($_SESSION['cart'][$productId] ?? 0));
                    if ($currentQuantity >= (int) $requestedProduct['stock_quantity']) {
                        $notice = (int) $requestedProduct['stock_quantity'] < 1
                            ? 'This game is sold out.'
                            : 'You already have all available stock of this game in your cart.';
                        $noticeType = 'error';
                    } else {
                        $_SESSION['cart'][$productId] = $currentQuantity + 1;
                        $_SESSION['flash_notice'] = 'Game added to your cart.';
                        $_SESSION['flash_cart_added'] = true;
                        header('Location: index.php');
                        exit;
                    }
                }
            }
        }

        $categoryStatement = $pdo->query(
            "SELECT DISTINCT category FROM dbo.products
             WHERE category IS NOT NULL AND LTRIM(RTRIM(category)) <> ''
             ORDER BY category"
        );
        $categories = array_values(array_filter(
            $categoryStatement->fetchAll(PDO::FETCH_COLUMN),
            static fn ($category): bool => in_array((string) $category, $platformOptions, true)
        ));

        $sql = 'SELECT id, name, description, price, stock_quantity, category, image_url
                FROM dbo.products';
        $conditions = [];
        $parameters = [];
        $platformPlaceholders = [];
        foreach ($platformOptions as $index => $platform) {
            $parameterName = 'platform' . $index;
            $platformPlaceholders[] = ':' . $parameterName;
            $parameters[$parameterName] = $platform;
        }
        $conditions[] = 'category IN (' . implode(', ', $platformPlaceholders) . ')';

        if ($search !== '') {
            $conditions[] = '(name LIKE :search_name OR description LIKE :search_description OR category LIKE :search_platform)';
            $searchTerm = '%' . $search . '%';
            $parameters['search_name'] = $searchTerm;
            $parameters['search_description'] = $searchTerm;
            $parameters['search_platform'] = $searchTerm;
        }
        if ($selectedCategory !== '') {
            $conditions[] = 'category = :category';
            $parameters['category'] = $selectedCategory;
        }
        if ($inStockOnly) {
            $conditions[] = 'stock_quantity > 0';
        }
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY ' . $sortOptions[$sort];

        $productStatement = $pdo->prepare($sql);
        $productStatement->execute($parameters);
        $products = $productStatement->fetchAll();
    } catch (PDOException $exception) {
        error_log('CloudCart database error: ' . $exception->getMessage());
        $databaseError = true;
    }
}

if (isset($_SESSION['flash_notice'])) {
    $notice = (string) $_SESSION['flash_notice'];
    unset($_SESSION['flash_notice']);
    $showCartLink = !empty($_SESSION['flash_cart_added']);
    unset($_SESSION['flash_cart_added']);
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safeImageUrl(?string $url): ?string
{
    if (!$url) {
        return null;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : null;
}

$cartCount = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $quantity) {
        $cartCount += max(0, (int) $quantity);
    }
}
$isLoggedIn = !empty($_SESSION['user_id']);
$userName = (string) ($_SESSION['name'] ?? 'My Account');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#160b2e">
    <meta name="description" content="Discover console games for PlayStation, Xbox, Nintendo and more at GameGrid. Find your next adventure.">
    <title><?= $selectedCategory !== '' ? h($selectedCategory) . ' | ' : '' ?>GameGrid | Console games</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <div class="bg-violet-950 px-4 py-2 text-center text-xs font-bold tracking-[0.14em] text-violet-100">
        <span class="text-fuchsia-300">GAMEGRID</span><span class="mx-2 text-violet-700">/</span>PLAYSTATION · XBOX · NINTENDO · PC &amp; MORE
    </div>
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-4 py-4">
                <a href="index.php" class="inline-flex items-center gap-2.5 text-xl font-black tracking-tight text-slate-950 sm:text-2xl" aria-label="GameGrid home">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-700 text-white shadow-sm">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7.5 8.5 6 8a3.5 3.5 0 0 0-4.4 2.4l-.7 2.4A2.5 2.5 0 0 0 3.3 16h1.1a2 2 0 0 0 1.6-.8l1-1.2h6l1 1.2a2 2 0 0 0 1.6.8h1.1a2.5 2.5 0 0 0 2.4-3.2l-.7-2.4A3.5 3.5 0 0 0 14 8l-1.5.5h-5ZM5 10v3m-1.5-1.5h3m8-.5h.01M17 13h.01"/>
                        </svg>
                    </span>
                    <span>GAME<span class="text-violet-700">GRID</span></span>
                </a>

                <form action="index.php" method="get" role="search" class="order-3 flex w-full max-w-xl sm:order-none sm:flex-1 sm:px-8">
                    <?php if ($selectedCategory !== ''): ?>
                        <input type="hidden" name="category" value="<?= h($selectedCategory) ?>">
                    <?php endif; ?>
                    <?php if ($sort !== 'newest'): ?>
                        <input type="hidden" name="sort" value="<?= h($sort) ?>">
                    <?php endif; ?>
                    <?php if ($inStockOnly): ?>
                        <input type="hidden" name="in_stock" value="1">
                    <?php endif; ?>
                    <label for="search" class="sr-only">Search games by title, genre or platform</label>
                    <input
                        id="search"
                        name="q"
                        type="search"
                        value="<?= h($search) ?>"
                        placeholder="Search games, genres, platforms..."
                        class="min-w-0 flex-1 rounded-l-lg border border-slate-300 bg-white px-4 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                    >
                    <button type="submit" class="rounded-r-lg bg-violet-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-violet-800">
                        Search
                    </button>
                </form>

                <nav aria-label="Account and shopping" class="flex items-center gap-3 text-sm font-medium sm:gap-4">
                    <?php if ($isLoggedIn): ?>
                        <a href="wishlist.php" class="hidden text-slate-600 transition hover:text-indigo-700 sm:inline">Wishlist</a>
                        <a href="orders.php" class="hidden text-slate-600 transition hover:text-indigo-700 md:inline">Orders</a>
                        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                            <a href="admin.php" class="hidden font-semibold text-indigo-700 hover:text-indigo-900 md:inline">Admin</a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <a href="cart.php" class="relative rounded-lg p-1 text-slate-600 transition hover:text-indigo-700" aria-label="Cart, <?= h($cartCount) ?> items">
                        <svg class="inline h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3h2l2.2 11.2a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 2-1.6L22 7H6M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"/>
                        </svg>
                        <span class="absolute -right-2 -top-2 min-w-5 rounded-full bg-indigo-600 px-1 text-center text-xs leading-5 text-white"><?= h($cartCount) ?></span>
                    </a>
                    <?php if ($isLoggedIn): ?>
                        <span class="hidden text-slate-600 lg:inline">Hi, <?= h($userName) ?></span>
                        <form action="index.php" method="post" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="font-semibold text-indigo-700 hover:text-indigo-900">Logout</button>
                        </form>
                    <?php else: ?>
                        <a href="login.php" class="font-semibold text-indigo-700 hover:text-indigo-900">Sign In</a>
                        <a href="register.php" class="rounded-lg bg-violet-700 px-3 py-2 font-bold text-white shadow-sm transition hover:bg-violet-800">Create account</a>
                    <?php endif; ?>
                </nav>
            </div>

            <nav aria-label="Game platforms" class="flex gap-6 overflow-x-auto border-t border-slate-100 py-3 text-sm whitespace-nowrap">
                <a href="index.php" class="<?= $selectedCategory === '' ? 'font-semibold text-violet-700' : 'text-slate-600 hover:text-violet-700' ?>">All games</a>
                <?php foreach ($categories as $category): ?>
                    <?php
                    $categoryQuery = ['category' => (string) $category];
                    if ($search !== '') {
                        $categoryQuery['q'] = $search;
                    }
                    if ($sort !== 'newest') {
                        $categoryQuery['sort'] = $sort;
                    }
                    if ($inStockOnly) {
                        $categoryQuery['in_stock'] = '1';
                    }
                    ?>
                    <a
                        href="index.php?<?= h(http_build_query($categoryQuery)) ?>"
                        class="<?= $selectedCategory === $category ? 'font-semibold text-violet-700' : 'text-slate-600 hover:text-violet-700' ?>"
                    ><?= h($category) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <section class="relative isolate mb-10 overflow-hidden rounded-[2rem] bg-[#160b2e] px-6 py-10 text-white shadow-2xl shadow-violet-950/20 sm:px-10 sm:py-14 lg:px-14">
            <div class="absolute -right-24 -top-36 -z-10 h-[28rem] w-[28rem] rounded-full bg-fuchsia-600/30 blur-3xl"></div>
            <div class="absolute -bottom-40 left-1/3 -z-10 h-80 w-80 rounded-full bg-violet-500/30 blur-3xl"></div>
            <div class="grid items-center gap-10 lg:grid-cols-[1.05fr_0.95fr]">
                <div class="relative">
                    <p class="mb-5 inline-flex items-center gap-2 rounded-full border border-fuchsia-200/20 bg-white/10 px-3 py-1.5 text-xs font-bold uppercase tracking-[0.18em] text-fuchsia-200">
                        <span class="h-2 w-2 animate-pulse rounded-full bg-lime-300"></span>
                        Your next level starts here
                    </p>
                    <h1 class="max-w-2xl text-4xl font-black leading-[1.05] tracking-tight sm:text-5xl lg:text-6xl">Find your next<br><span class="text-lime-300">world to get lost in.</span></h1>
                    <p class="mt-5 max-w-xl text-base leading-7 text-violet-100/80 sm:text-lg">Shop games for PlayStation, Xbox, Nintendo and more. New adventures, favourite franchises, all in one place.</p>
                    <a href="#games" class="mt-7 inline-flex items-center gap-2 rounded-xl bg-lime-300 px-5 py-3 font-extrabold text-violet-950 shadow-lg shadow-lime-950/20 transition hover:bg-lime-200">
                        Browse all games <span aria-hidden="true">→</span>
                    </a>
                    <div class="mt-8 flex flex-wrap gap-2 text-xs font-bold uppercase tracking-wider text-violet-100/75">
                        <span class="rounded-full border border-white/15 bg-white/5 px-3 py-1.5">PlayStation</span>
                        <span class="rounded-full border border-white/15 bg-white/5 px-3 py-1.5">Xbox</span>
                        <span class="rounded-full border border-white/15 bg-white/5 px-3 py-1.5">Nintendo</span>
                        <span class="rounded-full border border-white/15 bg-white/5 px-3 py-1.5">PC</span>
                        <span class="rounded-full border border-white/15 bg-white/5 px-3 py-1.5">And more</span>
                    </div>
                </div>
                <div class="relative mx-auto flex h-64 w-full max-w-lg items-center justify-center sm:h-80" aria-hidden="true">
                    <div class="absolute left-[12%] top-[15%] h-52 w-36 -rotate-12 overflow-hidden rounded-2xl border border-white/20 bg-gradient-to-br from-orange-400 via-rose-600 to-violet-950 shadow-2xl shadow-black/40 sm:h-64 sm:w-44">
                        <div class="absolute inset-2 rounded-xl border border-white/25"></div>
                        <div class="absolute -left-8 top-10 h-32 w-32 rounded-full border-[18px] border-orange-200/30"></div>
                        <div class="absolute bottom-7 left-4 right-4">
                            <span class="text-[9px] font-black uppercase tracking-[0.25em] text-white/70">A new adventure</span>
                            <span class="mt-1 block text-2xl font-black leading-none text-white sm:text-3xl">PLAY<br>YOUR WAY</span>
                        </div>
                    </div>
                    <div class="absolute right-[10%] top-[8%] h-52 w-36 rotate-[10deg] overflow-hidden rounded-2xl border border-white/20 bg-gradient-to-br from-cyan-300 via-blue-700 to-violet-950 shadow-2xl shadow-black/40 sm:h-64 sm:w-44">
                        <div class="absolute -right-8 -top-8 h-40 w-40 rounded-full border-[22px] border-cyan-100/20"></div>
                        <div class="absolute left-5 top-12 h-20 w-20 rotate-45 border border-white/40 bg-white/10"></div>
                        <div class="absolute bottom-7 left-4 right-4">
                            <span class="text-[9px] font-black uppercase tracking-[0.25em] text-white/70">Discover something</span>
                            <span class="mt-1 block text-2xl font-black leading-none text-white sm:text-3xl">PRESS<br>START</span>
                        </div>
                    </div>
                    <div class="absolute bottom-0 left-1/2 z-10 flex h-16 w-32 -translate-x-1/2 items-center justify-center rounded-full border border-white/20 bg-slate-950/90 shadow-xl backdrop-blur">
                        <svg class="h-9 w-20 text-lime-300" viewBox="0 0 90 40" fill="none">
                            <path d="M18 10 10 12a9 9 0 0 0-6 7l-2 8a6 6 0 0 0 6 7h3a6 6 0 0 0 5-3l4-5h50l4 5a6 6 0 0 0 5 3h3a6 6 0 0 0 6-7l-2-8a9 9 0 0 0-6-7l-8-2H18Z" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round"/>
                            <path d="M22 17v12m-6-6h12m28-3h.01M67 25h.01" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <span class="absolute bottom-1 right-0 rounded-full bg-lime-300 px-3 py-1.5 text-[10px] font-black uppercase tracking-widest text-violet-950 shadow-lg sm:right-4">Game on</span>
                </div>
            </div>
        </section>

        <?php if ($notice !== ''): ?>
            <div role="<?= $noticeType === 'error' ? 'alert' : 'status' ?>" class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 <?= $noticeType === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
                <span><?= h($notice) ?></span>
                <?php if ($showCartLink): ?>
                    <a href="cart.php" class="font-semibold underline underline-offset-2 hover:no-underline">View cart &amp; checkout</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($categories): ?>
            <section aria-labelledby="platforms-heading" class="mb-10">
                <div class="mb-4 flex items-end justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-violet-700">Pick your platform</p>
                        <h2 id="platforms-heading" class="mt-1 text-2xl font-black tracking-tight text-slate-950">Browse by platform</h2>
                    </div>
                </div>
                <div class="flex gap-3 overflow-x-auto pb-2">
                    <?php foreach ($categories as $category): ?>
                        <?php
                        $platformQuery = ['category' => (string) $category];
                        if ($search !== '') {
                            $platformQuery['q'] = $search;
                        }
                        ?>
                        <a href="index.php?<?= h(http_build_query($platformQuery)) ?>" class="group flex min-w-48 items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-violet-300 hover:shadow-md">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-700 transition group-hover:bg-violet-700 group-hover:text-white" aria-hidden="true">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7.5 8.5 6 8a3.5 3.5 0 0 0-4.4 2.4l-.7 2.4A2.5 2.5 0 0 0 3.3 16h1.1a2 2 0 0 0 1.6-.8l1-1.2h6l1 1.2a2 2 0 0 0 1.6.8h1.1a2.5 2.5 0 0 0 2.4-3.2l-.7-2.4A3.5 3.5 0 0 0 14 8l-1.5.5h-5ZM5 10v3m-1.5-1.5h3m8-.5h.01M17 13h.01"/></svg>
                            </span>
                            <span class="font-bold text-slate-900"><?= h($category) ?></span>
                            <span class="ml-auto text-slate-300 transition group-hover:translate-x-1 group-hover:text-violet-700" aria-hidden="true">→</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section id="games" class="scroll-mt-6">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="mb-1 text-xs font-bold uppercase tracking-[0.18em] text-violet-700">Curated for your next play session</p>
                <h2 class="text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">
                    <?= $selectedCategory !== '' ? h($selectedCategory) : 'Explore all games' ?>
                </h2>
                <?php if ($search !== ''): ?>
                    <p class="mt-1 text-sm text-slate-500">Results for “<?= h($search) ?>”</p>
                <?php endif; ?>
            </div>
            <?php if (!$databaseError): ?>
                <p class="text-sm font-medium text-slate-500"><?= h(count($products)) ?> <?= count($products) === 1 ? 'game' : 'games' ?></p>
            <?php endif; ?>
        </div>

        <form action="index.php#games" method="get" class="mb-6 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= h($search) ?>"><?php endif; ?>
            <?php if ($selectedCategory !== ''): ?><input type="hidden" name="category" value="<?= h($selectedCategory) ?>"><?php endif; ?>
            <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
                <label for="sort" class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                    Sort by
                    <select id="sort" name="sort" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-100">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Recently added</option>
                        <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: low to high</option>
                        <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price: high to low</option>
                        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name: A to Z</option>
                    </select>
                </label>
                <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-600">
                    <input type="checkbox" name="in_stock" value="1" <?= $inStockOnly ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500">
                    Available now
                </label>
            </div>
            <button type="submit" class="rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-violet-800">Apply filters</button>
        </form>

        <?php if ($databaseError): ?>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-amber-900">
                <h3 class="font-semibold">The game library is temporarily unavailable.</h3>
                <p class="mt-1 text-sm">Please try again later. If you manage this site, check the database configuration and server logs.</p>
            </div>
        <?php elseif (!$products): ?>
            <div class="rounded-2xl border border-slate-200 bg-white p-10 text-center shadow-sm">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-violet-100 text-2xl text-violet-700" aria-hidden="true">⌕</span>
                <h3 class="mt-4 text-xl font-bold text-slate-950"><?= $search !== '' || $selectedCategory !== '' ? 'No games found this time' : 'The game shelf is empty' ?></h3>
                <p class="mt-2 text-sm text-slate-500"><?= $search !== '' || $selectedCategory !== '' ? 'Try another title or platform, or browse the full library.' : 'Games added by the store will appear here. Check back soon.' ?></p>
                <a href="index.php" class="mt-5 inline-flex rounded-xl bg-violet-700 px-5 py-3 font-bold text-white transition hover:bg-violet-800">Browse all games</a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <?php foreach ($products as $product): ?>
                    <?php
                    $imageUrl = safeImageUrl(isset($product['image_url']) ? (string) $product['image_url'] : null);
                    $inStock = (int) $product['stock_quantity'] > 0;
                    ?>
                    <article class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:border-violet-300 hover:shadow-xl">
                        <div class="relative aspect-video overflow-hidden bg-slate-50 p-3">
                            <?php if ($imageUrl !== null): ?>
                                <img src="<?= h($imageUrl) ?>" alt="Artwork for <?= h($product['name']) ?>" loading="lazy" class="h-full w-full object-contain transition duration-300 group-hover:scale-[1.03]">
                            <?php else: ?>
                                <div class="flex h-full flex-col items-center justify-center rounded-xl bg-gradient-to-br from-indigo-50 to-violet-100 p-5 text-center text-indigo-900">
                                    <svg class="h-10 w-10 text-violet-700" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M17 12h14a8 8 0 0 1 7.7 5.8l3 10.5A5 5 0 0 1 37 34h-1.6a5 5 0 0 1-3.9-1.9L29 29H19l-2.5 3.1a5 5 0 0 1-3.9 1.9H11a5 5 0 0 1-4.8-6.3l3-10.5A8 8 0 0 1 17 12Z"/><path d="M16 19v8m-4-4h8m12-2h.01M36 25h.01"/></svg>
                                    <span class="mt-3 text-xs font-bold uppercase tracking-wider text-violet-700"><?= h($product['category'] ?: 'Game') ?></span>
                                    <h3 class="mt-1 line-clamp-2 font-bold"><?= h($product['name']) ?></h3>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($product['category'])): ?>
                                <span class="absolute left-3 top-3 rounded-full border border-slate-200 bg-white/95 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-slate-700 shadow-sm"><?= h($product['category']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="p-5">
                            <div class="flex items-start justify-between gap-3">
                                <h3 class="line-clamp-2 min-h-12 font-bold leading-6 text-slate-950"><?= h($product['name']) ?></h3>
                                <span class="shrink-0 rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wide <?= $inStock ? 'bg-lime-100 text-lime-800' : 'bg-slate-100 text-slate-500' ?>">
                                    <?= $inStock ? 'In stock' : 'Sold out' ?>
                                </span>
                            </div>
                            <?php if (trim((string) ($product['description'] ?? '')) !== ''): ?>
                                <p class="mt-2 line-clamp-2 min-h-10 text-sm leading-5 text-slate-500"><?= h($product['description']) ?></p>
                            <?php else: ?>
                                <p class="mt-2 min-h-10 text-sm text-slate-400">Ready for your next play session.</p>
                            <?php endif; ?>
                            <p class="mt-3 text-2xl font-black tracking-tight text-slate-950">$<?= h(number_format((float) $product['price'], 2)) ?></p>
                            <div class="mt-5 flex gap-2">
                                <form action="index.php" method="post" class="flex-1">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="add_to_cart">
                                    <input type="hidden" name="product_id" value="<?= h($product['id']) ?>">
                                    <button type="submit" <?= !$inStock ? 'disabled' : '' ?> class="w-full rounded-xl bg-violet-700 px-3 py-2.5 text-sm font-bold text-white transition hover:bg-violet-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                                        <?= $inStock ? 'Add to cart' : 'Sold out' ?>
                                    </button>
                                </form>
                                <form action="wishlist.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?= h($product['id']) ?>">
                                    <button type="submit" class="rounded-xl border border-slate-200 p-2.5 text-slate-600 transition hover:border-fuchsia-300 hover:bg-fuchsia-50 hover:text-fuchsia-600" aria-label="Add <?= h($product['name']) ?> to wishlist">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.5 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"/>
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </section>
    </main>

    <footer class="mt-16 border-t border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-[1.4fr_0.8fr_0.8fr]">
                <div>
                    <a href="index.php" class="text-xl font-black tracking-tight text-slate-950">GAME<span class="text-violet-700">GRID</span></a>
                    <p class="mt-3 max-w-sm text-sm leading-6 text-slate-500">Find your next game across the platforms you play. Browse the collection, save favourites, and build your next adventure.</p>
                </div>
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Explore</h2>
                    <ul class="mt-3 space-y-2 text-sm text-slate-500">
                        <li><a href="index.php" class="hover:text-violet-700">Shop all games</a></li>
                        <li><a href="wishlist.php" class="hover:text-violet-700">Your wishlist</a></li>
                        <li><a href="cart.php" class="hover:text-violet-700">Shopping cart</a></li>
                    </ul>
                </div>
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Player account</h2>
                    <ul class="mt-3 space-y-2 text-sm text-slate-500">
                        <?php if ($isLoggedIn): ?>
                            <li><a href="orders.php" class="hover:text-violet-700">Order history</a></li>
                        <?php else: ?>
                            <li><a href="login.php" class="hover:text-violet-700">Sign in</a></li>
                            <li><a href="register.php" class="hover:text-violet-700">Create a player account</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <div class="mt-8 flex flex-col gap-2 border-t border-slate-100 pt-5 text-xs text-slate-400 sm:flex-row sm:items-center sm:justify-between">
                <p>&copy; <?= h(date('Y')) ?> GameGrid. All rights reserved.</p>
                <p>GameGrid demo · Checkout and payment are simulated.</p>
            </div>
            <p class="mt-3 text-xs text-slate-400">Game discovery powered by <a href="https://rawg.io/" target="_blank" rel="noopener noreferrer" class="font-semibold text-violet-700 hover:text-violet-900">RAWG</a>.</p>
        </div>
    </footer>
</body>
</html>
