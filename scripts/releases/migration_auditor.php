<?php

declare(strict_types=1);

/**
 * Retorna somente instruções SQL executáveis para a auditoria de release.
 *
 * Comentários comuns são descartados e literais/identificadores delimitados
 * são mascarados, preservando seus limites. Assim, palavras de política dentro
 * de strings não são confundidas com comandos. Comentários executáveis com
 * marcadores especiais do MySQL/MariaDB permanecem sujeitos à auditoria.
 *
 * O formato atual de migrations não usa DELIMITER nem corpos de procedures.
 * Caso esse formato seja introduzido, a auditoria falha de modo seguro até que
 * o lexer seja ampliado para compreender instruções compostas.
 *
 * @return list<string>
 */
function release_sql_executable_statements(string $sql): array
{
    $length = strlen($sql);
    $statement = '';
    $statements = [];
    $insideExecutableComment = false;

    $appendStatement = static function () use (&$statement, &$statements): void {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $statement));
        if ($normalized !== '') {
            $statements[] = $normalized;
        }
        $statement = '';
    };

    for ($offset = 0; $offset < $length;) {
        if ($insideExecutableComment && substr($sql, $offset, 2) === '*/') {
            $insideExecutableComment = false;
            $statement .= ' ';
            $offset += 2;
            continue;
        }

        $character = $sql[$offset];
        $next = $offset + 1 < $length ? $sql[$offset + 1] : '';

        if ($character === '/' && $next === '*') {
            $mysqlExecutable = substr($sql, $offset, 3) === '/*!';
            $mariaDbExecutable = strcasecmp(substr($sql, $offset, 4), '/*M!') === 0;

            if (!$insideExecutableComment && ($mysqlExecutable || $mariaDbExecutable)) {
                $insideExecutableComment = true;
                $statement .= ' ';
                $offset += $mariaDbExecutable ? 4 : 3;
                continue;
            }

            if (!$insideExecutableComment) {
                $commentEnd = strpos($sql, '*/', $offset + 2);
                if ($commentEnd === false) {
                    throw new RuntimeException('Comentário SQL de bloco não terminado.');
                }
                $statement .= ' ';
                $offset = $commentEnd + 2;
                continue;
            }
        }

        $startsDashComment = $character === '-'
            && $next === '-'
            && ($offset + 2 >= $length || ctype_space($sql[$offset + 2]));
        if ($startsDashComment || $character === '#') {
            $lineEnd = strpos($sql, "\n", $offset + ($startsDashComment ? 2 : 1));
            $statement .= ' ';
            $offset = $lineEnd === false ? $length : $lineEnd + 1;
            continue;
        }

        if (in_array($character, ["'", '"', '`'], true)) {
            $delimiter = $character;
            $statement .= ' ' . $delimiter . $delimiter . ' ';
            $offset++;
            $closed = false;

            while ($offset < $length) {
                $literalCharacter = $sql[$offset];

                if ($literalCharacter === '\\') {
                    $offset += min(2, $length - $offset);
                    continue;
                }

                if ($literalCharacter === $delimiter) {
                    if ($offset + 1 < $length && $sql[$offset + 1] === $delimiter) {
                        $offset += 2;
                        continue;
                    }
                    $offset++;
                    $closed = true;
                    break;
                }

                $offset++;
            }

            if (!$closed) {
                throw new RuntimeException('Literal SQL não terminado.');
            }
            continue;
        }

        if ($character === ';') {
            $appendStatement();
            $offset++;
            continue;
        }

        $statement .= ctype_space($character) ? ' ' : $character;
        $offset++;
    }

    if ($insideExecutableComment) {
        throw new RuntimeException('Comentário SQL executável não terminado.');
    }

    $appendStatement();

    return $statements;
}

/**
 * @return list<array{rule: string, statement: string}>
 */
function release_migration_policy_violations(string $sql): array
{
    $statements = release_sql_executable_statements($sql);
    $violations = [];
    $rules = [
        'DROP' => '/\bDROP\b/i',
        'TRUNCATE' => '/\bTRUNCATE\b/i',
        'RENAME' => '/\bRENAME\b/i',
        'DELETE FROM' => '/\bDELETE\s+FROM\b/i',
        'ALTER TABLE CHANGE/MODIFY' => '/\bALTER\s+TABLE\b.*\b(?:CHANGE|MODIFY)\b/i',
        'DELIMITER não suportado' => '/(?:^|\s)DELIMITER\s+/i',
    ];

    foreach ($statements as $statement) {
        foreach ($rules as $rule => $pattern) {
            if (preg_match($pattern, $statement) === 1) {
                $violations[] = ['rule' => $rule, 'statement' => $statement];
                break;
            }
        }

        if (release_statement_has_unsafe_not_null_addition($statement)) {
            $violations[] = ['rule' => 'ADD COLUMN NOT NULL sem default seguro', 'statement' => $statement];
        }
    }

    return $violations;
}

function release_statement_has_unsafe_not_null_addition(string $statement): bool
{
    preg_match_all('/\bADD\s+COLUMN\b/i', $statement, $matches, PREG_OFFSET_CAPTURE);
    $additions = $matches[0] ?? [];

    foreach ($additions as $position => $addition) {
        $start = (int) $addition[1];
        $end = isset($additions[$position + 1]) ? (int) $additions[$position + 1][1] : strlen($statement);
        $definition = substr($statement, $start, $end - $start);

        if (preg_match('/\bNOT\s+NULL\b/i', $definition) !== 1) {
            continue;
        }

        if (preg_match('/\bDEFAULT\s+NULL\b/i', $definition) === 1
            || preg_match('/\bDEFAULT\s+(?!NULL\b)\S+/i', $definition) !== 1) {
            return true;
        }
    }

    return false;
}

function release_migration_is_compatible(string $sql): bool
{
    return release_migration_policy_violations($sql) === [];
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    if ($argc !== 2 || !is_file($argv[1])) {
        fwrite(STDERR, "Uso: php migration_auditor.php ARQUIVO.sql\n");
        exit(2);
    }

    try {
        $violations = release_migration_policy_violations((string) file_get_contents($argv[1]));
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Falha segura na análise SQL: ' . $exception->getMessage() . PHP_EOL);
        exit(2);
    }

    if ($violations !== []) {
        fwrite(STDERR, 'Operação incompatível: ' . $violations[0]['rule'] . PHP_EOL);
        exit(1);
    }

    exit(0);
}
