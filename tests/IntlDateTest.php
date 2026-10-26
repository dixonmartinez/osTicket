<?php
/**
 * tests/IntlDateTest.php
 *
 * Pruebas de la clave con la que se cachean los `IntlDateFormatter`
 * (`Internationalization::dateFormatterKey()`).
 *
 * Construir un formateador es caro —ICU se inicializa entero— y por eso se
 * guardan en un `static` por combinación de opciones. La clave se armaba con
 * `implode(':', array_filter(array_intersect_key(...)))`, y eso tenía dos
 * defectos: `IntlDateFormatter::FULL` vale **0** y `array_filter()` lo borraba
 * (un formateador de día completo se confundía con uno sin tipo de día), y el
 * orden de las claves salía del arreglo que llega, así que los mismos valores
 * en otro orden generaban otra clave.
 *
 * Aquí no hace falta la extensión `intl`: lo que se prueba es la clave.
 */

require_once INCLUDE_DIR . 'class.i18n.php';

class IntlDateTest extends UnitTestCase {

    private function options() {
        return array(
            'locale' => 'es_ES',
            'daytype' => 0,          // IntlDateFormatter::FULL
            'timetype' => 3,         // IntlDateFormatter::SHORT
            'timezone' => 'Europe/Madrid',
            'pattern' => 'dd/MM/yyyy',
        );
    }

    /** Los mismos valores dan siempre la misma clave. */
    function testLaMismaConfiguracionDaLaMismaClave() {
        $this->assertSame(
            Internationalization::dateFormatterKey($this->options()),
            Internationalization::dateFormatterKey($this->options()));
    }

    /**
     * El orden de las claves no cambia la clave.
     *
     * `array_intersect_key()` conserva el orden del arreglo de entrada, así que
     * antes dos llamadas con los mismos valores en distinto orden construían
     * dos formateadores idénticos.
     */
    function testElOrdenDeLasClavesNoCambiaLaClave() {
        $options = $this->options();
        $reordered = array_reverse($options, true);

        $this->assertSame(
            Internationalization::dateFormatterKey($options),
            Internationalization::dateFormatterKey($reordered));
    }

    /**
     * `daytype = 0` (día completo) no es lo mismo que no indicar día.
     *
     * Es el defecto que se estaba comiendo `array_filter()`: el cero es un
     * valor, no un hueco.
     */
    function testElDiaCompletoNoSeConfundeConNoIndicarDia() {
        $full = $this->options();
        $none = $this->options();
        $none['daytype'] = null;

        $this->assertFalse(
            Internationalization::dateFormatterKey($full)
                === Internationalization::dateFormatterKey($none),
            'daytype=0 y daytype=null comparten clave');

        $missing = $this->options();
        unset($missing['daytype']);
        $this->assertFalse(
            Internationalization::dateFormatterKey($full)
                === Internationalization::dateFormatterKey($missing),
            'daytype=0 y sin daytype comparten clave');
    }

    /** Lo mismo para el tipo de hora. */
    function testElTipoDeHoraCeroTampocoSePierde() {
        $zero = $this->options();
        $zero['timetype'] = 0;
        $absent = $this->options();
        $absent['timetype'] = null;

        $this->assertFalse(Internationalization::dateFormatterKey($zero)
            === Internationalization::dateFormatterKey($absent));
    }

    /** Un campo vacío y un campo que no viene tampoco son lo mismo. */
    function testLaCadenaVaciaNoEsLoMismoQueNoVenir() {
        $empty = $this->options();
        $empty['pattern'] = '';
        $missing = $this->options();
        unset($missing['pattern']);

        $this->assertFalse(Internationalization::dateFormatterKey($empty)
            === Internationalization::dateFormatterKey($missing));
    }

    /** Cada valor que cambia da otra clave. */
    function testCadaValorCambiaLaClave() {
        $base = Internationalization::dateFormatterKey($this->options());
        $seen = array($base => true);

        foreach (array('locale' => 'en_US', 'timezone' => 'UTC',
                'pattern' => 'yyyy-MM-dd', 'timetype' => 1) as $field => $value) {
            $options = $this->options();
            $options[$field] = $value;
            $key = Internationalization::dateFormatterKey($options);

            $this->assertFalse(isset($seen[$key]),
                'Dos configuraciones distintas comparten clave: ' . $field);
            $seen[$key] = true;
        }
    }

    /** La clave es un md5, como antes. */
    function testLaClaveEsUnMd5() {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/',
            Internationalization::dateFormatterKey($this->options()));
    }

    /**
     * `getIntDateFormatter()` usa esa clave y su caché nace como arreglo.
     *
     * Asignar sobre `false` era la conversión automática que PHP 8.1 marca
     * como obsoleta: un aviso en el registro cada primera llamada.
     */
    function testElFormateadorSeCacheaPorLaClaveNueva() {
        $source = file_get_contents(INCLUDE_DIR . 'class.i18n.php');

        $this->assertStringContainsString(
            '$k = self::dateFormatterKey($options);', $source);
        $this->assertStringContainsString('static $cache = array();', $source);
        $this->assertFalse(
            strpos($source, 'array_intersect_key($options') !== false,
            'Volvió la clave que dependía del orden y perdía los ceros');
    }
}
