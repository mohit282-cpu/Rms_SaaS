<?php
// api/landing-stream.php - Realtime Website Builder Stream & Save API
require_once __DIR__ . '/../config.php';

if (!Auth::isAdminLoggedIn()) {
    Response::error('Unauthorized access. Admin authentication required.', 401);
}
// Release session lock so multiple browser tabs can poll concurrently.
session_write_close();

$conn = getDBConnection();
if (!$conn) {
    Response::error('Database connection failed', 500);
}

// Fetch current landing page settings (tenant-scoped, never expose kds_password)
$tenantId = (int)TenantContext::getTenantId();
if ($tenantId <= 0) {
    Response::error('Forbidden. No tenant context.', 403);
}

$stmt = $conn->prepare("SELECT id, brand_name, brand_logo, brand_logo_image, hero_badge, hero_title, hero_subtitle, hero_cta_primary, hero_cta_secondary, qr_notice_text, about_badge, about_title, about_text, about_feature1_title, about_feature1_desc, about_feature2_title, about_feature2_desc, dishes_badge, dishes_title, dishes_subtitle, location_title, location_address, contact_phone, contact_email, hours_title, opening_hours, hero_image, footer_copyright FROM landing_page_settings WHERE restaurant_id = ? LIMIT 1");
$stmt->bind_param("i", $tenantId);
$stmt->execute();
$res = $stmt->get_result();
$settings = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : [];
$stmt->close();

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

Response::json([
    'success' => true,
    'timestamp' => date('c'),
    'settings' => $data
]);
