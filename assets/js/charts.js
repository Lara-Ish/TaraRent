/**
 * TaraRent - Business Intelligence Visualizations
 * Chart.js Integration with Warm Claymorphic Aesthetics
 */

'use strict';

const RentConnectCharts = (() => {
  let collectionChartInstance = null;
  let unitCostChartInstance = null;
  let categoryChartInstance = null;

  // Warm Cream, Golden Brown & Dark Brown Palette matching TaraRent Theme
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

  function hasChart() {
    return typeof window.Chart !== 'undefined';
  }

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

    if (!hasChart()) {
      console.warn('Chart.js library is not available.');
      return;
    }

    const labels = monthlyData.map(m => m.month_label || m.month_key);
    const billedRent = monthlyData.map(m => Number(m.billed_rent || 0));
    const collectedRent = monthlyData.map(m => Number(m.collected_rent || 0));
    const efficiencyRate = monthlyData.map(m => Number(m.collection_efficiency_percentage || 0));

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
                  return ` Efficiency: ${Number(context.parsed.y || 0).toFixed(1)}%`;
                }
                return ` ${context.dataset.label}: ₱${Number(context.parsed.y || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            border: { display: false },
            ticks: {
              color: colors.textMain,
              font: { weight: '600', size: 11 }
            }
          },
          yAmount: {
            type: 'linear',
            position: 'left',
            beginAtZero: true,
            border: { display: false },
            grid: {
              color: colors.gridLines
            },
            ticks: {
              color: colors.taupe,
              font: { weight: '600', size: 11 },
              callback: val => `₱${Number(val).toLocaleString()}`
            }
          },
          yEfficiency: {
            type: 'linear',
            position: 'right',
            min: 0,
            max: 100,
            border: { display: false },
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
    const emptyEl = document.getElementById('unitMaintenanceEmptyState');
    if (!ctx) return;

    if (unitCostChartInstance) {
      unitCostChartInstance.destroy();
      unitCostChartInstance = null;
    }

    const safeData = Array.isArray(unitData) ? unitData : [];

    if (safeData.length === 0) {
      if (emptyEl) emptyEl.classList.add('visible');
      return;
    }
    if (emptyEl) emptyEl.classList.remove('visible');

    if (!hasChart()) {
      console.warn('Chart.js library is not available.');
      return;
    }

    const labels = safeData.map(u => u.unit_number || `Unit ${u.unit_id}`);
    const costs = safeData.map(u => Number(u.total_cost || 0));

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
                  ` Expense: ₱${Number(item.total_cost || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`,
                  ` Tickets Logged: ${item.ticket_count || 0}`,
                  ` Avg / Ticket: ₱${Number(item.avg_cost_per_ticket || 0).toFixed(2)}`
                ];
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            border: { display: false },
            ticks: {
              color: colors.textMain,
              font: { weight: '600' }
            }
          },
          y: {
            beginAtZero: true,
            suggestedMax: 1000,
            border: { display: false },
            grid: {
              color: colors.gridLines
            },
            ticks: {
              color: colors.taupe,
              precision: 0,
              callback: val => `₱${Number(val).toLocaleString()}`
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
    const emptyEl = document.getElementById('categoryChartEmptyState');
    if (!ctx) return;

    if (categoryChartInstance) {
      categoryChartInstance.destroy();
      categoryChartInstance = null;
    }

    const safeCats = Array.isArray(categoryData) ? categoryData : [];

    if (safeCats.length === 0) {
      if (emptyEl) emptyEl.classList.add('visible');
      return;
    }
    if (emptyEl) emptyEl.classList.remove('visible');

    if (!hasChart()) {
      console.warn('Chart.js library is not available.');
      return;
    }

    const hasCostData = safeCats.some(c => Number(c.total_cost || 0) > 0);
    const labels = hasCostData 
      ? safeCats.map(c => c.category) 
      : (safeCats.length > 0 ? safeCats.map(c => `${c.category} (${c.ticket_count} req)`) : ['No Expenses Logged']);
    const values = hasCostData 
      ? safeCats.map(c => Number(c.total_cost || 0)) 
      : safeCats.map(c => Math.max(1, Number(c.ticket_count || 1)));

    const palette = [colors.darkBrown, colors.gold, colors.beige, colors.forest, colors.amberWarm, colors.terracotta, colors.taupe];

    categoryChartInstance = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{
          data: values,
          backgroundColor: palette.slice(0, labels.length),
          borderWidth: 3,
          borderColor: '#F7F3ED',
          hoverOffset: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
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
            callbacks: {
              label: (context) => {
                const cat = safeCats[context.dataIndex];
                if (!cat) return '';
                if (hasCostData) {
                  return ` ${cat.category}: ₱${Number(cat.total_cost || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} (${cat.cost_share_percentage}%)`;
                }
                return ` ${cat.category}: ${cat.ticket_count} request${cat.ticket_count === 1 ? '' : 's'} (₱0.00 spent)`;
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
        if (document.getElementById('chartUnitMaintenance')) {
          renderUnitCostChart('chartUnitMaintenance', analyticsData.charts.maintenance_by_unit || []);
        }
        renderCategoryChart('chartCategoryBreakdown', analyticsData.charts.maintenance_by_category || []);
      }
    }
  };
})();

// TaraRent Charts Global Alias
window.TaraRentCharts = RentConnectCharts;
