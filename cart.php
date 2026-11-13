<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$notice = '';
$noticeType = 'success';
$databaseError = false;
try {
    $pdo = cloudcartPdo();
} catch (Throwable $exception) {
    error_log('CloudCart database connection error: ' . $exception->getMessage());
    $databaseError = true;
}
$cart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
$cart = array_filter($cart, static fn ($quantity, $productId): bool =>
    filter_var($productId, FILTER_VALIDATE_INT) !== false
    && (int) $productId > 0
    && filter_var($quantity, FILTER_VALIDATE_INT) !== false
    && (int) $quantity > 0,
    ARRAY_FILTER_USE_BOTH
);
$_SESSION['cart'] = $cart;

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action'])) {
    $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $providedToken = (string) ($input['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $providedToken)) {
        http_response_code(400);
        $notice = 'Your request could not be verified. Please refresh the page and try again.';
        $noticeType = 'error';
    } else {
        $action = (string) ($input['action'] ?? '');
        $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);

        try {
            if ($action === 'clear') {
                $_SESSION['cart'] = [];
                $_SESSION['flash_notice'] = 'Your cart has been cleared.';
                header('Location: cart.php');
                exit;
            }

            if ($action === 'remove') {
                if (!$productId || $productId < 1) {
                    throw new InvalidArgumentException('Choose a valid product.');
                }
                unset($_SESSION['cart'][$productId]);
                $_SESSION['flash_notice'] = 'Product removed from your cart.';
                header('Location: cart.php');
                exit;
            }

            if ($action === 'add' || $action === 'update_qty') {
                if (!$productId || $productId < 1) {
                    throw new InvalidArgumentException('Choose a valid product.');
                }
                if ($databaseError) {
                    throw new RuntimeException('The store database is unavailable.');
                }

                $productQuery = $pdo->prepare(
                    'SELECT id, stock_quantity FROM dbo.products WHERE id = :id'
                );
                $productQuery->execute(['id' => $productId]);
                $product = $productQuery->fetch();

                if (!$product) {
                    unset($_SESSION['cart'][$productId]);
                    throw new InvalidArgumentException('That product is no longer available.');
                }

                $stock = max(0, (int) $product['stock_quantity']);
                if ($action === 'add') {
                    $quantity = (int) ($_SESSION['cart'][$productId] ?? 0) + 1;
                } else {
                    $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
                    if ($quantity === false || $quantity < 0) {
                        throw new InvalidArgumentException('Enter a valid quantity.');
                    }
                    if ($quantity === 0) {
                        unset($_SESSION['cart'][$productId]);
                        $_SESSION['flash_notice'] = 'Product removed from your cart.';
                        header('Location: cart.php');
                        exit;
                    }
                }

                if ($stock < 1 || $quantity > $stock) {
                    throw new InvalidArgumentException('The requested quantity is not available in stock.');
                }

                $_SESSION['cart'][$productId] = $quantity;
                $_SESSION['flash_notice'] = $action === 'add'
                    ? 'Product added to your cart.'
                    : 'Cart quantity updated.';
                header('Location: cart.php');
                exit;
            }

            throw new InvalidArgumentException('The requested cart action is invalid.');
        } catch (InvalidArgumentException $exception) {
            $notice = $exception->getMessage();
            $noticeType = 'error';
        } catch (Throwable $exception) {
            error_log('CloudCart cart database error: ' . $exception->getMessage());
            http_response_code(500);
            $notice = 'We could not update your cart. Please try again.';
            $noticeType = 'error';
        }
    }
}

