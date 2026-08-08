<?php
// admin/inventory-categories.php - Inventory Categories Management
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Inventory Categories';
$currentPage = 'inventory-categories';
$conn = getDBConnection();
$csrfToken = CSRF::generateToken();
include 'includes/header.php';
include 'includes/sidebar.php';
?>

    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-black text-white">🏷️ Inventory Categories</h1>
                    <p class="text-xs text-zinc-400">Classify stock items (Vegetables, Meat, Seafood, Dairy, Spices, Packaging, etc.)</p>
                </div>
                <button onclick="openCategoryModal()" class="h-10 px-4 rounded-2xl bg-amber-500 hover:bg-amber-400 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">
                    + Add Category
                </button>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 pb-12">
            <div id="categoriesGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <div class="col-span-full text-center py-12 text-zinc-500 text-sm">Loading categories...</div>
            </div>
        </main>
    </div>

    <!-- CATEGORY MODAL -->
    <div id="categoryModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-md shadow-2xl">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 id="modalTitle" class="text-sm font-black text-white">Add Inventory Category</h2>
                <button onclick="closeCategoryModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <form id="categoryForm" class="p-5 space-y-4" onsubmit="saveCategory(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" id="catId" value="0">
                <input type="hidden" name="action" value="save_category">
                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Category Name *</label>
                    <input type="text" name="name" id="fName" required placeholder="e.g. Vegetables, Seafood" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Icon Emoji</label>
                        <input type="text" name="icon" id="fIcon" value="📦" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none text-center">
                    </div>
                    <div>
                        <label class="text-[11px] text-zinc-400 font-bold block mb-1">Display Order</label>
                        <input type="number" name="display_order" id="fOrder" value="0" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none">
                    </div>
                </div>
                <div>
                    <label class="text-[11px] text-zinc-400 font-bold block mb-1">Description</label>
                    <textarea name="description" id="fDesc" rows="2" placeholder="Brief notes about this category" class="w-full px-3 py-2 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none resize-none"></textarea>
                </div>
                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">💾 Save Category</button>
            </form>
        </div>
    </div>

    <script>
        const API = '../api/inventory.php';
        const CSRF = '<?php echo $csrfToken; ?>';

        async function loadCategories() {
            const r = await fetch(API + '?action=list_categories');
            const j = await r.json();
            renderCategories(j.categories || []);
        }

        function renderCategories(items) {
            const grid = document.getElementById('categoriesGrid');
            if (items.length === 0) {
                grid.innerHTML = '<div class="col-span-full text-center py-12 text-zinc-500 text-sm">No categories found</div>';
                return;
            }
            grid.innerHTML = items.map(c => `
                <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-5 space-y-3 shadow-lg hover:border-amber-500/30 transition-all">
                    <div class="flex items-center justify-between">
                        <div class="w-12 h-12 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-2xl">
                            ${c.icon || '📦'}
                        </div>
                        <div class="flex items-center gap-1">
                            <button onclick="editCategory(${c.id}, '${c.name.replace(/'/g,"\\'")}', '${c.icon||'📦'}', '${(c.description||'').replace(/'/g,"\\'")}', ${c.display_order||0})" class="p-2 text-zinc-400 hover:text-amber-400 text-xs font-bold">✏️ Edit</button>
                            <button onclick="deleteCategory(${c.id})" class="p-2 text-rose-400 hover:text-rose-300 text-xs font-bold">🗑️</button>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-white">${c.name}</h3>
                        <p class="text-xs text-zinc-500 mt-1">${c.description || 'No description provided'}</p>
                    </div>
                    <div class="pt-2 border-t border-zinc-800/80 flex justify-between text-[10px] text-zinc-500">
                        <span>Order: ${c.display_order||0}</span>
                        <span class="text-emerald-400 font-bold">Active</span>
                    </div>
                </div>
            `).join('');
        }

        function openCategoryModal() {
            document.getElementById('modalTitle').textContent = 'Add Inventory Category';
            document.getElementById('catId').value = 0;
            document.getElementById('categoryForm').reset();
            document.getElementById('categoryModal').classList.remove('hidden');
            document.getElementById('categoryModal').classList.add('flex');
        }

        function closeCategoryModal() {
            document.getElementById('categoryModal').classList.add('hidden');
            document.getElementById('categoryModal').classList.remove('flex');
        }

        function editCategory(id, name, icon, desc, order) {
            document.getElementById('modalTitle').textContent = 'Edit Inventory Category';
            document.getElementById('catId').value = id;
            document.getElementById('fName').value = name;
            document.getElementById('fIcon').value = icon;
            document.getElementById('fDesc').value = desc;
            document.getElementById('fOrder').value = order;
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

        async function deleteCategory(id) {
            if (!confirm('Deactivate this inventory category?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_category');
            fd.append('id', id);
            fd.append('csrf_token', CSRF);
            await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
            loadCategories();
        }

        document.addEventListener('DOMContentLoaded', loadCategories);
    </script>
</body>
</html>
