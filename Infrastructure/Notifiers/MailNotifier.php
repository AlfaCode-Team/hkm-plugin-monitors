<?php

declare(strict_types=1);

namespace Plugins\Monitors\Infrastructure\Notifiers;

use Plugins\Mail\API\Contracts\MailerContract;
use Plugins\Monitors\Application\Ports\Notifier;
use Plugins\Monitors\Domain\Alert;
use Plugins\Monitors\Domain\AlertKind;
use Plugins\Monitors\Infrastructure\Support\Messages;
use Plugins\Monitors\Infrastructure\Support\Number;

/**
 * Sends through the Mail plugin's published contract, SYNCHRONOUSLY (`dispatch`,
 * not `enqueue`): this runs in a worker, and a transport failure has to surface
 * here so the alert stays pending and is retried. `enqueue` would report
 * success the moment the message was queued and hide a broken mailer behind the
 * Mail plugin's own retries.
 *
 * One message per recipient, not one with many `to`: addresses must not be
 * disclosed to each other, and one bad address must not stop the others.
 */
final class MailNotifier implements Notifier
{
    public function __construct(
        private readonly MailerContract $mailer,
        private readonly Messages $messages = new Messages(),
    ) {}

    public function channel(): string
    {
        return 'mail';
    }

    public function send(Alert $alert): void
    {
        $key    = $alert->kind === AlertKind::Fired ? 'fired' : 'resolved';
        $params = [
            'rule'      => $alert->ruleName,
            'metric'    => $alert->metric,
            'value'     => Number::format($alert->value),
            'threshold' => Number::format($alert->threshold),
            'severity'  => strtoupper($alert->severity->value),
            'tenant'    => $alert->tenant ?? $this->messages->get('tenant.all'),
            'at'        => gmdate('Y-m-d H:i:s', $alert->occurredAt),
        ];

        $subject = $this->messages->get("subject.{$key}", $params);
        $body    = $this->messages->get("body.{$key}", $params);

        $failures = [];
        foreach ($alert->mailTo as $address) {
            try {
                $this->mailer->dispatch($this->mailer->message()->to($address)->subject($subject)->text($body));
            } catch (\Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }

        // Recipients that already got it are NOT tracked individually: the
        // granularity is the channel. A retry re-sends to all, which is the
        // safe side of at-least-once for a page.
        if ($failures !== []) {
            throw new \RuntimeException(\count($failures) . ' of ' . \count($alert->mailTo) . ' mail(s) failed: ' . $failures[0]);
        }
    }
}
