<?php
/** Development-only exact PHP literal extraction for the bundled language catalog. */
declare(strict_types=1);

$result = [];
foreach (array_slice($argv, 1) as $path) {
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING ||
            !preg_match('/[آ-ی]/u', $token[1])) {
            continue;
        }
        // token_get_all guarantees this token is a complete, non-interpolated string literal.
        $value = eval('return ' . $token[1] . ';');
        $result[$value][] = $path . ':' . $token[2];
    }
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
