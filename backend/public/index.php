<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_name('nordlager_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function requestBody(): array
{
    if (($_SERVER['CONTENT_TYPE'] ?? '') !== 'application/json') {
        respond(['error' => 'Ungültiges Anfrageformat.'], 415);
    }

    $contents = file_get_contents('php://input');
    if ($contents === false || strlen($contents) > 16384) {
        respond(['error' => 'Die Anfrage ist ungültig oder zu groß.'], 413);
    }

    try {
        $body = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(['error' => 'Ungültige Anfrage.'], 400);
    }

    if (!is_array($body)) {
        respond(['error' => 'Ungültige Anfrage.'], 400);
    }

    return $body;
}

function verifyCsrfToken(): void
{
    $providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($providedToken) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $providedToken)) {
        respond(['error' => 'Sitzung abgelaufen. Bitte lade die Seite neu und versuche es erneut.'], 419);
    }
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function publicUser(PDO $database, int $id): ?array
{
    $statement = $database->prepare(
        'SELECT users.id, users.name, users.username, users.role_id, users.is_active, roles.name AS role_name
         FROM users JOIN roles ON roles.id = users.role_id
         WHERE users.id = :id'
    );
    $statement->execute(['id' => $id]);
    $user = $statement->fetch();

    if ($user === false || (int) $user['is_active'] !== 1) {
        return null;
    }

    $permissionsStatement = $database->prepare('SELECT permission FROM role_permissions WHERE role_id = :role_id ORDER BY permission');
    $permissionsStatement->execute(['role_id' => $user['role_id']]);

    return [
        'id' => (int) $user['id'],
        'name' => strcasecmp($user['username'], 'admin') === 0 ? 'Testkonto' : $user['name'],
        'roleId' => $user['role_id'],
        'roleName' => $user['role_name'],
        'permissions' => $permissionsStatement->fetchAll(PDO::FETCH_COLUMN),
        'isAdmin' => $user['role_id'] === 'admin',
        'canSell' => sellerAccessIsActive($database, (int) $user['id']),
    ];
}

function sellerAccessIsActive(PDO $database, int $userId): bool
{
    $statement = $database->prepare(
        'SELECT 1 FROM auction_profiles
         JOIN access_codes ON access_codes.id = auction_profiles.seller_code_id
         WHERE auction_profiles.user_id = :user_id
           AND access_codes.code_type = \'seller\' AND access_codes.revoked_at IS NULL'
    );
    $statement->execute(['user_id' => $userId]);
    return $statement->fetchColumn() !== false;
}

function requirePermission(?array $user, string ...$permissions): array
{
    if ($user === null) {
        respond(['error' => 'Bitte melde dich erneut an.'], 401);
    }

    foreach ($permissions as $permission) {
        if (in_array($permission, $user['permissions'], true)) {
            return $user;
        }
    }

    respond(['error' => 'Du hast keine Berechtigung für diese Aktion.'], 403);
}

function requireLogin(?array $user): array
{
    if ($user === null) {
        respond(['error' => 'Bitte melde dich erneut an.'], 401);
    }

    return $user;
}

function validUsername(mixed $username): bool
{
    return is_string($username) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,31}$/', $username) === 1;
}

function validNoticeText(mixed $text, int $maxBytes): bool
{
    if (!is_string($text)) {
        return false;
    }

    $text = trim($text);
    return $text !== ''
        && strlen($text) <= $maxBytes
        && preg_match('/\A.{1,1000}\z/us', $text) === 1;
}

function validNoticeSectionName(mixed $name): bool
{
    return is_string($name)
        && strlen(trim($name)) <= 240
        && preg_match('/\A.{1,60}\z/us', trim($name)) === 1;
}

function validRolePermissions(mixed $permissions): bool
{
    if (!is_array($permissions)) {
        return false;
    }

    foreach ($permissions as $permission) {
        if (!is_string($permission) || !in_array($permission, appPermissions(), true)) {
            return false;
        }
    }

    return count(array_unique($permissions)) === count($permissions);
}

function roleGrantsPermission(PDO $database, string $roleId, string $permission): bool
{
    $statement = $database->prepare('SELECT 1 FROM role_permissions WHERE role_id = :role_id AND permission = :permission');
    $statement->execute(['role_id' => $roleId, 'permission' => $permission]);
    return $statement->fetchColumn() !== false;
}

function decimalToScaledInteger(mixed $value, int $maxWholeDigits, int $fractionDigits): ?int
{
    if (is_string($value)) {
        $value = str_replace(',', '.', $value);
    }
    if (!is_string($value) || preg_match('/^(0|[1-9]\d{0,' . ($maxWholeDigits - 1) . '})(?:\.(\d{1,' . $fractionDigits . '}))?$/D', $value, $matches) !== 1) {
        return null;
    }

    $fraction = str_pad($matches[2] ?? '', $fractionDigits, '0');
    return ((int) $matches[1] * (10 ** $fractionDigits)) + (int) $fraction;
}

function formatScaledInteger(int $value, int $fractionDigits): string
{
    $factor = 10 ** $fractionDigits;
    $absoluteValue = abs($value);
    $sign = $value < 0 ? '-' : '';
    return $sign . intdiv($absoluteValue, $factor) . '.' . str_pad((string) ($absoluteValue % $factor), $fractionDigits, '0', STR_PAD_LEFT);
}

