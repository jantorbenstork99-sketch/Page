<?php
declare(strict_types=1);

require_once __DIR__ . '/src/database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Skript kann nur über die Kommandozeile ausgeführt werden.\n");
    exit(1);
}

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);

    if ($value === false) {
        throw new RuntimeException('Eingabe konnte nicht gelesen werden.');
    }

    return trim($value);
}

function promptPassword(string $label): string
{
    fwrite(STDOUT, $label);
    $isTerminal = function_exists('posix_isatty') && posix_isatty(STDIN);

    if ($isTerminal) {
        exec('stty -echo < /dev/tty', result_code: $disableEchoStatus);
        if ($disableEchoStatus !== 0) {
            throw new RuntimeException('Passworteingabe konnte nicht geschützt werden.');
        }
    }

    try {
        $password = fgets(STDIN);
    } finally {
        if ($isTerminal) {
            exec('stty echo < /dev/tty', result_code: $restoreEchoStatus);
            fwrite(STDOUT, PHP_EOL);
            if ($restoreEchoStatus !== 0) {
                throw new RuntimeException('Terminal-Eingabe konnte nicht wiederhergestellt werden.');
            }
        }
    }

    if ($password === false) {
        throw new RuntimeException('Passworteingabe konnte nicht gelesen werden.');
    }

    return rtrim($password, "\r\n");
}

try {
    $username = prompt('Benutzername (3–32 Zeichen, Buchstaben/Ziffern/._-): ');

    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,31}$/', $username)) {
        throw new InvalidArgumentException('Bitte gib einen gültigen Benutzernamen ein.');
    }

    $name = prompt('Anzeigename [Benutzername]: ');
    $name = $name === '' ? $username : $name;
    $database = appDatabase();
    $roleId = prompt('Rollen-ID (admin/employee) [employee]: ');
    $roleId = $roleId === '' ? 'employee' : $roleId;
    $roleStatement = $database->prepare('SELECT id FROM roles WHERE id = :id');
    $roleStatement->execute(['id' => $roleId]);
    if ($roleStatement->fetchColumn() === false) {
        throw new InvalidArgumentException('Diese Rollen-ID existiert nicht.');
    }

    $password = promptPassword('Passwort (beliebige Zeichen, 1–1024 Bytes): ');
    if ($password === '' || strlen($password) > 1024) {
        throw new InvalidArgumentException('Das Passwort darf nicht leer sein und höchstens 1024 Bytes lang sein.');
    }

    $confirmation = promptPassword('Passwort wiederholen: ');
    if (!hash_equals($password, $confirmation)) {
        throw new InvalidArgumentException('Die Passwörter stimmen nicht überein.');
    }

    $statement = $database->prepare('INSERT INTO users (name, username, password_hash, role_id) VALUES (:name, :username, :password_hash, :role_id)');
    $statement->execute([
        'name' => $name,
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role_id' => $roleId,
    ]);

    fwrite(STDOUT, "Benutzerkonto {$username} mit Rolle {$roleId} wurde angelegt.\n");
} catch (PDOException $error) {
    if (str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
        fwrite(STDERR, "Der Benutzername ist bereits vergeben.\n");
    } else {
        fwrite(STDERR, "Datenbankfehler: {$error->getMessage()}\n");
    }
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
