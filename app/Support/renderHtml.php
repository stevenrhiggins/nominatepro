<?php
declare(strict_types=1);

namespace App\Support;

use Base;
use App\Http\CsrfGuard;

final class renderHtml
{
    public function __construct(private Base $f3) {}

    public function render(string $view, string $layout = null, array $hive = []): void
    {
        // Optional toggles (default: on)
        $withCsrf = $hive['_withCsrf'] ?? true;
        $withSwal = $hive['_withSwal'] ?? true;
        unset($hive['_withCsrf'], $hive['_withSwal']);

        // 1) Push provided vars
        foreach ($hive as $k => $v) {
            $this->f3->set($k, $v);
        }

        // 2) CSRF (idempotent)
        if ($withCsrf) {
            if (class_exists(CsrfGuard::class)) {
                CsrfGuard::issueToken($this->f3);
            } elseif (!$this->f3->exists('SESSION.csrf_token')) {
                $this->f3->set('SESSION.csrf_token', bin2hex(random_bytes(32)));
            }
        }

        // Prepare flash once per request for SweetAlert + HTML fallback
        if ($withSwal && !$this->f3->exists('flash_json')) {
            $raw = \Flash::instance()->getMessages(); // pulls & clears

            // Normalize each item to ['text'=>..., 'type'=>...]
            $flash = array_map(function ($m) {
                if (is_string($m)) {
                    return ['text' => $m, 'type' => 'info'];
                }
                $text = $m['text'] ?? $m['message'] ?? $m['content'] ?? '';
                $type = $m['type'] ?? $m['level'] ?? 'info';
                return ['text' => (string)$text, 'type' => (string)$type];
            }, (array)$raw);

            $this->f3->set('flash', $flash);
            $this->f3->set('flash_json', json_encode(
                $flash,
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
            ));
        }


        // 4) Tell layout which inner view to include
        $this->f3->set('content', $view);

        echo \Template::instance()->render($layout);
    }
}