function validTransactionDate(mixed $value): bool
{
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $dateErrors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function writeAuditLog(PDO $database, array $actor, string $action, string $resource, int|string|null $resourceId = null): void
{
    $statement = $database->prepare(
        'INSERT INTO audit_logs (actor_id, actor_name, action, resource, resource_id)
         VALUES (:actor_id, :actor_name, :action, :resource, :resource_id)'
    );
    $statement->execute([
        'actor_id' => $actor['id'],
        'actor_name' => $actor['name'],
        'action' => $action,
        'resource' => $resource,
        'resource_id' => $resourceId === null ? null : (string) $resourceId,
    ]);
}

function expireAuctions(PDO $database): void
{
    $expired = $database->query(
        'SELECT id, seller_id, highest_bidder_id, title
         FROM auctions
         WHERE status = \'active\' AND is_approved = 1
           AND ends_at IS NOT NULL AND ends_at <= CURRENT_TIMESTAMP'
    )->fetchAll();
    if ($expired === []) {
        return;
    }

    $database->beginTransaction();
    try {
        $close = $database->prepare(
            'UPDATE auctions SET status = \'closed\'
             WHERE id = :id AND status = \'active\' AND ends_at <= CURRENT_TIMESTAMP'
        );
        $notifySeller = $database->prepare(
            'INSERT INTO user_notifications (user_id, auction_id, message)
             VALUES (:user_id, :auction_id, :message)'
        );
        foreach ($expired as $auction) {
            $close->execute(['id' => $auction['id']]);
            if ($close->rowCount() === 0) {
                continue;
            }
            $sellerMessage = $auction['highest_bidder_id'] === null
                ? 'Die Auktion „' . $auction['title'] . '“ ist beendet. Es gab keine Gebote.'
                : 'Die Auktion „' . $auction['title'] . '“ ist beendet. Öffne deine Angebote, um Gewinner und Endgebot zu sehen.';
            $notifySeller->execute([
                'user_id' => $auction['seller_id'],
                'auction_id' => $auction['id'],
                'message' => $sellerMessage,
            ]);
            writeAuditLog($database, ['id' => null, 'name' => 'System'], 'Beendet', 'Roleplay-Auktion', (int) $auction['id']);
        }
        $database->commit();
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $error;
    }
}

try {
    $database = appDatabase();
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $notificationUserId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $notificationUser = $notificationUserId === false || $notificationUserId === null
        ? null
        : publicUser($database, $notificationUserId);

    if ($path === '/api/notifications' && $method === 'GET') {
        $user = requireLogin($notificationUser);
        $statement = $database->prepare(
            'SELECT id, auction_id AS auctionId, message, is_read AS isRead, created_at AS createdAt
             FROM user_notifications WHERE user_id = :user_id
             ORDER BY id DESC LIMIT 100'
        );
        $statement->execute(['user_id' => $user['id']]);
        $notifications = $statement->fetchAll();
        $unreadCountStatement = $database->prepare(
            'SELECT COUNT(*) FROM user_notifications WHERE user_id = :user_id AND is_read = 0'
        );
        $unreadCountStatement->execute(['user_id' => $user['id']]);
        $unreadCount = (int) $unreadCountStatement->fetchColumn();
        foreach ($notifications as &$notification) {
            $notification['id'] = (int) $notification['id'];
            $notification['auctionId'] = $notification['auctionId'] === null ? null : (int) $notification['auctionId'];
            $notification['isRead'] = (bool) $notification['isRead'];
        }
        unset($notification);
        respond(['notifications' => $notifications, 'unreadCount' => $unreadCount]);
    }

    if (preg_match('#^/api/notifications/(\d+)/read$#D', (string) $path, $notificationMatch) === 1 && $method === 'POST') {
        $user = requireLogin($notificationUser);
        verifyCsrfToken();
        $markRead = $database->prepare(
            'UPDATE user_notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id'
        );
        $markRead->execute(['id' => (int) $notificationMatch[1], 'user_id' => $user['id']]);
        if ($markRead->rowCount() === 0) {
            $exists = $database->prepare(
                'SELECT 1 FROM user_notifications WHERE id = :id AND user_id = :user_id AND is_read = 1'
            );
            $exists->execute(['id' => (int) $notificationMatch[1], 'user_id' => $user['id']]);
            if ($exists->fetchColumn() === false) {
                respond(['error' => 'Die Nachricht wurde nicht gefunden.'], 404);
            }
        }
        respond(['message' => 'Nachricht als gelesen markiert.']);
    }

    if ($path === '/api/access-login' && $method === 'POST') {
        verifyCsrfToken();
        $body = requestBody();
        $code = is_string($body['code'] ?? null) ? strtoupper(trim($body['code'])) : '';
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if (preg_match('/\A(?:MIT|VER|NACHT)-[A-F0-9]{8}\z/D', $code) !== 1 || preg_match('/\A.{2,32}\z/us', $name) !== 1) {
            respond(['error' => 'Bitte prüfe deinen Zugangscode und Decknamen.'], 422);
        }

        $database->exec('BEGIN IMMEDIATE');
        try {
            $codeStatement = $database->prepare(
                'SELECT id, user_id, code_type FROM access_codes
                 WHERE code_hash = :code_hash AND revoked_at IS NULL'
            );
            $codeStatement->execute(['code_hash' => hash('sha256', $code)]);
            $codeRecord = $codeStatement->fetch();
            if ($codeRecord === false) {
                $database->rollBack();
                respond(['error' => 'Der Zugangscode ist ungültig oder wurde gesperrt.'], 401);
            }

            $userId = $codeRecord['user_id'] === null ? null : (int) $codeRecord['user_id'];
            if ($userId === null) {
                $username = 'guest_' . (int) $codeRecord['id'];
                $insertUser = $database->prepare(
                    'INSERT INTO users (name, username, password_hash, role_id)
                     VALUES (:name, :username, :password_hash, \'employee\')'
                );
                $insertUser->execute([
                    'name' => $name,
                    'username' => $username,
                    'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                ]);
                $userId = (int) $database->lastInsertId();
                $bindCode = $database->prepare('UPDATE access_codes SET user_id = :user_id WHERE id = :id');
                $bindCode->execute(['user_id' => $userId, 'id' => $codeRecord['id']]);
                $createProfile = $database->prepare(
                    'INSERT INTO auction_profiles (user_id, seller_code_id) VALUES (:user_id, :seller_code_id)'
                );
                $createProfile->execute([
                    'user_id' => $userId,
                    'seller_code_id' => $codeRecord['code_type'] === 'seller' ? $codeRecord['id'] : null,
                ]);
            } elseif ($codeRecord['code_type'] === 'seller') {
                if ($codeRecord['user_id'] !== null && (int) $codeRecord['user_id'] !== $userId) {
                    $database->rollBack();
                    respond(['error' => 'Dieser Verkäufer-Code wurde bereits einem anderen Konto zugeordnet.'], 401);
                }
                $bindSellerCode = $database->prepare('UPDATE access_codes SET user_id = :user_id WHERE id = :id');
                $bindSellerCode->execute(['user_id' => $userId, 'id' => $codeRecord['id']]);
                $saveProfile = $database->prepare(
                    'INSERT INTO auction_profiles (user_id, seller_code_id) VALUES (:user_id, :code_id)
                     ON CONFLICT(user_id) DO UPDATE SET seller_code_id = excluded.seller_code_id'
                );
                $saveProfile->execute(['user_id' => $userId, 'code_id' => $codeRecord['id']]);
            }
            $activeUser = $database->prepare('SELECT is_active FROM users WHERE id = :id');
            $activeUser->execute(['id' => $userId]);
            if ((int) $activeUser->fetchColumn() !== 1) {
                $database->rollBack();
                respond(['error' => 'Dieser Zugang wurde gesperrt.'], 401);
            }
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Could not rotate the session identifier.');
        }
        $_SESSION['user_id'] = $userId;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $loggedInUser = publicUser($database, $userId);
        if ($loggedInUser === null) {
            throw new RuntimeException('Authenticated account could not be loaded.');
        }
        writeAuditLog($database, $loggedInUser, 'Angemeldet', 'Roleplay-Netzwerk');
        respond(['user' => $loggedInUser, 'csrfToken' => csrfToken()]);
    }

    if ($path === '/api/session' && $method === 'GET') {
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        $user = $userId === false || $userId === null ? null : publicUser($database, $userId);
        if ($user === null) {
            unset($_SESSION['user_id']);
        }

        respond(['user' => $user, 'csrfToken' => csrfToken()]);
    }

    if ($path === '/api/login' && $method === 'POST') {
        verifyCsrfToken();
        $body = requestBody();
        $username = $body['username'] ?? null;
        $password = $body['password'] ?? null;

        if (!validUsername($username) || !is_string($password) || strlen($password) > 1024 || $password === '') {
            respond(['error' => 'Bitte prüfe deinen Benutzernamen und dein Passwort.'], 422);
        }

        $statement = $database->prepare('SELECT id, password_hash, role_id, is_active FROM users WHERE username = :username COLLATE NOCASE');
        $statement->execute(['username' => $username]);
        $userRecord = $statement->fetch();

        if ($userRecord === false || $userRecord['role_id'] !== 'admin' || (int) $userRecord['is_active'] !== 1 || !password_verify($password, $userRecord['password_hash'])) {
            respond(['error' => 'Benutzername oder Passwort ist nicht korrekt.'], 401);
        }

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Could not rotate the session identifier.');
        }

        $_SESSION['user_id'] = (int) $userRecord['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $loggedInUser = publicUser($database, (int) $userRecord['id']);
        if ($loggedInUser === null) {
            throw new RuntimeException('Authenticated account could not be loaded.');
        }
        writeAuditLog($database, $loggedInUser, 'Angemeldet', 'Konto');
        respond(['user' => $loggedInUser, 'csrfToken' => csrfToken()]);
    }

    if ($path === '/api/logout' && $method === 'POST') {
        verifyCsrfToken();
        $logoutUserId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($logoutUserId !== false && $logoutUserId !== null) {
            $logoutUserStatement = $database->prepare('SELECT id, name, username FROM users WHERE id = :id');
            $logoutUserStatement->execute(['id' => $logoutUserId]);
            $logoutUser = $logoutUserStatement->fetch();
            if ($logoutUser !== false) {
                writeAuditLog($database, [
                    'id' => (int) $logoutUser['id'],
                    'name' => strcasecmp($logoutUser['username'], 'admin') === 0 ? 'Testkonto' : $logoutUser['name'],
                ], 'Abgemeldet', 'Konto');
            }
        }
        $_SESSION = [];

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Could not rotate the session identifier.');
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond(['user' => null, 'csrfToken' => csrfToken()]);
    }

    $sessionUserId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $currentUser = $sessionUserId === false || $sessionUserId === null ? null : publicUser($database, $sessionUserId);

    if ($path === '/api/seller-access' && $method === 'POST') {
        $user = requireLogin($currentUser);
        verifyCsrfToken();
        $body = requestBody();
        $code = is_string($body['code'] ?? null) ? strtoupper(trim($body['code'])) : '';
        if (preg_match('/\A(?:VER|NACHT)-[A-F0-9]{8}\z/D', $code) !== 1) {
            respond(['error' => 'Bitte gib einen gültigen Verkäufer-Code ein.'], 422);
        }
        $database->exec('BEGIN IMMEDIATE');
        try {
            $codeStatement = $database->prepare(
                'SELECT id, user_id FROM access_codes
                 WHERE code_hash = :code_hash AND code_type = \'seller\' AND revoked_at IS NULL'
            );
            $codeStatement->execute(['code_hash' => hash('sha256', $code)]);
            $codeRecord = $codeStatement->fetch();
            if ($codeRecord === false || ($codeRecord['user_id'] !== null && (int) $codeRecord['user_id'] !== $user['id'])) {
                $database->rollBack();
                respond(['error' => 'Der Verkäufer-Code ist ungültig, gesperrt oder bereits verwendet.'], 401);
            }
            $bindCode = $database->prepare('UPDATE access_codes SET user_id = :user_id WHERE id = :id');
            $bindCode->execute(['user_id' => $user['id'], 'id' => $codeRecord['id']]);
            $saveProfile = $database->prepare(
                'INSERT INTO auction_profiles (user_id, seller_code_id) VALUES (:user_id, :code_id)
                 ON CONFLICT(user_id) DO UPDATE SET seller_code_id = excluded.seller_code_id'
            );
            $saveProfile->execute(['user_id' => $user['id'], 'code_id' => $codeRecord['id']]);
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
        $updatedUser = publicUser($database, $user['id']);
        respond(['user' => $updatedUser]);
    }

    if ($path === '/api/access-codes' && $method === 'GET') {
        if (!requireLogin($currentUser)['isAdmin']) {
            respond(['error' => 'Diese Aktion ist nur dem Admin erlaubt.'], 403);
        }
        $codes = $database->query(
            'SELECT access_codes.id, access_codes.code_type AS type, access_codes.group_name AS groupName,
                    access_codes.revoked_at AS revokedAt,
                    access_codes.created_at AS createdAt, users.name AS userName
             FROM access_codes LEFT JOIN users ON users.id = access_codes.user_id
             ORDER BY access_codes.group_name COLLATE NOCASE, access_codes.id DESC'
        )->fetchAll();
        foreach ($codes as &$code) {
            $code['id'] = (int) $code['id'];
            $code['revoked'] = $code['revokedAt'] !== null;
        }
        unset($code);
        respond(['codes' => $codes]);
    }

    if ($path === '/api/access-codes' && $method === 'POST') {
        if (!requireLogin($currentUser)['isAdmin']) {
            respond(['error' => 'Diese Aktion ist nur dem Admin erlaubt.'], 403);
        }
        verifyCsrfToken();
        $body = requestBody();
        $type = $body['type'] ?? null;
        $groupName = is_string($body['groupName'] ?? null) ? trim($body['groupName']) : '';
        if (!in_array($type, ['access', 'seller'], true)) {
            respond(['error' => 'Ungültiger Code-Typ.'], 422);
        }
        if ($groupName === '' || strlen($groupName) > 80) {
            respond(['error' => 'Bitte gib eine gültige Roleplay-Gruppe an (maximal 80 Zeichen).'], 422);
        }
        $prefix = $type === 'seller' ? 'VER-' : 'MIT-';
        $code = $prefix . strtoupper(bin2hex(random_bytes(4)));
        $createCode = $database->prepare(
            'INSERT INTO access_codes (code_hash, code_type, group_name, created_by)
             VALUES (:hash, :type, :group_name, :created_by)'
        );
        $createCode->execute([
            'hash' => hash('sha256', $code),
            'type' => $type,
            'group_name' => $groupName,
            'created_by' => $currentUser['id'],
        ]);
        writeAuditLog($database, $currentUser, 'Erstellt', 'Zugangscode', (int) $database->lastInsertId());
        respond(['code' => $code], 201);
    }

    if (preg_match('#^/api/access-codes/(\d+)$#D', (string) $path, $codeMatch) === 1 && $method === 'DELETE') {
        if (!requireLogin($currentUser)['isAdmin']) {
            respond(['error' => 'Diese Aktion ist nur dem Admin erlaubt.'], 403);
        }
        verifyCsrfToken();
        $codeId = (int) $codeMatch[1];
        $codeQuery = $database->prepare('SELECT code_type, user_id, revoked_at FROM access_codes WHERE id = :id');
        $codeQuery->execute(['id' => $codeId]);
        $codeRecord = $codeQuery->fetch();
        if ($codeRecord === false) {
            respond(['error' => 'Der Zugangscode wurde nicht gefunden.'], 404);
        }
        if ($codeRecord['revoked_at'] === null) {
            $revoke = $database->prepare('UPDATE access_codes SET revoked_at = CURRENT_TIMESTAMP WHERE id = :id');
            $revoke->execute(['id' => $codeId]);
            if ($codeRecord['code_type'] === 'access' && $codeRecord['user_id'] !== null) {
                $disableUser = $database->prepare('UPDATE users SET is_active = 0 WHERE id = :id');
                $disableUser->execute(['id' => $codeRecord['user_id']]);
            }
            writeAuditLog($database, $currentUser, 'Gesperrt', 'Zugangscode', $codeId);
        }
        respond(['message' => 'Der Code wurde gesperrt.']);
    }

    if ($path === '/api/admin/logs' && $method === 'GET') {
        $admin = requireLogin($currentUser);
        if (!$admin['isAdmin']) {
            respond(['error' => 'Diese Aktion ist nur dem Admin erlaubt.'], 403);
        }

        $beforeId = null;
        if (isset($_GET['before'])) {
            $beforeId = filter_var($_GET['before'], FILTER_VALIDATE_INT);
            if ($beforeId === false || $beforeId === null || $beforeId < 1) {
                respond(['error' => 'Ungültiger Protokoll-Cursor.'], 422);
            }
        }

        $query = 'SELECT audit_logs.id, audit_logs.actor_name AS actorName, audit_logs.action,
                         audit_logs.resource, audit_logs.resource_id AS resourceId,
                         audit_logs.created_at AS createdAt,
                         COALESCE(auctions.title, access_codes.group_name, \'\') AS detail
                  FROM audit_logs
                  LEFT JOIN auctions
                    ON audit_logs.resource = \'Roleplay-Auktion\'
                   AND CAST(audit_logs.resource_id AS INTEGER) = auctions.id
                  LEFT JOIN access_codes
                    ON audit_logs.resource = \'Zugangscode\'
                   AND CAST(audit_logs.resource_id AS INTEGER) = access_codes.id';
        if ($beforeId !== null) {
            $query .= ' WHERE audit_logs.id < :before_id';
        }
        $query .= ' ORDER BY audit_logs.id DESC LIMIT 101';
        $getLogs = $database->prepare($query);
        $getLogs->execute($beforeId === null ? [] : ['before_id' => $beforeId]);
        $logs = $getLogs->fetchAll();
        $hasMore = count($logs) > 100;
        if ($hasMore) {
            array_pop($logs);
        }
        foreach ($logs as &$log) {
            $log['id'] = (int) $log['id'];
        }
        unset($log);
        respond([
            'logs' => $logs,
            'hasMore' => $hasMore,
            'nextBefore' => $logs === [] ? null : $logs[array_key_last($logs)]['id'],
        ]);
    }

    if ($path === '/api/auctions' && $method === 'GET') {
        expireAuctions($database);
        $viewer = requireLogin($currentUser);
        $auctionQuery = 'SELECT auctions.id, auctions.seller_id AS sellerId, users.name AS sellerName,
                    auctions.highest_bidder_id AS highestBidderId, winning_users.name AS buyerName,
                    auctions.title, auctions.category, auctions.description,
                    auctions.starting_price_cents AS startingPriceCents,
                    auctions.current_bid_cents AS currentBidCents, auctions.status,
                    auctions.is_approved AS isApproved, auctions.ends_at AS endsAt,
                    auctions.created_at AS createdAt,
                    (SELECT COUNT(*) FROM auction_bids WHERE auction_id = auctions.id) AS bidCount
             FROM auctions JOIN users ON users.id = auctions.seller_id
             LEFT JOIN users AS winning_users ON winning_users.id = auctions.highest_bidder_id
             ';
        if (!$viewer['isAdmin']) {
            $auctionQuery .= 'WHERE auctions.is_approved = 1 ';
        }
        $auctionQuery .= 'ORDER BY CASE auctions.status WHEN \'active\' THEN 0 ELSE 1 END, auctions.id DESC';
        $auctions = $database->query($auctionQuery)->fetchAll();
        $auctionBids = [];
        if ($viewer['isAdmin']) {
            $bidQuery = $database->query(
                'SELECT auction_bids.auction_id AS auctionId, users.name AS bidderName,
                        auction_bids.amount_cents AS amountCents, auction_bids.created_at AS createdAt
                 FROM auction_bids
                 JOIN auctions ON auctions.id = auction_bids.auction_id
                 JOIN users ON users.id = auction_bids.bidder_id
                 WHERE auctions.status = \'active\' AND auctions.is_approved = 1
                 ORDER BY auction_bids.auction_id, auction_bids.created_at DESC, auction_bids.id DESC'
            );
            foreach ($bidQuery->fetchAll() as $bid) {
                $auctionId = (int) $bid['auctionId'];
                $auctionBids[$auctionId] ??= [];
                $auctionBids[$auctionId][] = [
                    'bidderName' => $bid['bidderName'],
                    'amount' => formatScaledInteger((int) $bid['amountCents'], 2),
                    'createdAt' => str_replace(' ', 'T', $bid['createdAt']) . 'Z',
                ];
            }
        }
        foreach ($auctions as &$auction) {
            $auction['id'] = (int) $auction['id'];
            $auction['sellerId'] = (int) $auction['sellerId'];
            $auction['startingPrice'] = formatScaledInteger((int) $auction['startingPriceCents'], 2);
            $auction['currentBid'] = formatScaledInteger((int) $auction['currentBidCents'], 2);
            $auction['bidCount'] = (int) $auction['bidCount'];
            $auction['isApproved'] = (bool) $auction['isApproved'];
            $auction['endsAt'] = $auction['endsAt'] === null
                ? null
                : str_replace(' ', 'T', $auction['endsAt']) . 'Z';
            if ($viewer['isAdmin'] && $auction['status'] === 'active' && $auction['isApproved']) {
                $auction['bids'] = $auctionBids[$auction['id']] ?? [];
            }
            if ($auction['status'] === 'closed' && ($viewer['isAdmin'] || $auction['sellerId'] === $viewer['id'])) {
                $auction['buyerName'] = $auction['highestBidderId'] === null ? null : $auction['buyerName'];
                $auction['soldAmount'] = $auction['highestBidderId'] === null ? null : $auction['currentBid'];
            } else {
                unset($auction['buyerName']);
            }
            unset($auction['highestBidderId']);
            unset($auction['startingPriceCents'], $auction['currentBidCents']);
        }
        unset($auction);
        respond(['auctions' => $auctions]);
    }

    if ($path === '/api/auctions' && $method === 'POST') {
        $seller = requireLogin($currentUser);
        if (!$seller['canSell']) {
            respond(['error' => 'Für das Einstellen von Angeboten wird ein aktiver Verkäufer-Code benötigt.'], 403);
        }
        verifyCsrfToken();
        $body = requestBody();
        $title = is_string($body['title'] ?? null) ? trim($body['title']) : '';
        $category = is_string($body['category'] ?? null) ? trim($body['category']) : '';
        $description = is_string($body['description'] ?? null) ? trim($body['description']) : '';
        $priceCents = decimalToScaledInteger($body['startingPrice'] ?? null, 8, 2);
        if ($title === '' || strlen($title) > 240 || !in_array($category, ['Waffen', 'Drogen'], true)
            || strlen($description) > 4000 || $priceCents === null || $priceCents < 1) {
            respond(['error' => 'Bitte prüfe Titel, Kategorie, Beschreibung und Startgebot.'], 422);
        }
        $createAuction = $database->prepare(
            'INSERT INTO auctions (seller_id, title, category, description, starting_price_cents, current_bid_cents, is_approved)
             VALUES (:seller_id, :title, :category, :description, :price, :price, 0)'
        );
        $createAuction->execute([
            'seller_id' => $seller['id'],
            'title' => $title,
            'category' => $category,
            'description' => $description,
            'price' => $priceCents,
        ]);
        $auctionId = (int) $database->lastInsertId();
        writeAuditLog($database, $seller, 'Eingereicht', 'Roleplay-Auktion', $auctionId);
        respond(['auctionId' => $auctionId, 'message' => 'Angebot wurde zur Freigabe eingereicht.'], 201);
    }

    if ($path === '/api/auctions/approve-all' && $method === 'POST') {
        $admin = requireLogin($currentUser);
        if (!$admin['isAdmin']) {
            respond(['error' => 'Nur der Admin darf Auktionen freigeben.'], 403);
        }
        verifyCsrfToken();
        $pending = $database->query(
            'SELECT id FROM auctions WHERE is_approved = 0 AND status = \'active\' ORDER BY id'
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($pending === []) {
            respond(['approvedCount' => 0, 'message' => 'Es warten keine Angebote auf Freigabe.']);
        }
        $database->beginTransaction();
        try {
            $approve = $database->prepare(
                'UPDATE auctions SET is_approved = 1 WHERE id = :id AND is_approved = 0 AND status = \'active\''
            );
            $approvedCount = 0;
            foreach ($pending as $auctionId) {
                $approve->execute(['id' => $auctionId]);
                if ($approve->rowCount() > 0) {
                    $approvedCount++;
                    writeAuditLog($database, $admin, 'Gestartet', 'Roleplay-Auktion', (int) $auctionId);
                }
            }
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
        respond([
            'approvedCount' => $approvedCount,
            'message' => $approvedCount . ($approvedCount === 1 ? ' Auktion wurde gestartet.' : ' Auktionen wurden gestartet.'),
        ]);
    }

    if ($path === '/api/auctions/end-all' && $method === 'POST') {
        $admin = requireLogin($currentUser);
        if (!$admin['isAdmin']) {
            respond(['error' => 'Nur der Admin darf Auktionen beenden.'], 403);
        }
        verifyCsrfToken();
        $body = requestBody();
        $durationSeconds = filter_var($body['durationSeconds'] ?? null, FILTER_VALIDATE_INT);
        if ($durationSeconds === false || $durationSeconds < 10 || $durationSeconds > 86400) {
            respond(['error' => 'Die letzte Gebotsphase muss zwischen 10 Sekunden und 24 Stunden dauern.'], 422);
        }
        $endsAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $durationSeconds . ' seconds')
            ->format('Y-m-d H:i:s');
        $auctionsToEnd = $database->query(
            'SELECT id, title FROM auctions WHERE status = \'active\' AND is_approved = 1 ORDER BY id'
        )->fetchAll();
        if ($auctionsToEnd === []) {
            respond(['endedCount' => 0, 'message' => 'Aktuell laufen keine freigegebenen Auktionen.']);
        }

        $durationLabel = $durationSeconds % 60 === 0
            ? intdiv($durationSeconds, 60) . (intdiv($durationSeconds, 60) === 1 ? ' Minute' : ' Minuten')
            : $durationSeconds . ' Sekunden';
        $database->beginTransaction();
        try {
            $startClosing = $database->prepare(
                'UPDATE auctions SET ends_at = :ends_at
                 WHERE id = :id AND status = \'active\' AND is_approved = 1'
            );
            $notifyBidders = $database->prepare(
                'INSERT INTO user_notifications (user_id, auction_id, message)
                 SELECT DISTINCT bidder_id, :notification_auction_id, :message
                 FROM auction_bids WHERE auction_id = :bid_auction_id'
            );
            $endedCount = 0;
            foreach ($auctionsToEnd as $auction) {
                $auctionId = (int) $auction['id'];
                $startClosing->execute(['ends_at' => $endsAt, 'id' => $auctionId]);
                if ($startClosing->rowCount() === 0) {
                    continue;
                }
                $notifyBidders->execute([
                    'notification_auction_id' => $auctionId,
                    'message' => 'Letzte Gebotsphase: „' . $auction['title'] . '“ endet in ' . $durationLabel . '. Gib jetzt dein letztes Gebot ab.',
                    'bid_auction_id' => $auctionId,
                ]);
                writeAuditLog($database, $admin, 'Letzte Gebotsphase gestartet', 'Roleplay-Auktion', $auctionId);
                $endedCount++;
            }
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
        respond([
            'endedCount' => $endedCount,
            'message' => $endedCount . ($endedCount === 1
                ? ' Auktion läuft jetzt in der letzten Gebotsphase.'
                : ' Auktionen laufen jetzt in der letzten Gebotsphase.'),
        ]);
    }

    if (preg_match('#^/api/auctions/(\d+)/bid$#D', (string) $path, $bidMatch) === 1 && $method === 'POST') {
        $bidder = requireLogin($currentUser);
        verifyCsrfToken();
        $body = requestBody();
        $amountCents = decimalToScaledInteger($body['amount'] ?? null, 8, 2);
        if ($amountCents === null || $amountCents < 1) {
            respond(['error' => 'Bitte gib ein gültiges Gebot ein.'], 422);
        }
        $auctionId = (int) $bidMatch[1];
        expireAuctions($database);
        $database->exec('BEGIN IMMEDIATE');
        try {
            $auctionStatement = $database->prepare(
                'SELECT seller_id, highest_bidder_id, current_bid_cents, status, is_approved, title, ends_at
                 FROM auctions WHERE id = :id'
            );
            $auctionStatement->execute(['id' => $auctionId]);
            $auction = $auctionStatement->fetch();
            if ($auction === false || $auction['status'] !== 'active' || (int) $auction['is_approved'] !== 1
                || ($auction['ends_at'] !== null && strtotime($auction['ends_at'] . ' UTC') <= time())) {
                $database->rollBack();
                respond(['error' => 'Diese Auktion ist nicht mehr verfügbar.'], 409);
            }
            if ((int) $auction['seller_id'] === $bidder['id']) {
                $database->rollBack();
                respond(['error' => 'Auf dein eigenes Angebot kannst du nicht bieten.'], 403);
            }
            if ($amountCents <= (int) $auction['current_bid_cents']) {
                $database->rollBack();
                respond(['error' => 'Dein Gebot muss höher als das aktuelle Gebot sein.'], 422);
            }
            $updateAuction = $database->prepare(
                'UPDATE auctions SET current_bid_cents = :amount, highest_bidder_id = :bidder_id WHERE id = :id'
            );
            $updateAuction->execute(['amount' => $amountCents, 'bidder_id' => $bidder['id'], 'id' => $auctionId]);
            $saveBid = $database->prepare(
                'INSERT INTO auction_bids (auction_id, bidder_id, amount_cents) VALUES (:auction_id, :bidder_id, :amount)'
            );
            $saveBid->execute(['auction_id' => $auctionId, 'bidder_id' => $bidder['id'], 'amount' => $amountCents]);
            if ($auction['highest_bidder_id'] !== null && (int) $auction['highest_bidder_id'] !== $bidder['id']) {
                $insertNotification = $database->prepare(
                    'INSERT INTO user_notifications (user_id, auction_id, message)
                     VALUES (:user_id, :auction_id, :message)'
                );
                $insertNotification->execute([
                    'user_id' => (int) $auction['highest_bidder_id'],
                    'auction_id' => $auctionId,
                    'message' => 'Du wurdest bei „' . $auction['title'] . '“ überboten. Das neue Gebot liegt bei ' . formatScaledInteger($amountCents, 2) . ' USD.',
                ]);
            }
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
        writeAuditLog($database, $bidder, 'Geboten', 'Roleplay-Auktion', $auctionId);
        respond(['message' => 'Gebot wurde abgegeben.']);
    }

    if (preg_match('#^/api/auctions/(\d+)$#D', (string) $path, $auctionMatch) === 1 && $method === 'PATCH') {
        $user = requireLogin($currentUser);
        verifyCsrfToken();
        $body = requestBody();
        $targetStatus = $body['status'] ?? null;
        if (!in_array($targetStatus, ['active', 'closed', 'ending'], true)) {
            respond(['error' => 'Ungültiger Auktionsstatus.'], 422);
        }
        $auctionId = (int) $auctionMatch[1];
        $auctionStatement = $database->prepare(
            'SELECT is_approved, status, ends_at, title, highest_bidder_id
             FROM auctions WHERE id = :id'
        );
        $auctionStatement->execute(['id' => $auctionId]);
        $auction = $auctionStatement->fetch();
        if ($auction === false) {
            respond(['error' => 'Die Auktion wurde nicht gefunden.'], 404);
        }
        if (!$user['isAdmin']) {
            respond(['error' => 'Nur der Admin darf Auktionen starten oder beenden.'], 403);
        }

        if ($targetStatus === 'active' && (int) $auction['is_approved'] === 0) {
            $startAuction = $database->prepare(
                'UPDATE auctions SET is_approved = 1, status = \'active\' WHERE id = :id'
            );
            $startAuction->execute(['id' => $auctionId]);
            writeAuditLog($database, $user, 'Gestartet', 'Roleplay-Auktion', $auctionId);
        } elseif ($targetStatus === 'ending' && $auction['status'] === 'active'
            && (int) $auction['is_approved'] === 1 && $auction['ends_at'] === null) {
            $durationSeconds = filter_var($body['durationSeconds'] ?? null, FILTER_VALIDATE_INT);
            if ($durationSeconds === false || $durationSeconds < 10 || $durationSeconds > 86400) {
                respond(['error' => 'Die letzte Gebotsphase muss zwischen 10 Sekunden und 24 Stunden dauern.'], 422);
            }
            $endsAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('+' . $durationSeconds . ' seconds')
                ->format('Y-m-d H:i:s');
            $startClosing = $database->prepare('UPDATE auctions SET ends_at = :ends_at WHERE id = :id');
            $startClosing->execute(['ends_at' => $endsAt, 'id' => $auctionId]);
            $notifyBidders = $database->prepare(
                'INSERT INTO user_notifications (user_id, auction_id, message)
                 SELECT DISTINCT bidder_id, :notification_auction_id, :message
                 FROM auction_bids WHERE auction_id = :bid_auction_id'
            );
            $durationLabel = $durationSeconds % 60 === 0
                ? intdiv($durationSeconds, 60) . (intdiv($durationSeconds, 60) === 1 ? ' Minute' : ' Minuten')
                : $durationSeconds . ' Sekunden';
            $notifyBidders->execute([
                'notification_auction_id' => $auctionId,
                'message' => 'Letzte Gebotsphase: „' . $auction['title'] . '“ endet in ' . $durationLabel . '. Gib jetzt dein letztes Gebot ab.',
                'bid_auction_id' => $auctionId,
            ]);
            writeAuditLog($database, $user, 'Letzte Gebotsphase gestartet', 'Roleplay-Auktion', $auctionId);
            respond([
                'message' => 'Letzte Gebotsphase gestartet. Die Bieter wurden benachrichtigt.',
                'endsAt' => str_replace(' ', 'T', $endsAt) . 'Z',
            ]);
        } elseif ($targetStatus === 'closed' && $auction['status'] === 'active' && (int) $auction['is_approved'] === 1) {
            if ($auction['ends_at'] !== null && strtotime($auction['ends_at'] . ' UTC') > time()) {
                respond(['error' => 'Die letzte Gebotsphase läuft noch. Die Auktion endet automatisch nach dem Countdown.'], 409);
            }
            $closeAuction = $database->prepare('UPDATE auctions SET status = \'closed\', ends_at = NULL WHERE id = :id');
            $closeAuction->execute(['id' => $auctionId]);
            writeAuditLog($database, $user, 'Beendet', 'Roleplay-Auktion', $auctionId);
        } else {
            respond(['error' => 'Diese Auktion kann in ihrem aktuellen Status nicht geändert werden.'], 409);
        }
        respond(['message' => $targetStatus === 'active' ? 'Die Auktion wurde gestartet.' : 'Die Auktion wurde beendet.']);
    }

    if ($path === '/api/logs' && $method === 'GET') {
        requirePermission($currentUser, 'logs_view');
        $beforeId = null;
        if (isset($_GET['before'])) {
            $beforeId = filter_var($_GET['before'], FILTER_VALIDATE_INT);
            if ($beforeId === false || $beforeId === null || $beforeId < 1) {
                respond(['error' => 'Ungültiger Protokoll-Cursor.'], 422);
            }
        }
        $query = 'SELECT id, actor_name AS actorName, action, resource, resource_id AS resourceId, created_at AS createdAt
                  FROM audit_logs';
        if ($beforeId !== null) {
            $query .= ' WHERE id < :before_id';
        }
        $query .= ' ORDER BY id DESC LIMIT 101';
        $getLogs = $database->prepare($query);
        $getLogs->execute($beforeId === null ? [] : ['before_id' => $beforeId]);
        $logs = $getLogs->fetchAll();
        $hasMore = count($logs) > 100;
        if ($hasMore) {
            array_pop($logs);
        }
        foreach ($logs as &$log) {
            $log['id'] = (int) $log['id'];
        }
        unset($log);
        respond([
            'logs' => $logs,
            'hasMore' => $hasMore,
            'nextBefore' => $logs === [] ? null : $logs[array_key_last($logs)]['id'],
        ]);
    }

    if ($path === '/api/logs' && $method === 'DELETE') {
        requirePermission($currentUser, 'logs_delete');
        verifyCsrfToken();
        $database->beginTransaction();
        try {
            $database->exec('DELETE FROM audit_logs');
            writeAuditLog($database, $currentUser, 'Gelöscht', 'Protokoll');
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
        respond(['message' => 'Das Protokoll wurde gelöscht.']);
    }

    if ($path === '/api/cash' && $method === 'GET') {
        requirePermission($currentUser, 'cash_manage');
        $transactions = $database->query(
            'SELECT id, transaction_date AS date, name, purpose, amount_cents AS amountCents, transaction_type AS type
             FROM cash_transactions ORDER BY transaction_date DESC, id DESC'
        )->fetchAll();
        foreach ($transactions as &$transaction) {
            $transaction['id'] = (int) $transaction['id'];
            $transaction['amount'] = formatScaledInteger((int) $transaction['amountCents'], 2);
            unset($transaction['amountCents']);
        }
        unset($transaction);
        respond(['transactions' => $transactions]);
    }

    if ($path === '/api/cash' && $method === 'POST') {
        requirePermission($currentUser, 'cash_manage');
        verifyCsrfToken();
        $body = requestBody();
        $date = $body['date'] ?? null;
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $purpose = is_string($body['purpose'] ?? null) ? trim($body['purpose']) : '';
        $amountCents = decimalToScaledInteger($body['amount'] ?? null, 9, 2);
        $type = $body['type'] ?? null;
        if (!validTransactionDate($date) || $name === '' || strlen($name) > 160 || $purpose === '' || strlen($purpose) > 160 || $amountCents === null || $amountCents < 1 || !in_array($type, ['income', 'expense'], true)) {
            respond(['error' => 'Bitte prüfe Datum, Name, Zweck, Betrag und Einnahme/Ausgabe. Der Betrag muss größer als 0 sein.'], 422);
        }
        $createTransaction = $database->prepare(
            'INSERT INTO cash_transactions (transaction_date, name, purpose, amount_cents, transaction_type)
             VALUES (:date, :name, :purpose, :amount, :type)'
        );
        $createTransaction->execute(['date' => $date, 'name' => $name, 'purpose' => $purpose, 'amount' => $amountCents, 'type' => $type]);
        $transactionId = (int) $database->lastInsertId();
        writeAuditLog($database, $currentUser, 'Erstellt', 'Kassenbuchung', $transactionId);
        respond(['transaction' => ['id' => $transactionId], 'message' => 'Kassenbuchung wurde gespeichert.'], 201);
    }

    if (preg_match('#^/api/cash/(\d+)$#D', (string) $path, $cashMatch) === 1) {
        requirePermission($currentUser, 'cash_manage');
        $transactionId = (int) $cashMatch[1];
        if ($method === 'PATCH') {
            verifyCsrfToken();
            $body = requestBody();
            $date = $body['date'] ?? null;
            $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
            $purpose = is_string($body['purpose'] ?? null) ? trim($body['purpose']) : '';
            $amountCents = decimalToScaledInteger($body['amount'] ?? null, 9, 2);
            $type = $body['type'] ?? null;
            if (!validTransactionDate($date) || $name === '' || strlen($name) > 160 || $purpose === '' || strlen($purpose) > 160 || $amountCents === null || $amountCents < 1 || !in_array($type, ['income', 'expense'], true)) {
                respond(['error' => 'Bitte prüfe Datum, Name, Zweck, Betrag und Einnahme/Ausgabe. Der Betrag muss größer als 0 sein.'], 422);
            }
            $updateTransaction = $database->prepare(
                'UPDATE cash_transactions
                 SET transaction_date = :date, name = :name, purpose = :purpose, amount_cents = :amount,
                     transaction_type = :type, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $updateTransaction->execute([
                'date' => $date,
                'name' => $name,
                'purpose' => $purpose,
                'amount' => $amountCents,
                'type' => $type,
                'id' => $transactionId,
            ]);
            if ($updateTransaction->rowCount() === 0) {
                $exists = $database->prepare('SELECT 1 FROM cash_transactions WHERE id = :id');
                $exists->execute(['id' => $transactionId]);
                if ($exists->fetchColumn() === false) {
                    respond(['error' => 'Kassenbuchung wurde nicht gefunden.'], 404);
                }
            }
            writeAuditLog($database, $currentUser, 'Bearbeitet', 'Kassenbuchung', $transactionId);
            respond(['message' => 'Kassenbuchung wurde aktualisiert.']);
        }

        if ($method === 'DELETE') {
            verifyCsrfToken();
            $deleteTransaction = $database->prepare('DELETE FROM cash_transactions WHERE id = :id');
            $deleteTransaction->execute(['id' => $transactionId]);
            if ($deleteTransaction->rowCount() === 0) {
                respond(['error' => 'Kassenbuchung wurde nicht gefunden.'], 404);
            }
            writeAuditLog($database, $currentUser, 'Gelöscht', 'Kassenbuchung', $transactionId);
            respond(['message' => 'Kassenbuchung wurde gelöscht.']);
        }
    }

    if ($path === '/api/balance' && $method === 'GET') {
        requirePermission($currentUser, 'balance_view');
        $transactionRows = $database->query(
            'SELECT id, transaction_date AS date, name, purpose, amount_cents AS amountCents, transaction_type AS type
             FROM cash_transactions ORDER BY transaction_date, id'
        )->fetchAll();
        $balanceCents = 0;
        $transactions = [];
        foreach ($transactionRows as $transaction) {
            $amountCents = (int) $transaction['amountCents'];
            $balanceCents += $transaction['type'] === 'income' ? $amountCents : -$amountCents;
            $transactions[] = [
                'id' => (int) $transaction['id'],
                'date' => $transaction['date'],
                'name' => $transaction['name'],
                'purpose' => $transaction['purpose'],
                'type' => $transaction['type'],
                'amount' => formatScaledInteger($amountCents, 2),
                'balanceCents' => $balanceCents,
            ];
        }
        respond(['balanceCents' => $balanceCents, 'transactions' => $transactions]);
    }

    if ($path === '/api/notices' && $method === 'GET') {
        requireLogin($currentUser);
        $noticeSections = $database->query(
            'SELECT id, name FROM notice_sections ORDER BY id'
        )->fetchAll();
        foreach ($noticeSections as &$noticeSection) {
            $noticeSection['id'] = (int) $noticeSection['id'];
        }
        unset($noticeSection);
        $notices = $database->query(
            "SELECT notices.id, notices.section_id AS sectionId,
                    CASE WHEN users.username = 'admin' COLLATE NOCASE THEN NULL ELSE notices.author_id END AS authorId,
                    CASE
                        WHEN users.username = 'admin' COLLATE NOCASE OR notices.author_name = 'admin' COLLATE NOCASE
                        THEN 'Team'
                        ELSE notices.author_name
                    END AS authorName,
                    notices.body, notices.created_at AS createdAt, notices.updated_at AS updatedAt
             FROM notices LEFT JOIN users ON users.id = notices.author_id
             ORDER BY notices.created_at DESC, notices.id DESC"
        )->fetchAll();
        foreach ($notices as &$notice) {
            $notice['id'] = (int) $notice['id'];
            $notice['sectionId'] = (int) $notice['sectionId'];
            $notice['authorId'] = $notice['authorId'] === null ? null : (int) $notice['authorId'];
        }
        unset($notice);
        respond(['sections' => $noticeSections, 'notices' => $notices]);
    }

    if ($path === '/api/notices' && $method === 'POST') {
        $user = requireLogin($currentUser);
        verifyCsrfToken();
        $body = requestBody();
        $sectionId = $body['sectionId'] ?? null;
        $noticeText = $body['body'] ?? null;
        if (!is_int($sectionId) || $sectionId < 1 || !validNoticeText($noticeText, 4000)) {
            respond(['error' => 'Bitte wähle einen Bereich und gib eine Nachricht mit höchstens 1000 Zeichen ein.'], 422);
        }
        $sectionCheck = $database->prepare('SELECT 1 FROM notice_sections WHERE id = :id');
        $sectionCheck->execute(['id' => $sectionId]);
        if ($sectionCheck->fetchColumn() === false) {
            respond(['error' => 'Der Nachrichtenbereich wurde nicht gefunden.'], 404);
        }
        $createNotice = $database->prepare(
            'INSERT INTO notices (section_id, author_id, author_name, body)
             VALUES (:section_id, :author_id, :author_name, :body)'
        );
        $createNotice->execute([
            'section_id' => $sectionId,
            'author_id' => $user['id'],
            'author_name' => $user['name'],
            'body' => trim($noticeText),
        ]);
        $noticeId = (int) $database->lastInsertId();
        writeAuditLog($database, $user, 'Erstellt', 'Nachricht', $noticeId);
        respond(['notice' => ['id' => $noticeId], 'message' => 'Nachricht wurde veröffentlicht.'], 201);
    }

    if (preg_match('#^/api/notices/(\d+)$#D', (string) $path, $noticeMatch) === 1) {
        $user = requireLogin($currentUser);
        $noticeId = (int) $noticeMatch[1];
        $noticeCheck = $database->prepare('SELECT author_id FROM notices WHERE id = :id');
        $noticeCheck->execute(['id' => $noticeId]);
        $noticeAuthorId = $noticeCheck->fetchColumn();
        if ($noticeAuthorId === false) {
            respond(['error' => 'Nachricht wurde nicht gefunden.'], 404);
        }
        $canManageNotices = in_array('notice_sections_manage', $user['permissions'], true);
        if (!$canManageNotices && (int) $noticeAuthorId !== $user['id']) {
            respond(['error' => 'Du kannst nur eigene Nachrichten bearbeiten oder löschen.'], 403);
        }

        if ($method === 'PATCH') {
            verifyCsrfToken();
            $body = requestBody();
            $noticeText = $body['body'] ?? null;
            if (!validNoticeText($noticeText, 4000)) {
                respond(['error' => 'Die Nachricht darf nicht leer sein und höchstens 1000 Zeichen enthalten.'], 422);
            }
            $updateNotice = $database->prepare(
                'UPDATE notices SET body = :body, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $updateNotice->execute(['body' => trim($noticeText), 'id' => $noticeId]);
            writeAuditLog($database, $user, 'Bearbeitet', 'Nachricht', $noticeId);
            respond(['message' => 'Nachricht wurde gespeichert.']);
        }

        if ($method === 'DELETE') {
            verifyCsrfToken();
            $deleteNotice = $database->prepare('DELETE FROM notices WHERE id = :id');
            $deleteNotice->execute(['id' => $noticeId]);
            writeAuditLog($database, $user, 'Gelöscht', 'Nachricht', $noticeId);
            respond(['message' => 'Nachricht wurde gelöscht.']);
        }
    }

    if ($path === '/api/notice-sections' && $method === 'POST') {
        requirePermission($currentUser, 'notice_sections_manage');
        verifyCsrfToken();
        $body = requestBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if (!validNoticeSectionName($name)) {
            respond(['error' => 'Der Bereichsname muss zwischen 1 und 60 Zeichen lang sein.'], 422);
        }
        $nameCheck = $database->prepare('SELECT 1 FROM notice_sections WHERE name = :name COLLATE NOCASE');
        $nameCheck->execute(['name' => $name]);
        if ($nameCheck->fetchColumn() !== false) {
            respond(['error' => 'Ein Bereich mit diesem Namen ist bereits vorhanden.'], 409);
        }
        $createSection = $database->prepare('INSERT INTO notice_sections (name) VALUES (:name)');
        $createSection->execute(['name' => $name]);
        $sectionId = (int) $database->lastInsertId();
        writeAuditLog($database, $currentUser, 'Erstellt', 'Nachrichtenbereich', $sectionId);
        respond(['section' => ['id' => $sectionId, 'name' => $name]], 201);
    }

    if (preg_match('#^/api/notice-sections/(\d+)$#D', (string) $path, $noticeSectionMatch) === 1) {
        requirePermission($currentUser, 'notice_sections_manage');
        $noticeSectionId = (int) $noticeSectionMatch[1];
        $sectionCheck = $database->prepare('SELECT 1 FROM notice_sections WHERE id = :id');
        $sectionCheck->execute(['id' => $noticeSectionId]);
        if ($sectionCheck->fetchColumn() === false) {
            respond(['error' => 'Nachrichtenbereich wurde nicht gefunden.'], 404);
        }

        if ($method === 'PATCH') {
            verifyCsrfToken();
            $body = requestBody();
            $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
            if (!validNoticeSectionName($name)) {
                respond(['error' => 'Der Bereichsname muss zwischen 1 und 60 Zeichen lang sein.'], 422);
            }
            $nameCheck = $database->prepare(
                'SELECT 1 FROM notice_sections WHERE name = :name COLLATE NOCASE AND id != :id'
            );
            $nameCheck->execute(['name' => $name, 'id' => $noticeSectionId]);
            if ($nameCheck->fetchColumn() !== false) {
                respond(['error' => 'Ein Bereich mit diesem Namen ist bereits vorhanden.'], 409);
            }
            $renameSection = $database->prepare('UPDATE notice_sections SET name = :name WHERE id = :id');
            $renameSection->execute(['name' => $name, 'id' => $noticeSectionId]);
            writeAuditLog($database, $currentUser, 'Bearbeitet', 'Nachrichtenbereich', $noticeSectionId);
            respond(['message' => 'Bereich wurde umbenannt.']);
        }

        if ($method === 'DELETE') {
            verifyCsrfToken();
            $noticeCount = $database->prepare('SELECT COUNT(*) FROM notices WHERE section_id = :id');
            $noticeCount->execute(['id' => $noticeSectionId]);
            if ((int) $noticeCount->fetchColumn() > 0) {
                respond(['error' => 'Der Bereich enthält noch Nachrichten. Lösche diese zuerst, bevor du den Bereich entfernst.'], 409);
            }
            $deleteSection = $database->prepare('DELETE FROM notice_sections WHERE id = :id');
            $deleteSection->execute(['id' => $noticeSectionId]);
            writeAuditLog($database, $currentUser, 'Gelöscht', 'Nachrichtenbereich', $noticeSectionId);
            respond(['message' => 'Bereich wurde gelöscht.']);
        }
    }

    if ($path === '/api/products' && $method === 'GET') {
        requirePermission($currentUser, 'products_view', 'products_manage', 'product_markup_manage');
        $markupBasisPoints = (int) $database->query('SELECT markup_basis_points FROM product_settings WHERE id = 1')->fetchColumn();
        $productRows = $database->query(
            'SELECT id, name, purchase_price_cents AS purchasePriceCents,
                    markup_basis_points AS markupBasisPoints
             FROM products ORDER BY name COLLATE NOCASE, id'
        )->fetchAll();
        $recipeRows = $database->query(
            'SELECT recipe.product_id AS productId, material.id AS rawMaterialId,
                    material.name AS name, recipe.quantity_milliunits AS quantityMilliunits
             FROM product_recipe_components AS recipe
             JOIN raw_materials AS material ON material.id = recipe.raw_material_id
             ORDER BY material.name COLLATE NOCASE, material.id'
        )->fetchAll();
        $recipesByProduct = [];
        foreach ($recipeRows as $recipe) {
            $recipesByProduct[(int) $recipe['productId']][] = [
                'rawMaterialId' => (int) $recipe['rawMaterialId'],
                'name' => $recipe['name'],
                'quantity' => formatScaledInteger((int) $recipe['quantityMilliunits'], 3),
            ];
        }
        $products = [];
        foreach ($productRows as $product) {
            $purchasePriceCents = (int) $product['purchasePriceCents'];
            $productMarkupBasisPoints = (int) $product['markupBasisPoints'];
            $product['id'] = (int) $product['id'];
            $product['recipe'] = $recipesByProduct[$product['id']] ?? [];
            $product['purchasePrice'] = formatScaledInteger($purchasePriceCents, 2);
            $product['markupPercent'] = formatScaledInteger($productMarkupBasisPoints, 2);
            $product['sellingPrice'] = formatScaledInteger(
                intdiv($purchasePriceCents * (10000 + $productMarkupBasisPoints) + 5000, 10000),
                2
            );
            unset($product['purchasePriceCents']);
            unset($product['markupBasisPoints']);
            $products[] = $product;
        }
        respond([
            'products' => $products,
            'markupPercent' => formatScaledInteger($markupBasisPoints, 2),
        ]);
    }

    if ($path === '/api/raw-materials' && $method === 'GET') {
        requirePermission($currentUser, 'products_view', 'products_manage', 'product_markup_manage');
        $materials = $database->query(
            'SELECT id, name FROM raw_materials ORDER BY name COLLATE NOCASE, id'
        )->fetchAll();
        foreach ($materials as &$material) {
            $material['id'] = (int) $material['id'];
        }
        unset($material);
        respond(['materials' => $materials]);
    }

    if ($path === '/api/raw-materials' && $method === 'POST') {
        requirePermission($currentUser, 'products_manage');
        verifyCsrfToken();
        $body = requestBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if (preg_match('/\A.{1,160}\z/us', $name) !== 1) {
            respond(['error' => 'Bitte gib einen Rohstoffnamen mit höchstens 160 Zeichen ein.'], 422);
        }
        $existingMaterial = $database->prepare(
            'SELECT id FROM raw_materials
             WHERE name = :name COLLATE NOCASE'
        );
        $existingMaterial->execute(['name' => $name]);
        if ($existingMaterial->fetchColumn() !== false) {
            respond(['error' => 'Dieser Rohstoff ist bereits vorhanden.'], 409);
        }
        $createMaterial = $database->prepare('INSERT INTO raw_materials (name, unit) VALUES (:name, :unit)');
        $createMaterial->execute(['name' => $name, 'unit' => 'Stück']);
        $materialId = (int) $database->lastInsertId();
        writeAuditLog($database, $currentUser, 'Erstellt', 'Rohstoff', $materialId);
        respond([
            'material' => [
                'id' => $materialId,
                'name' => $name,
            ],
            'message' => 'Rohstoff wurde angelegt.',
        ], 201);
    }

    if (preg_match('#^/api/raw-materials/(\d+)$#D', (string) $path, $materialMatch) === 1) {
        requirePermission($currentUser, 'products_manage');
        $materialId = (int) $materialMatch[1];

        if ($method === 'PATCH') {
            verifyCsrfToken();
            $body = requestBody();
            $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
            if (preg_match('/\A.{1,160}\z/us', $name) !== 1) {
                respond(['error' => 'Bitte gib einen Rohstoffnamen mit höchstens 160 Zeichen ein.'], 422);
            }
            $existingMaterial = $database->prepare(
                'SELECT id FROM raw_materials
                 WHERE name = :name COLLATE NOCASE AND id <> :id'
            );
            $existingMaterial->execute(['name' => $name, 'id' => $materialId]);
            if ($existingMaterial->fetchColumn() !== false) {
                respond(['error' => 'Dieser Rohstoff ist bereits vorhanden.'], 409);
            }
            $updateMaterial = $database->prepare(
                'UPDATE raw_materials SET name = :name WHERE id = :id'
            );
            $updateMaterial->execute(['name' => $name, 'id' => $materialId]);
            if ($updateMaterial->rowCount() === 0) {
                $materialExists = $database->prepare('SELECT 1 FROM raw_materials WHERE id = :id');
                $materialExists->execute(['id' => $materialId]);
                if ($materialExists->fetchColumn() === false) {
                    respond(['error' => 'Rohstoff wurde nicht gefunden.'], 404);
                }
            }
            writeAuditLog($database, $currentUser, 'Bearbeitet', 'Rohstoff', $materialId);
            respond(['message' => 'Rohstoff wurde aktualisiert.']);
        }

        if ($method === 'DELETE') {
            verifyCsrfToken();
            $materialExists = $database->prepare('SELECT 1 FROM raw_materials WHERE id = :id');
            $materialExists->execute(['id' => $materialId]);
            if ($materialExists->fetchColumn() === false) {
                respond(['error' => 'Rohstoff wurde nicht gefunden.'], 404);
            }
            $recipeUsage = $database->prepare('SELECT COUNT(*) FROM product_recipe_components WHERE raw_material_id = :id');
            $recipeUsage->execute(['id' => $materialId]);
            if ((int) $recipeUsage->fetchColumn() > 0) {
                respond(['error' => 'Der Rohstoff wird noch in Rezepturen verwendet. Entferne ihn zuerst aus allen Rezepturen.'], 409);
            }
            $deleteMaterial = $database->prepare('DELETE FROM raw_materials WHERE id = :id');
            $deleteMaterial->execute(['id' => $materialId]);
            writeAuditLog($database, $currentUser, 'Gelöscht', 'Rohstoff', $materialId);
            respond(['message' => 'Rohstoff wurde gelöscht.']);
        }
    }

    if (preg_match('#^/api/products/(\d+)/recipe$#D', (string) $path, $recipeProductMatch) === 1 && $method === 'PATCH') {
        requirePermission($currentUser, 'products_manage');
        verifyCsrfToken();
        $productId = (int) $recipeProductMatch[1];
        $productExists = $database->prepare('SELECT 1 FROM products WHERE id = :id');
        $productExists->execute(['id' => $productId]);
        if ($productExists->fetchColumn() === false) {
            respond(['error' => 'Produkt wurde nicht gefunden.'], 404);
        }

        $body = requestBody();
        $components = $body['components'] ?? null;
        if (!is_array($components) || !array_is_list($components) || count($components) > 50) {
            respond(['error' => 'Die Rezeptur muss eine Liste mit höchstens 50 Bestandteilen sein.'], 422);
        }

        $validatedComponents = [];
        $seenMaterialIds = [];
        $materialExists = $database->prepare('SELECT 1 FROM raw_materials WHERE id = :id');
        foreach ($components as $component) {
            if (!is_array($component)) {
                respond(['error' => 'Bitte prüfe die Bestandteile der Rezeptur.'], 422);
            }
            $rawMaterialId = filter_var($component['rawMaterialId'] ?? null, FILTER_VALIDATE_INT);
            $quantityMilliunits = decimalToScaledInteger($component['quantity'] ?? null, 6, 3);
            if (
                $rawMaterialId === false
                || $rawMaterialId === null
                || isset($seenMaterialIds[$rawMaterialId])
                || $quantityMilliunits === null
                || $quantityMilliunits < 1
            ) {
                respond(['error' => 'Bitte prüfe Rohstoff und Menge jedes Bestandteils.'], 422);
            }
            $materialExists->execute(['id' => $rawMaterialId]);
            if ($materialExists->fetchColumn() === false) {
                respond(['error' => 'Ein ausgewählter Rohstoff wurde nicht gefunden.'], 422);
            }
            $seenMaterialIds[$rawMaterialId] = true;
            $validatedComponents[] = [
                'rawMaterialId' => $rawMaterialId,
                'quantity' => $quantityMilliunits,
            ];
        }

        $database->beginTransaction();
        try {
            $deleteRecipe = $database->prepare('DELETE FROM product_recipe_components WHERE product_id = :product_id');
            $deleteRecipe->execute(['product_id' => $productId]);
            $insertComponent = $database->prepare(
                'INSERT INTO product_recipe_components (product_id, raw_material_id, quantity_milliunits)
                 VALUES (:product_id, :raw_material_id, :quantity)'
            );
            foreach ($validatedComponents as $component) {
                $insertComponent->execute([
                    'product_id' => $productId,
                    'raw_material_id' => $component['rawMaterialId'],
                    'quantity' => $component['quantity'],
                ]);
            }
            $database->commit();
        } catch (Throwable $recipeError) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $recipeError;
        }
        writeAuditLog($database, $currentUser, 'Bearbeitet', 'Produktrezeptur', $productId);
        respond(['message' => 'Produktrezeptur wurde gespeichert.']);
    }

    if ($path === '/api/products/markup' && $method === 'PATCH') {
        requirePermission($currentUser, 'product_markup_manage');
        verifyCsrfToken();
        $body = requestBody();
        $markupBasisPoints = decimalToScaledInteger($body['markupPercent'] ?? null, 4, 2);
        if ($markupBasisPoints === null || $markupBasisPoints > 100000) {
            respond(['error' => 'Der Aufschlag muss zwischen 0 und 1000 Prozent liegen.'], 422);
        }
        $updateMarkup = $database->prepare(
            'UPDATE product_settings SET markup_basis_points = :markup_basis_points, updated_at = CURRENT_TIMESTAMP WHERE id = 1'
        );
        $updateMarkup->execute(['markup_basis_points' => $markupBasisPoints]);
        writeAuditLog($database, $currentUser, 'Bearbeitet', 'Standardaufschlag');
        respond(['message' => 'Produktaufschlag wurde gespeichert.']);
    }

    if (preg_match('#^/api/products/(\d+)/markup$#D', (string) $path, $productMarkupMatch) === 1 && $method === 'PATCH') {
        requirePermission($currentUser, 'product_markup_manage');
        verifyCsrfToken();
        $body = requestBody();
        $markupBasisPoints = decimalToScaledInteger($body['markupPercent'] ?? null, 4, 2);
        if ($markupBasisPoints === null || $markupBasisPoints > 100000) {
            respond(['error' => 'Der Aufschlag muss zwischen 0 und 1000 Prozent liegen.'], 422);
        }
        $updateProductMarkup = $database->prepare(
            'UPDATE products SET markup_basis_points = :markup_basis_points, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $updateProductMarkup->execute([
            'markup_basis_points' => $markupBasisPoints,
            'id' => (int) $productMarkupMatch[1],
        ]);
        if ($updateProductMarkup->rowCount() === 0) {
            $exists = $database->prepare('SELECT 1 FROM products WHERE id = :id');
            $exists->execute(['id' => (int) $productMarkupMatch[1]]);
            if ($exists->fetchColumn() === false) {
                respond(['error' => 'Produkt wurde nicht gefunden.'], 404);
            }
        }
        writeAuditLog($database, $currentUser, 'Bearbeitet', 'Produktaufschlag', (int) $productMarkupMatch[1]);
        respond(['message' => 'Produktaufschlag wurde gespeichert.']);
    }

    if ($path === '/api/products' && $method === 'POST') {
        requirePermission($currentUser, 'products_manage');
        verifyCsrfToken();
        $body = requestBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $purchasePriceCents = decimalToScaledInteger($body['purchasePrice'] ?? null, 9, 2);
        if ($name === '' || strlen($name) > 160 || $purchasePriceCents === null) {
            respond(['error' => 'Bitte prüfe Produktname und Einkaufspreis. Preise müssen eine positive Zahl mit höchstens zwei Nachkommastellen sein.'], 422);
        }
        $defaultMarkupBasisPoints = (int) $database->query(
            'SELECT markup_basis_points FROM product_settings WHERE id = 1'
        )->fetchColumn();
        $createProduct = $database->prepare(
            'INSERT INTO products (name, purchase_price_cents, markup_basis_points)
             VALUES (:name, :purchase_price_cents, :markup_basis_points)'
        );
        $createProduct->execute([
            'name' => $name,
            'purchase_price_cents' => $purchasePriceCents,
            'markup_basis_points' => $defaultMarkupBasisPoints,
        ]);
        $productId = (int) $database->lastInsertId();
        writeAuditLog($database, $currentUser, 'Erstellt', 'Produkt', $productId);
        respond(['product' => ['id' => $productId], 'message' => 'Produkt wurde angelegt.'], 201);
    }

    if (preg_match('#^/api/products/(\d+)$#D', (string) $path, $productMatch) === 1) {
        $productId = (int) $productMatch[1];

        if ($method === 'PATCH') {
            requirePermission($currentUser, 'products_manage');
            verifyCsrfToken();
            $body = requestBody();
            $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
            $purchasePriceCents = decimalToScaledInteger($body['purchasePrice'] ?? null, 9, 2);
            if ($name === '' || strlen($name) > 160 || $purchasePriceCents === null) {
                respond(['error' => 'Bitte prüfe Produktname und Einkaufspreis. Preise müssen eine positive Zahl mit höchstens zwei Nachkommastellen sein.'], 422);
            }
            $updateProduct = $database->prepare(
                'UPDATE products SET name = :name, purchase_price_cents = :purchase_price_cents,
                                     updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $updateProduct->execute([
                'name' => $name,
                'purchase_price_cents' => $purchasePriceCents,
                'id' => $productId,
            ]);
            if ($updateProduct->rowCount() === 0) {
                $exists = $database->prepare('SELECT 1 FROM products WHERE id = :id');
                $exists->execute(['id' => $productId]);
                if ($exists->fetchColumn() === false) {
                    respond(['error' => 'Produkt wurde nicht gefunden.'], 404);
                }
            }
            writeAuditLog($database, $currentUser, 'Bearbeitet', 'Produkt', $productId);
            respond(['message' => 'Produkt wurde gespeichert.']);
        }

        if ($method === 'DELETE') {
            requirePermission($currentUser, 'products_manage');
            verifyCsrfToken();
            $deleteProduct = $database->prepare('DELETE FROM products WHERE id = :id');
            $deleteProduct->execute(['id' => $productId]);
            if ($deleteProduct->rowCount() === 0) {
                respond(['error' => 'Produkt wurde nicht gefunden.'], 404);
            }
            writeAuditLog($database, $currentUser, 'Gelöscht', 'Produkt', $productId);
            respond(['message' => 'Produkt wurde gelöscht.']);
        }
    }

    if ($path === '/api/orders' && $method === 'GET') {
        requirePermission($currentUser, 'orders_manage');
        $productRows = $database->query(
            'SELECT id, name, purchase_price_cents AS purchasePriceCents,
                    markup_basis_points AS markupBasisPoints
             FROM products ORDER BY name COLLATE NOCASE, id'
        )->fetchAll();
        $products = [];
        foreach ($productRows as $product) {
            $purchasePriceCents = (int) $product['purchasePriceCents'];
            $markupBasisPoints = (int) $product['markupBasisPoints'];
            $products[] = [
                'id' => (int) $product['id'],
                'name' => $product['name'],
                'sellingPrice' => formatScaledInteger(
                    intdiv($purchasePriceCents * (10000 + $markupBasisPoints) + 5000, 10000),
                    2
                ),
            ];
        }
        $orders = $database->query(
            'SELECT id, customer_name AS customerName, customer_phone AS customerPhone,
                    grouping_name AS groupingName, total_cents AS totalCents, created_at AS createdAt
             FROM orders ORDER BY id DESC LIMIT 50'
        )->fetchAll();
        $itemsStatement = $database->prepare(
            'SELECT product_name AS productName, quantity, unit_price_cents AS unitPriceCents,
                    line_total_cents AS lineTotalCents
             FROM order_items WHERE order_id = :order_id ORDER BY id'
        );
        foreach ($orders as &$order) {
            $orderId = (int) $order['id'];
            $order['id'] = $orderId;
            $order['total'] = formatScaledInteger((int) $order['totalCents'], 2);
            $order['items'] = [];
            $itemsStatement->execute(['order_id' => $orderId]);
            foreach ($itemsStatement->fetchAll() as $item) {
                $order['items'][] = [
                    'productName' => $item['productName'],
                    'quantity' => (int) $item['quantity'],
                    'unitPrice' => formatScaledInteger((int) $item['unitPriceCents'], 2),
                    'lineTotal' => formatScaledInteger((int) $item['lineTotalCents'], 2),
                ];
            }
            unset($order['totalCents']);
        }
        unset($order);
        respond(['products' => $products, 'orders' => $orders]);
    }

    if ($path === '/api/orders' && $method === 'POST') {
        requirePermission($currentUser, 'orders_manage');
        verifyCsrfToken();
        $body = requestBody();
        $customerName = is_string($body['customerName'] ?? null) ? trim($body['customerName']) : '';
        $customerPhone = is_string($body['customerPhone'] ?? null) ? trim($body['customerPhone']) : '';
        $groupingName = is_string($body['groupingName'] ?? null) ? trim($body['groupingName']) : '';
        $items = $body['items'] ?? null;
        if (
            $customerName === '' || strlen($customerName) > 160
            || $customerPhone === '' || strlen($customerPhone) > 40
            || $groupingName === '' || strlen($groupingName) > 160
            || !is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 50
        ) {
            respond(['error' => 'Bitte prüfe Name, Telefonnummer, Gruppierung und mindestens eine Produktposition.'], 422);
        }
        foreach ($items as $item) {
            if (
                !is_array($item)
                || !is_int($item['productId'] ?? null)
                || $item['productId'] < 1
                || !is_int($item['quantity'] ?? null)
                || $item['quantity'] < 1
                || $item['quantity'] > 9999
            ) {
                respond(['error' => 'Jede Position braucht ein gültiges Produkt und eine Menge von 1 bis 9999.'], 422);
            }
        }

        $database->beginTransaction();
        try {
            $getProduct = $database->prepare(
                'SELECT id, name, purchase_price_cents, markup_basis_points FROM products WHERE id = :id'
            );
            $orderLines = [];
            $totalCents = 0;
            foreach ($items as $item) {
                $getProduct->execute(['id' => $item['productId']]);
                $product = $getProduct->fetch();
                if ($product === false) {
                    $database->rollBack();
                    respond(['error' => 'Ein ausgewähltes Produkt wurde nicht gefunden. Bitte lade die Seite neu.'], 422);
                }
                $purchasePriceCents = (int) $product['purchase_price_cents'];
                $markupBasisPoints = (int) $product['markup_basis_points'];
                $unitPriceCents = intdiv($purchasePriceCents * (10000 + $markupBasisPoints) + 5000, 10000);
                $lineTotalCents = $unitPriceCents * $item['quantity'];
                $totalCents += $lineTotalCents;
                $orderLines[] = [
                    'productId' => (int) $product['id'],
                    'productName' => $product['name'],
                    'quantity' => $item['quantity'],
                    'unitPriceCents' => $unitPriceCents,
                    'lineTotalCents' => $lineTotalCents,
                ];
            }

            $createOrder = $database->prepare(
                'INSERT INTO orders (customer_name, customer_phone, grouping_name, total_cents)
                 VALUES (:customer_name, :customer_phone, :grouping_name, :total_cents)'
            );
            $createOrder->execute([
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'grouping_name' => $groupingName,
                'total_cents' => $totalCents,
            ]);
            $orderId = (int) $database->lastInsertId();
            $createOrderItem = $database->prepare(
                'INSERT INTO order_items
                    (order_id, product_id, product_name, quantity, unit_price_cents, line_total_cents)
                 VALUES (:order_id, :product_id, :product_name, :quantity, :unit_price_cents, :line_total_cents)'
            );
            foreach ($orderLines as $line) {
                $createOrderItem->execute([
                    'order_id' => $orderId,
                    'product_id' => $line['productId'],
                    'product_name' => $line['productName'],
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => $line['unitPriceCents'],
                    'line_total_cents' => $line['lineTotalCents'],
                ]);
            }
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
        writeAuditLog($database, $currentUser, 'Erstellt', 'Bestellung', $orderId);
        respond([
            'order' => ['id' => $orderId, 'total' => formatScaledInteger($totalCents, 2)],
            'message' => 'Bestellung wurde gespeichert.',
        ], 201);
    }

    if ($path === '/api/warehouses' && $method === 'GET') {
        requirePermission($currentUser, 'inventory_view', 'inventory_manage', 'warehouses_manage');
        $warehouseRows = $database->query('SELECT id, name, sort_order AS sortOrder FROM warehouses ORDER BY sort_order, name COLLATE NOCASE')->fetchAll();
        $entryRows = $database->query(
            "SELECT warehouse_entries.warehouse_id, warehouse_entries.user_id,
                    warehouse_entries.locker_number AS lockerNumber, warehouse_entries.pin, warehouse_entries.contents
             FROM warehouse_entries JOIN users ON users.id = warehouse_entries.user_id
             WHERE users.username != 'admin' COLLATE NOCASE"
        )->fetchAll();
        $entriesByWarehouse = [];
        foreach ($entryRows as $entry) {
            $entriesByWarehouse[$entry['warehouse_id']][(string) $entry['user_id']] = [
                'lockerNumber' => $entry['lockerNumber'],
                'pin' => in_array('inventory_manage', $currentUser['permissions'], true) ? $entry['pin'] : null,
                'contents' => $entry['contents'],
            ];
        }
        $warehouses = [];
        foreach ($warehouseRows as $warehouse) {
            $warehouse['id'] = (int) $warehouse['id'];
            $warehouse['sortOrder'] = (int) $warehouse['sortOrder'];
            $warehouse['entries'] = (object) ($entriesByWarehouse[$warehouse['id']] ?? []);
            $warehouses[] = $warehouse;
        }

        $members = $database->query(
            'SELECT users.id, users.name, users.username, users.role_id AS roleId,
                    roles.name AS roleName, roles.sort_order AS roleOrder
             FROM users JOIN roles ON roles.id = users.role_id
             WHERE users.is_active = 1 AND users.username != \'admin\' COLLATE NOCASE
             ORDER BY roles.sort_order, roles.name COLLATE NOCASE, users.name COLLATE NOCASE, users.id'
        )->fetchAll();
        foreach ($members as &$member) {
            $member['id'] = (int) $member['id'];
            $member['roleOrder'] = (int) $member['roleOrder'];
        }
        unset($member);

        respond(['warehouses' => $warehouses, 'members' => $members]);
    }

    if ($path === '/api/warehouses' && $method === 'POST') {
        requirePermission($currentUser, 'warehouses_manage');
        verifyCsrfToken();
        $body = requestBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if ($name === '' || strlen($name) > 100) {
            respond(['error' => 'Der Lagername muss zwischen 1 und 100 Zeichen lang sein.'], 422);
        }

        $sortOrder = (int) $database->query('SELECT COALESCE(MAX(sort_order), 0) + 100 FROM warehouses')->fetchColumn();
        $createWarehouse = $database->prepare('INSERT INTO warehouses (name, sort_order) VALUES (:name, :sort_order)');
        $createWarehouse->execute(['name' => $name, 'sort_order' => $sortOrder]);
        $warehouseId = (int) $database->lastInsertId();
        writeAuditLog($database, $currentUser, 'Erstellt', 'Lager', $warehouseId);
        respond(['warehouse' => ['id' => $warehouseId, 'name' => $name, 'sortOrder' => $sortOrder]], 201);
    }

    if (preg_match('#^/api/warehouses/(\d+)$#D', (string) $path, $warehouseMatch) === 1) {
        $warehouseId = (int) $warehouseMatch[1];

        if ($method === 'PATCH') {
            requirePermission($currentUser, 'warehouses_manage');
            verifyCsrfToken();
            $body = requestBody();
            $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
            if ($name === '' || strlen($name) > 100) {
                respond(['error' => 'Der Lagername muss zwischen 1 und 100 Zeichen lang sein.'], 422);
            }
            $updateWarehouse = $database->prepare('UPDATE warehouses SET name = :name WHERE id = :id');
            $updateWarehouse->execute(['name' => $name, 'id' => $warehouseId]);
            if ($updateWarehouse->rowCount() === 0) {
                $exists = $database->prepare('SELECT 1 FROM warehouses WHERE id = :id');
                $exists->execute(['id' => $warehouseId]);
                if ($exists->fetchColumn() === false) {
                    respond(['error' => 'Lager wurde nicht gefunden.'], 404);
                }
            }
            writeAuditLog($database, $currentUser, 'Bearbeitet', 'Lager', $warehouseId);
            respond(['message' => 'Lagername wurde gespeichert.']);
        }

        if ($method === 'DELETE') {
            requirePermission($currentUser, 'warehouses_manage');
            verifyCsrfToken();
            $deleteWarehouse = $database->prepare('DELETE FROM warehouses WHERE id = :id');
            $deleteWarehouse->execute(['id' => $warehouseId]);
            if ($deleteWarehouse->rowCount() === 0) {
                respond(['error' => 'Lager wurde nicht gefunden.'], 404);
            }
            writeAuditLog($database, $currentUser, 'Gelöscht', 'Lager', $warehouseId);
            respond(['message' => 'Lager und zugehörige Einträge wurden gelöscht.']);
        }
    }

    if (preg_match('#^/api/warehouses/(\d+)/members/(\d+)$#D', (string) $path, $entryMatch) === 1 && $method === 'PATCH') {
        requirePermission($currentUser, 'inventory_manage');
        verifyCsrfToken();
        $body = requestBody();
        $lockerNumber = $body['lockerNumber'] ?? null;
        $pin = $body['pin'] ?? null;
        $contents = $body['contents'] ?? null;
        if (
            !is_string($lockerNumber) || strlen($lockerNumber) > 200
            || !is_string($pin) || strlen($pin) > 200
            || !is_string($contents) || strlen($contents) > 4000
        ) {
            respond(['error' => 'Schließfachnummer und PIN dürfen höchstens 200, der Inhalt höchstens 4000 Bytes lang sein.'], 422);
        }
        $saveEntry = $database->prepare(
            'INSERT INTO warehouse_entries (warehouse_id, user_id, locker_number, pin, contents, updated_at)
             SELECT :warehouse_id, users.id, :locker_number, :pin, :contents, CURRENT_TIMESTAMP
             FROM users
             WHERE users.id = :user_id AND users.is_active = 1
               AND EXISTS (SELECT 1 FROM warehouses WHERE id = :warehouse_id)
             ON CONFLICT (warehouse_id, user_id)
             DO UPDATE SET locker_number = excluded.locker_number, pin = excluded.pin,
                           contents = excluded.contents, updated_at = CURRENT_TIMESTAMP'
        );
        $saveEntry->execute([
            'warehouse_id' => (int) $entryMatch[1],
            'locker_number' => $lockerNumber,
            'pin' => $pin,
            'contents' => $contents,
            'user_id' => (int) $entryMatch[2],
        ]);
        if ($saveEntry->rowCount() === 0) {
            respond(['error' => 'Lager oder aktives Mitglied wurde nicht gefunden.'], 404);
        }
        writeAuditLog(
            $database,
            $currentUser,
            'Bearbeitet',
            'Lagereintrag',
            $entryMatch[1] . ':' . $entryMatch[2]
        );
        respond(['message' => 'Lagereintrag wurde gespeichert.']);
    }

    if ($path === '/api/roles/order' && $method === 'PATCH') {
        requirePermission($currentUser, 'members_order');
        verifyCsrfToken();
        $body = requestBody();
        $roleIds = $body['roleIds'] ?? null;
        $existingRoleIds = $database->query('SELECT id FROM roles ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        if (
            !is_array($roleIds)
            || !array_is_list($roleIds)
            || array_filter($roleIds, static fn (mixed $id): bool => !is_string($id)) !== []
            || count($roleIds) !== count($existingRoleIds)
            || count(array_unique($roleIds)) !== count($roleIds)
            || array_diff($roleIds, $existingRoleIds) !== []
            || array_diff($existingRoleIds, $roleIds) !== []
        ) {
            respond(['error' => 'Die Rangfolge muss alle vorhandenen Rollen genau einmal enthalten.'], 422);
        }

        $database->beginTransaction();
        try {
            $updateRoleOrder = $database->prepare('UPDATE roles SET sort_order = :sort_order WHERE id = :id');
            foreach ($roleIds as $index => $roleId) {
                $updateRoleOrder->execute(['sort_order' => $index * 100, 'id' => $roleId]);
            }
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
        writeAuditLog($database, $currentUser, 'Bearbeitet', 'Rollenrangfolge');
        respond(['message' => 'Rangfolge wurde gespeichert.']);
    }

    if (preg_match('#^/api/employees(?:/(\d+))?$#D', (string) $path, $employeeMatch) === 1) {
        $employeeId = isset($employeeMatch[1]) ? (int) $employeeMatch[1] : null;

        if ($method === 'GET' && $employeeId === null) {
            requirePermission($currentUser, 'employees_view', 'employees_manage');
            $employees = $database->query(
                'SELECT users.id, users.name, users.username, users.role_id AS roleId,
                        roles.name AS roleName, users.is_active AS isActive, users.created_at AS createdAt
                 FROM users JOIN roles ON roles.id = users.role_id
                 WHERE users.username != \'admin\' COLLATE NOCASE
                 ORDER BY users.name COLLATE NOCASE'
            )->fetchAll();
            foreach ($employees as &$employee) {
                $employee['id'] = (int) $employee['id'];
                $employee['isActive'] = (bool) $employee['isActive'];
            }
            unset($employee);
            respond(['employees' => $employees]);
        }

        if ($method === 'POST' && $employeeId === null) {
            requirePermission($currentUser, 'employees_create', 'employees_manage');
            verifyCsrfToken();
            $body = requestBody();
            $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
            $username = $body['username'] ?? null;
            $password = $body['password'] ?? null;
            $roleId = $body['roleId'] ?? null;

            if ($name === '' || strlen($name) > 160 || !validUsername($username) || !is_string($password) || $password === '' || strlen($password) > 1024 || !is_string($roleId)) {
                respond(['error' => 'Bitte prüfe Name, Benutzername, Rolle und Passwort.'], 422);
            }

            $roleCheck = $database->prepare('SELECT 1 FROM roles WHERE id = :id');
            $roleCheck->execute(['id' => $roleId]);
            if ($roleCheck->fetchColumn() === false) {
                respond(['error' => 'Die ausgewählte Rolle existiert nicht.'], 422);
            }
            if (roleGrantsPermission($database, $roleId, 'roles_manage') && !in_array('roles_manage', $currentUser['permissions'], true)) {
                respond(['error' => 'Nur Personen mit Rollenverwaltungsrecht dürfen diese Rolle zuweisen.'], 403);
            }

            $createEmployee = $database->prepare(
                'INSERT INTO users (name, username, password_hash, role_id) VALUES (:name, :username, :password_hash, :role_id)'
            );
            $createEmployee->execute([
                'name' => $name,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role_id' => $roleId,
            ]);
            $createdEmployeeId = (int) $database->lastInsertId();
            writeAuditLog($database, $currentUser, 'Erstellt', 'Mitgliedskonto', $createdEmployeeId);
            respond(['employee' => ['id' => $createdEmployeeId], 'message' => 'Mitgliedskonto wurde angelegt.'], 201);
        }

        if ($method === 'PATCH' && $employeeId !== null) {
            requirePermission($currentUser, 'employees_manage');
            verifyCsrfToken();
            $body = requestBody();
            $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
            $roleId = $body['roleId'] ?? null;
            $isActive = $body['isActive'] ?? null;

            if ($name === '' || strlen($name) > 160 || !is_string($roleId) || !is_bool($isActive)) {
                respond(['error' => 'Bitte prüfe Name, Rolle und Aktiv-Status.'], 422);
            }

            $roleCheck = $database->prepare('SELECT 1 FROM roles WHERE id = :id');
            $roleCheck->execute(['id' => $roleId]);
            if ($roleCheck->fetchColumn() === false) {
                respond(['error' => 'Die ausgewählte Rolle existiert nicht.'], 422);
            }
            if (roleGrantsPermission($database, $roleId, 'roles_manage') && !in_array('roles_manage', $currentUser['permissions'], true)) {
                respond(['error' => 'Nur Personen mit Rollenverwaltungsrecht dürfen diese Rolle zuweisen.'], 403);
            }
            if ($currentUser !== null && $employeeId === $currentUser['id']) {
                $currentRole = $database->prepare('SELECT role_id, is_active FROM users WHERE id = :id');
                $currentRole->execute(['id' => $employeeId]);
                $currentRecord = $currentRole->fetch();
                if ($currentRecord === false || $currentRecord['role_id'] !== $roleId || (bool) $currentRecord['is_active'] !== $isActive) {
                    respond(['error' => 'Du kannst deine eigene Rolle oder deinen Zugang hier nicht ändern.'], 409);
                }
            }
            $targetStatement = $database->prepare(
                'SELECT users.role_id, users.is_active FROM users WHERE users.id = :id'
            );
            $targetStatement->execute(['id' => $employeeId]);
            $target = $targetStatement->fetch();
            if ($target === false) {
                respond(['error' => 'Mitglied wurde nicht gefunden.'], 404);
            }
            if (
                (bool) $target['is_active']
                && roleGrantsPermission($database, $target['role_id'], 'roles_manage')
                && ($target['role_id'] !== $roleId || !$isActive)
            ) {
                $activeManagers = (int) $database->query(
                    "SELECT COUNT(*) FROM users
                     WHERE is_active = 1 AND role_id IN (
                         SELECT role_id FROM role_permissions WHERE permission = 'roles_manage'
                     )"
                )->fetchColumn();
                if ($activeManagers <= 1) {
                    respond(['error' => 'Die letzte aktive Person mit Rollenverwaltungsrecht kann nicht deaktiviert oder herabgestuft werden.'], 409);
                }
            }

            $updateEmployee = $database->prepare('UPDATE users SET name = :name, role_id = :role_id, is_active = :is_active WHERE id = :id');
            $updateEmployee->execute([
                'name' => $name,
                'role_id' => $roleId,
                'is_active' => $isActive ? 1 : 0,
                'id' => $employeeId,
            ]);
            if ($updateEmployee->rowCount() === 0) {
                $exists = $database->prepare('SELECT 1 FROM users WHERE id = :id');
                $exists->execute(['id' => $employeeId]);
                if ($exists->fetchColumn() === false) {
                    respond(['error' => 'Mitglied wurde nicht gefunden.'], 404);
                }
            }
            writeAuditLog($database, $currentUser, 'Bearbeitet', 'Mitgliedskonto', $employeeId);
            respond(['message' => 'Mitgliedskonto wurde aktualisiert.']);
        }
    }

    if (preg_match('#^/api/roles(?:/([A-Za-z0-9_-]+))?$#D', (string) $path, $roleMatch) === 1) {
        $roleId = $roleMatch[1] ?? null;

        if ($method === 'GET' && $roleId === null) {
            requirePermission($currentUser, 'roles_manage', 'employees_manage', 'employees_create', 'members_order');
            $canManageRolePermissions = $currentUser !== null && in_array('roles_manage', $currentUser['permissions'], true);
            $roles = $database->query('SELECT id, name, is_system AS isSystem, sort_order AS sortOrder FROM roles ORDER BY sort_order, name COLLATE NOCASE')->fetchAll();
            $permissions = $database->query('SELECT role_id, permission FROM role_permissions ORDER BY permission')->fetchAll();
            foreach ($roles as &$role) {
                $role['isSystem'] = (bool) $role['isSystem'];
                $role['sortOrder'] = (int) $role['sortOrder'];
                $role['assignable'] = $canManageRolePermissions || !roleGrantsPermission($database, $role['id'], 'roles_manage');
                $role['permissions'] = $canManageRolePermissions
                    ? array_values(array_map(
                        static fn (array $item): string => $item['permission'],
                        array_filter($permissions, static fn (array $item): bool => $item['role_id'] === $role['id'])
                    ))
                    : [];
            }
            unset($role);
            respond(['roles' => $roles, 'permissionKeys' => $canManageRolePermissions ? appPermissions() : []]);
        }

        if ($method === 'POST' && $roleId === null) {
            requirePermission($currentUser, 'roles_manage');
            verifyCsrfToken();
            $body = requestBody();
            $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
            $permissions = $body['permissions'] ?? null;

            if (strlen($name) < 2 || strlen($name) > 60 || !validRolePermissions($permissions)) {
                respond(['error' => 'Bitte prüfe Rollenname und Berechtigungen.'], 422);
            }

            $newRoleId = 'role_' . bin2hex(random_bytes(8));
            $sortOrder = (int) $database->query('SELECT COALESCE(MAX(sort_order), 0) + 100 FROM roles')->fetchColumn();
            $database->beginTransaction();
            try {
                $createRole = $database->prepare('INSERT INTO roles (id, name, sort_order) VALUES (:id, :name, :sort_order)');
                $createRole->execute(['id' => $newRoleId, 'name' => $name, 'sort_order' => $sortOrder]);
                $addPermission = $database->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (:role_id, :permission)');
                foreach ($permissions as $permission) {
                    $addPermission->execute(['role_id' => $newRoleId, 'permission' => $permission]);
                }
                $database->commit();
            } catch (Throwable $error) {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
                throw $error;
            }
            writeAuditLog($database, $currentUser, 'Erstellt', 'Rolle', $newRoleId);
            respond(['role' => ['id' => $newRoleId, 'name' => $name, 'permissions' => $permissions]], 201);
        }

        if ($method === 'PATCH' && $roleId !== null) {
            requirePermission($currentUser, 'roles_manage');
            verifyCsrfToken();
            $body = requestBody();
            $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
            $permissions = $body['permissions'] ?? null;

            if (strlen($name) < 2 || strlen($name) > 60 || !validRolePermissions($permissions)) {
                respond(['error' => 'Bitte prüfe Rollenname und Berechtigungen.'], 422);
            }

            $roleCheck = $database->prepare('SELECT is_system FROM roles WHERE id = :id');
            $roleCheck->execute(['id' => $roleId]);
            $roleRecord = $roleCheck->fetch();
            if ($roleRecord === false) {
                respond(['error' => 'Rolle wurde nicht gefunden.'], 404);
            }
            if ((int) $roleRecord['is_system'] === 1) {
                respond(['error' => 'Systemrollen können nicht bearbeitet werden. Lege dafür eine eigene Rolle an.'], 409);
            }

            $roleHadManagePermission = $database->prepare("SELECT 1 FROM role_permissions WHERE role_id = :id AND permission = 'roles_manage'");
            $roleHadManagePermission->execute(['id' => $roleId]);
            if ($roleHadManagePermission->fetchColumn() !== false && !in_array('roles_manage', $permissions, true)) {
                $roleUsers = $database->prepare('SELECT COUNT(*) FROM users WHERE role_id = :id AND is_active = 1');
                $roleUsers->execute(['id' => $roleId]);
                $activeRoleUsers = (int) $roleUsers->fetchColumn();
                $managers = (int) $database->query(
                    "SELECT COUNT(*) FROM users
                     WHERE is_active = 1 AND role_id IN (
                         SELECT role_id FROM role_permissions WHERE permission = 'roles_manage'
                     )"
                )->fetchColumn();
                if ($activeRoleUsers >= $managers) {
                    respond(['error' => 'Die letzte aktive Person mit Rollenverwaltungsrecht kann dieses Recht nicht entfernen.'], 409);
                }
            }

            $database->beginTransaction();
            try {
                $updateRole = $database->prepare('UPDATE roles SET name = :name WHERE id = :id');
                $updateRole->execute(['name' => $name, 'id' => $roleId]);
                $deletePermissions = $database->prepare('DELETE FROM role_permissions WHERE role_id = :id');
                $deletePermissions->execute(['id' => $roleId]);
                $addPermission = $database->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (:role_id, :permission)');
                foreach ($permissions as $permission) {
                    $addPermission->execute(['role_id' => $roleId, 'permission' => $permission]);
                }
                $database->commit();
            } catch (Throwable $error) {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
                throw $error;
            }
            writeAuditLog($database, $currentUser, 'Bearbeitet', 'Rolle', $roleId);
            respond(['message' => 'Rolle wurde aktualisiert.']);
        }

        if ($method === 'DELETE' && $roleId !== null) {
            requirePermission($currentUser, 'roles_manage');
            verifyCsrfToken();
            $roleCheck = $database->prepare('SELECT is_system FROM roles WHERE id = :id');
            $roleCheck->execute(['id' => $roleId]);
            $roleRecord = $roleCheck->fetch();
            if ($roleRecord === false) {
                respond(['error' => 'Rolle wurde nicht gefunden.'], 404);
            }
            if ((int) $roleRecord['is_system'] === 1) {
                respond(['error' => 'Systemrollen können nicht gelöscht werden.'], 409);
            }

            $assignedUsers = $database->prepare('SELECT COUNT(*) FROM users WHERE role_id = :id');
            $assignedUsers->execute(['id' => $roleId]);
            if ((int) $assignedUsers->fetchColumn() > 0) {
                respond(['error' => 'Diese Rolle wird noch Mitgliedern zugewiesen.'], 409);
            }

            $deleteRole = $database->prepare('DELETE FROM roles WHERE id = :id');
            $deleteRole->execute(['id' => $roleId]);
            writeAuditLog($database, $currentUser, 'Gelöscht', 'Rolle', $roleId);
            respond(['message' => 'Rolle wurde gelöscht.']);
        }
    }

    respond(['error' => 'Endpunkt nicht gefunden.'], 404);
} catch (PDOException $error) {
    if (str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
        respond(['error' => 'Der Benutzername oder Rollenname ist bereits vergeben.'], 409);
    }
    error_log((string) $error);
    respond(['error' => 'Der Server konnte die Anfrage nicht abschließen.'], 500);
} catch (Throwable $error) {
    error_log((string) $error);
    respond(['error' => 'Der Server konnte die Anfrage nicht abschließen.'], 500);
}
