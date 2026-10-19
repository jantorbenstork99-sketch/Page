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
        'name' => $user['name'],
        'username' => $user['username'],
        'roleId' => $user['role_id'],
        'roleName' => $user['role_name'],
        'permissions' => $permissionsStatement->fetchAll(PDO::FETCH_COLUMN),
    ];
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
    return intdiv($value, $factor) . '.' . str_pad((string) ($value % $factor), $fractionDigits, '0', STR_PAD_LEFT);
}

try {
    $database = appDatabase();
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

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

        $statement = $database->prepare('SELECT id, password_hash, is_active FROM users WHERE username = :username COLLATE NOCASE');
        $statement->execute(['username' => $username]);
        $userRecord = $statement->fetch();

        if ($userRecord === false || (int) $userRecord['is_active'] !== 1 || !password_verify($password, $userRecord['password_hash'])) {
            respond(['error' => 'Benutzername oder Passwort ist nicht korrekt.'], 401);
        }

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Could not rotate the session identifier.');
        }

        $_SESSION['user_id'] = (int) $userRecord['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond(['user' => publicUser($database, (int) $userRecord['id']), 'csrfToken' => csrfToken()]);
    }

    if ($path === '/api/logout' && $method === 'POST') {
        verifyCsrfToken();
        $_SESSION = [];

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Could not rotate the session identifier.');
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond(['user' => null, 'csrfToken' => csrfToken()]);
    }

    $sessionUserId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $currentUser = $sessionUserId === false || $sessionUserId === null ? null : publicUser($database, $sessionUserId);

    if ($path === '/api/notices' && $method === 'GET') {
        requireLogin($currentUser);
        $noticeSections = $database->query(
            'SELECT id, name, is_fixed AS isFixed FROM notice_sections ORDER BY is_fixed DESC, id'
        )->fetchAll();
        foreach ($noticeSections as &$noticeSection) {
            $noticeSection['id'] = (int) $noticeSection['id'];
            $noticeSection['isFixed'] = (bool) $noticeSection['isFixed'];
        }
        unset($noticeSection);
        $notices = $database->query(
            'SELECT id, section_id AS sectionId, author_id AS authorId, author_name AS authorName,
                    body, created_at AS createdAt, updated_at AS updatedAt
             FROM notices ORDER BY created_at DESC, id DESC'
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
        respond(['notice' => ['id' => (int) $database->lastInsertId()], 'message' => 'Nachricht wurde veröffentlicht.'], 201);
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
            respond(['message' => 'Nachricht wurde gespeichert.']);
        }

        if ($method === 'DELETE') {
            verifyCsrfToken();
            $deleteNotice = $database->prepare('DELETE FROM notices WHERE id = :id');
            $deleteNotice->execute(['id' => $noticeId]);
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
        respond(['section' => ['id' => (int) $database->lastInsertId(), 'name' => $name, 'isFixed' => false]], 201);
    }

    if (preg_match('#^/api/notice-sections/(\d+)$#D', (string) $path, $noticeSectionMatch) === 1) {
        requirePermission($currentUser, 'notice_sections_manage');
        $noticeSectionId = (int) $noticeSectionMatch[1];
        $sectionCheck = $database->prepare('SELECT is_fixed FROM notice_sections WHERE id = :id');
        $sectionCheck->execute(['id' => $noticeSectionId]);
        $sectionIsFixed = $sectionCheck->fetchColumn();
        if ($sectionIsFixed === false) {
            respond(['error' => 'Nachrichtenbereich wurde nicht gefunden.'], 404);
        }
        if ((int) $sectionIsFixed === 1) {
            respond(['error' => 'Der Bereich „Wichtige Informationen“ kann nicht umbenannt oder gelöscht werden.'], 409);
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
        $products = [];
        foreach ($productRows as $product) {
            $purchasePriceCents = (int) $product['purchasePriceCents'];
            $productMarkupBasisPoints = (int) $product['markupBasisPoints'];
            $product['id'] = (int) $product['id'];
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
        respond(['product' => ['id' => (int) $database->lastInsertId()], 'message' => 'Produkt wurde angelegt.'], 201);
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
        respond([
            'order' => ['id' => $orderId, 'total' => formatScaledInteger($totalCents, 2)],
            'message' => 'Bestellung wurde gespeichert.',
        ], 201);
    }

    if ($path === '/api/warehouses' && $method === 'GET') {
        requirePermission($currentUser, 'inventory_view', 'inventory_manage', 'warehouses_manage');
        $warehouseRows = $database->query('SELECT id, name, sort_order AS sortOrder FROM warehouses ORDER BY sort_order, name COLLATE NOCASE')->fetchAll();
        $entryRows = $database->query(
            'SELECT warehouse_id, user_id, locker_number AS lockerNumber, pin, contents
             FROM warehouse_entries'
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
             WHERE users.is_active = 1
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
        respond(['warehouse' => ['id' => (int) $database->lastInsertId(), 'name' => $name, 'sortOrder' => $sortOrder]], 201);
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
        respond(['message' => 'Rangfolge wurde gespeichert.']);
    }

    if (preg_match('#^/api/employees(?:/(\d+))?$#D', (string) $path, $employeeMatch) === 1) {
        $employeeId = isset($employeeMatch[1]) ? (int) $employeeMatch[1] : null;

        if ($method === 'GET' && $employeeId === null) {
            requirePermission($currentUser, 'employees_view', 'employees_manage');
            $employees = $database->query(
                'SELECT users.id, users.name, users.username, users.role_id AS roleId,
                        roles.name AS roleName, users.is_active AS isActive, users.created_at AS createdAt
                 FROM users JOIN roles ON roles.id = users.role_id ORDER BY users.name COLLATE NOCASE'
            )->fetchAll();
            foreach ($employees as &$employee) {
                $employee['id'] = (int) $employee['id'];
                $employee['isActive'] = (bool) $employee['isActive'];
            }
            unset($employee);
            respond(['employees' => $employees]);
        }

        if ($method === 'POST' && $employeeId === null) {
            requirePermission($currentUser, 'employees_manage');
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
            respond(['employee' => ['id' => (int) $database->lastInsertId()], 'message' => 'Mitgliedskonto wurde angelegt.'], 201);
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
            respond(['message' => 'Mitgliedskonto wurde aktualisiert.']);
        }
    }

    if (preg_match('#^/api/roles(?:/([A-Za-z0-9_-]+))?$#D', (string) $path, $roleMatch) === 1) {
        $roleId = $roleMatch[1] ?? null;

        if ($method === 'GET' && $roleId === null) {
            requirePermission($currentUser, 'roles_manage', 'employees_manage', 'members_order');
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
