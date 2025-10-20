<?php
namespace App\Services;

use App\Contracts\UploadServiceInterface;
use Base;
use DB\SQL\Mapper;
use RuntimeException;

/**
 * Handles award logo uploads, replacements, deletions, and resolution logic.
 * - Uses UploadServiceInterface for generic file ops.
 * - Computes aspect ratios (PNG/JPG/WEBP/GIF + SVG).
 * - Resolves which logo to render (sponsor > control panel > default).
 */
final class LogoService
{
    public function __construct(
        private Base $f3,
        private UploadServiceInterface $uploader
    ) {}


// LogoService.php (essentials only)
    private function slugSegment(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('~[^a-z0-9._-]+~', '-', $s);
        return trim($s, '-._/');
    }

    private function ensureDirSimple(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $err = error_get_last();
            throw new \RuntimeException("mkdir failed for {$dir}: " . ($err['message'] ?? 'unknown'));
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException("Directory not writable: {$dir}");
        }
    }

// Minimal, dead-simple upload (no dependencies)
    function uploadLogo(string $root, string $slug, string $field = 'logo'): string
    {
        // 1) sanitize and build paths
        $slug = strtolower(trim(preg_replace('~[^a-z0-9._-]+~', '-', $slug), '-._/'));
        if ($slug === '') throw new RuntimeException('Empty slug.');
        $absDir = rtrim($root, '/')."/assets/logos/{$slug}";
        $relDir = "assets/logos/{$slug}";

        // 2) create dir
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true)) {
            $err = error_get_last(); throw new RuntimeException("mkdir failed: ".($err['message'] ?? 'unknown'));
        }
        if (!is_writable($absDir)) throw new RuntimeException("Dir not writable: {$absDir}");

        // 3) read uploaded file (MUST be raw $_FILES[$field])
        if (!isset($_FILES[$field])) throw new RuntimeException("No file field '{$field}'.");
        $f = $_FILES[$field];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException("Upload error code: ".($f['error'] ?? -1));

        // 4) pick name + ext
        $orig = (string)($f['name'] ?? 'logo');
        $base = pathinfo($orig, PATHINFO_FILENAME);
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') $ext = 'jpg';
        if (!in_array($ext, ['jpg','png','webp','svg'], true)) throw new RuntimeException("Unsupported ext: {$ext}");

        // 5) final filename (unique if exists)
        $base = strtolower(trim(preg_replace('~[^a-z0-9._-]+~', '-', $base), '-._/')) ?: 'logo';
        $filename = "{$base}.{$ext}";
        $target   = "{$absDir}/{$filename}";
        for ($i = 1; file_exists($target); $i++) {
            $filename = "{$base}-{$i}.{$ext}";
            $target   = "{$absDir}/{$filename}";
        }

        // 6) move
        $tmp = $f['tmp_name'] ?? '';
        if (!$tmp) throw new RuntimeException('tmp_name missing.');
        if (!@move_uploaded_file($tmp, $target)) {
            // fallback for odd setups; remove if you want to strictly allow only real uploads
            if (!@rename($tmp, $target)) {
                $err = error_get_last(); throw new RuntimeException("Failed to move file: ".($err['message'] ?? 'unknown'));
            }
        }
        @chmod($target, 0644);

        return "{$relDir}/{$filename}";
    }


    /**
     * Returns [absoluteBaseDir, relativeBaseDir] for sponsor logos.
     * Absolute is under ROOT; relative is suitable to store in DB.
     */
    /** Return [absDir, relDir] for sponsors of a given award. */
    /** Returns [absDir, relDir] => <ROOT>/assets/logos/sponsors/{award}, assets/logos/sponsors/{award} */
    /** Safe slug for path segment: letters, numbers, dot, underscore, dash only. */
    private function sanitizePathSegment(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('~[^a-z0-9._-]+~', '-', $s);
        $s = trim($s, '-._/');
        if ($s === '') {
            throw new \RuntimeException('Empty/invalid award slug after sanitization.');
        }
        return $s;
    }

    /** Returns [absDir, relDir] => <ROOT>/assets/logos/sponsors/{award}, assets/logos/sponsors/{award} */
    private function logoDir(string $awardSlug): array
    {
        $root  = rtrim((string)$this->f3->get('ROOT'), '/');
        $award = $this->sanitizePathSegment($awardSlug);

        $rel = 'assets/logos/'.$award;          // << no leading slash
        $abs = $root . '/' . $rel;                       // << forced under ROOT

        return [$abs, $rel];
    }

    /** Ensure directory exists (mkdir -p) and is writable. */
    private function ensureDir(string $dir): void
    {
        clearstatcache();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                $err = error_get_last();
                throw new \RuntimeException("mkdir failed for {$dir}: ".($err['message'] ?? 'unknown error'));
            }
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException("Directory not writable: {$dir}");
        }

        // One-time probe to prove we can write *a* file here.
        $probe = rtrim($dir, '/').'/.probe';
        if (@file_put_contents($probe, 'ok') === false) {
            $err = error_get_last();
            throw new \RuntimeException("Write probe failed at {$dir}: ".($err['message'] ?? 'unknown error'));
        }
        @unlink($probe);
    }

    /**
     * Take a preferred base name and the original upload name to ensure
     * we end up with a clean `basename.ext` that keeps the real extension.
     */
    private function normalizeFilename(string $preferredBase, string $originalName): string
    {
        $base = trim(preg_replace('~[^a-z0-9_-]+~i', '-', $preferredBase), '-_');
        $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'png');
        if ($ext === 'jpeg') { $ext = 'jpg'; }
        return ($base ?: 'logo') . '.' . $ext;
    }

    function delete_logo_if_replaced(string $root, ?string $oldRel, ?string $newRel): void {
        if (!$oldRel || $oldRel === $newRel) return;
        $abs = rtrim($root, '/').'/'.ltrim($oldRel, '/');
        if (is_file($abs)) @unlink($abs);
    }




    /**
     * Delete a logo file, clear DB field and related session keys.
     */
    public function deleteLogo(Mapper $award, string $awardSlug, string $logoField = 'header_logo'): void
    {
        $kind    = $this->logoKindFromField($logoField);
        $baseDir = $this->logoDir($awardSlug, $kind);
        $current = $this->getString($award->$logoField ?? null);

        if ($current !== '') {
            $this->uploader->delete($baseDir, $current);
            $award->$logoField = null;
            $award->save();
            $this->f3->clear('SESSION.'.$logoField);
            $this->f3->clear('SESSION.'.$logoField.'_ratio');
        }
    }


    /**
     * Bulk delete all logos for an award (use sparingly).
     */
    public function deleteAllLogos(string $awardSlug, ?string $kind = null): void
    {
        if ($kind) {
            $this->uploader->deleteDir($this->logoDir($awardSlug, $this->sanitizeLogoKind($kind)));
            return;
        }
        // both
        $this->uploader->deleteDir($this->logoDir($awardSlug, 'sponsors'));
        $this->uploader->deleteDir($this->logoDir($awardSlug, 'organizations'));
    }


    /**
     * Compute aspect ratio and return [width, height, ratioFloat] from an uploaded file array.
     * Returns [0,0,null] if not an image.
     */
    public function computeAspectRatio(array $file): array
    {
        $path = $file['tmp_name'] ?? '';
        if (!is_file($path)) {
            return [0, 0, null];
        }

        // Raster
        $info = @getimagesize($path);
        if ($info && isset($info[0], $info[1]) && $info[1] > 0) {
            $w = (int)$info[0];
            $h = (int)$info[1];
            return [$w, $h, round($w / $h, 3)];
        }

        // SVG (tmp files may be SVG too)
        $name = strtolower((string)($file['name'] ?? ''));
        if (str_ends_with($name, '.svg')) {
            [$w, $h] = $this->svgDimensions($path);
            if ($w > 0 && $h > 0) {
                return [$w, $h, round($w / $h, 3)];
            }
        }

        return [0, 0, null];
    }

    /**
     * Get stored logo info (width, height, mime, ratio, url, size).
     * Returns null if the file is missing or unreadable as an image.
     */
    public function getLogoInfo(string $awardSlug, string $filename, ?string $kind = null): ?array
    {
        $paths = [];
        if ($kind) {
            $paths[] = $this->logoDir($awardSlug, $kind) . '/' . $filename;
        } else {
            // try both locations
            $paths[] = $this->logoDir($awardSlug, 'sponsors') . '/' . $filename;
            $paths[] = $this->logoDir($awardSlug, 'organizations') . '/' . $filename;
        }

        $path = null;
        foreach ($paths as $p) {
            if (is_file($p)) { $path = $p; break; }
        }
        if (!$path) return null;

        $info   = @getimagesize($path);
        $width  = $info[0] ?? 0;
        $height = $info[1] ?? 0;
        $mime   = $info['mime'] ?? (str_ends_with(strtolower($path), '.svg') ? 'image/svg+xml' : null);

        if ((!$width || !$height) && str_ends_with(strtolower($path), '.svg')) {
            [$width, $height] = $this->svgDimensions($path);
        }
        $ratio = $height ? round($width / $height, 3) : null;

        // build web url that matches the dir we found
        $web = str_replace(
            rtrim((string)$this->f3->get('ROOT'), '/') . '/public',
            '',
            $path
        );

        return [
            'filename' => basename($path),
            'path'     => $path,
            'url'      => $web,
            'width'    => (int)$width,
            'height'   => (int)$height,
            'ratio'    => $ratio,
            'mime'     => $mime,
            'size_kb'  => round(filesize($path) / 1024, 1),
        ];
    }


    /**
     * Decide which logo to render (sponsor > control panel > default),
     * compute aspect ratio (as a pretty string), and stash values in session.
     *
     * Returns ['path' => webPath, 'alt' => string, 'isSponsor' => bool, 'aspectRatio' => string|null]
     */
    public function resolveAwardLogo(): array
    {
        $awardSlug   = (string) ($this->f3->get('PARAMS.awardSlug') ?? '');
        $cpSlug      = (string) ($this->f3->get('SESSION.cp_slug') ?? '');
        $cpName      = (string) ($this->f3->get('SESSION.cp_name') ?? 'Control panel');

        $sponsorName = (string) ($this->f3->get('SESSION._award_rows[0].sponsor_name') ?? 'Sponsor');
        $sponsorLogo = (string) ($this->f3->get('SESSION._award_rows[0].sponsor_logo') ?? '');

        // support either 'organization_logo' or legacy 'header_logo'
        $orgName     = (string) ($this->f3->get('SESSION.organization_name') ?? 'Organization');
        $orgLogo     = (string) ($this->f3->get('SESSION.organization_logo')
            ?? $this->f3->get('SESSION.organization_logo' ?? ''));

        $cpLogo      = (string) ($this->f3->get('SESSION.cp_logo') ?? '');
        $sponsorAck  = (string) ($this->f3->get('SESSION._award_rows[0].sponsor_acknowledgement') ?? '');

        $defaultPath = '/assets/logos/nominatePROLogoAndMark.jpg';

        // NEW canonical upload locations (note the subfolders)
        $newSponsorPath = ($awardSlug && $sponsorLogo)
            ? '/assets/logos/sponsors/' . $awardSlug . '/' . basename($sponsorLogo)
            : '';
        $newOrgPath = ($awardSlug && $orgLogo)
            ? '/assets/logos/organizations/' . $cpSlug . '/' . basename($cpLogo)
            : '';
        $newCpPath = ($cpSlug && $cpLogo)
            ? '/assets/logos/organizations/' . $cpSlug . '/' . basename($cpLogo)
            : '';

        // Priority: sponsor → organization → control panel → default
        $candidates = [
            ['path' => $newSponsorPath,    'alt' => 'Logo of ' . $sponsorName, 'isSponsor' => true],
            //['path' => $legacySponsorPath, 'alt' => 'Logo of ' . $sponsorName, 'isSponsor' => true],
            ['path' => $newOrgPath,        'alt' => 'Logo of ' . $orgName,     'isSponsor' => false],
            // ['path' => $legacyOrgPath,     'alt' => 'Logo of ' . $orgName,     'isSponsor' => false],
            ['path' => $newCpPath,         'alt' => 'Logo of ' . $cpName,      'isSponsor' => false],
            //  ['path' => $legacyCpPath,      'alt' => 'Logo of ' . $cpName,      'isSponsor' => false],
            ['path' => $defaultPath,       'alt' => 'Logo of NominatePRO',     'isSponsor' => false],
        ];

        $picked = end($candidates);
        foreach ($candidates as $c) {
            if ($c['path'] && $this->fileExists($c['path'])) { $picked = $c; break; }
        }

        $aspectStr = $this->computeAspectRatioStringFromPath($picked['path']);

        $this->f3->mset([
            'SESSION.logo'                   => $picked['path'],
            'SESSION.logo_alt'               => $picked['alt'],
            'SESSION.aspectRatio'            => $aspectStr,
            'SESSION.sponsor'                => $picked['isSponsor'],
            'SESSION.sponsorAcknowledgement' => $sponsorAck,
        ]);

        return [
            'path'        => $picked['path'],
            'alt'         => $picked['alt'],
            'isSponsor'   => $picked['isSponsor'],
            'aspectRatio' => $aspectStr,
        ];
    }



    // ===== Helpers ==========================================================

    private function guardSlug(string $slug): string
    {
        // Allow letters/digits/underscore/dash only; reject anything else (slashes, dots, spaces, etc.)
        if ($slug === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
            throw new \RuntimeException("Unsafe slug '{$slug}'.");
        }
        return $slug; // return as-is
    }

    private function sanitizeLogoKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        return in_array($kind, ['sponsors','organizations'], true) ? $kind : 'organizations';
    }

    private function logoKindFromField(string $logoField): string
    {
        $f = strtolower($logoField);
        if ($f === 'sponsor_logo') return 'sponsors';
        // treat both 'cpanel_logo' and 'organization_logo' as organization
        return 'organizations';
    }


    private function getString(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }

    private function preferredNameFromUpload(string $base, array $file): ?string
    {
        $name = (string)($file['name'] ?? '');
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '') return null;
        return preg_replace('/[^a-z0-9_\-]/i', '_', $base) . '.' . $ext;
    }

    private function computeAspectRatioStringFromPath(string $webPath): ?string
    {
        $abs = $this->webPathToFsPath($webPath);
        if (!$abs || !is_file($abs)) {
            return null;
        }

        // Raster first
        $info = @getimagesize($abs);
        if ($info && isset($info[0], $info[1]) && $info[1] > 0) {
            return $this->formatAspect((int)$info[0], (int)$info[1]);
        }

        // SVG
        if (str_ends_with(strtolower($abs), '.svg')) {
            [$w, $h] = $this->svgDimensions($abs);
            if ($w > 0 && $h > 0) {
                return $this->formatAspect($w, $h);
            }
        }

        return null;
    }

    /**
     * Prefer reduced integer ratio (e.g., "16:9"); if still huge, show decimal like "1.778:1".
     */
    private function formatAspect(int $w, int $h): string
    {
        $g  = $this->gcd($w, $h);
        $rw = (int) max(1, $w / $g);
        $rh = (int) max(1, $h / $g);

        if ($rw > 1000 || $rh > 1000) {
            $ratio = round($w / $h, 3);
            return "{$ratio}:1";
        }
        return "{$rw}:{$rh}";
    }

    private function gcd(int $a, int $b): int
    {
        $a = abs($a); $b = abs($b);
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return max(1, $a);
    }

    /**
     * Does a *web* path exist on disk (under public/)?
     */
    private function fileExists(string $webPath): bool
    {
        $abs = $this->webPathToFsPath($webPath);
        return $abs ? is_file($abs) : false;
    }


    /**
     * Map a web path "/uploads/..." or "/ui/..." to the filesystem path under ROOT/public/.
     * Returns null if it would escape the public dir.
     */
    /**
     * Map a web path (e.g. "/assets/logos/slug/kind/file.png") to an absolute filesystem path.
     * Supports new and legacy mounts, protects against traversal, and returns null on unsafe paths.
     */
    private function webPathToFsPath(string $webPath): ?string
    {
        $root    = rtrim((string)$this->f3->get('ROOT'), '/');
        $webPath = '/' . ltrim((string)$webPath, '/');
        $webPath = rawurldecode($webPath); // handle %20 etc.

        // Known mounts: prefix => filesystem base
        $mounts = [
            // NEW storage (not under /public)
            '/assets/logos' => $root . '/assets/logos',

            // Legacy/static under /public
            '/uploads'      => $root . '/public/uploads',
            '/ui'           => $root . '/public/ui',
        ];

        foreach ($mounts as $prefix => $base) {
            if (strncmp($webPath, $prefix, strlen($prefix)) === 0) {
                $tail = substr($webPath, strlen($prefix));            // "/{slug}/kind/file.png"
                $tail = preg_replace('#/+#', '/', $tail);             // collapse //
                if (preg_match('#\.\.(?:/|$)#', $tail)) return null;  // block traversal

                $baseReal = realpath($base) ?: $base;
                $abs      = rtrim($base, '/') . $tail;
                $absReal  = realpath($abs);

                // If it exists, ensure it's inside the mount
                if ($absReal !== false) {
                    $br = rtrim(is_string($baseReal) ? $baseReal : $base, '/');
                    if (strpos($absReal, $br) === 0) return $absReal;
                    return null;
                }
                // If it doesn't exist yet, return the intended path (still under base)
                return rtrim($base, '/') . '/' . ltrim($tail, '/');
            }
        }

        // Fallback: treat as /public-relative
        $public  = $root . '/public';
        $abs     = $public . $webPath;
        $absReal = realpath($abs);

        if ($absReal !== false) {
            $pubReal = realpath($public) ?: $public;
            if (strpos($absReal, rtrim($pubReal, '/')) === 0) return $absReal;
            return null;
        }

        if (preg_match('#\.\.(?:/|$)#', $webPath)) return null;
        return $abs; // non-existent yet, but under /public
    }


    /**
     * Extract width/height from SVG using viewBox or width/height attributes.
     */
    private function svgDimensions(string $absPath): array
    {
        $xml = @file_get_contents($absPath);
        if ($xml === false) return [0, 0];

        // viewBox="minX minY width height"
        if (preg_match('/viewBox\s*=\s*"[^"]*?\s+[^"]*?\s+([0-9.]+)\s+([0-9.]+)"/i', $xml, $m)) {
            $w = (int) round((float)$m[1]);
            $h = (int) round((float)$m[2]);
            return [$w, $h];
        }

        // width/height attributes (may include units, e.g., "100px")
        $w = $this->parseSvgLength($xml, 'width');
        $h = $this->parseSvgLength($xml, 'height');
        return [$w, $h];
    }

    private function parseSvgLength(string $xml, string $attr): int
    {
        // Accept digits with optional unit; percentages are ambiguous, default to 0
        if (preg_match('/'.$attr.'\s*=\s*"([0-9.]+)(px|pt|mm|cm|in|pc|em|rem|%)?"/i', $xml, $m)) {
            if (isset($m[2]) && $m[2] === '%') return 0;
            return (int) round((float)$m[1]);
        }
        return 0;
    }

    /**
     * Simple slug sanitizer.
     */
    private function slug(string $val): string
    {
        $s = strtolower(trim($val));
        $s = preg_replace('/[^a-z0-9\-]+/', '-', $s);
        return trim($s, '-');
    }
}
