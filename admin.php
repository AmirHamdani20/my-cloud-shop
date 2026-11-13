<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
$platformOptions = require __DIR__ . '/game-platforms.php';

if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['user_id'])) {
    http_response_code(403);
    $pageTitle = 'Access denied';
    require_once __DIR__ . '/header.php';
    ?>
    <section class="rounded-xl border border-red-200 bg-red-50 p-6 text-red-900">
        <h1 class="text-xl font-bold">Admin access required</h1>
        <p class="mt-2">Sign in with an administrator account to access this dashboard.</p>
        <?php if (empty($_SESSION['user_id'])): ?>
            <a href="login.php?redirect=admin.php" class="mt-4 inline-block font-semibold underline">Sign in</a>
        <?php endif; ?>
    </section>
    <?php
    require_once __DIR__ . '/footer.php';
    exit;
}

$notice = '';
$noticeType = 'success';
$orders = [];
$databaseError = false;
$allowedStatuses = ['Pending', 'Shipped', 'Delivered'];

try {
    $pdo = cloudcartPdo();
} catch (Throwable $exception) {
    error_log('CloudCart admin database connection error: ' . $exception->getMessage());
    $pdo = null;
    $databaseError = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        $notice = 'Your request could not be verified. Please refresh the page and try again.';
        $noticeType = 'error';
    } elseif ($databaseError || !$pdo) {
        $notice = 'The admin dashboard is temporarily unavailable.';
        $noticeType = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'update_order_status') {
                $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
                $status = (string) ($_POST['order_status'] ?? '');
                if (!$orderId || $orderId < 1 || !in_array($status, $allowedStatuses, true)) {
                    throw new InvalidArgumentException('Choose a valid order and delivery status.');
                }

                $update = $pdo->prepare(
                    'UPDATE dbo.orders SET order_status = :status WHERE id = :id'
                );
                $update->execute(['status' => $status, 'id' => $orderId]);
                if ($update->rowCount() !== 1) {
                    throw new InvalidArgumentException('The order was not found or its status is already set.');
                }
                $_SESSION['flash_notice'] = 'Order status updated.';
                header('Location: admin.php');
                exit;
            }

            if ($action === 'add_product') {
                $name = trim((string) ($_POST['name'] ?? ''));
                $description = trim((string) ($_POST['description'] ?? ''));
                $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
                $stock = filter_var($_POST['stock_quantity'] ?? null, FILTER_VALIDATE_INT);
                $category = trim((string) ($_POST['category'] ?? ''));
                $imageUrl = trim((string) ($_POST['image_url'] ?? ''));

                if ($name === '' || mb_strlen($name) > 150) {
                    throw new InvalidArgumentException('Game title is required and must be 150 characters or fewer.');
                }
                if (mb_strlen($description) > 500) {
                    throw new InvalidArgumentException('Game description must be 500 characters or fewer.');
                }
                if ($price === false || $price < 0 || $price > 99999999.99) {
                    throw new InvalidArgumentException('Enter a valid game price.');
                }
                if ($stock === false || $stock < 0) {
                    throw new InvalidArgumentException('Stock quantity must be zero or greater.');
                }
                if (mb_strlen($category) > 50) {
                    throw new InvalidArgumentException('Platform must be 50 characters or fewer.');
                }
                if (!in_array($category, $platformOptions, true)) {
                    throw new InvalidArgumentException('Choose a platform for this game.');
                }
                if ($imageUrl !== '') {
                    $scheme = strtolower((string) parse_url($imageUrl, PHP_URL_SCHEME));
                    if (mb_strlen($imageUrl) > 500 || !filter_var($imageUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                        throw new InvalidArgumentException('Image URL must be a valid HTTP or HTTPS URL of at most 500 characters.');
                    }
                }

                $insert = $pdo->prepare(
                    'INSERT INTO dbo.products
                        (name, description, price, stock_quantity, category, image_url, created_at)
                     VALUES
                        (:name, :description, :price, :stock_quantity, :category, :image_url, SYSUTCDATETIME())'
                );
                $insert->execute([
                    'name' => $name,
                    'description' => $description,
                    'price' => number_format((float) $price, 2, '.', ''),
                    'stock_quantity' => $stock,
                    'category' => $category === '' ? null : $category,
                    'image_url' => $imageUrl === '' ? null : $imageUrl,
                ]);
                $_SESSION['flash_notice'] = 'Game added to the library.';
                header('Location: admin.php');
                exit;
            }

            throw new InvalidArgumentException('The requested admin action is invalid.');
        } catch (InvalidArgumentException $exception) {
            $notice = $exception->getMessage();
            $noticeType = 'error';
        } catch (Throwable $exception) {
            error_log('CloudCart admin action error: ' . $exception->getMessage());
            http_response_code(500);
            $notice = 'The action could not be completed. Check the server logs and try again.';
            $noticeType = 'error';
        }
    }
}

