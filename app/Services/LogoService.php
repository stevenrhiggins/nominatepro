<?php
namespace App\Services;

use Base;
use DB\SQL\Mapper;

/**
 * Minimal, robust logo service.
 *
 * - Saves files to <ROOT>/assets/logos/{slug}/{filename}
 * - Stores DB value as "assets/logos/{slug}/{filename}"
 * - Deletes the old file when replacing
 * - Supports any logo field name (e.g., 'division_logo', 'organization_logo', 'sponsor_logo', 'header_logo')
 *
 * Notes:
 * - This class intentionally avoids indirection and external upload services.
 * - If you previously had callers relying on helper methods (e.g., slug, path resolution),
 *   compatible helpers remain here.
 */
final class LogoService
{
    public function __construct(
        private Base $f3,
        private array $options = []
    ) {
        // Reasonable defaults; keep it simple.
        $this->options = array_replace([
            'allowed_ext'  => ['jpg','jpeg','png','webp','svg'],
            'chmod'        => 0644,
            'dir_chmod'    => 0755,
            'check_mime'   => true,   // quick finfo check for raster images
            'overwrite'    => true,   // overwrite same name instead of -1, -2…
            // Optional visual constraints: set to null to skip
            'min_dims'     => [ // per-field or 'default'
                'default' => [1, 1],
                // 'division_logo'     => [300, 300],
                // 'organization_logo' => [300, 300],
                // 'sponsor_logo'      => [150, 150],
                // 'header_logo'       => [600, 150],
            ],
            'ratio_bounds' => [ // [min, max] ratio (width/height); set null to skip
                'default' => null,
                // 'header_logo' => [2.0, 8.0],
            ],
        ], $this->options);
    }

    /* ============================================================
     * Public API
     * ============================================================ */

    /**
     * Save a logo for any field and delete the previous file if different.
     *
     * @param Mapper $entity    The DB entity/mapper that has the logo field.
     * @param string $slug      The folder slug (award/org/etc).
     * @param array  $file      Raw $_FILES['field'] array.
     * @param string $logoField Field name on the entity (e.g., 'sponsor_logo').
     * @param string|null $preferredBase Optional preferred filename base (without ext).
     * @return string           Relative path stored in DB (e.g., assets/logos/my-award/logo.png).
     */
    public function saveLogo(
        Mapper $entity,
        string $slug,
        array $fileOrFiles,
        string $logoField,
        ?string $preferredBase = null
    ): string {

        $file = $this->pickFileArray($fileOrFiles, $logoField);

        // Validate upload + pick name
        $relPath = $this->storeLogoForSlug($slug, $file, $preferredBase ?? $logoField, $logoField);

        // Delete old file if present and different
        $oldRel = isset($entity->$logoField) ? (string)$entity->$logoField : '';
        if ($oldRel && $oldRel !== $relPath) {
            $this->deleteByRelativePath($oldRel);
        }

        // Persist and mirror to session if you rely on it elsewhere
        $entity->$logoField = $relPath;
        $entity->save();
        $this->f3->set('SESSION.'.$logoField, $relPath);

        return $relPath;
    }

    /**
     * Delete a logo file for a specific field on an entity and clear the field.
     */
    public function deleteLogoField(Mapper $entity, string $logoField): bool
    {
        $current = isset($entity->$logoField) ? (string)$entity->$logoField : '';
        if (!$current) {
            return false;
        }
        $deleted = $this->deleteByRelativePath($current);

        $entity->$logoField = '';
        $entity->save();
        $this->f3->clear('SESSION.'.$logoField);

        return $deleted;
    }

    /**
     * Delete by relative DB path (e.g., "assets/logos/slug/name.png").
     */
    public function deleteByRelativePath(string $relPath): bool
    {
        $abs = $this->absoluteFromRelative($relPath);
        if ($abs && is_file($abs)) {
            return @unlink($abs);
        }
        return false;
    }

    /**
     * Quick existence check (some callers use this when retrieving).
     */
    public function fileExists(string $relPath): bool
    {
        $abs = $this->absoluteFromRelative($relPath);
        return $abs ? is_file($abs) : false;
    }

    /**
     * Translate a stored relative path into an absolute filesystem path.
     */
    public function absoluteFromRelative(string $relPath): ?string
    {
        $root = rtrim((string)$this->f3->get('ROOT'), '/');
        $rel  = ltrim($relPath, '/');
        $abs  = $root . '/' . $rel;
        // Guard: must remain under ROOT
        if (!str_starts_with($abs, $root . '/')) {
            return null;
        }
        return $abs;
    }

