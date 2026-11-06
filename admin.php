<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

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
                $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
                $stock = filter_var($_POST['stock_quantity'] ?? null, FILTER_VALIDATE_INT);
                $category = trim((string) ($_POST['category'] ?? ''));
                $imageUrl = trim((string) ($_POST['image_url'] ?? ''));

                if ($name === '' || mb_strlen($name) > 150) {
                    throw new InvalidArgumentException('Product name is required and must be 150 characters or fewer.');
                }
                if ($price === false || $price < 0 || $price > 99999999.99) {
                    throw new InvalidArgumentException('Enter a valid product price.');
                }
                if ($stock === false || $stock < 0) {
                    throw new InvalidArgumentException('Stock quantity must be zero or greater.');
                }
                if (mb_strlen($category) > 50) {
                    throw new InvalidArgumentException('Category must be 50 characters or fewer.');
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
                    'description' => '',
                    'price' => number_format((float) $price, 2, '.', ''),
                    'stock_quantity' => $stock,
                    'category' => $category === '' ? null : $category,
                    'image_url' => $imageUrl === '' ? null : $imageUrl,
                ]);
                $_SESSION['flash_notice'] = 'Product added to the catalog.';
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
require_once __DIR__ . '/header.php';
?>
<div class="mb-7">
    <p class="text-sm font-semibold uppercase tracking-widest text-indigo-600">CloudCart management</p>
    <h1 class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900">Admin dashboard</h1>
</div>

<?php if ($databaseError): ?>
    <div class="mb-8 rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-900">The dashboard data could not be loaded. Please try again later.</div>
<?php endif; ?>

<section class="mb-10 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="text-xl font-bold text-slate-900">Add a product</h2>
    <form action="admin.php" method="post" class="mt-5 grid gap-4 sm:grid-cols-2">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="add_product">
        <div>
            <label for="name" class="mb-1.5 block text-sm font-semibold text-slate-700">Product name</label>
            <input id="name" name="name" maxlength="150" required class="w-full rounded-lg border border-slate-300 px-3 py-2.5">
        </div>
        <div>
            <label for="price" class="mb-1.5 block text-sm font-semibold text-slate-700">Price</label>
            <input id="price" name="price" type="number" min="0" max="99999999.99" step="0.01" required class="w-full rounded-lg border border-slate-300 px-3 py-2.5">
        </div>
        <div>
            <label for="stock_quantity" class="mb-1.5 block text-sm font-semibold text-slate-700">Stock quantity</label>
            <input id="stock_quantity" name="stock_quantity" type="number" min="0" step="1" required class="w-full rounded-lg border border-slate-300 px-3 py-2.5">
        </div>
        <div>
            <label for="category" class="mb-1.5 block text-sm font-semibold text-slate-700">Category</label>
            <input id="category" name="category" maxlength="50" class="w-full rounded-lg border border-slate-300 px-3 py-2.5">
        </div>
        <div class="sm:col-span-2">
            <label for="image_url" class="mb-1.5 block text-sm font-semibold text-slate-700">Image URL</label>
            <input id="image_url" name="image_url" type="url" maxlength="500" placeholder="https://..." class="w-full rounded-lg border border-slate-300 px-3 py-2.5">
        </div>
        <div class="sm:col-span-2">
            <button type="submit" <?= $databaseError ? 'disabled' : '' ?> class="rounded-lg bg-indigo-600 px-5 py-3 font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">Add product</button>
        </div>
    </form>
</section>

<section>
    <div class="mb-4 flex items-end justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-slate-900">All orders</h2>
            <?php if (!$databaseError): ?><p class="mt-1 text-sm text-slate-500"><?= e(count($orders)) ?> orders</p><?php endif; ?>
        </div>
    </div>
    <?php if (!$databaseError && !$orders): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">There are no orders yet.</div>
    <?php elseif (!$databaseError): ?>
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[800px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
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
                            <td class="px-5 py-4 font-semibold text-slate-900">#<?= e($order['id']) ?></td>
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
