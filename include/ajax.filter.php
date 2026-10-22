<?php

require_once(INCLUDE_DIR . 'class.filter.php');

class FilterAjaxAPI extends AjaxController {

    function getFilterActionForm($type) {
        if (!($A = FilterAction::lookupByType($type)))
            Http::response(404, 'No such filter action type');

        $form = $A->getConfigurationForm();
        ?>
        <div style="position:relative">
            <div class="pull-right" style="position:absolute;top:2px;right:2px;">
                <a href="#" title="<?php echo __('clear'); ?>" data-confirm="<?php echo __('You sure?'); ?>" data-remove-closest="tr"><i class="icon-trash"></i></a>
            </div>
        <?php
        include STAFFINC_DIR . 'templates/dynamic-form-simple.tmpl.php';
        ?>
        </div>
        <?php
    }

}
