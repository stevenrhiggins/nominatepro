<?php
declare(strict_types=1);

namespace App\Services;

use Base;

class ControlPanelService
{
    public function __construct(private Base $f3) {}

    public function isDisabled(string $cp_slug): bool
    {
        $sql = 'SELECT 1 FROM control_panel WHERE cp_slug = :slug AND status = 1 LIMIT 1';
        $rows = $this->f3->DB->exec($sql, [':slug' => $cp_slug]);
        return !empty($rows);
    }

    /**
     * Build a unified payload for session, depending on controller context.
     */
    public function sessionPayloadFor(string $controller, object $user): array
    {
        if ($controller === 'cp') {
            $data = $this->f3->DB->exec(
                'SELECT c.*, o.organization_name, o.organization_logo FROM control_panel c
                 JOIN organizations o ON o.organization_slug = c.organization_slug
                 WHERE c.cp_slug = :slug LIMIT 1',
                [':slug' => $user->cp_slug]
            );
        } else {
            $slugField = $controller . '_slug';
            $slug = $user->$slugField ?? null;
            $data = $this->f3->DB->exec(
                'SELECT * FROM organizations WHERE organization_slug = :slug LIMIT 1',
                [':slug' => $slug]
            );
        }
        return $data[0] ?? [];
    }
}
