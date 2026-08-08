<?php
require_once '../config.php';
requireAdminLogin();
$pageTitle = 'Recipe Management';
$currentPage = 'recipes';
$csrfToken = CSRF::generateToken();
include 'includes/header.php';
include 'includes/sidebar.php';
?>
    <div class="md:pl-64 min-h-screen">
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5">
            <div class="max-w-7xl mx-auto flex items-center justify-between gap-3">
                <div><h1 class="text-lg font-black text-white">🍳 Recipe Management</h1><p class="text-xs text-zinc-400">Bill of Materials — link menu items to inventory ingredients</p></div>
                <button onclick="openModal()" class="h-10 px-4 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">+ New Recipe</button>
            </div>
        </header>
        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-4 pb-8">
            <div id="recipeList" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                <div class="col-span-full text-center py-12 text-zinc-500 text-sm">Loading recipes...</div>
            </div>
        </main>
    </div>

    <!-- RECIPE MODAL -->
    <div id="modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl w-full max-w-2xl max-h-[90vh] overflow-y-auto no-scrollbar shadow-2xl">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 id="mTitle" class="text-sm font-black text-white">New Recipe</h2>
                <button onclick="closeModal()" class="text-zinc-400 hover:text-white text-lg">✕</button>
            </div>
            <form id="rForm" class="p-5 space-y-4" onsubmit="save(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" id="rId" value="0">
                <input type="hidden" name="action" value="save_recipe">
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Menu Item *</label>
                        <select name="menu_item_id" id="rMenuItem" required class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none"></select></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Recipe Name</label>
                        <input type="text" name="name" id="rName" placeholder="e.g. Chicken Momo Recipe" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50"></div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Yield Qty</label>
                        <input type="number" step="0.01" name="yield_qty" id="rYield" value="1" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none"></div>
                    <div><label class="text-[11px] text-zinc-400 font-bold block mb-1">Notes</label>
                        <input type="text" name="notes" id="rNotes" class="w-full h-10 px-3 rounded-xl bg-zinc-950 border border-zinc-800 text-xs text-white outline-none focus:border-amber-500/50"></div>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-[11px] text-zinc-400 font-bold">🥬 Ingredients</label>
                        <button type="button" onclick="addIngredient()" class="text-[10px] text-amber-400 font-bold">+ Add Ingredient</button>
                    </div>
                    <div id="ingredients" class="space-y-2"></div>
                    <div id="recipeCost" class="mt-2 text-right text-xs font-black text-amber-400">Estimated Cost: Rs.0</div>
                </div>
                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20">💾 Save Recipe</button>
            </form>
        </div>
    </div>

    <script>
        const API = '../api/inventory.php';
        const CSRF = '<?php echo $csrfToken; ?>';
        let menuItems=[], invItems=[], units=[];

        async function init() {
            const [mR, iR, uR] = await Promise.all([
                fetch(API+'?action=list_menu_items').then(r=>r.json()),
                fetch(API+'?action=list_items&status=active').then(r=>r.json()),
                fetch(API+'?action=list_units').then(r=>r.json())
            ]);
            menuItems = mR.menu_items||[];
            invItems = iR.items||[];
            units = uR.units||[];
            document.getElementById('rMenuItem').innerHTML = '<option value="">— Select Menu Item —</option>' + menuItems.map(m=>`<option value="${m.id}">${m.name} (Rs.${m.price})</option>`).join('');
            loadRecipes();
        }

        async function loadRecipes() {
            const r = await fetch(API+'?action=list_recipes');
            const j = await r.json();
            const el = document.getElementById('recipeList');
            const items = j.recipes||[];
            if (items.length===0) { el.innerHTML='<div class="col-span-full text-center py-12"><div class="text-4xl mb-3">🍳</div><div class="text-zinc-500 text-sm">No recipes yet</div></div>'; return; }
            el.innerHTML = items.map(r => {
                const cost = parseFloat(r.recipe_cost||0);
                const price = parseFloat(r.menu_price||0);
                const margin = price > 0 ? Math.round(((price-cost)/price)*100) : 0;
                const marginColor = margin >= 60 ? 'emerald' : margin >= 40 ? 'amber' : 'rose';
                return `<div class="bg-zinc-900/90 border border-zinc-800 rounded-2xl p-4 space-y-3 hover:border-amber-500/30 transition-all cursor-pointer" onclick="editRecipe(${r.id})">
                    <div class="flex items-start justify-between">
                        <div><div class="text-xs font-black text-white">${r.menu_item_name}</div>
                        <div class="text-[10px] text-zinc-500">${r.name||'Recipe'} · ${r.ingredient_count} ingredients</div></div>
                        <span class="px-2 py-0.5 rounded-full bg-${marginColor}-500/10 border border-${marginColor}-500/30 text-${marginColor}-400 text-[9px] font-black">${margin}% margin</span>
                    </div>
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="bg-zinc-950 rounded-xl p-2"><div class="text-[10px] text-zinc-500">Sell Price</div><div class="text-xs font-black text-white">Rs.${price.toFixed(0)}</div></div>
                        <div class="bg-zinc-950 rounded-xl p-2"><div class="text-[10px] text-zinc-500">Recipe Cost</div><div class="text-xs font-black text-amber-400">Rs.${cost.toFixed(0)}</div></div>
                        <div class="bg-zinc-950 rounded-xl p-2"><div class="text-[10px] text-zinc-500">Profit</div><div class="text-xs font-black text-${marginColor}-400">Rs.${(price-cost).toFixed(0)}</div></div>
                    </div>
                </div>`;
            }).join('');
        }

        function openModal(id=0) { document.getElementById('mTitle').textContent=id?'Edit Recipe':'New Recipe'; document.getElementById('rId').value=id; document.getElementById('rForm').reset(); document.getElementById('rId').value=id; document.getElementById('ingredients').innerHTML=''; addIngredient(); document.getElementById('modal').classList.remove('hidden'); document.getElementById('modal').classList.add('flex'); }
        function closeModal() { document.getElementById('modal').classList.add('hidden'); document.getElementById('modal').classList.remove('flex'); }

        function addIngredient(data={}) {
            const div = document.createElement('div');
            div.className = 'grid grid-cols-12 gap-2 items-end ing-row';
            div.innerHTML = `
                <select class="col-span-5 h-9 px-2 rounded-lg bg-zinc-950 border border-zinc-800 text-[10px] text-white outline-none ing-item">
                    <option value="">Select ingredient</option>
                    ${invItems.map(i=>`<option value="${i.id}" data-cost="${i.average_cost||i.purchase_cost}" ${data.inventory_item_id==i.id?'selected':''}>${i.name}</option>`).join('')}
                </select>
                <input type="number" step="0.001" placeholder="Qty" value="${data.quantity||''}" class="col-span-3 h-9 px-2 rounded-lg bg-zinc-950 border border-zinc-800 text-[10px] text-white outline-none ing-qty">
                <select class="col-span-3 h-9 px-2 rounded-lg bg-zinc-950 border border-zinc-800 text-[10px] text-white outline-none ing-unit">
                    <option value="">Unit</option>
                    ${units.map(u=>`<option value="${u.id}" ${data.unit_id==u.id?'selected':''}>${u.abbreviation}</option>`).join('')}
                </select>
                <button type="button" onclick="this.closest('.ing-row').remove();calcCost()" class="col-span-1 h-9 rounded-lg bg-zinc-950 border border-zinc-800 text-rose-400 text-xs">✕</button>
            `;
            div.querySelector('.ing-item').addEventListener('change', calcCost);
            div.querySelector('.ing-qty').addEventListener('input', calcCost);
            document.getElementById('ingredients').appendChild(div);
        }

        function calcCost() {
            let total = 0;
            document.querySelectorAll('.ing-row').forEach(row => {
                const sel = row.querySelector('.ing-item');
                const cost = parseFloat(sel.selectedOptions[0]?.dataset.cost||0);
                const qty = parseFloat(row.querySelector('.ing-qty').value||0);
                total += cost * qty;
            });
            document.getElementById('recipeCost').textContent = 'Estimated Cost: Rs.' + total.toFixed(2);
        }

        async function editRecipe(id) {
            const r = await fetch(`${API}?action=get_recipe&id=${id}`);
            const j = await r.json();
            if (!j.success) return;
            openModal(id);
            document.getElementById('rMenuItem').value = j.recipe.menu_item_id;
            document.getElementById('rName').value = j.recipe.name||'';
            document.getElementById('rYield').value = j.recipe.yield_qty||1;
            document.getElementById('rNotes').value = j.recipe.notes||'';
            document.getElementById('ingredients').innerHTML = '';
            (j.ingredients||[]).forEach(i => addIngredient(i));
            if ((j.ingredients||[]).length===0) addIngredient();
            calcCost();
        }

        async function save(e) {
            e.preventDefault();
            const ings = [];
            document.querySelectorAll('.ing-row').forEach(row => {
                const item_id = row.querySelector('.ing-item').value;
                const qty = row.querySelector('.ing-qty').value;
                const unit_id = row.querySelector('.ing-unit').value;
                if (item_id && qty > 0) ings.push({inventory_item_id:item_id,quantity:qty,unit_id:unit_id||0});
            });
            if (ings.length===0) { alert('Add at least one ingredient'); return; }
            const fd = new FormData(document.getElementById('rForm'));
            fd.append('ingredients', JSON.stringify(ings));
            const r = await fetch(API,{method:'POST',body:fd,credentials:'same-origin'});
            const j = await r.json();
            if (j.success) { closeModal(); loadRecipes(); } else { alert(j.message); }
        }

        document.addEventListener('DOMContentLoaded', init);
    </script>
</body>
</html>
