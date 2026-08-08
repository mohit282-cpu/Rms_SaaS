<?php
// helpers/Security.php - Security, Sanitization, Output Escaping & File Upload Validation

class Security {
    
    /**
     * Set Global Production Security Headers
     */
    public static function setSecurityHeaders() {
        if (headers_sent()) return;

        header("X-Frame-Options: SAMEORIGIN");
        header("X-Content-Type-Options: nosniff");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
        
        // Content Security Policy
        $csp = "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com; " .
               "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
               "font-src 'self' https://fonts.gstatic.com data:; " .
               "img-src 'self' data: blob: https:; " .
               "connect-src 'self'; " .
               "frame-ancestors 'self';";
        header("Content-Security-Policy: " . $csp);

        // Strict-Transport-Security if HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
        }
    }

    /**
     * Sanitize Input Data safely (Trim without entity pre-encoding)
     */
    public static function sanitize($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitize'], $data);
        }
        return trim(strval($data ?? ''));
    }

    /**
     * Escape Output Data for HTML context (Anti-XSS)
     */
    public static function escape($data) {
        return htmlspecialchars(strval($data ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Secure Enterprise File Upload Handler
     * Validates MIME type, restricts file extension, limits file size, and generates random filename.
     */
    public static function uploadFile($file, $uploadDir, $maxSizeBytes = 5242880) {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new Exception("Invalid upload parameters.");
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return null; // No file uploaded
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new Exception("Exceeded file size limit.");
            default:
                throw new Exception("Unknown file upload error.");
        }

        if ($file['size'] > $maxSizeBytes) {
            throw new Exception("File size exceeds maximum allowed limit (2MB).");
        }

        // Validate File Extension (Fixes RMS-014: SVG removed to prevent Stored XSS)
        $filename = basename($file['name']);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (!in_array($ext, $allowedExtensions)) {
            throw new Exception("Invalid file extension. Only JPG, PNG, and WEBP are allowed.");
        }

        // Validate MIME type using finfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMimes = [
            'image/jpeg',
            'image/png',
            'image/webp'
        ];

        if (!in_array($mime, $allowedMimes)) {
            throw new Exception("Invalid file MIME type ($mime). Only genuine images are accepted.");
        }

        // Ensure upload directory exists
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception("Failed to create upload directory.");
            }
        }

        // Generate cryptographically secure unique filename
        $newFilename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $targetPath = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $newFilename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception("Failed to move uploaded file.");
        }

        return $newFilename;
    }
}
