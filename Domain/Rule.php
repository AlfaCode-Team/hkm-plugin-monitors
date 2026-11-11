<?php

declare(strict_types=1);

namespace Plugins\Monitors\Domain;

/**
 * A rule over a metric, with BOTH kinds of hysteresis.
 *
 * ── THE TWO KNOBS ───────────────────────────────────────────────────────────
 * A rule whose metric hovers at its threshold pages someone at 3am, resolves,
 * pages again, and teaches the team to ignore the page. Two independent guards:
 *
 *   BAND    `resolveThreshold` differs from `fireThreshold`. A `gt 100` rule
 *           with `resolve 80` fires above 100 and does not resolve until the
 *           metric is at or below 80: a value of 90 changes nothing.
 *   STREAK  `fireAfter` / `resolveAfter` consecutive evaluations. A single
 *           spike is not an incident; a single good reading is not a recovery.
 *
 * Use either or both. A rule with NEITHER (`resolve == fire`, both streaks 1) is
 * allowed — it is sometimes exactly what is wanted — but it flaps, and
 * {@see isDamped()} says so, so the CLI can print a warning.
 *
 * Zero imports outside Domain/. Notification routing lives on the rule because
 * "who is told" is part of what a rule IS; the addresses are data, not behaviour.
 */
final class Rule
{
    /** @param list<string> $mailTo */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly string $metric,
        public readonly Source $source,
        public readonly int $windowSeconds,
        public readonly Comparison $comparison,
        public readonly float $fireThreshold,
        public readonly float $resolveThreshold,
        public readonly int $fireAfter,
        public readonly int $resolveAfter,
        public readonly ?string $tenant,
        public readonly Severity $severity,
        public readonly array $mailTo,
        public readonly bool $webhook,
        public readonly bool $console,
        public readonly bool $enabled,
    ) {}

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Validated construction.
     *
     * @param list<string> $mailTo
     * @throws \InvalidArgumentException
     */
    public static function define(
        string $id,
        string $name,
        string $metric,
        Source $source,
        Comparison $comparison,
        float $fireThreshold,
        ?float $resolveThreshold = null,
        int $windowSeconds = 3600,
        int $fireAfter = 1,
        int $resolveAfter = 1,
        ?string $tenant = null,
        Severity $severity = Severity::Warning,
        array $mailTo = [],
        bool $webhook = true,
        bool $console = true,
        bool $enabled = true,
        string $description = '',
    ): self {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $name) !== 1) {
            throw new \InvalidArgumentException('A rule name is lower-case letters, digits and . _ - (max 100), starting with a letter or digit.');
        }

        if (preg_match('/^[a-z][a-z0-9._:-]{0,127}$/', $metric) !== 1) {
            throw new \InvalidArgumentException('Invalid metric name [' . substr($metric, 0, 40) . '].');
        }

        $resolveThreshold ??= $fireThreshold;

        foreach ([$fireThreshold, $resolveThreshold] as $t) {
            if (!is_finite($t)) {
                throw new \InvalidArgumentException('Thresholds must be finite numbers.');
            }
        }

        if (!$comparison->resolveIsOnTheSafeSide($fireThreshold, $resolveThreshold)) {
            throw new \InvalidArgumentException(sprintf(
                'Rule [%s]: with "%s" the resolve threshold (%s) must be on the safe side of the fire threshold (%s). '
                . 'Otherwise the rule can resolve while it is still breaching, or never resolve.',
                $name,
                $comparison->value,
                (string) $resolveThreshold,
                (string) $fireThreshold,
            ));
        }

        if ($fireAfter < 1 || $fireAfter > 1000 || $resolveAfter < 1 || $resolveAfter > 1000) {
            throw new \InvalidArgumentException('fireAfter and resolveAfter are between 1 and 1000 evaluations.');
        }

        if ($source === Source::Gauge) {
            $windowSeconds = 0;
        } elseif ($windowSeconds < 3600 || $windowSeconds % 3600 !== 0) {
            // Usage is stored in hourly buckets. A shorter window would look
            // precise and quietly read the whole current hour.
            throw new \InvalidArgumentException('A sum or growth window is a positive multiple of 3600 seconds (usage buckets are hourly).');
        }

        $emails = [];
        foreach ($mailTo as $address) {
            $address = trim($address);
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $address) === 1) {
                throw new \InvalidArgumentException('Not an email address: [' . substr($address, 0, 60) . '].');
            }
            $emails[strtolower($address)] = strtolower($address);
        }

        if ($emails === [] && !$webhook && !$console) {
            throw new \InvalidArgumentException("Rule [{$name}] has no channel: it would fire into nothing. Give it mail, webhook or console.");
        }

        return new self(
            $id, $name, trim($description), $metric, $source, $windowSeconds, $comparison,
            $fireThreshold, $resolveThreshold, $fireAfter, $resolveAfter,
            $tenant === null ? null : substr($tenant, 0, 64),
            $severity, array_values($emails), $webhook, $console, $enabled,
        );
    }

    /** Rebuild from storage. Trusts the row: it was validated by define() on the way in. */
    public static function reconstitute(
        string $id, string $name, string $description, string $metric, Source $source, int $windowSeconds,
        Comparison $comparison, float $fireThreshold, float $resolveThreshold, int $fireAfter, int $resolveAfter,
        ?string $tenant, Severity $severity, array $mailTo, bool $webhook, bool $console, bool $enabled,
    ): self {
        return new self(
            $id, $name, $description, $metric, $source, $windowSeconds, $comparison, $fireThreshold,
            $resolveThreshold, $fireAfter, $resolveAfter, $tenant, $severity, $mailTo, $webhook, $console, $enabled,
        );
    }

    /** Does this rule have ANY protection against flapping at its threshold? */
    public function isDamped(): bool
    {
        return $this->resolveThreshold !== $this->fireThreshold
            || $this->fireAfter > 1
            || $this->resolveAfter > 1;
    }

    /** @return list<string> the channels this rule routes to */
    public function channels(): array
    {
        return array_values(array_filter([
            $this->mailTo !== [] ? 'mail' : null,
            $this->webhook ? 'webhook' : null,
            $this->console ? 'console' : null,
        ]));
    }

    public function withEnabled(bool $enabled): self
    {
        return self::reconstitute(
            $this->id, $this->name, $this->description, $this->metric, $this->source, $this->windowSeconds,
            $this->comparison, $this->fireThreshold, $this->resolveThreshold, $this->fireAfter, $this->resolveAfter,
            $this->tenant, $this->severity, $this->mailTo, $this->webhook, $this->console, $enabled,
        );
    }
}
