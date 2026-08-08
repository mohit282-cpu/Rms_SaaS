<?php
// privacy-policy.php - RMS SaaS Platform Privacy Policy
require_once 'config.php';
Auth::startSession();
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth bg-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#09090b">
    <title>Privacy Policy — RMS SaaS Platform</title>
    <meta name="description" content="Privacy policy for RMS SaaS, the multi-restaurant restaurant management platform for Nepal.">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23f59e0b'/%3E%3Cpath d='M17.5 4 8 18h6.5L13 28l9.5-14H16l1.5-10z' fill='%2309090b'/%3E%3C/svg%3E">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; }
        a:focus-visible, button:focus-visible { outline: 2px solid #f59e0b; outline-offset: 2px; border-radius: 6px; }
    </style>
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased font-sans">
    <header class="sticky top-0 z-50 bg-zinc-950/85 backdrop-blur-xl border-b border-zinc-800/80">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 h-[68px] flex items-center justify-between">
            <a href="index.php" class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-amber-500 to-amber-400 text-zinc-950 flex items-center justify-center">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
                </div>
                <div class="leading-tight">
                    <span class="block text-base font-extrabold tracking-tight text-white">RMS SaaS</span>
                    <span class="block text-[10px] font-bold uppercase tracking-widest text-zinc-400">Restaurant Operating System</span>
                </div>
            </a>
            <a href="index.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-zinc-800 bg-zinc-900 text-[13px] font-bold text-zinc-200 hover:text-white hover:border-zinc-700 transition-all">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                Back to Home
            </a>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="mb-10">
            <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] font-extrabold uppercase tracking-[0.16em] mb-4">Legal</div>
            <h1 class="text-3xl sm:text-4xl font-black text-white tracking-tight mb-3">Privacy Policy</h1>
            <p class="text-sm text-zinc-500 font-medium">Last updated: August 2026</p>
        </div>

        <div class="space-y-10 text-[15px] text-zinc-300 leading-relaxed">
            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">1. Overview</h2>
                <p>RMS SaaS ("RMS", "we", "our") is a multi-restaurant restaurant management platform. This Privacy Policy explains how we collect, use, store and protect information when you visit our website, request a demo, or use the platform.</p>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">2. Information We Collect</h2>
                <ul class="list-disc pl-6 space-y-1.5">
                    <li><strong class="text-white">Restaurant details</strong> you submit through the onboarding request form — restaurant name, owner name, email, phone, PAN/VAT number, restaurant type, table count, preferred plan and address.</li>
                    <li><strong class="text-white">Account credentials</strong> required to operate a restaurant workspace (staff names, emails and role assignments).</li>
                    <li><strong class="text-white">Operational data</strong> generated while using the platform — orders, menu, inventory, payments, tables and reports for your restaurant workspace.</li>
                    <li><strong class="text-white">Technical information</strong> such as IP address and request data, used for security and rate limiting.</li>
                </ul>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">3. How We Use Information</h2>
                <ul class="list-disc pl-6 space-y-1.5">
                    <li>To review and fulfil restaurant onboarding and demo requests.</li>
                    <li>To provide, operate and secure your restaurant workspace.</li>
                    <li>To notify restaurant owners about platform updates and account activity.</li>
                    <li>To maintain audit logs for security and accountability.</li>
                </ul>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">4. Tenant Isolation &amp; Data Separation</h2>
                <p>Each restaurant operates inside a logically isolated workspace. Data belonging to one restaurant is never accessible by another restaurant's staff or customers. Isolation is enforced at the session and database layer.</p>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">5. Security</h2>
                <p>We use commercially reasonable safeguards including password hashing (BCRYPT), secure session management, role-based access control and audit logging. Payment gateway credentials are stored per restaurant tenant and used only to configure settlement with the merchant's chosen gateway.</p>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">6. Sharing of Information</h2>
                <p>We do not sell personal information. Information is shared only where necessary to provide the service — for example, configuring a payment gateway a restaurant chooses to use, or as required by law.</p>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">7. Data Retention</h2>
                <p>Restaurant data is retained for as long as the workspace is active, and for a reasonable period afterwards to support account and billing queries. You may contact us to request access, correction or deletion of your data.</p>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">8. Contact</h2>
                <p>For privacy questions, contact us through the <a href="index.php#request-demo" class="text-amber-400 hover:underline font-bold">contact form</a> on our website.</p>
            </section>
        </div>
    </main>

    <footer class="border-t border-zinc-800 bg-zinc-950 py-8">
        <div class="max-w-4xl mx-auto px-4 text-center text-[12px] text-zinc-500">
            © 2026 RMS SaaS Platform. All rights reserved.
        </div>
    </footer>
</body>
</html>
