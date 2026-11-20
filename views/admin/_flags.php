<?php
/** The checkout flags of an order as small pills. @var array $flags */
foreach ($flags as $f) {
    [$k, $n] = array_pad(explode(':', (string) $f, 2), 2, null);
    $label = (Orders::FLAG_LABELS[$k] ?? $k) . ($n !== null ? " : $n" : '');
    $tone = in_array($k, ['blacklisted', 'serial_refuser'], true) ? 'danger' : (in_array($k, ['duplicate', 'too_many_today', 'past_refusals'], true) ? 'warn' : ($k === 'past_delivered' ? 'ok' : ''));
    echo '<span class="pill ' . $tone . '">' . e($label) . '</span> ';
}
