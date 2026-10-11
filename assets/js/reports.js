(function () {
    'use strict';

    // Chart colours for each mode. A chart follows its card: the card has
    // data-bs-theme="dark" or "light" (rendered by PHP from the user's choice).
    var THEMES = {
        dark: {
            text: '#c9d6ea',        // axis labels, legend
            muted: '#8ea3c4',       // axis titles
            grid: 'rgba(255, 255, 255, 0.07)',
            axis: 'rgba(255, 255, 255, 0.15)',
            cardBg: '#0b1d3a',      // rings the points / separates slices
            tooltipBg: 'rgba(4, 21, 47, 0.95)',
            glowTop: 0.28,
        },
        light: {
            text: '#4a5d78',
            muted: '#6b7c95',
            grid: 'rgba(10, 61, 122, 0.08)',
            axis: 'rgba(10, 61, 122, 0.2)',
            cardBg: '#ffffff',
            tooltipBg: 'rgba(13, 110, 253, 0.95)',   // standard blue (light mode)
            glowTop: 0.16,
        },
    };
    var GOLD = '#e8c547';

    /** The colour set for the card this canvas sits in. */
    function themeFor(canvas) {
        var card = canvas.closest('[data-bs-theme]');
        return THEMES[card && card.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light'];
    }
    // Fallback palette when the server sends no colors.
    var PALETTE = ['#4f9dff', '#a78bfa', '#2dd4bf', '#f2b632', '#f4566a', '#f472b6', '#a3aec2', '#fb923c'];

    function parseChartData(id) {
        var el = document.getElementById(id);
        if (!el) {
            return null;
        }
        var raw = el.getAttribute('data-chart');
        if (!raw) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    /** "#4f9dff" + 0.3 → "rgba(79, 157, 255, 0.3)" */
    function withAlpha(hex, alpha) {
        var n = parseInt(hex.replace('#', ''), 16);
        return 'rgba(' + ((n >> 16) & 255) + ', ' + ((n >> 8) & 255) + ', ' + (n & 255) + ', ' + alpha + ')';
    }

    /** A soft glow under a line: its own color at the top, fading to clear. */
    function glowFill(hex, topAlpha) {
        return function (context) {
            var chart = context.chart;
            if (!chart.chartArea) {
                return withAlpha(hex, 0.12);
            }
            var gradient = chart.ctx.createLinearGradient(0, chart.chartArea.top, 0, chart.chartArea.bottom);
            gradient.addColorStop(0, withAlpha(hex, topAlpha));
            gradient.addColorStop(1, withAlpha(hex, 0));
            return gradient;
        };
    }

    function tooltipStyle(t) {
        return {
            backgroundColor: t.tooltipBg,
            borderColor: 'rgba(232, 197, 71, 0.4)',
            borderWidth: 1,
            titleColor: GOLD,
            bodyColor: '#ffffff',
            padding: 12,
            cornerRadius: 8,
            boxPadding: 4,
            usePointStyle: true,
        };
    }

    /** "By category (all time)" doughnut. */
    function buildDoughnut(canvasId, data) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || !window.Chart || !data) {
            return;
        }
        var t = themeFor(canvas);
        new window.Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    label: data.label || 'Count',
                    data: data.values,
                    backgroundColor: data.colors || PALETTE.slice(0, data.labels.length),
                    borderColor: t.cardBg,
                    borderWidth: 3,
                    hoverOffset: 8,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: t.text, usePointStyle: true, boxWidth: 8, padding: 14 },
                    },
                    tooltip: tooltipStyle(t),
                },
            },
        });
    }

    /**
     * Complaint analytics: a line graph with ONE LINE PER CATEGORY (or per
     * status — the same function draws both cards on the Reports page).
     * data = { labels: [...], series: { name: [counts...] },
     *          colors: { name: '#hex' }, xTitle: '...' }
     * Colors come from the server (ComplaintAnalytics) so the chart matches
     * the exported PDF.
     */
    function buildAnalytics(canvasId, data) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || !window.Chart || !data) {
            return;
        }
        var t = themeFor(canvas);
        var datasets = Object.keys(data.series).map(function (name, i) {
            var color = data.colors[name] || PALETTE[i % PALETTE.length];
            return {
                label: name,
                data: data.series[name],
                borderColor: color,
                backgroundColor: glowFill(color, t.glowTop),
                fill: 'origin',
                borderWidth: 2.5,
                tension: 0.35,
                cubicInterpolationMode: 'monotone',   // smooth, but never dips below 0 between points
                pointRadius: 3,
                pointHoverRadius: 7,
                pointBackgroundColor: color,
                pointBorderColor: t.cardBg,
                pointBorderWidth: 2,
                pointHoverBorderColor: t.cardBg === '#ffffff' ? '#052654' : '#ffffff',
            };
        });

        new window.Chart(canvas, {
            type: 'line',
            data: { labels: data.labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },   // tooltip lists every line at that point
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: t.text, usePointStyle: true, pointStyle: 'circle', boxWidth: 8, padding: 16 },
                    },
                    tooltip: Object.assign(tooltipStyle(t), {
                        itemSort: function (a, b) { return b.parsed.y - a.parsed.y; },   // biggest first
                    }),
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { color: t.axis },
                        ticks: { color: t.text, autoSkip: true, maxRotation: 0 },
                        title: { display: true, text: data.xTitle, color: t.muted },
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        ticks: { precision: 0, color: t.text },
                        grid: { color: t.grid },
                        title: { display: true, text: 'Complaints', color: t.muted },
                    },
                },
            },
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        buildAnalytics('chartAnalytics', parseChartData('chartAnalyticsData'));
        buildAnalytics('chartAnalyticsStatus', parseChartData('chartAnalyticsStatusData'));
        buildDoughnut('chartCategory', parseChartData('chartCategoryData'));
    });
})();
