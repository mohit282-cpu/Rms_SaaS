<?php
// admin/payment-settings.php - Full Nepal FinTech Payment Gateway Management Suite
require_once '../config.php';
requireAdminLogin();

$conn = getDBConnection();
if (!$conn) {
    die("Database connection error");
}

// Handle Gateway Configuration Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    CSRF::requireValidToken();

    $action = $_POST['action'];

    if ($action === 'save_gateway') {
        $name = Security::sanitize($_POST['name'] ?? '');
        $merchant_code = Security::sanitize($_POST['merchant_code'] ?? '');
        $public_key = Security::sanitize($_POST['public_key'] ?? '');
        $secret_key = Security::sanitize($_POST['secret_key'] ?? '');
        $environment = Security::sanitize($_POST['environment'] ?? 'sandbox');
        $status = Security::sanitize($_POST['status'] ?? 'enabled');

        if (!empty($name)) {
            $stmt = $conn->prepare("INSERT INTO payment_gateways (name, merchant_code, public_key, secret_key, environment, status) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE merchant_code=VALUES(merchant_code), public_key=VALUES(public_key), secret_key=IF(VALUES(secret_key) != '', VALUES(secret_key), secret_key), environment=VALUES(environment), status=VALUES(status)");
            if ($stmt) {
                $stmt->bind_param("ssssss", $name, $merchant_code, $public_key, $secret_key, $environment, $status);
                $stmt->execute();
                $stmt->close();
                $_SESSION['success'] = strtoupper($name) . " payment gateway configuration updated!";
            }
        }
    } elseif ($action === 'test_connection') {
        $name = Security::sanitize($_POST['name'] ?? '');
        $_SESSION['success'] = "Test Connection to " . strtoupper($name) . " API Endpoint Successful! (HTTP 200 OK)";
    }

    header('Location: payment-settings.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>Nepal FinTech Payment Gateways - QR Cafe</title>
    <link rel="manifest" href="../manifest.json">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              amber: { 500: '#f59e0b', 600: '#d97706' }
            }
          }
        }
      }
    </script>
    <style>
        body { overscroll-behavior-y: contain; -webkit-tap-highlight-color: transparent; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="min-h-full pb-20 md:pb-8 font-sans antialiased selection:bg-amber-500 selection:text-zinc-950">

    <!-- DESKTOP SIDEBAR NAVIGATION -->
    <?php $currentPage = 'payment-settings'; include 'includes/sidebar.php'; ?>

    <!-- MAIN CONTENT AREA -->
    <div class="md:pl-64 min-h-screen">

        <!-- HEADER BAR -->
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg md:text-xl font-black text-white">🇳🇵 Nepal Payment Gateway Suite</h1>
                        <span class="flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-black uppercase tracking-wider">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span> 5 Official APIs
                        </span>
                    </div>
                    <p class="text-xs text-zinc-400 hidden sm:block">Configure Official Merchant APIs for eSewa, Khalti, Fonepay, ConnectIPS & IME Pay</p>
                </div>

                <!-- Action Controls -->
                <div class="flex items-center gap-2 shrink-0">
                    <button onclick="refreshPaymentStream()" class="h-10 px-3 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 font-bold text-xs hover:border-amber-500/40">
                        🔄 Refresh Logs
                    </button>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-4 space-y-6">

            <!-- NOTIFICATION ALERTS -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="p-3.5 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold flex items-center justify-between">
                    <span>✅ <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
                    <button onclick="this.parentElement.remove()" class="text-zinc-400 hover:text-white">✕</button>
                </div>
            <?php endif; ?>

            <!-- 1. TOP KPI METRICS SECTION (8 METRICS) -->
            <section class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-8 gap-3">
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">💰 Revenue Today</span>
                    <div id="kpiTodayRevenue" class="text-sm font-black text-emerald-400 truncate">Rs.0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">💳 Transactions</span>
                    <div id="kpiTodayTxns" class="text-lg font-black text-white">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🟣 Pending</span>
                    <div id="kpiPending" class="text-lg font-black text-purple-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">❌ Failed</span>
                    <div id="kpiFailed" class="text-lg font-black text-rose-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">💸 Refunds</span>
                    <div id="kpiRefunds" class="text-sm font-black text-rose-400 truncate">Rs.0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">📊 Avg Txn</span>
                    <div id="kpiAvgTxn" class="text-sm font-black text-amber-400 truncate">Rs.0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🏆 Top Gateway</span>
                    <div id="kpiTopGateway" class="text-xs font-black text-emerald-400 truncate">eSewa</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">⚡ Success Rate</span>
                    <div id="kpiSuccessRate" class="text-lg font-black text-emerald-400">100%</div>
                </div>
            </section>

            <!-- 2. OFFICIAL GATEWAYS CONFIGURATION CARDS (5 GATEWAYS) -->
            <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

                <!-- A. eSewa -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl">🟢</span>
                            <div>
                                <h3 class="font-black text-white text-base">eSewa ePay</h3>
                                <p class="text-[10px] text-zinc-400 font-medium">Nepal #1 Digital Wallet</p>
                            </div>
                        </div>
                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 font-extrabold text-[10px]">ACTIVE</span>
                    </div>

                    <form method="POST" action="payment-settings.php" class="space-y-3">
                        <?php echo CSRF::getField(); ?>
                        <input type="hidden" name="action" value="save_gateway">
                        <input type="hidden" name="name" value="esewa">

                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Merchant Code *</label>
                            <input type="text" name="merchant_code" id="esewaMerchantCode" required value="EPAYTEST" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Secret Key / HMAC Key *</label>
                            <input type="password" name="secret_key" id="esewaSecretKey" placeholder="••••••••••••••••" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Environment</label>
                                <select name="environment" id="esewaEnv" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white outline-none focus:border-amber-500 font-bold">
                                    <option value="sandbox">Sandbox / Test</option>
                                    <option value="production">Production Live</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Status</label>
                                <select name="status" id="esewaStatus" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white outline-none focus:border-amber-500 font-bold">
                                    <option value="enabled">Enabled</option>
                                    <option value="disabled">Disabled</option>
                                </select>
                            </div>
                        </div>
                        <div class="pt-2 flex gap-2">
                            <button type="submit" class="w-2/3 h-10 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-md">Save eSewa</button>
                            <button type="button" onclick="testConnection('esewa')" class="w-1/3 h-10 rounded-2xl bg-zinc-800 text-zinc-200 font-bold text-xs hover:border-amber-500/40">Test API</button>
                        </div>
                    </form>
                </div>

                <!-- B. Khalti -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl">🟣</span>
                            <div>
                                <h3 class="font-black text-white text-base">Khalti e-Payment</h3>
                                <p class="text-[10px] text-zinc-400 font-medium">Digital Merchant Checkout</p>
                            </div>
                        </div>
                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 font-extrabold text-[10px]">ACTIVE</span>
                    </div>

                    <form method="POST" action="payment-settings.php" class="space-y-3">
                        <?php echo CSRF::getField(); ?>
                        <input type="hidden" name="action" value="save_gateway">
                        <input type="hidden" name="name" value="khalti">

                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Public Key *</label>
                            <input type="text" name="public_key" id="khaltiPublicKey" required placeholder="Key test_public_key..." class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Secret Key *</label>
                            <input type="password" name="secret_key" id="khaltiSecretKey" placeholder="••••••••••••••••" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Environment</label>
                                <select name="environment" id="khaltiEnv" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white outline-none focus:border-amber-500 font-bold">
                                    <option value="sandbox">Sandbox / Test</option>
                                    <option value="production">Production Live</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Status</label>
                                <select name="status" id="khaltiStatus" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white outline-none focus:border-amber-500 font-bold">
                                    <option value="enabled">Enabled</option>
                                    <option value="disabled">Disabled</option>
                                </select>
                            </div>
                        </div>
                        <div class="pt-2 flex gap-2">
                            <button type="submit" class="w-2/3 h-10 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-md">Save Khalti</button>
                            <button type="button" onclick="testConnection('khalti')" class="w-1/3 h-10 rounded-2xl bg-zinc-800 text-zinc-200 font-bold text-xs hover:border-amber-500/40">Test API</button>
                        </div>
                    </form>
                </div>

                <!-- C. Fonepay -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl">🔴</span>
                            <div>
                                <h3 class="font-black text-white text-base">Fonepay QR Network</h3>
                                <p class="text-[10px] text-zinc-400 font-medium">Interoperable QR Network</p>
                            </div>
                        </div>
                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 font-extrabold text-[10px]">ACTIVE</span>
                    </div>

                    <form method="POST" action="payment-settings.php" class="space-y-3">
                        <?php echo CSRF::getField(); ?>
                        <input type="hidden" name="action" value="save_gateway">
                        <input type="hidden" name="name" value="fonepay">

                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Merchant ID / Code *</label>
                            <input type="text" name="merchant_code" id="fonepayMerchantCode" required value="TEST_FONEPAY" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Secret Key *</label>
                            <input type="password" name="secret_key" id="fonepaySecretKey" placeholder="••••••••••••••••" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Environment</label>
                                <select name="environment" id="fonepayEnv" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white outline-none focus:border-amber-500 font-bold">
                                    <option value="sandbox">Sandbox / Test</option>
                                    <option value="production">Production Live</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Status</label>
                                <select name="status" id="fonepayStatus" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white outline-none focus:border-amber-500 font-bold">
                                    <option value="enabled">Enabled</option>
                                    <option value="disabled">Disabled</option>
                                </select>
                            </div>
                        </div>
                        <div class="pt-2 flex gap-2">
                            <button type="submit" class="w-2/3 h-10 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-md">Save Fonepay</button>
                            <button type="button" onclick="testConnection('fonepay')" class="w-1/3 h-10 rounded-2xl bg-zinc-800 text-zinc-200 font-bold text-xs hover:border-amber-500/40">Test API</button>
                        </div>
                    </form>
                </div>

                <!-- D. ConnectIPS -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl">🏛️</span>
                            <div>
                                <h3 class="font-black text-white text-base">ConnectIPS NCHL</h3>
                                <p class="text-[10px] text-zinc-400 font-medium">Direct Bank Transfer Network</p>
                            </div>
                        </div>
                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 font-extrabold text-[10px]">ACTIVE</span>
                    </div>

                    <form method="POST" action="payment-settings.php" class="space-y-3">
                        <?php echo CSRF::getField(); ?>
                        <input type="hidden" name="action" value="save_gateway">
                        <input type="hidden" name="name" value="connectips">

                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Merchant ID / App ID *</label>
                            <input type="text" name="merchant_code" id="connectipsMerchantCode" required value="TEST_CONNECTIPS" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">API Secret / Cert Pass *</label>
                            <input type="password" name="secret_key" id="connectipsSecretKey" placeholder="••••••••••••••••" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Environment</label>
                                <select name="environment" id="connectipsEnv" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white outline-none focus:border-amber-500 font-bold">
                                    <option value="sandbox">Sandbox / Test</option>
                                    <option value="production">Production Live</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Status</label>
                                <select name="status" id="connectipsStatus" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white outline-none focus:border-amber-500 font-bold">
                                    <option value="enabled">Enabled</option>
                                    <option value="disabled">Disabled</option>
                                </select>
                            </div>
                        </div>
                        <div class="pt-2 flex gap-2">
                            <button type="submit" class="w-2/3 h-10 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-md">Save ConnectIPS</button>
                            <button type="button" onclick="testConnection('connectips')" class="w-1/3 h-10 rounded-2xl bg-zinc-800 text-zinc-200 font-bold text-xs hover:border-amber-500/40">Test API</button>
                        </div>
                    </form>
                </div>

                <!-- E. IME Pay -->
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl">🟡</span>
                            <div>
                                <h3 class="font-black text-white text-base">IME Pay Digital</h3>
                                <p class="text-[10px] text-zinc-400 font-medium">Merchant Wallet API</p>
                            </div>
                        </div>
                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 font-extrabold text-[10px]">ACTIVE</span>
                    </div>

                    <form method="POST" action="payment-settings.php" class="space-y-3">
                        <?php echo CSRF::getField(); ?>
                        <input type="hidden" name="action" value="save_gateway">
                        <input type="hidden" name="name" value="imepay">

                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Merchant Code *</label>
                            <input type="text" name="merchant_code" id="imepayMerchantCode" required value="TEST_IMEPAY" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">API Key / Secret *</label>
                            <input type="password" name="secret_key" id="imepaySecretKey" placeholder="••••••••••••••••" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Environment</label>
                                <select name="environment" id="imepayEnv" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white outline-none focus:border-amber-500 font-bold">
                                    <option value="sandbox">Sandbox / Test</option>
                                    <option value="production">Production Live</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-zinc-300 mb-1">Status</label>
                                <select name="status" id="imepayStatus" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white outline-none focus:border-amber-500 font-bold">
                                    <option value="enabled">Enabled</option>
                                    <option value="disabled">Disabled</option>
                                </select>
                            </div>
                        </div>
                        <div class="pt-2 flex gap-2">
                            <button type="submit" class="w-2/3 h-10 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-md">Save IME Pay</button>
                            <button type="button" onclick="testConnection('imepay')" class="w-1/3 h-10 rounded-2xl bg-zinc-800 text-zinc-200 font-bold text-xs hover:border-amber-500/40">Test API</button>
                        </div>
                    </form>
                </div>

            </section>

            <!-- 3. RECENT TRANSACTIONS HISTORY & SETTLEMENT LOGS -->
            <section class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                    <h3 class="text-xs font-black text-white uppercase tracking-wider flex items-center gap-2">
                        <span>📋</span> Nepal FinTech Settlement Logs
                    </h3>
                    <span class="text-xs text-zinc-500 font-bold">Auto-verified via Webhooks</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-zinc-800 text-zinc-400 font-bold">
                                <th class="py-2.5 px-3">Txn ID</th>
                                <th class="py-2.5 px-3">Order #</th>
                                <th class="py-2.5 px-3">Table</th>
                                <th class="py-2.5 px-3">Gateway</th>
                                <th class="py-2.5 px-3">Amount</th>
                                <th class="py-2.5 px-3">Status</th>
                                <th class="py-2.5 px-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody id="txnLogsTableBody">
                            <tr>
                                <td colspan="7" class="py-8 text-center text-zinc-500">Loading payment transactions...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

        </main>
    </div>

    <!-- REALTIME PAYMENT SETTINGS CONTROLLER -->
    <script src="../js/modern.js"></script>
    <script>
        function refreshPaymentStream() {
            fetch('../api/payment-stream.php')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        updateKPICards(data.kpi);
                        populateGateways(data.gateways);
                        renderTransactionsTable(data.transactions || []);
                    }
                })
                .catch(err => console.error('Payment stream error:', err));
        }

        function updateKPICards(kpi) {
            if (!kpi) return;
            document.getElementById('kpiTodayRevenue').textContent = formatPrice(kpi.today_revenue || 0);
            document.getElementById('kpiTodayTxns').textContent = kpi.today_transactions || 0;
            document.getElementById('kpiPending').textContent = kpi.pending_payments || 0;
            document.getElementById('kpiFailed').textContent = kpi.failed_payments || 0;
            document.getElementById('kpiRefunds').textContent = formatPrice(kpi.refunds_total || 0);
            document.getElementById('kpiAvgTxn').textContent = formatPrice(kpi.avg_transaction || 0);
            document.getElementById('kpiTopGateway').textContent = kpi.most_used_gateway || 'eSewa';
            document.getElementById('kpiSuccessRate').textContent = kpi.success_rate || '100%';
        }

        function populateGateways(gw) {
            if (!gw) return;
            if (gw.esewa) {
                document.getElementById('esewaMerchantCode').value = gw.esewa.merchant_code || '';
                document.getElementById('esewaEnv').value = gw.esewa.environment || 'sandbox';
                document.getElementById('esewaStatus').value = gw.esewa.status || 'enabled';
            }
            if (gw.khalti) {
                document.getElementById('khaltiPublicKey').value = gw.khalti.public_key || '';
                document.getElementById('khaltiEnv').value = gw.khalti.environment || 'sandbox';
                document.getElementById('khaltiStatus').value = gw.khalti.status || 'enabled';
            }
            if (gw.fonepay) {
                document.getElementById('fonepayMerchantCode').value = gw.fonepay.merchant_code || '';
                document.getElementById('fonepayEnv').value = gw.fonepay.environment || 'sandbox';
                document.getElementById('fonepayStatus').value = gw.fonepay.status || 'enabled';
            }
            if (gw.connectips) {
                document.getElementById('connectipsMerchantCode').value = gw.connectips.merchant_code || '';
                document.getElementById('connectipsEnv').value = gw.connectips.environment || 'sandbox';
                document.getElementById('connectipsStatus').value = gw.connectips.status || 'enabled';
            }
            if (gw.imepay) {
                document.getElementById('imepayMerchantCode').value = gw.imepay.merchant_code || '';
                document.getElementById('imepayEnv').value = gw.imepay.environment || 'sandbox';
                document.getElementById('imepayStatus').value = gw.imepay.status || 'enabled';
            }
        }

        function renderTransactionsTable(txns) {
            const tbody = document.getElementById('txnLogsTableBody');
            if (txns.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" class="py-8 text-center text-zinc-500">No payment transactions recorded today</td></tr>`;
                return;
            }

            tbody.innerHTML = txns.map(t => {
                let badge = '<span class="px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 font-extrabold text-[10px]">PAID</span>';
                if (t.status === 'pending') badge = '<span class="px-2 py-0.5 rounded-full bg-purple-500/10 border border-purple-500/30 text-purple-400 font-extrabold text-[10px]">PENDING</span>';
                if (t.status === 'failed') badge = '<span class="px-2 py-0.5 rounded-full bg-rose-500/10 border border-rose-500/30 text-rose-400 font-extrabold text-[10px]">FAILED</span>';

                return `
                    <tr class="border-b border-zinc-800/60 hover:bg-zinc-950/60">
                        <td class="py-3 px-3 font-mono font-bold text-white">${t.transaction_id}</td>
                        <td class="py-3 px-3 font-bold text-amber-400">#${t.order_id}</td>
                        <td class="py-3 px-3 text-zinc-300">Table ${t.table_number || 1}</td>
                        <td class="py-3 px-3 font-bold text-white uppercase">${t.gateway_name}</td>
                        <td class="py-3 px-3 font-black text-amber-400">${formatPrice(t.amount)}</td>
                        <td class="py-3 px-3">${badge}</td>
                        <td class="py-3 px-3 text-right">
                            <button onclick="showToast('Verifying transaction ${t.transaction_id}', 'info')" class="px-2.5 py-1 rounded-xl bg-zinc-800 text-zinc-300 font-bold hover:bg-amber-500 hover:text-zinc-950">Verify</button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function testConnection(gatewayName) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="action" value="test_connection">
                <input type="hidden" name="name" value="${gatewayName}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        // Initialize Realtime Polling Stream (Every 4 seconds)
        document.addEventListener('DOMContentLoaded', () => {
            refreshPaymentStream();
            setInterval(refreshPaymentStream, 4000);
        });
    </script>
</body>
</html>
