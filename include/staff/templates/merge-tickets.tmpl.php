<?php
    if ($info['error']) {
        echo sprintf('<p id="msg_error">%s</p>', $info['error']);
    } elseif ($info['warn']) {
        echo sprintf('<p id="msg_warning">%s</p>', $info['warn']);
    } elseif ($info['msg']) {
        echo sprintf('<p id="msg_notice">%s</p>', $info['msg']);
    } elseif ($info['notice']) {
       echo sprintf('<p id="msg_info"><i class="icon-info-sign"></i> %s</p>',
               $info['notice']);
    }
    $title = strpos($_SERVER['PATH_INFO'], 'link') !== false ? 'link' : 'merge';
?>
<h3 class="drag-handle"><i class="<?php echo $title == 'link' ? 'icon-link' : 'icon-code-fork' ?>"></i> <?php echo sprintf(__('%s Tickets'), ucfirst($title)); ?></i></h3>
<b><a class="close" href="#"><i class="icon-remove-circle"></i></a></b>
<hr/><?php echo sprintf(__(
'Choose which Tickets to %s. The Ticket on top will be the Parent Ticket. Sort the order of the Tickets by clicking and dragging them.'
), ($title == 'merge' ? __('merge into this one') : __('link')));
?>
<br/>
<br/>
<form method="post" data-call="refreshAndClose" action="<?php echo $info['action']; ?>">
<input type="hidden" name="title" value="<?php echo $title; ?>" />
<ul id="ticket-entries">
<div class="merge-tickets" style="overflow-y: auto; height:100px; margin-bottom:5px;">
<?php
if ($tickets) {
foreach ($tickets as $t) {
    if (!is_array($tickets))
        list($ticket_id, $number, $ticket_pid, $sort, $id, $user_id,
        $subject, $name, $flags, $tasks, $collaborators, $entries) = $t;
    else
        extract($t); //same as above, but maintains sort for linking
    $mergeType = Ticket::getMergeTypeByFlag($flags);
    if ($mergeType == 'combine')
        $forceOptions = true;
    if ($ticket_pid && $mergeType == 'visual')
        $isLinkChild = true;
    $types[] = $mergeType;
?>
<li class="<?php if ($isLinkChild) echo 'sortable'; ?> row-item
    <?php
    if ($title == 'merge' &&
        (($parent && $parent instanceof Ticket && $parent->getMergeType() != 'visual' && $parent->getId() == $ticket_id) || //mass process merge
              ($ticket && $ticket_id == $ticket->getId() && $ticket->getMergeType() != 'visual'))) //ticket view merge or mass process merge w/child ticket(s)
            echo ' ui-state-disabled';
          else
            echo 'ui-sortable-handle';
    ?>" data-id="<?php echo $ticket_id; ?>">
    <input type="hidden" id="tids" name="tids[]" value="<?php echo $number; ?>" />
    <?php $numberLink = sprintf('<a style="display: inline" class="preview" data-preview="#tickets/%d/preview" href="%s" target="_blank">%s</a>',
            $ticket_id, Ticket::getLink($ticket_id), $number);
        $nbsp = '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
        $iconLarge = '<i style="visibility: hidden;" class="icon-group"></i>';
        $iconSmall = '<i style="visibility: hidden;" class="icon-code-fork"></i>';
        $children=$ticket->getChildren();
        $subject = (strlen($subject) > 15) ? sprintf('%s...',substr($subject, 0, 15)) : $subject;
        $entryCount = $entries > 0 ?
            sprintf('<a data-placement="bottom" <i class="icon-comments-alt" data-toggle="tooltip" title="%s %s"></i></a>',
            $entries, __('Thread Entries')) : $nbsp.$iconLarge;
        $taskCount = sprintf('<a data-placement="bottom" <i class="icon-tasks" data-toggle="tooltip" title="%s %s"></i></a>',$tasks, __('Tasks'));
        $showMergePreview = ($mergeType != 'visual' && (count($children) > 0)) ?
            sprintf('<a class="merge preview"href="#tickets/%d/merge"><i class="icon-code-fork"></i></a>', $ticket_id) : '';
        $showLinkPreview = ($mergeType == 'visual' && (count($children) > 0)) ?
            sprintf('<a class="merge preview"href="#tickets/%d/merge"><i class="icon-link"></i></a>', $ticket_id) : '';
        $showCollaborators = ($collaborators > 0) ?
           sprintf('<a class="collaborators preview"href="#thread/%d/collaborators/0"><i class="icon-group"></i></a>', $id) : '';
    ?>
    <i class="icon-reorder"></i> <?php
    echo sprintf('%s %s %s %s %s <div style="position:absolute; right:40px; top:10px;">%s %s %s %s %s</div>',
        $numberLink ?: $number, $nbsp, $name, $nbsp, $subject, $entryCount, $tasks ? $nbsp.$taskCount : $nbsp.$iconLarge,
        $showMergePreview ? $nbsp.$showMergePreview : $nbsp.$iconSmall, $showLinkPreview ? $nbsp.$showLinkPreview : $nbsp.$iconLarge,
        $showCollaborators ? $nbsp.$showCollaborators : $nbsp.$iconLarge);
    if ($mergeType == 'visual') { ?>
    <div class="button-group">
    <div class="delete"><a href="#"
        data-ost-append-hidden="dtids[]"
        data-ost-append-value="<?php echo $ticket_id; ?>"
        data-toggle-target="#delete-warning" data-toggle-mode="show"
        data-remove-closest="li.row-item">
        <i class="icon-trash"></i></a></div>
    </div>
<?php } ?>
</li>
<?php } } ?>
</div>
</ul>

