<?php
/**
 * tests/FormFieldErrorsTest.php
 *
 * Un campo de un formulario dinámico puede validarse más de una vez en la
 * misma petición: el formulario del cliente se instancia al validar el ticket
 * y otra vez al crear el cliente. Cada vuelta añadía un mensaje al array, así
 * que el mismo aviso salía dos y tres veces debajo del campo, como si fueran
 * problemas distintos.
 *
 * Se ve en una captura: el campo de correo con «Enter a valid email address»
 * repetido. En el alta de un ticket contra MariaDB salió con tres copias.
 *
 * La prueba no necesita base de datos ni un formulario montado: `addError()`
 * sólo escribe en `_errors` y avisa al formulario padre si lo hay.
 */

require_once INCLUDE_DIR . 'class.forms.field.php';

/** Campo mínimo: `addError()` no necesita nada más. */
if (!class_exists('FormFieldErrorsProbe')) {
    class FormFieldErrorsProbe extends FormField {
        var $src = array();

        function get($what, $default = null) {
            return isset($this->src[$what]) ? $this->src[$what] : $default;
        }
    }
}

class FormFieldErrorsTest extends UnitTestCase {

    private function campo() {
        $campo = new FormFieldErrorsProbe();
        $campo->src = array('id' => 1, 'label' => 'Email Address');

        return $campo;
    }

    public function testElMismoAvisoNoSeRepite(): void {
        $campo = $this->campo();

        $campo->addError('Enter a valid email address');
        $campo->addError('Enter a valid email address');
        $campo->addError('Enter a valid email address');

        $this->assertSame(array('Enter a valid email address'),
            $campo->errors(),
            'el mismo mensaje tiene que quedar una sola vez');
    }

    public function testAvisosDistintosSiSeGuardan(): void {
        $campo = $this->campo();

        $campo->addError('This is a required field');
        $campo->addError('Enter a valid email address');

        $this->assertSame(
            array('This is a required field', 'Enter a valid email address'),
            $campo->errors());
    }

    public function testUnAvisoIndexadoSiSeActualiza(): void {
        $campo = $this->campo();

        $campo->addError('Primero', 'form');
        $campo->addError('Segundo', 'form');

        $this->assertSame(array('form' => 'Segundo'), $campo->errors(),
            'con índice, el aviso se reemplaza: así lo usa el formulario');
    }

    public function testLaComparacionEsExacta(): void {
        $campo = $this->campo();

        $campo->addError('Invalid');
        // Distinto por una letra: no es el mismo aviso y no se puede perder.
        $campo->addError('invalid');
        $campo->addError('Invalid ');

        $this->assertCount(3, $campo->errors());
    }
}
