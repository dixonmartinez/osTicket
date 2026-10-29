<?php
/**
 * include/class.plugin.info.php
 *
 * Lectura y normalización del `plugin.php` que declara cada plugin.
 *
 * Un plugin es una carpeta (o un `.phar`) dentro de `include/plugins/` con un
 * `plugin.php` que **devuelve un arreglo**:
 *
 * ```php
 * return array(
 *     'plugin'      => 'auth.php:AuthPlugin',   // archivo:clase
 *     'name'        => 'Autenticación LDAP',
 *     'version'     => '1.2.0',
 *     'ost_version' => '1.18',                  // opcional
 *     'include'     => 'include/',              // opcional
 *     'author'      => '…',
 *     'description' => '…',
 * );
 * ```
 *
 * Ese arreglo lo consumen el instalador, la pantalla de administración y el
 * cargador de clases del plugin; cada uno leía las claves a su manera y
 * daba por hecho que estaban todas. Un `plugin.php` al que le faltaba una
 * clave dejaba avisos de PHP («Undefined array key») y, en el peor caso, un
 * `enable()` sobre `null`.
 *
 * Esta clase no toca la base de datos ni el sistema de archivos: recibe el
 * arreglo y devuelve el arreglo completo. Por eso se puede probar sola
 * (frente 2 de `docs/CODE_QUALITY.md`, `tests/PluginTest.php`).
 */
class PluginInfo {

    /**
     * Claves que puede traer un `plugin.php`, con su valor por omisión.
     *
     * `plugin` vacío significa «este plugin no tiene clase»: se puede
     * instalar y configurar, pero no engancha nada en la aplicación.
     */
    static function defaults() {
        return array(
            'plugin'      => '',
            'name'        => '',
            'version'     => '',
            'ost_version' => null,
            'include'     => 'include/',
            'author'      => '',
            'description' => '',
            'stream'      => false,
        );
    }

    /**
     * El arreglo completo, con las claves de `defaults()` garantizadas.
     *
     * @param mixed  $info        lo que devolvió `plugin.php` (o nada)
     * @param string $installPath ruta relativa a `include/`, p. ej.
     *                            `plugins/ldap`
     * @param bool   $isPhar      si el plugin llegó en un `.phar` verificado
     *
     * @return array
     */
    static function normalize($info, $installPath, $isPhar = false) {
        if (!is_array($info))
            $info = array();

        $info = array_merge(self::defaults(), $info);

        // `include_path` es el nombre que leía `Plugin::getIncludePath()`
        // mientras el instalador publicaba `include`. Se acepta como alias
        // para no romper un plugin ya publicado con ese nombre.
        if (!empty($info['include_path']))
            $info['include'] = $info['include_path'];

        $info['include'] = self::includeDir($info['include']);
        $info['install_path'] = (string) $installPath;
        $info['isphar'] = (bool) $isPhar;

        return $info;
    }

    /**
     * La subcarpeta del plugin donde viven sus clases, siempre con la barra
     * final y sin barras de más.
     *
     * Acepta `include`, `include/` y `/include/`; la carpeta vacía —o un
     * valor que no sea texto— significa «la raíz del plugin».
     */
    static function includeDir($dir) {
        if (!is_string($dir))
            return '';

        $dir = trim($dir, "/ \t");

        return $dir === '' ? '' : $dir . '/';
    }

    /**
     * Ruta del directorio de clases del plugin, relativa a la raíz del
     * proyecto (`include/plugins/ldap/include/`).
     */
    static function includePath($installPath, array $info) {
        $installPath = rtrim((string) $installPath, '/');
        $dir = self::includeDir($info['include'] ?? '');

        return $installPath . '/' . $dir;
    }

    /**
     * Separa `archivo.php:Clase` en sus dos partes.
     *
     * Cuando el plugin no declara clase (`solo archivo.php`) el segundo
     * valor es `null`: quien llama decide si eso es un error o un plugin sin
     * enganches. Un plugin sin `plugin` devuelve `array(null, null)`.
     *
     * @return array{0: string|null, 1: string|null}
     */
    static function declared(array $info) {
        $declared = isset($info['plugin']) ? trim((string) $info['plugin']) : '';
        if ($declared === '')
            return array(null, null);

        // explode con límite: una clase con dos puntos (namespace) se queda
        // entera en la segunda parte
        $parts = explode(':', $declared, 2);
        $file = trim($parts[0]);
        $class = isset($parts[1]) ? trim($parts[1]) : '';

        return array($file ?: null, $class ?: null);
    }

    /**
     * El registro DNS de un `.phar` verificado, convertido en arreglo.
     *
     * El registro es una cadena de pares separados por `;`, por ejemplo
     * `v=1; i=storage:s3; s=MEUC…=; V=0.1;`. Un fragmento sin `=` (una
     * entrada vacía, un TXT ajeno o un espacio suelto) se ignora: antes
     * `list($k, $v) = explode('=', …)` dejaba un aviso de PHP y una clave
     * con valor vacío en la página de configuración.
     *
     * @param string $txt contenido del registro TXT
     *
     * @return array clave => valor
     */
    static function parseRegistry($txt) {
        $info = array();
        foreach (explode(';', (string) $txt) as $pair) {
            $parts = explode('=', trim($pair), 2);
            $key = trim($parts[0]);
            if (count($parts) !== 2 || $key === '')
                continue;

            $info[$key] = trim($parts[1]);
        }

        return $info;
    }

    /**
     * ¿Este plugin sirve con la versión de osTicket instalada?
     *
     * El requisito se compara **sólo por versión mayor y menor** cuando se
     * declara con dos puntos (`1.18` en vez de `1.18.4`): la versión exacta
     * sólo existe en las copias empaquetadas, no en un clon de git.
     *
     * @param string|null $version     lo que pide el plugin (`ost_version`)
     * @param string|null $ostVersion  la instalada; por omisión MAJOR_VERSION
     */
    static function isCompatible($version, $ostVersion = null) {
        // Sin requisito, todo vale
        if (!$version)
            return true;

        $matches = array();
        if (!$ostVersion
                && preg_match_all('/\./', $version, $matches, PREG_OFFSET_CAPTURE) == 2)
            $version = substr($version, 0, $matches[0][1][1]);

        return version_compare($ostVersion ?: MAJOR_VERSION, $version, '>=');
    }

    /**
     * ¿Hay que actualizar el registro del plugin?
     *
     * Sólo si el `plugin.php` declara una versión y es distinta de la
     * guardada. Antes se comparaba contra una clave mal escrita
     * (`verion`), así que la comparación era siempre cierta y el gancho
     * `pre_upgrade()` se ejecutaba en **cada carga** de la página, con un
     * aviso de PHP por cada plugin instalado.
     *
     * @param string|null $installed versión guardada en la base
     * @param array       $info      lo que declara el `plugin.php`
     */
    static function needsUpgrade($installed, array $info) {
        $version = isset($info['version']) ? trim((string) $info['version']) : '';
        if ($version === '')
            return false;

        return (string) $installed !== $version;
    }
}
