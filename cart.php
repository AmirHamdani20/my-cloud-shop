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
    <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">Your cart</h1>
    <p class="mt-2 text-slate-500">Review your items before checkout.</p>
</div>

<?php if ($databaseError): ?>
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-900">Your cart could not be loaded right now. Please try again later.</div>
<?php elseif (!$items): ?>
    <section class="rounded-xl border border-slate-200 bg-white p-10 text-center">
        <h2 class="text-xl font-semibold text-slate-900">Your cart is empty</h2>
        <p class="mt-2 text-slate-500">Browse the store to find something you love.</p>
        <a href="index.php" class="mt-5 inline-flex rounded-lg bg-indigo-600 px-5 py-3 font-semibold text-white hover:bg-indigo-700">Continue shopping</a>
    </section>
<?php else: ?>
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[700px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-4">Product</th>
                        <th class="px-5 py-4">Unit price</th>
                        <th class="px-5 py-4">Quantity</th>
                        <th class="px-5 py-4">Subtotal</th>
                        <th class="px-5 py-4"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="px-5 py-4 font-semibold text-slate-900"><?= e($item['name']) ?></td>
                            <td class="px-5 py-4">$<?= e(number_format($item['unit_cents'] / 100, 2)) ?></td>
                            <td class="px-5 py-4">
                                <form action="cart.php" method="post" class="flex items-center gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="update_qty">
                                    <input type="hidden" name="product_id" value="<?= e($item['id']) ?>">
                                    <button type="submit" name="quantity" value="<?= e(max(0, $item['quantity'] - 1)) ?>" aria-label="Decrease quantity" class="rounded-md border border-slate-300 px-2.5 py-1 hover:bg-slate-50">−</button>
                                    <span class="min-w-6 text-center"><?= e($item['quantity']) ?></span>
                                    <button type="submit" name="quantity" value="<?= e($item['quantity'] + 1) ?>" aria-label="Increase quantity" <?= $item['quantity'] >= (int) $item['stock_quantity'] ? 'disabled' : '' ?> class="rounded-md border border-slate-300 px-2.5 py-1 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40">+</button>
                                </form>
                                <?php if ($item['quantity'] > (int) $item['stock_quantity']): ?>
                                    <p class="mt-1 text-xs text-red-600">Only <?= e($item['stock_quantity']) ?> currently in stock.</p>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4 font-semibold">$<?= e(number_format($item['subtotal_cents'] / 100, 2)) ?></td>
                            <td class="px-5 py-4 text-right">
                                <form action="cart.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="product_id" value="<?= e($item['id']) ?>">
                                    <button type="submit" class="font-medium text-rose-600 hover:text-rose-800">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="flex flex-col gap-4 border-t border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between">
            <form action="cart.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="clear">
                <button type="submit" class="text-sm font-semibold text-slate-500 hover:text-rose-600">Clear cart</button>
            </form>
            <div class="flex flex-col items-start gap-3 sm:items-end">
                <p class="text-lg font-bold text-slate-900">Order total: $<?= e(number_format($totalCents / 100, 2)) ?></p>
                <a href="checkout.php" class="inline-flex rounded-lg bg-indigo-600 px-5 py-3 font-semibold text-white hover:bg-indigo-700">Proceed to Checkout</a>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