if (!$databaseError && $pdo) {
    try {
        $ordersQuery = $pdo->query(
            'SELECT o.id, o.user_id, u.name AS customer_name, o.total_amount,
                    o.order_status, o.payment_status, o.created_at
             FROM dbo.orders AS o
             LEFT JOIN dbo.shopusers AS u ON u.id = o.user_id
             ORDER BY o.created_at DESC, o.id DESC'
        );
        $orders = $ordersQuery->fetchAll();
    } catch (Throwable $exception) {
        error_log('CloudCart admin orders query error: ' . $exception->getMessage());
        $databaseError = true;
    }
}

$pageTitle = 'Admin Dashboard';
$pendingOrderCount = count(array_filter(
    $orders,
    static fn (array $order): bool => in_array(
        strtolower((string) $order['order_status']),
        ['pending', 'processing'],
        true
    )
));
$orderValue = array_sum(array_map(
    static fn (array $order): float => (float) $order['total_amount'],
    $orders
));
require_once __DIR__ . '/header.php';
?>
<div class="relative mb-8 overflow-hidden rounded-3xl bg-slate-950 px-6 py-8 text-white shadow-lg sm:px-9 sm:py-10">
    <div class="absolute -right-10 -top-20 h-56 w-56 rounded-full bg-indigo-500/30 blur-3xl"></div>
    <div class="relative">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-300">GameGrid management</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">Admin dashboard</h1>
        <p class="mt-2 max-w-xl text-sm leading-6 text-slate-300">A quick view of orders and a simple way to build your game library.</p>
    </div>
</div>

<?php if ($databaseError): ?>
    <div class="mb-8 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900">The dashboard data could not be loaded. Please try again later.</div>
<?php endif; ?>

<?php if (!$databaseError): ?>
    <section aria-label="Order overview" class="mb-8 grid gap-4 sm:grid-cols-3">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-medium text-slate-500">All orders</p>
            <p class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950"><?= e(count($orders)) ?></p>
            <p class="mt-1 text-xs text-slate-400">Orders placed in the store</p>
        </article>
        <article class="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-5 shadow-sm">
            <p class="text-sm font-medium text-indigo-700">In progress</p>
            <p class="mt-2 text-3xl font-extrabold tracking-tight text-indigo-950"><?= e($pendingOrderCount) ?></p>
            <p class="mt-1 text-xs text-indigo-600/70">Pending or processing</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Total order value</p>
            <p class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950">$<?= e(number_format($orderValue, 2)) ?></p>
            <p class="mt-1 text-xs text-slate-400">Across all orders</p>
        </article>
    </section>
<?php endif; ?>

