// webroot/js/sales-chart.js; load after Chart.js and before Interact's runtime.
document.addEventListener('alpine:init', () => {
  window.Alpine.data('salesChart', () => {
    let chart;
    return {
      init() {
        const copy = (data) => JSON.parse(JSON.stringify(data));
        chart = new window.Chart(this.$refs.canvas, {
          type: 'bar',
          data: copy(this.$server.state.chartData),
        });
        this.$watch('$server.state.chartData', (data) => {
          chart.data = copy(data);
          chart.update();
        });
      },
      destroy() {
        chart?.destroy();
      },
    };
  });
});
