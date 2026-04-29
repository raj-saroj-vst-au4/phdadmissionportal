<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send a single email via configured SMTP.
 * $attachments: optional list of ['data' => binary, 'name' => 'file.pdf', 'mime' => 'application/pdf'].
 * Returns ['ok'=>bool, 'error'=>string|null].
 */
function send_mail(string $to, string $toName, string $subject, string $htmlBody, array $attachments = []): array {
    $m = new PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host       = SMTP_HOST;
        $m->Port       = SMTP_PORT;
        if (SMTP_USER !== '') {
            $m->SMTPAuth   = true;
            $m->Username   = SMTP_USER;
            $m->Password   = SMTP_PASS;
        }
        if (SMTP_SECURE === 'tls') $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        elseif (SMTP_SECURE === 'ssl') $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $m->CharSet = 'UTF-8';
        $m->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $m->addAddress($to, $toName);
        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body    = $htmlBody;
        $m->AltBody = strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $htmlBody));
        foreach ($attachments as $att) {
            if (empty($att['data']) || empty($att['name'])) continue;
            $mime = $att['mime'] ?? 'application/octet-stream';
            $m->addStringAttachment($att['data'], $att['name'], PHPMailer::ENCODING_BASE64, $mime);
        }
        $m->send();
        return ['ok' => true, 'error' => null];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $m->ErrorInfo ?: $e->getMessage()];
    } catch (Throwable $t) {
        return ['ok' => false, 'error' => $t->getMessage()];
    }
}
