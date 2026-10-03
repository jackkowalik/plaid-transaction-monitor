<?php
declare(strict_types=1);

namespace PlaidMonitor\Mail;

interface Mailer
{
    /**
     * @throws \RuntimeException when the message could not be sent
     */
    public function send(string $to, string $subject, string $textBody): void;
}
