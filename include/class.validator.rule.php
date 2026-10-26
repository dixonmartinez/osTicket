<?php
/**
 * include/class.validator.rule.php
 *
 * Las reglas de tipo de `Validator::validate()`, sacadas a su propio archivo
 * (frente 2 de docs/CODE_QUALITY.md).
 *
 * Cada entrada de la definición de campos de un formulario trae un `type`
 * —`int`, `email`, `password`, `cs-url`…— y un `error` que se muestra si el
 * valor no pasa. Esa tabla de reglas estaba dentro del bucle de `validate()`,
 * mezclada con el manejo de campos obligatorios y vacíos.
 *
 * Aquí sólo se decide **si un valor pasa su tipo**. Lo que se hace con el
 * resultado —qué clave de error, si el campo era obligatorio, si se sigue con
 * el resto— se queda en `validate()`. No toca la base de datos ni el estado
 * de la petición: entra un valor, sale un mensaje o `null`.
 *
 * @see Validator::validate()
 */

class ValidatorRule {

    /**
     * Comprueba un valor contra el tipo declarado en el campo.
     *
     * @param string $type    tipo de la definición (`int`, `email`…)
     * @param mixed  $value   valor recibido (puede ser null)
     * @param array  $field   definición del campo: usa `error` y `min`
     *
     * @return string|null    el mensaje de error, o `null` si pasa
     */
    static function check($type, $value, array $field) {
        $error = isset($field['error']) ? (string) $field['error'] : '';

        // Los tipos que trabajan con cadenas no aceptan un arreglo. Antes eso
        // reventaba —`strtotime(): Argument #1 ($datetime) must be of type
        // string, array given`, un 500 en producción— o dejaba un aviso de
        // «Array to string conversion» en el log; ahora es el error del campo.
        if (!is_scalar($value) && $value !== null
                && in_array(strtolower($type), array('date', 'phone', 'fax',
                    'email', 'url', 'password', 'username', 'zipcode',
                    'cs-domain', 'cs-url', 'ipaddr'), true))
            return $error;

        switch (strtolower($type)) {
        case 'integer':
        case 'int':
            if (!is_numeric($value))
                return $error;
            if (($field['min'] ?? 0) && $value < $field['min'])
                return $error;
            return null;

        case 'double':
            return is_numeric($value) ? null : $error;

        case 'text':
        case 'string':
            return is_string($value) ? null : $error;

        case 'array':
            return ($value && is_array($value)) ? null : $error;

        case 'radio':
            return ($value === null) ? $error : null;

        case 'date': // TODO: comprobar que sea formato GNU de verdad
            return (strtotime((string) $value) === false) ? $error : null;

        case 'time': // TODO: comprobar que sea formato GNU de verdad
            return null;

        case 'phone':
        case 'fax':
            return self::isPhone($value) ? null : $error;

        case 'email':
            return self::isEmail($value) ? null : $error;

        case 'url':
            return self::isUrl($value) ? null : $error;

        case 'password':
            return (strlen((string) $value) < 6)
                ? $error . ' ' . __('(Six characters min)') : null;

        case 'username':
            $detail = '';
            return self::isUsername($value, $detail)
                ? null : $error . ": $detail";

        case 'zipcode':
            return (is_numeric($value) && strlen((string) $value) == 5)
                ? null : $error;

        case 'cs-domain': // lista de dominios separados por comas
            return self::everyValue($value, function ($v) {
                return preg_match_all(
                    '/^([a-z0-9|-]+\.)*[a-z0-9|-]+\.[a-z]+$/', $v);
            }) ? null : $error;

        case 'cs-url': // lista de urls separadas por comas
            return self::everyValue($value, function ($v) {
                return preg_match_all(
                    '/^(https?:\/\/)?((\*\.|\w+\.)?[\w-]+(\.[a-zA-Z]+)?'
                    . '(:([0-9]+|\*))?)+$/', $v);
            }) ? null : $error;

        case 'ipaddr':
            return self::everyValue($value, function ($v) {
                return preg_match_all('/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}'
                    . '\.[0-9]{1,3}$/', $v);
            }) ? null : $error;

        default: // tipo desconocido o no declarado
            return $error . ' ' . __('(type not set)');
        }
    }

    /**
     * ¿Pasan todos los valores de una lista separada por comas?
     *
     * Se recorre la lista igual que antes —`ltrim` de cada valor— para no
     * cambiar qué se acepta: un elemento inválido invalida el campo entero.
     */
    static function everyValue($value, callable $check) {
        $values = explode(',', (string) $value);
        foreach ($values as $v)
            if (!$check(ltrim($v)))
                return false;

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Las comprobaciones sueltas, delegadas a Validator                   */
    /* ------------------------------------------------------------------ */

    static function isEmail($value) {
        return Validator::is_email($value);
    }

    static function isUrl($value) {
        return Validator::is_url($value);
    }

    static function isPhone($value) {
        return Validator::is_phone($value);
    }

    static function isUsername($value, &$detail) {
        return Validator::is_username($value, $detail);
    }
}
