<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Support;

use PlaidMonitor\Mail\Mailer;

final class RecordingMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $textBody): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $textBody];
    }
}
