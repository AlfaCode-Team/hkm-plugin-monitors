<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Support;

use Plugins\Mail\API\Contracts\MailerContract;
use Plugins\Mail\Domain\Message;

final class RecordingMailer implements MailerContract
{
    /** @var list<Message> */
    public array $sent = [];

    /** @var list<string> addresses whose delivery throws */
    public array $failFor = [];

    public function message(): Message
    {
        return Message::make();
    }

    public function dispatch(Message $message): void
    {
        $to = $message->getTo()[0]->email ?? '';

        if (\in_array($to, $this->failFor, true)) {
            throw new \RuntimeException("smtp refused {$to}");
        }

        $this->sent[] = $message;
    }

    public function enqueue(Message $message): string
    {
        throw new \LogicException('monitors must not enqueue: a transport failure has to surface to the retry logic');
    }

    public function dispatchBatch(array $messages, ?Message $base = null): array
    {
        throw new \LogicException('unused');
    }

    public function preview(Message $message): string
    {
        return '';
    }
}
