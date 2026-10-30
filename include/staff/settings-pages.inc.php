<?php
if(!defined('OSTADMININC') || !$thisstaff || !$thisstaff->isAdmin() || !$config) die('Access Denied');
$pages = Page::getPages();
?>
<h2><?php echo __('Company Profile'); ?></h2>
<form action="settings.php?t=pages" method="post" class="save"
    enctype="multipart/form-data">
<?php csrf_token(); ?>



<input type="hidden" name="t" value="pages" >

<ul class="clean tabs">
    <li class="active"><a href="#basic-information"><i class="icon-asterisk"></i>
        <?php echo __('Basic Information'); ?></a></li>
    <li><a href="#site-pages"><i class="icon-file"></i>
        <?php echo __('Site Pages'); ?></a></li>
    <li><a href="#logos"><i class="icon-picture"></i>
        <?php echo __('Logos'); ?></a></li>
    <li><a href="#backdrops"><i class="icon-picture"></i>
        <?php echo __('Login Backdrop'); ?></a></li>
</ul>

<div class="tab_content" id="basic-information">
<table class="form_table settings_table" width="940" border="0" cellspacing="0" cellpadding="2">
    <tbody>
    <?php
        $form = $ost->company->getForm();
        $form->addMissingFields();
        $form->render();
    ?>
    </tbody>
</table>
</div>
<div class="hidden tab_content" id="site-pages">
<table class="form_table settings_table" width="940" border="0" cellspacing="0" cellpadding="2">
    <thead>
        <tr>
            <th colspan="2">
                <em><?php echo sprintf(__(
                'To edit or add new pages go to %s Manage &gt; Site Pages %s'),
                '<a href="pages.php">','</a>'); ?></em>
            </th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td width="220" class="required"><?php echo __('Landing Page'); ?>:</td>
            <td>
                <span>
                <select name="landing_page_id">
                    <option value="">&mdash; <?php echo __('Select Landing Page'); ?> &mdash;</option>
                    <?php
                    foreach($pages as $page) {
                        if(strcasecmp($page->getType(), 'landing')) continue;
                        echo sprintf('<option value="%d" %s>%s</option>',
                                $page->getId(),
                                ($config['landing_page_id']==$page->getId())?'selected="selected"':'',
                                $page->getName());
                    } ?>
                </select>&nbsp;<font class="error"><?php echo $errors['landing_page_id']; ?></font>
                <i class="help-tip icon-question-sign" href="#landing_page"></i>
                </span>
            </td>
        </tr>
        <tr>
            <td width="220" class="required"><?php echo __('Offline Page'); ?>:</td>
            <td>
                <span>
                <select name="offline_page_id">
                    <option value="">&mdash; <?php echo __('Select Offline Page');
                        ?> &mdash;</option>
                    <?php
                    foreach($pages as $page) {
                        if(strcasecmp($page->getType(), 'offline')) continue;
                        echo sprintf('<option value="%d" %s>%s</option>',
                                $page->getId(),
                                ($config['offline_page_id']==$page->getId())?'selected="selected"':'',
                                $page->getName());
                    } ?>
                </select>&nbsp;<font class="error"><?php echo $errors['offline_page_id']; ?></font>
                <i class="help-tip icon-question-sign" href="#offline_page"></i>
                </span>
            </td>
        </tr>
        <tr>
            <td width="220" class="required"><?php
                echo __('Default Thank-You Page'); ?>:</td>
            <td>
                <span>
                <select name="thank-you_page_id">
                    <option value="">&mdash; <?php
                        echo __('Select Thank-You Page'); ?> &mdash;</option>
                    <?php
                    foreach($pages as $page) {
                        if(strcasecmp($page->getType(), 'thank-you')) continue;
                        echo sprintf('<option value="%d" %s>%s</option>',
                                $page->getId(),
                                ($config['thank-you_page_id']==$page->getId())?'selected="selected"':'',
                                $page->getName());
                    } ?>
                </select>&nbsp;<font class="error"><?php echo $errors['thank-you_page_id']; ?></font>
                <i class="help-tip icon-question-sign" href="#default_thank_you_page"></i>
                </span>
            </td>
        </tr>
    </tbody>
</table>
</div>
<div class="hidden tab_content" id="logos">
<div class="media-picker">
    <div class="media-picker-head">
        <h3><?php echo __('System Default Logo'); ?>
            <i class="help-tip icon-question-sign" href="#logos"></i></h3>
        <p class="faded"><?php echo __('Choose the logo shown in the client portal and in the staff panel. '
            . 'Upload a new one and then select it on either side.'); ?></p>
    </div>
