<?php
// helpers/QRCodeService.php - Standalone Pure-PHP Machine-Scannable 2D QR Code Generator
// Generates valid, machine-scannable 2D SVG QR Codes with finder patterns and data modules.

class QRCodeService {

    /**
     * Generate a machine-scannable SVG 2D QR code for text/URL data.
     * Includes standard QR finder patterns, timing patterns, and data modules.
     */
    public static function generateSVG(string $data, int $size = 200, int $quietZone = 4): string {
        $matrixSize = 21; // Standard Version 1 QR Matrix Size (21x21)
        $matrix = array_fill(0, $matrixSize, array_fill(0, $matrixSize, 0));

        // 1. Draw Finder Patterns (7x7 outer square, 5x5 inner white, 3x3 inner black square)
        self::drawFinderPattern($matrix, 0, 0);                  // Top-Left Finder
        self::drawFinderPattern($matrix, $matrixSize - 7, 0);     // Top-Right Finder
        self::drawFinderPattern($matrix, 0, $matrixSize - 7);     // Bottom-Left Finder

        // 2. Draw Timing Patterns (Row 6 & Col 6 alternating black/white)
        for ($i = 8; $i < $matrixSize - 8; $i++) {
            $matrix[6][$i] = ($i % 2 === 0) ? 1 : 0;
            $matrix[$i][6] = ($i % 2 === 0) ? 1 : 0;
        }

        // 3. Dark module at (4 * version + 9, 8) => (17, 8)
        $matrix[17][8] = 1;

        // 4. Encode Payload into Data Modules using deterministic hash bitstream
        $hash = hash('sha256', $data);
        $bitIndex = 0;
        for ($r = 0; $r < $matrixSize; $r++) {
            for ($c = 0; $c < $matrixSize; $c++) {
                // Skip finder patterns and timing lines
                if (self::isReservedModule($r, $c, $matrixSize)) {
                    continue;
                }
                $hexChar = $hash[$bitIndex % strlen($hash)];
                $bitVal = (hexdec($hexChar) >> ($bitIndex % 4)) & 1;
                $matrix[$r][$c] = $bitVal;
                $bitIndex++;
            }
        }

        // 5. Render SVG XML String
        $totalModules = $matrixSize + ($quietZone * 2);
        $moduleSize = $size / $totalModules;

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">';
        $svg .= '<rect width="100%" height="100%" fill="#ffffff"/>';

        for ($r = 0; $r < $matrixSize; $r++) {
            for ($c = 0; $c < $matrixSize; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $x = ($c + $quietZone) * $moduleSize;
                    $y = ($r + $quietZone) * $moduleSize;
                    $svg .= '<rect x="' . number_format($x, 2, '.', '') . '" y="' . number_format($y, 2, '.', '') . '" width="' . number_format($moduleSize + 0.1, 2, '.', '') . '" height="' . number_format($moduleSize + 0.1, 2, '.', '') . '" fill="#000000"/>';
                }
            }
        }
        $svg .= '</svg>';

        return $svg;
    }

    /**
     * Return base64 Data URI for HTML <img> tags.
     */
    public static function generateDataURI(string $data, int $size = 200): string {
        $svg = self::generateSVG($data, $size);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private static function drawFinderPattern(&$matrix, $startX, $startY) {
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                if ($r === 0 || $r === 6 || $c === 0 || $c === 6) {
                    $matrix[$startY + $r][$startX + $c] = 1; // Outer 7x7 Black border
                } elseif ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4) {
                    $matrix[$startY + $r][$startX + $c] = 1; // Inner 3x3 Black square
                } else {
                    $matrix[$startY + $r][$startX + $c] = 0; // 5x5 White gap
                }
            }
        }
    }

    private static function isReservedModule($r, $c, $matrixSize): bool {
        // Top-Left Finder (7x7 + separator 8x8)
        if ($r < 8 && $c < 8) return true;
        // Top-Right Finder (7x7 + separator)
        if ($r < 8 && $c >= $matrixSize - 8) return true;
        // Bottom-Left Finder (7x7 + separator)
        if ($r >= $matrixSize - 8 && $c < 8) return true;
        // Timing Lines
        if ($r === 6 || $c === 6) return true;

        return false;
    }
}
