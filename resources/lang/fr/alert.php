<?php

declare(strict_types=1);

return [
    'subject.fired'    => '[:severity] EN ALERTE : :rule',
    'subject.resolved' => '[:severity] RÉSOLU : :rule',
    'body.fired'       => "La règle :rule est en alerte.\n\nMétrique :  :metric\nValeur :    :value\nSeuil :     :threshold\nSévérité :  :severity\nTenant :    :tenant\nÀ :         :at (UTC)\n",
    'body.resolved'    => "La règle :rule est résolue.\n\nMétrique :  :metric\nValeur :    :value\nSeuil :     :threshold\nSévérité :  :severity\nTenant :    :tenant\nÀ :         :at (UTC)\n",
    'tenant.all'       => 'tous les tenants',
    'console.fired'    => 'EN ALERTE :rule — :metric vaut :value (seuil :threshold, :severity)',
    'console.resolved' => 'RÉSOLU :rule — :metric vaut :value (seuil de résolution :threshold)',
];
