let renderCharts = function () {
    let $data = document.querySelector('[data-chart-config]');
    if (!$data || typeof Chart === 'undefined') {
        return;
    }
    let charts = JSON.parse($data.getAttribute('data-chart-config') || '[]');
    let dark = document.documentElement.getAttribute('data-theme') === 'dark';
    let ink = dark ? '#e4e4e7' : '#18181b';
    let muted = dark ? '#71717a' : '#a1a1aa';
    let grid = dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
    let accent = dark ? '#f97316' : '#ea580c';
    charts.forEach(function (chart) {
        let $canvas = document.getElementById(chart.id);
        if (!$canvas) {
            return;
        }
        Chart.getChart($canvas)?.destroy();
        // Stacked charts (sleep stages) carry their series in
        // chart.stacks and shade them from ink to muted so the
        // phases stay distinguishable in both themes.
        let stacked = (chart.stacks || []).length > 0;
        let multipleSeries = (chart.series || []).length > 0;
        let shades = dark
            ? ['#e4e4e7', '#a1a1aa', '#71717a', '#3f3f46', '#52525b']
            : ['#18181b', '#52525b', '#a1a1aa', '#d4d4d8', '#71717a'];
        let sets = multipleSeries
            ? chart.series.map(function (series, index) {
                  return {
                      label: series.label,
                      data: series.data,
                      backgroundColor: 'transparent',
                      borderColor: shades[index % shades.length],
                      borderWidth: 2,
                      pointRadius: chart.points ? 3 : 0,
                      tension: 0.3,
                      order: 2
                  };
              })
            : stacked
              ? chart.stacks.map(function (stack, index) {
                    return {
                        label: stack.label,
                        data: stack.data,
                        backgroundColor: shades[index % shades.length],
                        borderWidth: 0,
                        borderRadius: 2,
                        order: 2
                    };
                })
              : [
                    {
                        label: chart.label,
                        data: chart.data,
                        backgroundColor: chart.type === 'bar' ? ink : 'transparent',
                        borderColor: ink,
                        borderWidth: 2,
                        borderRadius: 3,
                        pointRadius: chart.points ? 3 : 0,
                        tension: 0.3,
                        order: 2
                    }
                ];
        if (chart.goal > 0) {
            sets.push({
                label: 'Ziel',
                data: chart.data.map(function () {
                    return chart.goal;
                }),
                type: 'line',
                borderColor: muted,
                borderDash: [4, 4],
                borderWidth: 1,
                pointRadius: 0,
                order: 2
            });
        }
        if ((chart.trend || []).length > 0) {
            sets.push({
                label: 'Trend',
                data: chart.trend,
                type: 'line',
                borderColor: accent,
                borderWidth: 2,
                pointRadius: 0,
                tension: 0.4,
                // Keeps the line above the stacked segments
                // instead of being summed into the stack.
                stack: 'trend',
                // Chart.js draws higher order first, so the
                // lower number puts the trend over the bars.
                order: 1
            });
        }
        new Chart($canvas, {
            type: chart.type,
            data: { labels: chart.labels, datasets: sets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            color: muted,
                            boxWidth: 10,
                            boxHeight: 10,
                            font: { size: 10 },
                            // Single-series charts only need
                            // the trend explained, not the bars.
                            filter: function (item) {
                                return stacked || multipleSeries || item.text === 'Trend';
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.dataset.label + ': ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: stacked,
                        grid: { display: false },
                        ticks: { color: muted, maxTicksLimit: 10, font: { size: 10 } }
                    },
                    y: {
                        stacked: stacked,
                        beginAtZero: chart.beginAtZero !== false,
                        grid: { color: grid },
                        ticks: {
                            color: muted,
                            font: { size: 10 },
                            callback: function (value) {
                                return value;
                            }
                        }
                    }
                }
            }
        });
    });
};

renderCharts();
document.addEventListener('themechange', renderCharts);
