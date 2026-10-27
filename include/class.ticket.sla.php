<?php
/*********************************************************************
    class.ticket.sla.php

    Vencimientos y planes de servicio del ticket: cuando vence, si esta
    vencido, que plan (SLA) le toca y el campo de fecha de vencimiento que
    usan los formularios.

    Es un rasgo de Ticket y no una clase aparte porque todo esto se calcula
    sobre el propio modelo (`$this->est_duedate`, `$this->duedate`,
    `$this->getDept()`, `$this->save()`) y no tiene estado propio. Se sacaron
    de include/class.ticket.php (frente 6 de docs/CODE_QUALITY.md) moviendo
    los metodos enteros, sin tocar su codigo.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

trait TicketSla {

    function isOverdue() {
        return $this->ht['isoverdue'];
    }

    function getDueDate() {
        return $this->duedate;
    }

    function getSLADueDate($recompute=false) {
        global $cfg;

        if (!$recompute && $this->est_duedate)
            return $this->est_duedate;

        if (($sla = $this->getSLA()) && $sla->isActive()) {
            $schedule = $this->getDept()->getSchedule();
            $tz = new DateTimeZone($cfg->getDbTimezone());
            $dt = new DateTime($this->getReopenDate() ?:
                    $this->getCreateDate(), $tz);
            $dt = $sla->addGracePeriod($dt, $schedule);
            // Make sure time is in DB timezone
            $dt->setTimezone($tz);
            return $dt->format('Y-m-d H:i:s');
        }
    }

    function updateEstDueDate($clearOverdue=true) {
        if ($this->isOverdue() && $clearOverdue)
            $this->clearOverdue(false);

        $this->est_duedate = $this->getSLADueDate(true) ?: null;

        return $this->save();
    }

    function getEstDueDate() {
        // Real due date or  sla due date (If ANY)
        return $this->getDueDate() ?: $this->getSLADueDate();
    }

    function setSLAId($slaId) {
        if ($slaId == $this->getSLAId())
            return true;

        $sla = null;
        if ($slaId && !($sla = Sla::lookup($slaId)))
            return false;

        $this->sla = $sla;
        return $this->save();
    }

    /**
     * Selects the appropriate service-level-agreement plan for this ticket.
     * When tickets are transfered between departments, the SLA of the new
     * department should be applied to the ticket. This would be useful,
     * for instance, if the ticket is transferred to a different department
     * which has a shorter grace period, the ticket should be considered
     * overdue in the shorter window now that it is owned by the new
     * department.
     *
     * $trump - if received, should trump any other possible SLA source.
     *          This is used in the case of email filters, where the SLA
     *          specified in the filter should trump any other SLA to be
     *          considered.
     */
    function selectSLAId($trump=null) {
        global $cfg;
        # XXX Should the SLA be overridden if it was originally set via an
        #     email filter? This method doesn't consider such a case
        if ($trump && is_numeric($trump)) {
            $slaId = $trump;
        } elseif ($this->getDept() && $this->getDept()->getSLAId()) {
            $slaId = $this->getDept()->getSLAId();
        } elseif ($this->getTopic() && $this->getTopic()->getSLAId()) {
            $slaId = $this->getTopic()->getSLAId();
        } else {
            $slaId = $cfg->getDefaultSLAId();
        }

        return ($slaId && $this->setSLAId($slaId)) ? $slaId : false;
    }

    function markOverdue($whine=true) {
        global $cfg;

        // Only open tickets can be marked overdue
        if (!$this->isOpen())
            return false;

        if ($this->isOverdue())
            return true;

        $this->isoverdue = 1;
        if (!$this->save())
            return false;

        $this->logEvent('overdue');
        $this->onOverdue($whine);

        return true;
    }

    function clearOverdue($save=true) {

        //NOTE: Previously logged overdue event is NOT annuled.
        if ($this->isOverdue())
            $this->isoverdue = 0;

        // clear due date if it's in the past
        if ($this->getDueDate() && Misc::db2gmtime($this->getDueDate()) <= Misc::gmtime())
            $this->duedate = null;

        // Clear SLA if est. due date is in the past
        if ($this->getSLADueDate() && Misc::db2gmtime($this->getSLADueDate()) <= Misc::gmtime())
            $this->est_duedate = null;

        return $save ? $this->save() : true;
    }

    static function checkOverdue() {
        $overdue = static::objects()
            ->filter(array(
                'isoverdue' => 0,
                'status__state' => 'open',
                Q::any(array(
                    Q::all(array(
                        'duedate__isnull' => true,
                        'est_duedate__isnull' => false,
                        'est_duedate__lt' => SqlFunction::NOW())
                        ),
                    Q::all(array(
                        'duedate__isnull' => false,
                        'duedate__lt' => SqlFunction::NOW())
                        )
                    ))
                ))
            ->limit(100);

        foreach ($overdue as $ticket)
            $ticket->markOverdue();

    }

    // TODO: Create internal Form for internal fields
    static function duedateField($name, $default='', $hint='') {
        return DateTimeField::init(array(
            'id' => $name,
            'name' => $name,
            'default' => $default ?: false,
            'label' => __('Due Date'),
            'hint' => $hint,
            'configuration' => array(
                'min' => Misc::gmtime(),
                'time' => true,
                'gmt' => false,
                'future' => true,
                )
            ));
    }

}
