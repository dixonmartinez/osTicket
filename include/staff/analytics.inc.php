<?php
/**
 * include/staff/analytics.inc.php
 *
 * Vista del panel de analíticas. Espera en el ámbito:
 *
 *   $analytics  Analytics  motor con el periodo ya aplicado
 *   $range      string     clave del rango seleccionado
 *   $startDate  string     Y-m-d (vacío si se usó un rango predefinido)
 *   $endDate    string     Y-m-d
 *
 * Todo el cálculo ocurre en el servidor: la página se puede imprimir, guardar
 * como HTML o consumir desde la app móvil sin ejecutar JavaScript.
 */

if (!isset($analytics) || !($analytics instanceof Analytics))
    return;

$summary = $analytics->getSummary();
$daily = $analytics->getDailySeries();
$hourly = $analytics->getHourlyDistribution();
$agents = $analytics->getAgentBreakdown();
$departments = $analytics->getDepartmentBreakdown();
$sources = $analytics->getSourceBreakdown();
$topics = $analytics->getTopicBreakdown();
$backlog = $analytics->getBacklogAge();
$workload = $analytics->getWorkload();

/* Formato de números y duraciones en la configuración regional del agente. */
$dec = Internationalization::getCurrentLocale()
    ? (class_exists('NumberFormatter')
        ? NumberFormatter::create(Internationalization::getCurrentLocale(),
            NumberFormatter::DECIMAL)
        : null)
    : null;
$decimalSep = $dec ? $dec->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL) : '.';
$thousandSep = $dec ? $dec->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL) : ',';
$num = function ($value, $decimals = 0) use ($decimalSep, $thousandSep) {
    return number_format((float) $value, $decimals, $decimalSep, $thousandSep);
};
$hours = function ($value) use ($num) {
    if ($value === null)
        return '—';
    if ($value < 24)
        return sprintf(__('%s h'), $num($value, 1));
    return sprintf(__('%s d'), $num($value / 24, $value < 240 ? 1 : 0));
};
$pct = function ($value) use ($num) {
    return $value === null ? '—' : $num($value, 1) . '%';
};

/* Tono semántico de los KPI (verde/ámbar/rojo). */
$slaTone = $summary['sla_pct'] === null ? ''
    : ($summary['sla_pct'] >= 90 ? 'is-ok'
        : ($summary['sla_pct'] >= 75 ? 'is-warn' : 'is-bad'));
$backlogTone = $summary['overdue'] > 0 ? 'is-bad'
    : ($summary['unanswered'] > 0 ? 'is-warn' : 'is-ok');

/* Fecha del periodo en el formato del agente (mediodía UTC evita saltos
   de día por zona horaria). */
$dateLabel = function ($iso) {
    return Format::date(strtotime($iso . ' 12:00:00 UTC'));
};
?>

<link rel="stylesheet" type="text/css" href="css/analytics.css" />

