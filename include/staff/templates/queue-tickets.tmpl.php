<?php
// Calling convention (assumed global scope):
// $tickets - <QuerySet> with all columns and annotations necessary to
//      render the full page


// Impose visibility constraints
// ------------------------------------------------------------
//filter if limited visibility or if unlimited visibility and in a queue
$ignoreVisibility = $queue->ignoreVisibilityConstraints($thisstaff);
if (!$ignoreVisibility || //limited visibility
   ($ignoreVisibility && ($queue->isAQueue() || $queue->isASubQueue())) //unlimited visibility + not a search
)
    $tickets->filter($thisstaff->getTicketsVisibility());

// do not show children tickets unless agent is doing a search
if ($queue->isAQueue() || $queue->isASubQueue())
    $tickets->filter(Q::any(
            array('ticket_pid' => null, 'flags__hasbit' => TICKET::FLAG_LINKED)));

// Make sure the cdata materialized view is available
TicketForm::ensureDynamicDataView();

// Identify columns of output
$columns = $queue->getColumns();

// Figure out REFRESH url — which might not be accurate after posting a
// response
// Remove some variables from query string.
$qsFilter = ['id'];
if (isset($_REQUEST['a']) && ($_REQUEST['a'] !== 'search'))
    $qsFilter[] = 'a';
$refresh_url = Http::refresh_url($qsFilter);

// Establish the selected or default sorting mechanism
if (isset($_GET['sort']) && is_numeric($_GET['sort'])) {
    $sort = $_SESSION['sort'][$queue->getId()] = array(
        'col' => (int) $_GET['sort'],
        'dir' => (int) $_GET['dir'],
    );
}
elseif (isset($_GET['sort'])
    // Drop the leading `qs-`
    && (strpos($_GET['sort'], 'qs-') === 0)
    && ($sort_id = substr($_GET['sort'], 3))
    && is_numeric($sort_id)
    && ($sort = QueueSort::lookup($sort_id))
) {
    $sort = $_SESSION['sort'][$queue->getId()] = array(
        'queuesort' => $sort,
        'dir' => (int) $_GET['dir'],
    );
}
elseif (isset($_SESSION['sort'][$queue->getId()])) {
    $sort = $_SESSION['sort'][$queue->getId()];
}
elseif ($queue_sort = $queue->getDefaultSort()) {
    $sort = $_SESSION['sort'][$queue->getId()] = array(
        'queuesort' => $queue_sort,
        'dir' => (int) $_GET['dir'] ?? 0,
    );
}

// Handle current sorting preferences

$sorted = false;
foreach ($columns as $C) {
    // Sort by this column ?
    if (isset($sort['col']) && $sort['col'] == $C->id) {
        $tickets = $C->applySort($tickets, $sort['dir']);
        $sorted = true;
    }
}

// Apply queue sort if it's not already sorted by a column
if (!$sorted) {
    // Apply queue sort-dropdown selected preference
    if (isset($sort['queuesort']))
        $sort['queuesort']->applySort($tickets, $sort['dir']);
    else // otherwise sort by created DESC
        $tickets->order_by('-created');
}

// Apply pagination

$page = (isset($_GET['p']) && is_numeric($_GET['p'])) ? (int) $_GET['p'] : 1;
if ($page < 1)
    $page = 1;
// Copia sin ventana: de ella sale el total que se muestra bajo la tabla. Se
// toma antes de paginar porque `paginateSimple()` mete LIMIT/OFFSET en la
// consulta que se ejecuta (y esa ya no sirve para contar). Lleva el mismo
// `distinct` que la consulta de la tabla: sin él, los JOIN del hilo y de las
// referencias (visibilidad) duplicarían filas y el total saldría inflado.
$ticketsTotal = clone $tickets;
$ticketsTotal->distinct('ticket_id');

$Q = $queue->getBasicQuery();
$empty = false;

if ($Q->constraints) {
    if (count($Q->constraints) > 1) {
        foreach ($Q->constraints as $value) {
            if (!$value->constraints)
                $empty = true;
        }
    }
}

if (($Q->extra && isset($Q->extra['tables'])) || !$Q->constraints || $empty) {
    $skipCount = true;
    $count = '-';
}
elseif (!isset($count) || $count === null) {
    // El total que se muestra sale de la **misma** consulta que llena la
    // tabla. `$queue->getCount()` no sirve para esto: cuenta el criterio de
    // la cola pero no el filtro rápido (`?filter=`), ni las columnas, ni la
    // visibilidad que se aplican aquí abajo, y además puede venir de la caché
    // de cinco minutos. Cuando los dos números no coinciden, el paginador
    // promete filas que la tabla no trae («Showing 1 - 23 of about 23» con la
    // tabla vacía).
    try {
        $count = (int) $ticketsTotal->count();
    }
    catch (Exception $e) {
        // Si la base no puede contar la consulta, se mantiene el número de
        // siempre antes que romper la página entera.
        $count = $queue->getCount($thisstaff);
    }
}

