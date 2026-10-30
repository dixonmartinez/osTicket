<?php
/*********************************************************************
    class.i18n.php

    Internationalization and localization helpers for osTicket

    Peter Rotich <peter@osticket.com>
    Jared Hancock <jared@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
require_once INCLUDE_DIR.'class.error.php';
require_once INCLUDE_DIR.'class.yaml.php';

class Internationalization {

    // Languages in order of decreasing priority. Always use en_US as a
    // fallback
    var $langs = array('en_US');

    /**
     * Lo que no entró al sembrar, por archivo y por registro.
     *
     * `loadModels()` crea cada registro con `@` y vacía el array de errores en
     * cada vuelta: cuando un YAML no entra, el instalador termina igual y no
     * queda rastro de por qué. Se guarda aquí para que quien siembra (el
     * instalador, una prueba, `tools/`) pueda decir cuál falló y qué dijo.
     */
    var $seed_errors = array();

    /** Errores de la siembra: `archivo.yaml` => `registro` => errores. */
    function getSeedErrors() {
        return $this->seed_errors;
    }

    function __construct($language=false) {
        global $cfg;

        if ($cfg && ($lang = $cfg->getPrimaryLanguage()))
            array_unshift($this->langs, $language);

        // Detect language filesystem path, case insensitively
        if ($language && ($info = self::getLanguageInfo($language))) {
            array_unshift($this->langs, $info['code']);
        }
    }

    function getTemplate($path) {
        return new DataTemplate($path, $this->langs);
    }

    /**
     * Loads data from the I18N_DIR for the target language into the
     * database. This is intended to be done at the time of installation;
     * however, care should be taken in this process to ensure that the
     * process could be repeated if an administrator wanted to change the
     * system language and reload the data.
     */
    /**
     * Los datos con los que arranca una instalación nueva.
     *
     * Son siete pasos con dependencias entre ellos —las formas y los temas
     * necesitan departamentos y SLA, las plantillas de correo necesitan su
     * grupo—, así que el orden importa. Cada paso tiene su método y este sólo
     * los llama en el orden correcto.
     */
    function loadDefaultData() {
        $this->loadModels();
        $this->loadPriorities();
        $this->loadConfig();
        $config = $this->loadCoreConfig();
        $this->loadPages($config);
        $this->loadCannedResponses();
        $this->loadEmailTemplates();
    }

    /**
     * Los registros que se crean a partir de un YAML, con su creador.
     *
     * El orden de la tabla también importa: departamento, SLA y formularios
     * tienen que existir antes que los temas de ayuda.
     */
    function loadModels() {
        # notrans -- do not translate the contents of this array
        $models = array(
            'sla.yaml' =>           'SLA::__create',
            'department.yaml' =>    'Dept::__create',
            'form.yaml' =>          'DynamicForm::create',
            'list.yaml' =>          'DynamicList::create',
            // Note that department, sla, and forms are required for
            // help_topic
            'help_topic.yaml' =>    'Topic::__create',
            'filter.yaml' =>        'Filter::__create',
            'team.yaml' =>          'Team::__create',
            // Organization
            'organization.yaml' =>  'Organization::__create',
            // Ticket
            'ticket_status.yaml' => 'TicketStatus::__create',
            // Role
            'role.yaml' =>          'Role::__create',
            'event.yaml' =>         'Event::__create',
            'file.yaml' =>          'AttachmentFile::__create',
            'sequence.yaml' =>      'Sequence::__create',
            'queue_column.yaml' =>  'QueueColumn::__create',
            'queue_sort.yaml' =>    'QueueSort::__create',
            'queue.yaml' =>         'CustomQueue::__create',
            // Schedule
            'schedule.yaml' =>      'Schedule::__create',
        );

        $this->seed_errors = array();
        foreach ($models as $yaml=>$m) {
            if (!$objects = $this->getTemplate($yaml)->getData())
                continue;

            // Un creador que no existe (clase sin cargar) se saltaba en
            // silencio: se anota como fallo del archivo entero.
            if (!$m || !is_callable($m)) {
                $this->seed_errors[$yaml]['*'] = array(
                    sprintf('no hay creador para %s', $yaml));
                continue;
            }

            foreach ($objects as $o) {
                // `@` porque el instalador no quiere avisos de PHP en la
                // pantalla; los errores de validación sí se recogen.
                $errors = array();
                $created = @call_user_func_array($m, array($o, &$errors));
                if ($errors || $created === false) {
                    $name = isset($o['name']) ? $o['name']
                        : (isset($o['id']) ? $o['id'] : '?');
                    $this->seed_errors[$yaml][$name] = $errors
                        ? $errors
                        : array('no se creó (el creador devolvió false)');
                    // El motor dice lo que la validación no ve (columna
                    // desconocida, valor obligatorio sin omisión…).
                    if (class_exists('VerySimpleModel')
                            && ($sql = VerySimpleModel::getLastError()))
                        $this->seed_errors[$yaml][$name]['sql'] = $sql;
                }
            }
        }
        if ($this->seed_errors)
            error_log('osTicket: la siembra dejó registros sin crear — '
                . json_encode($this->seed_errors));
    }

    /**
     * Las prioridades: no pasan por el ORM porque se crean con su id fijo
     * (`priority_id`) y el YAML ya lo trae.
     */
    function loadPriorities() {
        $priorities = $this->getTemplate('priority.yaml')->getData();
        foreach ($priorities as $name=>$info) {
            $sql = 'INSERT INTO '.PRIORITY_TABLE
                .' SET priority='.db_input($name)
                .', priority_id='.db_input($info['priority_id'])
                .', priority_desc='.db_input($info['priority_desc'])
                .', priority_color='.db_input($info['priority_color'])
                .', priority_urgency='.db_input($info['priority_urgency']);
            db_query($sql);
        }
    }

    /** La configuración por secciones (`config.yaml`). */
    function loadConfig() {
        // Configuration
        require_once INCLUDE_DIR.'class.config.php';
        if (($tpl = $this->getTemplate('config.yaml'))
                && ($data = $tpl->getData())) {
            foreach ($data as $section=>$items) {
                $_config = new Config($section);
                foreach ($items as $key=>$value)
                    $_config->set($key, $value);
            }
        }
    }

    /**
     * La configuración del sistema, con el tamaño máximo de archivo que
     * aguanta este servidor: la mitad de `upload_max_filesize`, redondeado a
     * la potencia de dos de debajo.
     */
    function loadCoreConfig() {
        // Load core config
        $_config = new OsticketConfig();

        // Determine reasonable default max_file_size
        $max_size = Format::filesize2bytes(strtoupper(ini_get('upload_max_filesize')));
        $val = ((int) $max_size/2);
        $po2 = 1;
        while( $po2 < $val ) $po2 <<= 1;

        $_config->set('max_file_size', $po2);
        return $_config;
    }

    /**
     * Las páginas del portal (portada, agradecimiento, fuera de línea y las de
     * registro y contraseña) y el idioma por omisión.
     */
    function loadPages(OsticketConfig $_config) {
        // Pages and content
        foreach (array('landing','thank-you','offline',
                'registration-staff', 'pwreset-staff', 'banner-staff',
                'registration-client', 'pwreset-client', 'banner-client',
                'registration-confirm', 'registration-thanks',
                'access-link', 'email2fa-staff') as $type) {
            $tpl = $this->getTemplate("templates/page/{$type}.yaml");
            if (!($page = $tpl->getData()))
                continue;
            $sql = 'INSERT INTO '.PAGE_TABLE.' SET type='.db_input($type)
                .', name='.db_input($page['name'])
                .', body='.db_input($page['body'])
                .', notes='.db_input($page['notes'])
                .', created=NOW(), updated=NOW(), isactive=1';
            if (db_query($sql) && ($id = db_insert_id())
                    && in_array($type, array('landing', 'thank-you', 'offline')))
                $_config->set("{$type}_page_id", $id);
        }
        // Default Language
        $_config->set('system_language', $this->langs[0]);
    }

    /** Las respuestas predefinidas de ejemplo, con sus adjuntos. */
    function loadCannedResponses() {
        // Canned response examples
        if (($tpl = $this->getTemplate('templates/premade.yaml'))
                && ($canned = $tpl->getData())) {
            foreach ($canned as $c) {
                $c['isenabled'] = 1;
                if (!($premade = Canned::create($c)) || !$premade->save())
                    continue;
                if (isset($c['attachments'])) {
                    $premade->attachments->upload($c['attachments']);
                }
            }
        }
    }

    /**
     * Las plantillas de correo: primero el grupo, después cada plantilla con
     * su `tpl_id` y sus adjuntos incrustados.
     */
    function loadEmailTemplates() {
        $errors = array();
        // Email templates
        // TODO: Lookup tpl_id
        if ($objects = $this->getTemplate('email_template_group.yaml')->getData()) {
            foreach ($objects as $o) {
                $o['lang_id'] = $this->langs[0];
                $tpl = EmailTemplateGroup::create($o, $errors);
            }
        }
        // This shouldn't be necessary
        $tpl = EmailTemplateGroup::lookup(1);
        foreach ($tpl::$all_names as $name=>$info) {
            if (($tp = $this->getTemplate("templates/email/$name.yaml"))
                    && ($t = $tp->getData())) {
                $t['tpl_id'] = $tpl->getId();
                $t['code_name'] = $name;
                $id = EmailTemplate::create($t, $errors);
                if ($id && ($template = EmailTemplate::lookup($id))
                        && ($ids = Draft::getAttachmentIds($t['body'])))
                    $template->attachments->upload($ids, true);
            }
        }
    }

    static function getLanguageDescription($lang) {
        global $thisstaff, $thisclient;

        $langs = self::availableLanguages();
        $lang = str_replace('-', '_', strtolower($lang));
        if (isset($langs[$lang])) {
            $info = &$langs[$lang];
            if (!isset($info['desc'])) {
                if (extension_loaded('intl')) {
                    $lang = self::getCurrentLanguage();
                    list($simple_lang,) = explode('_', $lang);
                    $info['desc'] = sprintf("%s%s",
                        // Display the localized name of the language
                        Locale::getDisplayName($info['code'], $info['code']),
                        // If the major language differes from the user's,
                        // display the language in the user's language
                        (strpos($simple_lang, $info['lang']) === false
                            ? sprintf(' (%s)', Locale::getDisplayName($info['code'], $lang)) : '')
                    );
                }
                else {
                    $info['desc'] = sprintf("%s%s (%s)",
                        $info['nativeName'],
                        $info['locale'] ? sprintf(' - %s', $info['locale']) : '',
                        $info['name']);
                }
            }
            return $info['desc'];
        }
        else
            return $lang;
    }

    static function getLanguageInfo($lang) {
        $langs = self::availableLanguages();
        return @$langs[str_replace('-', '_', strtolower($lang))] ?: array();
    }

    static function availableLanguages($base=I18N_DIR) {
        static $cache = false;
        if ($cache) return $cache;

        $langs = (include I18N_DIR . 'langs.php');

        // Consider all subdirectories and .phar files in the base dir
        $dirs = glob(I18N_DIR . '*', GLOB_ONLYDIR | GLOB_NOSORT);
        $phars = glob(I18N_DIR . '*.phar', GLOB_NOSORT) ?: array();

        $installed = array();
        foreach (array_merge($dirs, $phars) as $f) {
            $base = basename($f, '.phar');
            @list($code, $locale) = explode('_', $base);
            if (isset($langs[$code])) {
                $installed[strtolower($base)] =
                    $langs[$code] + array(
                    'lang' => $code,
                    'locale' => $locale,
                    'path' => $f,
                    'phar' => substr($f, -5) == '.phar',
                    'code' => $base,
                );
                $installed[strtolower($base)]['flag'] = strtolower(
                    ($langs[$code]['flag'] ?? $locale) ?: $code
                );
            }
        }
        ksort($installed);

        return $cache = $installed;
    }

    static function isLanguageInstalled($code) {
        $langs = self::availableLanguages();
        return isset($langs[strtolower((string) $code)]);
    }

    static function isLanguageEnabled($code) {
        $langs = self::getConfiguredSystemLanguages();
        return isset($langs[$code]);
    }

    static function getConfiguredSystemLanguages() {
        global $cfg;
        static $langs;

        if (!$cfg)
            return self::availableLanguages();

        if (!isset($langs)) {
            $langs = array();
            $pri = $cfg->getPrimaryLanguage();
            if ($info = self::getLanguageInfo($pri))
                $langs = array($pri => $info);

            // Honor sorting preference of ::availableLanguages()
            foreach ($cfg->getSecondaryLanguages() as $l) {
                if ($info = self::getLanguageInfo($l))
                    $langs[$l] = $info;
            }
        }
        return $langs;
    }

    // TODO: Move this to the REQUEST class or some middleware when that
    // exists.
    // Algorithm borrowed from Drupal 7 (locale.inc)
    static function getDefaultLanguage() {
        global $cfg;
        static $lang;

        if (isset($lang))
            return $lang;

        if (empty($_SERVER["HTTP_ACCEPT_LANGUAGE"]))
            return $cfg ? $cfg->getPrimaryLanguage() : 'en_US';

        $languages = self::getConfiguredSystemLanguages();

        // The Accept-Language header contains information about the
        // language preferences configured in the user's browser / operating
        // system. RFC 2616 (section 14.4) defines the Accept-Language
        // header as follows:
        //   Accept-Language = "Accept-Language" ":"
        //                  1#( language-range [ ";" "q" "=" qvalue ] )
        //   language-range  = ( ( 1*8ALPHA *( "-" 1*8ALPHA ) ) | "*" )
        // Samples: "hu, en-us;q=0.66, en;q=0.33", "hu,en-us;q=0.5"
        $browser_langcodes = array();
        $matches = array();
        if (preg_match_all('@(?<=[, ]|^)([a-zA-Z-]+|\*)(?:;q=([0-9.]+))?(?:$|\s*,\s*)@',
            trim($_SERVER['HTTP_ACCEPT_LANGUAGE']), $matches, PREG_SET_ORDER)) {
          foreach ($matches as $match) {
            // We can safely use strtolower() here, tags are ASCII.
            // RFC2616 mandates that the decimal part is no more than three
            // digits, so we multiply the qvalue by 1000 to avoid floating
            // point comparisons.
            $langcode = strtolower($match[1]);
            $qvalue = isset($match[2]) ? (float) $match[2] : 1;
            $browser_langcodes[$langcode] = (int) ($qvalue * 1000);
          }
        }

        // We should take pristine values from the HTTP headers, but
        // Internet Explorer from version 7 sends only specific language
        // tags (eg. fr-CA) without the corresponding generic tag (fr)
        // unless explicitly configured. In that case, we assume that the
        // lowest value of the specific tags is the value of the generic
        // language to be as close to the HTTP 1.1 spec as possible.
        //
        // References:
        // http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.4
        // http://blogs.msdn.com/b/ie/archive/2006/10/17/accept-language-header-for-internet-explorer-7.aspx
        asort($browser_langcodes);
        foreach ($browser_langcodes as $langcode => $qvalue) {
          $generic_tag = strtok($langcode, '-');
          if (!isset($browser_langcodes[$generic_tag])) {
            $browser_langcodes[$generic_tag] = $qvalue;
          }
        }

        // Find the enabled language with the greatest qvalue, following the rules
        // of RFC 2616 (section 14.4). If several languages have the same qvalue,
        // prefer the one with the greatest weight.
        $best_match_langcode = FALSE;
        $max_qvalue = 0;
        foreach ($languages as $langcode => $language) {
          // Language tags are case insensitive (RFC2616, sec 3.10).
          // We use _ as the location separator
          $langcode = str_replace('_','-',strtolower($langcode));

          // If nothing matches below, the default qvalue is the one of the wildcard
          // language, if set, or is 0 (which will never match).
          $qvalue = isset($browser_langcodes['*']) ? $browser_langcodes['*'] : 0;

          // Find the longest possible prefix of the browser-supplied language
          // ('the language-range') that matches this site language ('the language tag').
          $prefix = $langcode;
          do {
            if (isset($browser_langcodes[$prefix])) {
              $qvalue = $browser_langcodes[$prefix];
              break;
            }
          } while ($prefix = substr($prefix, 0, strrpos($prefix, '-')));

          // Find the best match.
          if ($qvalue > $max_qvalue) {
            $best_match_langcode = $language['code'];
            $max_qvalue = $qvalue;
          }
        }

        return $lang = self::isLanguageInstalled($best_match_langcode)
            ? $best_match_langcode
            : ($cfg ? $cfg->getPrimaryLanguage() : 'en_US');
    }

    static function getCurrentLanguage($user=false) {
        global $thisstaff, $thisclient;

        $user = $user ?: $thisstaff ?: $thisclient;
        if ($user && method_exists($user, 'getLanguage'))
            if (($lang = $user->getLanguage()) && self::isLanguageEnabled($lang))
                return $lang;

        // Support the flag buttons for guests
        if ((!$user || $user != $thisstaff) && isset($_SESSION['::lang']))
            return $_SESSION['::lang'];

        return self::getDefaultLanguage();
    }

    static function getCurrentLanguageInfo($user=false) {
        return static::getLanguageInfo(static::getCurrentLanguage($user));
    }

    static function getCurrentLocale($user=false) {
        global $thisstaff, $cfg;

        if ($user) {
            return self::getCurrentLanguage($user);
        }
        // FIXME: Move this majic elsewhere - see upgrade bug note in
        // class.staff.php
        if ($thisstaff) {
            return $thisstaff->getLocale()
                ?: self::getCurrentLanguage($thisstaff);
        }

        if (!($locale = $cfg->getDefaultLocale()))
            $locale = self::getCurrentLanguage();

        return $locale;
    }

    static function getCSVDelimiter($locale='') {

        if (!$locale && extension_loaded('intl'))  // Prefer browser settings
            $locale = Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);

        // Detect delimeter from the current locale settings. For locales
        // which use comma (,) as the decimal separator, the semicolon (;)
        // should be used as the field separator
        $delimiter = ',';
        if (class_exists('NumberFormatter')) {
            $nf = NumberFormatter::create($locale ?: self::getCurrentLocale(),
                NumberFormatter::DECIMAL);
            $s = $nf->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
            if ($s == ',')
                $delimiter = ';';
        } else {
            $info = localeconv();
            if ($info && $info['decimal_point'] == ',')
                $delimiter = ';';

        }

        return $delimiter;
    }

    /**
     * La clave con la que se guarda un `IntlDateFormatter`.
     *
     * Se calculaba con `implode(':', array_filter(array_intersect_key(...)))`
     * y eso tenía dos problemas:
     *
     *  - `array_filter()` sin función quita los valores falsos, y
     *    `IntlDateFormatter::FULL` vale **0**. Un formateador con día completo
     *    (`Format::daydatetime()`, `IDF_FULL`) generaba la misma clave que uno
     *    sin tipo de día (`ajax.config.php` pasa `null`), así que el segundo
     *    reutilizaba el primero y pintaba la fecha de otra manera. Los valores
     *    nulos y el cero tienen que distinguirse.
     *  - `array_intersect_key()` conserva el orden del arreglo que llega, no el
     *    de la lista de campos: los mismos valores en otro orden daban otra
     *    clave y un formateador de más.
     *
     * @param array $options
     * @return string
     */
    static function dateFormatterKey(array $options) {
        $parts = array();

        foreach (array('locale', 'daytype', 'timetype', 'timezone', 'pattern')
                as $field) {
            $value = array_key_exists($field, $options)
                ? $options[$field] : null;
            // `\0` para «no viene»: así no se confunde con la cadena vacía.
            $parts[] = is_null($value) ? "\0" : (string) $value;
        }

        return md5(implode('|', $parts));
    }

    //  getIntDateFormatter($options)
    //
    // Setting up the IntlDateFormatter is pretty expensive, so cache it since
    // there aren't many variations of the arguments passed to the constructor
    static function getIntDateFormatter($options) {
        // Un arreglo desde el principio: asignar sobre `false` era una
        // conversión automática que PHP 8.1 marca como obsoleta.
        static $cache = array();

        // Set some defaults
        $options['locale'] = $options['locale'] ?: self::getCurrentLocale();

        $k = self::dateFormatterKey($options);

        // We if we have it cached
        if (isset($cache[$k]) && $cache[$k])
            return $cache[$k];

        // Create formatter && cache
        $cache[$k] = $formatter = new IntlDateFormatter(
                $options['locale'],
                $options['daytype'] ?? null,
                $options['timetype'] ?? null,
                $options['timezone'] ?? null,
                $options['calendar'] ?? IntlDateFormatter::GREGORIAN,
                $options['pattern'] ?? null
                );

        return $formatter;
    }

    static function rfc1766($what) {
        if (is_array($what))
            return array_map(array(get_called_class(), 'rfc1766'), $what);

        $lr = explode('_', $what);
        if (isset($lr[1]))
            $lr[1] = strtoupper($lr[1]);
        return implode('-', $lr);
    }

    static function getTtfFonts() {
        if (!class_exists('Phar'))
            return;
        $fonts = $subs = array();
        foreach (self::availableLanguages() as $code=>$info) {
            if (!$info['phar'] || !isset($info['fonts']))
                continue;
            foreach ($info['fonts'] as $simple => $collection) {
                foreach ($collection as $type => $name) {
                    list($name, $url) = $name;
                    $ttffile = 'phar://' . $info['path'] . '/fonts/' . $name;
                    if (file_exists($ttffile))
                        $fonts[$simple][$type] = $ttffile;
                }
                if (@$collection[':sub'])
                    $subs[] = $simple;
            }
        }
        $rv = array($fonts, $subs);
        Signal::send('config.ttfonts', null, $rv);
        return $rv;
    }

    static function setCurrentLanguage($lang) {
        if (!self::isLanguageInstalled($lang))
            return false;

        $_SESSION['::lang'] = $lang ?: null;
        return true;
    }

    static function allLocales() {
        $locales = array();
        if (class_exists('ResourceBundle')) {
            $current_lang = self::getCurrentLanguage();
            $langs = array();
            foreach (self::getConfiguredSystemLanguages() as $code=>$info) {
                list($lang,) = explode('_', $code, 2);
                $langs[$lang] = true;
            }
            foreach (ResourceBundle::getLocales('') as $code) {
                list($lang,) = explode('_', $code, 2);
                if (isset($langs[$lang])) {
                    $locales[$code] = Locale::getDisplayName($code, $current_lang);
                }
            }
        }
        return $locales;
    }

    static function sortKeyedList($list, $case=false) {
        global $cfg;

        // XXX: Use current language
        if ($cfg && function_exists('collator_create')) {
            $coll = Collator::create($cfg->getPrimaryLanguage());
            if (!$case)
                $coll->setStrength(Collator::TERTIARY);
            // UASORT is necessary to preserve the keys
            uasort($list, function($a, $b) use ($coll) {
                return $coll->compare($a, $b); });
        }
        else {
            if (!$case)
                uasort($list, function($a, $b) {
                    return strcmp(mb_strtoupper($a), mb_strtoupper($b)); });
            else
                // Really only works on ascii names
                asort($list);
        }
        return $list;
    }

    static function bootstrap() {

        require_once INCLUDE_DIR . 'class.translation.php';

        $domain = 'messages';
        TextDomain::setDefaultDomain($domain);
        TextDomain::lookup()->setPath(I18N_DIR);

        // Set the default locale to UTF-8. It will be changed by
        // ::setLocaleForUser() later for web requests. See #2910
        TextDomain::setLocale(LC_ALL, 'en_US.UTF-8');

        // User-specific translations
        function _N($msgid, $plural, $n) {
            return TextDomain::lookup()->getTranslation()
                ->ngettext($msgid, $plural, is_numeric($n) ? $n : 1);
        }

        // System-specific translations
        function _S($msgid) {
            global $cfg;
            return __($msgid);
        }
        function _NS($msgid, $plural, $count) {
            global $cfg;
        }

        // Phrases with separate contexts
        function _P($context, $msgid) {
            return TextDomain::lookup()->getTranslation()
                ->pgettext($context, $msgid);
        }
        function _NP($context, $singular, $plural, $n) {
            return TextDomain::lookup()->getTranslation()
                ->npgettext($context, $singular, $plural, is_numeric($n) ? $n : 1);
        }

        // Language-specific translations
        function _L($msgid, $locale) {
            return TextDomain::lookup()->getTranslation($locale)
                ->translate($msgid);
        }
        function _NL($msgid, $plural, $n, $locale) {
            return TextDomain::lookup()->getTranslation($locale)
                ->ngettext($msgid, $plural, is_numeric($n) ? $n : 1);
        }
    }
}

