<?php
declare(strict_types=1);

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Lib\Streaming\MessageAccumulator;

/**
 * Claude, for the back office: the same requests as the Next.js shop's
 * lib/translate.ts, supplier-ai.ts, web-complete-ai.ts, image-ideas-ai.ts and
 * category-ai.ts, sent with Anthropic's PHP SDK (vendor/). The key
 * (anthropic_api_key in config.php) never leaves the server; the back office
 * receives only the texts, to read before saving.
 */
final class Ai
{
    public const NOT_READY_TRANSLATE = "Traduction automatique non configurée : ajoutez la clé anthropic_api_key dans config.php, sur l'hébergement, puis rechargez la page.";
    public const KEY_REFUSED = "La clé anthropic_api_key est refusée : vérifiez-la dans config.php.";
    /** A declined request is re-run on Anthropic's recommended model instead of failing. */
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private static ?Client $client = null;

    public static function key(): string
    {
        return trim((string) (config('anthropic_api_key') ?: (getenv('ANTHROPIC_API_KEY') ?: '')));
    }

    /** translationReady(): a key is there (and the SDK, kept in vendor/). */
    public static function ready(): bool
    {
        return self::key() !== '' && is_file(ROOT . '/vendor/autoload.php');
    }

    public static function model(): string
    {
        return (string) (config('anthropic_model') ?: (getenv('ANTHROPIC_MODEL') ?: 'claude-opus-5-5'));
    }

    /** For the tests: the HTTP transport the SDK uses (a fake one, never the real API). */
    public static ?object $transport = null;

    public static function client(): Client
    {
        if (self::$client) {
            return self::$client;
        }
        require_once ROOT . '/vendor/autoload.php';
        $options = ['timeout' => 600];
        if (self::$transport) {
            $options += ['transporter' => self::$transport, 'streamingTransporter' => self::$transport, 'maxRetries' => 0];
        }
        return self::$client = new Client(apiKey: self::key(), requestOptions: $options);
    }

    public static function reset(): void
    {
        self::$client = null;
    }

    /** The message of a failed request, as the Next.js actions word it. */
    public static function failure(Throwable $e, string $rateLimited, string $down, string $other): string
    {
        if ($e instanceof AuthenticationException) {
            return self::KEY_REFUSED;
        }
        if ($e instanceof RateLimitException) {
            return $rateLimited;
        }
        if ($e instanceof APIException) {
            return $down;
        }
        return $e instanceof AiError ? $e->getMessage() : ($e->getMessage() !== '' ? $e->getMessage() : $other);
    }

    /**
     * One request whose answer is a JSON object of `schema` (structured output), streamed or not.
     * @return array{stop: ?string, text: ?string}
     */
    public static function json(string $system, string $task, array $schema, int $maxTokens, string $effort, bool $stream): array
    {
        $params = [
            'maxTokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $task]],
            'model' => self::model(),
            'betas' => [self::FALLBACK_BETA],
            'fallbacks' => 'default',
            'outputConfig' => ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            'system' => $system,
        ];
        $m = $stream ? self::streamed($params) : self::client()->beta->messages->create(...$params);
        return ['stop' => self::stop($m), 'text' => self::firstText($m)];
    }

    /** A streamed request, read to its end (the SDK's accumulator: TypeScript's finalMessage()). */
    public static function streamed(array $params): BetaMessage
    {
        $acc = MessageAccumulator::forBetaMessages();
        foreach (self::client()->beta->messages->createStream(...$params) as $event) {
            $acc->accumulate($event);
        }
        $m = $acc->message();
        assert($m instanceof BetaMessage);
        return $m;
    }

    public static function stop(BetaMessage $m): ?string
    {
        $s = $m->stopReason ?? null;
        return $s instanceof BackedEnum ? (string) $s->value : ($s === null ? null : (string) $s);
    }

    public static function firstText(BetaMessage $m): ?string
    {
        foreach ($m->content as $b) {
            if (($b->type ?? null) === 'text') {
                return (string) $b->text;
            }
        }
        return null;
    }

    /** JSON.parse: an answer that does not read raises, as in the Next.js code (the action says "Réponse illisible"). */
    public static function parse(string $text): mixed
    {
        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiError('Réponse illisible.');
        }
    }
}

/** A failure the owner reads as it is ("Réponse vide.", "Textes trop longs…"). */
final class AiError extends RuntimeException
{
}

/** The requests' words, copied from the Next.js shop by tools/ai-sync.mts. */
final class AiPrompts
{
    private static ?array $all = null;
    /** The stand-in values tools/ai-sync.mts wrote in place of the product's own. */
    public const T = "\u{0001}";

    public static function get(string $group, string $key): mixed
    {
        self::$all ??= json_decode((string) file_get_contents(__DIR__ . '/ai-prompts.json'), true);
        return self::$all[$group][$key];
    }
}
