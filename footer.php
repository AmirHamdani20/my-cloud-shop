    </main>
    <footer class="mt-16 border-t border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-[1.4fr_0.8fr_0.8fr]">
                <div>
                    <a href="index.php" class="text-xl font-black tracking-tight text-slate-950">GAME<span class="text-violet-700">GRID</span></a>
                    <p class="mt-3 max-w-sm text-sm leading-6 text-slate-500">Find your next game across the platforms you play. Browse the collection, save favourites, and build your next adventure.</p>
                </div>
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Explore games</h2>
                    <ul class="mt-3 space-y-2 text-sm text-slate-500">
                        <li><a href="index.php" class="transition hover:text-violet-700">Shop all games</a></li>
                        <li><a href="wishlist.php" class="transition hover:text-violet-700">Your wishlist</a></li>
                        <li><a href="cart.php" class="transition hover:text-violet-700">Shopping cart</a></li>
                    </ul>
                </div>
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Player account</h2>
                    <ul class="mt-3 space-y-2 text-sm text-slate-500">
                        <?php if (!empty($_SESSION['user_id'])): ?>
                            <li><a href="orders.php" class="transition hover:text-violet-700">Order history</a></li>
                            <li><a href="index.php" class="transition hover:text-violet-700">Browse games</a></li>
                        <?php else: ?>
                            <li><a href="login.php" class="transition hover:text-violet-700">Sign in</a></li>
                            <li><a href="register.php" class="transition hover:text-violet-700">Create a player account</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <div class="mt-8 flex flex-col gap-2 border-t border-slate-100 pt-5 text-xs text-slate-400 sm:flex-row sm:items-center sm:justify-between">
                <p>&copy; <?= e(date('Y')) ?> GameGrid. All rights reserved.</p>
                <p>GameGrid demo · Checkout and payment are simulated.</p>
            </div>
            <p class="mt-3 text-xs text-slate-400">Game discovery powered by <a href="https://rawg.io/" target="_blank" rel="noopener noreferrer" class="font-semibold text-violet-700 hover:text-violet-900">RAWG</a>.</p>
        </div>
    </footer>
</body>
</html>
