<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$orderId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$order = false;
$databaseError = false;
if ($orderId && $orderId > 0) {
    try {
        $pdo = cloudcartPdo();
        $query = $pdo->prepare(
            'SELECT id, total_amount, order_status, payment_status, created_at
             FROM dbo.orders WHERE id = :id AND user_id = :user_id'
        );
        $query->execute([
            'id' => $orderId,
            'user_id' => (int) $_SESSION['user_id'],
        ]);
        $order = $query->fetch();
    } catch (Throwable $exception) {
        error_log('CloudCart order confirmation error: ' . $exception->getMessage());
        $databaseError = true;
    }
}

$pageTitle = 'Order Confirmation';
require_once __DIR__ . '/header.php';
?>
<section class="mx-auto max-w-3xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl">
    <?php if ($databaseError): ?>
        <div class="p-8 text-center sm:p-12">
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-amber-100 text-2xl font-bold text-amber-700" aria-hidden="true">!</span>
            <h1 class="mt-5 text-2xl font-bold text-slate-950">Order confirmation unavailable</h1>
            <p class="mt-3 text-slate-600">Your order may have been placed. Check your order history or try again later.</p>
        </div>
    <?php elseif (!$order): ?>
        <div class="p-8 text-center sm:p-12">
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100 text-2xl font-bold text-slate-500" aria-hidden="true">?</span>
            <h1 class="mt-5 text-2xl font-bold text-slate-950">Order not found</h1>
            <p class="mt-3 text-slate-600">We couldn’t find an order associated with your account.</p>
        </div>
    <?php else: ?>
        <div class="bg-gradient-to-br from-slate-950 via-indigo-950 to-slate-900 px-6 py-10 text-center text-white sm:px-12 sm:py-14">
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-400 text-3xl font-bold text-slate-950 shadow-lg shadow-emerald-500/20" aria-hidden="true">✓</span>
            <p class="mt-6 text-xs font-bold uppercase tracking-[0.2em] text-cyan-300">Order received</p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">Thanks for shopping with us.</h1>
            <p class="mx-auto mt-3 max-w-lg text-sm leading-6 text-slate-300">Your simulated payment is complete and your order is being processed.</p>
        </div>
        <div class="p-6 sm:p-9">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.15em] text-slate-400">Order number</p>
                    <p class="mt-1 text-xl font-extrabold text-slate-950">#<?= e($order['id']) ?></p>
                </div>
                <a href="orders.php" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-indigo-200 hover:text-indigo-700">Track your order</a>
            </div>
            <dl class="mt-6 grid gap-3 rounded-2xl bg-slate-50 p-5 text-sm sm:grid-cols-3">
                <div><dt class="text-slate-500">Status</dt><dd class="mt-1 font-semibold text-slate-950"><?= e($order['order_status']) ?></dd></div>
                <div><dt class="text-slate-500">Payment</dt><dd class="mt-1 font-semibold text-slate-950"><?= e($order['payment_status']) ?></dd></div>
                <div><dt class="text-slate-500">Total</dt><dd class="mt-1 font-bold text-slate-950">$<?= e(number_format((float) $order['total_amount'], 2)) ?></dd></div>
            </dl>
        </div>
    <?php endif; ?>
    <div class="border-t border-slate-100 px-6 py-5 text-center sm:px-9">
        <a href="index.php" class="inline-flex rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white shadow-sm transition hover:bg-indigo-700">Continue shopping</a>
    </div>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
