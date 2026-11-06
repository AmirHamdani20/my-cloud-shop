<?php
declare(strict_types=1);

session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$notice = '';
$noticeType = 'success';
$products = [];
$categories = [];
$databaseError = false;

$search = trim((string) ($_GET['q'] ?? ''));
$selectedCategory = trim((string) ($_GET['category'] ?? ''));
$search = mb_substr($search, 0, 100);
$selectedCategory = mb_substr($selectedCategory, 0, 50);

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
        unset($_SESSION['user_id'], $_SESSION['name']);
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

            if (!in_array($action, ['add_to_cart', 'add_to_wishlist'], true) || !$productId || $productId < 1) {
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
                    if ((int) $requestedProduct['stock_quantity'] < 1) {
                        $notice = 'This product is out of stock.';
                        $noticeType = 'error';
                    } else {
                        if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
                            $_SESSION['cart'] = [];
                        }
                        $_SESSION['cart'][$productId] = (int) ($_SESSION['cart'][$productId] ?? 0) + 1;
                        $_SESSION['flash_notice'] = 'Product added to your cart.';
                        header('Location: index.php');
                        exit;
                    }
                } elseif (empty($_SESSION['user_id'])) {
                    $notice = 'Please sign in before adding items to your wishlist.';
                    $noticeType = 'error';
                } else {
                    $wishlistCheck = $pdo->prepare(
                        'SELECT id FROM dbo.wishlists WHERE user_id = :user_id AND product_id = :product_id'
                    );
                    $wishlistCheck->execute([
                        'user_id' => (int) $_SESSION['user_id'],
                        'product_id' => $productId,
                    ]);

                    if (!$wishlistCheck->fetch()) {
                        $wishlistInsert = $pdo->prepare(
                            'INSERT INTO dbo.wishlists (user_id, product_id) VALUES (:user_id, :product_id)'
                        );
                        $wishlistInsert->execute([
                            'user_id' => (int) $_SESSION['user_id'],
                            'product_id' => $productId,
                        ]);
                    }

                    $_SESSION['flash_notice'] = 'Product added to your wishlist.';
                    header('Location: index.php');
                    exit;
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
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY created_at DESC, id DESC';

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
    <meta name="description" content="Shop quality products at CloudCart.">
    <title>CloudCart | Shop online</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-4 py-4">
                <a href="index.php" class="text-2xl font-extrabold tracking-tight text-indigo-700">CloudCart</a>

                <form action="index.php" method="get" role="search" class="order-3 flex w-full max-w-xl sm:order-none sm:flex-1 sm:px-8">
                    <?php if ($selectedCategory !== ''): ?>
                        <input type="hidden" name="category" value="<?= h($selectedCategory) ?>">
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

                <nav aria-label="Account and shopping" class="flex items-center gap-4 text-sm font-medium">
                    <a href="wishlist.php" class="hidden text-slate-600 hover:text-indigo-700 sm:inline">Wishlist</a>
                    <a href="cart.php" class="relative text-slate-600 hover:text-indigo-700" aria-label="Cart, <?= h($cartCount) ?> items">
                        <svg class="inline h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3h2l2.2 11.2a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 2-1.6L22 7H6M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"/>
                        </svg>
                        <span class="absolute -right-2 -top-2 min-w-5 rounded-full bg-indigo-600 px-1 text-center text-xs leading-5 text-white"><?= h($cartCount) ?></span>
                    </a>
                    <?php if ($isLoggedIn): ?>
                        <span class="hidden text-slate-600 md:inline">Hi, <?= h($userName) ?></span>
                        <form action="index.php" method="post" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="font-semibold text-indigo-700 hover:text-indigo-900">Logout</button>
                        </form>
                    <?php else: ?>
                        <a href="login.php" class="font-semibold text-indigo-700 hover:text-indigo-900">Sign In</a>
                        <a href="register.php" class="rounded-lg bg-indigo-600 px-3 py-2 font-semibold text-white hover:bg-indigo-700">Register</a>
                    <?php endif; ?>
                </nav>
            </div>

            <nav aria-label="Product categories" class="flex gap-6 overflow-x-auto border-t border-slate-100 py-3 text-sm whitespace-nowrap">
                <a href="index.php" class="<?= $selectedCategory === '' ? 'font-semibold text-indigo-700' : 'text-slate-600 hover:text-indigo-700' ?>">All products</a>
                <?php foreach ($categories as $category): ?>
                    <a
                        href="index.php?category=<?= rawurlencode((string) $category) ?>"
                        class="<?= $selectedCategory === $category ? 'font-semibold text-indigo-700' : 'text-slate-600 hover:text-indigo-700' ?>"
                    ><?= h($category) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <section class="mb-8 rounded-2xl bg-gradient-to-r from-indigo-700 to-violet-600 px-6 py-10 text-white sm:px-10">
            <p class="mb-2 text-sm font-semibold uppercase tracking-widest text-indigo-100">Welcome to CloudCart</p>
            <h1 class="text-3xl font-extrabold tracking-tight sm:text-4xl">Find something you’ll love.</h1>
            <p class="mt-3 max-w-2xl text-indigo-100">Explore our collection and discover great products for every day.</p>
        </section>

        <?php if ($notice !== ''): ?>
            <div role="status" class="mb-6 rounded-lg border px-4 py-3 <?= $noticeType === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
                <?= h($notice) ?>
            </div>
        <?php endif; ?>

        <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">
                    <?= $selectedCategory !== '' ? h($selectedCategory) : 'Shop all products' ?>
                </h2>
                <?php if ($search !== ''): ?>
                    <p class="mt-1 text-sm text-slate-500">Search results for “<?= h($search) ?>”</p>
                <?php endif; ?>
            </div>
            <?php if (!$databaseError): ?>
                <p class="text-sm text-slate-500"><?= h(count($products)) ?> products</p>
            <?php endif; ?>
        </div>

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
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <?php foreach ($products as $product): ?>
                    <?php
                    $imageUrl = safeImageUrl(isset($product['image_url']) ? (string) $product['image_url'] : null);
                    $inStock = (int) $product['stock_quantity'] > 0;
                    ?>
                    <article class="group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg">
                        <div class="relative aspect-[4/3] bg-slate-100">
                            <?php if ($imageUrl !== null): ?>
                                <img src="<?= h($imageUrl) ?>" alt="<?= h($product['name']) ?>" loading="lazy" class="h-full w-full object-cover">
                            <?php else: ?>
                                <div class="flex h-full items-center justify-center text-sm text-slate-400">Image unavailable</div>
                            <?php endif; ?>
                            <?php if (!empty($product['category'])): ?>
                                <span class="absolute left-3 top-3 rounded-full bg-white/95 px-3 py-1 text-xs font-semibold text-slate-700 shadow-sm"><?= h($product['category']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="p-5">
                            <div class="flex items-start justify-between gap-3">
                                <h3 class="line-clamp-2 min-h-12 font-semibold text-slate-900"><?= h($product['name']) ?></h3>
                                <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold <?= $inStock ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' ?>">
                                    <?= $inStock ? 'In Stock' : 'Out of Stock' ?>
                                </span>
                            </div>
                            <p class="mt-3 text-xl font-bold text-indigo-700">$<?= h(number_format((float) $product['price'], 2)) ?></p>
                            <div class="mt-5 flex gap-2">
                                <form action="index.php" method="post" class="flex-1">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="add_to_cart">
                                    <input type="hidden" name="product_id" value="<?= h($product['id']) ?>">
                                    <button type="submit" <?= !$inStock ? 'disabled' : '' ?> class="w-full rounded-lg bg-indigo-600 px-3 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                                        Add to Cart
                                    </button>
                                </form>
                                <form action="index.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="add_to_wishlist">
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
    </main>

    <footer class="mt-16 border-t border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-6 text-center text-sm text-slate-500 sm:px-6 lg:px-8">
            &copy; <?= h(date('Y')) ?> CloudCart. All rights reserved.
        </div>
    </footer>
</body>
</html>
