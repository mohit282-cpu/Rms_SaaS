<?php
// 404 Not Found Page for /admin.php
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-white">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 Page Not Found - QR Cafe</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full flex items-center justify-center p-4 text-center">
    <div class="max-w-md bg-zinc-900 border border-zinc-800 p-8 rounded-3xl space-y-4 shadow-2xl">
        <div class="text-6xl">⚠️</div>
        <h1 class="text-2xl font-black text-white">404 - Page Not Found</h1>
        <p class="text-xs text-zinc-400">The requested URL <code class="bg-zinc-950 px-2 py-1 rounded text-rose-400">/admin.php</code> was not found on this server.</p>
        <div class="pt-2">
            <a href="index.php" class="inline-block px-5 py-2.5 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95">
                Go to Home Portal
            </a>
        </div>
    </div>
</body>
</html>
