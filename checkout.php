<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?redirect=checkout.php');
    exit;
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
if (!$cart) {
    header('Location: cart.php');
    exit;
}

$notice = '';
$noticeType = 'error';
$shippingAddress = '';
$paymentMethod = 'Virtual Credit Card';
$summary = [];
$totalCents = 0;
$databaseError = false;
$checkoutBlocked = false;
try {
    $pdo = cloudcartPdo();
} catch (Throwable $exception) {
    error_log('CloudCart database connection error: ' . $exception->getMessage());
    $databaseError = true;
    $notice = 'Checkout is temporarily unavailable. Please try again later.';
}

if (!$databaseError && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $shippingAddress = trim((string) ($_POST['shipping_address'] ?? ''));
    $paymentMethod = (string) ($_POST['payment_method'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        $notice = 'Your request could not be verified. Please refresh the page and try again.';
    } elseif ($shippingAddress === '' || mb_strlen($shippingAddress) > 2000) {
        $notice = 'Enter a shipping address of no more than 2,000 characters.';
    } elseif (!in_array($paymentMethod, ['Virtual Credit Card', 'Virtual Wallet'], true)) {
        $notice = 'Choose a valid simulated payment method.';
    } else {
        try {
            $pdo->beginTransaction();
            $lockedProducts = [];
            $totalCents = 0;

            ksort($cart, SORT_NUMERIC);
            foreach ($cart as $productId => $quantity) {
                $productQuery = $pdo->prepare(
                    'SELECT id, name, price, stock_quantity
                     FROM dbo.products WITH (UPDLOCK, ROWLOCK)
                     WHERE id = :id'
                );
                $productQuery->execute(['id' => (int) $productId]);
                $product = $productQuery->fetch();

                if (!$product || (int) $product['stock_quantity'] < (int) $quantity) {
                    throw new DomainException(
                        'One or more products no longer have enough stock. Review your cart and try again.'
                    );
                }

                $unitCents = (int) round((float) $product['price'] * 100);
                $product['quantity'] = (int) $quantity;
                $product['unit_cents'] = $unitCents;
                $product['subtotal_cents'] = $unitCents * (int) $quantity;
                $lockedProducts[] = $product;
                $totalCents += $product['subtotal_cents'];
            }

            $transactionRef = 'TXN-' . strtoupper(bin2hex(random_bytes(16)));
            $orderInsert = $pdo->prepare(
                "INSERT INTO dbo.orders
                    (user_id, total_amount, order_status, shipping_address, payment_status,
                     payment_method, transaction_ref, created_at)
                 OUTPUT INSERTED.id
                 VALUES
                    (:user_id, :total_amount, 'Processing', :shipping_address, 'Simulated_Paid',
                     :payment_method, :transaction_ref, SYSUTCDATETIME())"
            );
            $orderInsert->execute([
                'user_id' => (int) $_SESSION['user_id'],
                'total_amount' => number_format($totalCents / 100, 2, '.', ''),
                'shipping_address' => $shippingAddress,
                'payment_method' => $paymentMethod,
                'transaction_ref' => $transactionRef,
            ]);
            $orderId = (int) $orderInsert->fetchColumn();
            if ($orderId < 1) {
                throw new RuntimeException('The order ID could not be retrieved.');
            }

            $itemInsert = $pdo->prepare(
                'INSERT INTO dbo.order_items (order_id, product_id, quantity, unit_price)
                 VALUES (:order_id, :product_id, :quantity, :unit_price)'
            );
            $stockUpdate = $pdo->prepare(
                'UPDATE dbo.products
                 SET stock_quantity = stock_quantity - :decrease
                 WHERE id = :id AND stock_quantity >= :required'
            );

            foreach ($lockedProducts as $product) {
                $itemInsert->execute([
                    'order_id' => $orderId,
                    'product_id' => (int) $product['id'],
                    'quantity' => (int) $product['quantity'],
                    'unit_price' => number_format($product['unit_cents'] / 100, 2, '.', ''),
                ]);
                $stockUpdate->execute([
                    'decrease' => (int) $product['quantity'],
                    'id' => (int) $product['id'],
                    'required' => (int) $product['quantity'],
                ]);
                if ($stockUpdate->rowCount() !== 1) {
                    throw new DomainException(
                        'Stock changed while placing your order. Please review your cart and try again.'
                    );
                }
            }

            $pdo->commit();
            $_SESSION['cart'] = [];
            $_SESSION['last_order_id'] = $orderId;
            header('Location: order-success.php?id=' . $orderId);
            exit;
        } catch (DomainException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $notice = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('CloudCart checkout error: ' . $exception->getMessage());
            $notice = 'We could not complete your order. Please try again.';
        }
    }
}

try {
    if ($databaseError) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    $totalCents = 0;
    $ids = array_map('intval', array_keys($cart));
    $placeholders = [];
    $parameters = [];
    foreach ($ids as $index => $id) {
        $parameterName = 'product' . $index;
        $placeholders[] = ':' . $parameterName;
        $parameters[$parameterName] = $id;
    }
    $summaryQuery = $pdo->prepare(
        'SELECT id, name, price, stock_quantity
         FROM dbo.products WHERE id IN (' . implode(', ', $placeholders) . ')'
    );
    $summaryQuery->execute($parameters);
    $summaryProducts = [];
    foreach ($summaryQuery->fetchAll() as $product) {
        $summaryProducts[(int) $product['id']] = $product;
    }
    foreach ($cart as $productId => $quantity) {
        $productId = (int) $productId;
        if (!isset($summaryProducts[$productId])) {
            $notice = 'A product in your cart is no longer available. Update your cart before checkout.';
            $checkoutBlocked = true;
            continue;
        }
        $product = $summaryProducts[$productId];
        $product['quantity'] = (int) $quantity;
        $product['unit_cents'] = (int) round((float) $product['price'] * 100);
        $product['subtotal_cents'] = $product['unit_cents'] * $product['quantity'];
        if ($product['quantity'] > (int) $product['stock_quantity']) {
            $notice = 'A product in your cart has insufficient stock. Update your cart before checkout.';
            $checkoutBlocked = true;
        }
        $summary[] = $product;
        $totalCents += $product['subtotal_cents'];
    }
} catch (Throwable $exception) {
    error_log('CloudCart checkout summary error: ' . $exception->getMessage());
    $databaseError = true;
    $notice = 'Your order summary could not be loaded. Please try again later.';
}

$pageTitle = 'Checkout';
require_once __DIR__ . '/header.php';
?>
<div class="mb-7">
    <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">Checkout</h1>
    <p class="mt-2 text-slate-500">Shipping and payment are simulated for this demonstration.</p>
</div>

<?php if ($databaseError): ?>
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-900">Checkout is temporarily unavailable. Please try again later.</div>
<?php else: ?>
    <div class="grid gap-8 lg:grid-cols-[1fr_0.8fr]">
        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-xl font-bold text-slate-900">Delivery and payment</h2>
            <form action="checkout.php" method="post" class="mt-6 space-y-5">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <div>
                    <label for="shipping_address" class="mb-2 block text-sm font-semibold text-slate-700">Shipping address</label>
                    <textarea id="shipping_address" name="shipping_address" rows="4" maxlength="2000" required class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"><?= e($shippingAddress) ?></textarea>
                </div>
                <fieldset>
                    <legend class="mb-3 text-sm font-semibold text-slate-700">Simulated payment method</legend>
                    <div class="space-y-3">
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-4 hover:border-indigo-300">
                            <input type="radio" name="payment_method" value="Virtual Credit Card" <?= $paymentMethod === 'Virtual Credit Card' ? 'checked' : '' ?> class="mt-1 accent-indigo-600">
                            <span>
                                <span class="block font-semibold text-slate-900">Virtual Credit Card</span>
                                <span class="mt-1 block text-sm text-slate-500">Demo only — no real card is charged or saved.</span>
                            </span>
                        </label>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label for="demo_card" class="mb-1 block text-xs font-medium text-slate-500">Dummy card number</label>
                                <input id="demo_card" value="4111 1111 1111 1111" readonly class="w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            </div>
                            <div>
                                <label for="demo_expiry" class="mb-1 block text-xs font-medium text-slate-500">Expiry</label>
                                <input id="demo_expiry" value="12/30" readonly class="w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            </div>
                            <div>
                                <label for="demo_cvv" class="mb-1 block text-xs font-medium text-slate-500">Dummy CVV</label>
                                <input id="demo_cvv" value="123" readonly class="w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            </div>
                        </div>
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-4 hover:border-indigo-300">
                            <input type="radio" name="payment_method" value="Virtual Wallet" <?= $paymentMethod === 'Virtual Wallet' ? 'checked' : '' ?> class="accent-indigo-600">
                            <span class="font-semibold text-slate-900">Virtual Wallet</span>
                        </label>
                    </div>
                </fieldset>
                <button type="submit" <?= !$summary || $checkoutBlocked || $databaseError ? 'disabled' : '' ?> class="w-full rounded-lg bg-indigo-600 px-5 py-3 font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                    Place simulated order
                </button>
            </form>
        </section>

        <aside class="h-fit rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-xl font-bold text-slate-900">Order summary</h2>
            <ul class="mt-5 divide-y divide-slate-100">
                <?php foreach ($summary as $item): ?>
                    <li class="flex justify-between gap-4 py-3 text-sm">
                        <span class="text-slate-600"><?= e($item['name']) ?> <span class="text-slate-400">× <?= e($item['quantity']) ?></span></span>
                        <span class="shrink-0 font-semibold text-slate-900">$<?= e(number_format($item['subtotal_cents'] / 100, 2)) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="mt-4 flex justify-between border-t border-slate-200 pt-4 text-lg font-bold">
                <span>Total</span>
                <span>$<?= e(number_format($totalCents / 100, 2)) ?></span>
            </div>
            <a href="cart.php" class="mt-5 inline-block text-sm font-semibold text-indigo-700 hover:text-indigo-900">Return to cart</a>
        </aside>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
