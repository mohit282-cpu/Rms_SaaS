<?php
// scripts/generate_icons.php - Generate high-quality PWA icon assets for RMS SaaS

$baseDir = dirname(__DIR__);
$targetDir = $baseDir . '/images';

if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

function createRmsIcon($size, $isMaskable = false) {
    $img = imagecreatetruecolor($size, $size);
    imagealphablending($img, false);
    imagesavealpha($img, true);

    // Background color: #09090b (Dark Zinc / Slate)
    $bgR = 9; $bgG = 9; $bgB = 11;
    if ($isMaskable) {
        // Solid fill for maskable
        $bgColor = imagecolorallocate($img, $bgR, $bgG, $bgB);
        imagefilledrectangle($img, 0, 0, $size, $size, $bgColor);
    } else {
        // Rounded rectangle background
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, $size, $size, $transparent);
        $bgColor = imagecolorallocate($img, $bgR, $bgG, $bgB);

        // Draw rounded box
        $radius = (int)($size * 0.22);
        imagefilledrectangle($img, $radius, 0, $size - $radius, $size, $bgColor);
        imagefilledrectangle($img, 0, $radius, $size, $size - $radius, $bgColor);
        imagefilledellipse($img, $radius, $radius, $radius * 2, $radius * 2, $bgColor);
        imagefilledellipse($img, $size - $radius, $radius, $radius * 2, $radius * 2, $bgColor);
        imagefilledellipse($img, $radius, $size - $radius, $radius * 2, $radius * 2, $bgColor);
        imagefilledellipse($img, $size - $radius, $size - $radius, $radius * 2, $radius * 2, $bgColor);
    }

    // Draw inner accent gradient circle or ring (#FF5700 Primary)
    $orange = imagecolorallocate($img, 255, 87, 0); // #FF5700
    $blue   = imagecolorallocate($img, 24, 28, 184); // #181CB8
    $white  = imagecolorallocate($img, 255, 255, 255);

    $scale = $isMaskable ? 0.7 : 0.82;
    $cx = (int)($size / 2);
    $cy = (int)($size / 2);
    $badgeSize = (int)($size * $scale);

    // Outer glowing ring
    imagefilledellipse($img, $cx, $cy, $badgeSize, $badgeSize, $orange);
    
    // Inner dark circle
    $innerSize = (int)($badgeSize * 0.84);
    $darkInner = imagecolorallocate($img, 18, 18, 22);
    imagefilledellipse($img, $cx, $cy, $innerSize, $innerSize, $darkInner);

    // Lightning bolt points relative to center
    $boltScale = $size * 0.0035 * $scale;
    $points = [
        $cx + (int)(2 * $boltScale),   $cy - (int)(35 * $boltScale),
        $cx - (int)(30 * $boltScale),  $cy + (int)(5 * $boltScale),
        $cx - (int)(2 * $boltScale),   $cy + (int)(5 * $boltScale),
        $cx - (int)(10 * $boltScale),  $cy + (int)(35 * $boltScale),
        $cx + (int)(25 * $boltScale),  $cy - (int)(5 * $boltScale),
        $cx + (int)(2 * $boltScale),   $cy - (int)(5 * $boltScale),
    ];
    imagefilledpolygon($img, $points, 6, $orange);

    // Subtext "RMS" overlay
    $string = "RMS";
    $font = 5; // Built-in font 5
    $strWidth = imagefontwidth($font) * strlen($string);
    
    imagestring($img, 5, $cx - (int)($strWidth / 2), $cy + (int)($badgeSize * 0.22), $string, $white);

    return $img;
}

$sizes = [
    'icon-192.png'          => [192, false],
    'icon-512.png'          => [512, false],
    'icon-180.png'          => [180, false],
    'icon-512-maskable.png' => [512, true],
    'favicon.png'           => [32, false],
];

foreach ($sizes as $filename => [$size, $isMaskable]) {
    $img = createRmsIcon($size, $isMaskable);
    $outPath = $targetDir . '/' . $filename;
    imagepng($img, $outPath);
    imagedestroy($img);
    echo "Generated: {$filename} ({$size}x{$size})\n";
}

echo "All icons generated successfully!\n";
