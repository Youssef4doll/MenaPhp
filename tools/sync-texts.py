#!/usr/bin/env python3
"""
Makes lang/fr.php and lang/ar.php from the Next.js shop's own texts
(src/i18n/fr.ts, ar.ts), so both shops say exactly the same words.

  npx tsx dump-dict.mts > dict.json   (in the Mena repository: see tools/README)
  python3 tools/sync-texts.py dict.json

Plain texts are copied as they are; the texts with values (arrow functions)
are translated to PHP closures by the small parser below.
tests/run.php then calls every one of them against the Next.js answers.
"""
import json, re, sys

def decode_js(s):
    return re.sub(r'\\x([0-9A-Fa-f]{2})|\\u\{([0-9A-Fa-f]+)\}|\\u([0-9A-Fa-f]{4})|\\(.)',
                  lambda m: chr(int(m.group(1), 16)) if m.group(1) else chr(int(m.group(2), 16)) if m.group(2) else chr(int(m.group(3), 16)) if m.group(3) else {'n': '\n', 't': '\t', '0': '\0'}.get(m.group(4), m.group(4)), s)

def php_str(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"

class Parser:
    def __init__(self, src): self.s = src; self.i = 0
    def ws(self):
        while self.i < len(self.s) and self.s[self.i].isspace(): self.i += 1
    def peek(self, t): self.ws(); return self.s.startswith(t, self.i)
    def eat(self, t):
        self.ws()
        if not self.s.startswith(t, self.i): raise SyntaxError(f'expected {t!r} at {self.s[self.i:self.i+30]!r}')
        self.i += len(t)
    def expr(self):
        cond = self.binary(0)
        if self.peek('?'):
            self.eat('?'); a = self.expr(); self.eat(':'); b = self.expr()
            return f'({cond} ? {a} : {b})'
        return cond
    OPS = [['||'], ['&&'], ['===', '!==', '==', '!='], ['<=', '>=', '<', '>'], ['+', '-'], ['*', '/', '%']]
    def binary(self, level):
        if level == len(self.OPS): return self.unary()
        left = self.binary(level + 1)
        while True:
            self.ws()
            op = next((o for o in self.OPS[level] if self.s.startswith(o, self.i) and not self.s.startswith('=>', self.i)), None)
            if not op: return left
            self.i += len(op)
            right = self.binary(level + 1)
            php_op = {'==': '==', '!=': '!='}.get(op, op)
            if op == '+':
                # JS "+" on text is PHP ".": text when either side is a string literal or a template.
                if left.startswith(("'", '(\'')) or right.startswith(("'", "('")): php_op = '.'
            left = f'({left} {php_op} {right})'
    def unary(self):
        if self.peek('!'): self.eat('!'); return f'!{self.unary()}'
        if self.peek('-') and not self.peek('->'): self.eat('-'); return f'-{self.unary()}'
        return self.primary()
    def primary(self):
        self.ws(); c = self.s[self.i]
        if c == '(':
            self.eat('('); e = self.expr(); self.eat(')'); return f'({e})'
        if c == '`': return self.template()
        if c in '"\'':
            q = c; j = self.i + 1; buf = ''
            while self.s[j] != q:
                if self.s[j] == '\\': buf += self.s[j:j+2]; j += 2
                else: buf += self.s[j]; j += 1
            self.i = j + 1
            return php_str(decode_js(buf))
        m = re.match(r'\d+(\.\d+)?', self.s[self.i:])
        if m: self.i += len(m.group(0)); return m.group(0)
        m = re.match(r'[A-Za-z_$][A-Za-z0-9_$]*', self.s[self.i:])
        if m:
            name = m.group(0); self.i += len(name)
            if name in ('true', 'false', 'null'): return name
            if name == 'undefined': return 'null'
            return '$' + name
        raise SyntaxError(f'unexpected {self.s[self.i:self.i+30]!r}')
    def template(self):
        self.eat('`'); parts = []; buf = ''
        while True:
            c = self.s[self.i]
            if c == '`': self.i += 1; break
            if c == '\\': buf += self.s[self.i:self.i+2]; self.i += 2; continue
            if self.s.startswith('${', self.i):
                if buf: parts.append(php_str(decode_js(buf))); buf = ''
                self.i += 2; e = self.expr(); self.eat('}'); parts.append(e); continue
            buf += c; self.i += 1
        if buf: parts.append(php_str(decode_js(buf)))
        return '(' + ' . '.join(parts) + ')' if len(parts) > 1 else (parts[0] if parts else "''")

def fn_to_php(src):
    m = re.match(r'\s*(?:\(([^)]*)\)|([A-Za-z_$][\w$]*))\s*=>\s*(.*)$', src, re.S)
    if not m: raise SyntaxError('not an arrow function: ' + src[:60])
    params = m.group(1) if m.group(1) is not None else m.group(2)
    php_params = []
    for p in [x.strip() for x in params.split(',') if x.strip()]:
        if '=' in p:
            n, d = [x.strip() for x in p.split('=', 1)]
            php_params.append(f'${n} = {Parser(d).expr()}')
        else:
            php_params.append('$' + p)
    p = Parser(m.group(3)); body = p.expr(); p.ws()
    if p.i != len(p.s): raise SyntaxError('left over: ' + p.s[p.i:p.i+40])
    return f"fn ({', '.join(php_params)}) => {body}"

def emit(o, indent):
    pad = '    ' * indent
    if isinstance(o, dict) and '__fn' in o: return fn_to_php(o['__fn'])
    if isinstance(o, dict):
        if not o: return '[]'
        return '[\n' + ''.join(f"{pad}    {php_str(k)} => {emit(v, indent + 1)},\n" for k, v in o.items()) + pad + ']'
    if isinstance(o, list):
        return '[' + ', '.join(emit(v, indent + 1) for v in o) + ']'
    if isinstance(o, bool): return 'true' if o else 'false'
    if o is None: return 'null'
    if isinstance(o, (int, float)): return str(o)
    return php_str(o)

if __name__ == '__main__':
    d = json.load(open(sys.argv[1]))
    root = sys.argv[2] if len(sys.argv) > 2 else '.'
    for lang in ('fr', 'ar'):
        body = emit(d[lang], 0)
        open(f'{root}/lang/{lang}.php', 'w').write(
            "<?php\n// Made by tools/sync-texts.py from the Next.js shop's src/i18n/" + lang + ".ts: do not edit here,\n"
            "// edit there and run the tool again. This version's own words are in lang/extra.php.\nreturn " + body + ";\n")
        print(lang, 'written')
