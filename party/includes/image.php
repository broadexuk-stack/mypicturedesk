<?php
declare(strict_types=1);

// ============================================================
// includes/image.php
// Magic-byte validation, EXIF stripping, resizing, thumbnailing.
// Tries Imagick first; falls back to GD.
// ============================================================

/**
 * Inspect the first 12 bytes of $filePath and return the canonical
 * lowercase extension ('jpg', 'png', 'webp', 'heic') or null if
 * the format is not accepted.
 */
function validate_magic_bytes(string $filePath): ?string
{
    $fp = fopen($filePath, 'rb');
    if (!$fp) return null;
    $header = fread($fp, 12);
    fclose($fp);
    if (strlen($header) < 4) return null;

    // JPEG — FF D8 FF
    if (substr($header, 0, 3) === "\xFF\xD8\xFF") {
        return 'jpg';
    }

    // PNG — 89 50 4E 47 0D 0A 1A 0A
    if (substr($header, 0, 8) === "\x89PNG\r\n\x1a\n") {
        return 'png';
    }

    // WebP — RIFF????WEBP
    if (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
        return 'webp';
    }

    // HEIC / HEIF — ISO Base Media File Format: bytes 4–7 == 'ftyp'
    // Major brands include heic, heix, hevc, mif1, msf1, etc.
    if (strlen($header) >= 8 && substr($header, 4, 4) === 'ftyp') {
        return 'heic';
    }

    return null;
}

/**
 * Process an accepted upload file:
 *   1. Auto-orient from EXIF (before stripping)
 *   2. Strip all EXIF / metadata
 *   3. Resize display image to MAX_DISPLAY_PX (longest edge) if ENABLE_RESIZE
 *   4. Generate THUMB_SIZE × THUMB_SIZE center-crop thumbnail
 *
 * Returns true on success. Logs which processing path was used.
 *
 * @param string $src   Absolute path to the raw quarantine file
 * @param string $dest  Absolute path for the processed display image
 * @param string $thumb Absolute path for the thumbnail
 * @param string $ext   Validated extension ('jpg','png','webp','heic')
 */
function process_image(string $src, string $dest, string $thumb, string $ext): bool
{
    if (extension_loaded('imagick')) {
        error_log("image.php: using Imagick for $ext");
        return process_with_imagick($src, $dest, $thumb, $ext);
    }
    error_log("image.php: using GD for $ext");
    return process_with_gd($src, $dest, $thumb, $ext);
}

// --------------- Imagick path ---------------

function process_with_imagick(string $src, string $dest, string $thumb, string $ext): bool
{
    try {
        $img = new Imagick($src);

        // Flatten animated GIFs / multi-frame HEIC into a single frame
        $img = $img->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

        // Apply EXIF orientation to pixels, then strip all metadata
        $img->autoOrient();
        $img->stripImage();

        if (ENABLE_RESIZE) {
            $img->resizeImage(
                MAX_DISPLAY_PX,
                MAX_DISPLAY_PX,
                Imagick::FILTER_LANCZOS,
                1,
                true  // fit within box, preserve aspect
            );
        }

        // Set reasonable quality for the display image
        $img->setImageCompressionQuality(88);

        // Convert HEIC to JPEG for broad browser compatibility
        if ($ext === 'heic') {
            $img->setImageFormat('jpeg');
            $dest  = preg_replace('/\.heic$/i', '.jpg', $dest);
            $thumb = preg_replace('/\.heic$/i', '.jpg', $thumb);
        }

        $img->writeImage($dest);

        // Thumbnail — square center crop
        $img->cropThumbnailImage(THUMB_SIZE, THUMB_SIZE);
        $img->setImageCompressionQuality(82);
        $img->writeImage($thumb);

        $img->clear();
        return true;
    } catch (ImagickException $e) {
        error_log('process_with_imagick: ' . $e->getMessage());
        return false;
    }
}

// --------------- GD path ---------------

