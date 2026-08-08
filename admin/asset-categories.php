<?php
// admin/asset-categories.php - Asset Categories Management
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Asset Categories';
$currentPage = 'asset-categories';
$conn = getDBConnection();
$csrfToken = CSRF::generateToken();
include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-black text-white">🏷️ Asset Categories</h1>
                    <p class="text-xs text-zinc-400">Classify capital equipment (Kitchen, POS Hardware, HVAC, Furniture, Electronics, Vehicles)</p>
                </div>
                <button onclick="openCategoryModal()" class="h-10 px-4 rounded-2xl bg-amber-500 hover:bg-amber-400 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">
                    + Add Category
                </button>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 pb-12">
            <div id="categoriesGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <div class="col-span-full text-center py-12 text-zinc-500 text-sm">Loading asset categories...</div>
            </div>
        </main>
    </div>

    <!-- CATEGORY MODAL -->
    <div id="categoryModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-md shadow-2xl">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 id="modalTitle" class="text-sm font-black text-white">Add Asset Category</h2>
                <button onclick="closeCategoryModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <form id="categoryForm" class="p-5 space-y-4" onsubmit="saveCategory(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" id="catId" value="0">
                <input type="hidden" name="action" value="save_asset_category">
                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Category Name *</label>
                    <input type="text" name="name" id="fName" required placeholder="e.g. Kitchen Equipment, POS Hardware" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Icon Emoji</label>
                        <input type="text" name="icon" id="fIcon" value="🏗️" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none text-center">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Default Useful Life (Months)</label>
                        <input type="number" name="default_useful_life" id="fLife" value="60" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Depreciation Method</label>
                        <select name="depreciation_method" id="fMethod" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                            <option value="straight_line">Straight Line</option>
                            <option value="declining_balance">Declining Balance</option>
                            <option value="none">None</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Annual Rate (%)</label>
                        <input type="number" step="0.01" name="depreciation_rate" id="fRate" value="0.00" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                </div>
                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Description</label>
                    <textarea name="description" id="fDesc" rows="2" placeholder="Notes on this asset class" class="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none resize-none"></textarea>
                </div>
                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">💾 Save Asset Category</button>
            </form>
        </div>
    </div>

    <script>
        const API = '../api/assets.php';
        const CSRF = '<?php echo $csrfToken; ?>';

        async function loadCategories() {
            const r = await fetch(API + '?action=list_asset_categories');
            const j = await r.json();
            renderCategories(j.categories || []);
        }

        function renderCategories(items) {
            const grid = document.getElementById('categoriesGrid');
            if (items.length === 0) {
                grid.innerHTML = '<div class="col-span-full text-center py-12 text-zinc-500 text-sm">No asset categories found</div>';
                return;
            }

            grid.innerHTML = items.map(c => `
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-3 shadow-lg hover:border-amber-500/30 transition-all">
                    <div class="flex items-center justify-between">
                        <div class="w-12 h-12 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-2xl">
                            ${c.icon || '🏗️'}
                        </div>
                        <button onclick="editCategory(${c.id}, '${c.name.replace(/'/g,"\\'")}', '${c.icon||'🏗️'}', '${(c.description||'').replace(/'/g,"\\'")}', '${c.depreciation_method}', ${c.depreciation_rate||0}, ${c.default_useful_life||60})" class="p-2 text-zinc-400 hover:text-amber-400 text-xs font-bold">✏️ Edit</button>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-white">${c.name}</h3>
                        <p class="text-xs text-zinc-500 mt-1">${c.description || 'No description'}</p>
                    </div>
                    <div class="pt-2 border-t border-zinc-800/80 grid grid-cols-2 text-[10px] text-zinc-500">
                        <span>Depreciation: <strong class="text-amber-400">${(c.depreciation_method||'').replace('_',' ')}</strong></span>
                        <span class="text-right">Useful Life: <strong class="text-white">${c.default_useful_life||60}m</strong></span>
                    </div>
                </div>
            `).join('');
        }

        function openCategoryModal() {
            document.getElementById('modalTitle').textContent = 'Add Asset Category';
            document.getElementById('catId').value = 0;
            document.getElementById('categoryForm').reset();
            document.getElementById('categoryModal').classList.remove('hidden');
            document.getElementById('categoryModal').classList.add('flex');
        }

        function closeCategoryModal() {
            document.getElementById('categoryModal').classList.add('hidden');
            document.getElementById('categoryModal').classList.remove('flex');
        }

        function editCategory(id, name, icon, desc, method, rate, life) {
            document.getElementById('modalTitle').textContent = 'Edit Asset Category';
            document.getElementById('catId').value = id;
            document.getElementById('fName').value = name;
            document.getElementById('fIcon').value = icon;
            document.getElementById('fDesc').value = desc;
            document.getElementById('fMethod').value = method;
            document.getElementById('fRate').value = rate;
            document.getElementById('fLife').value = life;
            document.getElementById('categoryModal').classList.remove('hidden');
            document.getElementById('categoryModal').classList.add('flex');
        }

        async function saveCategory(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('categoryForm'));
            const r = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
            const j = await r.json();
            if (j.success) {
                closeCategoryModal();
                loadCategories();
            } else {
                alert(j.message);
            }
        }

        document.addEventListener('DOMContentLoaded', loadCategories);
    </script>
</body>
</html>
