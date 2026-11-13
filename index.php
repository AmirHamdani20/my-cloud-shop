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
                $notice = 'The requested product action is invalid.';
                $noticeType = 'error';
            } else {
                $productLookup = $pdo->prepare('SELECT id, stock_quantity FROM dbo.products WHERE id = :id');
                $productLookup->execute(['id' => $productId]);
                $requestedProduct = $productLookup->fetch();

                if (!$requestedProduct) {
                    http_response_code(404);
                    $notice = 'That product could not be found.';
                    $noticeType = 'error';
                } elseif ($action === 'add_to_cart') {
                    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
                        $_SESSION['cart'] = [];
                    }
                    $currentQuantity = max(0, (int) ($_SESSION['cart'][$productId] ?? 0));
                    if ($currentQuantity >= (int) $requestedProduct['stock_quantity']) {
                        $notice = (int) $requestedProduct['stock_quantity'] < 1
                            ? 'This product is out of stock.'
                            : 'You already have all available stock of this product in your cart.';
                        $noticeType = 'error';
                    } else {
                        $_SESSION['cart'][$productId] = $currentQuantity + 1;
                        $_SESSION['flash_notice'] = 'Product added to your cart.';
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
        $categories = $categoryStatement->fetchAll(PDO::FETCH_COLUMN);

        $sql = 'SELECT id, name, price, stock_quantity, category, image_url
                FROM dbo.products';
        $conditions = [];
        $parameters = [];

        if ($search !== '') {
            $conditions[] = 'name LIKE :search';
            $parameters['search'] = '%' . $search . '%';
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
    <meta name="description" content="Discover tech and everyday essentials at CloudCart. Find your next favorite and shop the collection.">
    <title><?= $selectedCategory !== '' ? h($selectedCategory) . ' | ' : '' ?>CloudCart | Shop tech</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <div class="bg-slate-950 px-4 py-2 text-center text-xs font-medium tracking-wide text-slate-200">
        A better way to shop for tech and everyday essentials
    </div>
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-4 py-4">
                <a href="index.php" class="inline-flex items-center gap-2.5 text-xl font-black tracking-tight text-slate-950 sm:text-2xl" aria-label="CloudCart home">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-sm">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 2-1.6L22 8H6M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"/>
                        </svg>
                    </span>
                    <span>Cloud<span class="text-indigo-600">Cart</span></span>
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
                    <label for="search" class="sr-only">Search products</label>
                    <input
                        id="search"
                        name="q"
                        type="search"
                        value="<?= h($search) ?>"
                        placeholder="Search products..."
                        class="min-w-0 flex-1 rounded-l-lg border border-slate-300 bg-white px-4 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                    >
                    <button type="submit" class="rounded-r-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">
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
                        <a href="register.php" class="rounded-lg bg-indigo-600 px-3 py-2 font-bold text-white shadow-sm transition hover:bg-indigo-700">Join CloudCart</a>
                    <?php endif; ?>
                </nav>
            </div>

            <nav aria-label="Product categories" class="flex gap-6 overflow-x-auto border-t border-slate-100 py-3 text-sm whitespace-nowrap">
                <a href="index.php" class="<?= $selectedCategory === '' ? 'font-semibold text-indigo-700' : 'text-slate-600 hover:text-indigo-700' ?>">All products</a>
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
                        class="<?= $selectedCategory === $category ? 'font-semibold text-indigo-700' : 'text-slate-600 hover:text-indigo-700' ?>"
                    ><?= h($category) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <section class="relative isolate mb-10 overflow-hidden rounded-3xl bg-slate-950 px-6 py-10 text-white shadow-xl sm:px-10 sm:py-14 lg:px-14">
            <div class="absolute -right-20 -top-32 -z-10 h-96 w-96 rounded-full bg-indigo-600/40 blur-3xl"></div>
            <div class="absolute -bottom-40 right-1/4 -z-10 h-80 w-80 rounded-full bg-cyan-400/20 blur-3xl"></div>
            <div class="grid items-center gap-8 lg:grid-cols-[1.2fr_0.8fr]">
                <div>
                    <p class="mb-4 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-indigo-100">
                        <span class="h-2 w-2 rounded-full bg-cyan-300"></span>
                        Welcome to CloudCart
                    </p>
                    <h1 class="max-w-2xl text-4xl font-black leading-tight tracking-tight sm:text-5xl lg:text-6xl">Good tech.<br><span class="text-cyan-300">Great everyday.</span></h1>
                    <p class="mt-5 max-w-xl text-base leading-7 text-slate-300 sm:text-lg">Discover useful tech and everyday essentials, all in one easy-to-browse place.</p>
                    <a href="#products" class="mt-7 inline-flex items-center gap-2 rounded-xl bg-cyan-300 px-5 py-3 font-bold text-slate-950 transition hover:bg-cyan-200">
                        Explore the collection
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.69L10.22 5.03a.75.75 0 1 1 1.06-1.06l5.5 5.5a.75.75 0 0 1 0 1.06l-5.5 5.5a.75.75 0 1 1-1.06-1.06l4.22-4.22H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd"/></svg>
                    </a>
                </div>
                <div class="hidden lg:flex lg:justify-center" aria-hidden="true">
                    <div class="relative flex h-64 w-64 items-center justify-center rounded-[2.5rem] border border-white/15 bg-white/[0.07] shadow-2xl backdrop-blur-sm">
                        <div class="absolute -right-5 top-8 h-20 w-20 rounded-3xl border border-cyan-200/30 bg-cyan-300/15 rotate-12"></div>
                        <div class="absolute -bottom-4 left-2 h-16 w-16 rounded-full border border-indigo-200/30 bg-indigo-400/20"></div>
                        <svg class="relative h-36 w-36 text-cyan-200 drop-shadow-[0_0_25px_rgba(103,232,249,0.3)]" viewBox="0 0 120 120" fill="none">
                            <rect x="27" y="12" width="66" height="96" rx="13" stroke="currentColor" stroke-width="3"/>
                            <rect x="34" y="23" width="52" height="66" rx="5" fill="currentColor" fill-opacity=".12"/>
                            <path d="M52 99h16" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M46 54h28M60 40v28" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                        <span class="absolute bottom-5 rounded-full border border-white/10 bg-slate-900/80 px-3 py-1 text-xs font-semibold tracking-wide text-slate-200">Find your next favorite</span>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($notice !== ''): ?>
            <div role="status" class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border px-4 py-3 <?= $noticeType === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
                <span><?= h($notice) ?></span>
                <?php if ($showCartLink): ?>
                    <a href="cart.php" class="font-semibold underline underline-offset-2 hover:no-underline">View cart &amp; checkout</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <section id="products" class="scroll-mt-6">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="mb-1 text-xs font-bold uppercase tracking-[0.18em] text-indigo-600">The CloudCart edit</p>
                <h2 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                    <?= $selectedCategory !== '' ? h($selectedCategory) : 'Explore the collection' ?>
                </h2>
                <?php if ($search !== ''): ?>
                    <p class="mt-1 text-sm text-slate-500">Search results for “<?= h($search) ?>”</p>
                <?php endif; ?>
            </div>
            <?php if (!$databaseError): ?>
                <p class="text-sm text-slate-500"><?= h(count($products)) ?> <?= count($products) === 1 ? 'product' : 'products' ?></p>
            <?php endif; ?>
        </div>

        <form action="index.php#products" method="get" class="mb-6 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= h($search) ?>"><?php endif; ?>
            <?php if ($selectedCategory !== ''): ?><input type="hidden" name="category" value="<?= h($selectedCategory) ?>"><?php endif; ?>
            <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
                <label for="sort" class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                    Sort by
                    <select id="sort" name="sort" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                        <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: low to high</option>
                        <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price: high to low</option>
                        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name: A to Z</option>
                    </select>
                </label>
                <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-600">
                    <input type="checkbox" name="in_stock" value="1" <?= $inStockOnly ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    In stock only
                </label>
            </div>
            <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700">Apply filters</button>
        </form>

        <?php if ($databaseError): ?>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-amber-900">
                <h3 class="font-semibold">The store is temporarily unavailable.</h3>
                <p class="mt-1 text-sm">Please try again later. If you manage this site, check the database configuration and server logs.</p>
            </div>
        <?php elseif (!$products): ?>
            <div class="rounded-xl border border-slate-200 bg-white p-10 text-center">
                <h3 class="text-lg font-semibold text-slate-900">No products found</h3>
                <p class="mt-2 text-sm text-slate-500">Try another search or browse all products.</p>
                <a href="index.php" class="mt-4 inline-block font-semibold text-indigo-700 hover:text-indigo-900">View all products</a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <?php foreach ($products as $product): ?>
                    <?php
                    $imageUrl = safeImageUrl(isset($product['image_url']) ? (string) $product['image_url'] : null);
                    $inStock = (int) $product['stock_quantity'] > 0;
                    ?>
                    <article class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:border-indigo-200 hover:shadow-xl">
                        <div class="relative aspect-[4/3] overflow-hidden bg-gradient-to-br from-slate-100 to-slate-50">
                            <?php if ($imageUrl !== null): ?>
                                <img src="<?= h($imageUrl) ?>" alt="<?= h($product['name']) ?>" loading="lazy" class="h-full w-full object-contain p-5 transition duration-300 group-hover:scale-105">
                            <?php else: ?>
                                <div class="flex h-full items-center justify-center text-sm text-slate-400">Image unavailable</div>
                            <?php endif; ?>
                            <?php if (!empty($product['category'])): ?>
                                <span class="absolute left-3 top-3 rounded-full bg-white/95 px-3 py-1 text-xs font-semibold text-slate-700 shadow-sm"><?= h($product['category']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="p-5">
                            <div class="flex items-start justify-between gap-3">
                                <h3 class="line-clamp-2 min-h-12 font-semibold leading-6 text-slate-900"><?= h($product['name']) ?></h3>
                                <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold <?= $inStock ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>">
                                    <?= $inStock ? 'AVAILABLE' : 'SOLD OUT' ?>
                                </span>
                            </div>
                            <p class="mt-3 text-2xl font-extrabold tracking-tight text-slate-950">$<?= h(number_format((float) $product['price'], 2)) ?></p>
                            <div class="mt-5 flex gap-2">
                                <form action="index.php" method="post" class="flex-1">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="add_to_cart">
                                    <input type="hidden" name="product_id" value="<?= h($product['id']) ?>">
                                    <button type="submit" <?= !$inStock ? 'disabled' : '' ?> class="w-full rounded-lg bg-indigo-600 px-3 py-2.5 text-sm font-bold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                                        <?= $inStock ? 'Add to basket' : 'Unavailable' ?>
                                    </button>
                                </form>
                                <form action="wishlist.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?= h($product['id']) ?>">
                                    <button type="submit" class="rounded-lg border border-slate-300 p-2.5 text-slate-600 hover:border-rose-300 hover:text-rose-600" aria-label="Add <?= h($product['name']) ?> to wishlist">
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
                    <a href="index.php" class="text-xl font-black tracking-tight text-slate-950">Cloud<span class="text-indigo-600">Cart</span></a>
                    <p class="mt-3 max-w-sm text-sm leading-6 text-slate-500">A considered collection of tech and everyday essentials, with a simpler way to shop.</p>
                </div>
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Explore</h2>
                    <ul class="mt-3 space-y-2 text-sm text-slate-500">
                        <li><a href="index.php" class="hover:text-indigo-700">Shop all products</a></li>
                        <li><a href="wishlist.php" class="hover:text-indigo-700">Your wishlist</a></li>
                        <li><a href="cart.php" class="hover:text-indigo-700">Shopping cart</a></li>
                    </ul>
                </div>
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Your account</h2>
                    <ul class="mt-3 space-y-2 text-sm text-slate-500">
                        <?php if ($isLoggedIn): ?>
                            <li><a href="orders.php" class="hover:text-indigo-700">Order history</a></li>
                        <?php else: ?>
                            <li><a href="login.php" class="hover:text-indigo-700">Sign in</a></li>
                            <li><a href="register.php" class="hover:text-indigo-700">Create an account</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <div class="mt-8 flex flex-col gap-2 border-t border-slate-100 pt-5 text-xs text-slate-400 sm:flex-row sm:items-center sm:justify-between">
                <p>&copy; <?= h(date('Y')) ?> CloudCart. All rights reserved.</p>
                <p>Demo store · Checkout and payment are simulated.</p>
            </div>
        </div>
    </footer>
</body>
</html>
