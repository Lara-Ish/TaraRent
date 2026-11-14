/**
 * RentConnect - Business Intelligence Visualizations
 * Chart.js Integration with Claymorphic Aesthetics
 */

'use strict';

const RentConnectCharts = (() => {
  let collectionChartInstance = null;
  let unitCostChartInstance = null;
  let categoryChartInstance = null;

  // Warm Cream, Golden Brown & Dark Brown Palette matching TaraRent Owner Theme
  const colors = {
    gold: '#B58F53',
    goldLight: 'rgba(181, 143, 83, 0.45)',
    darkBrown: '#56380E',
    beige: '#D8BD94',
    beigeBar: 'rgba(216, 189, 148, 0.72)',
    cream: '#F5D99E',
    forest: '#2E6F4E',
    terracotta: '#B94A3E',
    amberWarm: '#D9822B',
    espresso: '#302313',
    taupe: '#6E5330',
    gridLines: 'rgba(86, 56, 14, 0.12)',
    tooltipBg: '#F7F3ED',
    textMain: '#302313'
  };

  /**
   * Universal Warm Cream Tooltip Configuration
   */
  const clayTooltipOptions = {
    backgroundColor: colors.tooltipBg,
    titleColor: '#56380E',
    bodyColor: '#302313',
    borderColor: '#D8BD94',
    borderWidth: 1,
    padding: 12,
    cornerRadius: 12,
    boxPadding: 6,
    usePointStyle: true,
    callbacks: {
      labelTextColor: () => '#302313'
    }
  };

  /**
   * 1. Render Revenue & Collection Analytics Chart
   * Dual-Axis Chart: Billed vs Collected Rent (Bars) + Efficiency Rate % (Line)
   */
  function renderCollectionChart(canvasId, monthlyData) {
    const ctx = document.getElementById(canvasId);
    const emptyEl = document.getElementById('revenueChartEmptyState');
    if (!ctx) return;

    if (collectionChartInstance) {
      collectionChartInstance.destroy();
      collectionChartInstance = null;
    }

    if (!Array.isArray(monthlyData) || monthlyData.length === 0) {
      if (emptyEl) emptyEl.classList.add('visible');
      return;
    }
    if (emptyEl) emptyEl.classList.remove('visible');

    const labels = monthlyData.map(m => m.month_label || m.month_key);
    const billedRent = monthlyData.map(m => m.billed_rent);
    const collectedRent = monthlyData.map(m => m.collected_rent);
    const efficiencyRate = monthlyData.map(m => m.collection_efficiency_percentage);

    collectionChartInstance = new Chart(ctx, {
      data: {
        labels: labels,
        datasets: [
          {
            type: 'line',
            label: 'Collection Efficiency (%)',
            data: efficiencyRate,
            borderColor: colors.darkBrown,
            backgroundColor: colors.cream,
            borderWidth: 3,
            tension: 0.35,
            pointRadius: 5,
            pointHoverRadius: 7,
            pointBackgroundColor: '#F7F3ED',
            pointBorderColor: colors.darkBrown,
            pointBorderWidth: 2.5,
            yAxisID: 'yEfficiency',
            order: 1
          },
          {
            type: 'bar',
            label: 'Collected Rent (₱)',
            data: collectedRent,
            backgroundColor: colors.gold,
            borderRadius: 10,
            borderSkipped: false,
            yAxisID: 'yAmount',
            order: 2
          },
          {
            type: 'bar',
            label: 'Billed Rent (₱)',
            data: billedRent,
            backgroundColor: colors.beigeBar,
            borderRadius: 10,
            borderSkipped: false,
            yAxisID: 'yAmount',
            order: 3
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index',
          intersect: false
        },
        plugins: {
          legend: {
            position: 'top',
            labels: {
              usePointStyle: true,
              pointStyle: 'circle',
              color: colors.textMain,
              font: { weight: '700', size: 12 },
              padding: 16
            }
          },
          tooltip: {
            ...clayTooltipOptions,
            callbacks: {
              label: function(context) {
                if (context.dataset.yAxisID === 'yEfficiency') {
                  return ` Efficiency: ${context.parsed.y.toFixed(1)}%`;
                }
                return ` ${context.dataset.label}: ₱${context.parsed.y.toLocaleString()}`;
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              color: colors.textMain,
              font: { weight: '600', size: 11 }
            }
          },
          yAmount: {
            type: 'linear',
            position: 'left',
            beginAtZero: true,
            grid: {
              color: colors.gridLines,
              drawBorder: false
            },
            ticks: {
              color: colors.taupe,
              font: { weight: '600', size: 11 },
              callback: val => `₱${val.toLocaleString()}`
            }
          },
          yEfficiency: {
            type: 'linear',
            position: 'right',
            min: 0,
            max: 100,
            grid: { display: false },
            ticks: {
              color: colors.darkBrown,
              font: { weight: '700', size: 11 },
              callback: val => `${val}%`
            }
          }
        }
      }
    });
  }

  /**
   * 2. Render Maintenance Cost Analysis Per Unit (Bar Chart)
   */
  function renderUnitCostChart(canvasId, unitData) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (unitCostChartInstance) {
      unitCostChartInstance.destroy();
      unitCostChartInstance = null;
    }

    const safeData = Array.isArray(unitData) ? unitData : [];
    const labels = safeData.map(u => u.unit_number);
    const costs = safeData.map(u => u.total_cost);

    const barColors = safeData.map(u =>
      u.cost_anomaly === 'critical_high_cost' ? colors.terracotta : colors.gold
    );

    unitCostChartInstance = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Total Maintenance Expense (₱)',
          data: costs,
          backgroundColor: barColors,
          borderRadius: 12,
          borderSkipped: false,
          maxBarThickness: 46
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            ...clayTooltipOptions,
            callbacks: {
              label: (context) => {
                const item = safeData[context.dataIndex];
                if (!item) return '';
                return [
                  ` Expense: ₱${item.total_cost.toLocaleString()}`,
                  ` Tickets Logged: ${item.ticket_count}`,
                  ` Avg / Ticket: ₱${item.avg_cost_per_ticket.toFixed(2)}`
                ];
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              color: colors.textMain,
              font: { weight: '600' }
            }
          },
          y: {
            beginAtZero: true,
            grid: {
              color: colors.gridLines,
              drawBorder: false
            },
            ticks: {
              color: colors.taupe,
              callback: val => `₱${val.toLocaleString()}`
            }
          }
        }
      }
    });
  }

  /**
   * 3. Render Repair Type Categorization (Doughnut Chart)
   */
  function renderCategoryChart(canvasId, categoryData) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (categoryChartInstance) {
      categoryChartInstance.destroy();
      categoryChartInstance = null;
    }

    const safeCats = Array.isArray(categoryData) ? categoryData : [];
    const hasData = safeCats.length > 0 && safeCats.some(c => c.total_cost > 0);
    const labels = hasData ? safeCats.map(c => c.category) : ['No Expenses Logged'];
    const costs = hasData ? safeCats.map(c => c.total_cost) : [1];
    const palette = hasData
      ? [colors.darkBrown, colors.gold, colors.beige, colors.forest, colors.amberWarm, colors.terracotta]
      : ['rgba(216, 189, 148, 0.45)'];

    categoryChartInstance = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{
          data: costs,
          backgroundColor: palette.slice(0, labels.length),
          borderWidth: 3,
          borderColor: '#F7F3ED',
          hoverOffset: hasData ? 8 : 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '64%',
        plugins: {
          legend: {
            position: window.innerWidth <= 560 ? 'bottom' : 'right',
            labels: {
              usePointStyle: true,
              pointStyle: 'circle',
              color: colors.textMain,
              font: { weight: '600', size: 12 },
              padding: 14
            }
          },
          tooltip: {
            ...clayTooltipOptions,
            enabled: hasData,
            callbacks: {
              label: (context) => {
                const cat = safeCats[context.dataIndex];
                if (!cat) return '';
                return ` ${cat.category}: ₱${cat.total_cost.toLocaleString()} (${cat.cost_share_percentage}%)`;
              }
            }
          }
        }
      }
    });
  }

  return {
    initCharts: (analyticsData) => {
      if (analyticsData && analyticsData.charts) {
        renderCollectionChart('chartCollectionEfficiency', analyticsData.charts.monthly_collection_efficiency || []);
        renderUnitCostChart('chartUnitMaintenance', analyticsData.charts.maintenance_by_unit || []);
        renderCategoryChart('chartCategoryBreakdown', analyticsData.charts.maintenance_by_category || []);
      }
    }
  };
})();

// TaraRent Charts Global Alias
window.TaraRentCharts = RentConnectCharts;
