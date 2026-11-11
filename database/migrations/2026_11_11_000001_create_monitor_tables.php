<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Rules, where each one is in its fire/resolve cycle, and the alerts raised.
 *
 * Times are unix seconds (bigint), written from ClockPort. Not timestamp
 * columns: the evaluator compares and subtracts them, and a DEFAULT
 * CURRENT_TIMESTAMP would be the database's clock rather than the injected one.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('monitor_rules', static function ($t) {
            $t->char('id', 32);
            $t->string('name', 100);
            $t->string('description', 255)->default('');
            $t->string('metric', 128);
            $t->string('source', 8)->comment('sum | gauge | growth');
            $t->unsignedInteger('window_seconds')->default(0);
            $t->string('comparison', 4)->comment('gt | gte | lt | lte');
            $t->double('fire_threshold');
            $t->double('resolve_threshold');
            $t->unsignedSmallInteger('fire_after')->default(1);
            $t->unsignedSmallInteger('resolve_after')->default(1);

            // NULL = every tenant added together; '' = measurements with no tenant.
            $t->string('tenant', 64)->nullable();

            $t->string('severity', 10)->default('warning');
            $t->text('mail_to');
            $t->boolean('webhook')->default(true);
            $t->boolean('console')->default(true);
            $t->boolean('enabled')->default(true);
            $t->unsignedBigInteger('created_at');
            $t->unsignedBigInteger('updated_at');

            $t->primary(['id'], 'pk_monitor_rule');

            // The name is how an operator addresses a rule; two with one name is
            // an alert nobody can tell apart.
            $t->unique(['name'], 'uniq_monitor_rule_name');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
        });

        $schema->create('monitor_states', static function ($t) {
            $t->char('rule_id', 32);
            $t->string('status', 8)->default('ok')->comment('ok | firing');
            $t->unsignedSmallInteger('breach_streak')->default(0);
            $t->unsignedSmallInteger('clear_streak')->default(0);
            $t->double('last_value')->nullable();
            $t->unsignedBigInteger('last_evaluated_at')->default(0);
            $t->unsignedBigInteger('changed_at')->default(0);

            // Optimistic-lock token. Two evaluators that both decide "fire" write
            // with the version they read; only one still matches.
            $t->unsignedInteger('version')->default(1);

            $t->primary(['rule_id'], 'pk_monitor_state');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
        });

        $schema->create('monitor_alerts', static function ($t) {
            $t->char('id', 32);
            $t->char('rule_id', 32);

            // Snapshots, not joins: the rule may be edited or deleted while this
            // alert waits for a retry, and the page must go where it was meant to.
            $t->string('rule_name', 100);
            $t->string('metric', 128);
            $t->string('severity', 10);
            $t->string('kind', 8)->comment('fired | resolved');
            $t->double('value');
            $t->double('threshold');
            $t->unsignedBigInteger('occurred_at');
            $t->string('tenant', 64)->nullable();
            $t->text('mail_to');
            $t->boolean('webhook');
            $t->boolean('console');

            // Channels already delivered (JSON list): a retry skips them.
            $t->text('delivered');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedBigInteger('claimed_at')->nullable();
            $t->unsignedBigInteger('notified_at')->nullable();
            $t->boolean('abandoned')->default(false);

            $t->primary(['id'], 'pk_monitor_alert');
            $t->index(['notified_at', 'abandoned', 'occurred_at'], 'idx_monitor_alert_pending');
            $t->index(['rule_id', 'occurred_at'], 'idx_monitor_alert_rule');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('monitor_alerts');
        $schema->dropIfExists('monitor_states');
        $schema->dropIfExists('monitor_rules');
    }
};
