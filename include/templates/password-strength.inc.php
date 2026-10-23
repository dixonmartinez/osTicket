<?php
/**
 * include/templates/password-strength.inc.php
 *
 * Carga js/password-strength.js con sus textos traducidos. Lo incluyen los
 * pies del portal del cliente y del panel de agentes, porque el medidor se
 * usa en los dos sitios (alta del cliente, alta de usuario en el panel y
 * diálogo de cambio de contraseña del agente).
 *
 * El script no necesita marcado especial: reconoce por sí solo los campos
 * `passwd1`/`passwd2` de los formularios de osTicket. Los atributos
 * `data-password-strength` y `data-password-confirm` siguen existiendo para
 * los campos que quieran salirse de esa convención.
 */
if (!defined('INCLUDE_DIR'))
    return;
?>
<script nonce="<?php echo Http::nonce(); ?>" type="text/javascript">
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
