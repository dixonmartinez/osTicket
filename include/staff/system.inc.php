<?php
if(!defined('OSTADMININC') || !$thisstaff || !$thisstaff->isAdmin()) die('Access Denied');

$commit = GIT_VERSION != '$git' ? GIT_VERSION : (
    // audit:ignore OST-RCE -- fixed command, displayed to admins only
    @shell_exec('git rev-parse HEAD | cut -b 1-8') ?: '?');

$extensions = array(
        'gd' => array(
            'name' => 'gdlib',
            'desc' => __('Used for image manipulation and PDF printing')
            ),
        'iconv' => array(
            'name' => 'iconv',
            'desc' => __('Useful for email processing')
            ),
        'imap' => array(
            'name' => 'imap',
            'desc' => __('Useful for email processing')
            ),
        'ctype' => array(
            'name' => 'ctype',
            'desc' => __('Required for email fetching')
            ),
        'xml' => array(
            'name' => 'xml',
            'desc' => __('XML API')
            ),
        'dom' => array(
            'name' => 'xml-dom',
            'desc' => __('Used for HTML email processing')
            ),
        'json' => array(
            'name' => 'json',
            'desc' => __('Improves performance creating and processing JSON')
            ),
        'mbstring' => array(
            'name' => 'mbstring',
            'desc' => __('Highly recommended for non western european language content')
            ),
        'phar' => array(
            'name' => 'phar',
            'desc' => __('Highly recommended for plugins and language packs')
            ),
        'intl' => array(
            'name' => 'intl',
            'desc' => __('Highly recommended for non western european language content')
            ),
        'fileinfo' => array(
            'name' => 'fileinfo',
            'desc' => __('Used to detect file types for uploads')
            ),
        'zip' => array(
            'name' => 'zip',
            'desc' => __('Used for ticket and task exporting')
            ),
        'apcu' => array(
            'name' => 'APCu',
            'desc' => __('Improves overall performance')
            ),
        'Zend Opcache' => array(
            'name' => 'Zend Opcache',
            'desc' => __('Improves overall performance')
            ),
        );

?>
<h2><?php echo __('About this osTicket Installation'); ?></h2>
<table class="list" width="100%";>
<thead>
    <tr><th colspan="2"><?php echo __('Server Information'); ?></th></tr>
</thead>
<tbody>
    <tr><td><?php echo __('osTicket Version'); ?></td>
        <td><span class="ltr"><?php
            echo sprintf("%s (%s)", THIS_VERSION, trim($commit)); ?></span>
<?php
$lv = $ost->getLatestVersion('core', MAJOR_VERSION);
$tv = THIS_VERSION;
// audit:ignore OST-RCE -- fixed command, displayed to admins only
$gv = (GIT_VERSION == '$git') ? substr(@shell_exec('git rev-parse HEAD'), 0, 7) : (false ?: GIT_VERSION);
if ($lv && $tv[0] == 'v' ? version_compare(THIS_VERSION, $lv, '>=') : $lv == $gv) { ?>
    — <span style="color:green"><i class="icon-check"></i> <?php echo __('Up to date'); ?></span>
<?php
}
else {
    // Report current version (v1.9.x ?: deadbeef ?: $git)
    $cv = $tv[0] == 'v' ? $tv : $gv;
?>
      <a class="green button action-button pull-right"
         href="https://osticket.com/download?cv=<?php echo $cv; ?>"><i class="icon-rocket"></i>
        <?php echo __('Upgrade'); ?></a>
<?php if ($lv) { ?>
      <strong> — <?php echo str_replace(
          '%s', $lv, __("%s is available")
      ); ?></strong>
<?php }
}
if (!$lv) { ?>
    <strong> — <?php echo __('This osTicket version is no longer supported. Please consider upgrading');
        ?></strong>
<?php
}
?>
    </td></tr>
    <tr><td><?php echo __('Web Server Software'); ?></td>
        <td><span class="ltr"><?php echo Format::htmlchars($_SERVER['SERVER_SOFTWARE'] ?? ''); ?></span></td></tr>
    <tr><td><?php echo __('MySQL Version'); ?></td>
        <td><span class="ltr"><?php echo db_version(); ?></span></td></tr>
    <tr><td><?php echo __('PHP Version'); ?></td>
        <td><span class="ltr"><?php echo phpversion(); ?></span></td></tr>
</tbody>
<thead>
    <tr><th colspan="2"><?php echo __('PHP Extensions'); ?></th></tr>
</thead>
<tbody>
    <?php
    foreach($extensions as $ext => $info) { ?>
    <tr><td><?php echo $info['name']; ?></td>
        <td><?php
            echo sprintf('<i class="icon icon-%s"></i> %s',
                    extension_loaded($ext) ? 'check' : 'warning-sign',
                    $info['desc']);
            ?>
        </td>
    </tr>
    <?php
    } ?>
</tbody>
<thead>
    <tr><th colspan="2"><?php echo __('PHP Settings'); ?></th></tr>
