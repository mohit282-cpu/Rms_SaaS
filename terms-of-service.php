<?php
// terms-of-service.php - RMS SaaS Platform Terms of Service
require_once 'config.php';
Auth::startSession();
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth bg-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#09090b">
    <title>Terms of Service — RMS SaaS Platform</title>
    <meta name="description" content="Terms of service for RMS SaaS, the multi-restaurant restaurant management platform for Nepal.">
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
            <h1 class="text-3xl sm:text-4xl font-black text-white tracking-tight mb-3">Terms of Service</h1>
            <p class="text-sm text-zinc-500 font-medium">Last updated: August 2026</p>
        </div>

        <div class="space-y-10 text-[15px] text-zinc-300 leading-relaxed">
            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">1. Acceptance of Terms</h2>
                <p>By accessing the RMS SaaS website or using the platform, you agree to these Terms of Service. If you use the platform on behalf of a restaurant, you represent that you are authorized to bind that restaurant.</p>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">2. Description of Service</h2>
                <p>RMS SaaS provides restaurant management software including POS billing, QR table ordering, Kitchen Display System, floor and table management, inventory management, asset management, payments, staff management, reporting and related services. Restaurant accounts are provisioned after review by the platform's Super Admin team.</p>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">3. Accounts &amp; Onboarding</h2>
                <ul class="list-disc pl-6 space-y-1.5">
                    <li>Submitting the request form does not guarantee an account. Requests are reviewed manually.</li>
                    <li>Each restaurant receives credentials for its own isolated workspace.</li>
                    <li>You are responsible for keeping credentials confidential and for activity under your account.</li>
                </ul>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">4. Plans &amp; Payments</h2>
                <ul class="list-disc pl-6 space-y-1.5">
                    <li>Plans are offered at the prices displayed on the pricing section (in NPR) and may change over time with notice.</li>
                    <li>Specific plan features and operational limits are defined by the plan the restaurant chooses.</li>
                    <li>No payment is collected through the website request form.</li>
                </ul>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">5. Payment Gateways</h2>
                <p>Digital payment gateway integrations (eSewa, Khalti, Fonepay, ConnectIPS, IME Pay and others) are configurable per restaurant using the restaurant's own merchant credentials. RMS is not a payment processor; settlement is handled by the gateway the restaurant configures.</p>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">6. Acceptable Use</h2>
                <ul class="list-disc pl-6 space-y-1.5">
                    <li>You may not attempt to access another restaurant's workspace or data.</li>
                    <li>You may not misuse, reverse-engineer or disrupt the platform.</li>
                    <li>You are responsible for the accuracy of the data you enter.</li>
                </ul>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">7. Availability &amp; Liability</h2>
                <p>The platform is provided "as is". We work to maintain high availability but do not guarantee uninterrupted service. To the maximum extent permitted by law, RMS SaaS is not liable for indirect or consequential damages arising from use of the platform.</p>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">8. Changes to Terms</h2>
                <p>We may update these terms from time to time. Continued use of the platform after changes constitutes acceptance of the updated terms.</p>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-extrabold text-white">9. Contact</h2>
                <p>For questions about these terms, contact us through the <a href="index.php#request-demo" class="text-amber-400 hover:underline font-bold">contact form</a> on our website.</p>
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
