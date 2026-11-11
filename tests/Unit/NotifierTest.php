<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Unit;

use AlfacodeTeam\Ground\Fakes\FakeLogger;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use PHPUnit\Framework\TestCase;
use Plugins\Monitors\Domain\Alert;
use Plugins\Monitors\Domain\AlertKind;
use Plugins\Monitors\Domain\Severity;
use Plugins\Monitors\Infrastructure\Notifiers\ConsoleNotifier;
use Plugins\Monitors\Infrastructure\Notifiers\MailNotifier;
use Plugins\Monitors\Infrastructure\Notifiers\WebhookNotifier;
use Plugins\Monitors\Infrastructure\Support\Messages;
use Plugins\Webhooks\Domain\WebhookEndpoint;
use Tests\Plugins\Monitors\Support\FakeTranslator;
use Tests\Plugins\Monitors\Support\RecordingListener;
use Tests\Plugins\Monitors\Support\RecordingMailer;

final class NotifierTest extends TestCase
{
    private function alert(AlertKind $kind = AlertKind::Fired, array $mail = ['ops@example.com', 'dev@example.com']): Alert
    {
        return new Alert(
            id: str_repeat('a', 32), ruleId: 'r', ruleName: 'api.errors', metric: 'http.errors',
            severity: Severity::Critical, kind: $kind, value: 120.5, threshold: 100.0,
            occurredAt: gmmktime(12, 0, 0, 3, 5, 2026), tenant: null, mailTo: $mail, webhook: true, console: true,
        );
    }

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Support/translator_stub.php';
        FakeTranslator::$lines = [];
        RecordingListener::$events = [];
        RecordingListener::$throw  = false;
    }

    // ── mail ─────────────────────────────────────────────────────────────────

    public function test_mail_sends_one_message_per_recipient_so_addresses_are_not_disclosed(): void
    {
        $mailer = new RecordingMailer();

        (new MailNotifier($mailer))->send($this->alert());

        $this->assertCount(2, $mailer->sent);
        $this->assertCount(1, $mailer->sent[0]->getTo());
        $this->assertSame('ops@example.com', $mailer->sent[0]->getTo()[0]->email);
        $this->assertSame('dev@example.com', $mailer->sent[1]->getTo()[0]->email);
    }

    public function test_mail_words_come_from_the_catalogue_and_state_the_facts(): void
    {
        $mailer = new RecordingMailer();

        (new MailNotifier($mailer))->send($this->alert());
        (new MailNotifier($mailer))->send($this->alert(AlertKind::Resolved, ['ops@example.com']));

        $this->assertSame('[CRITICAL] FIRING: api.errors', $mailer->sent[0]->getSubject());
        $this->assertStringContainsString('120.5', $mailer->sent[0]->getText());
        $this->assertStringContainsString('all tenants', $mailer->sent[0]->getText());
        $this->assertStringContainsString('2026-03-05 12:00:00', $mailer->sent[0]->getText());
        $this->assertSame('[CRITICAL] RESOLVED: api.errors', $mailer->sent[2]->getSubject());
    }

    public function test_one_refused_address_does_not_stop_the_others_but_fails_the_channel(): void
    {
        $mailer = new RecordingMailer();
        $mailer->failFor = ['ops@example.com'];

        try {
            (new MailNotifier($mailer))->send($this->alert());
            $this->fail('the channel must report the failure so the alert is retried');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 of 2', $e->getMessage());
        }

        $this->assertCount(1, $mailer->sent);
        $this->assertSame('dev@example.com', $mailer->sent[0]->getTo()[0]->email);
    }

    // ── webhook ──────────────────────────────────────────────────────────────

    private function bus(): EventBus
    {
        $bus = new EventBus(new CoreContainer());
        $bus->subscribe(EventBus::ALL, RecordingListener::class);

        return $bus;
    }

    public function test_webhook_dispatches_a_primitive_only_event_named_after_the_kind(): void
    {
        $notifier = new WebhookNotifier($this->bus());

        $notifier->send($this->alert());
        $notifier->send($this->alert(AlertKind::Resolved));

        $this->assertSame(['monitor.alert.fired', 'monitor.alert.resolved'], array_map(static fn ($e) => $e->name(), RecordingListener::$events));

        $payload = RecordingListener::$events[0]->payload();
        $this->assertSame(str_repeat('a', 32), $payload['alert_id']);
        $this->assertSame('critical', $payload['severity']);
        $this->assertSame(120.5, $payload['value']);
        $this->assertSame('2026-03-05T12:00:00+00:00', $payload['occurred_at']);
        $this->assertSame('', $payload['tenant']);

        foreach ($payload as $value) {
            $this->assertTrue(\is_string($value) || \is_float($value), 'an integration event carries primitives only');
        }
    }

    public function test_a_listener_failure_which_dispatch_swallows_still_fails_the_channel(): void
    {
        RecordingListener::$throw = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not queue deliveries');

        (new WebhookNotifier($this->bus()))->send($this->alert());
    }

    public function test_the_event_names_match_what_a_registered_webhook_endpoint_would_subscribe_with(): void
    {
        // The REAL Webhooks domain class, so this breaks if the pattern grammar changes.
        foreach (['monitor.alert.*', 'monitor.**', 'monitor.alert.fired'] as $pattern) {
            $endpoint = WebhookEndpoint::of('e1', 'https://example.com/hook', [$pattern], 'secret-secret-secret-secret');

            $this->assertTrue($endpoint->subscribesTo('monitor.alert.fired'), $pattern);
        }

        $narrow = WebhookEndpoint::of('e2', 'https://example.com/hook', ['monitor.alert.resolved'], 'secret-secret-secret-secret');
        $this->assertFalse($narrow->subscribesTo('monitor.alert.fired'));
    }

    // ── console ──────────────────────────────────────────────────────────────

    public function test_console_logs_at_the_rules_severity_and_a_resolution_as_info(): void
    {
        $logger = new FakeLogger();
        $n      = new ConsoleNotifier($logger);

        $n->send($this->alert());
        $n->send($this->alert(AlertKind::Resolved));

        $this->assertTrue($logger->logged('critical', 'FIRING api.errors'));
        $this->assertTrue($logger->logged('info', 'RESOLVED api.errors'));
    }

    public function test_console_without_a_logger_is_a_harmless_no_op(): void
    {
        (new ConsoleNotifier(null))->send($this->alert());

        $this->addToAssertionCount(1);
    }

    // ── catalogue ────────────────────────────────────────────────────────────

    public function test_french_has_exactly_the_keys_english_has(): void
    {
        $en = require \dirname(__DIR__, 2) . '/resources/lang/en/alert.php';
        $fr = require \dirname(__DIR__, 2) . '/resources/lang/fr/alert.php';

        $this->assertSame(array_keys($en), array_keys($fr));

        foreach ($en as $key => $line) {
            preg_match_all('/:[a-z]+/', $line, $a);
            preg_match_all('/:[a-z]+/', $fr[$key], $b);
            $this->assertEqualsCanonicalizing($a[0], $b[0], "placeholders differ in [{$key}]");
        }
    }

    public function test_with_a_translator_installed_a_key_it_does_not_know_still_falls_back_to_the_english_catalogue(): void
    {
        // `__()` echoes the key for a missing line. Taking that at face value
        // would put "monitors::alert.subject.fired" in the subject of a page.
        $mailer = new RecordingMailer();
        (new MailNotifier($mailer))->send($this->alert());

        $this->assertSame('[CRITICAL] FIRING: api.errors', $mailer->sent[0]->getSubject());
    }

    public function test_a_translation_a_project_supplies_wins_and_the_rest_still_come_from_the_catalogue(): void
    {
        FakeTranslator::$lines = ['monitors::alert.subject.fired' => 'PAGE :rule (:severity)'];

        $mailer = new RecordingMailer();
        (new MailNotifier($mailer))->send($this->alert());

        $this->assertSame('PAGE api.errors (CRITICAL)', $mailer->sent[0]->getSubject());
        $this->assertStringContainsString('120.5', $mailer->sent[0]->getText(), 'the un-overridden body still renders');
    }

    public function test_a_missing_key_is_returned_as_the_key_not_a_blank_message(): void
    {
        $this->assertSame('nope', (new Messages())->get('nope'));
    }
}
