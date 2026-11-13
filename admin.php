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
$games = [];
$gameCsvInput = '';
$databaseError = false;
$allowedStatuses = ['Pending', 'Shipped', 'Delivered'];

function validateAdminImageUrl(string $value, bool $required = false): ?string
{
    $imageUrl = trim($value);
    if ($imageUrl === '') {
        if ($required) {
            throw new InvalidArgumentException('A licensed game cover URL is required.');
        }

        return null;
    }

    $parts = parse_url($imageUrl);
    if (
        mb_strlen($imageUrl) > 500
        || !filter_var($imageUrl, FILTER_VALIDATE_URL)
        || !is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        throw new InvalidArgumentException('Image URL must be a valid HTTPS URL of at most 500 characters.');
    }

    return $imageUrl;
}

function parseGameImportCsv(string $csv, array $platformOptions): array
{
    if (trim($csv) === '' || strlen($csv) > 200000) {
        throw new InvalidArgumentException('Paste a CSV with a header and no more than 200 KB of data.');
    }

    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('The CSV import could not be read.');
    }

    try {
        if (fwrite($stream, $csv) !== strlen($csv)) {
            throw new RuntimeException('The CSV import could not be read.');
        }
        rewind($stream);

        $header = fgetcsv($stream, null, ',', '"', '');
        if (!is_array($header)) {
            throw new InvalidArgumentException('The CSV must start with the expected column header.');
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(static fn ($value): string => strtolower(trim((string) $value)), $header);
        if ($header !== ['name', 'platform', 'price', 'stock_quantity', 'image_url', 'description']) {
            throw new InvalidArgumentException(
                'Use this exact CSV header: name,platform,price,stock_quantity,image_url,description.'
            );
        }

        $rows = [];
        $rowNumber = 1;
        while (($fields = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rowNumber++;
            if ($fields === [null] || (count($fields) === 1 && trim((string) $fields[0]) === '')) {
                continue;
            }
            if (count($fields) !== 6) {
                throw new InvalidArgumentException('CSV row ' . $rowNumber . ' must contain exactly six columns.');
            }

            $name = trim((string) $fields[0]);
            $platform = trim((string) $fields[1]);
            $price = filter_var(trim((string) $fields[2]), FILTER_VALIDATE_FLOAT);
            $stock = filter_var(trim((string) $fields[3]), FILTER_VALIDATE_INT);
            $description = trim((string) $fields[5]);

            if ($name === '' || mb_strlen($name) > 150) {
                throw new InvalidArgumentException('CSV row ' . $rowNumber . ' needs a title of 1–150 characters.');
            }
            if (!in_array($platform, $platformOptions, true)) {
                throw new InvalidArgumentException('CSV row ' . $rowNumber . ' has an unsupported platform.');
            }
            if ($price === false || $price < 0 || $price > 99999999.99) {
                throw new InvalidArgumentException('CSV row ' . $rowNumber . ' needs a valid price.');
            }
            if ($stock === false || $stock < 0) {
                throw new InvalidArgumentException('CSV row ' . $rowNumber . ' needs a stock quantity of zero or greater.');
            }
            if (mb_strlen($description) > 500) {
                throw new InvalidArgumentException('CSV row ' . $rowNumber . ' has a description over 500 characters.');
            }

            try {
                $imageUrl = validateAdminImageUrl((string) $fields[4], true);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException('CSV row ' . $rowNumber . ': ' . $exception->getMessage());
            }

            $rows[] = [
                'name' => $name,
                'category' => $platform,
                'price' => number_format((float) $price, 2, '.', ''),
                'stock_quantity' => $stock,
                'image_url' => $imageUrl,
                'description' => $description,
            ];
            if (count($rows) > 50) {
                throw new InvalidArgumentException('A single CSV import is limited to 50 games.');
            }
        }

        if (!$rows) {
            throw new InvalidArgumentException('The CSV does not contain any games to import.');
        }

        return $rows;
    } finally {
        fclose($stream);
    }
}

try {
    $pdo = cloudcartPdo();
} catch (Throwable $exception) {
    error_log('CloudCart admin database connection error: ' . $exception->getMessage());
    $pdo = null;
    $databaseError = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gameCsvInput = (string) ($_POST['game_csv'] ?? '');
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

            if ($action === 'update_product_cover') {
                $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$productId || $productId < 1) {
                    throw new InvalidArgumentException('Choose a valid game to update.');
                }

                $imageUrl = validateAdminImageUrl((string) ($_POST['image_url'] ?? ''), true);
                $productQuery = $pdo->prepare(
                    'SELECT id, category FROM dbo.products WHERE id = :id'
                );
                $productQuery->execute(['id' => $productId]);
                $product = $productQuery->fetch();
                if (!$product || !in_array((string) $product['category'], $platformOptions, true)) {
                    throw new InvalidArgumentException('That game could not be found in the supported catalog.');
                }

                $update = $pdo->prepare(
                    'UPDATE dbo.products SET image_url = :image_url WHERE id = :id'
                );
                $update->execute(['image_url' => $imageUrl, 'id' => $productId]);
                $_SESSION['flash_notice'] = 'Game cover updated. Price, stock, and order history were left unchanged.';
                header('Location: admin.php#game-covers');
                exit;
            }

            if ($action === 'bulk_import_products') {
                $importRows = parseGameImportCsv(
                    $gameCsvInput,
                    $platformOptions
                );
                $pdo->beginTransaction();
                try {
                    $duplicateQuery = $pdo->prepare(
                        'SELECT TOP (1) id FROM dbo.products WHERE name = :name AND category = :category'
                    );
                    $insert = $pdo->prepare(
                        'INSERT INTO dbo.products
                            (name, description, price, stock_quantity, category, image_url, created_at)
                         VALUES
                            (:name, :description, :price, :stock_quantity, :category, :image_url, SYSUTCDATETIME())'
                    );
                    $seenGames = [];
                    foreach ($importRows as $row) {
                        $gameKey = mb_strtolower($row['category'] . '|' . $row['name']);
                        if (isset($seenGames[$gameKey])) {
                            throw new InvalidArgumentException(
                                'The CSV contains the same game more than once for ' . $row['category'] . '.'
                            );
                        }
                        $seenGames[$gameKey] = true;

                        $duplicateQuery->execute([
                            'name' => $row['name'],
                            'category' => $row['category'],
                        ]);
                        if ($duplicateQuery->fetch()) {
                            throw new InvalidArgumentException(
                                $row['name'] . ' already exists for ' . $row['category'] . '.'
                            );
                        }

                        $insert->execute($row);
                    }
                    $pdo->commit();
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $exception;
                }

                $_SESSION['flash_notice'] = count($importRows) . ' games added. Existing catalog items were not changed.';
                header('Location: admin.php');
                exit;
            }

            if ($action === 'add_product') {
                $name = trim((string) ($_POST['name'] ?? ''));
                $description = trim((string) ($_POST['description'] ?? ''));
                $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
                $stock = filter_var($_POST['stock_quantity'] ?? null, FILTER_VALIDATE_INT);
                $category = trim((string) ($_POST['category'] ?? ''));
                $imageUrl = validateAdminImageUrl((string) ($_POST['image_url'] ?? ''), true);

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
                    'image_url' => $imageUrl,
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

        $gamePlatformPlaceholders = [];
        $gamePlatformParameters = [];
        foreach ($platformOptions as $index => $platform) {
            $parameterName = 'game_platform' . $index;
            $gamePlatformPlaceholders[] = ':' . $parameterName;
            $gamePlatformParameters[$parameterName] = $platform;
        }
        $gamesQuery = $pdo->prepare(
            'SELECT id, name, category, image_url
             FROM dbo.products
             WHERE category IN (' . implode(', ', $gamePlatformPlaceholders) . ')
             ORDER BY category, name, id'
        );
        $gamesQuery->execute($gamePlatformParameters);
        $games = $gamesQuery->fetchAll();
    } catch (Throwable $exception) {
        error_log('CloudCart admin dashboard query error: ' . $exception->getMessage());
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
        <p class="mt-2 text-xs leading-5 text-slate-500">RAWG helps find the title and platform. Its images are not case covers, so paste a licensed box-cover URL below; enter your own price and stock.</p>
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
            <label for="image_url" class="mb-1.5 block text-sm font-semibold text-slate-700">Licensed case-cover URL</label>
            <input id="image_url" name="image_url" type="url" maxlength="500" required placeholder="Paste an HTTPS URL for the actual game case cover" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-3 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100">
            <p class="mt-1 text-xs text-slate-400">Use a cover image you have permission to display.</p>
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
            selectButton.textContent = 'Use title and platform';
            selectButton.addEventListener('click', () => {
                titleInput.value = game.name;
                const normalizedPlatforms = (game.platforms || []).map((platform) =>
                    platform.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim()
                );
                platformInput.value = normalizedPlatforms
                    .map((platform) => platformAliases[platform])
                    .find((platform) => supportedPlatforms.includes(platform)) || '';
                status.textContent = platformInput.value
                    ? `${game.name} selected. Add its licensed case-cover URL, price, and stock quantity.`
                    : `${game.name} selected. Choose a supported platform, then add its licensed case-cover URL, price, and stock quantity.`;
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

<section class="mb-10 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-100 bg-gradient-to-r from-white to-violet-50/60 px-6 py-5">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-violet-700">Catalog setup</p>
        <h2 class="mt-1 text-xl font-bold text-slate-950">Bulk add games</h2>
        <p class="mt-2 text-sm text-slate-500">Fill in the 18-game template with your prices, starting stock, and licensed case-cover URLs. This adds new rows only; it does not delete or alter existing products.</p>
        <a href="game-import-template.csv" download class="mt-3 inline-flex rounded-lg border border-violet-200 bg-white px-3 py-2 text-sm font-bold text-violet-800 transition hover:bg-violet-50">Download 18-game CSV template</a>
    </div>
    <form action="admin.php#bulk-import" method="post" id="bulk-import" class="p-6">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="bulk_import_products">
        <label for="game_csv" class="mb-2 block text-sm font-semibold text-slate-700">CSV data</label>
        <textarea id="game_csv" name="game_csv" rows="10" required placeholder="name,platform,price,stock_quantity,image_url,description&#10;Game title,PlayStation 5,59.99,10,https://example.com/licensed-cover.jpg,Optional description" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-3 font-mono text-xs outline-none transition focus:border-violet-500 focus:bg-white focus:ring-4 focus:ring-violet-100"><?= e($gameCsvInput) ?></textarea>
        <p class="mt-2 text-xs leading-5 text-slate-500">CSV must use the exact header shown. Cover URLs are required; quote any CSV values containing commas. Imports are all-or-nothing and limited to 50 games per submission.</p>
        <button type="submit" <?= $databaseError ? 'disabled' : '' ?> class="mt-4 rounded-xl bg-violet-700 px-5 py-3 font-bold text-white shadow-sm transition hover:bg-violet-800 disabled:cursor-not-allowed disabled:bg-slate-300">Import games</button>
    </form>
</section>

<section id="game-covers" class="mb-10 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-100 bg-gradient-to-r from-white to-cyan-50/60 px-6 py-5">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-cyan-700">Keep your catalog artwork current</p>
        <h2 class="mt-1 text-xl font-bold text-slate-950">Update existing game covers</h2>
        <p class="mt-2 text-sm text-slate-500">Only the selected game’s image URL changes. Its price, stock, and order history stay as they are.</p>
    </div>
    <?php if ($databaseError): ?>
        <p class="p-6 text-sm text-amber-800">Existing games could not be loaded right now.</p>
    <?php elseif (!$games): ?>
        <p class="p-6 text-sm text-slate-500">No games in the supported platforms have been added yet.</p>
    <?php else: ?>
        <div class="divide-y divide-slate-100">
            <?php foreach ($games as $game): ?>
                <?php
                $currentImageUrl = trim((string) ($game['image_url'] ?? ''));
                $currentImageParts = parse_url($currentImageUrl);
                $hasCurrentImage = filter_var($currentImageUrl, FILTER_VALIDATE_URL)
                    && is_array($currentImageParts)
                    && strtolower((string) ($currentImageParts['scheme'] ?? '')) === 'https';
                ?>
                <form action="admin.php#game-covers" method="post" class="grid gap-3 p-5 sm:grid-cols-[minmax(0,1.1fr)_minmax(0,2fr)_auto] sm:items-end">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="update_product_cover">
                    <input type="hidden" name="product_id" value="<?= e($game['id']) ?>">
                    <div class="flex min-w-0 items-center gap-3">
                        <?php if ($hasCurrentImage): ?>
                            <img src="<?= e($currentImageUrl) ?>" alt="Current artwork for <?= e($game['name']) ?>" loading="lazy" class="h-16 w-12 shrink-0 rounded-md bg-slate-100 object-cover">
                        <?php else: ?>
                            <span class="flex h-16 w-12 shrink-0 items-center justify-center rounded-md bg-violet-100 text-[9px] font-bold text-violet-700">NO ART</span>
                        <?php endif; ?>
                        <div class="min-w-0">
                            <p class="truncate font-bold text-slate-900"><?= e($game['name']) ?></p>
                            <p class="mt-1 text-xs font-semibold text-violet-700"><?= e($game['category']) ?> · ID <?= e($game['id']) ?></p>
                        </div>
                    </div>
                    <div>
                        <label for="cover-url-<?= e($game['id']) ?>" class="mb-1.5 block text-xs font-semibold text-slate-600">Licensed case-cover URL</label>
                        <input id="cover-url-<?= e($game['id']) ?>" name="image_url" type="url" maxlength="500" required value="<?= e($currentImageUrl) ?>" placeholder="https://..." class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-100">
                    </div>
                    <button type="submit" <?= $databaseError ? 'disabled' : '' ?> class="rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-violet-800 disabled:cursor-not-allowed disabled:bg-slate-300">Save cover</button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

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
