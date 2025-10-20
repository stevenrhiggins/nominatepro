<?php
declare(strict_types=1);

namespace App\Contracts;

interface UploadServiceInterface
{
    /**
     * Store an uploaded file in a base directory. Returns stored filename (not the full path).
     */
    public function store(string $baseDir, array $file, ?string $preferredName = null): string;

    /**
     * Replace an existing file (if provided) with a new upload in base directory.
     * Returns the new stored filename.
     */
    public function replace(string $baseDir, array $file, ?string $currentFilename = null, ?string $preferredName = null): string;

    /**
     * Delete a single file from a base directory (if it exists).
     */
    public function delete(string $baseDir, string $filename): void;

    /**
     * Recursively delete a directory (if it exists).
     */
    public function deleteDir(string $dirPath): void;
}
