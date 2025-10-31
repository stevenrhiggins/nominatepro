<?php
namespace App\Controllers;

use App\Repositories\AwardRepository;
use App\Services\Awards\AwardsService;
use App\Http\CsrfGuard;
use DateTimeImmutable;
use InvalidArgumentException;
use Mailgun\Mailgun;
use Template;
use Throwable;

class NominationsController
{
    protected Base $f3;

    protected $award;
    protected array $awardArray;
    protected string $awardSlug;

    protected string $action;

    public function __construct(Base $f3, $args = [])
    {
        $this->f3 = $f3;
        $db = $f3->get('DB');
        $this->action = $args['action'];
        // Services
        $awardRepository = new AwardRepository($f3);
        $this->award     = new AwardsService($awardRepository, $f3);

        $this->awardSlug = (string)($args['award_slug'] ?? $args['awardSlug'] ?? '');
        if ($this->awardSlug === '') {
            $f3->error(400, 'Missing award slug.');
            exit; // stop execution
        }

        if(!$f3->get('SESSION.nomination_slug')){

        }

        $award = $this->award->fetchAwardByAwardSlug($this->awardSlug);
        if (!$award) { $f3->error(404, 'Award not found.'); return; }
        $this->awardArray = $award[0];

        if($args['action']){
            $f3->set('action', $args['action']);
        }
    }

}