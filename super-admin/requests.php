<?php
// super-admin/requests.php - Public Onboarding Requests Management Workflow
$pageTitle = 'Restaurant Onboarding Requests';
require_once __DIR__ . '/includes/header.php';

$conn = getDBConnection();
$message = null;
$error = null;

// Handle Request Status Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verifyToken($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed.";
    } else {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $notes = Security::sanitize(trim($_POST['internal_notes'] ?? ''));

        if ($reqId > 0 && $conn) {
            if ($action === 'mark_contacted') {
                $conn->query("UPDATE restaurant_requests SET status = 'CONTACTED', internal_notes = '{$notes}' WHERE id = {$reqId}");
                $message = "Request status updated to CONTACTED.";
            } elseif ($action === 'reject') {
                $conn->query("UPDATE restaurant_requests SET status = 'REJECTED', internal_notes = '{$notes}' WHERE id = {$reqId}");
                $message = "Request rejected.";
            } elseif ($action === 'update_notes') {
                $conn->query("UPDATE restaurant_requests SET internal_notes = '{$notes}' WHERE id = {$reqId}");
                $message = "Internal notes saved.";
            }
        }
    }
}

// Fetch all requests
$requests = [];
if ($conn) {
    $res = $conn->query("SELECT * FROM restaurant_requests ORDER BY id DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $requests[] = $row;
        }
    }
}

$csrfField = CSRF::getField();
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-zinc-800 pb-6">
        <div>
            <h1 class="text-2xl font-black text-white tracking-tight">Onboarding Requests Pipeline</h1>
            <p class="text-xs text-zinc-400 mt-1 font-medium">Review, contact, approve, and convert incoming restaurant demo requests.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold">
            ✅ <?= $message ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">
            ⚠️ <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl overflow-hidden shadow-2xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-zinc-800 bg-zinc-950/60 text-[11px] font-black uppercase text-zinc-400 tracking-wider">
                        <th class="py-3.5 px-4">Restaurant & Owner</th>
                        <th class="py-3.5 px-4">Contact Info</th>
                        <th class="py-3.5 px-4">Details</th>
                        <th class="py-3.5 px-4">Status</th>
                        <th class="py-3.5 px-4 text-right">Actions Workflow</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/60 text-xs">
                    <?php if (empty($requests)): ?>
                        <tr>
                            <td colspan="5" class="py-8 text-center text-zinc-500 font-semibold">
                                No restaurant onboarding requests received yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $req): ?>
                            <tr class="hover:bg-zinc-800/30 transition-colors">
                                <td class="py-4 px-4">
                                    <div class="font-bold text-white text-sm"><?= htmlspecialchars($req['restaurant_name']) ?></div>
                                    <div class="text-xs text-zinc-300 font-medium">Owner: <?= htmlspecialchars($req['owner_name']) ?></div>
                                    <div class="text-[10px] text-zinc-500 mt-1">Submitted <?= date('M d, Y H:i', strtotime($req['created_at'])) ?></div>
                                </td>

                                <td class="py-4 px-4">
                                    <div class="font-mono text-amber-400 font-bold"><?= htmlspecialchars($req['phone']) ?></div>
                                    <div class="text-zinc-400 text-[11px]"><?= htmlspecialchars($req['email']) ?></div>
                                    <div class="text-zinc-500 text-[10px]">PAN: <?= htmlspecialchars($req['pan_number'] ?: 'N/A') ?></div>
                                </td>

                                <td class="py-4 px-4">
                                    <div class="text-zinc-300 font-semibold"><?= htmlspecialchars($req['restaurant_type']) ?></div>
                                    <div class="text-[11px] text-zinc-400"><?= (int)$req['table_count'] ?> Tables Requested</div>
                                    <?php if (!empty($req['message'])): ?>
                                        <div class="text-[10px] text-zinc-500 italic mt-1 max-w-xs truncate">"<?= htmlspecialchars($req['message']) ?>"</div>
                                    <?php endif; ?>
                                </td>

                                <td class="py-4 px-4">
                                    <?php
                                    $st = $req['status'];
                                    $badge = 'bg-zinc-800 text-zinc-400';
                                    if ($st === 'PENDING') $badge = 'bg-amber-500/10 text-amber-400 border border-amber-500/20';
                                    elseif ($st === 'CONTACTED') $badge = 'bg-blue-500/10 text-blue-400 border border-blue-500/20';
                                    elseif ($st === 'APPROVED' || $st === 'CONVERTED') $badge = 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
                                    elseif ($st === 'REJECTED') $badge = 'bg-rose-500/10 text-rose-400 border border-rose-500/20';
                                    ?>
                                    <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase <?= $badge ?>">
                                        <?= htmlspecialchars($st) ?>
                                    </span>
                                </td>

                                <td class="py-4 px-4 text-right">
                                    <div class="flex items-center justify-end space-x-1.5">
                                        <?php if ($st === 'PENDING' || $st === 'CONTACTED'): ?>
                                            <!-- Approve & Convert to Account -->
                                            <a href="create-restaurant.php?request_id=<?= $req['id'] ?>" class="px-3 py-1.5 rounded-xl bg-emerald-500 text-zinc-950 font-black text-xs hover:bg-emerald-400 transition-all shadow-md shadow-emerald-500/20">
                                                ✓ Approve & Onboard
                                            </a>

                                            <!-- Mark Contacted -->
                                            <?php if ($st === 'PENDING'): ?>
                                                <form method="POST" class="inline">
                                                    <?= $csrfField ?>
                                                    <input type="hidden" name="action" value="mark_contacted">
                                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                                    <button type="submit" class="px-3 py-1.5 rounded-xl bg-blue-500/10 border border-blue-500/30 text-blue-400 hover:bg-blue-500/20 text-xs font-bold transition-all">
                                                        Mark Contacted
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Reject -->
                                            <form method="POST" class="inline" onsubmit="return confirm('Reject this request?');">
                                                <?= $csrfField ?>
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                                <button type="submit" class="px-2.5 py-1.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 hover:bg-rose-500/20 text-xs font-bold transition-all">
                                                    ✕ Reject
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-[11px] text-zinc-500 font-medium">Processed</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
