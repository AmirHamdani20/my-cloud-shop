<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?redirect=wishlist.php');
    exit;
}

$notice = '';
$noticeType = 'success';
$products = [];
$databaseError = false;
$userId = (int) $_SESSION['user_id'];

try {
    $pdo = cloudcartPdo();
} catch (Throwable $exception) {
    error_log('CloudCart wishlist database connection error: ' . $exception->getMessage());
    $databaseError = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        $notice = 'Your request could not be verified. Please refresh the page and try again.';
        $noticeType = 'error';
    } elseif ($databaseError) {
        $notice = 'Your wishlist is temporarily unavailable. Please try again later.';
        $noticeType = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);

        try {
            if (!in_array($action, ['add', 'remove', 'move_to_cart'], true) || !$productId || $productId < 1) {
                throw new InvalidArgumentException('The requested wishlist action is invalid.');
            }

            if ($action === 'add') {
                $productQuery = $pdo->prepare(
                    'SELECT id FROM dbo.products WHERE id = :id'
                );
                $productQuery->execute(['id' => $productId]);
                if (!$productQuery->fetch()) {
                    throw new InvalidArgumentException('That product could not be found.');
                }

                $existsQuery = $pdo->prepare(
                    'SELECT id FROM dbo.wishlists WHERE user_id = :user_id AND product_id = :product_id'
                );
                $existsQuery->execute(['user_id' => $userId, 'product_id' => $productId]);
                if (!$existsQuery->fetch()) {
                    $insert = $pdo->prepare(
                        'INSERT INTO dbo.wishlists (user_id, product_id) VALUES (:user_id, :product_id)'
                    );
                    $insert->execute(['user_id' => $userId, 'product_id' => $productId]);
                }

                $_SESSION['flash_notice'] = 'Product saved to your wishlist.';
                header('Location: wishlist.php');
                exit;
            }

            $wishlistProduct = $pdo->prepare(
                'SELECT w.id AS wishlist_id, p.id, p.stock_quantity
                 FROM dbo.wishlists AS w
                 INNER JOIN dbo.products AS p ON p.id = w.product_id
                 WHERE w.user_id = :user_id AND w.product_id = :product_id'
            );
            $wishlistProduct->execute(['user_id' => $userId, 'product_id' => $productId]);
            $savedProduct = $wishlistProduct->fetch();
            if (!$savedProduct) {
                throw new InvalidArgumentException('That product is not in your wishlist.');
            }

            if ($action === 'remove') {
                $delete = $pdo->prepare(
                    'DELETE FROM dbo.wishlists WHERE id = :id AND user_id = :user_id'
                );
                $delete->execute(['id' => (int) $savedProduct['wishlist_id'], 'user_id' => $userId]);
                $_SESSION['flash_notice'] = 'Product removed from your wishlist.';
                header('Location: wishlist.php');
                exit;
            }

            $stock = max(0, (int) $savedProduct['stock_quantity']);
            $cart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
            $currentQuantity = max(0, (int) ($cart[$productId] ?? 0));
            if ($stock < 1 || $currentQuantity >= $stock) {
                throw new InvalidArgumentException('This product is not currently available in the requested quantity.');
            }

            $delete = $pdo->prepare(
                'DELETE FROM dbo.wishlists WHERE id = :id AND user_id = :user_id'
            );
            $delete->execute(['id' => (int) $savedProduct['wishlist_id'], 'user_id' => $userId]);
            $cart[$productId] = $currentQuantity + 1;
            $_SESSION['cart'] = $cart;
            $_SESSION['flash_notice'] = 'Product moved to your cart.';
            header('Location: cart.php');
            exit;
        } catch (InvalidArgumentException $exception) {
            $notice = $exception->getMessage();
            $noticeType = 'error';
        } catch (Throwable $exception) {
            error_log('CloudCart wishlist action error: ' . $exception->getMessage());
            http_response_code(500);
            $notice = 'We could not update your wishlist. Please try again.';
            $noticeType = 'error';
        }
    }
}

if (!$databaseError) {
    try {
        $query = $pdo->prepare(
            'SELECT p.id, p.name, p.price, p.stock_quantity, p.image_url, p.category, w.created_at
             FROM dbo.wishlists AS w
             INNER JOIN dbo.products AS p ON p.id = w.product_id
             WHERE w.user_id = :user_id
             ORDER BY w.created_at DESC, w.id DESC'
        );
        $query->execute(['user_id' => $userId]);
        $products = $query->fetchAll();
    } catch (Throwable $exception) {
        error_log('CloudCart wishlist query error: ' . $exception->getMessage());
        $databaseError = true;
    }
}

$pageTitle = 'My Wishlist';
require_once __DIR__ . '/header.php';
?>
<div class="mb-7">
    <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">My wishlist</h1>
    <p class="mt-2 text-slate-500">Keep track of the products you love.</p>
</div>

<?php if ($databaseError): ?>
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-900">
        Your wishlist could not be loaded right now. Please try again later.
    </div>
<?php elseif (!$products): ?>
    <section class="rounded-xl border border-slate-200 bg-white p-10 text-center">
        <h2 class="text-xl font-semibold text-slate-900">Your wishlist is empty</h2>
        <p class="mt-2 text-slate-500">Save products from the store and they’ll show up here.</p>
        <a href="index.php" class="mt-5 inline-flex rounded-lg bg-indigo-600 px-5 py-3 font-semibold text-white hover:bg-indigo-700">Browse products</a>
    </section>
<?php else: ?>
    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <?php foreach ($products as $product): ?>
            <?php
            $imageUrl = isset($product['image_url']) ? (string) $product['image_url'] : '';
            $imageScheme = strtolower((string) parse_url($imageUrl, PHP_URL_SCHEME));
            $hasImage = $imageUrl !== '' && in_array($imageScheme, ['http', 'https'], true);
            $inStock = (int) $product['stock_quantity'] > 0;
            ?>
            <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="relative aspect-[4/3] bg-slate-100">
                    <?php if ($hasImage): ?>
                        <img src="<?= e($imageUrl) ?>" alt="<?= e($product['name']) ?>" loading="lazy" class="h-full w-full object-cover">
                    <?php else: ?>
                        <div class="flex h-full items-center justify-center text-sm text-slate-400">Image unavailable</div>
                    <?php endif; ?>
                    <?php if (!empty($product['category'])): ?>
                        <span class="absolute left-3 top-3 rounded-full bg-white/95 px-3 py-1 text-xs font-semibold text-slate-700 shadow-sm"><?= e($product['category']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="p-5">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="font-semibold text-slate-900"><?= e($product['name']) ?></h2>
                        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold <?= $inStock ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' ?>">
                            <?= $inStock ? 'In Stock' : 'Out of Stock' ?>
                        </span>
                    </div>
                    <p class="mt-3 text-xl font-bold text-indigo-700">$<?= e(number_format((float) $product['price'], 2)) ?></p>
                    <div class="mt-5 grid grid-cols-2 gap-2">
                        <form action="wishlist.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="move_to_cart">
                            <input type="hidden" name="product_id" value="<?= e($product['id']) ?>">
                            <button type="submit" <?= !$inStock ? 'disabled' : '' ?> class="w-full rounded-lg bg-indigo-600 px-3 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                                Move to Cart
                            </button>
                        </form>
                        <form action="wishlist.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="product_id" value="<?= e($product['id']) ?>">
                            <button type="submit" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-semibold text-slate-700 hover:border-rose-300 hover:text-rose-600">
                                Remove
                            </button>
                        </form>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