$items = [];
$totalCents = 0;
if ($cart && !$databaseError) {
    try {
        $ids = array_map('intval', array_keys($cart));
        $placeholders = [];
        $parameters = [];
        foreach ($ids as $index => $id) {
            $parameterName = 'product' . $index;
            $placeholders[] = ':' . $parameterName;
            $parameters[$parameterName] = $id;
        }
        $query = $pdo->prepare(
            'SELECT id, name, price, stock_quantity, image_url
             FROM dbo.products WHERE id IN (' . implode(', ', $placeholders) . ')'
        );
        $query->execute($parameters);
        $products = [];
        foreach ($query->fetchAll() as $product) {
            $products[(int) $product['id']] = $product;
        }

        foreach ($cart as $id => $quantity) {
            $id = (int) $id;
            if (!isset($products[$id])) {
                unset($_SESSION['cart'][$id]);
                continue;
            }
            $product = $products[$id];
            $price = number_format((float) $product['price'], 2, '.', '');
            $unitCents = (int) round((float) $price * 100);
            $product['quantity'] = (int) $quantity;
            $product['unit_cents'] = $unitCents;
            $product['subtotal_cents'] = $unitCents * (int) $quantity;
            $items[] = $product;
            $totalCents += $product['subtotal_cents'];
        }
    } catch (Throwable $exception) {
        error_log('CloudCart cart database error: ' . $exception->getMessage());
        $databaseError = true;
    }
}
$pageTitle = 'Your Cart';
require_once __DIR__ . '/header.php';
?>
<div class="mb-7">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-600">Your picks, all together</p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950 sm:text-4xl">Shopping cart</h1>
            <p class="mt-2 text-slate-500">Review your items before you head to checkout.</p>
        </div>
        <a href="index.php" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-indigo-200 hover:text-indigo-700">
            <span aria-hidden="true">←</span> Keep browsing
        </a>
    </div>
    <ol class="mt-7 flex max-w-xl items-center gap-3 text-xs font-semibold sm:text-sm" aria-label="Checkout progress">
        <li class="flex items-center gap-2 text-indigo-700"><span class="flex h-7 w-7 items-center justify-center rounded-full bg-indigo-600 text-white">1</span> Cart</li>
        <li class="h-px flex-1 bg-slate-200" aria-hidden="true"></li>
        <li class="flex items-center gap-2 text-slate-400"><span class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white">2</span> Details</li>
        <li class="h-px flex-1 bg-slate-200" aria-hidden="true"></li>
        <li class="flex items-center gap-2 text-slate-400"><span class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white">3</span> Complete</li>
    </ol>
</div>

<?php if ($databaseError): ?>
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-900">Your cart could not be loaded right now. Please try again later.</div>
<?php elseif (!$items): ?>
    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white text-center shadow-sm">
        <div class="bg-gradient-to-br from-indigo-50 via-white to-cyan-50 px-6 py-14 sm:px-10">
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-white text-indigo-600 shadow-sm" aria-hidden="true">
                <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3h2l2.2 11.2a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 2-1.6L22 7H6M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"/></svg>
            </span>
            <h2 class="mt-5 text-2xl font-bold text-slate-950">Your cart is waiting for something good</h2>
            <p class="mx-auto mt-2 max-w-md text-slate-500">Browse the collection and add something you love. Your picks will be saved here.</p>
            <a href="index.php" class="mt-6 inline-flex rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white shadow-sm transition hover:bg-indigo-700">Explore the shop</a>
        </div>
    </section>
