<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pendingUserId = filter_var($_SESSION['pending_mfa_user_id'] ?? null, FILTER_VALIDATE_INT);
$pendingAt = (int) ($_SESSION['pending_mfa_started_at'] ?? 0);
if (!$pendingUserId || $pendingUserId < 1 || $pendingAt < time() - 300) {
    unset(
        $_SESSION['pending_mfa_user_id'],
        $_SESSION['pending_mfa_started_at'],
        $_SESSION['pending_mfa_redirect'],
        $_SESSION['pending_mfa_attempts']
    );
    header('Location: login.php');
    exit;
}

$redirectTo = (string) ($_SESSION['pending_mfa_redirect'] ?? 'index.php');
$allowedRedirects = ['index.php', 'cart.php', 'checkout.php', 'orders.php', 'wishlist.php', 'admin.php'];
if (!in_array($redirectTo, $allowedRedirects, true)) {
    $redirectTo = 'index.php';
}

function decodeMfaSecret(string $secret): string
{
    $secret = strtoupper(preg_replace('/[\s=-]/', '', $secret) ?? '');
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bits = 0;
    $decoded = '';

    foreach (str_split($secret) as $character) {
        $value = strpos($alphabet, $character);
        if ($value === false) {
            return '';
        }
        $buffer = ($buffer << 5) | $value;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $decoded .= chr(($buffer >> $bits) & 0xff);
            $buffer &= (1 << $bits) - 1;
        }
    }

    return $decoded;
}

function validTotp(string $secret, string $code): bool
{
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $key = decodeMfaSecret($secret);
    if ($key === '') {
        return false;
    }

    $counter = intdiv(time(), 30);
    for ($offset = -1; $offset <= 1; $offset++) {
        $step = $counter + $offset;
        $message = pack('N2', intdiv($step, 0x100000000), $step % 0x100000000);
        $hash = hash_hmac('sha1', $message, $key, true);
        $position = ord($hash[19]) & 0x0f;
        $binaryCode = ((ord($hash[$position]) & 0x7f) << 24)
            | ((ord($hash[$position + 1]) & 0xff) << 16)
            | ((ord($hash[$position + 2]) & 0xff) << 8)
            | (ord($hash[$position + 3]) & 0xff);
        $expected = str_pad((string) ($binaryCode % 1000000), 6, '0', STR_PAD_LEFT);
        if (hash_equals($expected, $code)) {
            return true;
        }
    }

    return false;
}

$notice = '';
$noticeType = 'error';
$databaseError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attempts = (int) ($_SESSION['pending_mfa_attempts'] ?? 0);
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        $notice = 'Your request could not be verified. Please refresh the page and try again.';
    } elseif ($attempts >= 5) {
        unset(
            $_SESSION['pending_mfa_user_id'],
            $_SESSION['pending_mfa_started_at'],
            $_SESSION['pending_mfa_redirect'],
            $_SESSION['pending_mfa_attempts']
        );
        $notice = 'Too many verification attempts. Please sign in again.';
    } else {
        $_SESSION['pending_mfa_attempts'] = $attempts + 1;
        $code = trim((string) ($_POST['code'] ?? ''));
        try {
            $pdo = cloudcartPdo();
            $query = $pdo->prepare(
                'SELECT id, name, email, role, mfa_secret
                 FROM dbo.shopusers
                 WHERE id = :id AND mfa_enabled = 1'
            );
            $query->execute(['id' => $pendingUserId]);
            $user = $query->fetch();

            if ($user && is_string($user['mfa_secret']) && validTotp($user['mfa_secret'], $code)) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['name'] = (string) $user['name'];
                $_SESSION['email'] = (string) $user['email'];
                $_SESSION['role'] = (string) $user['role'];
                unset(
                    $_SESSION['pending_mfa_user_id'],
                    $_SESSION['pending_mfa_started_at'],
                    $_SESSION['pending_mfa_redirect'],
                    $_SESSION['pending_mfa_attempts']
                );
                header('Location: ' . $redirectTo);
                exit;
            }

            $notice = 'The verification code is incorrect or expired.';
        } catch (Throwable $exception) {
            error_log('CloudCart MFA verification error: ' . $exception->getMessage());
            http_response_code(500);
            $notice = 'Verification is temporarily unavailable. Please try again later.';
        }
    }
}

$pageTitle = 'Verify Sign In';
require_once __DIR__ . '/header.php';
?>
<section class="mx-auto max-w-md rounded-2xl border border-slate-200 bg-white p-7 shadow-sm sm:p-9">
    <div class="mb-7 text-center">
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">Two-step verification</h1>
        <p class="mt-2 text-sm text-slate-500">Enter the 6-digit code from your authenticator app.</p>
    </div>
    <?php if ($notice !== ''): ?>
        <div role="alert" class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?= e($notice) ?></div>
    <?php endif; ?>
    <form action="verify-mfa.php" method="post" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <div>
            <label for="code" class="mb-2 block text-sm font-semibold text-slate-700">Authenticator code</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus class="w-full rounded-lg border border-slate-300 px-3 py-3 text-center text-xl tracking-[0.4em] outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
        </div>
        <button type="submit" class="w-full rounded-lg bg-indigo-600 px-5 py-3 font-semibold text-white hover:bg-indigo-700">Verify and sign in</button>
    </form>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
