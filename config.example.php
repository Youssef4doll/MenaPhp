<?php
/**
 * The shop's settings. Copy this file to config.php (next to it, NOT inside
 * public/) and fill it in. config.php is never committed: it holds the
 * database password and the shop's secret key. Never send it in a chat.
 */
return [
    // The same database the shop uses today (Neon): the value of DATABASE_URL,
    // the POOLED address (its host name has "-pooler" in it). Neon → your
    // project → Connect → "Connection pooling" on.
    'database_url' => 'postgresql://USER:PASSWORD@ep-xxxx-pooler.REGION.aws.neon.tech/neondb?sslmode=require',

    // The SAME value as APP_SECRET on Netlify/Vercel (32+ characters). With
    // the same key, order tracking links and back-office logins work in both
    // versions of the shop.
    'app_secret' => '',

    // The shop's address, without a slash at the end.
    'site_url' => 'https://menatn.com',

    // 'production' on the real shop: errors are logged, never shown.
    'env' => 'production',
];
