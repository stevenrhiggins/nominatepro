<?php
declare(strict_types=1);

namespace App\Services\Files;

use App\Contracts\UploadServiceInterface;
use Base;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

// Fat-Free

/**
 * Hardened upload service with:
 * - Strict is_uploaded_file check (toggleable for tests)
 * - Extension + MIME validation (via finfo, toggleable)
 * - Safe preferredName handling (can include an extension)
 * - Robust, non-recursive-symlink deleteDir
 */
final class UploadService implements UploadServiceInterface
{
    public function __construct(
        private Base $f3,
        private array $options = []
    ) {
        $this->options = array_replace([
            // case-insensitive ext list (images + documents)
            'allowed_ext' => [
                // Images
                'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
                // Documents
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                'txt', 'rtf', 'odt', 'ods', 'odp'
            ],

            // map of ext => list of allowed MIME(s)
            'allowed_mime' => [
                // Images
                'jpg'  => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png'  => ['image/png'],
                'gif'  => ['image/gif'],
                'webp' => ['image/webp'],
                'svg'  => ['image/svg+xml', 'image/svg'],

                // Documents
                'pdf'  => ['application/pdf'],
                'doc'  => ['application/msword'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                'xls'  => ['application/vnd.ms-excel'],
                'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                'ppt'  => ['application/vnd.ms-powerpoint'],
                'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
                'txt'  => ['text/plain'],
                'rtf'  => ['application/rtf', 'text/rtf'],
                'odt'  => ['application/vnd.oasis.opendocument.text'],
                'ods'  => ['application/vnd.oasis.opendocument.spreadsheet'],
                'odp'  => ['application/vnd.oasis.opendocument.presentation'],
            ],

            'max_bytes'          => 10 * 1024 * 1024, // 10 MB
            'chmod'              => 0644,
            'dir_chmod'          => 0755,
            'enforce_is_uploaded'=> true,   // set false for CLI/tests
            'use_finfo'          => true,   // set false if finfo not available
        ], $this->options);

        // Normalize allowed_ext to lowercase and ensure uniqueness
        $this->options['allowed_ext'] = array_values(array_unique(
            array_map('strtolower', $this->options['allowed_ext'])
        ));
    }


    public function store(string $baseDir, array $file, ?string $preferredName = null): string
    {
        $this->ensureDir($baseDir);

        // Normalize file array (handles multi-file/nested cases)
        $file = $this->normalizeUploadArray($file);

        // Early diagnostics
        $err  = $file['error'] ?? null;
        $tmp  = $file['tmp_name'] ?? null;
        $name = $file['name'] ?? '';
        error_log("[Uploader] baseDir={$baseDir}");
        error_log("[Uploader] file.error=" . var_export($err, true) . " name=" . var_export($name, true));
        error_log("[Uploader] tmp_name=" . var_export($tmp, true));

        // Standard validations
        $this->validateFileArray($file);
        $this->validateSize($file['size'] ?? 0);

        // Build filename (no double-ext)
        [$preferredBase, $preferredExt] = $this->splitNameAndExt($preferredName);
        [$uploadBase,   $uploadExt]     = $this->splitNameAndExt($name);
        $finalExt = strtolower($preferredExt ?: $uploadExt ?: 'png');
        if ($finalExt === 'jpeg') { $finalExt = 'jpg'; }

        if (empty($tmp) || !is_file($tmp)) {
            throw new \RuntimeException("Temporary upload missing: " . var_export($tmp, true));
        }

        $mime = $this->detectMime($tmp);
        $this->validateMimeForExt($finalExt, $mime);

        $safeBase  = $this->sanitizeFilename($preferredBase ?: $uploadBase ?: 'upload');
        $finalName = $this->uniqueName($baseDir, $safeBase, $finalExt);
        $target    = rtrim($baseDir, '/').'/'.$finalName;

        // More diagnostics
        error_log("[Uploader] finalName={$finalName}");
        error_log("[Uploader] target={$target}");
        error_log("[Uploader] is_uploaded_file=" . (function_exists('is_uploaded_file') && @is_uploaded_file($tmp) ? 'yes' : 'no'));
        error_log("[Uploader] baseDir writable=" . (is_writable($baseDir) ? 'yes' : 'no'));

        // Move strategy
        $enforce = (bool)($this->options['enforce_is_uploaded'] ?? true);
        if ($enforce) {
            if (!is_uploaded_file($tmp)) {
                throw new \RuntimeException("Invalid upload source (not an uploaded file): {$tmp}");
            }
            if (!@move_uploaded_file($tmp, $target)) {
                $e = error_get_last(); $m = $e['message'] ?? 'unknown';
                throw new \RuntimeException("move_uploaded_file failed: {$m} (tmp: {$tmp} → {$target})");
            }
        } else {
            // Dev/CLI/tests: allow rename/copy fallback
            if (!@rename($tmp, $target)) {
                if (!@copy($tmp, $target) || !@unlink($tmp)) {
                    $e = error_get_last(); $m = $e['message'] ?? 'unknown';
                    throw new \RuntimeException("Failed to place file into destination: {$m} (tmp: {$tmp} → {$target})");
                }
            }
        }

        @chmod($target, $this->options['chmod'] ?? 0644);
        error_log("[Uploader] Stored OK: {$target}");
        return $finalName;
    }


    public function replace(string $baseDir, array $file, ?string $currentFilename = null, ?string $preferredName = null): string
    {
        if ($currentFilename) {
            $this->delete($baseDir, $currentFilename);
        }
        return $this->store($baseDir, $file, $preferredName);
    }

    public function delete(string $baseDir, string $filename): void
    {
        $path = rtrim($baseDir, '/').'/'.$filename;
        if (is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }

    public function deleteDir(string $dirPath): void
    {
        if (!is_dir($dirPath)) {
            return;
        }

        $it = new RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($ri as $node) {
            /** @var \SplFileInfo $node */
            $p = $node->getPathname();
            if ($node->isLink()) {
                // Don’t follow/delete targets; remove the link itself
                @unlink($p);
                continue;
            }
            if ($node->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }

        @rmdir($dirPath);
    }

    /* ---------------- Helpers ---------------- */

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, $this->options['dir_chmod'], true) && !is_dir($dir)) {
                throw new RuntimeException("Failed to create directory: {$dir}");
            }
            @chmod($dir, $this->options['dir_chmod']);
        }
        if (!is_writable($dir)) {
            throw new RuntimeException("Directory not writable: {$dir}");
        }
    }

    private function validateFileArray(array $file): void
    {
        foreach (['name','tmp_name'] as $k) {
            if (!isset($file[$k]) || $file[$k] === '') {
                throw new InvalidArgumentException("Invalid upload array: missing '{$k}'.");
            }
        }
        $error = $file['error'] ?? UPLOAD_ERR_OK;
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->phpUploadErrorToMessage($error));
        }
    }

    private function validateSize(int $bytes): void
    {
        if ($bytes <= 0) {
            throw new RuntimeException('Empty upload.');
        }
        if ($bytes > $this->options['max_bytes']) {
            throw new RuntimeException('File too large.');
        }
    }

    private function splitNameAndExt(?string $name): array
    {
        if (!$name) {
            return [null, null];
        }
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: '');
        return [$base ?: null, $ext ?: null];
    }

    private function extractExtension(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: '');
    }

    private function validateExtension(string $ext): void
    {
        if ($ext === '' || !in_array(strtolower($ext), $this->options['allowed_ext'], true)) {
            throw new RuntimeException('Unsupported file type.');
        }
    }

    private function detectMime(?string $tmpPath): ?string
    {
        if (!$this->options['use_finfo'] || !$tmpPath || !is_file($tmpPath)) {
            return null;
        }
        if (!function_exists('finfo_open')) {
            return null;
        }
        $f = finfo_open(\FILEINFO_MIME_TYPE);
        if (!$f) {
            return null;
        }
        $mime = finfo_file($f, $tmpPath) ?: null;
        finfo_close($f);
        return $mime ?: null;
    }

    private function validateMimeForExt(string $ext, ?string $mime): void
    {
        if ($mime === null) {
            return; // best-effort: if we can’t detect, don’t block
        }
        $ext = strtolower($ext);
        $map = $this->options['allowed_mime'][$ext] ?? null;
        if ($map && !in_array($mime, $map, true)) {
            // Common aliasing: some servers report svg as image/svg
            if (!($ext === 'svg' && str_starts_with($mime, 'image/svg'))) {
                throw new RuntimeException("MIME/type mismatch for .{$ext} (got {$mime}).");
            }
        }
    }

    private function sanitizeFilename(string $name): string
    {
        // Strip extension if present; we add validated ext later
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9_\-]+/', '-', $base) ?: 'file';
        return trim($base, '-_');
    }

    private function uniqueName(string $dir, string $base, string $ext): string
    {
        $dir  = rtrim($dir, '/');
        $ext  = trim($ext, '.');
        $cand = "{$base}.{$ext}";
        $i = 1;

        while (file_exists($dir.'/'.$cand)) {
            $cand = "{$base}-{$i}.{$ext}";
            $i++;
        }
        return $cand;
    }

    private function phpUploadErrorToMessage(int $code): string
    {
        return match ($code) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'File exceeds size limit.',
            \UPLOAD_ERR_PARTIAL => 'File partially uploaded.',
            \UPLOAD_ERR_NO_FILE => 'No file uploaded.',
            \UPLOAD_ERR_NO_TMP_DIR => 'Missing temp directory.',
            \UPLOAD_ERR_CANT_WRITE => 'Cannot write file to disk.',
            \UPLOAD_ERR_EXTENSION => 'Upload blocked by extension.',
            default => 'Unknown upload error.',
        };
    }

    /** Normalize a $_FILES-like array to a single-file shape. */
    private function normalizeUploadArray(array $f): array
    {
        // Typical single-file: ['name' => 'x.png', 'tmp_name' => '/tmp/..', 'size'=>..., 'error'=>0]
        if (!isset($f['tmp_name']) || !is_array($f['tmp_name'])) {
            return $f;
        }
        // Multi-file or nested (e.g., name="logo[]" or F3 nesting)
        // Pick the first successful upload entry.
        $firstIdx = null;
        foreach ($f['tmp_name'] as $idx => $tmp) {
            $err = $f['error'][$idx] ?? UPLOAD_ERR_OK;
            if ($tmp && $err === UPLOAD_ERR_OK) { $firstIdx = $idx; break; }
        }
        if ($firstIdx === null) {
            // No valid files; pick 0 to surface a proper error downstream
            $firstIdx = array_key_first($f['tmp_name']);
        }
        return [
            'name'     => $f['name'][$firstIdx]     ?? null,
            'type'     => $f['type'][$firstIdx]     ?? null,
            'tmp_name' => $f['tmp_name'][$firstIdx] ?? null,
            'error'    => $f['error'][$firstIdx]    ?? null,
            'size'     => $f['size'][$firstIdx]     ?? null,
        ];
    }

}
