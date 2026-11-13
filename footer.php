    </main>
    <footer class="mt-16 border-t border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-[1.4fr_0.8fr_0.8fr]">
                <div>
                    <a href="index.php" class="text-xl font-black tracking-tight text-slate-950">Cloud<span class="text-indigo-600">Cart</span></a>
                    <p class="mt-3 max-w-sm text-sm leading-6 text-slate-500">A considered collection of tech and everyday essentials, with a simpler way to shop.</p>
                </div>
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Explore</h2>
                    <ul class="mt-3 space-y-2 text-sm text-slate-500">
                        <li><a href="index.php" class="transition hover:text-indigo-700">Shop all products</a></li>
                        <li><a href="wishlist.php" class="transition hover:text-indigo-700">Your wishlist</a></li>
                        <li><a href="cart.php" class="transition hover:text-indigo-700">Shopping cart</a></li>
                    </ul>
                </div>
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Your account</h2>
                    <ul class="mt-3 space-y-2 text-sm text-slate-500">
                        <?php if (!empty($_SESSION['user_id'])): ?>
                            <li><a href="orders.php" class="transition hover:text-indigo-700">Order history</a></li>
                            <li><a href="index.php" class="transition hover:text-indigo-700">Continue shopping</a></li>
                        <?php else: ?>
                            <li><a href="login.php" class="transition hover:text-indigo-700">Sign in</a></li>
                            <li><a href="register.php" class="transition hover:text-indigo-700">Create an account</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <div class="mt-8 flex flex-col gap-2 border-t border-slate-100 pt-5 text-xs text-slate-400 sm:flex-row sm:items-center sm:justify-between">
                <p>&copy; <?= e(date('Y')) ?> CloudCart. All rights reserved.</p>
                <p>Demo store · Checkout and payment are simulated.</p>
            </div>
        </div>
    </footer>
</body>
</html>
