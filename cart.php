<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>Your Cart - QR Cafe</title>
    <link rel="manifest" href="manifest.json">
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
    </style>
</head>
<body class="min-h-full pb-24 font-sans antialiased selection:bg-amber-500 selection:text-zinc-950">

    <?php
    $table_num = isset($_GET['table']) ? htmlspecialchars($_GET['table']) : '1';
    ?>

    <!-- Sticky Mobile Header -->
    <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 py-3.5">
        <div class="max-w-md mx-auto flex items-center justify-between gap-3">
            <a href="menu.php?table=<?php echo urlencode($table_num); ?>" class="flex items-center gap-2 text-lg font-black text-white">
                <span>☕</span> QR Cafe & Dining
            </a>
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-400 font-bold text-xs">
                📍 Table <?php echo $table_num; ?>
            </span>
        </div>
    </header>

    <main class="max-w-md mx-auto px-4 pt-4">
        <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 shadow-2xl">
            <h1 class="text-xl font-black text-white mb-4 flex items-center gap-2">
                <span>🛒</span> Your Cart Summary
            </h1>

            <div id="cartItemsContainer" class="space-y-3 mb-4">
                <!-- Rendered dynamically by JS -->
            </div>

            <div id="emptyCartView" style="display: none;" class="text-center py-10 space-y-3">
                <div class="text-5xl">🛒</div>
                <h3 class="text-base font-extrabold text-white">Your cart is empty</h3>
                <p class="text-xs text-zinc-400">Add some gourmet items from our menu to get started.</p>
                <a href="menu.php?table=<?php echo urlencode($table_num); ?>" class="h-12 w-full rounded-2xl bg-amber-500 text-zinc-950 font-black text-sm flex items-center justify-center active:scale-95 shadow-lg shadow-amber-500/20">
                    Browse Menu
                </a>
            </div>

            <div id="cartSummaryView" class="pt-4 border-t border-zinc-800">
                <div class="flex justify-between items-center mb-4 font-black text-lg">
                    <span class="text-zinc-400">Total Amount:</span>
                    <span id="pageCartTotal" class="text-amber-400 text-2xl">Rs. 0.00</span>
                </div>

                <a href="checkout.php?table=<?php echo urlencode($table_num); ?>" id="pageCheckoutBtn" class="h-12 w-full rounded-2xl bg-gradient-to-r from-amber-500 to-amber-600 text-zinc-950 font-black text-sm flex items-center justify-center active:scale-95 shadow-lg shadow-amber-500/20">
                    Proceed to Checkout →
                </a>
            </div>
        </div>
    </main>

    <!-- Fixed Bottom Tab Bar -->
    <nav class="fixed bottom-0 left-0 right-0 z-40 max-w-md mx-auto bg-zinc-950/95 backdrop-blur-xl border-t border-zinc-800/80 flex justify-around items-center h-16 rounded-t-2xl px-2">
        <a href="menu.php?table=<?php echo urlencode($table_num); ?>" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">🍽️</span>
            <span>Menu</span>
        </a>
        <a href="cart.php?table=<?php echo urlencode($table_num); ?>" class="flex flex-col items-center gap-0.5 text-amber-500 font-extrabold text-[10px]">
            <span class="text-lg">🛒</span>
            <span>Cart</span>
            <span class="cart-badge absolute top-1 right-2 bg-amber-500 text-zinc-950 font-black text-[9px] w-4 h-4 rounded-full flex items-center justify-center" style="display: none;">0</span>
        </a>
        <button onclick="callWaiter()" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">🔔</span>
            <span>Waiter</span>
        </button>
        <a href="order-success.php?table=<?php echo urlencode($table_num); ?>" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">📋</span>
            <span>Tracker</span>
        </a>
    </nav>

    <script src="js/modern.js"></script>
    <script>
        function renderCartPage() {
            const container = document.getElementById('cartItemsContainer');
            const emptyView = document.getElementById('emptyCartView');
            const summaryView = document.getElementById('cartSummaryView');
            const totalEl = document.getElementById('pageCartTotal');

            if (!container) return;

            if (cart.length === 0) {
                container.innerHTML = '';
                if (emptyView) emptyView.style.display = 'block';
                if (summaryView) summaryView.style.display = 'none';
                return;
            }

            if (emptyView) emptyView.style.display = 'none';
            if (summaryView) summaryView.style.display = 'block';

            container.innerHTML = cart.map(item => {
                let customText = '';
                if (item.customizations) {
                    if (item.customizations.spice_level) customText += `🌶️ ${item.customizations.spice_level}`;
                    if (item.customizations.extras && item.customizations.extras.length > 0) {
                        const extraNames = item.customizations.extras.map(e => e.name).join(', ');
                        customText += ` | + ${extraNames}`;
                    }
                }

                return `
                    <div class="bg-zinc-950/80 border border-zinc-800/60 rounded-2xl p-3 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-zinc-900 flex items-center justify-center text-xl shrink-0">🍽️</div>
                            <div>
                                <div class="font-extrabold text-xs text-white">${item.name}</div>
                                ${customText ? `<div class="text-[10px] text-zinc-400">${customText}</div>` : ''}
                                <div class="text-xs font-black text-amber-400 mt-0.5">${formatPrice(item.price * item.quantity)}</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="flex items-center gap-1.5 bg-zinc-900 border border-zinc-800 rounded-xl p-1">
                                <button class="w-6 h-6 rounded-lg bg-zinc-800 text-white font-black text-xs flex items-center justify-center active:bg-amber-500 active:text-zinc-950" onclick="updateQuantity(${item.id}, -1, ${JSON.stringify(item.customizations || {}).replace(/"/g, '&quot;')})">−</button>
                                <span class="text-xs font-black text-white w-4 text-center">${item.quantity}</span>
                                <button class="w-6 h-6 rounded-lg bg-zinc-800 text-white font-black text-xs flex items-center justify-center active:bg-amber-500 active:text-zinc-950" onclick="updateQuantity(${item.id}, 1, ${JSON.stringify(item.customizations || {}).replace(/"/g, '&quot;')})">+</button>
                            </div>
                            <button class="text-xs text-rose-400 font-bold px-1" onclick="removeFromCart(${item.id}, ${JSON.stringify(item.customizations || {}).replace(/"/g, '&quot;')})">✕</button>
                        </div>
                    </div>
                `;
            }).join('');

            if (totalEl) totalEl.textContent = formatPrice(getCartTotal());
        }

        document.addEventListener('DOMContentLoaded', renderCartPage);
    </script>
</body>
</html>