class DataTemplate {
    // Base folder for default data and templates
    var $base = I18N_DIR;

    var $filepath;
    var $data;

    /**
     * Searches for the files matching the template in the order of the
     * received languages. Once matched, the language is captured so that
     * template itself does not have to keep track of the language for which
     * it is defined.
     */
    function __construct($path, $langs=array('en_US')) {
        foreach ($langs as $l) {
            if (file_exists("{$this->base}/$l/$path")) {
                $this->lang = $l;
                $this->filepath = Misc::realpath("{$this->base}/$l/$path");
                break;
            }
            elseif (class_exists('Phar')
                    && Phar::isValidPharFilename("{$this->base}/$l.phar")
                    && file_exists("phar://{$this->base}/$l.phar/$path")) {
                $this->lang = $l;
                $this->filepath = "phar://{$this->base}/$l.phar/$path";
                break;
            }
        }
    }

    function getData() {
        if (!isset($this->data) && $this->filepath)
            $this->data = YamlDataParser::load($this->filepath);
            // TODO: If there was a parsing error, attempt to try the next
            //       language in the list of requested languages
        return $this->data;
    }

    function getRawData() {
        if (!isset($this->data) && $this->filepath)
            return file_get_contents($this->filepath);
            // TODO: If there was a parsing error, attempt to try the next
            //       language in the list of requested languages
        return false;
    }

    function getLang() {
        return $this->lang;
    }
}

?>
