<?php
// Admin Order Details & Status Manager - Responsive Adaptive Architecture
require_once '../config.php';
requireAdminLogin();

$conn = getDBConnection();
if (!$conn) die("Database connection failed");

$tenantId = (int)($_SESSION['restaurant_id'] ?? 0);

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($order_id <= 0) {
    header('Location: orders.php');
    exit;
}

// Fetch Order Header
$stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND restaurant_id = ?");
$stmt->bind_param("ii", $order_id, $tenantId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    header('Location: orders.php');
    exit;
}

// Fetch Order Items
$items_stmt = $conn->prepare("SELECT oi.*, mi.name as item_name, mi.image FROM order_items oi LEFT JOIN menu_items mi ON oi.menu_item_id = mi.id WHERE oi.order_id = ?");
$items_stmt->bind_param("i", $order_id);
$items_stmt->execute();
$items_res = $items_stmt->get_result();
$order_items = [];
$calculated_total = 0;

while ($row = $items_res->fetch_assoc()) {
    $order_items[] = $row;
    $calculated_total += ($row['quantity'] * $row['price']);
}
$items_stmt->close();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>Order #<?php echo $order_id; ?> Details - QR Cafe</title>
    <link rel="manifest" href="../manifest.json">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              amber: {
                500: '#f59e0b',
                600: '#d97706',
              }
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
<body class="min-h-full font-sans antialiased selection:bg-amber-500 selection:text-zinc-950">

    <!-- DESKTOP SIDEBAR NAVIGATION -->
    <?php $currentPage = 'orders'; include 'includes/sidebar.php'; ?>

    <!-- MAIN CONTENT AREA -->
    <div class="md:pl-64 min-h-screen pb-24 md:pb-8">

        <!-- Header -->
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-4">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-2">
                <div class="flex items-center gap-3">
                    <a href="orders.php" class="text-amber-400 font-extrabold text-xs">← Back</a>
                    <h1 class="text-base md:text-xl font-black text-white">Order #<?php echo $order_id; ?> Details</h1>
                </div>
                <span class="px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 font-black text-xs">
                    📍 Table <?php echo htmlspecialchars($order['table_number']); ?>
                </span>
            </div>
        </header>

        <main class="max-w-4xl mx-auto px-4 md:px-8 pt-4 space-y-6">

            <!-- Status Control Header Card -->
            <section class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-zinc-800">
                    <div>
                        <div class="text-xs text-zinc-400 font-bold">Placed Time</div>
                        <div class="text-sm font-extrabold text-white"><?php echo htmlspecialchars($order['created_at']); ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-400 font-bold">Current Status</div>
                        <span id="currentStatusBadge" class="px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 font-black text-xs uppercase inline-block mt-0.5">
                            <?php echo htmlspecialchars($order['status']); ?>
                        </span>
                    </div>
                </div>

                <!-- Update Status Buttons -->
                <div>
                    <h4 class="text-xs font-extrabold text-zinc-400 uppercase tracking-wider mb-2">Update Order Status</h4>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <button onclick="changeOrderStatus(<?php echo $order_id; ?>, 'new')" class="h-11 rounded-2xl bg-zinc-950 border border-zinc-800 text-zinc-300 font-bold text-xs hover:border-amber-500 active:scale-95">🆕 New</button>
                        <button onclick="changeOrderStatus(<?php echo $order_id; ?>, 'preparing')" class="h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-md">🔥 In Prep</button>
                        <button onclick="changeOrderStatus(<?php echo $order_id; ?>, 'ready')" class="h-11 rounded-2xl bg-emerald-500 text-zinc-950 font-black text-xs active:scale-95 shadow-md">✅ Ready</button>
                        <button onclick="changeOrderStatus(<?php echo $order_id; ?>, 'completed')" class="h-11 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-600 text-zinc-950 font-black text-xs active:scale-95 shadow-md">✔ Served</button>
                    </div>
                </div>
            </section>

            <!-- Order Items Breakdown List -->
            <section class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-xl space-y-4">
                <h3 class="text-base font-black text-white flex items-center gap-2">
                    <span>🍽️</span> Ordered Items Breakdown
                </h3>

                <div class="space-y-3">
                    <?php foreach ($order_items as $item): ?>
                        <div class="bg-zinc-950/70 border border-zinc-800/60 rounded-2xl p-3.5 flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-zinc-900 border border-zinc-800 flex items-center justify-center text-lg">
                                    🍽️
                                </div>
                                <div>
                                    <div class="font-black text-sm text-white"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                    <div class="text-xs text-zinc-400">Rs. <?php echo number_format($item['price'], 0); ?> x <?php echo $item['quantity']; ?></div>
                                </div>
                            </div>
                            <div class="font-black text-sm text-amber-400">
                                Rs. <?php echo number_format($item['price'] * $item['quantity'], 0); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="pt-4 border-t border-zinc-800 flex justify-between items-center text-base font-black">
                    <span class="text-zinc-400">Total Order Amount:</span>
                    <span class="text-amber-400 text-xl">Rs. <?php echo number_format($calculated_total, 2); ?></span>
                </div>
            </section>

        </main>
    </div>

    <!-- Mobile Bottom Navigation Bar (Hidden on md: desktop) -->
    <nav class="md:hidden fixed bottom-0 left-0 right-0 z-40 max-w-md mx-auto bg-zinc-950/95 backdrop-blur-xl border-t border-zinc-800/80 flex justify-around items-center h-16 rounded-t-2xl px-2">
        <a href="index.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">📊</span>
            <span>Summary</span>
        </a>
        <a href="orders.php" class="flex flex-col items-center gap-0.5 text-amber-500 font-extrabold text-[10px]">
            <span class="text-lg">📋</span>
            <span>Orders</span>
        </a>
        <a href="menu-items.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">🍔</span>
            <span>Items</span>
        </a>
        <a href="tables.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">📍</span>
            <span>Tables</span>
        </a>
    </nav>

    <script src="../js/modern.js"></script>
    <script>
        function changeOrderStatus(orderId, status) {
            fetch('../api/update-order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: orderId, status: status })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('currentStatusBadge').textContent = status.toUpperCase();
                    showToast(`Order #${orderId} updated to ${status}`, 'success');
                } else {
                    showToast(data.message || 'Failed to update order status', 'error');
                }
            })
            .catch(err => console.error(err));
        }
    </script>
</body>
</html>