<section class="mb-10 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-100 bg-gradient-to-r from-white to-indigo-50/60 px-6 py-5">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-indigo-600">Game library</p>
        <h2 class="mt-1 text-xl font-bold text-slate-950">Add a game</h2>
    </div>
    <div class="p-6">
    <div class="mb-7 rounded-2xl border border-violet-100 bg-violet-50/60 p-5">
        <div class="flex flex-col gap-3 sm:flex-row">
            <div class="flex-1">
                <label for="rawg-search" class="mb-1.5 block text-sm font-bold text-slate-800">Find a game with RAWG</label>
                <input id="rawg-search" type="search" maxlength="80" placeholder="Search popular games by title..." class="w-full rounded-xl border border-violet-200 bg-white px-3.5 py-3 outline-none transition focus:border-violet-500 focus:ring-4 focus:ring-violet-100">
            </div>
            <button id="rawg-search-button" type="button" class="self-end rounded-xl bg-violet-700 px-5 py-3 font-bold text-white transition hover:bg-violet-800">Search games</button>
        </div>
        <p class="mt-2 text-xs leading-5 text-slate-500">Choose a result to fill its title, platform, and artwork URL. Set your own selling price and stock before adding it.</p>
        <div id="rawg-search-status" class="mt-3 text-sm text-slate-600" role="status" aria-live="polite"></div>
        <div id="rawg-search-results" class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3"></div>
    </div>

    <form action="admin.php" method="post" class="grid gap-4 sm:grid-cols-2">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="add_product">
        <div>
            <label for="name" class="mb-1.5 block text-sm font-semibold text-slate-700">Game title</label>
            <input id="name" name="name" maxlength="150" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-3 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100">
        </div>
        <div>
            <label for="price" class="mb-1.5 block text-sm font-semibold text-slate-700">Price</label>
            <input id="price" name="price" type="number" min="0" max="99999999.99" step="0.01" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-3 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100">
        </div>
        <div>
            <label for="stock_quantity" class="mb-1.5 block text-sm font-semibold text-slate-700">Stock quantity</label>
            <input id="stock_quantity" name="stock_quantity" type="number" min="0" step="1" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-3 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100">
        </div>
        <div>
            <label for="category" class="mb-1.5 block text-sm font-semibold text-slate-700">Platform</label>
            <select id="category" name="category" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-3 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100">
                <option value="">Select platform</option>
                <?php foreach ($platformOptions as $platform): ?>
                    <option value="<?= e($platform) ?>"><?= e($platform) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="description" class="mb-1.5 block text-sm font-semibold text-slate-700">Short description <span class="font-normal text-slate-400">(optional)</span></label>
            <textarea id="description" name="description" maxlength="500" rows="3" placeholder="Genre, edition, or a short store description" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-3 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"></textarea>
        </div>
        <div>
            <label for="image_url" class="mb-1.5 block text-sm font-semibold text-slate-700">Game artwork URL</label>
            <input id="image_url" name="image_url" type="url" maxlength="500" placeholder="Choose a RAWG result or paste a licensed HTTPS image URL" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-3 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100">
            <p class="mt-1 text-xs text-slate-400">Use artwork you have permission to display.</p>
        </div>
        <div class="sm:col-span-2">
            <button type="submit" <?= $databaseError ? 'disabled' : '' ?> class="rounded-xl bg-violet-700 px-5 py-3 font-bold text-white shadow-sm transition hover:bg-violet-800 disabled:cursor-not-allowed disabled:bg-slate-300">Add game to library</button>
        </div>
    </form>
    </div>
</section>

