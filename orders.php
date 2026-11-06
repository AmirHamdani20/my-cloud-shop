<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?redirect=orders.php');
    exit;
}

$orders = [];
$databaseError = false;

try {
    $pdo = cloudcartPdo();
    $orderQuery = $pdo->prepare(
        'SELECT id, total_amount, order_status, payment_status, created_at
         FROM dbo.orders
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC'
    );
    $orderQuery->execute(['user_id' => (int) $_SESSION['user_id']]);
    $orders = $orderQuery->fetchAll();

    if ($orders) {
        $placeholders = [];
        $parameters = [];
        foreach ($orders as $index => $order) {
            $parameterName = 'order' . $index;
            $placeholders[] = ':' . $parameterName;
            $parameters[$parameterName] = (int) $order['id'];
        }

        $itemQuery = $pdo->prepare(
            'SELECT oi.order_id, oi.product_id, oi.quantity, oi.unit_price, p.name AS product_name
             FROM dbo.order_items AS oi
             LEFT JOIN dbo.products AS p ON p.id = oi.product_id
             WHERE oi.order_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY oi.order_id DESC, oi.id ASC'
        );
        $itemQuery->execute($parameters);

        $itemsByOrder = [];
        foreach ($itemQuery->fetchAll() as $item) {
            $itemsByOrder[(int) $item['order_id']][] = $item;
        }
        foreach ($orders as &$order) {
            $order['items'] = $itemsByOrder[(int) $order['id']] ?? [];
        }
        unset($order);
    }
} catch (Throwable $exception) {
    error_log('CloudCart orders page error: ' . $exception->getMessage());
    $databaseError = true;
}

$statusSteps = ['Pending', 'Processing', 'Shipped', 'Delivered'];
$pageTitle = 'My Orders';
require_once __DIR__ . '/header.php';
?>
<div class="mb-7">
    <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">My orders</h1>
    <p class="mt-2 text-slate-500">Track your orders and review the items you purchased.</p>
</div>

<?php if ($databaseError): ?>
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-900">
        Your order history could not be loaded right now. Please try again later.
    </div>
<?php elseif (!$orders): ?>
    <section class="rounded-xl border border-slate-200 bg-white p-10 text-center">
        <h2 class="text-xl font-semibold text-slate-900">No orders yet</h2>
        <p class="mt-2 text-slate-500">Your completed purchases will appear here.</p>
        <a href="index.php" class="mt-5 inline-flex rounded-lg bg-indigo-600 px-5 py-3 font-semibold text-white hover:bg-indigo-700">Start shopping</a>
    </section>
<?php else: ?>
    <div class="space-y-5">
        <?php foreach ($orders as $order): ?>
            <?php
            $normalizedStatus = strtolower(trim((string) $order['order_status']));
            $currentStep = array_search($normalizedStatus, array_map('strtolower', $statusSteps), true);
            $paymentStatus = (string) $order['payment_status'];
            $paymentClass = match (strtolower($paymentStatus)) {
                'simulated_paid', 'paid' => 'bg-emerald-100 text-emerald-800',
                'unpaid', 'failed' => 'bg-rose-100 text-rose-800',
                default => 'bg-amber-100 text-amber-800',
            };
            try {
                $orderDate = (new DateTimeImmutable((string) $order['created_at']))->format('M j, Y · g:i A');
            } catch (Throwable) {
                $orderDate = (string) $order['created_at'];
            }
            ?>
            <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 p-5 sm:p-6">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Order #<?= e($order['id']) ?></h2>
                        <p class="mt-1 text-sm text-slate-500"><?= e($orderDate) ?></p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-full px-3 py-1 text-xs font-semibold <?= e($paymentClass) ?>">
                            <?= e($paymentStatus) ?>
                        </span>
                        <span class="text-lg font-bold text-slate-900">$<?= e(number_format((float) $order['total_amount'], 2)) ?></span>
                    </div>
                </div>

                <div class="px-5 py-6 sm:px-6">
                    <h3 class="mb-4 text-sm font-semibold text-slate-700">Order status</h3>
                    <ol class="grid grid-cols-4 gap-2" aria-label="Order status: <?= e($order['order_status']) ?>">
                        <?php foreach ($statusSteps as $stepIndex => $step): ?>
                            <?php
                            $isComplete = $currentStep !== false && $stepIndex <= $currentStep;
                            $isCurrent = $currentStep !== false && $stepIndex === $currentStep;
                            ?>
                            <li class="relative text-center">
                                <?php if ($stepIndex > 0): ?>
                                    <span class="absolute right-1/2 top-3 -z-0 h-0.5 w-full <?= $isComplete ? 'bg-indigo-600' : 'bg-slate-200' ?>" aria-hidden="true"></span>
                                <?php endif; ?>
                                <span class="relative z-10 mx-auto flex h-6 w-6 items-center justify-center rounded-full border-2 text-xs <?= $isComplete ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 bg-white text-slate-400' ?>">
                                    <?= $isComplete ? '✓' : '' ?>
                                </span>
                                <span class="mt-2 block text-[11px] leading-tight sm:text-xs <?= $isCurrent ? 'font-bold text-indigo-700' : ($isComplete ? 'font-medium text-slate-700' : 'text-slate-400') ?>">
                                    <?= e($step) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <?php if ($currentStep === false): ?>
                        <p class="mt-3 text-center text-sm text-slate-500">Current status: <?= e($order['order_status']) ?></p>
                    <?php endif; ?>
                </div>

                <details class="border-t border-slate-100">
                    <summary class="cursor-pointer px-5 py-4 text-sm font-semibold text-indigo-700 hover:bg-slate-50 sm:px-6">
                        Purchased items (<?= e(count($order['items'])) ?>)
                    </summary>
                    <?php if ($order['items']): ?>
                        <ul class="divide-y divide-slate-100 px-5 pb-2 sm:px-6">
                            <?php foreach ($order['items'] as $item): ?>
                                <li class="flex flex-wrap justify-between gap-2 py-3 text-sm">
                                    <span class="text-slate-700">
                                        <?= e($item['product_name'] ?? ('Product #' . $item['product_id'])) ?>
                                        <span class="text-slate-400">× <?= e($item['quantity']) ?></span>
                                    </span>
                                    <span class="font-medium text-slate-900">$<?= e(number_format((float) $item['unit_price'] * (int) $item['quantity'], 2)) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="px-5 pb-5 text-sm text-slate-500 sm:px-6">No item details are available for this order.</p>
                    <?php endif; ?>
                </details>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
