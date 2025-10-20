<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Files\UploadService;
use App\Support\renderHtml;
use Base;
use DB\SQL\Mapper;
use App\Contracts\RendererInterface;
use App\Contracts\UploadServiceInterface;
use App\Services\LogoService; // not directly required for upload, but useful if you want to recompute/display after save
use Throwable;

final class SponsorLogo
{
    /** Base directory where files are stored (no trailing slash). */
        private const BASE_DIR = 'ui/images/logos/sponsors';
        private Base $f3;
        private RendererInterface $renderer;
        private UploadServiceInterface $uploader;
        private ?LogoService $logoService = null;

    public function __construct(Base $f3)
    {
        $this->f3 = $f3;
        $this->renderer = new renderHtml($f3);
        $this->uploader = new UploadService($f3);
        $this->logoService = new LogoService($f3);
    }

    /**
     * Entry point that handles both GET and POST.
     * Route example: /app/@awardSlug/sponsor
     */
    public function __invoke(array $args = []): void
    {
        $verb = strtoupper($this->f3->get('VERB') ?? 'GET');

        if ($verb === 'POST') {
            $this->save($args);
            return;
        }

        $this->show($args);
    }

    /**
     * Render the form with any existing data.
     */
    public function index(Base $f3, array $args = []): void
    {
        $awardSlug = (string)($args['awardSlug'] ?? $args['award_slug'] ?? '');
        $row = $this->loadRow($awardSlug);

        $this->renderer->render(
            '/views/settings/sponsors/logo_form.htm',
            '/views/settings/layout.htm',
            [
                'PAGE_TITLE' => 'Sponsor Logo',
                'award_slug' => $awardSlug,
                'data'       => $row ? $row->cast() : [],
            ]
        );
    }

    /**
     * Handle POST: text fields + optional file upload.
     */
    private function save(array $args): void
    {
        $awardSlug = (string)($args['awardSlug'] ?? $args['award_slug'] ?? '');
        if ($awardSlug === '') {
            $this->flash('Invalid award.', 'danger');
            $this->rerouteBack($awardSlug);
            return;
        }

        $post = (array)$this->f3->get('POST');

        // Sanitize text inputs (adjust keys to match your form names)
        $sponsorName                = trim((string)($post['sponsor_name']    ?? ''));
        $sponsorLink                = trim((string)($post['sponsor_link'] ?? ''));
        $sponsorLogo                = trim((string)($post['sponsor_logo'] ?? ''));
        $sponsorAcknowledgement     = trim((string)($post['sponsor_acknowledgement']   ?? ''));

        // Load or create record
        $row = $this->loadRow($awardSlug) ?? $this->newRow($awardSlug);

        // Handle upload if a file was provided
        $files = (array)($_FILES ?? []);
        $fileArray = $files['sponsor_logo'] ?? null;

        try {
            if ($fileArray && is_array($fileArray) && ($fileArray['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $dir = $this->dirFor($awardSlug);
                $preferredName = $this->preferredFilename($sponsorName, $fileArray['name'] ?? '');
                $current = $row->sponsor_logo ?: null;

                // Replace the old file with the new one, keeping only the stored filename
                $storedFilename = $this->uploader->replace($dir, $fileArray, $current, $preferredName);
                $row->sponsor_logo = $storedFilename;
            }

            // Persist text fields
            $row->sponsor_name              = $sponsorName;
            $row->sponsor_link              = $sponsorLink;
            $row->sponsor_logo	            = $sponsorLogo;
            $row->sponsor_acknowledgement   = $sponsorAcknowledgement;
            $row->date_edited     = date('Y-m-d H:i:s');

            $row->save();

            Flash::instance()->addMessage(
                $count > 0 ? 'The question was deleted' : 'The question could not be deleted. Try again.',
                $count > 0 ? 'success' : 'danger'
            );
            $f3->reroute($paths['list']);

            $this->flash('Sponsor details saved.', 'success');
        } catch (Throwable $e) {
            $this->flash('Could not save sponsor details. ' . $e->getMessage(), 'danger');
        }

        $this->rerouteBack($awardSlug);
    }

    /**
     * Compute the directory for a given award.
     */
    private function dirFor(string $awardSlug): string
    {
        // e.g. ui/images/logos/sponsors/{awardSlug}/
        $dir = rtrim(self::BASE_DIR, '/') . '/' . rawurlencode($awardSlug) . '/';
        return $dir;
    }

    /**
     * Best-effort, readable filename suggestion.
     */
    private function preferredFilename(string $name, string $original): ?string
    {
        if ($original === '') {
            return null;
        }
        $ext = pathinfo($original, PATHINFO_EXTENSION);
        $base = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)), '-');
        if ($base === '') {
            $base = pathinfo($original, PATHINFO_FILENAME);
            $base = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$base)), '-');
        }
        return $ext ? ($base . '.' . strtolower($ext)) : $base;
    }

    /**
     * Load row by award_slug from `sponsors`.
     */
    private function loadRow(string $awardSlug): ?Mapper
    {
        $m = new Mapper($this->f3->get('DB'), 'awards');
        $m->load(['award_slug = ?', $awardSlug]);
        return $m->dry() ? null : $m;
    }

    /**
     * Create a new row with award_slug preset.
     */
    private function newRow(string $awardSlug): Mapper
    {
        $m = new Mapper($this->db(), 'sponsors');
        $m->award_slug = $awardSlug;
        return $m;
    }

    /**
     * Reroute back to this settings page (adjust to your route).
     */
    private function rerouteBack(string $awardSlug): void
    {
        $route = "app/{$awardSlug}/sponsor#settings";
        $this->f3->reroute($route);
    }
}
