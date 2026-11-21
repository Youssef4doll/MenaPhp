<?php
declare(strict_types=1);

use Anthropic\Beta\Messages\BetaTool;
use Anthropic\Beta\Messages\BetaWebFetchTool20260209;
use Anthropic\Beta\Messages\BetaWebSearchTool20260209;

/**
 * One product looked up on the web by Claude: it searches, reads the pages of
 * this exact product, and answers with its "report" tool (the pages, a video,
 * texts written from those pages). lib/web-complete-ai.ts and
 * web-complete-prompt.ts. Nothing is saved here.
 */
final class WebComplete
{
    public const TEXT_KEYS = ['summary', 'summaryAr', 'description', 'descriptionAr', 'specs', 'specsAr', 'keywords', 'seoTitle', 'seoTitleAr', 'seoDescription', 'seoDescriptionAr'];
    private const LIMITS = ['summary' => 300, 'summaryAr' => 300, 'description' => 8000, 'descriptionAr' => 8000, 'specs' => 4000, 'specsAr' => 4000, 'keywords' => 1000, 'seoTitle' => 120, 'seoTitleAr' => 120, 'seoDescription' => 320, 'seoDescriptionAr' => 320];
    private const ONE_LINE = ['summary', 'summaryAr', 'seoTitle', 'seoTitleAr', 'seoDescription', 'seoDescriptionAr', 'keywords'];
    /** A paused search (the server's own loop of searches ran long) is taken up again this many times at most. */
    private const MAX_CONTINUATIONS = 4;

    /** WebTexts (partial when $partial). */
    public static function texts(bool $partial): Z
    {
        $shape = [];
        foreach (self::TEXT_KEYS as $k) {
            $shape[$k] = $partial ? Z::str()->optional() : Z::str();
        }
        return Z::obj($shape);
    }

    /** WebReport */
    public static function report(): Z
    {
        return Z::obj([
            'pages' => Z::arr(Z::obj(['url' => Z::str(), 'title' => Z::str()->default(''), 'sure' => Z::bool()->default(false)]))->default([]),
            'images' => Z::arr(Z::str())->default([]),
            'video' => Z::str()->default(''),
            'texts' => self::texts(true)->default([]),
        ]);
    }

    /** cleanTexts(): trimmed, one line where the editor has one line, within its lengths; empty texts dropped. */
    public static function cleanTexts(array $t): array
    {
        $out = [];
        foreach (self::TEXT_KEYS as $k) {
            $v = Text::trim((string) preg_replace('/\r\n?/', "\n", (string) ($t[$k] ?? '')));
            if (in_array($k, self::ONE_LINE, true)) {
                $v = (string) preg_replace('/' . Text::WS . '*\n+' . Text::WS . '*/u', ' ', $v);
            }
            $v = Text::trim(Text::slice($v, 0, self::LIMITS[$k]));
            if ($v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** webTask(): the task for one product, as the shop has it now. */
    public static function task(array $p): string
    {
        $block = Text::json(['ref' => $p['ref'], 'name' => $p['name'], 'nameAr' => $p['nameAr'], 'summary' => $p['summary'], 'description' => Text::slice($p['description'], 0, 1500), 'specs' => Text::slice($p['specs'], 0, 800)]);
        return str_replace(AiPrompts::get('web', 'product'), $block, AiPrompts::get('web', 'task'));
    }

    /** researchProduct() */
    public static function research(array $product): array
    {
        $tools = [
            BetaWebSearchTool20260209::with(maxUses: 6),
            BetaWebFetchTool20260209::with(maxUses: 5, maxContentTokens: 20000),
            BetaTool::with(
                inputSchema: AiPrompts::get('web', 'schema'),
                name: 'report',
                description: 'Your answer for this product, given once at the end: the pages of this exact product, large photo addresses seen in them, one video, and the texts written from those pages.',
                eagerInputStreaming: true,
            ),
        ];
        $first = ['role' => 'user', 'content' => self::task($product)];
        $messages = [$first];
        // What the assistant already did before a pause: each answer goes on from it.
        $done = [];
        for ($turn = 0; $turn <= self::MAX_CONTINUATIONS; $turn++) {
            // Streamed: searching and reading several pages takes a while, and a stream is not cut by a timeout.
            $m = Ai::streamed([
                'maxTokens' => 16000,
                'messages' => $messages,
                'model' => Ai::model(),
                'betas' => ['server-side-fallback-2026-07-01'],
                'fallbacks' => 'default',
                'outputConfig' => ['effort' => 'medium'],
                'system' => AiPrompts::get('web', 'system'),
                'tools' => $tools,
            ]);
            $stop = Ai::stop($m);
            if ($stop === 'refusal') {
                throw new AiError("L'IA a refusé de chercher ce produit.");
            }
            $call = null;
            foreach ($m->content as $b) {
                if (($b->type ?? null) === 'tool_use' && ($b->name ?? null) === 'report') {
                    $call = $b;
                    break;
                }
            }
            if ($stop === 'max_tokens') {
                throw new AiError('Réponse trop longue : réessayez ce produit.');
            }
            if ($call) {
                // Streamed inputs are not checked by the API: the answer is read against its form here.
                $input = json_decode((string) json_encode($call->input), true);
                $parsed = self::report()->safeParse($input);
                if (!$parsed['ok']) {
                    throw new AiError('Réponse illisible : réessayez ce produit.');
                }
                return $parsed['data'];
            }
            if ($stop !== 'pause_turn') {
                break;
            }
            // Taken up where it stopped: the paused turn is sent back as it is, with nothing added.
            $done = [...$done, ...$m->content];
            $messages = [$first, ['role' => 'assistant', 'content' => $done]];
        }
        throw new AiError('Rien de sûr trouvé pour ce produit.');
    }
}
