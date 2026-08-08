<?php
// landing-preview.php - Realtime Interactive Live Visual Preview & Direct Inline Editor Engine
require_once 'config.php';

$conn = getDBConnection();
$res = $conn ? $conn->query("SELECT * FROM landing_page_settings LIMIT 1") : null;
$settings = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : [];

$defaults = [
    'brand_name' => 'QR Cafe & Dining',
    'brand_logo' => '☕',
    'brand_logo_image' => '',
    'hero_badge' => '⭐ Gourmet Culinary Experience',
    'hero_title' => 'Artisanal Flavors & Modern Dining',
    'hero_subtitle' => 'Experience handcrafted gourmet meals, freshly brewed espresso, and seamless digital table ordering.',
    'hero_cta_primary' => 'Explore Full Menu',
    'hero_cta_secondary' => 'Order via QR',
    'about_title' => 'Crafting Unforgettable Culinary Memories',
    'about_text' => 'Founded with a passion for exceptional taste, our kitchen combines locally sourced organic ingredients with master culinary craftsmanship.',
    'location_address' => '123 Gourmet Boulevard, Foodville, FC 45678',
    'contact_phone' => '+1 (555) 234-5678',
    'contact_email' => 'hello@qrcafe.com',
    'opening_hours' => "Mon - Fri: 8:00 AM - 10:00 PM\nSat - Sun: 9:00 AM - 11:00 PM",
    'hero_image' => 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=1200'
];

$data = array_merge($defaults, $settings);
?>
<!DOCTYPE html>
<html lang="en" class="bg-zinc-950 text-zinc-100 selection:bg-amber-500 selection:text-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview - <?php echo htmlspecialchars($data['brand_name']); ?></title>
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
        .editable-element { transition: outline 0.15s ease, background-color 0.15s ease; cursor: pointer; }
        .editable-element:hover { outline: 2px dashed #f59e0b; background-color: rgba(245, 158, 11, 0.05); }
        .editable-element:focus { outline: 2px solid #f59e0b; background-color: rgba(245, 158, 11, 0.1); }
    </style>
</head>
<body class="font-sans antialiased">

    <!-- Header Navigation -->
    <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span id="pvLogoEmoji" class="editable-element text-2xl" contenteditable="true" data-field="brand_logo"><?php echo htmlspecialchars($data['brand_logo']); ?></span>
            <h1 id="pvBrandName" class="editable-element text-lg font-black text-white" contenteditable="true" data-field="brand_name"><?php echo htmlspecialchars($data['brand_name']); ?></h1>
        </div>
        <nav class="flex gap-4 text-xs font-bold text-zinc-400">
            <span class="hover:text-white cursor-pointer">Home</span>
            <span class="hover:text-white cursor-pointer">Menu</span>
            <span class="hover:text-white cursor-pointer">About</span>
            <span class="hover:text-white cursor-pointer">Contact</span>
        </nav>
    </header>

    <!-- Hero Section -->
    <section class="relative px-6 py-20 bg-gradient-to-b from-zinc-900 to-zinc-950 text-center space-y-6">
        <span id="pvHeroBadge" class="editable-element inline-block px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs font-black uppercase tracking-wider" contenteditable="true" data-field="hero_badge">
            <?php echo htmlspecialchars($data['hero_badge']); ?>
        </span>

        <h1 id="pvHeroTitle" class="editable-element text-3xl md:text-5xl font-black text-white max-w-3xl mx-auto leading-tight" contenteditable="true" data-field="hero_title">
            <?php echo htmlspecialchars($data['hero_title']); ?>
        </h1>

        <p id="pvHeroSubtitle" class="editable-element text-sm text-zinc-400 max-w-xl mx-auto font-medium" contenteditable="true" data-field="hero_subtitle">
            <?php echo htmlspecialchars($data['hero_subtitle']); ?>
        </p>

        <div class="flex justify-center gap-3 pt-2">
            <button id="pvCtaPrimary" class="editable-element px-6 py-3 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg shadow-amber-500/20" contenteditable="true" data-field="hero_cta_primary">
                <?php echo htmlspecialchars($data['hero_cta_primary']); ?>
            </button>
            <button id="pvCtaSecondary" class="editable-element px-6 py-3 rounded-2xl bg-zinc-900 border border-zinc-800 text-white font-bold text-xs" contenteditable="true" data-field="hero_cta_secondary">
                <?php echo htmlspecialchars($data['hero_cta_secondary']); ?>
            </button>
        </div>
    </section>

    <!-- About Section -->
    <section class="px-6 py-16 max-w-4xl mx-auto space-y-4 text-center">
        <h2 id="pvAboutTitle" class="editable-element text-2xl font-black text-white" contenteditable="true" data-field="about_title"><?php echo htmlspecialchars($data['about_title']); ?></h2>
        <p id="pvAboutText" class="editable-element text-sm text-zinc-400 leading-relaxed font-medium" contenteditable="true" data-field="about_text"><?php echo htmlspecialchars($data['about_text']); ?></p>
    </section>

    <!-- Contact & Location Footer Section -->
    <footer class="bg-zinc-900 border-t border-zinc-800 p-8 text-center text-xs text-zinc-400 space-y-2">
        <p id="pvAddress" class="editable-element font-bold text-zinc-300" contenteditable="true" data-field="location_address"><?php echo htmlspecialchars($data['location_address']); ?></p>
        <p>Phone: <span id="pvPhone" class="editable-element font-bold text-white" contenteditable="true" data-field="contact_phone"><?php echo htmlspecialchars($data['contact_phone']); ?></span> • Email: <span id="pvEmail" class="editable-element font-bold text-white" contenteditable="true" data-field="contact_email"><?php echo htmlspecialchars($data['contact_email']); ?></span></p>
    </footer>

    <!-- Real-Time Visual Sync Engine (Bi-Directional Messaging) -->
    <script>
        // Send inline edits back to parent CMS builder
        document.querySelectorAll('.editable-element').forEach(el => {
            el.addEventListener('input', function() {
                const field = this.dataset.field;
                const value = this.innerText;
                window.parent.postMessage({
                    type: 'INLINE_EDIT_FIELD',
                    field: field,
                    value: value
                }, '*');
            });
        });

        // Listen for parent CMS builder updates
        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'UPDATE_LANDING_PREVIEW') {
                const d = e.data.data;
                if (d.brand_name) document.getElementById('pvBrandName').innerText = d.brand_name;
                if (d.brand_logo) document.getElementById('pvLogoEmoji').innerText = d.brand_logo;
                if (d.hero_badge) document.getElementById('pvHeroBadge').innerText = d.hero_badge;
                if (d.hero_title) document.getElementById('pvHeroTitle').innerText = d.hero_title;
                if (d.hero_subtitle) document.getElementById('pvHeroSubtitle').innerText = d.hero_subtitle;
                if (d.hero_cta_primary) document.getElementById('pvCtaPrimary').innerText = d.hero_cta_primary;
                if (d.hero_cta_secondary) document.getElementById('pvCtaSecondary').innerText = d.hero_cta_secondary;
                if (d.about_title) document.getElementById('pvAboutTitle').innerText = d.about_title;
                if (d.about_text) document.getElementById('pvAboutText').innerText = d.about_text;
                if (d.location_address) document.getElementById('pvAddress').innerText = d.location_address;
                if (d.contact_phone) document.getElementById('pvPhone').innerText = d.contact_phone;
                if (d.contact_email) document.getElementById('pvEmail').innerText = d.contact_email;
            }
        });
    </script>
</body>
</html>
