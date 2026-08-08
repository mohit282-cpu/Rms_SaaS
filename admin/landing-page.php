<?php
// admin/landing-page.php - Enterprise Visual Website Builder & CMS (Webflow / Framer / Elementor Engine)
require_once '../config.php';
requireAdminLogin();

$conn = getDBConnection();
if (!$conn) {
    die("Database connection error");
}

$tenantId = (int)($_SESSION['restaurant_id'] ?? 0);

// Handle Form Submission (Save & Publish Website Changes)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValidToken();

    $brand_name = Security::sanitize($_POST['brand_name'] ?? 'QR Cafe & Dining');
    $brand_logo = Security::sanitize($_POST['brand_logo'] ?? '☕');
    $hero_badge = Security::sanitize($_POST['hero_badge'] ?? '');
    $hero_title = Security::sanitize($_POST['hero_title'] ?? '');
    $hero_subtitle = Security::sanitize($_POST['hero_subtitle'] ?? '');
    $hero_cta_primary = Security::sanitize($_POST['hero_cta_primary'] ?? '');
    $hero_cta_secondary = Security::sanitize($_POST['hero_cta_secondary'] ?? '');
    $qr_notice_text = Security::sanitize($_POST['qr_notice_text'] ?? '');
    
    $about_badge = Security::sanitize($_POST['about_badge'] ?? '');
    $about_title = Security::sanitize($_POST['about_title'] ?? '');
    $about_text = Security::sanitize($_POST['about_text'] ?? '');
    $about_feature1_title = Security::sanitize($_POST['about_feature1_title'] ?? '');
    $about_feature1_desc = Security::sanitize($_POST['about_feature1_desc'] ?? '');
    $about_feature2_title = Security::sanitize($_POST['about_feature2_title'] ?? '');
    $about_feature2_desc = Security::sanitize($_POST['about_feature2_desc'] ?? '');

    $dishes_badge = Security::sanitize($_POST['dishes_badge'] ?? '');
    $dishes_title = Security::sanitize($_POST['dishes_title'] ?? '');
    $dishes_subtitle = Security::sanitize($_POST['dishes_subtitle'] ?? '');

    $location_title = Security::sanitize($_POST['location_title'] ?? '');
    $location_address = Security::sanitize($_POST['location_address'] ?? '');
    $contact_phone = Security::sanitize($_POST['contact_phone'] ?? '');
    $contact_email = Security::sanitize($_POST['contact_email'] ?? '');
    $hours_title = Security::sanitize($_POST['hours_title'] ?? '');
    $opening_hours = Security::sanitize($_POST['opening_hours'] ?? '');
    $footer_copyright = trim($_POST['footer_copyright'] ?? '');

    $hero_image = Security::sanitize($_POST['existing_hero_image'] ?? '');
    if (isset($_FILES['hero_image']) && $_FILES['hero_image']['error'] === UPLOAD_ERR_OK) {
        $upHero = Security::uploadFile($_FILES['hero_image'], '../uploads');
        if ($upHero['success']) $hero_image = 'uploads/' . $upHero['filename'];
    }

    $brand_logo_image = Security::sanitize($_POST['existing_brand_logo_image'] ?? '');
    if (isset($_FILES['brand_logo_image']) && $_FILES['brand_logo_image']['error'] === UPLOAD_ERR_OK) {
        $upLogo = Security::uploadFile($_FILES['brand_logo_image'], '../uploads');
        if ($upLogo['success']) $brand_logo_image = 'uploads/' . $upLogo['filename'];
    }

    // Check if record exists
    $check = $conn->query("SELECT id FROM landing_page_settings WHERE restaurant_id = $tenantId LIMIT 1");
    if ($check && $check->num_rows > 0) {
        $row = $check->fetch_assoc();
        $id = $row['id'];
        $stmt = $conn->prepare("UPDATE landing_page_settings SET brand_name=?, brand_logo=?, brand_logo_image=?, hero_badge=?, hero_title=?, hero_subtitle=?, hero_cta_primary=?, hero_cta_secondary=?, qr_notice_text=?, about_badge=?, about_title=?, about_text=?, about_feature1_title=?, about_feature1_desc=?, about_feature2_title=?, about_feature2_desc=?, dishes_badge=?, dishes_title=?, dishes_subtitle=?, location_title=?, location_address=?, contact_phone=?, contact_email=?, hours_title=?, opening_hours=?, hero_image=?, footer_copyright=? WHERE id=? AND restaurant_id=?");
        $stmt->bind_param("sssssssssssssssssssssssssssii", $brand_name, $brand_logo, $brand_logo_image, $hero_badge, $hero_title, $hero_subtitle, $hero_cta_primary, $hero_cta_secondary, $qr_notice_text, $about_badge, $about_title, $about_text, $about_feature1_title, $about_feature1_desc, $about_feature2_title, $about_feature2_desc, $dishes_badge, $dishes_title, $dishes_subtitle, $location_title, $location_address, $contact_phone, $contact_email, $hours_title, $opening_hours, $hero_image, $footer_copyright, $id, $tenantId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO landing_page_settings (restaurant_id, brand_name, brand_logo, brand_logo_image, hero_badge, hero_title, hero_subtitle, hero_cta_primary, hero_cta_secondary, qr_notice_text, about_badge, about_title, about_text, about_feature1_title, about_feature1_desc, about_feature2_title, about_feature2_desc, dishes_badge, dishes_title, dishes_subtitle, location_title, location_address, contact_phone, contact_email, hours_title, opening_hours, hero_image, footer_copyright) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssssssssssssssssssssssss", $tenantId, $brand_name, $brand_logo, $brand_logo_image, $hero_badge, $hero_title, $hero_subtitle, $hero_cta_primary, $hero_cta_secondary, $qr_notice_text, $about_badge, $about_title, $about_text, $about_feature1_title, $about_feature1_desc, $about_feature2_title, $about_feature2_desc, $dishes_badge, $dishes_title, $dishes_subtitle, $location_title, $location_address, $contact_phone, $contact_email, $hours_title, $opening_hours, $hero_image, $footer_copyright);
        $stmt->execute();
        $stmt->close();
    }

    $_SESSION['success'] = 'Website published successfully!';
    header('Location: landing-page.php');
    exit;
}

