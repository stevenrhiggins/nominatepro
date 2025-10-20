<?php
namespace Controllers;

use App\Http\CsrfGuard;
use App\Contracts\RendererInterface;
use Base;
use Services\Store;
use DB\SQL\Mapper;
use Template;
use App\Support\renderHtml;


class WebsiteController {

    private Base $f3;
    private RendererInterface $renderer;

    public function __construct(Base $f3)
    {
        $this->f3 = $f3;
        $this->renderer = new renderHtml($f3);
    }

    public function landing(Base $f3): void
    {
        CsrfGuard::issueToken($f3);
        $now = time();
        $_SESSION['issued_at'] = $now;          // server copy
        $f3->set('site_name','NominatePro');
        $f3->set('site_description','Modern nomination management for awards and recognition programs.');
        $f3->set('site_keywords','NominatePro, nominations, awards, recognition, submission management');
        $f3->set('ASSETS','/assets');
        $f3->set('issued_at', $now);            // sent to client for hidden field
        $f3->set('dateY', date('Y'));
        $this->renderer->render(
            '/views/website/home.htm',
            '',
            [
            ]
        );
        echo Template::instance()->render('\views\website\home.htm');
    }

    public function notify(Base $f3) {
        CsrfGuard::validate($f3, $f3->get('POST.token'));

        $hp = (string)($f3->get('POST.hp') ?? '');
        if (trim($hp) !== '') {
            $this->deny($f3, 'Request blocked.'); return;
        }

        // 3) Time trap: >= 3s and <= 30m
        $clientTs = (int)($f3->get('POST.t') ?? 0);
        $serverTs = (int)($_SESSION['issued_at'] ?? 0);
        $now      = time();

        // Use serverTs when present, fall back to clientTs
        $issued = $serverTs ?: $clientTs;
        if ($issued <= 0 || ($now - $issued) < 3 || ($now - $issued) > (30 * 60)) {
            $this->deny($f3, 'Request timed or too fast.'); return;
        }

        // 4) Rate limit by IP: 5 per 10 minutes
        if (!$this->allowByRateLimit($f3, 5, 600)) {
            $this->deny($f3, 'Too many submissions. Try again later.'); return;
        }

        // 5) Optional: Cloudflare Turnstile verification (uncomment if you add widget)
        $token = (string)($f3->get('POST.cf-turnstile-response') ?? '');
        if (!$this->verifyTurnstile($token)) {
            $this->deny($f3, 'Challenge failed.'); return;
        }

        // Success
        $f3->set('success', 'Thanks! We’ve saved your info.');
        $f3->set('token', CsrfGuard::issueToken($f3));
        $_SESSION['issued_at'] = time();
        $f3->set('issued_at', $_SESSION['issued_at']);
        $f3->set('dateY', date('Y'));
        echo Template::instance()->render('/views/website/home.htm');
    }

    private function deny(Base $f3, string $msg) {
        // Generic deny path
        $f3->set('errors', [$msg]);
        $f3->set('token', \Services\Csrf::issue(true));
        $_SESSION['issued_at'] = time();
        $f3->set('issued_at', $_SESSION['issued_at']);
        $f3->set('dateY', date('Y'));
        echo Template::instance()->render('/views/website/home.htm');
    }

    private function allowByRateLimit(Base $f3, int $max, int $windowSec): bool {
        // Simple SQLite-backed limiter
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $now = time();

        $dbPath = __DIR__ . '/../../storage/site.db';
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE IF NOT EXISTS ratelimit (
            ip TEXT PRIMARY KEY,
            window_start INTEGER NOT NULL,
            count INTEGER NOT NULL
        )');

        // Fetch current window
        $stmt = $pdo->prepare('SELECT window_start, count FROM ratelimit WHERE ip = :ip');
        $stmt->execute([':ip' => $ip]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $stmt = $pdo->prepare('INSERT INTO ratelimit (ip, window_start, count) VALUES (:ip,:ws,:cnt)');
            $stmt->execute([':ip' => $ip, ':ws' => $now, ':cnt' => 1]);
            return true;
        }

        $ws = (int)$row['window_start'];
        $cnt = (int)$row['count'];

        if (($now - $ws) > $windowSec) {
            // new window
            $stmt = $pdo->prepare('UPDATE ratelimit SET window_start=:ws, count=1 WHERE ip=:ip');
            $stmt->execute([':ws' => $now, ':ip' => $ip]);
            return true;
        }

        if ($cnt >= $max) {
            return false;
        }

        $stmt = $pdo->prepare('UPDATE ratelimit SET count = count + 1 WHERE ip=:ip');
        $stmt->execute([':ip' => $ip]);
        return true;
    }

    private function verifyTurnstile(string $token): bool {
        // Only if you add the Turnstile widget. Replace with your secret key.
        // Docs: https://developers.cloudflare.com/turnstile/
        $secret = getenv('TURNSTILE_SECRET') ?: '0x4AAAAAAB2UUiCSSFgQWwUak4bKFlpXyVI';
        if ($token === '') return false;

        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]),
            CURLOPT_TIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) return false;
        $data = json_decode($resp, true);
        return isset($data['success']) && $data['success'] === true;
    }
}
