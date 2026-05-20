<?php
// backend/helpers/ImageStore.php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Image-handling utilities shared between the upload controller and any
 * server-side path that might receive a base64 data URL instead of a URL.
 *
 * - normalize($pathOrDataUrl, $purpose)
 *     If the input is a base64 data URL, decode it to disk and return the
 *     public URL. If it's already a path/URL or empty, return it as-is.
 *     This is the function controllers should call before storing image_path.
 *
 * - saveDataUrl($dataUrl, $purpose)
 *     Lower-level: takes a known data URL and writes it. Returns the URL
 *     or null on failure.
 *
 * Folder layout: <project_root>/uploads/<purpose>/<YYYY>/<MM>/<file>.<ext>
 */
class ImageStore
{
    private const MAX_BYTES = 5_242_880;            // 5 MB
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    private const VALID_PURPOSES = ['order', 'profile', 'employee'];

    /**
     * Pass-through helper.
     *   - empty       → null
     *   - "data:image/jpeg;base64,..." → saves to disk, returns "/uploads/..."
     *   - anything else → returned unchanged (assume it's already a path/URL)
     *
     * If decoding/saving fails the function returns null so the caller can
     * decide whether to error out or persist without an image.
     */
    public static function normalize(?string $value, string $purpose = 'order'): ?string
    {
        if ($value === null) return null;
        $v = trim($value);
        if ($v === '') return null;

        if (str_starts_with($v, 'data:image/')) {
            return self::saveDataUrl($v, $purpose);
        }
        // Already a path/URL — leave it alone
        return $v;
    }

    public static function saveDataUrl(string $dataUrl, string $purpose = 'order'): ?string
    {
        if (!preg_match('#^data:(image/[a-zA-Z0-9+.-]+);base64,(.+)$#', $dataUrl, $m)) {
            return null;
        }
        $mime = strtolower($m[1]);
        if (!isset(self::ALLOWED[$mime])) return null;

        $bin = base64_decode($m[2], true);
        if ($bin === false) return null;
        $len = strlen($bin);
        if ($len < 100 || $len > self::MAX_BYTES) return null;

        // Verify magic bytes
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $sniffed = $finfo->buffer($bin);
        if ($sniffed === false || !isset(self::ALLOWED[strtolower($sniffed)])) return null;

        $purpose = preg_replace('/[^a-z0-9_-]/', '', strtolower($purpose));
        if (!$purpose || !in_array($purpose, self::VALID_PURPOSES, true)) {
            $purpose = 'order';
        }

        $ext     = self::ALLOWED[$mime];
        $relDir  = sprintf('uploads/%s/%s/%s', $purpose, date('Y'), date('m'));
        $absDir  = self::projectRoot() . '/' . $relDir;

        if (!is_dir($absDir) && !mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            return null;
        }

        try { $rand = bin2hex(random_bytes(8)); }
        catch (\Throwable) { $rand = uniqid('', true); }
        $name = date('Ymd_His') . '_' . $rand . '.' . $ext;
        $abs  = $absDir . '/' . $name;

        if (file_put_contents($abs, $bin) === false) return null;
        @chmod($abs, 0644);

        return '/' . $relDir . '/' . $name;
    }

    /**
     * Best-effort project root. Helpers/ is at <root>/backend/helpers/,
     * so two levels up is the public root (where uploads/ should live).
     */
    public static function projectRoot(): string
    {
        return rtrim(dirname(__DIR__, 2), '/');
    }
}