<?php
/**
 * ComplaintAnalytics — complaints per category over a school year or month.
 *
 * Used by the OSWD Reports page (line graphs + tables) and their PDF export.
 * Two breakdowns:
 *   'category' → one line per complaint category
 *   'status'   → one line per CURRENT status of the complaints submitted
 *                in each period (e.g. "resolved in Oct" = filed in Oct, now resolved).
 *
 * A school year (SY) starts in the month set by the Super Admin
 * (Settings → "School year starts in month", default August). SY 2026–2027
 * with an August start = 1 Aug 2026 up to (not including) 1 Aug 2027.
 *
 * Two views:
 *   - whole school year → one point per month (12 points)
 *   - one month         → one point per day (28–31 points)
 * Every category always gets a value for every point (0 when there were
 * no complaints), so each line on the graph is continuous.
 */
class ComplaintAnalytics {
    /**
     * One color per category line, used by BOTH the web chart and the PDF so
     * they match. Bright, clearly different hues: readable on the dark navy
     * analytics cards and on the white PDF page. Categories get colors in allowedComplaintCategories() order.
     */
    /**
     * Status lines follow the meaning of the status badges (pending = gold,
     * resolved = green, escalated = red...), in brighter shades that stand out
     * on the dark analytics cards and still print well on the white PDF.
     */
    const STATUS_COLORS = [
        'pending' => '#f2b632', 'under_review' => '#38bdf8', 'investigating' => '#6d8dff',
        'escalated' => '#f4566a', 'resolved' => '#22c58b', 'rejected' => '#a3aec2',
    ];

    const COLORS = ['#4f9dff', '#a78bfa', '#2dd4bf', '#f2b632', '#f4566a', '#f472b6', '#a3aec2', '#fb923c'];

    private $db;
    private $startMonth;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->startMonth = (int)Settings::get('school_year_start_month');
    }

    /** The SY a date belongs to, as its starting calendar year (e.g. 2026 for SY 2026–2027). */
    public function schoolYearOf($timestamp) {
        $year = (int)date('Y', $timestamp);
        return (int)date('n', $timestamp) >= $this->startMonth ? $year : $year - 1;
    }

    public static function label($syStart) {
        return 'SY ' . $syStart . '–' . ($syStart + 1);
    }

    /** First day of the SY, and first day of the next SY (exclusive end). */
    public function range($syStart) {
        $from = sprintf('%04d-%02d-01', $syStart, $this->startMonth);
        return [$from, date('Y-m-d', strtotime($from . ' +1 year'))];
    }

    /** The 12 months of a SY as 'YYYY-MM', in order. */
    public function months($syStart) {
        [$from] = $this->range($syStart);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = date('Y-m', strtotime($from . " +{$i} month"));
        }
        return $months;
    }

    /** School years to offer: from the oldest complaint up to the current SY, newest first. */
    public function availableSchoolYears() {
        $oldest = $this->db->query('SELECT MIN(created_at) FROM complaints')->fetchColumn();
        $current = $this->schoolYearOf(time());
        $first = $oldest ? min($current, $this->schoolYearOf(strtotime($oldest))) : $current;
        return range($current, $first);
    }

    /**
     * Counts per category (or per status) for the chosen period.
     * $month = null → whole SY by month; 'YYYY-MM' → that month by day.
     * $by = 'category' | 'status'.
     */
    public function data($syStart, $month = null, $by = 'category') {
        $byStatus = $by === 'status';
        if ($month !== null) {
            $from = $month . '-01';
            $to = date('Y-m-d', strtotime($from . ' +1 month'));
            $days = (int)date('t', strtotime($from));
            $buckets = [];
            for ($d = 1; $d <= $days; $d++) {
                $buckets[] = sprintf('%s-%02d', $month, $d);
            }
            $labels = array_map(fn($b) => date('j', strtotime($b)), $buckets);          // "1".."31"
            $bucketSql = 'DATE(created_at)';
            $period = date('F Y', strtotime($from));
        } else {
            [$from, $to] = $this->range($syStart);
            $buckets = $this->months($syStart);
            $labels = array_map(fn($b) => date('M Y', strtotime($b . '-01')), $buckets); // "Aug 2026"
            $bucketSql = "DATE_FORMAT(created_at, '%Y-%m')";
            $period = 'Whole school year (' . date('M Y', strtotime($from)) . ' – ' . date('M Y', strtotime($to . ' -1 day')) . ')';
        }

        // Start every group at zero for every point, then fill in counts.
        // Statuses are listed in workflow order and shown by their label ("Under review").
        $groupKeys = $byStatus
            ? ['pending', 'under_review', 'investigating', 'escalated', 'resolved', 'rejected']
            : allowedComplaintCategories();
        $displayName = fn($key) => $byStatus ? statusMeta($key)['label'] : $key;
        $series = [];
        foreach ($groupKeys as $key) {
            $series[$displayName($key)] = array_fill(0, count($buckets), 0);
        }
        $index = array_flip($buckets);

        $stmt = $this->db->prepare(
            "SELECT " . ($byStatus ? 'status' : "COALESCE(complaint_category, 'Uncategorized')") . " AS grp, {$bucketSql} AS bucket, COUNT(*) AS total
             FROM complaints
             WHERE created_at >= :from AND created_at < :to
             GROUP BY grp, bucket"
        );
        $stmt->execute([':from' => $from, ':to' => $to]);
        foreach ($stmt->fetchAll() as $row) {
            $name = $displayName($row['grp']);
            if (!isset($series[$name])) {
                $series[$name] = array_fill(0, count($buckets), 0);   // e.g. an old category name
            }
            if (isset($index[$row['bucket']])) {
                $series[$name][$index[$row['bucket']]] = (int)$row['total'];
            }
        }

        // Per-category summary for the table: total, busiest point, share.
        $grandTotal = array_sum(array_map('array_sum', $series));
        $summary = [];
        foreach ($series as $category => $values) {
            $total = array_sum($values);
            $peakIndex = $total ? array_search(max($values), $values, true) : null;
            // Busiest point, written in full: "Oct 15" (month view) or "Oct 2026" (year view).
            $peakLabel = $peakIndex === null ? '—'
                : ($month !== null ? date('M j', strtotime($buckets[$peakIndex])) : $labels[$peakIndex]);
            $summary[$category] = [
                'total' => $total,
                'peak_label' => $peakLabel,
                'peak_count' => $peakIndex !== null ? $values[$peakIndex] : 0,
                'share' => $grandTotal ? round($total / $grandTotal * 100, 1) : 0,
                'type' => $byStatus ? null : (complaintTypeFromCategory($category) === 'services' ? 'Service' : 'Person'),
            ];
        }

        // Color for each line (same order every time, so colors stay stable).
        $colors = [];
        foreach (array_keys($series) as $i => $name) {
            $statusKey = $byStatus ? array_search($name, array_map($displayName, $groupKeys), true) : false;
            $colors[$name] = $statusKey !== false ? self::STATUS_COLORS[$groupKeys[$statusKey]] : self::COLORS[$i % count(self::COLORS)];
        }

        return [
            'school_year' => self::label($syStart),
            'dimension' => $byStatus ? 'status' : 'category',
            'dimension_label' => $byStatus ? 'Status' : 'Category',
            'colors' => $colors,
            'period' => $period,
            'view' => $month === null ? 'year' : 'month',
            'labels' => $labels,
            'series' => $series,
            'summary' => $summary,
            'total' => $grandTotal,
        ];
    }
}