// La página pedida puede quedar más allá del final: un enlace guardado, el
// historial del navegador (`?p=2`), o un filtro que acaba de reducir la cola.
// `paginateSimple()` pone ese OFFSET tal cual, así que la tabla salía vacía
// aunque la cola tuviera 23 tickets —y el pie decía «esta cola tiene tickets,
// pero la lista no se ha podido cargar», que es falso—. Se pinta la última
// página que existe. Si el total es desconocido (`-`) no se toca nada.
if (is_int($count) && $count > 0) {
    $lastPage = (int) ceil($count / PAGE_LIMIT);
    if ($page > $lastPage)
        $page = $lastPage;
}

$pageNav = new Pagenate(PHP_INT_MAX, $page, PAGE_LIMIT);
$tickets = $pageNav->paginateSimple($tickets);

if (isset($tickets->extra['tables'])) {
    // Creative twist here. Create a new query copying the query criteria, sort, limit,
    // and offset. Then join this new query to the $tickets query and clear the
    // criteria, sort, limit, and offset from the outer query.
    $criteria = clone $tickets;
    $criteria->limit(500);
    $criteria->annotations = $criteria->related = $criteria->aggregated =
        $criteria->annotations = $criteria->ordering = [];
    $tickets->constraints = $tickets->extra = [];
    $criteria->extra(array('select' => array('relevance' => 'Z1.relevance')));
    $tickets = $tickets->filter(['ticket_id__in' =>
            $criteria->values_flat('ticket_id')]);
    $tickets->order_by(new SqlCode('relevance'), QuerySet::DESC);
    # Index hint should be used on the $criteria query only
    $tickets->clearOption(QuerySet::OPT_INDEX_HINT);
}

$tickets->distinct('ticket_id');
$pageNav->setTotal($count, true);
$pageNav->setURL('tickets.php', $args);
?>

<!-- SEARCH FORM START -->
<div id='basic_search'>
  <div class="pull-right" style="height:25px">
    <span class="valign-helper"></span>
    <?php
    require 'queue-quickfilter.tmpl.php';
    if ($queue->getSortOptions())
        require 'queue-sort.tmpl.php';
    ?>
  </div>
    <form action="tickets.php" method="get" data-pjax-submit="#pjax-container">
    <input type="hidden" name="a" value="search">
    <input type="hidden" name="search-type" value=""/>
    <div class="attached input">
      <input type="text" class="basic-search" data-url="ajax.php/tickets/lookup" name="query"
        autofocus size="30" value="<?php echo Format::htmlchars($_REQUEST['query'] ?? null, true); ?>"
        placeholder="<?php echo __('Search tickets...'); ?>"
        aria-label="<?php echo __('Search tickets'); ?>"
        autocomplete="off" autocorrect="off" autocapitalize="off">
      <button type="submit" class="attached button"><i class="icon-search"></i>
      </button>
    </div>
    <a href="#" data-ost-dialog="201" data-ost-dialog-url="ajax.php/tickets/search"
        >[<?php echo __('advanced'); ?>]</a>
        <i class="help-tip icon-question-sign" href="#advanced"></i>
    </form>
</div>
<!-- SEARCH FORM END -->

<div class="clear"></div>
<div style="margin-bottom:20px; padding-top:5px;">
    <div class="sticky bar opaque">
        <div class="content">
            <div class="pull-left flush-left">
                <h2><a href="<?php echo $refresh_url; ?>"
                    title="<?php echo __('Refresh'); ?>"><i class="icon-refresh"></i> <?php echo
                    $queue->getName(); ?></a>
                    <?php
                    if (($crit=$queue->getSupplementalCriteria()))
                        echo sprintf('<i class="icon-filter"
                                data-placement="bottom" data-toggle="tooltip"
                                title="%s"></i>&nbsp;',
                                Format::htmlchars($queue->describeCriteria($crit)));
                    ?>
                </h2>
            </div>
            <div class="configureQ">
                <i class="icon-cog"></i>
                <div class="noclick-dropdown anchor-left">
                    <ul>
                        <li>
                            <a class="no-pjax" href="#"
                              data-dialog="ajax.php/tickets/search/<?php echo
                              urlencode($queue->getId()); ?>"><i
                            class="icon-fixed-width icon-pencil"></i>
                            <?php echo __('Edit'); ?></a>
                        </li>
                        <li>
                            <a class="no-pjax" href="#"
                              data-dialog="ajax.php/tickets/search/create?pid=<?php
                              echo $queue->getId(); ?>"><i
                            class="icon-fixed-width icon-plus-sign"></i>
                            <?php echo __('Add Sub Queue'); ?></a>
                        </li>
<?php