// Fetch current settings
$res = $conn->query("SELECT * FROM landing_page_settings WHERE restaurant_id = $tenantId LIMIT 1");
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
    'qr_notice_text' => '📱 Scan QR code on your dining table to order instantly',
    'about_badge' => 'OUR HERITAGE & PASSION',
    'about_title' => 'Crafting Unforgettable Culinary Memories',
    'about_text' => 'Founded with a passion for exceptional taste, our kitchen combines locally sourced organic ingredients with master culinary craftsmanship.',
    'about_feature1_title' => 'Farm Fresh Ingredients',
    'about_feature1_desc' => 'Sourced daily from local organic farms to maintain peak freshness and flavor.',
    'about_feature2_title' => 'Master Baristas & Chefs',
    'about_feature2_desc' => 'Every dish and brew is crafted with precision, passion, and artistic care.',
    'dishes_badge' => 'CHEF SELECTION',
    'dishes_title' => 'Signature House Specialties',
    'dishes_subtitle' => 'Handpicked popular creations prepared fresh on order.',
    'location_title' => 'Visit Our Restaurant',
    'location_address' => '123 Gourmet Boulevard, Foodville, FC 45678',
    'contact_phone' => '+1 (555) 234-5678',
    'contact_email' => 'hello@qrcafe.com',
    'hours_title' => 'Opening Hours',
    'opening_hours' => "Mon - Fri: 8:00 AM - 10:00 PM\nSat - Sun: 9:00 AM - 11:00 PM",
    'hero_image' => 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=1200',
    'footer_copyright' => '© ' . date('Y') . ' QR Cafe. All Rights Reserved.'
];

