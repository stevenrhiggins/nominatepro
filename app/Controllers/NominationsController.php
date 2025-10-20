<?php
namespace App\Controllers;

use Base;
use App\Contracts\RendererInterface;
use App\Support\renderHtml;
use App\Services\Awards\AwardsService;
use App\Repositories\AwardRepository;
use App\Http\CsrfGuard;
use App\Repositories\EmailTokenRepository;
use Mailgun\Mailgun;

class NominationsController
{
    /** @var Base */
    private $f3;

    /** @var RendererInterface */
    private $renderer;

    /** @var AwardsService */
    private $award;

    /** @var EmailTokenRepository */
    private $emailTokens;

    public function __construct(Base $f3)
    {
        $this->f3 = $f3;

        // Services
        $awardRepository = new AwardRepository($f3);
        $this->award     = new AwardsService($awardRepository, $f3);
        $this->renderer  = new renderHtml($f3);

        // Get a PDO instance from your bootstrap/config
        // Adjust the key to whatever you set (e.g., 'PDO', 'DB_PDO', etc.)
//        $pdo = $f3->get('PDO'); // <-- MUST be an actual PDO instance
//
//        if (!$pdo instanceof PDO) {
//            // Fail fast with a clear message so you know what's misconfigured
//            throw new \RuntimeException('PDO not available in $f3. Set $f3->set("PDO", new PDO(...)) in bootstrap.');
//        }

        $this->emailTokens = new EmailTokenRepository($this->f3->get('DB'));
    }

    /**
     * Step 1: check access token requirement.
     * If required and not granted yet, show form.
     * If not required, go straight to welcome step.
     */
    public function start(Base $f3, array $args = []): void
    {
        $awardSlug = (string)($args['award_slug'] ?? $args['awardSlug'] ?? '');
        if ($awardSlug === '') {
            $f3->error(400, 'Missing award slug.');
            return;
        }

        $award = $this->award->fetchAwardByAwardSlug($awardSlug);
        if (!$award) {
            $f3->error(404, 'Award not found.');
            return;
        }

        $award = $award[0];

        // Already granted for this award in the session?
        $granted = (bool)($f3->get("SESSION.access_tokens.$awardSlug") ?? false);

        // If token not required, go directly to live welcome
        if ((int)$award['use_access_token'] !== 1) {
            $f3->reroute("/app/nomination/{$awardSlug}/live/welcome");
            return;
        }

        // If token not required, go directly to live welcome
        if ((int)$award['use_access_token'] !== 1) {
            $f3->reroute("/app/nomination/{$awardSlug}/live/welcome");
            return;
        }

        // If token already granted, go directly to welcome
        if ($granted) {
            $f3->reroute("/app/nomination/{$awardSlug}/live/welcome");
            return;
        }

        $verb = strtoupper((string)($f3->get('VERB') ?? 'GET'));

        if ($verb === 'GET') {
            // Show the token entry form
            $f3->clear('SESSION.csrf_token');
            CsrfGuard::issueToken($f3);

            $f3->set('award', $award);
            $f3->set('errors', []);
            $this->renderer->render(
                '/views/nominations/access-token.htm',
                '/views/nominations/layout.htm'
            );
            return;
        }

        // POST: validate token
        try {
            CsrfGuard::validate($f3, $f3->get('POST.token'));
        } catch (\Throwable $e) {
            $f3->set('errors', ['Your session expired. Please try again.']);
            $f3->set('award', $award);
            $this->renderer->render(
                '/views/nominations/access-token.htm',
                '/views/nominations/layout.htm'
            );
            return;
        }

        $submitted = trim((string)$f3->get('POST.access_token'));

        if (!$this->award->validateAccessToken($awardSlug, $submitted)) {
            $f3->set('errors', ['That access token is not valid.']);
            $f3->set('award', $award);
            $this->renderer->render('views/nominations/access-token.htm');
            return;
        }

        // Success → store session token + reroute
        $f3->set("SESSION.access_tokens.$awardSlug", true);
        $f3->reroute("/app/nomination/{$awardSlug}/live/welcome");
    }

