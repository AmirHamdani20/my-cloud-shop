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
<section class="mx-auto max-w-2xl rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm sm:p-10">
    <?php if ($databaseError): ?>
        <h1 class="text-2xl font-bold text-slate-900">Order confirmation unavailable</h1>
        <p class="mt-3 text-slate-600">Your order may have been placed. Check your order history or try again later.</p>
    <?php elseif (!$order): ?>
        <h1 class="text-2xl font-bold text-slate-900">Order not found</h1>
        <p class="mt-3 text-slate-600">We couldn’t find an order associated with your account.</p>
    <?php else: ?>
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-2xl font-bold text-emerald-700" aria-hidden="true">✓</div>
        <h1 class="mt-5 text-3xl font-extrabold tracking-tight text-slate-900">Thank you for your order!</h1>
        <p class="mt-3 text-slate-600">Your simulated payment is complete and your order is being processed.</p>
        <dl class="mx-auto mt-7 max-w-sm space-y-3 rounded-lg bg-slate-50 p-5 text-left text-sm">
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Order number</dt><dd class="font-semibold text-slate-900">#<?= e($order['id']) ?></dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Status</dt><dd class="font-semibold text-slate-900"><?= e($order['order_status']) ?></dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Payment</dt><dd class="font-semibold text-slate-900"><?= e($order['payment_status']) ?></dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Total</dt><dd class="font-semibold text-slate-900">$<?= e(number_format((float) $order['total_amount'], 2)) ?></dd></div>
        </dl>
    <?php endif; ?>
    <a href="index.php" class="mt-7 inline-flex rounded-lg bg-indigo-600 px-5 py-3 font-semibold text-white hover:bg-indigo-700">Continue shopping</a>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
