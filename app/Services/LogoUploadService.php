<?php
declare(strict_types=1);

namespace App\Services\Files;

use Base;
use DB\SQL\Mapper;
use RuntimeException;

final class LogoUploadService implements UploadServiceInterface
{
    public function __construct(private Base $f3) {}

    public function recordAndUploadLogo(
        array  $post,
        Mapper $award,
        string $logoType,
        string $sessionSlugKey,
        string $directory,
        string $currentLogoPostKey,
        string $fileLogoKey,
        array  $files
    ): ?string {
        // No upload? Nothing to do.
        if (empty($files[$fileLogoKey]['name']) || empty($files[$fileLogoKey]['tmp_name'])) {
            return null;
        }

        // Build paths
        $slug = (string)$this->f3->get($sessionSlugKey); // e.g. 'SESSION.awardSlug'
        if ($slug === '') {
            throw new RuntimeException('Missing award slug in session for upload path.');
        }

        $baseDir   = 'ui/images/logos';
        $targetDir = $baseDir . '/' . trim($directory, '/'). '/' . $slug;

        // If POST includes the "current" logo key, clear the folder first (legacy parity)
        if (isset($post[$currentLogoPostKey])) {
            $this->removeDirectoryRecursive($targetDir);
        }

        // Ensure directory exists
        $this->createDirectory($targetDir);

        // Sanitize filename a bit (keep parity but safer)
        $originalName = (string)$files[$fileLogoKey]['name'];
        $filename     = $this->sanitizeFilename($originalName);
        $tmpPath      = (string)$files[$fileLogoKey]['tmp_name'];
        $destPath     = rtrim($targetDir, '/').'/'.$filename;

        // Move the file
        if (!is_uploaded_file($tmpPath) || !@move_uploaded_file($tmpPath, $destPath)) {
            throw new RuntimeException('Failed to move uploaded file.');
        }

        // Update mapper column + session (same as legacy)
        $award->$logoType = $filename;
        $this->f3->set('SESSION.'.$logoType, $filename);

        return $filename;
    }

    // ——— helpers ———

    private function removeDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) $this->removeDirectoryRecursive($path);
            else @unlink($path);
        }
        @rmdir($dir);
    }

    private function createDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory: '.$dir);
        }
    }

    private function sanitizeFilename(string $name): string
    {
        $name = preg_replace('~[^\w.\-]+~u', '_', $name) ?? 'logo';
        // prevent dotfiles / path tricks
        $name = ltrim(basename($name), '.');
        return $name !== '' ? $name : 'logo';
    }
}
