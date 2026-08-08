<?php
require_once 'config.php';

$conn = getDBConnection();

// Fetch Landing Page Settings
$defaults = [
    'brand_name' => 'QR Cafe & Dining',
    'brand_logo' => '☕',
    'brand_logo_image' => '',
    'hero_badge' => '⭐ Gourmet Culinary Experience',
    'hero_title' => 'Artisanal Flavors & Modern Dining',
    'hero_subtitle' => 'Experience handcrafted gourmet meals, freshly brewed espresso, and seamless digital table ordering.',
    'hero_cta_primary' => '🍽️ View Signature Menu',
    'hero_cta_secondary' => '📍 Location & Hours',
    'qr_notice_text' => 'Dining in? Scan the QR code on your table for live ordering & waiter service!',
    'about_badge' => 'About Us',
    'about_title' => 'Our Culinary Journey',
    'about_text' => 'Welcome to QR Cafe, where passion meets culinary perfection. We serve artisanal dishes prepared with organic ingredients, handcrafted coffee, and gourmet desserts in a warm, modern atmosphere.',
    'about_feature1_title' => '100%',
    'about_feature1_desc' => 'Fresh & Organic',
    'about_feature2_title' => 'Handcrafted',
    'about_feature2_desc' => 'Specialty Coffee',
    'dishes_badge' => 'Chef Recommended',
    'dishes_title' => 'Signature Dishes & Brews',
    'dishes_subtitle' => 'Explore our top culinary creations crafted daily with passion',
    'location_title' => 'Location & Address',
    'location_address' => 'Kathmandu, Nepal',
    'contact_phone' => '+977 9800000000',
    'contact_email' => 'info@qrcafe.com',
    'hours_title' => 'Opening Hours',
    'opening_hours' => 'Mon - Sun: 8:00 AM - 10:00 PM',
    'hero_image' => '',
    'footer_copyright' => '©️ 2026 QRMS · A Product by <a href="https://sovryxtech.com.np" target="_blank" class="text-amber-400 font-bold hover:underline">Sovryx Tech Pvt. Ltd.</a> · All rights reserved.'
];

$settings = $defaults;
if ($conn) {
    $res = $conn->query("SELECT * FROM landing_page_settings LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $settings = array_merge($defaults, array_filter($row));
    }
}