<script>
(() => {
    const searchInput = document.getElementById('rawg-search');
    const searchButton = document.getElementById('rawg-search-button');
    const status = document.getElementById('rawg-search-status');
    const resultsContainer = document.getElementById('rawg-search-results');
    const titleInput = document.getElementById('name');
    const platformInput = document.getElementById('category');
    const imageInput = document.getElementById('image_url');
    const supportedPlatforms = Array.from(platformInput?.options || []).map((option) => option.value);
    const platformAliases = {
        'pc': 'PC',
        'playstation': 'PlayStation',
        'playstation 2': 'PlayStation 2',
        'playstation 3': 'PlayStation 3',
        'playstation 4': 'PlayStation 4',
        'playstation 5': 'PlayStation 5',
        'playstation vita': 'PlayStation Vita',
        'ps vita': 'PlayStation Vita',
        'playstation portable': 'PSP',
        'psp': 'PSP',
        'xbox': 'Xbox',
        'xbox 360': 'Xbox 360',
        'xbox one': 'Xbox One',
        'xbox series s x': 'Xbox Series X|S',
        'xbox series x s': 'Xbox Series X|S',
        'nintendo ds': 'Nintendo DS',
        'nintendo 3ds': 'Nintendo 3DS',
        'nintendo switch': 'Nintendo Switch',
        'nintendo switch 2': 'Nintendo Switch 2',
        'nintendo gamecube': 'Nintendo GameCube',
        'gamecube': 'Nintendo GameCube',
        'nintendo 64': 'Nintendo 64',
        'wii': 'Wii',
        'wii u': 'Wii U',
        'game boy advance': 'Game Boy Advance',
        'game boy color': 'Game Boy Color',
        'game boy': 'Game Boy',
        'sega dreamcast': 'Sega Dreamcast',
    };

    if (!searchInput || !searchButton || !status || !resultsContainer || !titleInput || !platformInput || !imageInput) {
        return;
    }

    const renderResults = (games) => {
        resultsContainer.replaceChildren();
        for (const game of games) {
            const card = document.createElement('article');
            card.className = 'flex gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm';

            if (game.image_url) {
                const image = document.createElement('img');
                image.src = game.image_url;
                image.alt = '';
                image.loading = 'lazy';
                image.className = 'h-24 w-16 shrink-0 rounded-lg bg-slate-100 object-cover';
                card.append(image);
            }

            const details = document.createElement('div');
            details.className = 'min-w-0 flex-1';
            const title = document.createElement('h3');
            title.className = 'font-bold leading-5 text-slate-900';
            title.textContent = game.name;
            const metadata = document.createElement('p');
            metadata.className = 'mt-1 line-clamp-2 text-xs leading-4 text-slate-500';
            const metadataParts = [];
            if (game.released) metadataParts.push(game.released);
            if (typeof game.rating === 'number') metadataParts.push(`RAWG ${game.rating.toFixed(1)}/5`);
            if (game.platforms?.length) metadataParts.push(game.platforms.join(', '));
            metadata.textContent = metadataParts.join(' · ') || 'Platforms not listed';
            const selectButton = document.createElement('button');
            selectButton.type = 'button';
            selectButton.className = 'mt-2 rounded-lg bg-violet-100 px-3 py-1.5 text-xs font-bold text-violet-800 transition hover:bg-violet-200';
            selectButton.textContent = 'Use this game';
            selectButton.addEventListener('click', () => {
                titleInput.value = game.name;
                const normalizedPlatforms = (game.platforms || []).map((platform) =>
                    platform.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim()
                );
                platformInput.value = normalizedPlatforms
                    .map((platform) => platformAliases[platform])
                    .find((platform) => supportedPlatforms.includes(platform)) || '';
                imageInput.value = game.image_url || '';
                status.textContent = platformInput.value
                    ? `${game.name} selected. Add a price and stock quantity to list it.`
                    : `${game.name} selected. Choose a supported platform, then add a price and stock quantity.`;
                titleInput.focus();
            });
            details.append(title, metadata, selectButton);
            card.append(details);
            resultsContainer.append(card);
        }
    };

    const searchGames = async () => {
        const query = searchInput.value.trim();
        const params = new URLSearchParams();
        if (query) params.set('q', query);
        searchButton.disabled = true;
        searchButton.textContent = 'Searching…';
        status.textContent = 'Loading popular games…';
        resultsContainer.replaceChildren();

        try {
            const response = await fetch(`rawg-search.php?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Game search is unavailable.');
            renderResults(payload.results || []);
            status.textContent = payload.results?.length
                ? `Showing ${payload.results.length} games.`
                : 'No games found. Try another title.';
        } catch (error) {
            status.textContent = error instanceof Error ? error.message : 'Game search is unavailable.';
        } finally {
            searchButton.disabled = false;
            searchButton.textContent = 'Search games';
        }
    };

    searchButton.addEventListener('click', searchGames);
    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchGames();
        }
    });
    searchGames();
})();
</script>

<section>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-indigo-600">Store activity</p>
            <h2 class="mt-1 text-2xl font-bold text-slate-950">All orders</h2>
            <?php if (!$databaseError): ?><p class="mt-1 text-sm text-slate-500"><?= e(count($orders)) ?> orders to manage</p><?php endif; ?>
        </div>
    </div>
    <?php if (!$databaseError && !$orders): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">There are no orders yet.</div>
    <?php elseif (!$databaseError): ?>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[800px] text-left text-sm">
                <thead class="bg-slate-950 text-xs uppercase tracking-wide text-slate-300">
                    <tr>
                        <th class="px-5 py-4">Order</th>
                        <th class="px-5 py-4">Customer</th>
                        <th class="px-5 py-4">Date</th>
                        <th class="px-5 py-4">Total</th>
                        <th class="px-5 py-4">Payment</th>
                        <th class="px-5 py-4">Delivery status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td class="px-5 py-4 font-semibold text-slate-950">#<?= e($order['id']) ?></td>
                            <td class="px-5 py-4"><?= e($order['customer_name'] ?? ('User #' . $order['user_id'])) ?></td>
                            <td class="px-5 py-4"><?= e($order['created_at']) ?></td>
                            <td class="px-5 py-4 font-semibold">$<?= e(number_format((float) $order['total_amount'], 2)) ?></td>
                            <td class="px-5 py-4"><?= e($order['payment_status']) ?></td>
                            <td class="px-5 py-4">
                                <form action="admin.php" method="post" class="flex items-center gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="update_order_status">
                                    <input type="hidden" name="order_id" value="<?= e($order['id']) ?>">
                                    <label for="status-<?= e($order['id']) ?>" class="sr-only">Order #<?= e($order['id']) ?> status</label>
                                    <select id="status-<?= e($order['id']) ?>" name="order_status" required class="rounded-md border border-slate-300 px-2 py-1.5">
                                        <option value="" selected disabled>Choose status</option>
                                        <?php foreach ($allowedStatuses as $status): ?>
                                            <option value="<?= e($status) ?>"><?= e($status) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="rounded-md bg-slate-800 px-3 py-1.5 font-semibold text-white hover:bg-slate-700">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
