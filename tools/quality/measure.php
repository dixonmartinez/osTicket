<?php
/**
 * tools/quality/measure.php
 *
 * Medidor de funciones por archivo, usado por `make metrics` y probado en
 * `tests/CodeMeasureTest.php`.
 *
 * Por qué existe: contar `{` y `}` sobre el texto de un archivo es inútil en
 * PHP. Una llave dentro de una cadena, de un heredoc, de una expresión
 * regular o de una interpolación (`"{$var}"`) descuadra la cuenta y hace
 * parecer gigantes funciones de veinte líneas, o desaparecer funciones
 * grandes. Se recorre la lista de tokens (`token_get_all`), donde una cadena
 * es un solo token, y se lleva una pila para poder medir funciones anidadas
 * (cierres dentro de un método) sin perder la de fuera.
 *
 * Lo que mide es lo que se ve en el archivo: desde la línea del `function`
 * hasta la línea de la llave que lo cierra, incluidas las líneas en blanco.
 */
class CodeMeasure {

    /**
     * @return array<int, array{name:string, lines:int, line:int, depth:int}>
     */
    static function functionsIn($source) {
        if (!function_exists('token_get_all'))
            return [];

        $tokens = @token_get_all($source);
        if (!is_array($tokens))
            return [];

        $out = [];
        $stack = [];      // funciones abiertas o pendientes de llave
        $depth = 0;       // profundidad de llaves
        $line = 1;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                switch ($token[0]) {
                case T_FUNCTION:
                    // `use function Foo\bar;` no declara nada
                    if (self::previousSignificant($tokens, $i) === T_USE)
                        break;

                    $stack[] = [
                        'name' => self::functionName($tokens, $i),
                        'line' => $token[2],
                        'depth' => null,
                    ];
                    break;

                // Interpolación: `"{$var}"` y `"${var}"` abren llave
                case T_CURLY_OPEN:
                case T_DOLLAR_OPEN_CURLY_BRACES:
                    $depth++;
                    break;
                }

                $line += substr_count($token[1], "\n");
                continue;
            }

            if ($token === '{') {
                $depth++;
                if ($stack && end($stack)['depth'] === null)
                    $stack[count($stack) - 1]['depth'] = $depth;
            }
            elseif ($token === '}') {
                if ($stack && end($stack)['depth'] === $depth) {
                    $fn = array_pop($stack);
                    $out[] = [
                        'name' => $fn['name'],
                        'lines' => $line - $fn['line'] + 1,
                        'line' => $fn['line'],
                        'depth' => $fn['depth'],
                    ];
                }

                $depth = max(0, $depth - 1);
            }
            elseif ($token === ';') {
                // Declaración sin cuerpo (interfaz, método abstracto):
                // no se puede medir y no debe tapar a la siguiente función
                if ($stack && end($stack)['depth'] === null)
                    array_pop($stack);
            }

            $line += substr_count($token, "\n");
        }

        return $out;
    }

    /** Nombre de la función, o 'closure' si es anónima. */
    private static function functionName(array $tokens, $i) {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT,
                    T_DOC_COMMENT], true))
                continue;
            // `function &nombre()`
            if ($t === '&')
                continue;
            if (is_array($t) && $t[0] === T_STRING)
                return $t[1];
            return 'closure';
        }
        return 'closure';
    }

    private static function previousSignificant(array $tokens, $i) {
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT,
                    T_DOC_COMMENT], true))
                continue;
            return is_array($t) ? $t[0] : $t;
        }
        return null;
    }
}
