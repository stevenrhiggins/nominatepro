<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * View renderer abstraction so controllers don't depend on a base class.
 * Implement this using your templating approach (F3->set + Template::instance()->render, etc).
 */
interface RendererInterface
{
    /**
     * Render a view inside a layout with hive data.
     * Implementations can set hive variables and echo the output.
     *
     * @param string $view   Path to the view template
     * @param string $layout Path to the layout template
     * @param array  $hive   Key/value data
     */
    public function render(string $view, string $layout, array $hive = []): void;
}