<div class="an-page">

  <div class="an-header">
    <div>
      <h1><?php echo __('Service desk analytics'); ?></h1>
      <p class="an-subtitle">
        <?php echo sprintf(
            __('Period %s – %s · %s days'),
            $dateLabel($analytics->getStartDate()),
            $dateLabel($analytics->getEndDate()),
            number_format(max(1, round(($analytics->getEnd() - $analytics->getStart()) / 86400))));
        ?>
      </p>
    </div>

    <form class="an-filters" method="get" action="analytics.php" id="an-filter">
      <div>
        <label for="an-range"><?php echo __('Period'); ?></label>
        <select name="range" id="an-range">
          <?php foreach (Analytics::getRangeChoices() as $key => $label) { ?>
            <option value="<?php echo $key; ?>"<?php
                echo ($range === $key && !$startDate) ? ' selected="selected"' : ''; ?>>
              <?php echo $label; ?>
            </option>
          <?php } ?>
        </select>
      </div>
      <div>
        <label for="an-start"><?php echo __('From'); ?></label>
        <input type="date" name="start" id="an-start"
               value="<?php echo Format::htmlchars($startDate); ?>" />
      </div>
      <div>
        <label for="an-end"><?php echo __('To'); ?></label>
        <input type="date" name="end" id="an-end"
               value="<?php echo Format::htmlchars($endDate); ?>" />
      </div>
      <button class="an-btn" type="submit"><?php echo __('Apply'); ?></button>
      <a class="an-btn ghost" href="analytics.php?export=csv&amp;range=<?php
          echo urlencode($range); ?>&amp;start=<?php echo urlencode($startDate);
          ?>&amp;end=<?php echo urlencode($endDate); ?>">
        <?php echo __('Export CSV'); ?>
      </a>
    </form>
  </div>

  <?php if (!$analytics->canSeeOthers()) { ?>
    <p class="an-scope">
      <?php echo __('You are seeing only the data of the departments you belong to. Ask an administrator for the "Stats" permission to see the whole team.'); ?>
    </p>
  <?php } ?>

  <!-- ================= KPIs ================= -->
  <div class="an-kpis">

    <div class="an-kpi">
      <div class="an-kpi-label"><?php echo __('Tickets created'); ?></div>
      <div class="an-kpi-value"><?php echo $num($summary['created']); ?></div>
      <div class="an-kpi-note">
        <?php echo sprintf(__('%s per day'), $num($summary['per_day'], 1)); ?>
        <?php if ($summary['trend'] !== null) {
            $tone = abs($summary['trend']) < 5 ? 'flat'
                : ($summary['trend'] > 0 ? 'up' : 'down');
            $arrow = $summary['trend'] > 0 ? '▲' : ($summary['trend'] < 0 ? '▼' : '■');
            ?>
          <span class="an-delta <?php echo $tone; ?>"
                title="<?php echo __('Second half of the period vs. first half'); ?>">
            <?php echo $arrow . ' ' . $pct(abs($summary['trend'])); ?>
          </span>
        <?php } ?>
      </div>
    </div>

    <div class="an-kpi is-ok">
      <div class="an-kpi-label"><?php echo __('Tickets closed'); ?></div>
      <div class="an-kpi-value"><?php echo $num($summary['closed']); ?></div>
      <div class="an-kpi-note">
        <?php echo $summary['created'] > 0
            ? sprintf(__('%s of what came in'),
                $pct(100 * $summary['closed'] / $summary['created']))
            : '&nbsp;'; ?>
      </div>
    </div>

    <div class="an-kpi <?php echo $backlogTone; ?>">
      <div class="an-kpi-label"><?php echo __('Still open'); ?></div>
      <div class="an-kpi-value"><?php echo $num($summary['open']); ?></div>
      <div class="an-kpi-note">
        <?php echo sprintf(__('%s overdue · %s unanswered'),
            $num($summary['overdue']), $num($summary['unanswered'])); ?>
      </div>
    </div>

    <div class="an-kpi">
      <div class="an-kpi-label"><?php echo __('First response'); ?></div>
      <div class="an-kpi-value"><?php
          echo $hours($summary['first_response_h']); ?></div>
      <div class="an-kpi-note"><?php echo __('Average, from creation'); ?></div>
    </div>

    <div class="an-kpi">
      <div class="an-kpi-label"><?php echo __('Resolution time'); ?></div>
      <div class="an-kpi-value"><?php
          echo $hours($summary['resolution_h']); ?></div>
      <div class="an-kpi-note"><?php echo __('Average, until closed'); ?></div>
    </div>

    <div class="an-kpi <?php echo $slaTone; ?>">
      <div class="an-kpi-label"><?php echo __('SLA compliance'); ?></div>
      <div class="an-kpi-value"><?php echo $pct($summary['sla_pct']); ?></div>
      <div class="an-kpi-note">
        <?php echo sprintf(__('%s met · %s breached'),
            $num($summary['sla_met']), $num($summary['sla_breached'])); ?>
      </div>
    </div>

  </div>

  <!-- ================= Evolución diaria ================= -->
  <div class="an-card an-card--wide">
    <h2><?php echo __('Daily flow'); ?></h2>
    <?php echo AnalyticsChart::lineSvg($daily); ?>
    <div class="an-legend">
      <span><span class="swatch created"></span><?php echo __('Created'); ?></span>
      <span><span class="swatch closed"></span><?php echo __('Closed'); ?></span>
    </div>
  </div>

  <div class="an-grid">

    <!-- Distribución por hora -->
    <div class="an-card">
      <h2><?php echo __('When do tickets arrive?'); ?></h2>
      <?php echo AnalyticsChart::hourBars($hourly); ?>
      <p class="an-kpi-note"><?php
        echo __('Hour of the day in the server time zone. Use it to plan shift coverage.'); ?></p>
    </div>

    <!-- Antigüedad del pendiente -->
    <div class="an-card">
      <h2><?php echo __('Age of open tickets'); ?></h2>
      <?php
      $ageRows = [];
      $tone = ['ok', null, 'warn', 'warn', 'bad'];
      $i = 0;
      foreach ($backlog as $label => $count) {
          $ageRows[] = [
              'label' => __($label),
              'value' => $count,
              'tone' => $count > 0 ? $tone[$i] : null,
          ];
          $i++;
      }
      echo AnalyticsChart::barList($ageRows);
      ?>
      <p class="an-kpi-note"><?php
        echo __('Tickets older than a week usually need a decision: answer, escalate or close.'); ?></p>
    </div>

    <!-- Origen -->
    <div class="an-card">
      <h2><?php echo __('Where do tickets come from?'); ?></h2>
      <?php
      $sourceRows = [];
      foreach ($sources as $s)
          $sourceRows[] = ['label' => $s['source'], 'value' => $s['count']];
      echo AnalyticsChart::barList($sourceRows);
      ?>
    </div>

    <!-- Temas -->
    <div class="an-card">
      <h2><?php echo __('Most frequent topics'); ?></h2>
      <?php
      $topicRows = [];
      foreach ($topics as $t)
          $topicRows[] = ['label' => $t['name'], 'value' => $t['count']];
      echo AnalyticsChart::barList($topicRows);
      ?>
      <p class="an-kpi-note"><?php
        echo __('Repeated topics are candidates for a knowledge base article or a canned response.'); ?></p>
    </div>

  </div>

  <!-- ================= Tablas ================= -->
  <div class="an-grid">

    <div class="an-card an-card--wide">
      <h2><?php echo __('Workload right now'); ?></h2>
      <?php if (!$workload) { ?>
        <p class="an-empty"><?php echo __('No open tickets assigned.'); ?></p>
      <?php } else { ?>
        <table class="an-table">
          <thead>
            <tr>
              <th><?php echo __('Agent'); ?></th>
              <th class="num"><?php echo __('Open'); ?></th>
              <th class="num"><?php echo __('Overdue'); ?></th>
              <th><?php echo __('Load'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php
            $maxOpen = max(1, max(array_map(function ($w) { return $w['open']; }, $workload)));
            foreach ($workload as $w) { ?>
              <tr>
                <td data-label="<?php echo __('Agent'); ?>">
                  <?php echo Format::htmlchars($w['name'] ?: __('Unassigned')); ?>
                </td>
                <td class="num" data-label="<?php echo __('Open'); ?>">
                  <?php echo $num($w['open']); ?>
                </td>
                <td class="num" data-label="<?php echo __('Overdue'); ?>">
                  <?php if ($w['overdue']) { ?>
                    <span class="pill is-bad"><?php echo $num($w['overdue']); ?></span>
                  <?php } else { ?>
                    <span class="pill is-ok">0</span>
                  <?php } ?>
                </td>
                <td data-label="<?php echo __('Load'); ?>">
                  <span class="an-bar-track"><span class="an-bar-fill<?php
                    echo $w['overdue'] ? ' is-bad' : ''; ?>"
                    style="width:<?php echo min(100, round(100 * $w['open'] / $maxOpen)); ?>%"></span></span>
                </td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      <?php } ?>
    </div>

    <div class="an-card an-card--wide">
      <h2><?php echo __('Performance by agent'); ?></h2>
      <?php if (!$agents) { ?>
        <p class="an-empty"><?php echo __('No tickets assigned in this period.'); ?></p>
      <?php } else { ?>
        <table class="an-table">
          <thead>
            <tr>
              <th><?php echo __('Agent'); ?></th>
              <th class="num"><?php echo __('Assigned'); ?></th>
              <th class="num"><?php echo __('Closed'); ?></th>
              <th class="num"><?php echo __('Overdue'); ?></th>
              <th class="num"><?php echo __('Avg. resolution'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($agents as $a) { ?>
              <tr>
                <td data-label="<?php echo __('Agent'); ?>">
                  <?php echo Format::htmlchars($a['name']); ?>
                </td>
                <td class="num" data-label="<?php echo __('Assigned'); ?>">
                  <?php echo $num($a['assigned']); ?>
                </td>
                <td class="num" data-label="<?php echo __('Closed'); ?>">
                  <?php echo $num($a['closed']); ?>
                </td>
                <td class="num" data-label="<?php echo __('Overdue'); ?>">
                  <?php echo $num($a['overdue']); ?>
                </td>
                <td class="num" data-label="<?php echo __('Avg. resolution'); ?>">
                  <?php echo $hours($a['avg_resolution_h']); ?>
                </td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      <?php } ?>
    </div>

    <div class="an-card an-card--wide">
      <h2><?php echo __('Performance by department'); ?></h2>
      <?php if (!$departments) { ?>
        <p class="an-empty"><?php echo __('No data for the selected period.'); ?></p>
      <?php } else { ?>
        <table class="an-table">
          <thead>
            <tr>
              <th><?php echo __('Department'); ?></th>
              <th class="num"><?php echo __('Created'); ?></th>
              <th class="num"><?php echo __('Closed'); ?></th>
              <th class="num"><?php echo __('Overdue'); ?></th>
              <th class="num"><?php echo __('Unanswered'); ?></th>
              <th class="num"><?php echo __('Avg. resolution'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($departments as $d) { ?>
              <tr>
                <td data-label="<?php echo __('Department'); ?>">
                  <?php echo Format::htmlchars($d['name']); ?>
                </td>
                <td class="num" data-label="<?php echo __('Created'); ?>">
                  <?php echo $num($d['created']); ?>
                </td>
                <td class="num" data-label="<?php echo __('Closed'); ?>">
                  <?php echo $num($d['closed']); ?>
                </td>
                <td class="num" data-label="<?php echo __('Overdue'); ?>">
                  <?php echo $num($d['overdue']); ?>
                </td>
                <td class="num" data-label="<?php echo __('Unanswered'); ?>">
                  <?php echo $num($d['unanswered']); ?>
                </td>
                <td class="num" data-label="<?php echo __('Avg. resolution'); ?>">
                  <?php echo $hours($d['avg_resolution_h']); ?>
                </td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      <?php } ?>
    </div>

  </div>

</div>
