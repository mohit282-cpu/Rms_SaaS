<?php
// admin/menu-items.php - Enterprise POS Menu & Inventory Management System
require_once '../config.php';
requireAdminLogin();

$conn = getDBConnection();
if (!$conn) {
    die("Database connection error");
}

$tenantId = (int)($_SESSION['restaurant_id'] ?? 0);

// Fetch Categories for Dropdowns
$categories_res = $conn->query("SELECT * FROM categories WHERE restaurant_id = $tenantId ORDER BY name ASC");
$categories = [];
if ($categories_res) {
    while ($cat = $categories_res->fetch_assoc()) {
        $categories[] = $cat;
    }
}

// Handle Form Submissions (Create, Edit, Delete, Toggle Stock)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    CSRF::requireValidToken();

    $action = $_POST['action'];

    if ($action === 'create' || $action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $name = Security::sanitize($_POST['name'] ?? '');
        $sku = Security::sanitize($_POST['sku'] ?? 'SKU-' . rand(1000, 9999));
        $category_id = intval($_POST['category_id'] ?? 0);
        $description = Security::sanitize($_POST['description'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $cost_price = floatval($_POST['cost_price'] ?? 0);
        $stock_quantity = intval($_POST['stock_quantity'] ?? 50);
        $min_stock_level = intval($_POST['min_stock_level'] ?? 10);
        $preparation_time = intval($_POST['preparation_time'] ?? 15);
        $dietary_type = Security::sanitize($_POST['dietary_type'] ?? 'veg');
        $status = Security::sanitize($_POST['status'] ?? 'active');
        $is_popular = isset($_POST['is_popular']) ? 1 : 0;
        $allergens = Security::sanitize($_POST['allergens'] ?? '');

        // Handle Image Upload
        $image_path = Security::sanitize($_POST['existing_image'] ?? '');
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_res = Security::uploadFile($_FILES['image'], '../uploads');
            if ($upload_res['success']) {
                $image_path = 'uploads/' . $upload_res['filename'];
            } else {
                $_SESSION['error'] = $upload_res['message'];
            }
        }

        if ($action === 'create') {
            $stmt = $conn->prepare("INSERT INTO menu_items (restaurant_id, name, sku, category_id, description, price, cost_price, stock_quantity, min_stock_level, preparation_time, dietary_type, status, is_popular, allergens, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("i" . "ssisddiiissiis", $tenantId, $name, $sku, $category_id, $description, $price, $cost_price, $stock_quantity, $min_stock_level, $preparation_time, $dietary_type, $status, $is_popular, $allergens, $image_path);
                $stmt->execute();
                $stmt->close();
            }
            $_SESSION['success'] = "Menu item '$name' created successfully!";
        } elseif ($action === 'edit' && $id > 0) {
            $stmt = $conn->prepare("UPDATE menu_items SET name = ?, sku = ?, category_id = ?, description = ?, price = ?, cost_price = ?, stock_quantity = ?, min_stock_level = ?, preparation_time = ?, dietary_type = ?, status = ?, is_popular = ?, allergens = ?, image = ? WHERE id = ? AND restaurant_id = ?");
            if ($stmt) {
                $stmt->bind_param("ssisddiiisssisii", $name, $sku, $category_id, $description, $price, $cost_price, $stock_quantity, $min_stock_level, $preparation_time, $dietary_type, $status, $is_popular, $allergens, $image_path, $id, $tenantId);
                $stmt->execute();
                $stmt->close();
            }
            $_SESSION['success'] = "Menu item '$name' updated successfully!";
        }
    } elseif ($action === 'toggle_status') {
        $id = intval($_POST['id'] ?? 0);
        $new_status = Security::sanitize($_POST['status'] ?? 'active');
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE menu_items SET status = ? WHERE id = ? AND restaurant_id = ?");
            if ($stmt) {
                $stmt->bind_param("sii", $new_status, $id, $tenantId);
                $stmt->execute();
                $stmt->close();
            }
            $_SESSION['success'] = "Item status updated to " . strtoupper($new_status);
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM menu_items WHERE id = ? AND restaurant_id = ?");
            if ($stmt) {
                $stmt->bind_param("ii", $id, $tenantId);
                $stmt->execute();
                $stmt->close();
            }
            $_SESSION['success'] = "Menu item deleted successfully";
        }
    }

    header('Location: menu-items.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>POS Menu & Inventory Management - QR Cafe</title>
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
    <?php $currentPage = 'menu-items'; include 'includes/sidebar.php'; ?>

    <!-- MAIN CONTENT AREA -->
    <div class="md:pl-64 min-h-screen">

        <!-- HEADER BAR -->
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg md:text-xl font-black text-white">Menu & Inventory Management System</h1>
                        <span class="flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-black uppercase tracking-wider">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span> Live POS Catalog
                        </span>
                    </div>
                    <p class="text-xs text-zinc-400 hidden sm:block">Manage Menu Items, Pricing, Cost Margins, Stock Control & Allergens</p>
                </div>

                <!-- Action Controls -->
                <div class="flex items-center gap-2 shrink-0">
                    <button onclick="openCreateItemModal()" class="h-10 px-4 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs flex items-center gap-1.5 active:scale-95 shadow-lg shadow-amber-500/20">
                        <span>➕</span> Create Menu Item
                    </button>
                    <button onclick="refreshMenuStream()" class="h-10 px-3 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 font-bold text-xs hover:border-amber-500/40">
                        🔄 Refresh
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

            <?php if (isset($_SESSION['error'])): ?>
                <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold flex items-center justify-between">
                    <span>⚠️ <?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
                    <button onclick="this.parentElement.remove()" class="text-zinc-400 hover:text-white">✕</button>
                </div>
            <?php endif; ?>

            <!-- 1. TOP KPI METRICS SECTION (9 METRICS) -->
            <section class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 lg:grid-cols-9 gap-3">
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🍔 Total Items</span>
                    <div id="kpiTotalItems" class="text-lg font-black text-white">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">📂 Categories</span>
                    <div id="kpiCategories" class="text-lg font-black text-amber-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🟢 Available</span>
                    <div id="kpiAvailable" class="text-lg font-black text-emerald-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🔴 Sold Out</span>
                    <div id="kpiSoldOut" class="text-lg font-black text-rose-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">⚠️ Low Stock</span>
                    <div id="kpiLowStock" class="text-lg font-black text-amber-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">⭐ Top Seller</span>
                    <div id="kpiBestSelling" class="text-xs font-black text-amber-400 truncate">N/A</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">💰 Avg Price</span>
                    <div id="kpiAvgPrice" class="text-sm font-black text-emerald-400 truncate">Rs.0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">📸 No Image</span>
                    <div id="kpiMissingImages" class="text-lg font-black text-zinc-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🔥 Today Sold</span>
                    <div id="kpiTodaySold" class="text-lg font-black text-white">0</div>
                </div>
            </section>

            <!-- 2. SEARCH & CATEGORY FILTERS BAR -->
            <section class="bg-zinc-900/90 border border-zinc-800/80 rounded-3xl p-4 shadow-xl space-y-3">
                <div class="flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
                    
                    <!-- Search Field -->
                    <div class="relative flex-1">
                        <span class="absolute left-3.5 top-3 text-zinc-500 text-xs">🔍</span>
                        <input type="text" id="searchInput" oninput="filterMenuCatalog()" placeholder="Search Item Name, SKU, Category, Ingredients..." class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl pl-9 pr-4 text-xs text-white placeholder-zinc-500 outline-none focus:border-amber-500 font-medium">
                    </div>

                    <!-- Category Filter Tabs -->
                    <div class="flex items-center gap-1.5 overflow-x-auto no-scrollbar py-1">
                        <button onclick="setCategoryFilter(0)" id="catTabAll" class="cat-btn px-3.5 py-2 rounded-xl text-xs font-black bg-amber-500 text-zinc-950 shadow-md">All Categories</button>
                        <?php foreach ($categories as $c): ?>
                            <button onclick="setCategoryFilter(<?php echo $c['id']; ?>)" class="cat-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white" data-catid="<?php echo $c['id']; ?>">
                                <?php echo htmlspecialchars($c['name']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- 3. RICH MENU CATALOG DATA TABLE / CARDS -->
            <section class="space-y-4">
                <div id="menuCatalogContainer" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <div class="col-span-full py-12 text-center text-zinc-500">
                        <div class="text-4xl mb-2 animate-bounce">⏳</div>
                        <p class="font-bold text-xs">Loading Menu Items Catalog...</p>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- 4. MULTI-TAB ITEM CREATION & EDIT MODAL -->
    <div id="itemModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-2xl w-full shadow-2xl space-y-4 max-h-[90vh] flex flex-col">
            
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <h3 id="modalFormTitle" class="font-black text-white text-base">➕ Create New Menu Item</h3>
                <button onclick="closeItemModal()" class="text-zinc-400 hover:text-white font-bold">✕</button>
            </div>

            <!-- Tab Navigation Bar -->
            <div class="flex border-b border-zinc-800 gap-2">
                <button type="button" onclick="switchFormTab('general')" id="tabBtnGeneral" class="px-4 py-2 border-b-2 border-amber-500 text-amber-400 font-bold text-xs">General</button>
                <button type="button" onclick="switchFormTab('pricing')" id="tabBtnPricing" class="px-4 py-2 border-b-2 border-transparent text-zinc-400 hover:text-white font-bold text-xs">Pricing & Cost</button>
                <button type="button" onclick="switchFormTab('inventory')" id="tabBtnInventory" class="px-4 py-2 border-b-2 border-transparent text-zinc-400 hover:text-white font-bold text-xs">Inventory & Stock</button>
                <button type="button" onclick="switchFormTab('media')" id="tabBtnMedia" class="px-4 py-2 border-b-2 border-transparent text-zinc-400 hover:text-white font-bold text-xs">Image & Media</button>
            </div>

            <form id="itemForm" method="POST" action="menu-items.php" enctype="multipart/form-data" class="flex-1 overflow-y-auto space-y-4 pr-1">
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="formId" value="0">
                <input type="hidden" name="existing_image" id="formExistingImage" value="">

                <!-- TAB 1: GENERAL -->
                <div id="tabContentGeneral" class="space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Item Name *</label>
                            <input type="text" name="name" id="inputName" required placeholder="e.g. Cappuccino" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">SKU / Code</label>
                            <input type="text" name="sku" id="inputSKU" placeholder="Auto-generated if empty" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500 font-medium">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Category *</label>
                            <select name="category_id" id="inputCategory" required class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500 font-bold">
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Dietary Type</label>
                            <select name="dietary_type" id="inputDietary" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500 font-bold">
                                <option value="veg">🌿 Vegetarian</option>
                                <option value="non-veg">🍗 Non-Vegetarian</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-zinc-300 mb-1">Description</label>
                        <textarea name="description" id="inputDescription" rows="3" placeholder="Rich espresso with steamed milk foam..." class="w-full bg-zinc-950 border border-zinc-800 rounded-2xl p-3 text-xs text-white outline-none focus:border-amber-500 font-medium"></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Prep Time (mins)</label>
                            <input type="number" name="preparation_time" id="inputPrepTime" value="15" min="1" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500 font-bold">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Initial Status</label>
                            <select name="status" id="inputStatus" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500 font-bold">
                                <option value="active">🟢 Active / Available</option>
                                <option value="sold_out">🔴 Out of Stock</option>
                                <option value="inactive">⚫ Inactive / Hidden</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: PRICING & COST -->
                <div id="tabContentPricing" class="space-y-4 hidden">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Selling Price (Rs.) *</label>
                            <input type="number" step="0.01" name="price" id="inputPrice" required placeholder="250.00" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-amber-400 font-black outline-none focus:border-amber-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Cost Price (Rs.)</label>
                            <input type="number" step="0.01" name="cost_price" id="inputCostPrice" placeholder="120.00" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                        </div>
                    </div>
                </div>

                <!-- TAB 3: INVENTORY & STOCK -->
                <div id="tabContentInventory" class="space-y-4 hidden">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Current Stock Quantity</label>
                            <input type="number" name="stock_quantity" id="inputStockQuantity" value="50" min="0" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-zinc-300 mb-1">Low Stock Alert Threshold</label>
                            <input type="number" name="min_stock_level" id="inputMinStockLevel" value="10" min="1" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-300 mb-1">Allergens List</label>
                        <input type="text" name="allergens" id="inputAllergens" placeholder="e.g. Milk, Peanuts, Gluten" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-medium outline-none focus:border-amber-500">
                    </div>
                </div>

                <!-- TAB 4: IMAGE & MEDIA -->
                <div id="tabContentMedia" class="space-y-4 hidden">
                    <div>
                        <label class="block text-xs font-bold text-zinc-300 mb-1">Item Thumbnail Image</label>
                        <input type="file" name="image" accept="image/*" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 py-2 text-xs text-zinc-400 file:mr-4 file:py-1 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-amber-500 file:text-zinc-950">
                    </div>
                    <div id="imagePreviewBox" class="p-3 bg-zinc-950 rounded-2xl border border-zinc-800 hidden text-center">
                        <img id="imagePreviewImg" src="" alt="Preview" class="w-32 h-32 object-cover rounded-xl mx-auto">
                    </div>
                </div>

                <div class="flex gap-2 pt-4 border-t border-zinc-800">
                    <button type="button" onclick="closeItemModal()" class="w-1/3 h-11 rounded-2xl bg-zinc-800 font-bold text-xs text-zinc-300">Cancel</button>
                    <button type="submit" class="w-2/3 h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">Save Menu Item</button>
                </div>
            </form>

        </div>
    </div>

    <!-- MOBILE BOTTOM NAVIGATION -->
    <nav class="md:hidden fixed bottom-0 left-0 right-0 z-40 max-w-md mx-auto bg-zinc-950/95 backdrop-blur-xl border-t border-zinc-800/80 flex justify-around items-center h-16 rounded-t-2xl px-2">
        <a href="index.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">📊</span>
            <span>Dashboard</span>
        </a>
        <a href="menu-items.php" class="flex flex-col items-center gap-0.5 text-amber-500 font-black text-[10px]">
            <span class="text-lg">🍔</span>
            <span>Menu</span>
        </a>
        <a href="orders.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">📋</span>
            <span>Orders</span>
        </a>
        <a href="tables.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">📍</span>
            <span>Tables</span>
        </a>
    </nav>

    <!-- REALTIME MENU MANAGEMENT CONTROLLER -->
    <script src="../js/modern.js"></script>
    <script>
        let allMenuItems = [];
        let selectedCategoryFilter = 0;

        function setCategoryFilter(catId) {
            selectedCategoryFilter = catId;
            document.querySelectorAll('.cat-btn').forEach(btn => {
                if (btn.id === 'catTabAll' && catId === 0) {
                    btn.className = 'cat-btn px-3.5 py-2 rounded-xl text-xs font-black bg-amber-500 text-zinc-950 shadow-md';
                } else if (btn.dataset.catid == catId) {
                    btn.className = 'cat-btn px-3.5 py-2 rounded-xl text-xs font-black bg-amber-500 text-zinc-950 shadow-md';
                } else {
                    btn.className = 'cat-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white';
                }
            });
            refreshMenuStream();
        }

        function refreshMenuStream() {
            fetch('../api/menu-stream.php?category_id=' + selectedCategoryFilter)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        updateKPICards(data.kpi);
                        allMenuItems = data.items || [];
                        renderMenuCatalog();
                    }
                })
                .catch(err => console.error('Menu stream error:', err));
        }

        function updateKPICards(kpi) {
            if (!kpi) return;
            document.getElementById('kpiTotalItems').textContent = kpi.total_items || 0;
            document.getElementById('kpiCategories').textContent = kpi.categories || 0;
            document.getElementById('kpiAvailable').textContent = kpi.available_items || 0;
            document.getElementById('kpiSoldOut').textContent = kpi.sold_out_items || 0;
            document.getElementById('kpiLowStock').textContent = kpi.low_stock_items || 0;
            document.getElementById('kpiBestSelling').textContent = kpi.best_selling || 'N/A';
            document.getElementById('kpiAvgPrice').textContent = formatPrice(kpi.avg_price || 0);
            document.getElementById('kpiMissingImages').textContent = kpi.missing_images || 0;
            document.getElementById('kpiTodaySold').textContent = kpi.today_sold_qty || 0;
        }

        function filterMenuCatalog() {
            renderMenuCatalog();
        }

        function renderMenuCatalog() {
            const container = document.getElementById('menuCatalogContainer');
            const search = document.getElementById('searchInput').value.trim().toLowerCase();

            let filtered = allMenuItems.filter(i => {
                const matchSearch = (!search || 
                    i.name.toLowerCase().includes(search) ||
                    (i.sku && i.sku.toLowerCase().includes(search)) ||
                    i.category_name.toLowerCase().includes(search)
                );
                return matchSearch;
            });

            if (filtered.length === 0) {
                container.innerHTML = `
                    <div class="col-span-full bg-zinc-900/80 border border-zinc-800 rounded-3xl p-12 text-center text-zinc-500">
                        <div class="text-4xl mb-2">🍔</div>
                        <h4 class="font-bold text-sm text-zinc-300">No menu items match active filters</h4>
                        <p class="text-xs text-zinc-500 mt-1">Try searching or clearing active filters</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = filtered.map(i => {
                const isSoldOut = (i.status === 'sold_out');
                const imgUrl = i.image ? '../' + i.image : 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=300';
                const isVeg = (i.dietary_type === 'veg');
                const margin = i.profit_margin || 0;

                return `
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-3 shadow-xl hover:border-amber-500/40 transition-all flex flex-col justify-between">
                        
                        <div>
                            <!-- Thumbnail & Badges -->
                            <div class="relative h-40 rounded-2xl overflow-hidden mb-3 bg-zinc-950">
                                <img src="${imgUrl}" alt="${i.name}" class="w-full h-full object-cover">
                                <div class="absolute top-2 left-2 flex gap-1">
                                    <span class="px-2 py-0.5 rounded-full ${isVeg ? 'bg-emerald-500/90 text-zinc-950 font-black' : 'bg-rose-500/90 text-white font-black'} text-[10px]">
                                        ${isVeg ? '🌿 VEG' : '🍗 NON-VEG'}
                                    </span>
                                </div>
                                <div class="absolute top-2 right-2">
                                    <span class="px-2 py-0.5 rounded-full ${isSoldOut ? 'bg-rose-500/90 text-white' : 'bg-emerald-500/90 text-zinc-950'} font-black text-[10px]">
                                        ${isSoldOut ? '🔴 SOLD OUT' : '🟢 ACTIVE'}
                                    </span>
                                </div>
                            </div>

                            <!-- Name & Category -->
                            <div class="space-y-1">
                                <div class="flex items-center justify-between">
                                    <h4 class="font-black text-white text-sm truncate">${i.name}</h4>
                                    <span class="text-[10px] text-zinc-500 font-mono">${i.sku || 'SKU-NONE'}</span>
                                </div>
                                <div class="text-[11px] text-zinc-400 font-bold">${i.category_name}</div>
                            </div>
                        </div>

                        <!-- Price & Margin Grid -->
                        <div class="space-y-3 pt-2 border-t border-zinc-800">
                            <div class="flex items-center justify-between text-xs">
                                <div>
                                    <span class="text-[10px] font-bold text-zinc-500 block">Selling Price</span>
                                    <span class="font-black text-amber-400 text-sm">${formatPrice(i.price)}</span>
                                </div>
                                <div class="text-right">
                                    <span class="text-[10px] font-bold text-zinc-500 block">Profit Margin</span>
                                    <span class="font-bold text-emerald-400">${margin}%</span>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="grid grid-cols-3 gap-1.5 pt-1">
                                <button onclick="editMenuItem(${i.id})" class="h-9 rounded-xl bg-zinc-800 text-zinc-200 font-bold text-xs hover:bg-amber-500 hover:text-zinc-950">Edit</button>
                                <button onclick="toggleItemStatus(${i.id}, '${isSoldOut ? 'active' : 'sold_out'}')" class="h-9 rounded-xl ${isSoldOut ? 'bg-emerald-500/20 text-emerald-400' : 'bg-amber-500/20 text-amber-400'} font-bold text-xs">
                                    ${isSoldOut ? 'Restock' : 'Sold Out'}
                                </button>
                                <button onclick="deleteMenuItem(${i.id})" class="h-9 rounded-xl bg-rose-500/10 text-rose-400 font-bold text-xs hover:bg-rose-500 hover:text-white">Delete</button>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function openCreateItemModal() {
            document.getElementById('modalFormTitle').textContent = '➕ Create New Menu Item';
            document.getElementById('formAction').value = 'create';
            document.getElementById('formId').value = '0';
            document.getElementById('itemForm').reset();
            document.getElementById('imagePreviewBox').classList.add('hidden');
            switchFormTab('general');
            document.getElementById('itemModal').classList.remove('hidden');
        }

        function editMenuItem(id) {
            const item = allMenuItems.find(x => x.id == id);
            if (!item) return;

            document.getElementById('modalFormTitle').textContent = '✏️ Edit Menu Item #' + item.id;
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = item.id;
            document.getElementById('inputName').value = item.name;
            document.getElementById('inputSKU').value = item.sku || '';
            document.getElementById('inputCategory').value = item.category_id;
            document.getElementById('inputDietary').value = item.dietary_type || 'veg';
            document.getElementById('inputDescription').value = item.description || '';
            document.getElementById('inputPrice').value = item.price;
            document.getElementById('inputCostPrice').value = item.cost_price || 0;
            document.getElementById('inputStockQuantity').value = item.stock_quantity || 50;
            document.getElementById('inputMinStockLevel').value = item.min_stock_level || 10;
            document.getElementById('inputPrepTime').value = item.preparation_time || 15;
            document.getElementById('inputStatus').value = item.status;
            document.getElementById('inputAllergens').value = item.allergens || '';
            document.getElementById('formExistingImage').value = item.image || '';

            if (item.image) {
                document.getElementById('imagePreviewImg').src = '../' + item.image;
                document.getElementById('imagePreviewBox').classList.remove('hidden');
            }

            switchFormTab('general');
            document.getElementById('itemModal').classList.remove('hidden');
        }

        function switchFormTab(tabName) {
            ['general', 'pricing', 'inventory', 'media'].forEach(t => {
                const btn = document.getElementById('tabBtn' + t.charAt(0).toUpperCase() + t.slice(1));
                const content = document.getElementById('tabContent' + t.charAt(0).toUpperCase() + t.slice(1));
                if (t === tabName) {
                    btn.className = 'px-4 py-2 border-b-2 border-amber-500 text-amber-400 font-bold text-xs';
                    content.classList.remove('hidden');
                } else {
                    btn.className = 'px-4 py-2 border-b-2 border-transparent text-zinc-400 hover:text-white font-bold text-xs';
                    content.classList.add('hidden');
                }
            });
        }

        function closeItemModal() {
            document.getElementById('itemModal').classList.add('hidden');
        }

        function toggleItemStatus(id, newStatus) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="id" value="${id}">
                <input type="hidden" name="status" value="${newStatus}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function deleteMenuItem(id) {
            if (!confirm('Are you sure you want to delete this menu item?')) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        // Initialize Realtime Polling Stream (Every 4 seconds)
        document.addEventListener('DOMContentLoaded', () => {
            refreshMenuStream();
            setInterval(refreshMenuStream, 4000);
        });
    </script>
</body>
</html>