if ($queue->id > 0 && $queue->isOwner($thisstaff)) { ?>
                        <li class="danger">
                            <a class="no-pjax confirm-action" href="#"
                                data-dialog="ajax.php/queue/<?php
                                echo $queue->id; ?>/delete"><i
                            class="icon-fixed-width icon-trash"></i>
                            <?php echo __('Delete'); ?></a>
                        </li>
<?php } ?>
                    </ul>
                </div>
            </div>

          <div class="pull-right flush-right">
            <?php
            // TODO: Respect queue root and corresponding actions
            if ($count) {
                Ticket::agentActions($thisstaff, array('status' => $status ?? null));
            }?>
            </div>
        </div>
    </div>
</div>
<div class="clear"></div>

<form action="?" method="POST" name='tickets' id="tickets">
<?php csrf_token(); ?>
 <input type="hidden" name="a" value="mass_process" >
 <input type="hidden" name="do" id="action" value="" >

<table class="list queue tickets" border="0" cellspacing="1" cellpadding="2" width="940">
  <thead>
    <tr>
<?php
$canManageTickets = $thisstaff->canManageTickets();
if ($canManageTickets) { ?>
        <th style="width:12px"></th>
<?php
}

foreach ($columns as $C) {
    $heading = Format::htmlchars($C->getLocalHeading());
    if ($C->isSortable()) {
        $args = $_GET;
        $dir = $sort['col'] != $C->id ?: ($sort['dir'] ? 'desc' : 'asc');
        $args['dir'] = $sort['col'] != $C->id ?: (int) !$sort['dir'];
        $args['sort'] = $C->id;
        $heading = sprintf('<a href="?%s" class="%s">%s</a>',
            Http::build_query($args), $dir, $heading);
    }
    echo sprintf('<th width="%s" data-id="%d">%s</th>',
        $C->getWidth(), $C->id, $heading);
}
?>
    </tr>
  </thead>
  <tbody>
<?php
$rendered = 0;
foreach ($tickets as $T) {
    $rendered++;
    echo '<tr>';
    if ($canManageTickets) { ?>
        <td><input type="checkbox" class="ckb" name="tids[]"
            value="<?php echo $T['ticket_id']; ?>" /></td>
<?php
    }
    foreach ($columns as $C) {
        list($contents, $styles) = $C->render($T);
        if ($style = $styles ? 'style="'.$styles.'"' : '') {
            echo "<td $style><div $style>$contents</div></td>";
        }
        else {
            echo "<td>$contents</td>";
        }
    }
    echo '</tr>';
}

// El total y la tabla pueden discrepar. El total sale de un COUNT sobre la
// misma consulta, pero la pagina que se pinta puede volver vacia: paso con
// la cola «Closed · This Year», que mostraba «Showing 1 - 23 of about 23»
// sobre una tabla sin una sola fila. Se mira lo que de verdad se ha pintado.
// Si no hay filas, el pie no promete ninguna y se explica el motivo en vez
// de dejar la tabla en blanco.
if (!$rendered && $count) {
    $count = 0;
    $pageNav->setTotal(0, true);
    $ferror = __('This queue has tickets, but the list could not be loaded.');
}
?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="<?php echo count($columns)+1; ?>">
        <?php if ($count && $canManageTickets) {
        echo __('Select');?>:&nbsp;
        <a id="selectAll" href="#ckb"><?php echo __('All');?></a>&nbsp;&nbsp;
        <a id="selectNone" href="#ckb"><?php echo __('None');?></a>&nbsp;&nbsp;
        <a id="selectToggle" href="#ckb"><?php echo __('Toggle');?></a>&nbsp;&nbsp;
        <?php }else{
            // Nada que mostrar. Se explica en vez de dejar solo una linea en
            // el pie: una tabla vacia sin motivo parece una pagina rota (paso
            // de verdad al ver la cola sin filas por un error de SQL).
            echo '<div class="empty-state">';
            echo '<i class="icon-inbox"></i>';
            echo '<p>';
            echo $ferror
                ? Format::htmlchars($ferror)
                : __('Query returned 0 results.');
            echo '</p>';
            if (!$ferror)
                echo '<p class="faded">'
                    . __('Try clearing the search or picking another queue.')
                    . '</p>';
            echo '</div>';
        } ?>
      </td>
    </tr>
  </tfoot>
</table>

<?php
    if ($count > 0 || $skipCount) { //if we actually had any tickets returned.
?>  <div>
      <span class="faded pull-right"><?php echo $pageNav->showing(); ?></span>
<?php
        echo __('Page').':'.$pageNav->getPageLinks().'&nbsp;';
        ?>
        <a href="#tickets/export/<?php echo $queue->getId(); ?>"
        id="queue-export" class="no-pjax export"
            ><?php echo __('Export'); ?></a>
        <i class="help-tip icon-question-sign" href="#export"></i>
    </div>
<?php
    } ?>
</form>
