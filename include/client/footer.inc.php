        </div>
    </div>
    <div id="footer">
        <p><?php echo __('Copyright &copy;'); ?> <?php echo date('Y'); ?> <?php
        echo Format::htmlchars((string) $ost->company ?: 'osTicket.com'); ?> - <?php echo __('All rights reserved.'); ?></p>
        <a id="poweredBy" href="https://osticket.com" target="_blank"><?php echo __('Helpdesk software - powered by osTicket'); ?></a>
    </div>
<div id="overlay"></div>
<div id="loading">
    <h4><?php echo __('Please Wait!');?></h4>
    <p><?php echo __('Please wait... it will take a second!');?></p>
</div>
<?php
if (($lang = Internationalization::getCurrentLanguage()) && $lang != 'en_US') { ?>
    <script type="text/javascript" src="<?php echo ROOT_PATH; ?>ajax.php/i18n/<?php
        echo $lang; ?>/js"></script>
<?php } ?>
<script type="text/javascript" src="<?php echo ROOT_PATH; ?>js/dialog-focus.js"></script>
<script type="text/javascript" src="<?php echo ROOT_PATH; ?>js/icons.js"></script>
<script type="text/javascript">
    // Textos del indicador de fuerza de la contraseña (js/password-strength.js)
    window.OST_PW_STRENGTH = {
        weak: <?php echo json_encode(__('Weak')); ?>,
        fair: <?php echo json_encode(__('Fair')); ?>,
        good: <?php echo json_encode(__('Good')); ?>,
        strong: <?php echo json_encode(__('Strong')); ?>,
        empty: <?php echo json_encode(__('Use at least 8 characters, with numbers and symbols.')); ?>,
        hintShort: <?php echo json_encode(__('Add length and variety of characters.')); ?>,
        mismatch: <?php echo json_encode(__('The passwords do not match.')); ?>
    };
</script>
<script type="text/javascript" src="<?php echo ROOT_PATH; ?>js/password-strength.js"></script>
<script type="text/javascript">
    getConfig().resolve(<?php
        include INCLUDE_DIR . 'ajax.config.php';
        $api = new ConfigAjaxAPI();
        print $api->client(false);
    ?>);
</script>
</body>
</html>
