<?php
// admin/categories.php - Enterprise POS Category & Hierarchy Management System
require_once '../config.php';
requireAdminLogin();

$conn = getDBConnection();
if (!$conn) {
    die("Database connection error");
}

$tenantId = (int)($_SESSION['restaurant_id'] ?? 0);

// Fetch Parent Categories for Dropdown
$parent_cats_res = $conn->query("SELECT id, name FROM categories WHERE parent_id IS NULL AND restaurant_id = $tenantId ORDER BY name ASC");
$parent_categories = [];
if ($parent_cats_res) {
    while ($p = $parent_cats_res->fetch_assoc()) {
        $parent_categories[] = $p;
    }
}

// Handle Form Submissions (Create, Edit, Toggle Status, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    CSRF::requireValidToken();

    $action = $_POST['action'];

    if ($action === 'create' || $action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $name = Security::sanitize($_POST['name'] ?? '');
        $description = Security::sanitize($_POST['description'] ?? '');
        $icon = Security::sanitize($_POST['icon'] ?? '🍽️');
        $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
        $display_order = intval($_POST['display_order'] ?? 0);
        $status = Security::sanitize($_POST['status'] ?? 'active');

        if (!empty($name)) {
            $conn->begin_transaction();
            try {
                if ($action === 'create') {
                    $stmt = $conn->prepare("INSERT INTO categories (restaurant_id, name, description, parent_id, icon, display_order, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param("isssisi", $tenantId, $name, $description, $parent_id, $icon, $display_order, $status);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $_SESSION['success'] = "Category '$name' created successfully!";
                } elseif ($action === 'edit' && $id > 0) {
                    $stmt = $conn->prepare("UPDATE categories SET name = ?, description = ?, parent_id = ?, icon = ?, display_order = ?, status = ? WHERE id = ? AND restaurant_id = ?");
                    if ($stmt) {
                        $stmt->bind_param("ssisiisii", $name, $description, $parent_id, $icon, $display_order, $status, $id, $tenantId);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $_SESSION['success'] = "Category '$name' updated successfully!";
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'toggle_status') {
        $id = intval($_POST['id'] ?? 0);
        $new_status = Security::sanitize($_POST['status'] ?? 'active');
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE categories SET status = ? WHERE id = ? AND restaurant_id = ?");
            if ($stmt) {
                $stmt->bind_param("sii", $new_status, $id, $tenantId);
                $stmt->execute();
                $stmt->close();
            }
            $_SESSION['success'] = "Category visibility updated to " . strtoupper($new_status);
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $conn->begin_transaction();
            try {
                // Check if items are assigned
                $check_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM menu_items WHERE category_id = ? AND restaurant_id = ?");
                if ($check_stmt) {
                    $check_stmt->bind_param("ii", $id, $tenantId);
                    $check_stmt->execute();
                    $item_count = intval($check_stmt->get_result()->fetch_assoc()['cnt']);
                    $check_stmt->close();
                } else {
                    $item_count = 0;
                }

                if ($item_count > 0) {
                    $_SESSION['error'] = "Cannot delete category. Move or reassign $item_count menu items first.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM categories WHERE id = ? AND restaurant_id = ?");
                    if ($stmt) {
                        $stmt->bind_param("ii", $id, $tenantId);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $_SESSION['success'] = "Category deleted successfully!";
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        }
    }

    header('Location: categories.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>POS Category Management - QR Cafe</title>
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
    <?php $currentPage = 'categories'; include 'includes/sidebar.php'; ?>

    <!-- MAIN CONTENT AREA -->
    <div class="md:pl-64 min-h-screen">

        <!-- HEADER BAR -->
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg md:text-xl font-black text-white">Menu Category & Hierarchy System</h1>
                        <span class="flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[10px] font-black uppercase tracking-wider">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span> Live Catalog
                        </span>
                    </div>
                    <p class="text-xs text-zinc-400 hidden sm:block">Manage Menu Categories, Subcategories, Display Order & Real-Time Analytics</p>
                </div>

                <!-- Action Controls -->
                <div class="flex items-center gap-2 shrink-0">
                    <button onclick="openCreateCategoryModal()" class="h-10 px-4 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs flex items-center gap-1.5 active:scale-95 shadow-lg shadow-amber-500/20">
                        <span>➕</span> Create Category
                    </button>
                    <button onclick="refreshCategoriesStream()" class="h-10 px-3 rounded-2xl bg-zinc-900 border border-zinc-800 text-zinc-300 font-bold text-xs hover:border-amber-500/40">
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

            <!-- 1. TOP KPI METRICS SECTION (7 METRICS) -->
            <section class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-7 gap-3">
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">📂 Total Categories</span>
                    <div id="kpiTotalCategories" class="text-lg font-black text-white">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">📁 Subcategories</span>
                    <div id="kpiSubcategories" class="text-lg font-black text-amber-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🍽 Total Items</span>
                    <div id="kpiTotalItems" class="text-lg font-black text-white">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">🟢 Active</span>
                    <div id="kpiActiveCategories" class="text-lg font-black text-emerald-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">⚫ Hidden</span>
                    <div id="kpiHiddenCategories" class="text-lg font-black text-zinc-400">0</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">💰 Top Revenue</span>
                    <div id="kpiHighestRevenue" class="text-xs font-black text-emerald-400 truncate">N/A</div>
                </div>
                <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-2xl p-3 text-center space-y-1">
                    <span class="text-xs text-zinc-400 font-bold">⚠️ Empty</span>
                    <div id="kpiEmptyCategories" class="text-lg font-black text-rose-400">0</div>
                </div>
            </section>

            <!-- 2. SEARCH & FILTER CONTROLS BAR -->
            <section class="bg-zinc-900/90 border border-zinc-800/80 rounded-3xl p-4 shadow-xl space-y-3">
                <div class="flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
                    
                    <!-- Search Input -->
                    <div class="relative flex-1">
                        <span class="absolute left-3.5 top-3 text-zinc-500 text-xs">🔍</span>
                        <input type="text" id="searchInput" oninput="filterCategoriesGrid()" placeholder="Search Category Name, Description..." class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl pl-9 pr-4 text-xs text-white placeholder-zinc-500 outline-none focus:border-amber-500 font-medium">
                    </div>

                    <!-- Status Filters -->
                    <div class="flex items-center gap-1.5 overflow-x-auto no-scrollbar py-1">
                        <button onclick="setStatusFilter('all')" class="status-btn px-3.5 py-2 rounded-xl text-xs font-black bg-amber-500 text-zinc-950 shadow-md" data-status="all">All Categories</button>
                        <button onclick="setStatusFilter('active')" class="status-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-emerald-400 hover:text-white" data-status="active">🟢 Active</button>
                        <button onclick="setStatusFilter('hidden')" class="status-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white" data-status="hidden">⚫ Hidden</button>
                        <button onclick="setStatusFilter('subcategories')" class="status-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-amber-400 hover:text-white" data-status="subcategories">📁 Subcategories</button>
                    </div>
                </div>
            </section>

            <!-- 3. RICH CATEGORIES CARDS GRID -->
            <section class="space-y-4">
                <div id="categoriesGridContainer" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <div class="col-span-full py-12 text-center text-zinc-500">
                        <div class="text-4xl mb-2 animate-bounce">⏳</div>
                        <p class="font-bold text-xs">Loading Categories Catalog...</p>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- 4. RIGHT SLIDE-OVER CATEGORY DETAILS DRAWER -->
    <div id="categoryDrawer" class="fixed inset-y-0 right-0 z-50 w-full max-w-md bg-zinc-900 border-l border-zinc-800 shadow-2xl transform translate-x-full transition-transform duration-300 flex flex-col">
        
        <!-- Drawer Header -->
        <div class="p-5 border-b border-zinc-800 flex items-center justify-between bg-zinc-950/80">
            <div class="flex items-center gap-3">
                <div id="drawerIconBadge" class="w-12 h-12 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-2xl">🍛</div>
                <div>
                    <h3 id="drawerCategoryTitle" class="font-black text-white text-base">Main Dishes</h3>
                    <p id="drawerCategorySubtitle" class="text-xs text-zinc-400">12 Menu Items • Active</p>
                </div>
            </div>
            <button onclick="closeCategoryDrawer()" class="w-9 h-9 rounded-xl bg-zinc-800 text-zinc-400 hover:text-white font-bold flex items-center justify-center">✕</button>
        </div>

        <!-- Drawer Scrollable Body -->
        <div class="flex-1 overflow-y-auto p-5 space-y-6">
            
            <!-- Category Overview Cards -->
            <div class="grid grid-cols-2 gap-2 text-xs">
                <div class="bg-zinc-950 p-3 rounded-2xl border border-zinc-800">
                    <span class="text-[10px] font-bold text-zinc-500 block uppercase">Sales Today</span>
                    <span id="drawerSalesToday" class="font-black text-amber-400 text-sm">Rs.0</span>
                </div>
                <div class="bg-zinc-950 p-3 rounded-2xl border border-zinc-800">
                    <span class="text-[10px] font-bold text-zinc-500 block uppercase">Orders Today</span>
                    <span id="drawerOrdersToday" class="font-black text-white text-sm">0</span>
                </div>
            </div>

            <!-- ASSIGNED MENU ITEMS LIST -->
            <div class="space-y-2">
                <h4 class="text-xs font-black text-zinc-400 uppercase tracking-wider">Assigned Menu Items</h4>
                <div id="drawerItemsList" class="space-y-2">
                    <div class="text-center py-6 text-xs text-zinc-500 bg-zinc-950 rounded-2xl border border-zinc-800">
                        No menu items in this category
                    </div>
                </div>
            </div>

        </div>

        <!-- Drawer Footer -->
        <div class="p-4 border-t border-zinc-800 bg-zinc-950">
            <button onclick="closeCategoryDrawer()" class="w-full h-11 rounded-2xl bg-zinc-800 font-bold text-xs text-zinc-300">Close Panel</button>
        </div>
    </div>

    <!-- 5. CATEGORY CREATE / EDIT MODAL -->
    <div id="categoryModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <h3 id="modalCategoryTitle" class="font-black text-white text-base">➕ Create New Category</h3>
                <button onclick="closeCategoryModal()" class="text-zinc-400 hover:text-white font-bold">✕</button>
            </div>

            <form id="categoryForm" method="POST" action="categories.php" class="space-y-4">
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="formId" value="0">

                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block text-xs font-bold text-zinc-300 mb-1">Icon / Emoji</label>
                        <input type="text" name="icon" id="inputIcon" value="🍽️" required class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl text-center text-lg outline-none focus:border-amber-500 font-bold">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-bold text-zinc-300 mb-1">Category Name *</label>
                        <input type="text" name="name" id="inputName" required placeholder="e.g. Starters & Appetizers" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-zinc-300 mb-1">Parent Category (Optional)</label>
                    <select name="parent_id" id="inputParent" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500 font-bold">
                        <option value="">None (Top-Level Main Category)</option>
                        <?php foreach ($parent_categories as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-zinc-300 mb-1">Description</label>
                    <textarea name="description" id="inputDescription" rows="2" placeholder="Brief category summary..." class="w-full bg-zinc-950 border border-zinc-800 rounded-2xl p-3 text-xs text-white outline-none focus:border-amber-500 font-medium"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-zinc-300 mb-1">Display Order</label>
                        <input type="number" name="display_order" id="inputDisplayOrder" value="0" min="0" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-zinc-300 mb-1">Status</label>
                        <select name="status" id="inputStatus" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500 font-bold">
                            <option value="active">🟢 Active</option>
                            <option value="hidden">⚫ Hidden</option>
                        </select>
                    </div>
                </div>

                <div class="flex gap-2 pt-2 border-t border-zinc-800">
                    <button type="button" onclick="closeCategoryModal()" class="w-1/3 h-11 rounded-2xl bg-zinc-800 font-bold text-xs text-zinc-300">Cancel</button>
                    <button type="submit" class="w-2/3 h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">Save Category</button>
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
        <a href="categories.php" class="flex flex-col items-center gap-0.5 text-amber-500 font-black text-[10px]">
            <span class="text-lg">🏷️</span>
            <span>Categories</span>
        </a>
        <a href="menu-items.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">🍔</span>
            <span>Menu</span>
        </a>
        <a href="orders.php" class="flex flex-col items-center gap-0.5 text-zinc-400 font-bold text-[10px]">
            <span class="text-lg">📋</span>
            <span>Orders</span>
        </a>
    </nav>

    <!-- REALTIME CATEGORY MANAGEMENT CONTROLLER -->
    <script src="../js/modern.js"></script>
    <script>
        let allCategories = [];
        let selectedStatusFilter = 'all';
        let selectedCategoryId = null;

        function setStatusFilter(status) {
            selectedStatusFilter = status;
            document.querySelectorAll('.status-btn').forEach(btn => {
                if (btn.dataset.status === status) {
                    btn.className = 'status-btn px-3.5 py-2 rounded-xl text-xs font-black bg-amber-500 text-zinc-950 shadow-md';
                } else {
                    btn.className = 'status-btn px-3.5 py-2 rounded-xl text-xs font-bold bg-zinc-950 border border-zinc-800 text-zinc-400 hover:text-white';
                }
            });
            renderCategoriesGrid();
        }

        function refreshCategoriesStream() {
            fetch('../api/categories-stream.php')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        updateKPICards(data.kpi);
                        allCategories = data.categories || [];
                        renderCategoriesGrid();
                    }
                })
                .catch(err => console.error('Categories stream error:', err));
        }

        function updateKPICards(kpi) {
            if (!kpi) return;
            document.getElementById('kpiTotalCategories').textContent = kpi.total_categories || 0;
            document.getElementById('kpiSubcategories').textContent = kpi.subcategories || 0;
            document.getElementById('kpiTotalItems').textContent = kpi.total_items || 0;
            document.getElementById('kpiActiveCategories').textContent = kpi.active_categories || 0;
            document.getElementById('kpiHiddenCategories').textContent = kpi.hidden_categories || 0;
            document.getElementById('kpiHighestRevenue').textContent = kpi.highest_revenue_category || 'N/A';
            document.getElementById('kpiEmptyCategories').textContent = kpi.empty_categories || 0;
        }

        function filterCategoriesGrid() {
            renderCategoriesGrid();
        }

        function renderCategoriesGrid() {
            const container = document.getElementById('categoriesGridContainer');
            const search = document.getElementById('searchInput').value.trim().toLowerCase();

            let filtered = allCategories.filter(c => {
                const matchStatus = (selectedStatusFilter === 'all' || 
                    (selectedStatusFilter === 'active' && c.status !== 'hidden') ||
                    (selectedStatusFilter === 'hidden' && c.status === 'hidden') ||
                    (selectedStatusFilter === 'subcategories' && c.parent_id !== null)
                );
                const matchSearch = (!search || 
                    c.name.toLowerCase().includes(search) ||
                    (c.description && c.description.toLowerCase().includes(search))
                );
                return matchStatus && matchSearch;
            });

            if (filtered.length === 0) {
                container.innerHTML = `
                    <div class="col-span-full bg-zinc-900/80 border border-zinc-800 rounded-3xl p-12 text-center text-zinc-500">
                        <div class="text-4xl mb-2">🏷️</div>
                        <h4 class="font-bold text-sm text-zinc-300">No categories match active filters</h4>
                        <p class="text-xs text-zinc-500 mt-1">Try clearing search or active filters</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = filtered.map(c => {
                const isHidden = (c.status === 'hidden');

                return `
                    <div onclick="openCategoryDrawer(${c.id})" class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-4 cursor-pointer hover:border-amber-500/80 transition-all shadow-xl flex flex-col justify-between">
                        
                        <div class="space-y-3">
                            <!-- Header -->
                            <div class="flex items-center justify-between border-b border-zinc-800/80 pb-3">
                                <div class="flex items-center gap-3">
                                    <span class="text-2xl">${c.icon || '🍽️'}</span>
                                    <div>
                                        <h4 class="font-black text-white text-base">${c.name}</h4>
                                        ${c.parent_name ? `<span class="text-[10px] text-amber-400 font-bold block">📁 ${c.parent_name}</span>` : ''}
                                    </div>
                                </div>
                                <span class="px-2.5 py-0.5 rounded-full ${isHidden ? 'bg-zinc-800 text-zinc-400' : 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400'} font-extrabold text-[10px]">
                                    ${isHidden ? '⚫ Hidden' : '🟢 Active'}
                                </span>
                            </div>

                            ${c.description ? `<p class="text-xs text-zinc-400 line-clamp-2">${c.description}</p>` : ''}
                        </div>

                        <!-- Info Grid & Actions -->
                        <div class="space-y-3 pt-2 border-t border-zinc-800">
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <div class="bg-zinc-950 p-2 rounded-xl border border-zinc-800/60">
                                    <span class="text-[10px] font-bold text-zinc-500 block">Menu Items</span>
                                    <span class="font-extrabold text-white">${c.item_count} Items</span>
                                </div>
                                <div class="bg-zinc-950 p-2 rounded-xl border border-zinc-800/60">
                                    <span class="text-[10px] font-bold text-zinc-500 block">Sales Today</span>
                                    <span class="font-black text-amber-400">${formatPrice(c.revenue_today || 0)}</span>
                                </div>
                            </div>

                            <!-- Buttons -->
                            <div class="grid grid-cols-3 gap-1.5 pt-1">
                                <button onclick="event.stopPropagation(); editCategory(${c.id})" class="h-9 rounded-xl bg-zinc-800 text-zinc-200 font-bold text-xs hover:bg-amber-500 hover:text-zinc-950">Edit</button>
                                <button onclick="event.stopPropagation(); toggleCategoryStatus(${c.id}, '${isHidden ? 'active' : 'hidden'}')" class="h-9 rounded-xl ${isHidden ? 'bg-emerald-500/20 text-emerald-400' : 'bg-zinc-800 text-zinc-400'} font-bold text-xs">
                                    ${isHidden ? 'Show' : 'Hide'}
                                </button>
                                <button onclick="event.stopPropagation(); deleteCategory(${c.id})" class="h-9 rounded-xl bg-rose-500/10 text-rose-400 font-bold text-xs hover:bg-rose-500 hover:text-white">Delete</button>
                            </div>
                        </div>

                    </div>
                `;
            }).join('');
        }

        function openCategoryDrawer(catId) {
            selectedCategoryId = catId;
            const c = allCategories.find(x => x.id == catId);
            if (!c) return;

            document.getElementById('drawerIconBadge').textContent = c.icon || '🍽️';
            document.getElementById('drawerCategoryTitle').textContent = c.name;
            document.getElementById('drawerCategorySubtitle').textContent = c.item_count + ' Items • ' + (c.status === 'hidden' ? 'Hidden' : 'Active');
            document.getElementById('drawerSalesToday').textContent = formatPrice(c.revenue_today || 0);
            document.getElementById('drawerOrdersToday').textContent = c.orders_today || 0;

            const itemsContainer = document.getElementById('drawerItemsList');
            if (c.items && c.items.length > 0) {
                itemsContainer.innerHTML = c.items.map(i => `
                    <div class="flex justify-between items-center bg-zinc-950 p-2.5 rounded-xl border border-zinc-800 text-xs">
                        <span class="font-bold text-white">${i.name}</span>
                        <span class="font-black text-amber-400">${formatPrice(i.price)}</span>
                    </div>
                `).join('');
            } else {
                itemsContainer.innerHTML = `<div class="text-center py-6 text-xs text-zinc-500 bg-zinc-950 rounded-2xl border border-zinc-800">No items in this category</div>`;
            }

            document.getElementById('categoryDrawer').classList.remove('translate-x-full');
        }

        function closeCategoryDrawer() {
            document.getElementById('categoryDrawer').classList.add('translate-x-full');
        }

        function openCreateCategoryModal() {
            document.getElementById('modalCategoryTitle').textContent = '➕ Create New Category';
            document.getElementById('formAction').value = 'create';
            document.getElementById('formId').value = '0';
            document.getElementById('categoryForm').reset();
            document.getElementById('categoryModal').classList.remove('hidden');
        }

        function editCategory(id) {
            const c = allCategories.find(x => x.id == id);
            if (!c) return;

            document.getElementById('modalCategoryTitle').textContent = '✏️ Edit Category #' + c.id;
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = c.id;
            document.getElementById('inputIcon').value = c.icon || '🍽️';
            document.getElementById('inputName').value = c.name;
            document.getElementById('inputParent').value = c.parent_id || '';
            document.getElementById('inputDescription').value = c.description || '';
            document.getElementById('inputDisplayOrder').value = c.display_order || 0;
            document.getElementById('inputStatus').value = c.status || 'active';

            document.getElementById('categoryModal').classList.remove('hidden');
        }

        function closeCategoryModal() {
            document.getElementById('categoryModal').classList.add('hidden');
        }

        function toggleCategoryStatus(id, newStatus) {
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

        function deleteCategory(id) {
            if (!confirm('Are you sure you want to delete this category?')) return;
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
            refreshCategoriesStream();
            setInterval(refreshCategoriesStream, 4000);
        });
    </script>
</body>
</html>
