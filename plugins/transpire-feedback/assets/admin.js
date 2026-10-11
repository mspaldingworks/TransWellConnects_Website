/* Transpire Feedback: WP Admin results screen.
 * Adds the hover readout to the timeline, the live refresh, and the delete confirmation.
 */
(function () {
  'use strict';

  var PLOT_WIDTH = 720;

  [].forEach.call(document.querySelectorAll('[data-tspr-chart]'), function (chart) {
    var data;
    try { data = JSON.parse(chart.getAttribute('data-tspr-chart')); } catch (e) { return; }
    var band = chart.querySelector('.tspr-chart-band');
    var tip = chart.querySelector('.tspr-chart-tip');
    var count = data.labels.length;
    var step = PLOT_WIDTH / count;
    var shown = -1;

    function row(name, value, className) {
      var line = document.createElement('div');
      line.className = 'tspr-tip-row' + (className ? ' ' + className : '');
      var label = document.createElement('span');
      label.textContent = name;
      var number = document.createElement('b');
      number.textContent = String(value);
      line.appendChild(label);
      line.appendChild(number);
      return line;
    }

    function fill(index) {
      tip.textContent = '';
      var heading = document.createElement('strong');
      heading.textContent = data.labels[index];
      tip.appendChild(heading);
      data.series.forEach(function (series) {
        var value = series.counts[index] || 0;
        tip.appendChild(row(series.name, value, value ? '' : 'is-zero'));
      });
      tip.appendChild(row('All reactions', data.all[index] || 0, 'tspr-tip-total'));
    }

    function hide() {
      shown = -1;
      band.hidden = true;
      tip.hidden = true;
    }

    chart.addEventListener('pointermove', function (event) {
      var plot = event.target.closest ? event.target.closest('.tspr-plot') : null;
      if (!plot) { hide(); return; }

      var plotBox = plot.getBoundingClientRect();
      var chartBox = chart.getBoundingClientRect();
      var index = Math.floor((event.clientX - plotBox.left) / step);
      if (index < 0 || index >= count) { hide(); return; }

      if (index !== shown) {
        shown = index;
        fill(index);
        band.style.left = (plotBox.left - chartBox.left + index * step) + 'px';
        band.style.width = Math.max(step, 2) + 'px';
        band.hidden = false;
        tip.hidden = false;
      }

      // Keep the readout beside the pointer and inside the chart.
      var x = event.clientX - chartBox.left + 16;
      if (x + tip.offsetWidth > chartBox.width) { x = event.clientX - chartBox.left - tip.offsetWidth - 16; }
      tip.style.left = Math.max(0, x) + 'px';
      tip.style.top = Math.max(0, event.clientY - chartBox.top - tip.offsetHeight / 2) + 'px';
    });
    chart.addEventListener('pointerleave', hide);
  });

  var live = document.querySelector('[data-tspr-live]');
  if (live) {
    setTimeout(function () { window.location.reload(); }, parseInt(live.getAttribute('data-tspr-live'), 10) * 1000);
  }

  [].forEach.call(document.querySelectorAll('form[data-tspr-confirm]'), function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm(form.getAttribute('data-tspr-confirm'))) { event.preventDefault(); }
    });
  });
})();