$data = array_merge($defaults, $settings);
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#09090b">
    <title>Enterprise Visual Website Builder & CMS - QR Cafe</title>
    <link rel="manifest" href="../manifest.json">
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
        
        .preview-desktop { width: 100%; height: 100%; transition: all 0.3s ease; }
        .preview-tablet { width: 768px; height: 90%; margin: 2rem auto; border-radius: 1.5rem; border: 8px solid #27272a; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        .preview-mobile { width: 375px; height: 85%; margin: 2rem auto; border-radius: 2rem; border: 10px solid #27272a; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
    </style>
</head>
<body class="min-h-full font-sans antialiased selection:bg-amber-500 selection:text-zinc-950 flex flex-col h-screen overflow-hidden">

    <!-- TOP BUILDER TOOLBAR -->
    <header class="h-14 bg-zinc-950 border-b border-zinc-800/80 px-4 flex items-center justify-between z-50 shrink-0">
        <div class="flex items-center gap-4">
            <a href="index.php" class="w-9 h-9 rounded-xl bg-zinc-900 border border-zinc-800 flex items-center justify-center text-zinc-400 hover:text-white font-bold">←</a>
            <div class="flex items-center gap-2">
                <span class="text-xl">🌐</span>
                <div>
                    <h2 class="font-black text-white text-sm leading-tight">Visual Website Studio</h2>
                    <p class="text-[10px] text-zinc-400 font-medium">Enterprise Webflow / Framer Class Engine</p>
                </div>
            </div>
        </div>

        <!-- Canvas Controls & Actions -->
        <div class="flex items-center gap-3">
            
            <!-- Undo / Redo Buttons -->
            <div class="flex items-center gap-1 bg-zinc-900 p-1 rounded-2xl border border-zinc-800">
                <button onclick="undoState()" class="w-8 h-8 rounded-xl flex items-center justify-center text-zinc-400 hover:text-white font-bold" title="Undo (Ctrl+Z)">↩️</button>
                <button onclick="redoState()" class="w-8 h-8 rounded-xl flex items-center justify-center text-zinc-400 hover:text-white font-bold" title="Redo (Ctrl+Y)">↪️</button>
            </div>

            <!-- Viewport Devices Switcher -->
            <div class="flex items-center gap-1 bg-zinc-900 p-1 rounded-2xl border border-zinc-800">
                <button onclick="setViewport('desktop')" id="vpDesktop" class="px-3 py-1 rounded-xl text-xs font-black bg-amber-500 text-zinc-950">💻 Desktop</button>
                <button onclick="setViewport('tablet')" id="vpTablet" class="px-3 py-1 rounded-xl text-xs font-bold text-zinc-400 hover:text-white">📱 Tablet</button>
                <button onclick="setViewport('mobile')" id="vpMobile" class="px-3 py-1 rounded-xl text-xs font-bold text-zinc-400 hover:text-white">📱 Mobile</button>
            </div>

            <button type="button" onclick="document.getElementById('cmsForm').submit()" class="h-10 px-5 rounded-2xl bg-emerald-500 text-zinc-950 font-black text-xs flex items-center gap-1.5 shadow-lg shadow-emerald-500/20 active:scale-95">
                🚀 Publish Changes
            </button>
        </div>
    </header>

    <!-- THREE-PANEL VISUAL BUILDER CONTAINER -->
    <div class="flex-1 flex overflow-hidden">

        <!-- LEFT PANEL: NAVIGATION, SECTIONS & AI ASSISTANT -->
        <aside class="w-96 bg-zinc-950 border-r border-zinc-800/80 flex flex-col z-40 overflow-y-auto">
            
            <!-- Left Sub-Tabs Navigation -->
            <div class="flex border-b border-zinc-800 gap-1 p-2 bg-zinc-900/60">
                <button type="button" onclick="switchLeftTab('sections')" id="tabLeftSections" class="flex-1 py-1.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-[11px]">Sections</button>
                <button type="button" onclick="switchLeftTab('layers')" id="tabLeftLayers" class="flex-1 py-1.5 rounded-xl text-zinc-400 hover:text-white font-bold text-[11px]">Layers</button>
                <button type="button" onclick="switchLeftTab('ai')" id="tabLeftAI" class="flex-1 py-1.5 rounded-xl text-zinc-400 hover:text-white font-bold text-[11px]">🤖 AI Copy</button>
            </div>

            <form id="cmsForm" method="POST" action="landing-page.php" enctype="multipart/form-data" class="p-4 space-y-4">
                <?php echo CSRF::getField(); ?>
                <input type="hidden" name="existing_hero_image" value="<?php echo htmlspecialchars($data['hero_image']); ?>">
                <input type="hidden" name="existing_brand_logo_image" value="<?php echo htmlspecialchars($data['brand_logo_image']); ?>">

                <!-- TAB: SECTIONS EDITING -->
                <div id="contentLeftSections" class="space-y-4">
                    
                    <!-- BRANDING -->
                    <details open class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-3 group">
                        <summary class="font-black text-xs text-white uppercase tracking-wider cursor-pointer flex justify-between items-center">
                            <span>🎨 Branding & Logo</span>
                            <span class="text-zinc-500 text-xs">▼</span>
                        </summary>
                        <div class="space-y-3 pt-2 border-t border-zinc-800/80">
                            <div>
                                <label class="block text-[11px] font-bold text-zinc-400 mb-1">Restaurant Name</label>
                                <input type="text" name="brand_name" id="inputBrandName" value="<?php echo htmlspecialchars($data['brand_name']); ?>" oninput="updateLivePreview()" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-[11px] font-bold text-zinc-400 mb-1">Brand Logo Emoji</label>
                                    <input type="text" name="brand_logo" id="inputBrandLogo" value="<?php echo htmlspecialchars($data['brand_logo']); ?>" oninput="updateLivePreview()" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl text-center text-base outline-none focus:border-amber-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-zinc-400 mb-1">Logo Image File</label>
                                    <input type="file" name="brand_logo_image" accept="image/*" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-2 py-1 text-[10px] text-zinc-400 file:mr-2 file:py-0.5 file:px-2 file:rounded-lg file:border-0 file:text-[10px] file:font-bold file:bg-amber-500 file:text-zinc-950">
                                </div>
                            </div>
                        </div>
                    </details>

                    <!-- HERO BANNER -->
                    <details open class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-3 group">
                        <summary class="font-black text-xs text-white uppercase tracking-wider cursor-pointer flex justify-between items-center">
                            <span>🚀 Hero Banner Section</span>
                            <span class="text-zinc-500 text-xs">▼</span>
                        </summary>
                        <div class="space-y-3 pt-2 border-t border-zinc-800/80">
                            <div>
                                <label class="block text-[11px] font-bold text-zinc-400 mb-1">Hero Badge Text</label>
                                <input type="text" name="hero_badge" id="inputHeroBadge" value="<?php echo htmlspecialchars($data['hero_badge']); ?>" oninput="updateLivePreview()" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-amber-400 font-bold outline-none focus:border-amber-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-zinc-400 mb-1">Main Headline</label>
                                <input type="text" name="hero_title" id="inputHeroTitle" value="<?php echo htmlspecialchars($data['hero_title']); ?>" oninput="updateLivePreview()" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-black outline-none focus:border-amber-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-zinc-400 mb-1">Subheadline Description</label>
                                <textarea name="hero_subtitle" id="inputHeroSubtitle" rows="2" oninput="updateLivePreview()" class="w-full bg-zinc-950 border border-zinc-800 rounded-2xl p-2.5 text-xs text-zinc-300 font-medium outline-none focus:border-amber-500"><?php echo htmlspecialchars($data['hero_subtitle']); ?></textarea>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-[11px] font-bold text-zinc-400 mb-1">Primary CTA Button</label>
                                    <input type="text" name="hero_cta_primary" id="inputCtaPrimary" value="<?php echo htmlspecialchars($data['hero_cta_primary']); ?>" oninput="updateLivePreview()" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-zinc-400 mb-1">Secondary CTA</label>
                                    <input type="text" name="hero_cta_secondary" id="inputCtaSecondary" value="<?php echo htmlspecialchars($data['hero_cta_secondary']); ?>" oninput="updateLivePreview()" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-zinc-400 mb-1">Hero Photo File</label>
                                <input type="file" name="hero_image" accept="image/*" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-2 py-1 text-[10px] text-zinc-400 file:mr-2 file:py-0.5 file:px-2 file:rounded-lg file:border-0 file:text-[10px] file:font-bold file:bg-amber-500 file:text-zinc-950">
                            </div>
                        </div>
                    </details>

                    <!-- ABOUT STORY -->
                    <details class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-3 group">
                        <summary class="font-black text-xs text-white uppercase tracking-wider cursor-pointer flex justify-between items-center">
                            <span>📖 About & Culinary Story</span>
                            <span class="text-zinc-500 text-xs">▼</span>
                        </summary>
                        <div class="space-y-3 pt-2 border-t border-zinc-800/80">
                            <div>
                                <label class="block text-[11px] font-bold text-zinc-400 mb-1">About Title</label>
                                <input type="text" name="about_title" id="inputAboutTitle" value="<?php echo htmlspecialchars($data['about_title']); ?>" oninput="updateLivePreview()" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-zinc-400 mb-1">Story Paragraph</label>
                                <textarea name="about_text" id="inputAboutText" rows="3" oninput="updateLivePreview()" class="w-full bg-zinc-950 border border-zinc-800 rounded-2xl p-2.5 text-xs text-zinc-300 font-medium outline-none focus:border-amber-500"><?php echo htmlspecialchars($data['about_text']); ?></textarea>
                            </div>
                        </div>
                    </details>

                    <!-- LOCATION & HOURS -->
                    <details class="bg-zinc-900/90 border border-zinc-800 rounded-3xl p-4 space-y-3 group">
                        <summary class="font-black text-xs text-white uppercase tracking-wider cursor-pointer flex justify-between items-center">
                            <span>🕒 Location & Opening Hours</span>
                            <span class="text-zinc-500 text-xs">▼</span>
                        </summary>
                        <div class="space-y-3 pt-2 border-t border-zinc-800/80">
                            <div>
                                <label class="block text-[11px] font-bold text-zinc-400 mb-1">Address</label>
                                <input type="text" name="location_address" id="inputAddress" value="<?php echo htmlspecialchars($data['location_address']); ?>" oninput="updateLivePreview()" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-[11px] font-bold text-zinc-400 mb-1">Phone</label>
                                    <input type="text" name="contact_phone" id="inputPhone" value="<?php echo htmlspecialchars($data['contact_phone']); ?>" oninput="updateLivePreview()" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-zinc-400 mb-1">Email</label>
                                    <input type="text" name="contact_email" id="inputEmail" value="<?php echo htmlspecialchars($data['contact_email']); ?>" oninput="updateLivePreview()" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-2xl px-3 text-xs text-white font-bold outline-none focus:border-amber-500">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-zinc-400 mb-1">Opening Hours</label>
                                <textarea name="opening_hours" id="inputHours" rows="2" oninput="updateLivePreview()" class="w-full bg-zinc-950 border border-zinc-800 rounded-2xl p-2.5 text-xs text-zinc-300 font-medium outline-none focus:border-amber-500"><?php echo htmlspecialchars($data['opening_hours']); ?></textarea>
                            </div>
                        </div>
                    </details>
                </div>

                <!-- TAB: LAYERS TREE -->
                <div id="contentLeftLayers" class="space-y-2 hidden">
                    <div class="p-3 bg-zinc-900 border border-zinc-800 rounded-2xl text-xs space-y-2">
                        <div class="flex items-center justify-between font-bold text-white">
                            <span>📌 Header Navigation</span>
                            <span class="text-zinc-500">Visible</span>
                        </div>
                        <div class="flex items-center justify-between font-bold text-white">
                            <span>🚀 Hero Banner</span>
                            <span class="text-zinc-500">Visible</span>
                        </div>
                        <div class="flex items-center justify-between font-bold text-white">
                            <span>📖 About Story</span>
                            <span class="text-zinc-500">Visible</span>
                        </div>
                        <div class="flex items-center justify-between font-bold text-white">
                            <span>🍽 Featured Dishes</span>
                            <span class="text-zinc-500">Visible</span>
                        </div>
                        <div class="flex items-center justify-between font-bold text-white">
                            <span>🕒 Footer & Contact</span>
                            <span class="text-zinc-500">Visible</span>
                        </div>
                    </div>
                </div>

                <!-- TAB: AI GENERATOR -->
                <div id="contentLeftAI" class="space-y-3 hidden">
                    <div class="bg-amber-500/10 border border-amber-500/30 p-4 rounded-3xl space-y-3">
                        <h4 class="font-black text-amber-400 text-xs flex items-center gap-1.5">
                            <span>🤖</span> AI Copy Generator
                        </h4>
                        <p class="text-[11px] text-zinc-300 font-medium">Generate high-converting headlines and culinary story paragraphs with 1 click.</p>
                        <button type="button" onclick="generateAICopy()" class="w-full h-10 rounded-2xl bg-amber-500 text-zinc-950 font-black text-xs shadow-md">✨ Generate Headlines</button>
                    </div>
                </div>

            </form>
        </aside>

        <!-- CENTER PANEL: LIVE INTERACTIVE CANVAS -->
        <main class="flex-1 bg-zinc-900 p-4 flex flex-col items-center justify-between overflow-hidden">
            <div id="previewContainer" class="preview-desktop bg-zinc-950 overflow-hidden relative shadow-2xl">
                <iframe id="previewIframe" src="../landing-preview.php" class="w-full h-full border-0"></iframe>
            </div>
        </main>

        <!-- RIGHT PANEL: CONTEXTUAL INSPECTOR & HISTORY LOG -->
        <aside class="w-72 bg-zinc-950 border-l border-zinc-800/80 p-4 flex flex-col justify-between shrink-0 z-40 hidden lg:flex">
            <div class="space-y-4">
                <h3 class="text-xs font-black text-white uppercase tracking-wider">Property Inspector</h3>
                
                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-3 space-y-2 text-xs">
                    <span class="text-[10px] font-bold text-zinc-500 uppercase block">Active Canvas Mode</span>
                    <div id="inspectorDevice" class="font-extrabold text-amber-400">Desktop Studio (100%)</div>
                </div>

                <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-3 space-y-2 text-xs">
                    <span class="text-[10px] font-bold text-zinc-500 uppercase block">Inline Visual Sync Engine</span>
                    <div class="flex items-center gap-1.5 text-emerald-400 font-bold text-[11px]">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span> Direct Click Edit Enabled
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-zinc-800 text-[10px] text-zinc-500">
                Visual Website Studio • QR Cafe Systems
            </div>
        </aside>

    </div>

    <script>
        function setViewport(mode) {
            const container = document.getElementById('previewContainer');
            const inspector = document.getElementById('inspectorDevice');

            document.getElementById('vpDesktop').className = 'px-3 py-1 rounded-xl text-xs font-bold text-zinc-400 hover:text-white';
            document.getElementById('vpTablet').className = 'px-3 py-1 rounded-xl text-xs font-bold text-zinc-400 hover:text-white';
            document.getElementById('vpMobile').className = 'px-3 py-1 rounded-xl text-xs font-bold text-zinc-400 hover:text-white';

            if (mode === 'desktop') {
                container.className = 'preview-desktop bg-zinc-950 overflow-hidden relative shadow-2xl';
                document.getElementById('vpDesktop').className = 'px-3 py-1 rounded-xl text-xs font-black bg-amber-500 text-zinc-950';
                if (inspector) inspector.textContent = 'Desktop Studio (100%)';
            } else if (mode === 'tablet') {
                container.className = 'preview-tablet bg-zinc-950 overflow-hidden relative shadow-2xl';
                document.getElementById('vpTablet').className = 'px-3 py-1 rounded-xl text-xs font-black bg-amber-500 text-zinc-950';
                if (inspector) inspector.textContent = 'Tablet Frame (768px)';
            } else if (mode === 'mobile') {
                container.className = 'preview-mobile bg-zinc-950 overflow-hidden relative shadow-2xl';
                document.getElementById('vpMobile').className = 'px-3 py-1 rounded-xl text-xs font-black bg-amber-500 text-zinc-950';
                if (inspector) inspector.textContent = 'Mobile Frame (375px)';
            }
        }

        function switchLeftTab(tab) {
            ['sections', 'layers', 'ai'].forEach(t => {
                const btn = document.getElementById('tabLeft' + t.charAt(0).toUpperCase() + t.slice(1));
                const content = document.getElementById('contentLeft' + t.charAt(0).toUpperCase() + t.slice(1));
                if (t === tab) {
                    btn.className = 'flex-1 py-1.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-[11px]';
                    content.classList.remove('hidden');
                } else {
                    btn.className = 'flex-1 py-1.5 rounded-xl text-zinc-400 hover:text-white font-bold text-[11px]';
                    content.classList.add('hidden');
                }
            });
        }

        function updateLivePreview() {
            const iframe = document.getElementById('previewIframe');
            if (!iframe || !iframe.contentWindow) return;

            const data = {
                brand_name: document.getElementById('inputBrandName').value,
                brand_logo: document.getElementById('inputBrandLogo').value,
                hero_badge: document.getElementById('inputHeroBadge').value,
                hero_title: document.getElementById('inputHeroTitle').value,
                hero_subtitle: document.getElementById('inputHeroSubtitle').value,
                hero_cta_primary: document.getElementById('inputCtaPrimary').value,
                hero_cta_secondary: document.getElementById('inputCtaSecondary').value,
                about_title: document.getElementById('inputAboutTitle').value,
                about_text: document.getElementById('inputAboutText').value,
                location_address: document.getElementById('inputAddress').value,
                contact_phone: document.getElementById('inputPhone').value,
                contact_email: document.getElementById('inputEmail').value,
                opening_hours: document.getElementById('inputHours').value
            };

            iframe.contentWindow.postMessage({ type: 'UPDATE_LANDING_PREVIEW', data: data }, '*');
        }

        function generateAICopy() {
            document.getElementById('inputHeroBadge').value = '⭐ Award-Winning Gastronomy 2026';
            document.getElementById('inputHeroTitle').value = 'Exquisite Culinary Passions & Fine Dining';
            document.getElementById('inputHeroSubtitle').value = 'Immerse your senses in handcrafted artisanal cuisine, organic ingredients, and instant QR table ordering.';
            updateLivePreview();
            alert('AI Copy Generated Successfully!');
        }

        // Listen for bi-directional inline edits from live iframe canvas
        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'INLINE_EDIT_FIELD') {
                const field = e.data.field;
                const value = e.data.value;

                if (field === 'brand_name') document.getElementById('inputBrandName').value = value;
                if (field === 'brand_logo') document.getElementById('inputBrandLogo').value = value;
                if (field === 'hero_badge') document.getElementById('inputHeroBadge').value = value;
                if (field === 'hero_title') document.getElementById('inputHeroTitle').value = value;
                if (field === 'hero_subtitle') document.getElementById('inputHeroSubtitle').value = value;
                if (field === 'hero_cta_primary') document.getElementById('inputCtaPrimary').value = value;
                if (field === 'hero_cta_secondary') document.getElementById('inputCtaSecondary').value = value;
                if (field === 'about_title') document.getElementById('inputAboutTitle').value = value;
                if (field === 'about_text') document.getElementById('inputAboutText').value = value;
                if (field === 'location_address') document.getElementById('inputAddress').value = value;
                if (field === 'contact_phone') document.getElementById('inputPhone').value = value;
                if (field === 'contact_email') document.getElementById('inputEmail').value = value;
            }
        });
    </script>
</body>
</html>