<div>
    <i class="icon-plus"></i>&nbsp;
    <span>
        <select class="ticketSelection" name="name" id="ticket-number"
        data-placeholder="<?php echo __('Select Ticket'); ?>">
      </select>
    </span>
    <button type="button" class="inline green button"
    data-call="ostAddMergeTicket"
    data-ost-link="<?php echo Ticket::getLink(''); ?>"
    data-ost-merged-alert="<?php echo __('Merged tickets cannot be added manually. They must be preselected.'); ?>"
    data-ost-duplicate-alert="<?php echo __('This Ticket is already in the list.'); ?>"
    ><i class="icon-plus-sign"></i>
    <?php echo __('Add a Ticket'); ?></button>
    &nbsp;&nbsp;&nbsp;
</div>
<?php if ($title == 'merge') { ?>
<div id="participant-options">
&nbsp;&nbsp;&nbsp;
    <label class="inline checkbox">
        <?php echo __('Participants') ?>&nbsp;
    <select id="participants" name="participants">
            <option value='user'><?php echo __('User');?></option>
            <option value='all' selected="selected"><?php echo __('User + Collaborators'); ?></option>
    </select>
    </label>
</div>
<br/><br/>
<div id="child-status">
&nbsp;&nbsp;&nbsp;
    <label class="inline checkbox">
        <?php echo __('Child Status');?>
        <select id="childStatusId" name="childStatusId">
        <?php
        $states = array('closed');
        foreach (TicketStatusList::getStatuses(
                    array('states' => $states)) as $s) {
            if (!$s->isEnabled()) continue;
            echo sprintf('<option value="%d" %s>%s</option>',
                    $s->getId(),
                    (!$s->isDisableable()) ? 'selected="selected"' : '',
                    $s->getLocalName());
        }
        ?>
        </select>
        <i class="help-tip icon-question-sign" href="#child_status"></i>
    </label>
</div>
<br/>
<div id="parent-status">
&nbsp;&nbsp;&nbsp;
    <label class="inline checkbox">
        <?php echo __('Parent Status');?>
        <select id="parentStatusId" name="parentStatusId">
        <option value="">— Select —</option>
        <?php
        $states = array('open', 'closed');
        foreach (TicketStatusList::getStatuses(
                    array('states' => $states)) as $s) {
            if (!$s->isEnabled()) continue;
            echo sprintf('<option value="%d">%s</option>',
                    $s->getId(),
                    $s->getLocalName());
        }
        ?>
        </select>
        <i class="help-tip icon-question-sign" href="#parent_status"></i>
    </label>
</div>
<br/>
<?php } ?>

