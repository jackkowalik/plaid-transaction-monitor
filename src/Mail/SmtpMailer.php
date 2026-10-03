<?php
declare(strict_types=1);

namespace PlaidMonitor\Mail;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $encryption,
        private readonly string $fromAddress,
        private readonly string $fromName
    ) {
    }

    public function send(string $to, string $subject, string $textBody): void
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            if ($this->username !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $this->username;
                $mail->Password = $this->password;
            }
            $mail->SMTPSecure = match ($this->encryption) {
                'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS,
                'none', '' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS,
            };
            $mail->SMTPAutoTLS = $this->encryption !== 'none';
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $textBody;
            $mail->isHTML(false);

            $mail->send();
        } catch (MailerException $e) {
            throw new \RuntimeException('SMTP delivery failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
