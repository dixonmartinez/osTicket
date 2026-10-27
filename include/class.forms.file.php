<?php
/*********************************************************************
    class.forms.file.php

    Formularios: campos de archivo.

    Parte de include/class.forms.php (frente 6 de docs/CODE_QUALITY.md):
    el archivo tenia 4.515 lineas y 37 clases. Se movieron las clases
    enteras, sin tocar su codigo.

    FileUploadField y los adjuntos del campo.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/


class FileUploadField extends FormField {
    static $widget = 'FileUploadWidget';

    protected $attachments;
    protected $files;

    static function getFileTypes() {
        static $filetypes;

        if (!isset($filetypes)) {
            if (function_exists('apcu_fetch')) {
                $key = md5(SECRET_SALT . GIT_VERSION . 'filetypes');
                $filetypes = apcu_fetch($key);
            }
            if (!$filetypes)
                $filetypes = YamlDataParser::load(INCLUDE_DIR . '/config/filetype.yaml');
            if ($key)
                apcu_store($key, $filetypes, 7200);
        }
        return $filetypes;
    }

    function getConfigurationOptions() {
        // Compute size selections
        $sizes = array('262144' => '— '.__('Small').' —');
        $next = 512 << 10;
        $max = strtoupper(ini_get('upload_max_filesize'));
        $limit = (int) $max;
        if (!$limit) $limit = 2 << 20; # 2M default value
        elseif (strpos($max, 'K')) $limit <<= 10;
        elseif (strpos($max, 'M')) $limit <<= 20;
        elseif (strpos($max, 'G')) $limit <<= 30;
        while ($next <= $limit) {
            // Select the closest, larger value (in case the
            // current value is between two)
            $sizes[$next] = Format::file_size($next);
            $next *= 2;
        }
        // Add extra option if top-limit in php.ini doesn't fall
        // at a power of two
        if ($next < $limit * 2)
            $sizes[$limit] = Format::file_size($limit);

        $types = array();
        foreach (self::getFileTypes() as $type=>$info) {
            $types[$type] = $info['description'];
        }

        global $cfg;
        return array(
            'size' => new ChoiceField(array(
                'label'=>__('Maximum File Size'),
                'hint'=>__('Choose maximum size of a single file uploaded to this field'),
                'default'=>$cfg->getMaxFileSize(),
                'choices'=>$sizes
            )),
            'mimetypes' => new ChoiceField(array(
                'label'=>__('Restrict by File Type'),
                'hint'=>__('Optionally, choose acceptable file types.'),
                'required'=>false,
                'choices'=>$types,
                'configuration'=>array('multiselect'=>true,'prompt'=>__('No restrictions'))
            )),
            'extensions' => new TextareaField(array(
                'label'=>__('Additional File Type Filters'),
                'hint'=>__('Optionally, enter comma-separated list of additional file types, by extension. (e.g .doc, .pdf).'),
                'configuration'=>array('html'=>false, 'rows'=>2),
            )),
            'strictmimecheck' => new BooleanField([
                'id' => 4, 'label'=>__('Strict Mime Type Check'), 'required' => false, 'default' => false,
                'hint' => 'File Mime Type associations is OS dependent',
                'configuration' => ['desc' => __('Enable strict Mime Type check')]]),
            'max' => new TextboxField(array(
                'label'=>__('Maximum Files'),
                'hint'=>__('Users cannot upload more than this many files.'),
                'default'=>false,
                'required'=>false,
                'validator'=>'number',
                'configuration'=>array('size'=>8, 'length'=>4, 'placeholder'=>__('No limit')),
            ))
        );
    }

    /**
     * Called from the ajax handler for async uploads via web clients.
     */
    function ajaxUpload($bypass=false) {
        $config = $this->getConfiguration();

        $files = AttachmentFile::format($_FILES['upload'],
            // For numeric fields assume configuration exists
            !is_numeric($this->get('id')));
        if (count($files) != 1)
            Http::response(400, 'Send one file at a time');
        $file = array_shift($files);
        $file['name'] = urldecode($file['name']);
        $config = $this->getConfiguration();

        if (!self::isValidFile($file, $config['strictmimecheck']))
            Http::response(413, 'Invalid File');

        if (!$bypass && !$this->isValidFileType($file['name'], $file['type']))
            Http::response(415, 'File type is not allowed');

        $config = $this->getConfiguration();
        if (!$bypass && $file['size'] > $config['size'])
            Http::response(413, 'File is too large');

        if (!($F = AttachmentFile::upload($file)))
            Http::response(500, 'Unable to store file: '. $file['error']);

        $id = $F->getId();

        // This file is allowed for attachment in this session
        $_SESSION[':uploadedFiles'][$id] = $F->getName();

        return $id;
    }

    /**
     * Called from FileUploadWidget::getValue() when manual upload is used
     * for browsers which do not support the HTML5 way of uploading async.
     */
    function uploadFile($file) {
        if (!$this->isValidFileType($file['name'], $file['type']))
            throw new FileUploadError(__('File type is not allowed'));

        $config = $this->getConfiguration();

        if (!self::isValidFile($file, $config['strictmimecheck']))
             throw new FileUploadError(__('Invalid File'));

        if ($file['size'] > $config['size'])
            throw new FileUploadError(__('File size is too large'));

        return AttachmentFile::upload($file);
    }

    /**
     * Called from API and email routines and such to handle attachments
     * sent other than via web upload
     */
    function uploadAttachment(&$file) {
        if (!$this->isValidFileType($file['name'], $file['type']))
            throw new FileUploadError(__('File type is not allowed'));

        if (!isset($file['data']) && isset($file['data_cbk'])
                && is_callable($file['data_cbk']))
            $file['data'] = $file['data_cbk']();

        if (!isset($file['size']) && isset($file['data'])) {
            // bootstrap.php include a compat version of mb_strlen
            if (extension_loaded('mbstring'))
                $file['size'] = mb_strlen($file['data'], '8bit');
            else
                $file['size'] = strlen($file['data']);
        }

        $config = $this->getConfiguration();
        if ($file['size'] > $config['size'])
            throw new FileUploadError(__('File size is too large'));

        if (!$F = AttachmentFile::create($file))
            throw new FileUploadError(__('Unable to save file'));

        return $F;
    }

    /**
     * Strict mode can be enabled in Admin Panel > Settings > Tickets
     *
     * PS: Please note that the a mismatch can happen if the mime types
     * database is not up to date or a little different compared to what the
     * browser reports.
     **/
    static function isValidFile($file, $strict = false) {
        // Strict mime check
        if ($strict
            && !empty($file['type'])
            && FileObject::mimecmp($file['tmp_name'], $file['type']))
            return false;

        // Check invalid image hacks
        if ($file['tmp_name']
                && stripos($file['type'], 'image/') === 0
                && !(exif_imagetype($file['tmp_name'])
                    || mime_content_type($file['tmp_name']) === 'image/svg+xml'))
            return false;

        return true;
    }

    function isValidFileType($name, $type=false) {
        $config = $this->getConfiguration();

        // Check MIME type - file ext. shouldn't be solely trusted.
        if ($type && $config['__mimetypes']
                && in_array($type, $config['__mimetypes'], true))
            return true;

        // Return true if all file types are allowed (.*)
        if (!$config['__extensions'] || in_array('.*', $config['__extensions']))
            return true;

        $allowed = $config['__extensions'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return ($ext && is_array($allowed) && in_array(".$ext", $allowed));
    }

    function getAttachments() {
        if (!isset($this->attachments) && ($a = $this->getAnswer())
            && ($e = $a->getEntry()) && ($e->get('id'))
        ) {
            $this->attachments = GenericAttachments::forIdAndType(
                // Combine the field and entry ids to make the key
                sprintf('%u', abs(crc32('E'.$this->get('id').$e->get('id')))),
                'E');
        }
        return $this->attachments ?: array();
    }

    function setAttachments(GenericAttachments $att) {
        $this->attachments = $att;
    }

    function getFiles() {
        if (!isset($this->files)) {
            $files = array();
            foreach ($this->getAttachments() as $a) {
                if ($a && ($f=$a->getFile()))
                    $files[] = $f;
            }

            foreach ($this->getClean(false) ?: array() as $key => $value)
                $files[] = array('id' => $key, 'name' => $value);

            $this->files = $files;
        }
        return $this->files;
    }

    function getConfiguration() {
        global $cfg;

        $config = parent::getConfiguration();
        // If no size present default to system setting
        $config['size'] ??= $cfg->getMaxFileSize();
        $_types = self::getFileTypes();
        $mimetypes = array();
        $extensions = array();
        if (isset($config['mimetypes']) && is_array($config['mimetypes'])) {
            foreach ($config['mimetypes'] as $type=>$desc) {
                foreach ($_types[$type]['types'] as $mime=>$exts) {
                    $mimetypes[$mime] = true;
                    if (is_array($exts))
                        foreach ($exts as $ext)
                            $extensions['.'.$ext] = true;
                }
            }
        }
        if (strpos($config['extensions'], '.*') !== false)
            $config['extensions'] = '';

        if (is_string($config['extensions'])) {
            foreach (preg_split('/\s+/', str_replace(',',' ', $config['extensions'])) as $ext) {
                if (!$ext) {
                    continue;
                }
                elseif (strpos($ext, '/')) {
                    $mimetypes[$ext] = true;
                }
                else {
                    if ($ext[0] != '.')
                        $ext = '.' . $ext;

                    // Ensure that the extension is lower-cased for comparison latr
                    $ext = strtolower($ext);

                    // Add this to the MIME types list so it can be exported to
                    // the @accept attribute
                    if (!isset($extensions[$ext]))
                        $mimetypes[$ext] = true;

                    $extensions[$ext] = true;
                }
            }
            $config['__extensions'] = array_keys($extensions);
        }
        elseif (is_array($config['extensions'])) {
            $config['__extensions'] = $config['extensions'];
        }

        // 'mimetypes' is the array represented from the user interface,
        // '__mimetypes' is a complete list of supported MIME types.
        $config['__mimetypes'] = array_keys($mimetypes);
        return $config;
    }

    // When the field is saved to database, encode the ID listing as a json
    // array. Then, inspect the difference between the files actually
    // attached to this field
    function to_database($value) {
        $this->getAttachments();
        if (isset($this->attachments) && $this->attachments) {
            $this->attachments->keepOnlyFileIds($value);
        }
        return JsonDataEncoder::encode($value) ?? NULL;
    }

    function parse($value) {
        return $value;
    }

    function to_php($value) {
        return is_array($value) ? $value : JsonDataParser::decode($value);
    }

    function display($value) {
        $links = array();
        foreach ($this->getAttachments() as $a) {
            $links[] = sprintf('<a class="no-pjax" href="%s"><i class="icon-paperclip icon-flip-horizontal"></i> %s</a>',
                Format::htmlchars($a->file->getDownloadUrl()),
                Format::htmlchars($a->getFilename()));
        }
        return implode('<br/>', $links);
    }

    function toString($value) {
        $files = array();
        foreach ($this->getFiles() as $f) {
            $files[] = $f->name;
        }
        return implode(', ', $files);
    }

    function db_cleanup($field=false) {
        if ($this->getAttachments()) {
            $this->attachments->deleteAll();
        }
    }

    function asVar($value, $id=false) {
        if (($attachments = $this->getAttachments()))
            $attachments = $attachments->all();

        return new FileFieldAttachments($attachments ?: array());
    }
    function asVarType() {
        return 'FileFieldAttachments';
    }

    function whatChanged($before, $after) {
        $B = (array) $before;
        $A = (array) $after;
        $added = array_diff($A, $B);
        $deleted = array_diff($B, $A);
        $added = Format::htmlchars(array_values($added));
        $deleted = Format::htmlchars(array_values($deleted));

        if ($added && $deleted) {
            $desc = sprintf(
                __('added <strong>%1$s</strong> and removed <strong>%2$s</strong>'),
                implode(', ', $added), implode(', ', $deleted));
        }
        elseif ($added) {
            $desc = sprintf(
                __('added <strong>%1$s</strong>'),
                implode(', ', $added));
        }
        elseif ($deleted) {
            $desc = sprintf(
                __('removed <strong>%1$s</strong>'),
                implode(', ', $deleted));
        }
        else {
            $desc = sprintf(
                __('changed from <strong>%1$s</strong> to <strong>%2$s</strong>'),
                $this->display($before), $this->display($after));
        }
        return $desc;
    }
}


class FileFieldAttachments {
    var $attachments;

    function __construct($attachments) {
        $this->attachments = $attachments;
    }

    function __toString() {
        $files = array();
        foreach ($this->getAttachments() as $a) {
            $files[] = $a->getFilename();
        }
        return implode(', ', $files);
    }

    function getAttachments() {
        return $this->attachments ?: array();
    }

    function getVar($tag) {
        switch ($tag) {
        case 'names':
            return $this->__toString();
        case 'files':
            throw new OOBContent(OOBContent::FILES, $this->getAttachments());
        }
    }

    static function getVarScope() {
        return array(
            'names' => __('List of file names'),
            'files' => __('Attached files'),
        );
    }
}
