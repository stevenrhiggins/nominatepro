<?php
namespace App\Controllers;

use Base;
use App\Repositories\AwardRepository;
use App\Services\Awards\AwardsService;
use App\Repositories\EmailTokenRepository;
use App\Http\CsrfGuard;
use DateTimeImmutable;
use InvalidArgumentException;
use Mailgun\Mailgun;
use Template;
use Throwable;

class RegistrationController
{
    /** @var Base */
    protected Base $f3;

    protected $award;
    protected array $awardArray;
    protected string $awardSlug;

    /** @var EmailTokenRepository */
    protected $emailTokens;
    protected string $action;

    public function __construct(Base $f3, $args = [])
    {
        $this->f3 = $f3;
        $db = $f3->get('DB');
        $this->action = $args['action'];
        // Services
        $awardRepository = new AwardRepository($f3);
        $this->award     = new AwardsService($awardRepository, $f3);

        $this->emailTokens = new EmailTokenRepository($db);

        $this->awardSlug = (string)($args['award_slug'] ?? $args['awardSlug'] ?? '');
        if ($this->awardSlug === '') {
            $f3->error(400, 'Missing award slug.');
            exit; // stop execution
        }

        $award = $this->award->fetchAwardByAwardSlug($this->awardSlug);
        if (!$award) { $f3->error(404, 'Award not found.'); return; }
        $this->awardArray = $award[0];

        if($args['action']){
            $f3->set('action', $args['action']);
        }
    }

    /**
     * Step 1: check access token requirement.
     * If required and not granted yet, show form.
     * If not required, go straight to welcome step.
     */
    public function start(Base $f3): void
    {

        // Already granted for this award in the session?
        $granted = (bool)($f3->get("SESSION.access_tokens.$this->awardSlug") ?? false);

        // If token not required, go directly to live welcome
        if ((int)$this->awardArray['use_access_token'] !== 1) {
            $f3->reroute("/app/nomination/$this->awardSlug/live/welcome");
            return;
        }

        // If token already granted, go directly to welcome
        if ($granted) {
            $f3->reroute("/app/nomination/$this->awardSlug/live/welcome");
            return;
        }

        $verb = strtoupper((string)($f3->get('VERB') ?? 'GET'));

        if ($verb === 'GET') {
            // Show the token entry form
            $f3->clear('SESSION.csrf_token');
            CsrfGuard::issueToken($f3);

            $f3->set('award', $this->awardArray);
            $f3->set('errors', []);
            $f3->set('content', 'nominations/access-token.htm');
            echo Template::instance()->render('nominations/layout.htm');
            return;
        }

        // POST: validate token
        try {
            CsrfGuard::validate($f3, $f3->get('POST.token'));
        } catch (Throwable $e) {
            CsrfGuard::issueToken($f3);
            $f3->set('errors', ['Your session expired. Please try again.']);
            $f3->set('award', $this->awardArray);
            $f3->set('content', 'nominations/access-token.htm');
            echo Template::instance()->render('nominations/layout.htm');
            return;
        }

        $submitted = trim((string)$f3->get('POST.access_token'));

        if (!$this->award->validateAccessToken($this->awardSlug, $submitted)) {
            $f3->set('errors', ['That access token is not valid.']);
            $f3->set('award', $this->awardArray);
            $f3->set('content', 'nominations/access-token.htm');
            echo Template::instance()->render('nominations/layout.htm');
            return;
        }

        // Success → store session token + reroute
        $f3->set("SESSION.access_tokens.$this->awardArray", true);
        $f3->reroute("/app/nomination/$this->awardSlug/$this->action/welcome");
    }

    public function welcome(Base $f3): void
    {
        CsrfGuard::issueToken($f3);
        // render welcome page
        $f3->set('award', $this->awardArray);
        $f3->set('content', 'nominations/welcome.htm');
        echo Template::instance()->render('nominations/layout.htm');
    }

