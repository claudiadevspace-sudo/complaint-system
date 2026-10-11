<?php
/**
 * One analytics card on the OSWD Reports page: summary strip + line graph +
 * totals table + Export PDF. Used twice (by category, by status) so both
 * look the same. Dark theme: .nemsu-analytics + data-bs-theme="<?php echo appTheme(); ?>".
 *
 * Set before including:
 *   $card       — ComplaintAnalytics::data() result
 *   $cardId     — unique id for the chart, e.g. 'chartAnalytics'
 *   $cardTitle  — e.g. 'Complaint analytics by category'
 *   $cardIcon   — Bootstrap icon name
 *   $cardExport — URL of the PDF export for this card
 *   $cardNote   — optional sentence shown under the chart
 */
$isCategory = $card['dimension'] === 'category';

// Summary strip: the most frequent group and the busiest period overall.
$topName = null;
$topTotal = 0;
foreach ($card['summary'] as $name => $s) {
    if ($s['total'] > $topTotal) {
        $topName = $name;
        $topTotal = $s['total'];
    }
}
$perPoint = array_fill(0, count($card['labels']), 0);
foreach ($card['series'] as $values) {
    foreach ($values as $i => $v) {
        $perPoint[$i] += $v;
    }
}
$busiestIndex = $card['total'] ? array_search(max($perPoint), $perPoint, true) : null;
$busiestLabel = $busiestIndex === null ? '—'
    : ($card['view'] === 'year' ? $card['labels'][$busiestIndex] : date('M', strtotime($card['period'])) . ' ' . $card['labels'][$busiestIndex]);
?>
<div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel nemsu-analytics mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-<?php echo $cardIcon; ?> me-2" aria-hidden="true"></i><?php echo htmlspecialchars($cardTitle); ?></span>
        <a href="<?php echo htmlspecialchars($cardExport); ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Export PDF
        </a>
    </div>
    <div class="card-body">
        <div class="nemsu-analytics-kpis">
            <div class="nemsu-analytics-kpi">
                <div class="nemsu-analytics-kpi__label">Total complaints</div>
                <div class="nemsu-analytics-kpi__value"><?php echo $card['total']; ?></div>
                <div class="nemsu-analytics-kpi__sub"><?php echo htmlspecialchars($card['period']); ?></div>
            </div>
            <div class="nemsu-analytics-kpi" style="--kpi-accent: <?php echo $topName ? $card['colors'][$topName] : '#8ea3c4'; ?>">
                <div class="nemsu-analytics-kpi__label">Most frequent <?php echo strtolower($card['dimension_label']); ?></div>
                <div class="nemsu-analytics-kpi__value" title="<?php echo htmlspecialchars((string)$topName); ?>"><?php echo htmlspecialchars($topName ?? '—'); ?></div>
                <div class="nemsu-analytics-kpi__sub"><?php echo $topName ? $topTotal . ' complaint(s) · ' . $card['summary'][$topName]['share'] . '%' : 'No complaints yet'; ?></div>
            </div>
            <div class="nemsu-analytics-kpi" style="--kpi-accent: #38bdf8">
                <div class="nemsu-analytics-kpi__label">Busiest <?php echo $card['view'] === 'year' ? 'month' : 'day'; ?></div>
                <div class="nemsu-analytics-kpi__value"><?php echo htmlspecialchars($busiestLabel); ?></div>
                <div class="nemsu-analytics-kpi__sub"><?php echo $busiestIndex !== null ? $perPoint[$busiestIndex] . ' complaint(s)' : '—'; ?></div>
            </div>
        </div>

        <div class="nemsu-chart-wrap" style="min-height: 320px" id="<?php echo $cardId; ?>Data"
             data-chart="<?php echo htmlspecialchars(json_encode([
                 'labels' => $card['labels'],
                 'series' => $card['series'],
                 'colors' => $card['colors'],
                 'xTitle' => $card['view'] === 'year' ? 'Month' : 'Day of ' . $card['period'],
             ]), ENT_QUOTES, 'UTF-8'); ?>">
            <canvas id="<?php echo $cardId; ?>" aria-label="Line graph of complaints per <?php echo strtolower($card['dimension_label']); ?>, <?php echo htmlspecialchars($card['school_year'] . ', ' . $card['period']); ?>"></canvas>
        </div>
        <?php if (!empty($cardNote)): ?>
            <p class="small nemsu-analytics-note mt-2 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?php echo htmlspecialchars($cardNote); ?></p>
        <?php endif; ?>

        <!-- Totals for the same period -->
        <div class="table-responsive mt-3">
            <table class="table table-sm table-hover align-middle mb-0 nemsu-table nemsu-table--stack">
                <thead>
                    <tr>
                        <th><?php echo $card['dimension_label']; ?></th>
                        <?php if ($isCategory): ?><th>Type</th><?php endif; ?>
                        <th class="text-end">Complaints</th>
                        <th class="ps-4">Busiest <?php echo $card['view'] === 'year' ? 'month' : 'day'; ?></th>
                        <th class="text-end" style="min-width: 110px">Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($card['summary'] as $name => $s): ?>
                        <?php $color = $card['colors'][$name]; ?>
                        <tr>
                            <td><span class="nemsu-swatch" style="background: <?php echo $color; ?>; color: <?php echo $color; ?>" aria-hidden="true"></span><?php echo htmlspecialchars($name); ?></td>
                            <?php if ($isCategory): ?><td><small class="text-body-secondary"><?php echo $s['type']; ?>-related</small></td><?php endif; ?>
                            <td class="text-end fw-semibold"><?php echo $s['total']; ?></td>
                            <td class="ps-4"><?php echo $s['total'] ? htmlspecialchars($s['peak_label']) . ' <small class="text-body-secondary">(' . $s['peak_count'] . ')</small>' : '<span class="text-body-secondary">—</span>'; ?></td>
                            <td class="text-end">
                                <?php echo $s['share']; ?>%
                                <div class="nemsu-share-bar"><span style="width: <?php echo $s['share']; ?>%; background: <?php echo $color; ?>"></span></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
