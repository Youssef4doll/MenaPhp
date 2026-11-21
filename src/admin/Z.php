<?php
declare(strict_types=1);

/** The first problem a schema found: where it is, as zod's issue path. */
final class ZIssue extends Exception
{
    /** @param list<string|int> $path */
    public function __construct(public readonly array $path, string $message = 'invalid')
    {
        parent::__construct($message);
    }
}

/**
 * The few zod schemas the Next.js back office checks its input with, written
 * the same way so the same values pass and fail, and a refusal names the same
 * field (zod's first issue). Values come from JSON: a missing key is
 * JavaScript's undefined, null is null.
 *
 *   $r = Z::obj(['name' => Z::str()->trim()->min(2)->max(140)])->safeParse($raw);
 *   // ['ok' => true, 'data' => …] or ['ok' => false, 'path' => ['name']]
 */
final class Z
{
    /** @var list<Closure(mixed, list<string|int>): mixed> */
    private array $checks = [];
    private bool $optional = false;
    private bool $nullable = false;
    private bool $hasDefault = false;
    private mixed $default = null;

    private function __construct(private readonly Closure $base)
    {
    }

    /** Marks "undefined" (a missing key) for the parent object. */
    public const MISSING = "\0missing";

    public static function str(): self
    {
        return new self(static function (mixed $v, array $path): string {
            if (!is_string($v)) {
                throw new ZIssue($path, 'string');
            }
            return $v;
        });
    }

    public static function int(): self
    {
        return new self(static function (mixed $v, array $path): int {
            if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) <= 9007199254740991) {
                return (int) $v;
            }
            if (!is_int($v)) {
                throw new ZIssue($path, 'int');
            }
            return $v;
        });
    }

    public static function bool(): self
    {
        return new self(static function (mixed $v, array $path): bool {
            if (!is_bool($v)) {
                throw new ZIssue($path, 'bool');
            }
            return $v;
        });
    }

    public static function lit(mixed $value): self
    {
        return new self(static function (mixed $v, array $path) use ($value): mixed {
            if ($v !== $value) {
                throw new ZIssue($path, 'literal');
            }
            return $v;
        });
    }

    /** @param list<string> $values */
    public static function enum(array $values): self
    {
        return new self(static function (mixed $v, array $path) use ($values): string {
            if (!is_string($v) || !in_array($v, $values, true)) {
                throw new ZIssue($path, 'enum');
            }
            return $v;
        });
    }

    /** z.url(): a string a URL parser reads. */
    public static function url(): self
    {
        return self::str()->check(static fn (string $v) => Text::isUrl($v));
    }

    public static function unknown(): self
    {
        return new self(static fn (mixed $v) => $v);
    }

    public static function arr(self $item): self
    {
        return new self(static function (mixed $v, array $path) use ($item): array {
            if (!is_array($v) || !array_is_list($v)) {
                throw new ZIssue($path, 'array');
            }
            $out = [];
            foreach ($v as $i => $x) {
                $out[] = $item->run($x, true, [...$path, $i]);
            }
            return $out;
        });
    }

    /**
     * z.object(shape): unknown keys are dropped (zod's default), or refused with strict().
     * @param array<string, self> $shape
     */
    public static function obj(array $shape, bool $strict = false): self
    {
        return new self(static function (mixed $v, array $path) use ($shape, $strict): array {
            if (!is_array($v) || ($v !== [] && array_is_list($v))) {
                throw new ZIssue($path, 'object');
            }
            $out = [];
            foreach ($shape as $k => $schema) {
                $present = array_key_exists($k, $v);
                $val = $schema->run($present ? $v[$k] : null, $present, [...$path, $k]);
                if ($val !== self::MISSING) {
                    $out[$k] = $val;
                }
            }
            if ($strict && array_diff(array_keys($v), array_keys($shape))) {
                throw new ZIssue($path, 'unrecognized_keys');
            }
            return $out;
        });
    }

    /** @param list<self> $options the first that reads the value wins */
    public static function union(array $options): self
    {
        return new self(static function (mixed $v, array $path) use ($options): mixed {
            foreach ($options as $o) {
                try {
                    return $o->run($v, true, $path);
                } catch (ZIssue) {
                }
            }
            throw new ZIssue($path, 'union');
        });
    }

    private function with(Closure $check): self
    {
        $c = clone $this;
        $c->checks[] = $check;
        return $c;
    }

    /** A check of the value as read: false is a refusal at this place. */
    public function check(Closure $ok): self
    {
        return $this->with(static function (mixed $v, array $path) use ($ok) {
            if (!$ok($v)) {
                throw new ZIssue($path, 'custom');
            }
            return $v;
        });
    }

    /** A change of the value once read (zod's transform). */
    public function transform(Closure $f): self
    {
        return $this->with(static fn (mixed $v) => $f($v));
    }

    public function trim(): self
    {
        return $this->transform(static fn (string $v) => Text::trim($v));
    }

    /** Strings: their length in UTF-16 units; numbers: their value; lists: their size. */
    public function min(int|float $n): self
    {
        return $this->check(static fn (mixed $v) => (is_string($v) ? Text::len($v) : (is_array($v) ? count($v) : $v)) >= $n);
    }

    public function max(int|float $n): self
    {
        return $this->check(static fn (mixed $v) => (is_string($v) ? Text::len($v) : (is_array($v) ? count($v) : $v)) <= $n);
    }

    public function regex(string $re): self
    {
        return $this->check(static fn (string $v) => (bool) preg_match($re, $v));
    }

    public function optional(): self
    {
        $c = clone $this;
        $c->optional = true;
        return $c;
    }

    public function nullable(): self
    {
        $c = clone $this;
        $c->nullable = true;
        return $c;
    }

    public function default(mixed $value): self
    {
        $c = clone $this;
        $c->hasDefault = true;
        $c->default = $value;
        return $c;
    }

    /** @param list<string|int> $path */
    public function run(mixed $v, bool $present, array $path): mixed
    {
        if (!$present) {
            if ($this->hasDefault) {
                $v = $this->default;
            } elseif ($this->optional) {
                return self::MISSING;
            } else {
                throw new ZIssue($path, 'required');
            }
        }
        if ($v === null && $this->nullable) {
            return null;
        }
        $v = ($this->base)($v, $path);
        foreach ($this->checks as $check) {
            $v = $check($v, $path);
        }
        return $v;
    }

    /** @return array{ok: true, data: mixed}|array{ok: false, path: list<string|int>} */
    public function safeParse(mixed $v): array
    {
        try {
            $out = $this->run($v, true, []);
            return ['ok' => true, 'data' => $out === self::MISSING ? null : $out];
        } catch (ZIssue $e) {
            return ['ok' => false, 'path' => $e->path];
        }
    }

    /** zod's .parse(): the value, or an exception (a server error for the caller, as in Next.js). */
    public function parse(mixed $v): mixed
    {
        $r = $this->safeParse($v);
        if (!$r['ok']) {
            throw new InvalidArgumentException('Invalid input at ' . implode('.', $r['path']));
        }
        return $r['data'];
    }
}