    public function registration(Base $f3, array $args = []): void
    {
        // Enforce access token gate if required
        if ((int)$this->awardArray['use_access_token'] === 1) {
            $granted = (bool)($f3->get("SESSION.access_tokens.$this->awardSlug") ?? false);
            if (!$granted) { $f3->reroute("/app/nomination/$this->awardSlug"); return; }
        }

        $verb = strtoupper((string)($f3->get('VERB') ?? 'GET'));
        if ($verb === 'GET') {
            $f3->clear('SESSION.csrf_token');
            CsrfGuard::issueToken($f3);
            $f3->set('award', $this->awardArray);
            $f3->set('content', 'nominations/registration.htm');
            echo Template::instance()->render('nominations/layout.htm');
            return;
        }

        // POST: validate CSRF + email, then send token
        try { CsrfGuard::validate($f3, $f3->get('POST.csrf_token')); }
        catch (Throwable $e) {
            $f3->set('award', $this->awardArray);
            $f3->set('errors', ['Your session expired. Please try again.']);
            $f3->set('content', 'nominations/registration.htm');
            echo Template::instance()->render('nominations/layout.htm');
        }

        $email = trim((string)$f3->get('POST.email'));


//
//        // Optional throttle: avoid spamming (e.g., 1 per minute)
//        if ($this->emailTokens->tooFrequent($this->awardSlug, $email, 60)) {
//            $f3->set('award', $this->awardArray);
//            $f3->set('SESSION.flash.notice', 'You’ve already requested a verification code. Please check your inbox (and spam folder) or wait 1 minute before requesting a new one.');
//            $f3->reroute($verifyUrl);
//            return;
//        }
//
//        $this->emailTokens->markAllUnusedAsUsed($this->awardSlug, $email);

        // Generate token (store only a hash)
        $plainCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); // "083241"
        $codeHash  = hash('sha256', $plainCode);
        $expiresAt = (new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');

        $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $this->emailTokens->create([
            'award_slug' => $this->awardSlug,
            'email'      => mb_strtolower($email),
            'token_hash' => $codeHash,
            'expires_at' => $expiresAt,
            'ip'         => $ip,
            'ua'         => $ua,
            'attempts'   => 0,
            'used_at'    => null,
        ]);

        $this->sendOneTimeCode($email, $plainCode, $this->awardArray);

        $mask = static function (string $e): string {
            if (!str_contains($e, '@')) return $e;
            [$u,$d] = explode('@', $e, 2);
            $uMasked = mb_substr($u, 0, 1) . str_repeat('*', max(1, mb_strlen($u)-1));
            return "$uMasked@$d";
        };

        $verifyUrl = "/app/nomination/$this->awardSlug/{$args['action']}/showVerificationForm?email=" . rawurlencode($email);

        $f3->set('SESSION.flash.notice', 'We just sent a 6-digit verification code to ' . $mask($email) . '. It expires in 30 minutes.');

        $f3->reroute($verifyUrl, false /*permanent*/, 303);
    }

    /**
     * Minimal Mailgun sender (SDK or HTTP). Keep it here or move to a MailService.
     */
    private function sendOneTimeCode(string $toEmail, string $code, array $award): void
    {
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Missing or invalid recipient email.');
        }

        $domain = $_ENV['MAILGUN_DOMAIN'] ?? '';
        $key    = $_ENV['MAILGUN_API_KEY'] ?? '';
        $from   = $_ENV['MAIL_FROM'] ?? "noreply@$domain";
        $subject= "Your {$award['award_name']} nomination verification code.";
        $text = "Your verification code is: $code\n"
            . "It expires in 30 minutes.\n\n"
            . "If you didn’t request this, you can ignore this message.\n"
            . "— NominatePRO";

        $html = "
        <p>Your verification code is: <strong>$code</strong></p>
        <p>It expires in 30 minutes.</p>
        <p>If you didn’t request this, you can ignore this message.</p>
        <p>— NominatePRO</p>";

