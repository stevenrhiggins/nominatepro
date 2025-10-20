<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\{
    PasswordResetRepository,
    UserRepository
};
use App\Services\{
    MailgunMailer,
    PasswordPolicy,
    PasswordResetService
};
use App\Http\CsrfGuard;
use App\Support\renderHtml;
use Base;
use Flash;

final class ResetPasswordController {

    private \Base $f3;
    private $controller;
    private PasswordResetService $service;
    private CsrfGuard $csrf;

    public function __construct(Base $f3, array $params = [])
    {
        $this->f3 = $f3;
        $usersRepo  = new UserRepository($f3);
        $resetsRepo = new PasswordResetRepository($this->f3->get('DB'));
        $mailer     = new MailgunMailer($f3);
        $policy     = new PasswordPolicy();
        $this->renderer = new renderHtml($this->f3);
        $this->controller = $params['controller'];

        $this->service = new PasswordResetService(
            $f3,
            $this->controller,
            $usersRepo,
            $resetsRepo,
            $mailer,
            $policy
        );
    }

    // GET: show form where user enters username
    public function index(Base $f3): void
    {
        CsrfGuard::issueToken($f3);
        $this->renderer->render(
            '/views/password/reset_password_email_form.htm',
            '/views/password/layout.htm',
            [
                'PAGE_TITLE'      => 'Reset Password',
                'controller'      =>  $this->controller,
            ]
        );
    }

    // POST: handle username submission
    public function validate(): void
    {
        try {
            CsrfGuard::validate($this->f3, $this->f3->get('POST.token'));
        } catch (RuntimeException $e) {
            Flash::instance()->addMessage('Security check failed, please try again.', 'danger');
            $this->f3->reroute('/app/reset/password/cp/index');
            return;
        }

        $email = trim((string)$this->f3->get('POST.email'));

        $this->service->requestByEmail($email);

        // Always ambiguous to avoid user enumeration
        Flash::instance()->addMessage('If the email exists, we’ve sent a reset password link to it.', 'info');
        $this->f3->reroute('/app/reset/password/cp/index');
    }

    // GET: user lands here from email to set a new password
    public function resetConfirmForm(Base $f3, $params = []): void
    {
        CsrfGuard::issueToken($f3);

        $selector = (string)$params['selector'];
        $token    = (string)$params['token'];

        // We don’t fully validate yet (avoid info leak), just pass to view
        $this->f3->set('selector', $selector);
        $this->f3->set('token', $token);
        $this->renderer->render(
            '/views/password/reset_password_form.htm',
            '/views/password/layout.htm',
            [
                'PAGE_TITLE'      => 'Reset Your Password',
                'controller'      =>  $params['controller'],
            ]
        );
    }

    // POST: set the new password
    public function resetConfirmSubmit(): void
    {
        try {
            CsrfGuard::validate($this->f3, $this->f3->get('POST.token'));
        } catch (RuntimeException $e) {
            Flash::instance()->addMessage('Security check failed, please try again.', 'danger');
            $this->f3->reroute('/app/reset/password/cp/' .$this->f3->get('POST.selector'). '/' .$this->f3->get('POST.token_value'));
            return;
        }

        $selector = (string)$this->f3->get('POST.selector');
        $token    = (string)$this->f3->get('POST.token_value'); // avoid POST.token name collision
        $pw1      = (string)$this->f3->get('POST.password');
        $pw2      = (string)$this->f3->get('POST.confirm_password');

        if ($pw1 !== $pw2) {
            Flash::instance()->addMessage('Passwords do not match.', 'danger');
            $this->f3->reroute('/app/reset/password/' .$this->controller. '/' .rawurlencode($selector).'/'.rawurlencode($token));
            return;
        }

        $result = $this->service->setNewPassword($selector, $token, $pw1);
        if (!$result['ok']) {
            foreach ($result['errors'] as $e) {
                Flash::instance()->addMessage($e, 'danger');
            }
            $this->f3->reroute('/app/reset/password/' .$this->controller. '/' .rawurlencode($selector).'/'.rawurlencode($token));
            return;
        }

        Flash::instance()->addMessage('Your password has been updated. You can now sign in.', 'success');
        $this->f3->reroute('/login/'.$this->controller.'/index');
    }
}
