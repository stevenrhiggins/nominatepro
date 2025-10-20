<?php
namespace App\Services;

use App\Contracts\{
    UserRepositoryInterface,
    PasswordResetRepositoryInterface,
    MailerInterface,
    PasswordPolicyInterface
};

final class PasswordResetService {

    public function __construct(
        private \Base $f3, // for base URL
        private $controller,
        private UserRepositoryInterface $users,
        private PasswordResetRepositoryInterface $resets,
        private MailerInterface $mailer,
        private PasswordPolicyInterface $policy
    ) {}

    public function requestByEmail(string $email): void {
        $user = $this->users->findByEmail($email);
        // Regardless of existence, behave the same to avoid user enumeration
        if ($user) {
            $this->f3->set('SESSION.cp_slug', $user[0]['cp_slug']);
            $this->issueToken((int)$user[0]['id'], (string)$user[0]['email']);
        }
    }
    private function issueToken(int $userId, string $email): void {
        // purge old stuff sometimes
        if (random_int(1, 10) === 1) { $this->resets->purgeExpired(); }

        // Token scheme: selector (public) + secret (private)
        $selector = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $secret   = bin2hex(random_bytes(16)); // shown to user via URL as "token"
        $verifierHash = hash('sha256', $secret);

        $expires = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->add(new \DateInterval('PT60M'));
        $this->resets->create($userId, $selector, $verifierHash, $expires);

        $base = rtrim($this->f3->get('SCHEME').'://'.$this->f3->get('HOST'), '/');
        $link = $base."/app/reset/password/$this->controller/$selector/$secret";

        $subject = 'Reset your password';
        $html = '<div style="font-size: 1.2rem"><p>We received a request to reset your password.</p>
                 <p><a href="'.htmlspecialchars($link, ENT_QUOTES).'">Click here to set a new password</a> (valid for 60 minutes).</p>
                 <p>If you didn’t request this, you can ignore this email.</p></div>';
        $this->mailer->send($email, $subject, $html, strip_tags($html));
    }

    public function validateLink(string $selector, string $token): ?array {
        $row = $this->resets->findValidBySelector($selector);
        if (!$row) { return null; }
        $ok = hash_equals($row['verifier_hash'], hash('sha256', $token));
        return $ok ? $row : null;
    }

    public function setNewPassword(string $selector, string $token, string $newPassword): array {
        // returns [ok=>bool, errors=>[]]
        $row = $this->validateLink($selector, $token);
        if (!$row) {
            return ['ok' => false, 'errors' => ['This reset link is invalid or expired.']];
        }

        $errors = $this->policy->validate($newPassword);
        if ($errors) { return ['ok' => false, 'errors' => $errors]; }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->users->updatePassword($this->f3->get('SESSION.'.$this->controller.'_slug'), $hash);
        $this->resets->markUsed((int)$row['user_id']);

        return ['ok' => true, 'errors' => []];
    }
}
