<?php
declare(strict_types=1);

/**
 * The shop's icons, the same drawings as the Next.js shop (src/components/shop/Icons.tsx),
 * made from that file by tools/sync-icons. icon('Search', 'h-5 w-5').
 */
final class Icons
{
    public const SVG = [
        'Bag' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="%CLASS%" aria-hidden="true"><path d="M5 8h14l-1 12H6L5 8Z" stroke-linejoin="round"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>'],
        'Search' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="%CLASS%" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2" stroke-linecap="round"/></svg>'],
        'Menu' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="%CLASS%" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10" stroke-linecap="round"/></svg>'],
        'Close' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="%CLASS%" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" stroke-linecap="round"/></svg>'],
        'Truck' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="%CLASS%" aria-hidden="true"><path d="M3 6h11v10H3zM14 9h4l3 3v4h-7" stroke-linejoin="round"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/></svg>'],
        'Cash' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="%CLASS%" aria-hidden="true"><rect x="3" y="6.5" width="18" height="11" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9.5v5M18 9.5v5" stroke-linecap="round"/></svg>'],
        'Return' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="%CLASS%" aria-hidden="true"><path d="M9 8H4V3" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.5 8A8 8 0 1 1 4 13" stroke-linecap="round"/></svg>'],
        'Chat' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="%CLASS%" aria-hidden="true"><path d="M20 12a8 8 0 0 1-11.6 7.1L4 20l1-4.2A8 8 0 1 1 20 12Z" stroke-linejoin="round"/></svg>'],
        'WhatsApp' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="currentColor" class="%CLASS%" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.1.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.2-.2-.4-.3Z"/></svg>'],
        'Messenger' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="currentColor" class="%CLASS%" aria-hidden="true"><path d="M12 2C6.4 2 2 6.1 2 11.7c0 2.9 1.2 5.5 3.2 7.3v3.5l3-1.7c1.2.4 2.5.6 3.8.6 5.6 0 10-4.1 10-9.7S17.6 2 12 2Zm1 13-2.5-2.7L5.6 15l5.4-5.7 2.6 2.7 4.8-2.7L13 15Z"/></svg>'],
        'Chevron' => ['h-4 w-4', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="%CLASS% rtl:-scale-x-100" aria-hidden="true"><path d="m9 6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>'],
        'ChevronDown' => ['h-4 w-4', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="%CLASS%" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>'],
        'ArrowDown' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="%CLASS%" aria-hidden="true"><path d="M12 5v14M6.5 13.5 12 19l5.5-5.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'],
        'Phone' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="%CLASS%" aria-hidden="true"><path d="M5 4h3.5l1.7 4.3-2.2 1.4a11 11 0 0 0 6.3 6.3l1.4-2.2L20 15.5V19a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1Z" stroke-linejoin="round"/></svg>'],
        'Star' => ['h-4 w-4', '<svg viewBox="0 0 20 20" fill="currentColor" class="%CLASS%" aria-hidden="true"><path d="M10 1.5l2.6 5.6 6 .7-4.5 4.1 1.2 6-5.3-3-5.3 3 1.2-6L1.4 7.8l6-.7L10 1.5z"/></svg>'],
        'User' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="%CLASS%" aria-hidden="true"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c.8-3.6 3.6-5.6 7-5.6s6.2 2 7 5.6" stroke-linecap="round"/></svg>'],
        'Home' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="%CLASS%" aria-hidden="true"><path d="M4 10.5 12 4l8 6.5V20h-5.5v-5.5h-5V20H4v-9.5Z" stroke-linejoin="round"/></svg>'],
        'Check' => ['h-4 w-4', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="%CLASS%" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'],
        'Minus' => ['h-4 w-4', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="%CLASS%" aria-hidden="true"><path d="M6 12h12" stroke-linecap="round"/></svg>'],
        'Plus' => ['h-4 w-4', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="%CLASS%" aria-hidden="true"><path d="M12 6v12M6 12h12" stroke-linecap="round"/></svg>'],
        'Pin' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="%CLASS%" aria-hidden="true"><path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.5"/></svg>'],
        'Shield' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="%CLASS%" aria-hidden="true"><path d="M12 3 5 6v5.5c0 4.4 3 8.2 7 9.5 4-1.3 7-5.1 7-9.5V6l-7-3Z" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/></svg>'],
        'Cart' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="%CLASS%" aria-hidden="true"><path d="M2.5 4h2.6l2.3 10.6a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.1L21 8H6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="19.5" r="1.5"/><circle cx="17" cy="19.5" r="1.5"/></svg>'],
        'CartPlus' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="%CLASS%" aria-hidden="true"><path d="M2.5 4h2.6l2.3 10.6a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.1L21 8H6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="19.5" r="1.5"/><circle cx="17" cy="19.5" r="1.5"/><path d="M13.3 8.6v4.6M11 10.9h4.6" stroke-linecap="round"/></svg>'],
        'Play' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="currentColor" class="%CLASS%" aria-hidden="true"><path d="M8 5.5v13a1 1 0 0 0 1.5.9l10.4-6.5a1 1 0 0 0 0-1.8L9.5 4.6A1 1 0 0 0 8 5.5Z"/></svg>'],
        'Expand' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="%CLASS%" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m20 20-4.6-4.6M10.5 7.5v6M7.5 10.5h6" stroke-linecap="round"/></svg>'],
        'Gift' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="%CLASS%" aria-hidden="true"><rect x="3.5" y="8" width="17" height="4" rx="1"/><path d="M5 12v8h14v-8M12 8v12"/><path d="M12 8c-1.5-3.5-5.5-4-5.5-1.5S10 8 12 8Zm0 0c1.5-3.5 5.5-4 5.5-1.5S14 8 12 8Z" stroke-linejoin="round"/></svg>'],
        'Fire' => ['h-5 w-5', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="%CLASS%" aria-hidden="true"><path d="M12 21c-3.9 0-7-2.7-7-6.6 0-3.4 2.4-5.4 3.6-8.4.3 2 1.3 3 2.4 3.6C11 6.4 12.8 4 15 3c-.5 2.6.6 4.4 2 6.2 1.2 1.6 2 3.2 2 5.2 0 3.9-3.1 6.6-7 6.6Z" stroke-linejoin="round"/></svg>'],
    ];

    public static function get(string $name, ?string $class = null): string
    {
        [$default, $svg] = self::SVG[$name] ?? ['h-5 w-5', ''];
        return str_replace('%CLASS%', htmlspecialchars($class ?? $default, ENT_QUOTES), $svg);
    }
}

function icon(string $name, ?string $class = null): string
{
    return Icons::get($name, $class);
}