    /* ============================================================
     * Core storage (simple & direct)
     * ============================================================ */

    /**
     * Minimal store to <ROOT>/assets/logos/{slug}/{filename}.
     * Returns "assets/logos/{slug}/{filename}" for DB.
     *
     * @param string      $slug
     * @param array       $file           Raw $_FILES['field'] array
     * @param string      $preferredBase  filename base (no extension)
     * @param string|null $logoField      used for optional min/ratio checks
     */
    private function storeLogoForSlug(
        string $slug,
        array $file,
        string $preferredBase,
        ?string $logoField = null
    ): string {
        // 1) Resolve destination
        [$absDir, $relDir] = $this->dirsForSlug($slug);
        $this->ensureDir($absDir);

        // 2) Validate upload basics
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload failed (PHP error '.$error.').');
        }
        $tmp = $file['tmp_name'] ?? '';
        if (!$tmp || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Invalid upload source.');
        }

        // 3) Decide filename + ext
        $origName = (string)($file['name'] ?? 'logo');
        [$base, $ext] = $this->splitNameAndExt($origName);
        $base = $this->sanitizePathSegment($preferredBase ?: $base ?: 'logo');
        $ext  = strtolower($ext ?: 'png');
        if ($ext === 'jpeg') { $ext = 'jpg'; }

        $allowed = $this->options['allowed_ext'] ?? ['jpg','jpeg','png','webp','svg'];
        if (!in_array($ext, $allowed, true)) {
            throw new \RuntimeException('Unsupported file extension: '.$ext);
        }

        // 4) Optional MIME sanity for raster images
        if ($this->options['check_mime'] && $ext !== 'svg' && class_exists(\finfo::class)) {
            $fi   = new \finfo(\FILEINFO_MIME_TYPE);
            $mime = (string)$fi->file($tmp);
            $ok   = in_array($mime, ['image/jpeg','image/png','image/webp'], true);
            if (!$ok) {
                throw new \RuntimeException('Invalid image MIME type: '.$mime);
            }
        }

        // 5) Optional visual constraints (kept for compatibility; skip if unset)
        $this->validateDimensionsIfConfigured($tmp, $logoField);

        // 6) Final name (overwrite or unique)
        $filename = "{$base}.{$ext}";
        $target   = "{$absDir}/{$filename}";
        if (!($this->options['overwrite'] ?? true)) {
            for ($i = 1; file_exists($target); $i++) {
                $filename = "{$base}-{$i}.{$ext}";
                $target   = "{$absDir}/{$filename}";
            }
        } else {
            if (file_exists($target)) { @unlink($target); }
        }

        // 7) Move + perms
        if (!@move_uploaded_file($tmp, $target)) {
            $err = error_get_last();
            throw new \RuntimeException('Failed to move uploaded file: '.($err['message'] ?? 'unknown'));
        }
        @chmod($target, $this->options['chmod'] ?? 0644);

