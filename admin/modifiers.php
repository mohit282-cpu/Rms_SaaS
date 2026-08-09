<?php
// admin/modifiers.php - Product Modifiers & Add-ons Management UI (Phase 2)
require_once __DIR__ . '/../config.php';
requireAdminLogin();
RBAC::requirePermission('manage_modifiers');

$currentPage = 'modifiers';
$tenantId = TenantContext::getTenantId();
$groups = ModifierService::getModifierGroups($tenantId);
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Product Modifiers & Add-ons - QR Cafe</title>
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

    <div class="flex min-h-screen">
        <?php include 'includes/sidebar.php'; ?>

        <main class="flex-1 md:pl-64">
            <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-lg md:text-xl font-black text-white flex items-center gap-2">
                        <span>🧪</span> Product Modifiers & Add-ons
                    </h1>
                    <p class="text-xs text-zinc-400">Configure dish sizes, spice levels, toppings, and extra add-ons</p>
                </div>
                <button onclick="openGroupModal()" class="h-10 px-5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110 active:scale-95 transition-all flex items-center gap-2 shadow-lg shadow-amber-500/20">
                    <span>➕</span> <span>New Modifier Group</span>
                </button>
            </header>

            <div class="p-4 md:p-8 max-w-6xl mx-auto space-y-6">

                <?php if (empty($groups)): ?>
                    <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-12 text-center space-y-3">
                        <div class="text-4xl">🧪</div>
                        <h3 class="text-base font-bold text-white">No Modifier Groups Created</h3>
                        <p class="text-xs text-zinc-400 max-w-sm mx-auto">Create modifier groups like 'Size Options', 'Spice Level', or 'Extra Toppings' to customize menu items in POS and QR Ordering.</p>
                        <button onclick="openGroupModal()" class="h-10 px-5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs">➕ Create First Group</button>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php foreach ($groups as $g): ?>
                            <div class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-6 space-y-4 shadow-xl flex flex-col justify-between">
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <h3 class="font-black text-white text-base"><?= htmlspecialchars($g['name']) ?></h3>
                                                <?php if ($g['is_required']): ?>
                                                    <span class="px-2 py-0.5 rounded-md bg-rose-500/10 border border-rose-500/30 text-rose-400 font-bold text-[10px]">Required</span>
                                                <?php else: ?>
                                                    <span class="px-2 py-0.5 rounded-md bg-zinc-800 text-zinc-400 font-bold text-[10px]">Optional</span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-[10px] text-zinc-400 mt-0.5">Type: <?= ucfirst($g['selection_type']) ?> | Min: <?= $g['min_selections'] ?>, Max: <?= $g['max_selections'] ?></p>
                                        </div>
                                        <button onclick="deleteGroup(<?= $g['id'] ?>)" class="text-rose-400 hover:text-rose-300 font-bold text-xs p-1">🗑️</button>
                                    </div>

                                    <!-- Modifiers List -->
                                    <div class="space-y-2">
                                        <?php if (empty($g['modifiers'])): ?>
                                            <p class="text-xs text-zinc-500 italic py-2">No option items in this group yet.</p>
                                        <?php else: ?>
                                            <?php foreach ($g['modifiers'] as $m): ?>
                                                <div class="flex items-center justify-between bg-zinc-950/80 border border-zinc-800 p-2.5 rounded-2xl text-xs">
                                                    <span class="font-bold text-white"><?= htmlspecialchars($m['name']) ?></span>
                                                    <div class="flex items-center gap-3">
                                                        <span class="font-black text-amber-400">+NPR <?= number_format($m['price'], 2) ?></span>
                                                        <button onclick="deleteModifier(<?= $m['id'] ?>)" class="text-rose-400 hover:text-rose-300 text-xs">✕</button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="pt-3 border-t border-zinc-800">
                                    <button onclick="openAddModifierModal(<?= $g['id'] ?>, '<?= htmlspecialchars($g['name']) ?>')" class="w-full h-10 rounded-2xl bg-zinc-950 border border-zinc-800 text-amber-400 font-bold text-xs hover:border-amber-500">
                                        ➕ Add Option to <?= htmlspecialchars($g['name']) ?>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </main>
    </div>

    <!-- CREATE GROUP MODAL -->
    <div id="groupModal" class="fixed inset-0 z-50 hidden bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 w-full max-w-md space-y-4 shadow-2xl">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <h3 class="text-sm font-black text-white">New Modifier Group</h3>
                <button onclick="closeGroupModal()" class="text-zinc-500 hover:text-white font-bold text-sm">✕</button>
            </div>

            <form id="groupForm" onsubmit="event.preventDefault(); submitGroup();" class="space-y-4">
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="action" value="create_group">

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Group Name</label>
                    <input type="text" name="name" required placeholder="e.g. Dish Size or Spice Level" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-zinc-300">Selection Type</label>
                        <select name="selection_type" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                            <option value="single">Single Select (Radio)</option>
                            <option value="multiple">Multi Select (Checkbox)</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2 pt-6">
                        <input type="checkbox" name="is_required" value="1" class="w-4 h-4 accent-amber-500 cursor-pointer">
                        <span class="text-xs font-bold text-zinc-300">Required Selection</span>
                    </div>
                </div>

                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110">Save Modifier Group</button>
            </form>
        </div>
    </div>

    <!-- ADD MODIFIER ITEM MODAL -->
    <div id="modModal" class="fixed inset-0 z-50 hidden bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 w-full max-w-md space-y-4 shadow-2xl">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <h3 class="text-sm font-black text-white">Add Option Item</h3>
                <button onclick="closeModModal()" class="text-zinc-500 hover:text-white font-bold text-sm">✕</button>
            </div>

            <form id="modForm" onsubmit="event.preventDefault(); submitModifier();" class="space-y-4">
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="action" value="add_modifier">
                <input type="hidden" name="group_id" id="targetGroupId">

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Option Name</label>
                    <input type="text" name="name" required placeholder="e.g. Extra Cheese or Full Portion" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white outline-none focus:border-amber-500">
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-zinc-300">Additional Price (NPR)</label>
                    <input type="number" step="0.01" name="price" value="0.00" class="w-full h-11 bg-zinc-950 border border-zinc-800 rounded-2xl px-4 text-xs text-white font-bold outline-none focus:border-amber-500">
                </div>

                <button type="submit" class="w-full h-11 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs hover:brightness-110">Save Option Item</button>
            </form>
        </div>
    </div>

    <script src="../js/modern.js"></script>
    <script>
        function openGroupModal() { document.getElementById('groupModal').classList.remove('hidden'); }
        function closeGroupModal() { document.getElementById('groupModal').classList.add('hidden'); }
        function openAddModifierModal(gid, name) {
            document.getElementById('targetGroupId').value = gid;
            document.getElementById('modModal').classList.remove('hidden');
        }
        function closeModModal() { document.getElementById('modModal').classList.add('hidden'); }

        function submitGroup() {
            const formData = new FormData(document.getElementById('groupForm'));
            fetch('../api/modifiers.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Modifier group created!', 'success'); setTimeout(() => location.reload(), 800); }
                    else showToast(data.message || 'Error creating group', 'error');
                });
        }

        function submitModifier() {
            const formData = new FormData(document.getElementById('modForm'));
            fetch('../api/modifiers.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Modifier option added!', 'success'); setTimeout(() => location.reload(), 800); }
                    else showToast(data.message || 'Error adding modifier', 'error');
                });
        }

        function deleteGroup(id) {
            if (!confirm('Delete this modifier group and all its options?')) return;
            const formData = new FormData();
            formData.append('action', 'delete_group');
            formData.append('group_id', id);
            formData.append('csrf_token', '<?= CSRF::generateToken() ?>');

            fetch('../api/modifiers.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Group deleted!', 'success'); setTimeout(() => location.reload(), 800); }
                });
        }

        function deleteModifier(id) {
            if (!confirm('Delete this option?')) return;
            const formData = new FormData();
            formData.append('action', 'delete_modifier');
            formData.append('modifier_id', id);
            formData.append('csrf_token', '<?= CSRF::generateToken() ?>');

            fetch('../api/modifiers.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { showToast('Option deleted!', 'success'); setTimeout(() => location.reload(), 800); }
                });
        }
    </script>
</body>
</html>