<?php
$client_logo = $ost->getConfig()->getClientLogoId();
$staff_logo = $ost->getConfig()->getStaffLogoId();
?>
    <ul class="media-list">
        <li class="media-item">
            <div class="media-thumb">
                <img src="<?php echo ROOT_PATH; ?>assets/default/images/logo.png"
                     alt="<?php echo __('Default client logo'); ?>"/>
                <img src="<?php echo ROOT_PATH; ?>scp/images/ost-logo.png"
                     alt="<?php echo __('Default staff logo'); ?>"/>
            </div>
            <div class="media-info">
                <strong><?php echo __('Default'); ?></strong>
                <span class="faded"><?php echo __('Shipped with osTicket'); ?></span>
            </div>
            <div class="media-choices">
                <label class="media-choice <?php if (!$client_logo) echo 'is-active'; ?>">
                    <input type="radio" name="selected-logo" value="0" <?php
                    if (!$client_logo) echo 'checked="checked"'; ?>/>
                    <span><?php echo __('Client'); ?></span>
                </label>
                <label class="media-choice <?php if (!$staff_logo) echo 'is-active'; ?>">
                    <input type="radio" name="selected-logo-scp" value="0" <?php
                    if (!$staff_logo) echo 'checked="checked"'; ?>/>
                    <span><?php echo __('Staff'); ?></span>
                </label>
            </div>
        </li>
<?php foreach (AttachmentFile::allLogos() as $logo) {
        $isClient = ($logo->getId() == $client_logo);
        $isStaff = ($logo->getId() == $staff_logo); ?>
        <li class="media-item">
            <div class="media-thumb">
                <img src="<?php echo $logo->getDownloadUrl(); ?>"
                     alt="<?php echo Format::htmlchars($logo->getName()); ?>"/>
            </div>
            <div class="media-info">
                <strong><?php echo Format::htmlchars($logo->getName()); ?></strong>
                <span class="faded"><?php echo Format::file_size($logo->getSize()); ?></span>
            </div>
            <div class="media-choices">
                <label class="media-choice <?php if ($isClient) echo 'is-active'; ?>">
                    <input type="radio" name="selected-logo"
                        value="<?php echo $logo->getId(); ?>" <?php
                        if ($isClient) echo 'checked="checked"'; ?>/>
                    <span><?php echo __('Client'); ?></span>
                </label>
                <label class="media-choice <?php if ($isStaff) echo 'is-active'; ?>">
                    <input type="radio" name="selected-logo-scp"
                        value="<?php echo $logo->getId(); ?>" <?php
                        if ($isStaff) echo 'checked="checked"'; ?>/>
                    <span><?php echo __('Staff'); ?></span>
                </label>
            </div>
            <div class="media-actions">
<?php if (!$isClient && !$isStaff) { ?>
                <label class="media-danger">
                    <input type="checkbox" name="delete-logo[]" value="<?php
                    echo $logo->getId(); ?>"/>
                    <?php echo __('Delete'); ?>
                </label>
<?php } else { ?>
                <span class="media-inuse"><?php echo __('In use'); ?></span>
<?php } ?>
            </div>
        </li>
<?php } ?>
    </ul>
    <div class="media-upload">
        <label for="logo-file"><i class="icon-upload"></i>
            <?php echo __('Upload a new logo'); ?></label>
        <input type="file" id="logo-file" name="logo[]" size="30" value=""/>
        <span class="error"><?php echo $errors['logo']; ?></span>
    </div>
</div>
</div>

<div class="hidden tab_content" id="backdrops">
<div class="media-picker">
    <div class="media-picker-head">
        <h3><?php echo __('System Default Backdrop'); ?>
            <i class="help-tip icon-question-sign" href="#backdrops"></i></h3>
        <p class="faded"><?php echo __('Background image of the staff login page.'); ?></p>
    </div>
<?php $backdrop_id = $ost->getConfig()->getStaffLoginBackdropId(); ?>
    <ul class="media-list">
        <li class="media-item is-wide">
            <div class="media-thumb">
                <img src="<?php echo ROOT_PATH; ?>scp/images/login-headquarters.jpg"
                     alt="<?php echo __('Default backdrop'); ?>"/>
            </div>
            <div class="media-info">
                <strong><?php echo __('Default'); ?></strong>
                <span class="faded"><?php echo __('Shipped with osTicket'); ?></span>
            </div>
            <div class="media-choices">
                <label class="media-choice <?php if (!$backdrop_id) echo 'is-active'; ?>">
                    <input type="radio" name="selected-backdrop" value="0" <?php
                    if (!$backdrop_id) echo 'checked="checked"'; ?>/>
                    <span><?php echo __('Staff'); ?></span>
                </label>
            </div>
        </li>