</thead>
<tbody>
    <tr>
        <td><span class="ltr"><code>cgi.fix_pathinfo</code></span></td>
        <td><i class="icon icon-<?php
                echo ini_get('cgi.fix_pathinfo') == 1 ? 'check' : 'warning-sign'; ?>"></i>
                <span class="faded"><?php echo __('"1" is recommended if AJAX is not working'); ?></span>
        </td>
    </tr>
    <tr>
        <td><span class="ltr"><code>date.timezone</code></span></td>
        <td><i class="icon icon-<?php
                echo ini_get('date.timezone') ? 'check' : 'warning-sign'; ?>"></i>
                <span class="faded"><?php
                    echo ini_get('date.timezone')
                    ?: __('Setting default timezone is highly recommended');
                    ?></span>
        </td>
    </tr>
</tbody>
<thead>
    <tr><th colspan="2"><?php echo __('Database Information and Usage'); ?></th></tr>
</thead>
<tbody>
    <tr><td><?php echo __('Schema'); ?></td>
        <td><?php echo sprintf('<span class="ltr">%s (%s)</span>', DBNAME, DBHOST); ?> </td></tr>
    </tr>
    <tr><td><?php echo __('Schema Signature'); ?></td>
        <td><?php echo $cfg->getSchemaSignature(); ?> </td>
    </tr>
    <tr><td><?php echo __('Space Used'); ?></td>
        <td><?php
        $sql = 'SELECT sum( data_length + index_length ) / 1048576 total_size
            FROM information_schema.TABLES WHERE table_schema = '
            .db_input(DBNAME);
        $space = db_result(db_query($sql));
        echo sprintf('%.2f MiB', $space); ?></td>
    <tr><td><?php echo __('Space for Attachments'); ?></td>
        <td><?php
        $sql = 'SELECT
                    (DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024
                FROM
                    information_schema.TABLES
                WHERE
                    TABLE_SCHEMA = "'.DBNAME.'"
                AND
                    TABLE_NAME = "'.FILE_CHUNK_TABLE.'"
                ORDER BY
                    (DATA_LENGTH + INDEX_LENGTH)
                DESC';
        $space = db_result(db_query($sql));
        echo sprintf('%.2f MiB', $space); ?></td></tr>
    <tr><td><?php echo __('Timezone'); ?></td>
        <td><?php echo $dbtz = db_timezone(); ?>
          <?php if ($cfg->getDbTimezone() != $dbtz) { ?>
            (<?php echo sprintf(__('Interpreted as %s'), $cfg->getDbTimezone()); ?>)
          <?php } ?>
        </td></tr>
</tbody>
</table>
<br/>
<h2><?php echo __('Security Posture'); ?>
    <i class="help-tip icon-question-sign" href="#security_posture"></i></h2>
<?php
    $posture = class_exists('SecurityPosture') ? SecurityPosture::getIssues($cfg) : [];
    if (!$posture) { ?>
    <p class="notice-banner">
        <i class="icon-ok"></i>
        <?php echo __('No environment issues detected. Keep PHP and the application up to date.'); ?>
    </p>
<?php } else { ?>
    <table class="list" width="100%">
        <thead>
            <tr>
                <th width="10%"><?php echo __('Level'); ?></th>
                <th><?php echo __('Finding'); ?></th>
                <th width="18%"><?php echo __('Current'); ?></th>
                <th width="18%"><?php echo __('Recommended'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($posture as $issue) { ?>
            <tr>
                <td><span class="ui-badge ui-badge--<?php echo Format::htmlchars($issue['level']); ?>">
                    <?php echo Format::htmlchars(__(ucfirst($issue['level']))); ?></span></td>
                <td><?php echo Format::htmlchars(__($issue['message'])); ?></td>
                <td><code><?php echo Format::htmlchars($issue['current']); ?></code></td>
                <td><code><?php echo Format::htmlchars($issue['recommended']); ?></code></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <p class="faded">
        <?php echo __('php2shell references: CVE-2026-109618 and CVE-2026-109619. Patching PHP is the only complete fix.'); ?>
        &nbsp;<a href="https://www.php.net/archive/2026.php" target="_blank" rel="noopener noreferrer">
            <?php echo __('PHP security releases'); ?></a>
    </p>
<?php } ?>
<br/>
<h2><?php echo __('Installed Language Packs'); ?></h2>
<div style="margin: 0 20px">
<?php
    foreach (Internationalization::availableLanguages() as $info) {
        $p = $info['path'];
        if ($info['phar'])
            $p = 'phar://' . $p;
        $manifest = (file_exists($p . '/MANIFEST.php')) ? (include $p . '/MANIFEST.php') : null;
?>
    <h3><strong><?php echo Internationalization::getLanguageDescription($info['code']); ?></strong>
        <?php if ($manifest) { ?>
            &mdash; <?php echo $manifest['Language']; ?>
        <?php } ?>
<?php   if ($info['phar'])
            PluginManager::showVerificationBadge($info['path']); ?>
        </h3>
        <div><?php echo sprintf('<code>%s</code> — %s', $info['code'],
                str_replace(ROOT_DIR, '', $info['path'])); ?>
<?php   if ($manifest) { ?>
            <br/> <?php echo __('Version'); ?>: <?php echo $manifest['Version'];
                ?>, <?php echo sprintf(__('for version %s'),
                    'v'.($manifest['Phrases-Version'] ?: '1.9')); ?>
            <br/> <?php echo __('Built'); ?>: <?php echo $manifest['Build-Date']; ?>
<?php   } ?>
        </div>
<?php
    } ?>
</div>
