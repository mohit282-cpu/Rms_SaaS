<?php
require_once 'config.php';

// CRYPTOGRAPHIC QR TOKEN & DINING SESSION VALIDATION (PREVENTS IDOR / TABLE ENUMERATION)
$requested_token = isset($_GET['token']) ? trim($_GET['token']) : null;
$requested_table = isset($_GET['table']) ? trim($_GET['table']) : null;
$requested_sig = isset($_GET['sig']) ? trim($_GET['sig']) : null;

$is_access_valid = false;
$access_error_title = "🚫 Access Denied";
$access_error_code = 403;
$access_error_msg = "Please scan the official QR code located on your dining table to access the menu.";

$conn = getDBConnection();

// 1. Primary Auth Path: Cryptographically Secure Token (menu.php?token=5fd8a0fdb6e7411fb58d94c6abbe27e2)
if ($requested_token !== null && $requested_token !== '') {
    if ($conn) {
        $stmt = $conn->prepare("SELECT id, table_number, status, qr_token, restaurant_id FROM tables WHERE qr_token = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $requested_token);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                if ($row['status'] === 'disabled') {
                    $access_error_code = 403;
                    $access_error_title = "❌ Table Currently Unavailable";
                    $access_error_msg = "Table " . htmlspecialchars($row['table_number']) . " is currently disabled or undergoing maintenance. Please request assistance from staff.";
                } else {
                    $is_access_valid = true;
                    $_SESSION['customer_table_id'] = $row['table_number'];
                    $_SESSION['customer_table_token'] = $row['qr_token'];
                    $_SESSION['customer_restaurant_id'] = (int)($row['restaurant_id'] ?? 0);
                    $_SESSION['restaurant_id'] = (int)($row['restaurant_id'] ?? 0);
                }
            } else {
                // Invalid or fake token attempt -> Log Audit Event
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
                $audit = $conn->prepare("INSERT INTO audit_logs (username, event_type, description, ip_address, user_agent) VALUES ('Customer', 'INVALID_QR_TOKEN_ATTEMPT', ?, ?, ?)");
                if ($audit) {
                    $desc = "Unauthorized QR token scan attempt: " . htmlspecialchars($requested_token);
                    $audit->bind_param("sss", $desc, $ip, $ua);
                    $audit->execute();
                    $audit->close();
                }

                $access_error_code = 403;
                $access_error_title = "❌ Invalid or Expired QR Token";
                $access_error_msg = "The scanned QR token is invalid or expired. Please scan the official QR code printed on your dining table.";
            }
            $stmt->close();
        }
    }
}
// 2. Secondary Auth Path: Signed Legacy URL (menu.php?table=1&sig=...)
elseif ($requested_table !== null && $requested_table !== '') {
    if ($conn) {
        $tbl_safe = $conn->real_escape_string($requested_table);
        $existing_ctx = (int)($_SESSION['customer_restaurant_id'] ?? 0);
        if ($existing_ctx > 0) {
            $t_res = $conn->query("SELECT id, table_number, status, qr_token, restaurant_id FROM tables WHERE table_number = '$tbl_safe' AND restaurant_id = $existing_ctx LIMIT 1");
        } else {
            $t_res = null; // Unscoped table lookups are rejected to enforce tenant isolation
        }
        
        if (!$t_res || $t_res->num_rows === 0) {
            $access_error_code = 404;
            $access_error_title = "❌ Table Not Found";
            $access_error_msg = "Table '" . htmlspecialchars($requested_table) . "' does not exist in our system.";
        } else {
            $table_data = $t_res->fetch_assoc();
            if ($table_data['status'] === 'disabled') {
                $access_error_code = 403;
                $access_error_title = "❌ Table Currently Unavailable";
                $access_error_msg = "Table " . htmlspecialchars($requested_table) . " is disabled.";
            } else {
                if ($requested_sig !== null && verifyTableSignature($requested_table, $requested_sig)) {
                    $is_access_valid = true;
                    $_SESSION['customer_table_id'] = $table_data['table_number'];
                    $_SESSION['customer_table_token'] = $table_data['qr_token'];
                    $_SESSION['customer_restaurant_id'] = (int)($table_data['restaurant_id'] ?? 0);
                    $_SESSION['restaurant_id'] = (int)($table_data['restaurant_id'] ?? 0);
                } else {
                    // IDOR Protection: Rejection of direct table URL parameter without valid token/signature
                    $access_error_code = 403;
                    $access_error_title = "❌ Direct Table Access Blocked (IDOR Protection)";
                    $access_error_msg = "Direct table ID access is disabled for security. Please scan the official QR token on your dining table.";
                }
            }
        }
    }
}
// 3. Existing Valid Customer Session Check (Once session is pinned, never trust URL query overrides!)
elseif (isset($_SESSION['customer_table_id']) && !empty($_SESSION['customer_table_id'])) {
    $sess_table = trim($_SESSION['customer_table_id']);
    if ($conn) {
        $tbl_safe = $conn->real_escape_string($sess_table);
        $sess_rest_id = (int)($_SESSION['customer_restaurant_id'] ?? 0);
        $t_res = $conn->query("SELECT status FROM tables WHERE table_number = '$tbl_safe' AND restaurant_id = $sess_rest_id LIMIT 1");
        if ($t_res && $t_row = $t_res->fetch_assoc()) {
            if ($t_row['status'] !== 'disabled') {
                $is_access_valid = true;
            } else {
                $access_error_code = 403;
                $access_error_title = "❌ Table Deactivated";
                $access_error_msg = "Your table session has ended because the table was disabled by management.";
            }
        } else {
            $is_access_valid = true;
        }
    } else {
        $is_access_valid = true;
    }
} else {
    $access_error_code = 400;
    $access_error_title = "❌ Missing Table QR Token";
    $access_error_msg = "No dining table token was detected. Please scan the official QR code located on your dining table to access the menu.";
}

