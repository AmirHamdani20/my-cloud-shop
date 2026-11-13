<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pageTitle = 'Registration Successful';
require_once __DIR__ . '/header.php';
?>
<section class="mx-auto max-w-3xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl">
    <div class="bg-gradient-to-br from-slate-950 via-indigo-950 to-slate-900 px-6 py-10 text-center text-white sm:px-12 sm:py-14">
        <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-400 text-3xl font-bold text-slate-950 shadow-lg shadow-emerald-500/20" aria-hidden="true">✓</span>
        <p class="mt-6 text-xs font-bold uppercase tracking-[0.2em] text-cyan-300">You’re all set</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">Welcome to CloudCart.</h1>
        <p class="mx-auto mt-3 max-w-lg text-sm leading-6 text-slate-300">Your account is ready. Sign in to keep track of your wishlist and orders.</p>
    </div>
    <div class="flex flex-wrap justify-center gap-3 p-6 sm:p-9">
        <a href="login.php" class="inline-flex rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white shadow-sm transition hover:bg-indigo-700">Sign in</a>
        <a href="index.php" class="inline-flex rounded-xl border border-slate-200 px-5 py-3 font-semibold text-slate-700 transition hover:border-indigo-200 hover:text-indigo-700">Explore the shop</a>
    </div>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
