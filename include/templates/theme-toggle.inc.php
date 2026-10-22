<?php
/**
 * include/templates/theme-toggle.inc.php
 *
 * Botones que alternan el tema (claro/oscuro) y la densidad (cómoda/compacta).
 * Los incluyen las cabeceras del panel de agentes y del portal del cliente.
 *
 * El estado lo lleva js/theme.js (ver ese archivo para la prioridad de
 * preferencias). Aquí solo hay marcado accesible:
 *   - <button> real, no un enlace: no navega a ninguna parte.
 *   - aria-pressed refleja si el tema oscuro está activo.
 *   - El texto para lectores de pantalla se actualiza desde JS.
 *   - Los iconos son SVG en línea: no dependen de la fuente de iconos ni de
 *     una petición extra.
 *
 * El CSS que los muestra/oculta vive en css/dark.css (sección «Selector de
 * tema»), cargado tanto en el panel como en el portal.
 */
if (!defined('INCLUDE_DIR'))
    return;
?>
<button type="button" class="ost-icon-button ost-theme-toggle no-pjax"
        data-ost-theme-toggle
        data-label-to-dark="<?php echo Format::htmlchars(__('Switch to dark theme')); ?>"
        data-label-to-light="<?php echo Format::htmlchars(__('Switch to light theme')); ?>"
        title="<?php echo Format::htmlchars(__('Switch to dark theme')); ?>"
        aria-pressed="false">
    <svg class="ost-theme-icon ost-icon-moon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
        <path d="M6.5 1.2a6.8 6.8 0 0 0 8.3 8.3A6.8 6.8 0 1 1 6.5 1.2z"/>
    </svg>
    <svg class="ost-theme-icon ost-icon-sun" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
        <path d="M8 4.6A3.4 3.4 0 1 0 8 11.4 3.4 3.4 0 0 0 8 4.6zM8 1.5a.7.7 0 0 1 .7.7v1a.7.7 0 1 1-1.4 0v-1a.7.7 0 0 1 .7-.7zm0 10.6a.7.7 0 0 1 .7.7v1a.7.7 0 1 1-1.4 0v-1a.7.7 0 0 1 .7-.7zM1.5 7.3h1a.7.7 0 1 1 0 1.4h-1a.7.7 0 1 1 0-1.4zm12 0h1a.7.7 0 1 1 0 1.4h-1a.7.7 0 1 1 0-1.4zM3.4 2.9a.7.7 0 0 1 1 0l.7.7a.7.7 0 1 1-1 1l-.7-.7a.7.7 0 0 1 0-1zm7.5 7.5a.7.7 0 0 1 1 0l.7.7a.7.7 0 1 1-1 1l-.7-.7a.7.7 0 0 1 0-1zM12.6 2.9a.7.7 0 0 1 0 1l-.7.7a.7.7 0 1 1-1-1l.7-.7a.7.7 0 0 1 1 0zM5.1 10.4a.7.7 0 0 1 0 1l-.7.7a.7.7 0 1 1-1-1l.7-.7a.7.7 0 0 1 1 0z"/>
    </svg>
    <span class="sr-only"><?php echo __('Switch to dark theme'); ?></span>
</button>
<button type="button" class="ost-icon-button ost-density-toggle no-pjax"
        data-ost-density-toggle
        data-label-compact="<?php echo Format::htmlchars(__('Switch to compact spacing')); ?>"
        data-label-comfy="<?php echo Format::htmlchars(__('Switch to comfortable spacing')); ?>"
        title="<?php echo Format::htmlchars(__('Switch to compact spacing')); ?>"
        aria-pressed="false">
    <svg class="ost-theme-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
        <path d="M2 2.6h12a.6.6 0 0 1 0 1.2H2a.6.6 0 0 1 0-1.2zm0 4.6h12a.6.6 0 0 1 0 1.2H2a.6.6 0 0 1 0-1.2zm0 4.6h12a.6.6 0 0 1 0 1.2H2a.6.6 0 0 1 0-1.2z"/>
    </svg>
    <span class="sr-only"><?php echo __('Switch to compact spacing'); ?></span>
</button>
<span id="ost-theme-live" class="sr-only" role="status" aria-live="polite"></span>
