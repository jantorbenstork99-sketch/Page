<?php
declare(strict_types=1);

function appPermissions(): array
{
    return [
        'employees_view',
        'employees_create',
        'employees_manage',
        'roles_manage',
        'members_order',
        'inventory_view',
        'inventory_manage',
        'warehouses_manage',
        'products_view',
        'products_manage',
        'product_markup_manage',
        'orders_manage',
        'notice_sections_manage',
        'logs_view',
        'logs_delete',
        'cash_manage',
        'balance_view',
    ];
}

function appDatabase(): PDO
{
    $databasePath = getenv('APP_DB_PATH') ?: dirname(__DIR__) . '/data/app.sqlite';
    $directory = dirname($databasePath);

    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the database directory.');
    }

    $database = new PDO('sqlite:' . $databasePath, options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec(
        'CREATE TABLE IF NOT EXISTS roles (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL COLLATE NOCASE UNIQUE,
            is_system INTEGER NOT NULL DEFAULT 0 CHECK (is_system IN (0, 1)),
            sort_order INTEGER NOT NULL DEFAULT 100
        )'
    );
    $roleColumns = $database->query('PRAGMA table_info(roles)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('sort_order', $roleColumns, true)) {
        $database->exec('ALTER TABLE roles ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 100');
        $legacyRoles = $database->query(
            "SELECT id FROM roles
             ORDER BY CASE id WHEN 'admin' THEN 0 WHEN 'employee' THEN 1 ELSE 2 END, name COLLATE NOCASE"
        )->fetchAll(PDO::FETCH_COLUMN);
        $setRoleOrder = $database->prepare('UPDATE roles SET sort_order = :sort_order WHERE id = :id');
        foreach ($legacyRoles as $index => $roleId) {
            $setRoleOrder->execute(['sort_order' => $index * 100, 'id' => $roleId]);
        }
    }
    $database->exec(
        'CREATE TABLE IF NOT EXISTS role_permissions (
            role_id TEXT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
            permission TEXT NOT NULL,
            PRIMARY KEY (role_id, permission)
        )'
    );
    $database->exec("INSERT OR IGNORE INTO roles (id, name, is_system, sort_order) VALUES ('admin', 'Administrator', 1, 0), ('employee', 'Mitglied', 1, 100)");
    $database->exec("UPDATE roles SET name = 'Mitglied' WHERE id = 'employee' AND name = 'Mitarbeiter'");
    $database->exec("DELETE FROM role_permissions WHERE permission = 'suppliers_manage'");

    $insertPermission = $database->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission) VALUES (:role_id, :permission)');
    foreach (appPermissions() as $permission) {
        $insertPermission->execute(['role_id' => 'admin', 'permission' => $permission]);
    }
    $database->exec(
        "INSERT OR IGNORE INTO role_permissions (role_id, permission)
         SELECT role_id, 'employees_create' FROM role_permissions WHERE permission = 'employees_manage'"
    );
    $employeePermissionCount = (int) $database->query("SELECT COUNT(*) FROM role_permissions WHERE role_id = 'employee'")->fetchColumn();
    if ($employeePermissionCount === 0) {
        foreach (['employees_view', 'inventory_view'] as $permission) {
            $insertPermission->execute(['role_id' => 'employee', 'permission' => $permission]);
        }
    }

    $database->exec(
        'CREATE TABLE IF NOT EXISTS warehouses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL COLLATE NOCASE UNIQUE,
            sort_order INTEGER NOT NULL DEFAULT 100,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $tables = $database->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    if ($tables === false) {
        $database->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                username TEXT NOT NULL COLLATE NOCASE UNIQUE,
                password_hash TEXT NOT NULL,
                role_id TEXT NOT NULL REFERENCES roles(id),
                is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    } else {
        $columns = $database->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('role_id', $columns, true) || !in_array('is_active', $columns, true) || in_array('email', $columns, true)) {
            migrateUsers($database, $columns);
        }
    }

    $database->exec(
        'CREATE TABLE IF NOT EXISTS access_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code_hash TEXT NOT NULL UNIQUE,
            code_type TEXT NOT NULL CHECK (code_type IN (\'access\', \'seller\')),
            group_name TEXT NOT NULL DEFAULT \'Unsortiert\',
            user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            revoked_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $accessCodeColumns = $database->query('PRAGMA table_info(access_codes)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('group_name', $accessCodeColumns, true)) {
        $database->exec("ALTER TABLE access_codes ADD COLUMN group_name TEXT NOT NULL DEFAULT 'Unsortiert'");
    }
    $database->exec(
        'CREATE TABLE IF NOT EXISTS auction_profiles (
            user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            seller_code_id INTEGER REFERENCES access_codes(id) ON DELETE SET NULL
        )'
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS auctions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            seller_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            title TEXT NOT NULL,
            category TEXT NOT NULL,
            description TEXT NOT NULL,
            starting_price_cents INTEGER NOT NULL CHECK (starting_price_cents > 0),
            current_bid_cents INTEGER NOT NULL CHECK (current_bid_cents > 0),
            highest_bidder_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            is_approved INTEGER NOT NULL DEFAULT 1 CHECK (is_approved IN (0, 1)),
            ends_at TEXT,
            status TEXT NOT NULL DEFAULT \'active\' CHECK (status IN (\'active\', \'closed\')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $auctionColumns = $database->query('PRAGMA table_info(auctions)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('is_approved', $auctionColumns, true)) {
        $database->exec('ALTER TABLE auctions ADD COLUMN is_approved INTEGER NOT NULL DEFAULT 1 CHECK (is_approved IN (0, 1))');
    }
    if (!in_array('ends_at', $auctionColumns, true)) {
        $database->exec('ALTER TABLE auctions ADD COLUMN ends_at TEXT');
    }
    $database->exec(
        'CREATE TABLE IF NOT EXISTS auction_bids (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            auction_id INTEGER NOT NULL REFERENCES auctions(id) ON DELETE CASCADE,
            bidder_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            amount_cents INTEGER NOT NULL CHECK (amount_cents > 0),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS user_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            auction_id INTEGER REFERENCES auctions(id) ON DELETE SET NULL,
            message TEXT NOT NULL,
            is_read INTEGER NOT NULL DEFAULT 0 CHECK (is_read IN (0, 1)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    $database->exec(
        'CREATE TABLE IF NOT EXISTS notice_sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL COLLATE NOCASE UNIQUE,
            is_fixed INTEGER NOT NULL DEFAULT 0 CHECK (is_fixed IN (0, 1)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS notices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            section_id INTEGER NOT NULL REFERENCES notice_sections(id) ON DELETE CASCADE,
            author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            author_name TEXT NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $database->exec('DELETE FROM notice_sections WHERE is_fixed = 1');

    $database->exec(
        'CREATE TABLE IF NOT EXISTS warehouse_entries (
            warehouse_id INTEGER NOT NULL REFERENCES warehouses(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            locker_number TEXT NOT NULL DEFAULT \'\',
            pin TEXT NOT NULL DEFAULT \'\',
            contents TEXT NOT NULL DEFAULT \'\',
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (warehouse_id, user_id)
        )'
    );
    $entryColumns = $database->query('PRAGMA table_info(warehouse_entries)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('locker_number', $entryColumns, true)) {
        $database->exec("ALTER TABLE warehouse_entries ADD COLUMN locker_number TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('pin', $entryColumns, true)) {
        $database->exec("ALTER TABLE warehouse_entries ADD COLUMN pin TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('contents', $entryColumns, true)) {
        $database->exec("ALTER TABLE warehouse_entries ADD COLUMN contents TEXT NOT NULL DEFAULT ''");
        if (in_array('content', $entryColumns, true)) {
            $database->exec('UPDATE warehouse_entries SET contents = content');
        }
    }

    $database->exec(
        'CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            purchase_price_cents INTEGER NOT NULL CHECK (purchase_price_cents >= 0),
            markup_basis_points INTEGER NOT NULL DEFAULT 2000 CHECK (markup_basis_points BETWEEN 0 AND 100000),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS product_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            markup_basis_points INTEGER NOT NULL DEFAULT 2000 CHECK (markup_basis_points BETWEEN 0 AND 100000),
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $database->exec('INSERT OR IGNORE INTO product_settings (id, markup_basis_points) VALUES (1, 2000)');
    $productColumns = $database->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('markup_basis_points', $productColumns, true)) {
        $database->exec('ALTER TABLE products ADD COLUMN markup_basis_points INTEGER NOT NULL DEFAULT 2000');
        $database->exec(
            'UPDATE products SET markup_basis_points = (SELECT markup_basis_points FROM product_settings WHERE id = 1)'
        );
    }
    $database->exec(
        'CREATE TABLE IF NOT EXISTS raw_materials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL COLLATE NOCASE,
            unit TEXT NOT NULL COLLATE NOCASE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (name, unit),
            CHECK (length(trim(name)) BETWEEN 1 AND 160),
            CHECK (length(trim(unit)) BETWEEN 1 AND 24)
        )'
    );
    $recipeTableExists = $database->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'product_recipe_components'"
    )->fetchColumn() !== false;
    if ($recipeTableExists) {
        $recipeColumns = $database->query('PRAGMA table_info(product_recipe_components)')->fetchAll(PDO::FETCH_COLUMN, 1);
    } else {
        $recipeColumns = [];
    }
    if (!in_array('raw_material_id', $recipeColumns, true)) {
        $database->beginTransaction();
        try {
            $legacyRecipes = [];
            if ($recipeTableExists) {
                $legacyRecipes = $database->query(
                    'SELECT recipe.product_id, component.name, recipe.quantity_milliunits, recipe.unit
                     FROM product_recipe_components AS recipe
                     JOIN products AS component ON component.id = recipe.component_product_id'
                )->fetchAll();
                $database->exec('ALTER TABLE product_recipe_components RENAME TO product_recipe_components_legacy');
            }
            $database->exec(
                'CREATE TABLE product_recipe_components (
                    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                    raw_material_id INTEGER NOT NULL REFERENCES raw_materials(id) ON DELETE RESTRICT,
                    quantity_milliunits INTEGER NOT NULL CHECK (quantity_milliunits > 0),
                    PRIMARY KEY (product_id, raw_material_id)
                )'
            );
            $findMaterial = $database->prepare(
                'SELECT id FROM raw_materials WHERE name = :name COLLATE NOCASE AND unit = :unit COLLATE NOCASE'
            );
            $createMaterial = $database->prepare(
                'INSERT INTO raw_materials (name, unit) VALUES (:name, :unit)'
            );
            $createRecipe = $database->prepare(
                'INSERT INTO product_recipe_components (product_id, raw_material_id, quantity_milliunits)
                 VALUES (:product_id, :raw_material_id, :quantity)'
            );
            foreach ($legacyRecipes as $legacyRecipe) {
                $findMaterial->execute(['name' => $legacyRecipe['name'], 'unit' => $legacyRecipe['unit']]);
                $materialId = $findMaterial->fetchColumn();
                if ($materialId === false) {
                    $createMaterial->execute(['name' => $legacyRecipe['name'], 'unit' => $legacyRecipe['unit']]);
                    $materialId = $database->lastInsertId();
                }
                $createRecipe->execute([
                    'product_id' => $legacyRecipe['product_id'],
                    'raw_material_id' => $materialId,
                    'quantity' => $legacyRecipe['quantity_milliunits'],
                ]);
            }
            if ($recipeTableExists) {
                $database->exec('DROP TABLE product_recipe_components_legacy');
            }
            $database->commit();
        } catch (Throwable $recipeMigrationError) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $recipeMigrationError;
        }
    }

    $database->exec(
        'CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_name TEXT NOT NULL,
            customer_phone TEXT NOT NULL,
            grouping_name TEXT NOT NULL,
            total_cents INTEGER NOT NULL CHECK (total_cents >= 0),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
            product_id INTEGER REFERENCES products(id) ON DELETE SET NULL,
            product_name TEXT NOT NULL,
            quantity INTEGER NOT NULL CHECK (quantity BETWEEN 1 AND 9999),
            unit_price_cents INTEGER NOT NULL CHECK (unit_price_cents >= 0),
            line_total_cents INTEGER NOT NULL CHECK (line_total_cents >= 0)
        )'
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER,
            actor_name TEXT NOT NULL,
            action TEXT NOT NULL,
            resource TEXT NOT NULL,
            resource_id TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS cash_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transaction_date TEXT NOT NULL,
            name TEXT NOT NULL,
            purpose TEXT NOT NULL DEFAULT \'\',
            amount_cents INTEGER NOT NULL CHECK (amount_cents > 0),
            transaction_type TEXT NOT NULL CHECK (transaction_type IN (\'income\', \'expense\')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $cashTransactionColumns = $database->query('PRAGMA table_info(cash_transactions)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('purpose', $cashTransactionColumns, true)) {
        $database->exec("ALTER TABLE cash_transactions ADD COLUMN purpose TEXT NOT NULL DEFAULT ''");
    }

    if (file_exists($databasePath) && !chmod($databasePath, 0600)) {
        throw new RuntimeException('Could not secure the database file permissions.');
    }

    return $database;
}

function migrateUsers(PDO $database, array $columns): void
{
    $users = $database->query('SELECT * FROM users ORDER BY id')->fetchAll();
    $database->beginTransaction();

    try {
        $database->exec('DROP TABLE IF EXISTS users_migrating');
        $database->exec(
            'CREATE TABLE users_migrating (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                username TEXT NOT NULL COLLATE NOCASE UNIQUE,
                password_hash TEXT NOT NULL,
                role_id TEXT NOT NULL REFERENCES roles(id),
                is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $insert = $database->prepare(
            'INSERT INTO users_migrating (id, name, username, password_hash, role_id, is_active, created_at)
             VALUES (:id, :name, :username, :password_hash, :role_id, :is_active, :created_at)'
        );
        $usedUsernames = [];
        $roleExists = $database->prepare('SELECT 1 FROM roles WHERE id = :id');

        foreach ($users as $user) {
            $id = (int) $user['id'];
            $name = (string) ($user['name'] ?? '');
            $username = (string) ($user['username'] ?? '');
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,31}$/', $username)) {
                $source = isset($user['email']) ? explode('@', (string) $user['email'], 2)[0] : $name;
                $username = strtolower(trim(preg_replace('/[^a-z0-9._-]+/i', '_', $source) ?? '', '._-'));
                if (strlen($username) < 3) {
                    $username = 'user';
                }
            }

            $base = substr($username, 0, 32);
            $username = $base;
            $suffix = '_' . $id;
            while (isset($usedUsernames[strtolower($username)])) {
                $username = substr($base, 0, 32 - strlen($suffix)) . $suffix;
            }
            $usedUsernames[strtolower($username)] = true;

            $legacyRole = (string) ($user['role'] ?? '');
            $candidateRoleId = (string) ($user['role_id'] ?? '');
            $roleExists->execute(['id' => $candidateRoleId]);
            $roleId = $roleExists->fetchColumn() !== false
                ? $candidateRoleId
                : ($legacyRole === 'admin' ? 'admin' : 'employee');
            $insert->execute([
                'id' => $id,
                'name' => $name !== '' ? $name : $username,
                'username' => $username,
                'password_hash' => (string) ($user['password_hash'] ?? ''),
                'role_id' => $roleId,
                'is_active' => (int) ($user['is_active'] ?? 1),
                'created_at' => (string) ($user['created_at'] ?? date('Y-m-d H:i:s')),
            ]);
        }

        $database->exec('DROP TABLE users');
        $database->exec('ALTER TABLE users_migrating RENAME TO users');
        $database->commit();
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $error;
    }
}