<?php else: ?>
    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-6">
                <h2 class="font-bold text-slate-950"><?= e(count($items)) ?> <?= count($items) === 1 ? 'product' : 'products' ?> in your cart</h2>
                <span class="text-xs font-medium text-slate-400">Prices in USD</span>
            </div>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($items as $item): ?>
                    <?php
                    $imageUrl = (string) ($item['image_url'] ?? '');
                    $imageScheme = strtolower((string) parse_url($imageUrl, PHP_URL_SCHEME));
                    $hasImage = $imageUrl !== '' && in_array($imageScheme, ['http', 'https'], true);
                    ?>
                    <li class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:p-6">
                        <div class="flex min-w-0 flex-1 items-center gap-4">
                            <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-slate-50 sm:h-24 sm:w-24">
                                <?php if ($hasImage): ?>
                                    <img src="<?= e($imageUrl) ?>" alt="<?= e($item['name']) ?>" loading="lazy" class="h-full w-full object-contain p-2">
                                <?php else: ?>
                                    <span class="text-xs text-slate-400">No image</span>
                                <?php endif; ?>
                            </div>
                            <div class="min-w-0">
                                <h3 class="font-bold leading-6 text-slate-950"><?= e($item['name']) ?></h3>
                                <p class="mt-1 text-sm text-slate-500">$<?= e(number_format($item['unit_cents'] / 100, 2)) ?> each</p>
                                <?php if ($item['quantity'] > (int) $item['stock_quantity']): ?>
                                    <p class="mt-2 text-xs font-semibold text-red-600">Only <?= e($item['stock_quantity']) ?> currently in stock.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-4 sm:justify-end">
                            <form action="cart.php" method="post" class="flex items-center rounded-lg border border-slate-200 p-1">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="update_qty">
                                <input type="hidden" name="product_id" value="<?= e($item['id']) ?>">
                                <button type="submit" name="quantity" value="<?= e(max(0, $item['quantity'] - 1)) ?>" aria-label="Decrease quantity of <?= e($item['name']) ?>" class="flex h-8 w-8 items-center justify-center rounded-md text-lg text-slate-600 transition hover:bg-slate-100">−</button>
                                <span class="min-w-8 text-center text-sm font-bold text-slate-800"><?= e($item['quantity']) ?></span>
                                <button type="submit" name="quantity" value="<?= e($item['quantity'] + 1) ?>" aria-label="Increase quantity of <?= e($item['name']) ?>" <?= $item['quantity'] >= (int) $item['stock_quantity'] ? 'disabled' : '' ?> class="flex h-8 w-8 items-center justify-center rounded-md text-lg text-slate-600 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40">+</button>
                            </form>
                            <div class="min-w-20 text-right">
                                <p class="font-bold text-slate-950">$<?= e(number_format($item['subtotal_cents'] / 100, 2)) ?></p>
                                <form action="cart.php" method="post" class="mt-1">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="product_id" value="<?= e($item['id']) ?>">
                                    <button type="submit" class="text-xs font-semibold text-slate-400 underline decoration-slate-300 underline-offset-2 transition hover:text-rose-600">Remove</button>
                                </form>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="border-t border-slate-100 px-5 py-4 sm:px-6">
                <form action="cart.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="text-sm font-semibold text-slate-400 transition hover:text-rose-600">Clear your cart</button>
                </form>
            </div>
        </section>
        <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 lg:sticky lg:top-6">
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-indigo-600">The overview</p>
            <h2 class="mt-2 text-xl font-bold text-slate-950">Order summary</h2>
            <div class="mt-5 flex justify-between border-b border-slate-100 pb-4 text-sm">
                <span class="text-slate-500">Items subtotal</span>
                <span class="font-semibold text-slate-900">$<?= e(number_format($totalCents / 100, 2)) ?></span>
            </div>
            <div class="flex justify-between py-4">
                <span class="font-bold text-slate-900">Total</span>
                <span class="text-xl font-extrabold text-slate-950">$<?= e(number_format($totalCents / 100, 2)) ?></span>
            </div>
            <p class="text-xs leading-5 text-slate-400">Checkout and payment are simulated for this demo.</p>
            <a href="checkout.php" class="mt-5 flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-3.5 text-sm font-bold text-white shadow-sm transition hover:bg-indigo-700">
                Continue to checkout <span aria-hidden="true">→</span>
            </a>
            <div class="mt-5 flex items-center justify-center gap-2 border-t border-slate-100 pt-4 text-xs text-slate-400">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 1.667a.833.833 0 0 1 .348.076l6.666 3A.833.833 0 0 1 17.5 5.5v4.167c0 3.348-2.034 6.523-6.965 8.6a1.375 1.375 0 0 1-1.07 0C4.534 16.19 2.5 13.015 2.5 9.667V5.5a.833.833 0 0 1 .486-.757l6.666-3A.833.833 0 0 1 10 1.667Z" clip-rule="evenodd"/></svg>
                Secure, simulated checkout
            </div>
            </aside>
        </div>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
