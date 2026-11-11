<?php

declare(strict_types=1);

return [
    'subject.fired'    => '[:severity] FIRING: :rule',
    'subject.resolved' => '[:severity] RESOLVED: :rule',
    'body.fired'       => "Rule :rule is firing.\n\nMetric:    :metric\nValue:     :value\nThreshold: :threshold\nSeverity:  :severity\nTenant:    :tenant\nAt:        :at (UTC)\n",
    'body.resolved'    => "Rule :rule has resolved.\n\nMetric:    :metric\nValue:     :value\nThreshold: :threshold\nSeverity:  :severity\nTenant:    :tenant\nAt:        :at (UTC)\n",
    'tenant.all'       => 'all tenants',
    'console.fired'    => 'FIRING :rule — :metric is :value (threshold :threshold, :severity)',
    'console.resolved' => 'RESOLVED :rule — :metric is :value (resolve threshold :threshold)',
];
