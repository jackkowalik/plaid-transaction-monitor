<?php
declare(strict_types=1);

namespace PlaidMonitor\Alerts\Sinks;

use PlaidMonitor\Mail\Mailer;
use Psr\Log\LoggerInterface;

class EmailSink
{
    public function __construct(
        private readonly ?Mailer $mailer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if ($this->mailer === null) {
            $this->logger->warning('Email alert skipped: SMTP is not configured');
            return false;
        }

        try {
            $this->mailer->send($to, $subject, $body);
            return true;
        } catch (\RuntimeException $e) {
            $this->logger->error('Email alert failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