<div>
    <hr>
    <?php echo ($title == 'merge') ? __('Merge Type: ') : ''; ?>
    <?php echo ($title == 'merge') ? '<i class="help-tip icon-question-sign" href="#merge_types"></i>' : ''; ?><br>
    <label><input <?php echo ($title == 'link') ? 'style="display:none"' : '';?> type="radio" name="combine" value="1"
           <?php echo ($ticket->getMergeType() == 'combine' || ($title == 'merge' && !$parent))?'checked="checked"':''; ?>>
           <?php echo ($title == 'merge') ? __('Combine Threads') : '';?></label>
    <label><input <?php echo ($title == 'link') ? 'style="display:none"' : '';?> type="radio" name="combine" value="0"
           <?php echo ($ticket->getMergeType() == 'separate')?'checked="checked"':''; ?>>
           <?php echo ($title == 'merge') ? __('Separate Threads') : '';?></label>
    <label><input style="display:none" type="radio" name="combine" value="2" <?php echo ($ticket->getMergeType() == 'visual' && $title == 'link')?'checked="checked"':''; ?>></label>
</div>
<?php if ($title == 'merge') { ?>
<hr>
<div id="merge-options">
    <label class="inline checkbox">
        <input type="checkbox" id="delete-child" name="delete-child">
        <?php echo __('Delete Child Ticket') ?>
    </label>
    <br>
    <label style="margin-top:2px;" class="inline checkbox">
        <input type="checkbox" id="move-tasks" name="move-tasks">
        <?php echo __('Move Child Tasks to Parent') ?>
    </label>
</div>
<div id="savewarning" style="display:none; padding-top:2px;"><p
id="msg_warning"><?php echo __('Are you sure you want to delete the child ticket(s)?'); ?></p></div>
<?php } ?>

<div id="delete-warning" style="display:none">
    <div id="msg_warning"><?php echo __(
    'Clicking <strong>Save Changes</strong> will unlink this Ticket'
    ); ?>
    </div>
</div>

<p class="full-width">
    <span class="buttons pull-left">
        <input type="button" name="cancel" class="<?php
            echo $user ? 'cancel' : 'close' ?>" value="<?php echo __('Cancel'); ?>">
    </span>
    <?php if (!$info['error']) { ?>
        <span class="buttons pull-right">
            <input type="submit" value="<?php echo __('Save Changes'); ?>">
        </span>
    <?php } ?>
 </p>

<script nonce="<?php echo Http::nonce(); ?>" type="text/javascript">
$(function() {
    $('#ticket-entries').sortable({items: "li:not(.ui-state-disabled)"});
    $( "#ticket-entries li" ).disableSelection();
});
function refreshAndClose() {
    setTimeout(function () {
        location.reload();
    }, 1000);
}
$(document).ready(function() {
    $('.ticketSelection').select2({
      width: '450px',
      minimumInputLength: 3,
      ajax: {
        url: "ajax.php/tickets/number-lookup",
        dataType: 'json',
        data: function (params) {
          return {
            q: params.term,
          };
        },
        processResults: function (data) {
          return {
            results: $.map(data, function (item) {
                subject = (item.subject.length > 25) ? item.subject.substring(0, 25) + '...' : item.subject;
              return {
                text: item.id,
                ticket_id: item.ticket_id,
                user: item.user,
                id: item.id,
                tasks: item.tasks,
                thread_id: item.thread_id,
                spaces: '\xa0\xa0\xa0\xa0\xa0\xa0\xa0',
                mergeType: item.mergeType,
                mergePrev: item.children,
                collaborators: item.collaborators,
                entries: item.entries,
                subject: subject,
              }
            })
          };
        }
      }
    });
    $('#merge-options input[type=checkbox]').change(function(){
        childWarning();
    });
    function childWarning() {
        var value = $("#delete-child").prop("checked") ? 1 : 0;
        (value == 1) ? $('#savewarning').show() : $('#savewarning').hide();
    }
});
</script>

<style>
  #ticket-entries { list-style-type: none; padding: 0px;}
  #participant-options{ padding: 0px; display: block; float: left;}
</style>