    public function welcome(Base $f3, array $args = []): void
    {
        $awardSlug = (string)($args['award_slug'] ?? $args['awardSlug'] ?? '');
        if ($awardSlug === '') {
            $f3->error(400, 'Missing award slug.');
            return;
        }

        // Fetch award info via the access service (uses repository under the hood)
        $award = $this->award->fetchAwardByAwardSlug($awardSlug);
        if (!$award) {
            $f3->error(404, 'Award not found.');
            return;
        }

        $award = $award[0];

        // Optional: ensure user has access (if required)
//        if ((int)$award['use_access_token'] === 1) {
//            $granted = (bool)($f3->get("SESSION.access_tokens.$awardSlug") ?? false);
//            if (!$granted) {
//                $f3->reroute("/app/nomination/{$awardSlug}");
//                return;
//            }
//        }

        // render welcome page
        $f3->set('award', $award);
        $this->renderer->render(
            'views/nominations/welcome.htm',
            '/views/nominations/layout.htm'
        );
    }

    public function registration(Base $f3, array $args = []): void
    {
        $awardSlug = (string)($args['award_slug'] ?? $args['awardSlug'] ?? '');
        if ($awardSlug === '') { $f3->error(400, 'Missing award slug.'); return; }

        $award = $this->award->fetchAwardByAwardSlug($awardSlug);
        if (!$award) { $f3->error(404, 'Award not found.'); return; }

        $award = $award[0];

        // Enforce access token gate if required
//        if ((int)$award['use_access_token'] === 1) {
//            $granted = (bool)($f3->get("SESSION.access_tokens.$awardSlug") ?? false);
//            if (!$granted) { $f3->reroute("/app/nomination/{$awardSlug}"); return; }
//        }

        $verb = strtoupper((string)($f3->get('VERB') ?? 'GET'));
        if ($verb === 'GET') {
            $f3->clear('SESSION.csrf_token');
            CsrfGuard::issueToken($f3);
            $f3->set('award', $award);
            $this->renderer->render(
                'views/nominations/registration.htm',
                'views/nominations/layout.htm',
            );
            return;
        }

        // POST: validate CSRF + email, then send token
        try { CsrfGuard::validate($f3, $f3->get('POST.csrf_token')); }
        catch (\Throwable $e) {
            $f3->set('award', $award);
            $f3->set('errors', ['Your session expired. Please try again.']);
            $this->renderer->render(
                'views/nominations/registration.htm',
                'views/nominations/layout.htm'
            );
            return;
        }

        $email = trim((string)$f3->get('POST.email'));

        // Optional throttle: avoid spamming (e.g., 1 per minute)
        if ($this->emailTokens->tooFrequent($awardSlug, $email, 60)) {
            $f3->set('award', $award);
            $f3->set('notice', 'We just sent you a link. Please check your inbox.');
            $this->renderer->render(
                'views/nominations/registration.htm',
                'views/nominations/layout.htm'
            );
            return;
        }

        // Generate token (store only a hash)
        $plainToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash  = hash('sha256', $plainToken); // fast+constant length; or password_hash() if you prefer

        $expiresAt  = (new \DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua         = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $this->emailTokens->create([
            'award_slug' => $awardSlug,
            'email'      => mb_strtolower($email),
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'ip'         => $ip,
            'ua'         => $ua,
        ]);

        // Build magic link (GET—no CSRF)
        $baseUrl   = rtrim((string)$f3->get('BASE_URL') ?: '', '/'); // set this in config, e.g. https://example.com
        $verifyUrl = $baseUrl . "/app/nomination/{$awardSlug}/verify-email?token={$plainToken}&email=" . urlencode($email);

        // Send via Mailgun
        $this->sendMagicLink($email, $verifyUrl, $award);

        // UX: show confirmation page/message
        $f3->set('award', $award);
        $f3->set('title', 'Registration');
        $f3->set('notice', 'We sent you a sign-in link. Please check your email.');
        $this->renderer->render(
            'views/nominations/registration.htm',
            'views/nominations/layout.htm'
        );
    }

    /**
     * Minimal Mailgun sender (SDK or HTTP). Keep it here or move to a MailService.
     */
    private function sendMagicLink(string $toEmail, string $verifyUrl, array $award): void
    {
        $domain = $_ENV['MAILGUN_DOMAIN'] ?? '';
        $key    = $_ENV['MAILGUN_API_KEY'] ?? '';
        $from   = $_ENV['MAIL_FROM'] ?? "noreply@{$domain}";
        $subject= "Your {$award['title']} nomination sign-in link";
        $text   = "Hi,\n\nUse this secure link to continue your nomination:\n{$verifyUrl}\n\nThis link expires in 30 minutes.\n\n— NominatePRO";

        // If you have mailgun/mailgun-php installed:
        if (class_exists(Mailgun::class)) {
            $mg = Mailgun::create($key);
            $mg->messages()->send($domain, [
                'from'    => $from,
                'to'      => $toEmail,
                'subject' => $subject,
                'text'    => $text,
            ]);
            return;
        }

        // Fallback raw HTTP using curl
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => "https://api.mailgun.net/v3/{$domain}/messages",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => "api:{$key}",
            CURLOPT_POSTFIELDS     => [
                'from'    => $from,
                'to'      => $toEmail,
                'subject' => $subject,
                'text'    => $text,
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * GET /app/nomination/@award_slug/verify-email?token=...&email=...
     * - verifies email token
     * - finds or creates a nomination record
     * - resumes at the correct step
     */
    public function verifyEmail(Base $f3, array $args = []): void
    {
        $awardSlug = (string)($args['award_slug'] ?? $args['awardSlug'] ?? '');
        $token     = (string)($f3->get('GET.token') ?? '');
        $email     = mb_strtolower((string)($f3->get('GET.email') ?? ''));

        if ($awardSlug === '' || $token === '' || $email === '') {
            $f3->error(400, 'Invalid verification link.');
            return;
        }

        $award = $this->award->findBySlug($awardSlug); // or accessService->findBySlug
        if (!$award) {
            $f3->error(404, 'Award not found.');
            return;
        }

        // 1) verify token
        $tokenHash = hash('sha256', $token);
        $match     = $this->emailTokens->findValid($awardSlug, $email, $tokenHash);
        if (!$match) {
            $f3->error(400, 'This verification link is invalid or has expired.');
            return;
        }
        $this->emailTokens->markUsed((int)$match['id']);
        $f3->set("SESSION.nomination_email_verified.$awardSlug", true);
        $f3->set('SESSION.nomination_email', $email);

        // 2) find or create nomination record
        /** @var SQL $db */
        $db  = $f3->get('DB');
        $repo = new NominationRepository($db);

        $allowMultiple = (int)($award['allow_multiple_nominations'] ?? 0) === 1;
        $needsDocs     = (int)($award['documents_required'] ?? $award['requires_documents'] ?? 0) === 1;

        $existing = $repo->findActiveByAwardAndEmail($awardSlug, $email);

        if (!$existing) {
            $completed = $repo->findLatestCompletedByAwardAndEmail($awardSlug, $email);
            if ($completed && !$allowMultiple) {
                // Already submitted; show advisory page (or reroute to success)
                $f3->set('award', $award);
                $f3->set('message', 'You have already submitted a nomination for this award.');
                $this->renderer->render('views/nominations/already-submitted.htm');
                return;
            }

            // create a fresh nomination (new record every time if allowMultiple; else only when none in progress)
            $existing = $repo->create($awardSlug, $email, $needsDocs);
        }

        // 3) resume step
        $next = $repo->computeNextStep($existing);

        // store handy session vars
        $f3->set("SESSION.nomination_slug.$awardSlug", $existing['nomination_slug']);
        $f3->set("SESSION.nomination_id.$awardSlug",   (int)$existing['id']);

        $f3->reroute($this->stepToRoute($awardSlug, $next));
    }

    private function stepToRoute(string $awardSlug, string $step): string
    {
        switch ($step) {
            case 'nominator':
                return "/app/nomination/{$awardSlug}/live/nominator";
            case 'nominee':
                return "/app/nomination/{$awardSlug}/live/nominee";
            case 'questionnaire':
                return "/app/nomination/{$awardSlug}/live/questionnaire";
            case 'documents':
                return "/app/nomination/{$awardSlug}/live/documents";
            case 'success':
            default:
                return "/app/nomination/{$awardSlug}/live/success";
        }
    }

}
