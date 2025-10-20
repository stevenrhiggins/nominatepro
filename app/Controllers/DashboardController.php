<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Contracts\RendererInterface;
use App\Contracts\SessionInterface;
use App\Repositories\UserRepository;
use App\Services\Auth\AuthService;
use App\Services\ControlPanelService;
use App\Services\DashboardService;
use App\Services\SessionManager;
use App\Support\renderHtml;
use Base;

class DashboardController
{
    private Base $f3;
    private AuthService $auth;
    private SessionInterface $session;      // contract
    private ControlPanelService $cpService;
    private RendererInterface $renderer;    // contract
    private DashboardService $dashboardService;

    public function __construct(Base $f3, array $args = [], $alias = null)
    {
        $this->f3        = $f3;
        $usersRepo   = new UserRepository($this->f3);
        $this->renderer  = new renderHtml($f3);      // implements RendererInterface
        $this->session   = new SessionManager($f3);  // implements SessionInterface
        $this->auth      = new AuthService($f3, $usersRepo);
        $this->cpService = new ControlPanelService($f3);
        $this->dashboardService = new DashboardService($f3);
    }

    public function beforeroute(): void
    {
        if (!$this->f3->get('SESSION.logged')) {
            $this->f3->reroute('/login/cp/index');
            return;
        }
        // Hoist session vars for templates
        $this->f3->set('CP_ID',           $this->f3->get('SESSION.cp_id'));
        $this->f3->set('CP_NAME',         $this->f3->get('SESSION.cp_name'));
        $this->f3->set('CP_SLUG',         $this->f3->get('SESSION.cp_slug'));
        $this->f3->set('CP_LOGO',         $this->f3->get('SESSION.cp_logo'));
        $this->f3->set('CP_CONTACT_NAME', $this->f3->get('SESSION.cp_contact_name'));


    }

    public function index(): void
    {
        $this->f3->clear('SESSION.logo');
        $cpSlug  = (string)$this->f3->get('CP_SLUG');
        $metrics = $this->dashboardService->getAwardMetricsForCpSlug($cpSlug);
        $this->f3->set('all_awards',  $metrics['total']  ?? 0);
        $this->f3->set('active_awards', $metrics['active'] ?? 0);

        $this->renderer->render(
            'views/control_panel/dashboard.htm',
            'views/settings/layout.htm',
            [
            'PAGE_TITLE' => 'Dashboard',
        ]);
    }
}
