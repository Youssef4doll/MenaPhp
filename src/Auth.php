<?php
declare(strict_types=1);

/**
 * Back-office accounts: "AdminUser" / "AdminSession", shared with the
 * Next.js shop. The cookie holds a random token; the database holds its
 * keyed hash (same APP_SECRET, same cookie name), so a session opened in one
 * version is valid in the other. Passwords are bcrypt, as bcryptjs writes them.
 */
final class Auth
{
    public const COOKIE = 'mena_admin';
    private const DAYS = 14;
    private static ?array $user = null;

    public static function current(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || !Tokens::looksLikeToken($token) || !Tokens::hasSecret()) {
            return null;
        }
        $row = Db::one('SELECT u.id, u.email, u.name, s."expiresAt" FROM "AdminSession" s JOIN "AdminUser" u ON u.id = s."userId" WHERE s."tokenHash" = ?', [Tokens::hash($token)]);
        if (!$row || (string) $row['expiresAt'] < Db::now()) {
            return null;
        }
        return self::$user = $row;
    }

    /** Every admin page starts here. */
    public static function require(): array
    {
        $u = self::current();
        if (!$u) {
            redirect('/admin/connexion');
        }
        return $u;
    }

    public static function verify(string $email, string $password): ?array
    {
        $u = Db::one('SELECT id, email, name, "passwordHash" FROM "AdminUser" WHERE email = ?', [mb_strtolower(trim($email))]);
        // Compared against a dummy hash when there is no user, so timing does not tell which e-mails exist.
        $hash = $u['passwordHash'] ?? '$2y$10$kZdL5pnY4ffR7djB9pRPKe7kkSoPmnFKdt43mnN/T9up4OJeVQdmG';
        $ok = password_verify(trim($password), $hash);
        return $ok && $u ? $u : null;
    }

    public static function login(string $userId): void
    {
        $token = Tokens::random(32);
        Db::insert('AdminSession', ['id' => new_id(), 'userId' => $userId, 'tokenHash' => Tokens::hash($token), 'expiresAt' => Db::now(self::DAYS * 86400), 'createdAt' => Db::now()]);
        setcookie(self::COOKIE, $token, ['expires' => time() + self::DAYS * 86400, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    public static function logout(): void
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($token) && $token !== '' && Tokens::hasSecret()) {
            Db::run('DELETE FROM "AdminSession" WHERE "tokenHash" = ?', [Tokens::hash($token)]);
        }
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    /** A bcrypt hash both versions read: PHP writes $2y$, bcryptjs reads $2b$ (the same algorithm). */
    public static function hashPassword(string $password): string
    {
        return '$2b$' . substr(password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]), 4);
    }
}
