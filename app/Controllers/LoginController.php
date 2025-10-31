<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Contracts\SessionInterface;
use App\Http\CsrfGuard;
use App\Repositories\UserRepository;
use App\Services\Auth\AuthService;
use App\Services\ControlPanelService;
use App\Services\SessionManager;
use App\Support\renderHtml;
use Base;
use Flash;


class LoginController
{
    private Base $f3;
    private AuthService $auth;
    private SessionInterface $session;      // contract
    private ControlPanelService $cpService;
    private renderHtml $renderer;    // contract

    // F3-friendly signature; instantiate concretes that IMPLEMENT the contracts
    public function __construct(Base $f3, array $args = [], $alias = null)
    {
        $this->f3        = $f3;
        $users = new UserRepository($f3);
        $this->auth = new AuthService($f3, $users);

        $this->renderer  = new renderHtml($f3);
        $this->session   = new SessionManager($f3);
        $users = new UserRepository($f3);
        $this->auth  = new AuthService($f3, $users);
        $this->cpService = new ControlPanelService($f3);
    }


    // GET /login/@controller/index
    public function index(Base $f3, array $params): void
    {
        $controller = $params['controller'] ?? 'organization';
        $view = $this->viewFor($controller);
        CsrfGuard::issueToken($f3);
        $this->renderer->render($view, 'login/login_layout.htm', ['login' => true]);
        $this->session->unset('message');
    }

    public function resetPassword(Base $f3, array $params): void
    {
        $this->renderer->render($view, 'login/login_layout.htm', ['login' => true]);
        $this->session->unset('message');
    }


    // POST /login/@controller/validate
    public function authenticate(Base $f3, array $params): void
    {
        $controller = $params['controller'] ?? 'organization';

        $post = $f3->clean($f3->get('POST'));

        if (empty($post['username']) || empty($post['password'])) {
            Flash::instance()->addMessage('You must enter a username and password.', 'warning');
            $f3->reroute('/login/'.$controller.'/index');
        }

        $result = $this->auth->validateCredentials($post['username'], $post['password'], $controller);
        if (!$result->ok) {
            Flash::instance()->addMessage('It looks like either the username or password is incorrect.', 'danger');
            $this->renderer->render('login/cp_login.htm', 'login/login_layout.htm');
            //$f3->reroute('/login/'.$controller.'/index');
        }

        // Extra control panel rules
        if ($controller === 'cp') {
            if (empty($result->user->cp_slug)) {
                Flash::instance()->addMessage('We could not find an account with those credentials.', 'danger');
                $f3->reroute('/login/cp/index');
            }
            if ($this->cpService->isDisabled($result->user->cp_slug)) {
                Flash::instance()->addMessage('Access to this account has been disabled by your administrator.', 'danger');
                $f3->reroute('/login/cp/index');
            }
        }

        // Persist login + session payload
        $this->auth->registerLogin($result->user);
        $payload = $this->cpService->sessionPayloadFor($controller, $result->user);
        $this->session->store($controller, $payload);

        // Reroute
        $this->rerouteAfterLogin($controller);
    }

    public function logout(): void
    {
        $this->session->destroy();
        Flash::instance()->addMessage('You have been logged out.', 'info');
        $this->f3->reroute('/login/cp/index');
    }

    private function viewFor(string $controller): string
    {
        return match ($controller) {
            'cp'           => 'login/cp_login.htm',
            'organization' => 'login/organization_login.htm',
            'judges'       => 'login/judges_login.htm',
            default        => 'login/organization_login.htm',
        };
    }

    private function rerouteAfterLogin(string $controller): void
    {
        if ($controller === 'cp') {
            $this->f3->reroute('/app/dashboard/index');
            return;
        }
        if ($controller === 'organization') {
            $this->f3->reroute('/app/organization/index');
            return;
        }
        if ($controller === 'judges') {
            $this->f3->reroute('/app/judges/renderJudgeLogin');
            return;
        }
        $this->f3->reroute('/');
    }
}