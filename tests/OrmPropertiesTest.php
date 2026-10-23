<?php
/**
 * tests/OrmPropertiesTest.php
 *
 * Que el ORM no cree propiedades sobre la marcha.
 *
 * PHP 8.2 marcó como obsoleta la creación de propiedades dinámicas: cada
 * `$this->algo = ...` sobre una propiedad no declarada emite una deprecación
 * en **cada** consulta. En la aplicación se quedaba en el log (el nivel de
 * errores la oculta); en la suite, donde un aviso es un fallo, salió en cuanto
 * el ORM se ejecutó contra la base de datos. Eran siete, una de ellas por una
 * errata (`$annnotations` en vez de `$annotations`).
 *
 * Esta prueba las busca sin ejecutar nada: recorre `include/class.orm.php`,
 * se queda con cada `$this->propiedad = ...` y comprueba con `property_exists`
 * que la propiedad exista en la clase o en un padre. Los accesos calculados
 * (`$this->{$campo}`) no se miran: ésos no crean propiedades.
 *
 * Para saber a qué clase pertenece cada línea se usan los rangos que da la
 * reflexión (`getStartLine()`/`getEndLine()`). Contar llaves a mano no vale:
 * las cadenas con interpolación (`"{$variable}"`) meten llaves que no abren ni
 * cierran clases, y el conteo se desbalancea.
 */

require_once INCLUDE_DIR . 'class.orm.php';

class OrmPropertiesTest extends UnitTestCase {

    /**
     * Archivos que se analizan. Son los que forman el ORM y su base; ahí una
     * propiedad dinámica es siempre un descuido, no una técnica.
     */
    private static $files = array('class.orm.php', 'class.orm.select.php',
        'class.util.php');

    /** Operadores que escriben en la propiedad. */
    private static $writes = array('=', '+=', '-=', '*=', '/=', '.=', '%=',
        '&=', '|=', '^=', '<<=', '>>=', '??=', '++', '--');

    public function testElArchivoDelOrmSeAnalizaEntero(): void {
        $classes = $this->classes(INCLUDE_DIR . 'class.orm.php');

        // Si el analizador deja de ver clases, las demás pruebas pasarían
        // por el motivo equivocado
        $this->assertGreaterThan(25, count($classes));
        $this->assertArrayHasKey('QuerySet', $classes);
        $this->assertArrayHasKey('VerySimpleModel', $classes);
        $this->assertArrayHasKey('MySqlExecutor', $classes);
    }

    public function testNoHayPropiedadesSinDeclarar(): void {
        $writes = array();
        foreach (self::$files as $file)
            $writes = array_merge($writes, $this->writes(INCLUDE_DIR . $file));

        $faltan = array();

        // Control de que se está mirando algo: el ORM escribe mucho
        $this->assertGreaterThan(50, count($writes));

        foreach ($writes as $write) {
            if (!property_exists($write['class'], $write['prop']))
                $faltan[] = sprintf('%s::$%s (línea %d)', $write['class'],
                    $write['prop'], $write['line']);
        }

        $faltan = array_values(array_unique($faltan));
        sort($faltan);
        $this->assertSame(array(), $faltan,
            'propiedades dinámicas (PHP 8.2 las marca obsoletas): '
            . implode(', ', $faltan));
    }

    public function testLasPropiedadesConocidasSeVen(): void {
        // Control positivo: si `property_exists()` no viera lo declarado, la
        // prueba anterior pasaría por el motivo equivocado.
        $this->assertTrue(property_exists('QuerySet', '_iterator'));
        $this->assertTrue(property_exists('ModelInstanceManager', 'annotations'));
        $this->assertTrue(property_exists('SqlFunction', 'args'));
        $this->assertTrue(property_exists('MySqlExecutor', 'types'));
        $this->assertTrue(property_exists('ModelMeta', 'parent'));
    }

    /* ------------------------------------------------------------------ */
    /* Análisis                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Clases declaradas en el archivo del ORM, con su rango de líneas.
     *
     * @return array  array('Clase' => array(inicio, fin))
     */
    private function classes($path) {
        $file = realpath($path);
        $classes = array();

        foreach (get_declared_classes() as $class) {
            $reflection = new ReflectionClass($class);
            if (realpath((string) $reflection->getFileName()) !== $file)
                continue;
            // Las evaluadas con eval() no tienen líneas fiables
            if ($reflection->getStartLine() < 1)
                continue;

            $classes[$reflection->getShortName()] =
                array($reflection->getStartLine(), $reflection->getEndLine());
        }

        return $classes;
    }

    /**
     * Cada escritura de `$this->propiedad` en el archivo, con la clase a la
     * que pertenece.
     *
     * @return array  lista de array('class', 'prop', 'line')
     */
    private function writes($path) {
        $classes = $this->classes($path);
        $tokens = token_get_all(file_get_contents($path));
        $tokens = array_values(array_filter($tokens, function ($token) {
            return !is_array($token)
                || ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT);
        }));

        $writes = array();
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_VARIABLE
                    || $token[1] !== '$this')
                continue;

            if (!$this->isToken($tokens[$i + 1] ?? null, T_OBJECT_OPERATOR))
                continue;

            $name = $tokens[$i + 2] ?? null;
            if (!is_array($name) || $name[0] !== T_STRING)
                // `$this->{$campo}` es calculado y no crea propiedades
                continue;

            // Puede haber espacios entre el nombre y el operador
            $j = $this->skipSpace($tokens, $i + 3);

            // `$this->lista[]` también inicializa la propiedad: se salta el
            // índice antes de mirar el operador
            while (($tokens[$j] ?? null) === '[')
                $j = $this->skipSpace($tokens, $this->skipBrackets($tokens, $j));

            if (!$this->isWrite($tokens[$j] ?? null))
                continue;

            $class = $this->classAt($classes, $token[2]);
            if ($class === null)
                continue;

            $writes[] = array('class' => $class, 'prop' => $name[1],
                'line' => $token[2]);
        }

        return $writes;
    }

    /** Clase cuyo rango contiene la línea (la más pequeña si hay varias). */
    private function classAt(array $classes, $line) {
        $found = null;
        $size = null;

        foreach ($classes as $name => $range) {
            if ($line < $range[0] || $line > $range[1])
                continue;

            $length = $range[1] - $range[0];
            if ($size === null || $length < $size) {
                $found = $name;
                $size = $length;
            }
        }

        return $found;
    }

    private function isWrite($token) {
        if (is_array($token))
            return in_array($token[0], array(T_CONCAT_EQUAL, T_PLUS_EQUAL,
                T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL,
                T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
                T_COALESCE_EQUAL, T_INC, T_DEC));

        return in_array($token, self::$writes, true) && $token !== '==';
    }

    /** Primer token que no es un espacio. */
    private function skipSpace(array $tokens, $i) {
        $count = count($tokens);

        while ($i < $count && is_array($tokens[$i])
                && $tokens[$i][0] === T_WHITESPACE)
            $i++;

        return $i;
    }

    private function skipBrackets(array $tokens, $i) {
        $depth = 0;
        $count = count($tokens);

        for (; $i < $count; $i++) {
            if ($tokens[$i] === '[')
                $depth++;
            elseif ($tokens[$i] === ']') {
                $depth--;
                if ($depth === 0)
                    return $i + 1;
            }
        }

        return $i;
    }

    private function isToken($token, $id) {
        return is_array($token) && $token[0] === $id;
    }
}