<?php foreach (AttachmentFile::allBackdrops() as $backdrop) {
        $isCurrent = ($backdrop->getId() == $backdrop_id); ?>
        <li class="media-item is-wide">
            <div class="media-thumb">
                <img src="<?php echo $backdrop->getDownloadUrl(); ?>"
                     alt="<?php echo Format::htmlchars($backdrop->getName()); ?>"/>
            </div>
            <div class="media-info">
                <strong><?php echo Format::htmlchars($backdrop->getName()); ?></strong>
                <span class="faded"><?php echo Format::file_size($backdrop->getSize()); ?></span>
            </div>
            <div class="media-choices">
                <label class="media-choice <?php if ($isCurrent) echo 'is-active'; ?>">
                    <input type="radio" name="selected-backdrop"
                        value="<?php echo $backdrop->getId(); ?>" <?php
                        if ($isCurrent) echo 'checked="checked"'; ?>/>
                    <span><?php echo __('Staff'); ?></span>
                </label>
            </div>
            <div class="media-actions">
<?php if (!$isCurrent) { ?>
                <label class="media-danger">
                    <input type="checkbox" name="delete-backdrop[]" value="<?php
                    echo $backdrop->getId(); ?>"/>
                    <?php echo __('Delete'); ?>
                </label>
<?php } else { ?>
                <span class="media-inuse"><?php echo __('In use'); ?></span>
<?php } ?>
            </div>
        </li>
<?php } ?>
    </ul>
    <div class="media-upload">
        <label for="backdrop-file"><i class="icon-upload"></i>
            <?php echo __('Upload a new backdrop'); ?></label>
        <input type="file" id="backdrop-file" name="backdrop[]" size="30" value=""/>
        <span class="error"><?php echo $errors['backdrop']; ?></span>
    </div>
</div>
</div>
<p class="form-actions">
    <input class="button" type="reset" name="reset" value="<?php
    echo __('Reset Changes'); ?>">
    <input class="button" type="submit" name="submit-button" value="<?php
    echo __('Save Changes'); ?>">
</p>
</form>

<div style="display:none;" class="dialog" id="confirm-action">
    <h3><?php echo __('Please Confirm'); ?></h3>
    <a class="close" href=""><i class="icon-remove-circle"></i></a>
    <hr/>
    <p class="confirm-action" id="delete-confirm">
        <font color="red"><strong><?php echo sprintf(
        __('Are you sure you want to DELETE %s?'),
        _N('selected image', 'selected images', 2)); ?></strong></font>
        <br/><br/><?php echo __('Deleted data CANNOT be recovered.'); ?>
    </p>
    <div><?php echo __('Please confirm to continue.'); ?></div>
    <hr style="margin-top:1em"/>
    <p class="full-width">
        <span class="buttons pull-left">
            <input type="button" value="<?php echo __('No, Cancel'); ?>" class="close">
        </span>
        <span class="buttons pull-right">
            <input type="button" value="<?php echo __('Yes, Do it!'); ?>" class="confirm">
        </span>
     </p>
    <div class="clear"></div>
</div>

<script nonce="<?php echo Http::nonce(); ?>" type="text/javascript">
(function() {
    // El formulario de esta pantalla no tiene `id="save"`: el manejador
    // antiguo se enganchaba a `#save` y no se ejecutaba nunca, asi que marcar
    // «Delete» y pulsar «Save Changes» borraba la imagen sin preguntar.
    //
    // Se escucha en la fase de captura para adelantarse al manejador generico
    // de `form.save` (scp/js/scp.js), que deshabilita los botones al enviar:
    // si el aviso se cancelara despues, la pantalla quedaria con el boton de
    // guardar deshabilitado. Y el envio confirmado se hace con el metodo
    // nativo, que no dispara otra vez los manejadores de jQuery. No se puede
    // escribir `form.submit()`: el formulario tiene un campo con ese nombre.
    var confirmed = false,
        pending = null;

    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (!form || !form.matches || !form.matches('form.save'))
            return;
        if (confirmed
                || !form.querySelectorAll('input[name^="delete-"]:checked').length)
            return;

        e.preventDefault();
        e.stopPropagation();
        pending = form;

        var dialog = document.getElementById('confirm-action');
        dialog.style.display = 'block';
        $.toggleOverlay(true);
    }, true);

    $(function() {
        var $dialog = $('.dialog#confirm-action');

        $dialog.find('input.confirm').on('click', function(e) {
            e.preventDefault();
            $dialog.hide();
            $.toggleOverlay(false);
            if (!pending)
                return;
            confirmed = true;
            HTMLFormElement.prototype.submit.call(pending);
        });

        $dialog.find('a.close, input.close').on('click', function(e) {
            e.preventDefault();
            $dialog.hide();
            $.toggleOverlay(false);
            pending = null;
        });

        // La opcion marcada de la galeria se resalta en el servidor
        // (`is-active`); al cambiar de radio hay que moverla a mano o el
        // resaltado se queda en la imagen que estaba antes.
        var $picker = $('.media-picker');
        $picker.on('change', 'input[type=radio]', function() {
            var name = this.name;
            $picker.find('input[type=radio]').filter(function() {
                return this.name === name;
            }).each(function() {
                $(this).closest('.media-choice')
                    .toggleClass('is-active', this.checked);
            });
        });

        // Lo que se va a borrar se marca en la propia fila: el aviso llega
        // despues, pero antes de guardar ya se ve que imagen esta condenada.
        $picker.on('change', 'input[name^="delete-"]', function() {
            $(this).closest('.media-item').toggleClass('is-doomed', this.checked);
        });
    });
})();
</script>