if (!$is_access_valid) {
    http_response_code($access_error_code);
    die('<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-white">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $access_error_title . '</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full flex items-center justify-center p-4 text-center selection:bg-amber-500 selection:text-zinc-950">
    <div class="max-w-md w-full bg-zinc-900 border border-zinc-800 p-8 rounded-3xl space-y-4 shadow-2xl">
        <div class="text-6xl mb-2">🚫</div>
        <h1 class="text-xl font-black text-white">' . $access_error_title . '</h1>
        <p class="text-xs text-zinc-400 leading-relaxed">' . $access_error_msg . '</p>
        <div class="pt-4">
            <a href="index.php" class="inline-flex items-center justify-center px-6 py-3 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                🏠 Return to Home
            </a>
        </div>
    </div>
</body>
</html>');
}

$table_num = strval($_SESSION['customer_table_id']);
$conn = getDBConnection();
$db_error = ($conn === null);
$tenant_id = (int)($_SESSION['customer_restaurant_id'] ?? 0);

// Check if table has active dining session / placed orders
$active_order_id = 0;
$session_orders = [];
$session_running_total = 0.0;

if ($conn) {
    $tbl_safe = $conn->real_escape_string($table_num);
    $res = $conn->query("SELECT id, status, total_amount, batch_number FROM orders WHERE table_number = '$tbl_safe' AND restaurant_id = $tenant_id AND payment_status = 'pending' AND status != 'cancelled' ORDER BY id ASC");
    if ($res) {
        while ($so = $res->fetch_assoc()) {
            $session_orders[] = $so;
            $session_running_total += floatval($so['total_amount']);
            $active_order_id = $so['id'];
        }
    }
}
// Fetch Active Addons for Modal Customizations
$addons = [];
if ($conn) {
    $a_res = $conn->query("SELECT id, name, price, status FROM menu_addons WHERE status = 'active' AND restaurant_id = $tenant_id ORDER BY id ASC");
    if ($a_res) {
        while ($a = $a_res->fetch_assoc()) {
            $addons[] = $a;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>Menu - QR Cafe & Dining</title>
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
<body class="min-h-full pb-24 lg:pb-8 font-sans antialiased selection:bg-amber-500 selection:text-zinc-950">

    <!-- Top Header -->
    <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 py-3.5">
        <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
            <a href="menu.php" class="flex items-center gap-2 text-lg font-black tracking-tight text-white">
                <span class="text-xl">☕</span>
                <span>QR Cafe & Dining</span>
            </a>
            <div class="flex items-center gap-3">
                <?php if ($active_order_id > 0): ?>
                    <a href="order-success.php?order_id=<?php echo $active_order_id; ?>" class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 font-extrabold text-xs">
                        📋 Active Order #<?php echo $active_order_id; ?> →
                    </a>
                <?php endif; ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-400 font-bold text-xs">
                    <span>📍</span> Table <?php echo $table_num; ?>
                </span>
            </div>
        </div>
    </header>

    <!-- Main Responsive Layout Wrapper (2-Column on Desktop) -->
    <div class="max-w-7xl mx-auto px-4 pt-4 lg:flex lg:gap-8">
        
        <!-- Left Side: Search, Categories, Food Grid -->
        <main class="flex-1 min-w-0">
            
            <?php if (!empty($session_orders)): ?>
                <div class="mb-4 p-4 rounded-3xl bg-amber-500/10 border border-amber-500/30 text-amber-300 flex flex-col sm:flex-row items-center justify-between gap-3 shadow-lg">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-2xl bg-amber-500 text-zinc-950 flex items-center justify-center font-black text-lg shadow-md">🍽️</div>
                        <div>
                            <div class="font-black text-sm text-white flex items-center gap-2">
                                Active Dining Session • Table <?php echo htmlspecialchars($table_num); ?>
                                <span class="px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-400 text-[10px] font-bold"><?php echo count($session_orders); ?> Batch(es) Placed</span>
                            </div>
                            <p class="text-xs text-zinc-400">Current Running Total: <strong class="text-amber-400">Rs. <?php echo number_format($session_running_total, 2); ?></strong></p>
                        </div>
                    </div>
                    <a href="order-success.php?order_id=<?php echo end($session_orders)['id']; ?>" class="px-4 py-2 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110 active:scale-95 transition-all shadow-md">
                        📋 View Active Orders & Bill →
                    </a>
                </div>
            <?php endif; ?>

            <!-- Search Input -->
            <div class="relative mb-3">
                <input type="text" id="searchInput" placeholder="Search coffee, burgers, desserts..." class="w-full bg-zinc-900 border border-zinc-800 rounded-2xl py-3 pl-11 pr-4 text-sm text-zinc-100 placeholder-zinc-500 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500 transition-all">
                <span class="absolute left-4 top-3.5 text-zinc-500 text-sm">🔍</span>
            </div>

            <!-- Sticky Horizontal Category Carousel -->
            <nav class="sticky top-[57px] z-30 bg-zinc-950/90 backdrop-blur-md py-2 -mx-4 px-4 mb-4">
                <div class="flex gap-2 overflow-x-auto no-scrollbar">
                    <?php if (!$db_error): ?>
                        <?php
                        $categories_result = $conn->query("SELECT id, name FROM categories WHERE restaurant_id = $tenant_id ORDER BY name");
                        $categories = [];
                        while ($cat = $categories_result->fetch_assoc()) {
                            $categories[] = $cat;
                        }
                        $current_cat = isset($_GET['category']) ? intval($_GET['category']) : 0;
                        ?>
                        <button onclick="filterByCategory(0)" class="px-4 py-2.5 rounded-2xl font-bold text-xs whitespace-nowrap transition-all <?php echo $current_cat === 0 ? 'bg-gradient-to-r from-amber-500 to-amber-600 text-zinc-950 shadow-lg shadow-amber-500/20' : 'bg-zinc-900 border border-zinc-800/80 text-zinc-300 active:scale-95'; ?>">
                            🔥 All Dishes
                        </button>
                        <?php foreach ($categories as $cat): ?>
                            <button onclick="filterByCategory(<?php echo $cat['id']; ?>)" class="px-4 py-2.5 rounded-2xl font-bold text-xs whitespace-nowrap transition-all <?php echo $current_cat === $cat['id'] ? 'bg-gradient-to-r from-amber-500 to-amber-600 text-zinc-950 shadow-lg shadow-amber-500/20' : 'bg-zinc-900 border border-zinc-800/80 text-zinc-300 active:scale-95'; ?>">
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </nav>

            <!-- Adaptive Food Grid View (2 cols mobile, 3 cols tablet, 3-4 cols desktop) -->
            <section class="mb-24 lg:mb-8">
                <?php if ($db_error): ?>
                    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 text-center">
                        <div class="text-3xl mb-2">⚠️</div>
                        <h3 class="font-bold text-zinc-200">Database Connection Failed</h3>
                    </div>
                <?php else: ?>
                    <div id="menuGrid" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 gap-3.5">
                        <?php
                        $category_filter = isset($_GET['category']) ? intval($_GET['category']) : 0;
                        $public_item_cols = "id, name, description, price, image, category_id, status, is_popular, preparation_time, dietary_type, addons";
                        if ($category_filter > 0) {
                            $stmt = $conn->prepare("SELECT $public_item_cols FROM menu_items WHERE status != 'inactive' AND category_id = ? AND restaurant_id = ? ORDER BY name");
                            $stmt->bind_param("ii", $category_filter, $tenant_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                        } else {
                            $result = $conn->query("SELECT $public_item_cols FROM menu_items WHERE status != 'inactive' AND restaurant_id = $tenant_id ORDER BY category_id, name");
                        }

                        if ($result && $result->num_rows > 0) {
                            while ($item = $result->fetch_assoc()) {
                                $out_of_stock = ($item['status'] === 'sold_out' || $item['status'] === 'inactive');
                                $dietary = strtolower($item['dietary_type'] ?? 'veg');
                                $dietary_color = ($dietary === 'non-veg') ? 'border-red-500 bg-red-500' : 'border-emerald-500 bg-emerald-500';
                                $prepTime = isset($item['preparation_time']) ? intval($item['preparation_time']) : 15;
                                $img_src = (!empty($item['image']) && file_exists(__DIR__ . '/images/' . $item['image'])) ? ('images/' . htmlspecialchars($item['image'])) : '';
                                
                                echo '<div id="item-card-' . $item['id'] . '" class="menu-item bg-zinc-900/90 border border-zinc-800/80 rounded-3xl p-3 flex flex-col justify-between transition-all active:scale-[0.98]" data-id="' . $item['id'] . '" data-price="' . $item['price'] . '" data-preptime="' . $prepTime . '" data-addons="' . addslashes(htmlspecialchars($item['addons'] ?? '')) . '" data-name="' . strtolower(htmlspecialchars($item['name'])) . '" data-rawname="' . addslashes(htmlspecialchars($item['name'])) . '" data-rawdesc="' . addslashes(htmlspecialchars($item['description'])) . '" data-description="' . strtolower(htmlspecialchars($item['description'])) . '">';
                                
                                // RECTANGULAR 16:9 Image Container with Zoom Click Trigger
                                if (!empty($img_src)) {
                                    echo '<div onclick="openImageZoomModal(\'' . $img_src . '\', \'' . addslashes(htmlspecialchars($item['name'])) . '\', \'' . number_format($item['price'], 0) . '\')" class="relative aspect-[16/9] w-full rounded-2xl bg-zinc-950 overflow-hidden mb-2.5 flex items-center justify-center text-4xl border border-zinc-800/50 cursor-pointer group active:scale-95 transition-all">';
                                    echo '<img src="' . $img_src . '" alt="' . htmlspecialchars($item['name']) . '" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy" onerror="this.parentElement.innerHTML=\'🍽️\'">';
                                    echo '<span class="absolute bottom-2 right-2 bg-zinc-950/80 text-white p-1 px-2 rounded-lg text-[10px] font-extrabold opacity-0 group-hover:opacity-100 transition-opacity">🔍 Zoom</span>';
                                } else {
                                    echo '<div class="relative aspect-[16/9] w-full rounded-2xl bg-zinc-950 overflow-hidden mb-2.5 flex items-center justify-center text-4xl border border-zinc-800/50">';
                                    echo '🍽️';
                                }

                                if (!empty($item['is_popular'])) {
                                    echo '<span class="absolute top-2 left-2 bg-amber-500 text-zinc-950 font-black text-[10px] px-2 py-0.5 rounded-full uppercase tracking-wider shadow-md">⭐ Top</span>';
                                }
                                echo '</div>';

                                echo '<div class="flex-1 flex flex-col mb-2">';
                                echo '<div class="flex items-center gap-1.5 mb-1">';
                                echo '<span class="w-3.5 h-3.5 rounded-sm border-2 ' . $dietary_color . ' flex items-center justify-center shrink-0"><span class="w-1.5 h-1.5 rounded-full bg-white"></span></span>';
                                echo '<h3 class="font-extrabold text-sm text-zinc-100 truncate">' . htmlspecialchars($item['name']) . '</h3>';
                                echo '</div>';
                                echo '<p class="text-xs text-zinc-400 line-clamp-2 mb-2">' . htmlspecialchars($item['description']) . '</p>';
                                echo '</div>';

                                echo '<div class="mt-auto flex flex-col gap-2 action-container">';
                                echo '<span class="text-base font-black text-amber-400">Rs. ' . number_format($item['price'], 0) . '</span>';
                                if (!$out_of_stock) {
                                    echo '<button onclick="openCustomModal(' . $item['id'] . ', \'' . addslashes(htmlspecialchars($item['name'])) . '\', ' . $item['price'] . ', \'' . addslashes(htmlspecialchars($item['description'])) . '\', ' . $prepTime . ')" class="btn-add h-11 w-full rounded-2xl bg-amber-500 hover:bg-amber-600 active:scale-95 text-zinc-950 font-black text-xs transition-all shadow-lg shadow-amber-500/10 flex items-center justify-center gap-1">+ Add</button>';
                                } else {
                                    echo '<button disabled class="btn-soldout h-11 w-full rounded-2xl bg-zinc-800 text-rose-400/80 font-bold text-xs">Out of stock</button>';
                                }
                                echo '</div>';

                                echo '</div>';
                            }
                        } else {
                            echo '<div class="col-span-full bg-zinc-900 border border-zinc-800 rounded-3xl p-8 text-center text-zinc-400">';
                            echo '<div class="text-4xl mb-2">🍽️</div>';
                            echo '<h3 class="font-bold">No dishes found</h3>';
                            echo '</div>';
                        }
                        $conn->close();
                        ?>
                    </div>
                <?php endif; ?>
            </section>

        </main>

        <!-- Right Side: Persistent Desktop Cart Sidebar (Visible on lg: screens) -->
        <aside class="hidden lg:block lg:w-80 xl:w-96 shrink-0">
            <div class="sticky top-20 bg-zinc-900 border border-zinc-800 rounded-3xl p-5 shadow-2xl flex flex-col max-h-[calc(100vh-6rem)]">
                <div class="flex justify-between items-center pb-3 border-b border-zinc-800 mb-3">
                    <h3 class="text-base font-black text-white flex items-center gap-2">
                        <span>🛒</span> Live Order Cart
                    </h3>
                    <span class="cart-badge bg-amber-500 text-zinc-950 font-black text-xs px-2.5 py-0.5 rounded-full" style="display: none;">0</span>
                </div>

                <?php if ($active_order_id > 0): ?>
                    <div class="mb-3">
                        <a href="order-success.php?order_id=<?php echo $active_order_id; ?>" class="w-full p-3 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-amber-400 font-extrabold text-xs flex items-center justify-between active:scale-95 transition-all">
                            <span>📋 Track Order #<?php echo $active_order_id; ?></span>
                            <span>View →</span>
                        </a>
                    </div>
                <?php endif; ?>

                <div id="desktopCartItems" class="cart-items flex-1 min-h-0 overflow-y-auto space-y-3 pr-1 py-1">
                    <!-- Rendered by JS -->
                </div>

                <div class="pt-4 border-t border-zinc-800 mt-auto space-y-3">
                    <div class="flex justify-between items-center text-base font-black">
                        <span class="text-zinc-400">Total:</span>
                        <span id="desktopCartTotal" class="text-amber-400 text-xl">Rs. 0.00</span>
                    </div>
                    <a id="desktopCheckoutBtn" href="checkout.php" class="h-12 w-full rounded-2xl bg-gradient-to-r from-amber-500 to-amber-600 text-zinc-950 font-black text-sm flex items-center justify-center active:scale-95 shadow-lg shadow-amber-500/20">
                        Proceed to Checkout →
                    </a>
                </div>
            </div>
        </aside>

    </div>

    <!-- FLOATING WAITER CALL BUTTON -->
    <button onclick="callWaiter()" class="fixed bottom-20 lg:bottom-6 right-4 lg:right-6 z-40 w-14 h-14 lg:w-auto lg:h-12 lg:px-5 rounded-full bg-gradient-to-r from-amber-500 to-amber-600 text-zinc-950 font-black text-xl lg:text-xs flex items-center justify-center gap-2 shadow-2xl active:scale-95 transition-all cursor-pointer border-2 border-amber-400/50 hover:shadow-amber-500/20" title="Call Waiter to Table">
        <span>🔔</span>
        <span class="hidden lg:inline font-black uppercase tracking-wider">Call Waiter</span>
    </button>

    <!-- SPATIAL IMAGE ZOOM LIGHTBOX MODAL -->
    <div id="imageZoomModal" class="fixed inset-0 z-50 flex items-center justify-center opacity-0 pointer-events-none transition-all duration-300 p-4">
        <div class="absolute inset-0 bg-zinc-950/90 backdrop-blur-md" onclick="closeImageZoomModal()"></div>
        <div class="relative z-10 w-full max-w-2xl bg-zinc-900 border border-zinc-800/80 rounded-3xl p-4 shadow-2xl scale-90 transition-transform duration-300 space-y-3 overflow-hidden">
            <button onclick="closeImageZoomModal()" class="absolute top-3 right-3 z-20 bg-zinc-950/80 border border-zinc-800 text-white w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm shadow-lg hover:bg-rose-600 transition-colors">✕</button>
            <div class="aspect-[16/9] w-full rounded-2xl bg-zinc-950 overflow-hidden border border-zinc-800/50 flex items-center justify-center">
                <img id="zoomModalImage" src="" alt="Zoomed View" class="w-full h-full object-contain">
            </div>
            <div class="flex items-center justify-between px-2 pt-1">
                <div class="font-extrabold text-base text-white" id="zoomModalTitle">Item Name</div>
                <div class="font-black text-amber-400 text-lg" id="zoomModalPrice">Rs. 0</div>
            </div>
            <div class="text-[11px] text-zinc-500 text-center font-medium">Tap outside to close</div>
        </div>
    </div>

    <!-- Slide-Up Bottom Sheet Customization Modal -->
    <div id="customModal" class="fixed inset-0 z-50 flex items-end lg:items-center justify-center opacity-0 pointer-events-none transition-all duration-300">
        <div class="absolute inset-0 bg-zinc-950/80 backdrop-blur-md" onclick="closeCustomModal()"></div>
        <div class="relative z-10 w-full max-w-md bg-zinc-900 border border-zinc-800 rounded-t-3xl lg:rounded-3xl p-6 shadow-2xl translate-y-full lg:translate-y-0 transition-transform duration-300 max-h-[85vh] overflow-y-auto">
            <button onclick="closeCustomModal()" class="absolute top-4 right-4 bg-zinc-800 text-zinc-400 w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm">✕</button>
            <div class="text-4xl text-center mb-2" id="customModalImage">🍽️</div>
            <h3 class="text-lg font-black text-center text-white" id="customModalTitle">Item Name</h3>
            <p class="text-xs text-zinc-400 text-center mb-3" id="customModalDesc">Description</p>
            <div class="text-center font-black text-amber-400 text-lg mb-4" id="customModalPrice">Rs. 0</div>

            <div class="space-y-4 mb-6 bg-zinc-950/50 p-4 rounded-2xl border border-zinc-800/60">
                <div id="customAddonsContainer" class="hidden">
                    <h4 class="text-xs font-bold text-zinc-300 mb-2">🧀 Dish Add-ons & Extra Options</h4>
                    <div id="customModalAddonsList" class="space-y-2"></div>
                </div>

                <div>
                    <h4 class="text-xs font-bold text-zinc-300 mb-1.5">📝 Special Instructions / Note (Optional)</h4>
                    <textarea id="customModalNotes" rows="2" placeholder="e.g. Less spicy, no onions, extra crisp..." class="w-full bg-zinc-900 border border-zinc-800 rounded-xl p-3 text-xs text-white placeholder-zinc-500 outline-none focus:border-amber-500 resize-none"></textarea>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <div class="flex items-center gap-3 bg-zinc-950 border border-zinc-800 rounded-2xl p-1.5 px-3">
                    <button onclick="customModalChangeQty(-1)" class="w-8 h-8 rounded-xl bg-zinc-800 text-white font-black text-lg flex items-center justify-center active:bg-amber-500 active:text-zinc-950">−</button>
                    <span id="customModalQty" class="font-black text-base w-5 text-center text-white">1</span>
                    <button onclick="customModalChangeQty(1)" class="w-8 h-8 rounded-xl bg-zinc-800 text-white font-black text-lg flex items-center justify-center active:bg-amber-500 active:text-zinc-950">+</button>
                </div>
                <button onclick="addCustomToCart()" class="flex-1 h-12 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-600 text-zinc-950 font-black text-sm active:scale-95 shadow-lg shadow-amber-500/20">
                    Add to Cart • <span id="customModalTotal">Rs. 0</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Slide-out Cart Drawer Panel (Mobile Only) -->
    <div class="lg:hidden cart-overlay fixed inset-0 bg-zinc-950/85 backdrop-blur-md z-50 opacity-0 pointer-events-none transition-opacity duration-300"></div>
    <div class="lg:hidden cart-panel fixed inset-y-0 right-0 z-50 w-full max-w-md bg-zinc-900 border-l border-zinc-800 p-6 flex flex-col translate-x-full transition-transform duration-300 shadow-2xl">
        <div class="flex justify-between items-center pb-4 border-b border-zinc-800 mb-4 shrink-0">
            <h3 class="text-lg font-black text-white">🛒 Your Cart</h3>
            <button class="cart-close bg-zinc-800 text-zinc-400 w-8 h-8 rounded-full flex items-center justify-center font-bold">✕</button>
        </div>

        <?php if ($active_order_id > 0): ?>
            <div class="mb-4 shrink-0">
                <a href="order-success.php?order_id=<?php echo $active_order_id; ?>" class="w-full p-3 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-amber-400 font-extrabold text-xs flex items-center justify-between active:scale-95 transition-all">
                    <span>📋 Track Active Order #<?php echo $active_order_id; ?></span>
                    <span>View Status →</span>
                </a>
            </div>
        <?php endif; ?>

        <!-- Mobile Cart Items List -->
        <div id="mobileCartItems" class="cart-items flex-1 min-h-[180px] max-h-[60vh] overflow-y-auto space-y-3 pr-1 py-1"></div>
        
        <div class="pt-4 border-t border-zinc-800 mt-auto shrink-0 space-y-3">
            <div class="flex justify-between items-center mb-4 text-base font-black">
                <span class="text-zinc-400">Total Amount:</span>
                <span id="cartTotal" class="text-amber-400 text-xl">Rs. 0.00</span>
            </div>
            <a id="checkoutBtn" href="checkout.php" class="h-12 w-full rounded-2xl bg-gradient-to-r from-amber-500 to-amber-600 text-zinc-950 font-black text-sm flex items-center justify-center active:scale-95 shadow-lg shadow-amber-500/20">
                Proceed to Checkout →
            </a>
        </div>
    </div>

    <!-- Fixed Customer Bottom Navigation Bar (Hidden on lg: desktop) -->
    <nav class="lg:hidden fixed bottom-0 left-0 right-0 z-40 max-w-md mx-auto bg-zinc-950/95 backdrop-blur-xl border-t border-zinc-800/80 flex justify-around items-center h-16 rounded-t-2xl px-2">
        <a href="menu.php" class="flex flex-col items-center gap-0.5 text-amber-500 font-extrabold text-xs">
            <span class="text-lg">🍽️</span>
            <span>Menu</span>
        </a>
        <button onclick="openCartPanel()" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-xs relative">
            <span class="text-lg">🛒</span>
            <span>Cart</span>
            <span class="cart-badge absolute -top-1 right-2 bg-amber-500 text-zinc-950 font-black text-[9px] w-4 h-4 rounded-full flex items-center justify-center" style="display: none;">0</span>
        </button>
    </nav>

    <script src="js/modern.js"></script>
    <script>
        function filterByCategory(catId) {
            const url = new URL(window.location.href);
            if (catId > 0) url.searchParams.set('category', catId);
            else url.searchParams.delete('category');
            window.location.href = url.toString();
        }

        document.getElementById('searchInput').addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase().trim();
            document.querySelectorAll('.menu-item').forEach(item => {
                const name = item.dataset.name || '';
                const desc = item.dataset.description || '';
                item.style.display = (name.includes(query) || desc.includes(query)) ? '' : 'none';
            });
        });

        // Live Stock Sync Polling
        function pollLiveStockStatus() {
            fetch('api/menu-status.php')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.items) {
                        data.items.forEach(item => {
                            const card = document.getElementById('item-card-' + item.id);
                            if (card) {
                                const actionBox = card.querySelector('.action-container');
                                if (actionBox) {
                                    const rawName = card.dataset.rawname;
                                    const rawDesc = card.dataset.rawdesc;
                                    const price = card.dataset.price;
                                    const prepTime = card.dataset.preptime;
                                    const formattedPrice = formatPrice(price);

                                    if (item.status === 'sold_out' || item.status === 'inactive') {
                                        actionBox.innerHTML = `
                                            <span class="text-base font-black text-amber-400">${formattedPrice}</span>
                                            <button disabled class="btn-soldout h-11 w-full rounded-2xl bg-zinc-800 text-rose-400/80 font-bold text-xs">Out of stock</button>
                                        `;
                                    } else if (item.status === 'active') {
                                        actionBox.innerHTML = `
                                            <span class="text-base font-black text-amber-400">${formattedPrice}</span>
                                            <button onclick="openCustomModal(${item.id}, '${rawName}', ${price}, '${rawDesc}', ${prepTime})" class="btn-add h-11 w-full rounded-2xl bg-amber-500 hover:bg-amber-600 active:scale-95 text-zinc-950 font-black text-xs transition-all shadow-lg shadow-amber-500/10 flex items-center justify-center gap-1">+ Add</button>
                                        `;
                                    }
                                }
                            }
                        });
                    }
                })
                .catch(err => console.error(err));
        }

        // Start polling immediately & poll every 2 seconds for live real-time stock sync
        pollLiveStockStatus();
        setInterval(pollLiveStockStatus, 2000);
    </script>
</body>
</html>
