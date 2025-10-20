<?php

namespace App\Contracts;
interface MailerInterface
{
    public function send(string $toEmail, string $subject, string $html, ?string $text = null): void;
}