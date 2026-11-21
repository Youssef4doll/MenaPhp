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

    // Keep the database connection open between visitors (faster when the
    // database is far away). Set to false only if the host has trouble with it.
    'persistent' => true,

    // The back office's AI (translation, product pages written from a supplier's page, the
    // search for a product's pictures on the web, picture ideas): the same key as
    // ANTHROPIC_API_KEY on Netlify/Vercel, from console.anthropic.com → API keys. Empty: the
    // back office works without it and offers the free way (copy the request into claude.ai,
    // paste the answer back). Type it here, on the host; never send it in a chat.
    'anthropic_api_key' => '',

    // 'production' on the real shop: errors are logged, never shown.
    'env' => 'production',
];