        return "{$relDir}/{$filename}";
    }

    /* ===========================================================
    * FILES helpers
    *=============================================================*/

    /** Accept either $_FILES['field'] or the whole $_FILES and pick the right one. */
    private function pickFileArray(array $fileOrFiles, string $logoField): array
    {
        // Already a single-file shape?
        if (isset($fileOrFiles['tmp_name'])) {
            return $this->normalizeFirstFile($fileOrFiles);
        }
        // Exact field present?
        if (isset($fileOrFiles[$logoField])) {
            return $this->normalizeFirstFile($fileOrFiles[$logoField]);
        }
        // If only one file field was posted, auto-pick it:
        $keys = array_keys($fileOrFiles);
        if (count($keys) === 1 && isset($fileOrFiles[$keys[0]])) {
            return $this->normalizeFirstFile($fileOrFiles[$keys[0]]);
        }
        // As a last resort, scan for the first OK-looking file array:
        foreach ($fileOrFiles as $maybe) {
            if (is_array($maybe) && (isset($maybe['tmp_name']) || (isset($maybe['tmp_name'][0])))) {
                return $this->normalizeFirstFile($maybe);
            }
        }
        throw new \InvalidArgumentException("Expected \$_FILES['{$logoField}'] or a single file field in \$_FILES.");
    }

    /** If array is multi-file (name="field[]"), return the first successful entry. */
    private function normalizeFirstFile(array $f): array
    {
        if (!isset($f['tmp_name']) || !is_array($f['tmp_name'])) {
            return $f;
        }
        foreach ($f['tmp_name'] as $i => $tmp) {
            if (($f['error'][$i] ?? \UPLOAD_ERR_OK) === \UPLOAD_ERR_OK && $tmp) {
                return [
                    'name'     => $f['name'][$i]     ?? null,
                    'type'     => $f['type'][$i]     ?? null,
                    'tmp_name' => $f['tmp_name'][$i] ?? null,
                    'error'    => $f['error'][$i]    ?? null,
                    'size'     => $f['size'][$i]     ?? null,
                ];
            }
        }
        // fallback to first index
        $i = array_key_first($f['tmp_name']);
        return [
            'name'     => $f['name'][$i]     ?? null,
            'type'     => $f['type'][$i]     ?? null,
            'tmp_name' => $f['tmp_name'][$i] ?? null,
            'error'    => $f['error'][$i]    ?? null,
            'size'     => $f['size'][$i]     ?? null,
        ];
    }


    /* ============================================================
     * Retrieval helpers (kept to avoid breaking callers)
     * ============================================================ */

    /**
     * Build absolute + relative directories for a slug.
     * Returns [abs, rel] => <ROOT>/assets/logos/{slug}, assets/logos/{slug}
     */
    private function dirsForSlug(string $slug): array
    {
        $root = rtrim((string)$this->f3->get('ROOT'), '/');
        $seg  = $this->sanitizePathSegment($slug);
        $rel  = "assets/logos/{$seg}";
        $abs  = $root . '/' . $rel;
        return [$abs, $rel];
    }

    /**
     * Simple directory ensure with clear errors.
     */
    private function ensureDir(string $dir): void
    {
        clearstatcache();
        if (!is_dir($dir) && !@mkdir($dir, $this->options['dir_chmod'] ?? 0755, true)) {
            $err = error_get_last();
            throw new \RuntimeException("mkdir failed for {$dir}: ".($err['message'] ?? 'unknown'));
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException("Directory not writable: {$dir}");
        }
    }

    /* ============================================================
     * Safe utilities (kept because other code may call them)
     * ============================================================ */

    /**
     * Split filename into [base, ext] (ext without the dot).
     */
    private function splitNameAndExt(?string $name): array
    {
        $name = (string)($name ?? '');
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: '');
        $base = pathinfo($name, PATHINFO_FILENAME) ?: '';
        return [$base, $ext];
    }

    /**
     * Safe path segment for directories or base file names.
     */
    private function sanitizePathSegment(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('~[^a-z0-9._-]+~', '-', $s);
        return trim($s, '-._/');
    }

    /**
     * Optional dimension checks kept for compatibility; configure in $options['min_dims'] / ['ratio_bounds'].
     * If no constraints set for a field (or 'default'), this is a no-op.
     */
    private function validateDimensionsIfConfigured(string $tmpFile, ?string $logoField): void
    {
        $field = $logoField ?? 'default';

        // Min dimensions
        $minMap = $this->options['min_dims'] ?? [];
        $mins   = $minMap[$field] ?? $minMap['default'] ?? null;

        // Ratio bounds
        $ratioMap = $this->options['ratio_bounds'] ?? [];
        $ratioB   = $ratioMap[$field] ?? $ratioMap['default'] ?? null;

        if (!$mins && !$ratioB) {
            return; // nothing to enforce
        }

        [$w, $h] = $this->imageSizeFast($tmpFile);
        if ($w <= 0 || $h <= 0) {
            return; // skip if unreadable; keeps this optional
        }

        if ($mins) {
            [$minW, $minH] = $mins;
            if ($w < $minW || $h < $minH) {
                throw new \RuntimeException("Logo must be at least {$minW}×{$minH} pixels.");
            }
        }

        if ($ratioB) {
            [$minR, $maxR] = $ratioB;
            $ratio = $h > 0 ? $w / $h : 0;
            if ($minR !== null && $ratio < $minR || $maxR !== null && $ratio > $maxR) {
                throw new \RuntimeException("Logo aspect ratio must be between {$minR}:1 and {$maxR}:1.");
            }
        }
    }

    /**
     * Fast size for common raster types; SVG returns [0,0] (skipped by checks above).
     */
    private function imageSizeFast(string $file): array
    {
        $info = @getimagesize($file);
        if (is_array($info) && isset($info[0], $info[1])) {
            return [(int)$info[0], (int)$info[1]];
        }
        return [0, 0];
    }

    /**
     * Very small slug helper retained (some callers expect it).
     */
    public function slug(string $val): string
    {
        $s = strtolower(trim($val));
        $s = preg_replace('/[^a-z0-9\-]+/', '-', $s);
        return trim($s, '-');
    }

    /* ============================================================
 * Compatibility helpers your code referenced before
 * ============================================================ */

    /**
     * Return rich info for a logo field on an entity.
     * Shape:
     *  [
     *    'field'     => string,
     *    'relative'  => string|null,
     *    'absolute'  => string|null,
     *    'exists'    => bool,
     *    'width'     => int|null,
     *    'height'    => int|null,
     *    'ratio'     => float|null,    // width / height
     *    'ratio_str' => string|null,   // e.g. "16:9"
     *    'ext'       => string|null,
     *    'mime'      => string|null,
     *  ]
     */
    public function getLogoInfo(Mapper $entity, string $logoField): array
    {
        $rel = isset($entity->$logoField) ? trim((string)$entity->$logoField) : '';
        $rel = $rel !== '' ? $rel : null;

        $abs = $rel ? $this->absoluteFromRelative($rel) : null;
        $exists = $abs ? is_file($abs) : false;

        $width = $height = null;
        $ratio = null;
        $ratioStr = null;
        $mime = null;
        $ext  = $rel ? strtolower(pathinfo($rel, PATHINFO_EXTENSION) ?: '') : null;

        if ($exists) {
            [$width, $height, $ratio] = $this->computeAspectRatioFromPath($abs);
            $ratioStr = $this->computeAspectRatioStringFromPath($abs);

            if (class_exists(\finfo::class)) {
                $fi = new \finfo(\FILEINFO_MIME_TYPE);
                $mime = (string)$fi->file($abs);
            }
        }

        return [
            'field'     => $logoField,
            'relative'  => $rel,
            'absolute'  => $abs,
            'exists'    => $exists,
            'width'     => $width,
            'height'    => $height,
            'ratio'     => $ratio,
            'ratio_str' => $ratioStr,
            'ext'       => $ext,
            'mime'      => $mime,
        ];
    }

    /**
     * Resolve the first available logo field for an award/entity.
     * Default preference order can be customized.
     *
     * @return string|null  Relative path if found, otherwise null.
     */
    public function resolveAwardLogo($award, array $preferredFields = ['header_logo','organization_logo','sponsor_logo','division_logo']): ?string
    {
        foreach ($preferredFields as $field) {
            $rel = isset($award[$field]) ? trim((string)$award[$field]) : '';
            if ($rel === '') {
                continue;
            }
            $abs = $this->absoluteFromRelative($rel);
            if ($abs && is_file($abs)) {
                return $rel;
            }
        }
        return null;
    }

    /**
     * Compute [width, height, ratio] from an already-stored file path.
     * Accepts either ABS or REL path; will resolve REL under ROOT.
     */
    public function computeAspectRatioFromPath(string $path): array
    {
        $abs = $path;
        if (!str_starts_with($abs, rtrim((string)$this->f3->get('ROOT'), '/').'/')) {
            // Treat as relative if not under ROOT
            $abs = $this->absoluteFromRelative($path) ?? $path;
        }

        $info = @getimagesize($abs);
        if (!is_array($info) || !isset($info[0], $info[1])) {
            return [0, 0, null];
        }
        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($w <= 0 || $h <= 0) {
            return [0, 0, null];
        }
        return [$w, $h, $h > 0 ? $w / $h : null];
    }

    /**
     * Compute aspect ratio string like "16:9" from a stored file path.
     * Returns null if dimensions cannot be read (e.g., some SVGs).
     */
    public function computeAspectRatioStringFromPath(string $path): ?string
    {
        [$w, $h, $r] = $this->computeAspectRatioFromPath($path);
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        [$rw, $rh] = $this->reduceRatio($w, $h);
        return "{$rw}:{$rh}";
    }

    /**
     * Compute [width, height, ratio] for an UPLOAD array (e.g., $_FILES['field']).
     * Falls back to [0,0,null] if not readable.
     */
    public function computeAspectRatio(array $upload): array
    {
        $tmp = $upload['tmp_name'] ?? null;
        if (!$tmp || !is_file($tmp)) {
            return [0, 0, null];
        }
        $info = @getimagesize($tmp);
        if (!is_array($info) || !isset($info[0], $info[1])) {
            return [0, 0, null];
        }
        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($w <= 0 || $h <= 0) {
            return [0, 0, null];
        }
        return [$w, $h, $h > 0 ? $w / $h : null];
    }

    /* ---------- small internal helpers ---------- */

    /** Reduce W:H to the smallest integer ratio (e.g., 1920×1080 -> 16:9). */
    private function reduceRatio(int $w, int $h): array
    {
        $g = $this->gcd(max(1,$w), max(1,$h));
        return [$w / $g, $h / $g];
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return max(1, $a);
    }
}