        // If you have mailgun/mailgun-php installed:
        if (class_exists(Mailgun::class)) {
            $mg = Mailgun::create($key);
            $mg->messages()->send($domain, [
                'from'    => $from,
                'to'      => $toEmail,
                'subject' => $subject,
                'text'    => $text,
                'html'    => $html
            ]);
            return;
        }

        // Fallback raw HTTP using curl
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => "https://api.mailgun.net/v3/$domain/messages",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => "api:$key",
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

    public function showVerificationForm(Base $f3, array $args = []): void
    {
        // CSRF
        $f3->clear('SESSION.csrf_token');
        CsrfGuard::issueToken($f3);

        // Path params (safe access)
        if (isset($args['action'])) {
            $f3->set('action', $args['action']);
        }

        $f3->set('award', $this->awardArray);

        // Query string param ?email=...
        $email = trim((string)($f3->get('GET.email') ?? ''));
        $f3->set('email', $email);            // ok if empty; your template can show an input

        // Optional: consume flash notice if you set one earlier
        if ($n = $f3->get('SESSION.flash.notice')) {
            $f3->set('notice', $n);
            $f3->clear('SESSION.flash.notice');
        }

        $f3->set('content', 'nominations/verify_form.htm');
        echo \Template::instance()->render('nominations/layout.htm');
    }


    public function verifyToken(Base $f3): void
    {
        $awardSlug = $this->awardSlug;
        $action    = $this->action ?: 'live';
        $formUrl   = "/app/nomination/{$awardSlug}/{$action}/showVerificationForm";

        try {
            CsrfGuard::validate($f3, (string)$f3->get('POST.csrf_token'));
        } catch (\Throwable $e) {
            $f3->set('SESSION.flash.notice', 'Session expired. Refresh the page and try again.');
            $f3->reroute($formUrl); // back to form
            return;
        }

        $token = preg_replace('/\s+/', '', (string)$f3->get('POST.code'));
        $email = strtolower(trim((string)$f3->get('POST.email')));

        try {
            if ($token !== '' && $email !== '' && $this->emailTokens->existsValidHash($awardSlug, $email, $token)) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                $nominationSlug = hash('sha1', $awardSlug . $email . microtime(true));
                $f3->set('SESSION.nomination_slug', $nominationSlug);
                $this->emailTokens->markAllUnusedAsUsed($awardSlug, $email, $token);
                $f3->reroute("/app/nomination/{$awardSlug}/{$action}/nominator");
                return;
            }

            $f3->set('SESSION.flash.notice', 'The code is invalid or has expired. Please try again.');
            $f3->reroute($formUrl); // back to form

        } catch (\Throwable $e) {
            // TODO: log
            $f3->set('SESSION.flash.notice', 'Server error while verifying the code. Please try again.');
            $f3->reroute($formUrl);
        }
    }

    public function resendCodeAjax(Base $f3): void
    {
        header('Content-Type: application/json');

            $awardSlug = $this->awardSlug; // from constructor
            $email     = trim((string)$f3->get('POST.email'));

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('A valid email address is required.');
            }

            if ($this->emailTokens->tooFrequent($awardSlug, $email, 60)) {
                http_response_code(429);
                echo json_encode(['ok' => false, 'message' => 'Please check your inbox or wait a minute before requesting another code.']);
                return;
            }

            $this->emailTokens->markAllUnusedAsUsed($awardSlug, $email);

            // Create a fresh token (auto-expires any previous unused)
            $plainCode = $this->emailTokens->createToken(
                $awardSlug,
                $email,
                null,
                30,
                $_SERVER['REMOTE_ADDR']    ?? '',
                $_SERVER['HTTP_USER_AGENT']?? ''
            );

            // Send email
            $this->sendOneTimeCode($email, $plainCode, $this->awardArray);

            // Optionally rotate a new CSRF token for the next request
            CsrfGuard::issueToken($f3);

            echo json_encode([
                'ok' => true,
                'message' => 'We just sent you a new verification code.',
                'csrf_token' => $f3->get('SESSION.csrf_token') // if you rotate
            ]);

    }
}