foreach ($settings as $key => $val) {
    if (is_string($val) && $key !== 'footer_copyright') {
        $settings[$key] = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

// Fetch Popular / Featured Dishes
$featured_items = [];
if ($conn) {
    $f_res = $conn->query("SELECT * FROM menu_items WHERE status != 'inactive' ORDER BY is_popular DESC, id ASC LIMIT 6");
    if ($f_res) {
        while ($item = $f_res->fetch_assoc()) {
            $featured_items[] = $item;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100 scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title><?php echo htmlspecialchars($settings['brand_name']); ?> - Artisanal Restaurant</title>
    <link rel="manifest" href="manifest.json">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              amber: {
                500: '#f59e0b',
                600: '#d97706',
              }
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
<body class="min-h-full font-sans antialiased selection:bg-amber-500 selection:text-zinc-950">

    <!-- Navigation Header -->
    <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-4">
        <div class="max-w-7xl mx-auto flex items-center justify-between gap-4">
            <a href="index.php" class="flex items-center gap-2.5 text-xl font-black tracking-tight text-white">
                <?php if (!empty($settings['brand_logo_image']) && file_exists(__DIR__ . '/images/' . $settings['brand_logo_image'])): ?>
                    <img src="images/<?php echo htmlspecialchars($settings['brand_logo_image']); ?>" alt="Logo" class="w-8 h-8 object-contain rounded-lg shrink-0">
                <?php else: ?>
                    <span class="text-2xl"><?php echo htmlspecialchars($settings['brand_logo']); ?></span>
                <?php endif; ?>
                <span><?php echo htmlspecialchars($settings['brand_name']); ?></span>
            </a>
            
            <nav class="hidden md:flex items-center gap-8 text-xs font-bold text-zinc-300">
                <a href="#about" class="hover:text-amber-400 transition-colors">Our Story</a>
                <a href="#dishes" class="hover:text-amber-400 transition-colors">Featured Dishes</a>
                <a href="#contact" class="hover:text-amber-400 transition-colors">Location & Hours</a>
            </nav>

            <div class="flex items-center gap-3">
                <a href="#dishes" class="px-4 py-2 rounded-full bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                    Explore Menu ↓
                </a>
            </div>
        </div>
    </header>

    <!-- HERO SECTION -->
    <section class="relative bg-zinc-950 overflow-hidden pt-12 pb-20 md:py-28 border-b border-zinc-800/60">
        <div class="absolute inset-0 bg-gradient-to-b from-amber-500/10 via-transparent to-zinc-950 pointer-events-none"></div>
        
        <div class="max-w-5xl mx-auto px-4 text-center relative z-10 space-y-6">
            <?php if (!empty($settings['hero_badge'])): ?>
                <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs font-extrabold tracking-wider uppercase">
                    <?php echo htmlspecialchars($settings['hero_badge']); ?>
                </div>
            <?php endif; ?>
            
            <h1 class="text-3xl md:text-6xl font-black text-white tracking-tight leading-tight max-w-4xl mx-auto">
                <?php echo htmlspecialchars($settings['hero_title']); ?>
            </h1>
            
            <p class="text-sm md:text-lg text-zinc-400 max-w-2xl mx-auto leading-relaxed">
                <?php echo htmlspecialchars($settings['hero_subtitle']); ?>
            </p>

            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 pt-4">
                <?php if (!empty($settings['hero_cta_primary'])): ?>
                    <a href="#dishes" class="w-full sm:w-auto px-8 py-3.5 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-600 text-zinc-950 font-black text-sm active:scale-95 shadow-xl shadow-amber-500/20">
                        <?php echo htmlspecialchars($settings['hero_cta_primary']); ?>
                    </a>
                <?php endif; ?>
                <?php if (!empty($settings['hero_cta_secondary'])): ?>
                    <a href="#contact" class="w-full sm:w-auto px-8 py-3.5 rounded-2xl bg-zinc-900 border border-zinc-800 text-white font-bold text-sm hover:border-zinc-700 active:scale-95">
                        <?php echo htmlspecialchars($settings['hero_cta_secondary']); ?>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Notice card for QR ordering -->
            <?php if (!empty($settings['qr_notice_text'])): ?>
                <div class="max-w-md mx-auto mt-8 p-4 rounded-2xl bg-zinc-900/80 border border-zinc-800/80 text-xs text-zinc-400 flex items-center justify-center gap-3">
                    <span class="text-xl">📱</span>
                    <span><?php echo htmlspecialchars($settings['qr_notice_text']); ?></span>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- OUR STORY SECTION -->
    <section id="about" class="py-16 md:py-24 border-b border-zinc-800/60 bg-zinc-900/30">
        <div class="max-w-5xl mx-auto px-4 grid grid-cols-1 md:grid-cols-2 gap-10 items-center">
            <div class="space-y-4">
                <?php if (!empty($settings['about_badge'])): ?>
                    <span class="text-xs font-extrabold text-amber-500 uppercase tracking-widest"><?php echo htmlspecialchars($settings['about_badge']); ?></span>
                <?php endif; ?>
                
                <h2 class="text-2xl md:text-4xl font-black text-white"><?php echo htmlspecialchars($settings['about_title']); ?></h2>
                
                <p class="text-sm text-zinc-300 leading-relaxed">
                    <?php echo nl2br(htmlspecialchars($settings['about_text'])); ?>
                </p>
                
                <div class="grid grid-cols-2 gap-4 pt-2">
                    <?php if (!empty($settings['about_feature1_title'])): ?>
                        <div class="p-4 rounded-2xl bg-zinc-900 border border-zinc-800">
                            <div class="text-2xl font-black text-amber-400"><?php echo htmlspecialchars($settings['about_feature1_title']); ?></div>
                            <div class="text-xs text-zinc-400 font-bold mt-0.5"><?php echo htmlspecialchars($settings['about_feature1_desc']); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($settings['about_feature2_title'])): ?>
                        <div class="p-4 rounded-2xl bg-zinc-900 border border-zinc-800">
                            <div class="text-2xl font-black text-amber-400"><?php echo htmlspecialchars($settings['about_feature2_title']); ?></div>
                            <div class="text-xs text-zinc-400 font-bold mt-0.5"><?php echo htmlspecialchars($settings['about_feature2_desc']); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="relative aspect-[4/3] rounded-3xl bg-zinc-900 border border-zinc-800 overflow-hidden flex items-center justify-center text-6xl shadow-2xl">
                <?php if (!empty($settings['hero_image']) && file_exists(__DIR__ . '/images/' . $settings['hero_image'])): ?>
                    <img src="images/<?php echo htmlspecialchars($settings['hero_image']); ?>" alt="Restaurant Showcase" class="w-full h-full object-cover">
                <?php else: ?>
                    ☕
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- FEATURED SPECIALTIES SECTION -->
    <section id="dishes" class="py-16 md:py-24 border-b border-zinc-800/60">
        <div class="max-w-7xl mx-auto px-4 space-y-10">
            <div class="text-center space-y-2">
                <?php if (!empty($settings['dishes_badge'])): ?>
                    <span class="text-xs font-extrabold text-amber-500 uppercase tracking-widest"><?php echo htmlspecialchars($settings['dishes_badge']); ?></span>
                <?php endif; ?>
                <h2 class="text-2xl md:text-4xl font-black text-white"><?php echo htmlspecialchars($settings['dishes_title']); ?></h2>
                <p class="text-xs text-zinc-400 max-w-lg mx-auto"><?php echo htmlspecialchars($settings['dishes_subtitle']); ?></p>
            </div>

            <?php if (empty($featured_items)): ?>
                <div class="text-center py-10 text-zinc-500 text-xs font-bold">No dishes available at the moment.</div>
            <?php else: ?>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                    <?php foreach ($featured_items as $item): ?>
                        <?php 
                        $dietary = strtolower($item['dietary_type'] ?? 'veg');
                        $dietary_color = ($dietary === 'non-veg') ? 'border-red-500 bg-red-500' : 'border-emerald-500 bg-emerald-500';
                        $img_src = (!empty($item['image']) && file_exists(__DIR__ . '/images/' . $item['image'])) ? ('images/' . htmlspecialchars($item['image'])) : '';
                        ?>
                        <div class="bg-zinc-900/90 border border-zinc-800/80 rounded-3xl p-3 flex flex-col justify-between shadow-xl">
                            <div class="relative aspect-[16/9] w-full rounded-2xl bg-zinc-950 overflow-hidden mb-2.5 flex items-center justify-center text-4xl border border-zinc-800/50">
                                <?php if (!empty($img_src)): ?>
                                    <img src="<?php echo $img_src; ?>" alt="img" class="w-full h-full object-cover">
                                <?php else: ?>
                                    🍽️
                                <?php endif; ?>
                                <?php if (!empty($item['is_popular'])): ?>
                                    <span class="absolute top-2 left-2 bg-amber-500 text-zinc-950 font-black text-[10px] px-2 py-0.5 rounded-full uppercase">⭐ Top</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1 flex flex-col mb-2">
                                <div class="flex items-center gap-1.5 mb-1">
                                    <span class="w-3.5 h-3.5 rounded-sm border-2 <?php echo $dietary_color; ?> flex items-center justify-center shrink-0"><span class="w-1.5 h-1.5 rounded-full bg-white"></span></span>
                                    <h3 class="font-extrabold text-sm text-zinc-100 truncate"><?php echo htmlspecialchars($item['name']); ?></h3>
                                </div>
                                <p class="text-xs text-zinc-400 line-clamp-2"><?php echo htmlspecialchars($item['description']); ?></p>
                            </div>
                            <div class="mt-auto pt-2 border-t border-zinc-800/60 flex justify-between items-center">
                                <span class="text-base font-black text-amber-400">Rs. <?php echo number_format($item['price'], 0); ?></span>
                                <span class="text-[10px] font-extrabold text-zinc-400 uppercase">Handcrafted</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- LOCATION & CONTACT SECTION -->
    <section id="contact" class="py-16 md:py-24 bg-zinc-900/30">
        <div class="max-w-5xl mx-auto px-4 grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 shadow-xl space-y-4">
                <h3 class="text-lg font-black text-white flex items-center gap-2">
                    <span>📍</span> <?php echo htmlspecialchars($settings['location_title']); ?>
                </h3>
                <p class="text-sm text-zinc-300"><?php echo htmlspecialchars($settings['location_address']); ?></p>
                <div class="pt-2 border-t border-zinc-800/80 space-y-2 text-xs">
                    <div class="flex items-center justify-between text-zinc-400">
                        <span>📞 Phone:</span>
                        <strong class="text-white"><?php echo htmlspecialchars($settings['contact_phone']); ?></strong>
                    </div>
                    <div class="flex items-center justify-between text-zinc-400">
                        <span>✉️ Email:</span>
                        <strong class="text-white"><?php echo htmlspecialchars($settings['contact_email']); ?></strong>
                    </div>
                </div>
            </div>

            <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 shadow-xl space-y-4">
                <h3 class="text-lg font-black text-white flex items-center gap-2">
                    <span>🕒</span> <?php echo htmlspecialchars($settings['hours_title']); ?>
                </h3>
                <p class="text-sm text-zinc-300"><?php echo htmlspecialchars($settings['opening_hours']); ?></p>
                <div class="pt-2 border-t border-zinc-800/80 text-xs text-zinc-400 space-y-1">
                    <p>• Kitchen open 7 days a week</p>
                    <p>• Digital table QR ordering available at every table</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="bg-zinc-950 border-t border-zinc-800/80 py-8 text-center text-xs text-zinc-500">
        <div class="max-w-7xl mx-auto px-4 text-center">
            <div><?php echo $settings['footer_copyright']; ?></div>
        </div>
    </footer>

</body>
</html>
