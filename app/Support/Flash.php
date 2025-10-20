<?php
declare(strict_types=1);

/**
 * Lightweight Flash message bag that stores messages in PHP session.
 * Compatible with: Flash::instance()->addMessage($text, $type);
 * Types: info, success, warning, danger (mapped to SWAL icons).
 */
class Flash
{
    private static ?Flash $instance = null;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function addMessage(string $text, string $type = 'info'): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['_flash'][] = ['text' => $text, 'type' => $type];
    }

    public function getMessages(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $msgs = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
        return $msgs;
    }
}
