<?php
require_once 'config.php';
requireKitchenLogin();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>Today's Menu Catalog - Kitchen View</title>
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
<body class="min-h-full pb-24 md:pb-8 font-sans antialiased selection:bg-amber-500 selection:text-zinc-950">

    <?php
    require_once 'config.php';
    $conn = getDBConnection();
    $db_error = ($conn === null);
    ?>

    <!-- Sticky Header -->
    <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
        <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 font-black text-lg text-white">
                <span class="text-2xl">📋</span>
                <div>
                    <h1 class="text-base md:text-lg font-black leading-tight">Today's Kitchen Menu Catalog</h1>
                    <p class="text-[10px] text-zinc-400 font-medium hidden sm:block">Read-Only Dish Reference & Stock Status</p>
                </div>
            </div>
            <a href="kitchen-dashboard.php" class="px-4 py-2 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                👨‍🍳 KDS Monitor →
            </a>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 md:px-8 pt-4 space-y-4">
        
        <!-- Search & Filter Bar -->
        <div class="relative max-w-md">
            <input type="text" id="searchInput" placeholder="Search menu items or categories..." class="w-full bg-zinc-900 border border-zinc-800 rounded-2xl py-3 pl-11 pr-4 text-sm text-zinc-100 placeholder-zinc-500 focus:outline-none focus:border-amber-500 transition-all">
            <span class="absolute left-4 top-3.5 text-zinc-500 text-sm">🔍</span>
        </div>

        <!-- Adaptive Responsive Grid (1 col mobile, 2 cols tablet, 3-4 cols desktop) -->
        <div id="kitchenMenuList" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3.5 mb-20 md:mb-8">
            <?php if ($db_error): ?>
                <div class="col-span-full bg-zinc-900 border border-zinc-800 rounded-3xl p-6 text-center text-zinc-400">
                    Database connection error
                </div>
            <?php else: ?>
                <?php
                $kitchen_tenant_id = (int)TenantContext::getTenantId();
                $res = $conn->query("SELECT mi.*, c.name as category_name FROM menu_items mi LEFT JOIN categories c ON mi.category_id = c.id WHERE mi.restaurant_id = $kitchen_tenant_id ORDER BY mi.category_id, mi.name");
                if ($res && $res->num_rows > 0) {
                    while ($item = $res->fetch_assoc()) {
                        $is_out_of_stock = ($item['status'] === 'sold_out' || $item['status'] === 'inactive');
                        $dietary = strtolower($item['dietary_type'] ?? 'veg');
                        $dietary_color = ($dietary === 'non-veg') ? 'border-red-500 bg-red-500' : 'border-emerald-500 bg-emerald-500';

                        echo '<div class="menu-item-row bg-zinc-900/90 border border-zinc-800/80 rounded-3xl p-4 flex items-center justify-between gap-3 shadow-lg" data-name="' . strtolower(htmlspecialchars($item['name'])) . ' ' . strtolower(htmlspecialchars($item['category_name'] ?? '')) . '">';
                        
                        echo '<div class="flex items-center gap-3 min-w-0">';
                        echo '<div class="w-12 h-12 rounded-2xl bg-zinc-950 border border-zinc-800 overflow-hidden flex items-center justify-center text-2xl shrink-0">';
                        if (!empty($item['image'])) {
                            echo '<img src="images/' . htmlspecialchars($item['image']) . '" alt="img" class="w-full h-full object-cover" onerror="this.parentElement.innerHTML=\'🍽️\'">';
                        } else {
                            echo '🍽️';
                        }
                        echo '</div>';

                        echo '<div class="min-w-0">';
                        echo '<div class="flex items-center gap-1.5 mb-0.5">';
                        echo '<span class="w-3.5 h-3.5 rounded-sm border-2 ' . $dietary_color . ' flex items-center justify-center shrink-0"><span class="w-1.5 h-1.5 rounded-full bg-white"></span></span>';
                        echo '<h4 class="font-extrabold text-sm text-white truncate">' . htmlspecialchars($item['name']) . '</h4>';
                        echo '</div>';
                        echo '<div class="text-[11px] text-zinc-400 truncate">' . htmlspecialchars($item['category_name'] ?? 'General') . '</div>';
                        echo '</div>';
                        echo '</div>';

                        echo '<div class="text-right shrink-0 flex items-center gap-3">';
                        echo '<div>';
                        echo '<div class="font-black text-sm text-amber-400 mb-0.5">Rs. ' . number_format($item['price'], 0) . '</div>';
                        echo '<span id="stock-lbl-' . $item['id'] . '" class="text-xs font-black ' . (!$is_out_of_stock ? 'text-emerald-400' : 'text-rose-400') . '">' . (!$is_out_of_stock ? 'In Stock' : 'Out of Stock') . '</span>';
                        echo '</div>';

                        echo '<label class="relative inline-flex items-center cursor-pointer shrink-0" title="Toggle Stock Status">';
                        echo '<input type="checkbox" ' . (!$is_out_of_stock ? 'checked' : '') . ' onchange="toggleItemStock(' . $item['id'] . ', this.checked)" class="sr-only peer">';
                        echo '<div class="w-11 h-6 bg-zinc-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[\'\'] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-zinc-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>';
                        echo '</label>';
                        echo '</div>';

                        echo '</div>';
                    }
                }
                $conn->close();
                ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Kitchen Navigation Bar (Mobile Only, Hidden on md: desktop) -->
    <nav class="md:hidden fixed bottom-0 left-0 right-0 z-40 max-w-md mx-auto bg-zinc-950/95 backdrop-blur-xl border-t border-zinc-800/80 flex justify-around items-center h-16 rounded-t-2xl px-2">
        <a href="kitchen-dashboard.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-xs">
            <span class="text-lg">👨‍🍳</span>
            <span>KDS Stream</span>
        </a>
        <a href="kitchen-menu.php" class="flex flex-col items-center gap-0.5 text-amber-500 font-extrabold text-xs">
            <span class="text-lg">📋</span>
            <span>Today's Menu</span>
        </a>
    </nav>

    <script>
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const q = e.target.value.toLowerCase().trim();
            document.querySelectorAll('.menu-item-row').forEach(row => {
                const name = row.dataset.name || '';
                row.style.display = name.includes(q) ? '' : 'none';
            });
        });

        function toggleItemStock(itemId, isChecked) {
            const status = isChecked ? 'active' : 'sold_out';
            const label = document.getElementById('stock-lbl-' + itemId);
            
            // Immediate optimistic UI update for instant feedback
            const text = isChecked ? 'In Stock' : 'Out of Stock';
            const cls = 'text-xs font-black ' + (isChecked ? 'text-emerald-400' : 'text-rose-400');
            if (label) { label.textContent = text; label.className = cls; }

            fetch('api/toggle-stock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: itemId, status: status })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(isChecked ? 'Item marked In Stock!' : 'Item marked Out of Stock!', isChecked ? 'success' : 'warning');
                } else {
                    // Revert UI on failure
                    const revertChecked = !isChecked;
                    const revText = revertChecked ? 'In Stock' : 'Out of Stock';
                    const revCls = 'text-xs font-black ' + (revertChecked ? 'text-emerald-400' : 'text-rose-400');
                    if (label) { label.textContent = revText; label.className = revCls; }
                    const checkbox = label ? label.parentElement.parentElement.querySelector('input[type="checkbox"]') : null;
                    if (checkbox) checkbox.checked = revertChecked;
                    showToast(data.message || 'Failed to update stock status', 'error');
                }
            })
            .catch(err => {
                console.error(err);
                showToast('Network error updating stock', 'error');
            });
        }

        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            const bgColor = type === 'success' ? 'bg-emerald-500 text-zinc-950' : (type === 'warning' ? 'bg-rose-500 text-white' : 'bg-amber-500 text-zinc-950');
            toast.className = `fixed top-20 right-4 z-50 ${bgColor} px-4 py-2.5 rounded-2xl font-black text-xs shadow-2xl transition-all duration-300 transform translate-y-2 opacity-0 flex items-center gap-2`;
            toast.innerHTML = `<span>${type === 'success' ? '✅' : '⚠️'}</span> <span>${message}</span>`;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.remove('translate-y-2', 'opacity-0');
            }, 10);

            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-y-2');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
    </script>
</body>
</html>