function process_with_gd(string $src, string $dest, string $thumb, string $ext): bool
{
    // GD cannot read HEIC natively. Copy unchanged and warn.
    // Without Imagick, HEIC uploads will be stored but not resized/stripped.
    if ($ext === 'heic') {
        error_log('image.php: GD cannot process HEIC — copying raw file. Install php-imagick for full HEIC support.');
        $ok = @copy($src, $dest) && @copy($src, $thumb);
        if (!$ok) error_log('process_with_gd: failed to copy HEIC file');
        return $ok;
    }

    // Load image
    $img = gd_load($src, $ext);
    if ($img === false) {
        error_log("process_with_gd: gd_load failed for $ext");
        return false;
    }

    // EXIF auto-orientation for JPEG (GD does not do this automatically)
    if (($ext === 'jpg') && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        if (!empty($exif['Orientation'])) {
            $img = gd_orient($img, (int)$exif['Orientation']);
        }
    }

    $w = imagesx($img);
    $h = imagesy($img);

    // Resize display image
    if (ENABLE_RESIZE && ($w > MAX_DISPLAY_PX || $h > MAX_DISPLAY_PX)) {
        if ($w >= $h) {
            $nw = MAX_DISPLAY_PX;
            $nh = (int)round($h * MAX_DISPLAY_PX / $w);
        } else {
            $nh = MAX_DISPLAY_PX;
            $nw = (int)round($w * MAX_DISPLAY_PX / $h);
        }
        $resized = imagecreatetruecolor($nw, $nh);
        gd_preserve_alpha($resized, $ext);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $resized;
        $w   = $nw;
        $h   = $nh;
    }

    // Save display image — re-encoding naturally strips EXIF
    if (!gd_save($img, $dest, $ext, 88)) {
        imagedestroy($img);
        return false;
    }

    // Thumbnail — square center crop
    $side   = min($w, $h);
    $src_x  = (int)(($w - $side) / 2);
    $src_y  = (int)(($h - $side) / 2);
    $thumbI = imagecreatetruecolor(THUMB_SIZE, THUMB_SIZE);
    gd_preserve_alpha($thumbI, $ext);
    imagecopyresampled($thumbI, $img, 0, 0, $src_x, $src_y, THUMB_SIZE, THUMB_SIZE, $side, $side);
    gd_save($thumbI, $thumb, $ext, 82);

    imagedestroy($img);
    imagedestroy($thumbI);
    return true;
}

/** Load a GD image resource from file, returning false on failure. */
function gd_load(string $path, string $ext): GdImage|false
{
    return match ($ext) {
        'jpg'  => @imagecreatefromjpeg($path),
        'png'  => @imagecreatefrompng($path),
        'webp' => @imagecreatefromwebp($path),
        default => false,
    };
}

/** Save a GD image to disk. Quality 0–100 for JPEG/WebP; ignored for PNG. */
function gd_save(GdImage $img, string $path, string $ext, int $quality): bool
{
    return match ($ext) {
        'jpg'  => imagejpeg($img, $path, $quality),
        'png'  => imagepng($img, $path, (int)round((100 - $quality) / 10)),
        'webp' => imagewebp($img, $path, $quality),
        default => false,
    };
}

/** Preserve PNG/WebP transparency on a new GD canvas. */
function gd_preserve_alpha(GdImage $canvas, string $ext): void
{
    if ($ext === 'png' || $ext === 'webp') {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
    }
}

/**
 * Rotate a GD image according to EXIF orientation tag value.
 * Returns a new (or the same) GdImage.
 */
function gd_orient(GdImage $img, int $orientation): GdImage
{
    $angle = match ($orientation) {
        3 => 180,
        6 => 270,
        8 => 90,
        default => 0,
    };
    if ($angle === 0) return $img;
    $rotated = imagerotate($img, $angle, 0);
    imagedestroy($img);
    return $rotated ?: $img;
}

/**
 * Determine the correct file extension for a processed file.
 * HEIC becomes jpg (converted during processing).
 */
function output_extension(string $ext): string
{
    return ($ext === 'heic') ? 'jpg' : $ext;
}

/**
 * Generate a small preview thumbnail for the quarantine folder so the admin
 * can see photos before approving them. Stored in quarantine/thumbs/.
 *
 * Unlike process_image(), this does NOT strip EXIF or resize the display image —
 * it only creates the square thumbnail. The quarantine thumb is admin-only and
 * never exposed publicly.
 *
 * Returns the extension of the file written (may differ from $ext if converted).
 */
function generate_quarantine_thumb(string $src, string $dest, string $ext): bool
{
    if (extension_loaded('imagick')) {
        try {
            $img = new Imagick($src);
            $img = $img->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $img->autoOrient();
            // Convert HEIC to JPEG so browsers can display it
            if ($ext === 'heic') {
                $img->setImageFormat('jpeg');
            }
            $img->cropThumbnailImage(THUMB_SIZE, THUMB_SIZE);
            $img->setImageCompressionQuality(80);
            $img->writeImage($dest);
            $img->clear();
            return true;
        } catch (ImagickException $e) {
            error_log('generate_quarantine_thumb (Imagick): ' . $e->getMessage());
            return false;
        }
    }

    // GD fallback — cannot handle HEIC
    if ($ext === 'heic') {
        error_log('generate_quarantine_thumb: GD cannot process HEIC — no preview available');
        return false;
    }

    $img = gd_load($src, $ext);
    if ($img === false) return false;

    // Auto-orient JPEG
    if ($ext === 'jpg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        if (!empty($exif['Orientation'])) {
            $img = gd_orient($img, (int)$exif['Orientation']);
        }
    }

    $w     = imagesx($img);
    $h     = imagesy($img);
    $side  = min($w, $h);
    $src_x = (int)(($w - $side) / 2);
    $src_y = (int)(($h - $side) / 2);

    $thumb = imagecreatetruecolor(THUMB_SIZE, THUMB_SIZE);
    gd_preserve_alpha($thumb, $ext);
    imagecopyresampled($thumb, $img, 0, 0, $src_x, $src_y, THUMB_SIZE, THUMB_SIZE, $side, $side);

    $ok = gd_save($thumb, $dest, $ext, 80);
    imagedestroy($img);
    imagedestroy($thumb);
    return $ok;
}
