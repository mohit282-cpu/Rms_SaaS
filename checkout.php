<?php
// Checkout Page - Order Review & Confirmation
require_once 'config.php';

if (!isset($_SESSION['customer_table_id']) || empty($_SESSION['customer_table_id'])) {
    http_response_code(403);
    die('<!DOCTYPE html><html lang="en" class="h-full bg-zinc-950 text-white"><head><meta charset="UTF-8"><title>403 Session Expired</title><script src="https://cdn.tailwindcss.com"></script></head><body class="h-full flex items-center justify-center p-4 text-center"><div class="max-w-md bg-zinc-900 border border-zinc-800 p-8 rounded-3xl space-y-4"><div class="text-5xl">🔒</div><h1 class="text-xl font-black text-white">Session Expired</h1><p class="text-xs text-zinc-400">Please scan the table QR code again to access checkout.</p></div></body></html>');
}

$table_num = strval($_SESSION['customer_table_id']);
$conn = getDBConnection();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>Review & Place Order - QR Cafe</title>
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
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="min-h-full pb-24 lg:pb-12 font-sans antialiased selection:bg-amber-500 selection:text-zinc-950">

    <!-- Top Header -->
    <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 py-3.5">
        <div class="max-w-4xl mx-auto flex items-center justify-between gap-3">
            <a href="menu.php" class="flex items-center gap-2 font-extrabold text-xs text-amber-400">
                <span>← Back to Menu</span>
            </a>
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-400 font-bold text-xs">
                <span>📍</span> Table <?php echo $table_num; ?>
            </span>
        </div>
    </header>

    <!-- Main Adaptive Container -->
    <main class="max-w-4xl mx-auto px-4 pt-4 space-y-6">

        <div class="text-center md:text-left">
            <h1 class="text-xl md:text-2xl font-black text-white">Review Your Order</h1>
            <p class="text-xs text-zinc-400">Verify items before sending to the kitchen for Table <?php echo $table_num; ?></p>
        </div>

        <!-- 2-Column Responsive Grid on Desktop -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">

            <!-- Left Side: Order Items Breakdown -->
            <section class="bg-zinc-900/90 border border-zinc-800/80 rounded-3xl p-5 shadow-2xl space-y-4">
                <h3 class="text-sm font-black text-white flex items-center gap-2 border-b border-zinc-800 pb-3">
                    <span>🛒</span> Selected Order Items
                </h3>

                <div id="checkoutItemsList" class="cart-items space-y-3 max-h-[350px] overflow-y-auto pr-1">
                    <!-- Rendered dynamically by JS -->
                </div>

                <div class="pt-3 border-t border-zinc-800 space-y-2">
                    <div class="flex justify-between items-center text-sm font-bold text-zinc-400">
                        <span>Subtotal:</span>
                        <span id="checkoutSubtotal" class="text-white">Rs. 0.00</span>
                    </div>
                    <div class="flex justify-between items-center text-sm font-bold text-zinc-400">
                        <span>Taxes & Service Charge:</span>
                        <span class="text-emerald-400 font-extrabold">INCLUDED</span>
                    </div>
                    <div class="flex justify-between items-center text-base font-black pt-2 border-t border-zinc-800/60">
                        <span class="text-zinc-200">Total Amount:</span>
                        <span id="cartTotal" class="text-amber-400 text-xl">Rs. 0.00</span>
                    </div>
                </div>
            </section>

            <!-- Right Side: Special Requests & Send Order -->
            <section class="bg-zinc-900/90 border border-zinc-800/80 rounded-3xl p-5 shadow-2xl space-y-5">
                <h3 class="text-sm font-black text-white flex items-center gap-2 border-b border-zinc-800 pb-3">
                    <span>📝</span> Special Cooking Instructions
                </h3>

                <div>
                    <label class="block text-xs font-bold text-zinc-300 mb-1.5">Cooking Notes / Allergy Requests (Optional)</label>
                    <textarea id="orderNotes" rows="3" placeholder="e.g. Less sugar in coffee, extra crispy fries, no onion..." class="w-full bg-zinc-950 border border-zinc-800 rounded-2xl p-3.5 text-xs text-white placeholder-zinc-500 outline-none focus:border-amber-500 resize-none"></textarea>
                </div>

                <div class="bg-amber-500/10 border border-amber-500/20 p-3.5 rounded-2xl text-xs text-amber-300 space-y-1">
                    <div class="font-extrabold flex items-center gap-1.5">
                        <span>⚡</span> Fast Order Delivery
                    </div>
                    <p class="text-[11px] text-zinc-400">Once confirmed, your order tickets will immediately print in the kitchen. You can choose payment options after receiving your food.</p>
                </div>

                <!-- Confirm Order Button -->
                <button id="confirmOrderBtn" onclick="submitOrder()" class="h-14 w-full rounded-2xl bg-gradient-to-r from-amber-500 to-amber-600 text-zinc-950 font-black text-sm active:scale-95 shadow-xl shadow-amber-500/20 flex items-center justify-center gap-2">
                    <span>🚀 Place Order & Send to Kitchen</span>
                </button>
            </section>

        </div>

    </main>

    <script src="js/modern.js"></script>
    <script>
        function submitOrder() {
            const currentCart = JSON.parse(localStorage.getItem('cart')) || (typeof cart !== 'undefined' ? cart : []);
            if (!currentCart || currentCart.length === 0) {
                showToast('Your cart is empty', 'warning');
                return;
            }

            const btn = document.getElementById('confirmOrderBtn');
            if (btn) {
                btn.disabled = true;
                btn.classList.add('opacity-50', 'pointer-events-none');
                btn.innerHTML = `<span>⏳ Sending Order...</span>`;
            }

            const notes = document.getElementById('orderNotes')?.value.trim() || '';

            fetch('place-order.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?php echo CSRF::generateToken(); ?>'
                },
                body: JSON.stringify({
                    csrf_token: '<?php echo CSRF::generateToken(); ?>',
                    payment_method: 'pending',
                    notes: notes,
                    cart: currentCart,
                    items: currentCart
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showOrderPlacedTickModal(data.order, `order-success.php?order_id=${data.order.id}`);
                } else {
                    showToast(data.message || 'Failed to place order', 'error');
                    if (btn) {
                        btn.disabled = false;
                        btn.classList.remove('opacity-50', 'pointer-events-none');
                        btn.innerHTML = `<span>🚀 Place Order & Send to Kitchen</span>`;
                    }
                }
            })
            .catch(err => {
                console.error(err);
                showToast('Network error while placing order', 'error');
                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('opacity-50', 'pointer-events-none');
                    btn.innerHTML = `<span>🚀 Place Order & Send to Kitchen</span>`;
                }
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            updateCartPanel();
            const subtotalEl = document.getElementById('checkoutSubtotal');
            if (subtotalEl) subtotalEl.textContent = formatPrice(getCartTotal());
        });
    </script>
</body>
</html>
