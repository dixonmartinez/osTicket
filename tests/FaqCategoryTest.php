<?php
/**
 * tests/FaqCategoryTest.php
 *
 * El listado de categorías de la base de conocimiento.
 *
 * Origen: una captura del usuario con «las faq no se muestran cuando estoy en
 * todas las categorías». La pantalla enseñaba «NO FAQs found» mientras el
 * desplegable de al lado contaba 33 artículos en 6 categorías.
 *
 * La causa no era la consulta sino el dato: `Category::update()` guardaba
 * `0` para una categoría de primer nivel y el listado las busca con
 * `category_pid IS NULL` (que es lo que dice el esquema y lo que usan
 * `findIdByName()` y el JOIN del padre). Una categoría editada —como la de la
 * captura— desaparecía del listado.
 *
 * Se arregla por los dos lados: se escribe NULL y se leen las dos formas, para
 * que las instalaciones que ya tienen ceros no necesiten migración.
 *
 * `Category` no se puede cargar en las pruebas unitarias (arrastra la base de
 * datos), así que esto se comprueba leyendo el código, como el resto de
 * verdades de pantalla que hay en `tests/`.
 */

class FaqCategoryTest extends UnitTestCase {

    private function fichero($ruta): string {
        return (string) file_get_contents(ROOT_DIR . '/' . $ruta);
    }

    /** El cuerpo de un método, desde su firma hasta la llave que lo cierra. */
    private function metodo(string $clase, string $nombre): string {
        $src = $this->fichero($clase);
        $pos = strpos($src, 'function ' . $nombre . '(');
        if ($pos === false)
            return '';

        $desde = strrpos(substr($src, 0, $pos), "\n");
        $llaves = 0;
        $abierto = false;
        for ($i = $pos; $i < strlen($src); $i++) {
            if ($src[$i] === '{') {
                $llaves++;
                $abierto = true;
            }
            elseif ($src[$i] === '}') {
                $llaves--;
                if ($abierto && $llaves === 0)
                    return substr($src, $desde, $i - $desde + 1);
            }
        }
        return '';
    }

    /** El listado dice qué es «primer nivel» en un solo sitio. */
    public function testElListadoUsaElCriterioDePrimerNivel(): void {
        $vista = $this->fichero('include/staff/faq-categories.inc.php');

        $this->assertStringContainsString('->filter(Category::topLevel())', $vista,
            'el listado de categorías tiene que usar el criterio único de '
            . 'primer nivel, no un `IS NULL` suelto');
        $this->assertFalse(strpos($vista, "'category_pid__isnull'") !== false,
            'el `IS NULL` directo deja fuera las categorías guardadas con 0');
    }

    /** El criterio acepta las dos formas del dato. */
    public function testElCriterioAceptaNuloYCero(): void {
        $cuerpo = $this->metodo('include/class.category.php', 'topLevel');

        $this->assertTrue($cuerpo !== '', 'no se encontró `Category::topLevel()`');
        $this->assertStringContainsString("'category_pid__isnull' => true", $cuerpo,
            'falta la forma NULL, que es la que escribe el esquema');
        $this->assertStringContainsString("'category_pid' => 0", $cuerpo,
            'falta la forma 0, que dejaron las versiones anteriores');
        $this->assertStringContainsString('Q::ANY', $cuerpo,
            'las dos formas tienen que ser un OR: con un `all` haría falta que '
            . 'fueran verdad las dos a la vez');
    }

    /** Y se escribe NULL, que es lo que dice el esquema. */
    public function testLaCategoriaSeGuardaConNuloYNoConCero(): void {
        $cuerpo = $this->metodo('include/class.category.php', 'update');

        $this->assertStringContainsString('$this->category_pid = $vars[\'pid\'] ?: null;',
            $cuerpo,
            'guardar 0 deja la categoría fuera del listado y del JOIN del padre');
        $this->assertFalse(
            strpos($cuerpo, '$this->category_pid = $vars[\'pid\'] ?: 0;') !== false,
            'sigue guardándose 0 para el primer nivel');
    }

    /** El nombre duplicado se busca con el mismo criterio que el listado. */
    public function testLaBusquedaPorNombreUsaElMismoCriterio(): void {
        foreach (array('findIdByName', 'findByName') as $metodo) {
            $cuerpo = $this->metodo('include/class.category.php', $metodo);
            $this->assertTrue($cuerpo !== '', "no se encontró `$metodo()`");
            $this->assertStringContainsString('self::parentIs(', $cuerpo,
                "`$metodo()` tiene que usar el criterio del padre: con "
                . "`\$pid ?: null` no encuentra una categoría guardada con 0 y "
                . 'deja crear un duplicado');
        }

        $cuerpo = $this->metodo('include/class.category.php', 'parentIs');
        $this->assertStringContainsString('self::topLevel()', $cuerpo,
            '`parentIs()` sin padre tiene que delegar en `topLevel()`');
    }
}
