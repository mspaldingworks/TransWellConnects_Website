/* Transpire Feedback: WP Admin screens.
 * Adds the hover readout to the timeline, the live chart on the Live session
 * screen, the page refresh on Results, and the confirmations.
 */
(function () {
  'use strict';

  var PLOT_WIDTH = 720;

  function bindChart(chart) {
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
  }

  [].forEach.call(document.querySelectorAll('[data-tspr-chart]'), bindChart);

  var live = document.querySelector('[data-tspr-live]');
  if (live) {
    setTimeout(function () { window.location.reload(); }, parseInt(live.getAttribute('data-tspr-live'), 10) * 1000);
  }

  // Live session screen, while a run is recording: tick the clock every
  // second and redraw the chart every five seconds.
  var panel = document.querySelector('[data-tspr-run]');
  if (panel) {
    var since = parseInt(panel.getAttribute('data-tspr-since'), 10) * 1000;
    var skew = parseInt(panel.getAttribute('data-tspr-now'), 10) * 1000 - Date.now();
    var clock = panel.querySelector('[data-tspr-elapsed]');
    var totalNode = panel.querySelector('[data-tspr-total]');
    var devicesNode = panel.querySelector('[data-tspr-devices]');
    var chartBox = panel.querySelector('[data-tspr-livechart]');
    var updated = panel.querySelector('[data-tspr-updated]');
    var url = panel.getAttribute('data-tspr-ajax') +
      '?action=transpire_fb_live&run=' + encodeURIComponent(panel.getAttribute('data-tspr-run')) +
      '&_ajax_nonce=' + encodeURIComponent(panel.getAttribute('data-tspr-nonce'));
    var busy = false;

    var tick = function () {
      var seconds = Math.max(0, Math.floor((Date.now() + skew - since) / 1000));
      var h = Math.floor(seconds / 3600);
      var m = Math.floor(seconds / 60) % 60;
      var s = seconds % 60;
      clock.textContent = h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    };
    tick();
    setInterval(tick, 1000);

    var refresh = function () {
      if (busy || document.hidden) { return; }
      busy = true;
      fetch(url + '&_=' + Date.now(), { credentials: 'same-origin' })
        .then(function (response) { return response.json(); })
        .then(function (result) {
          if (!result || !result.success) { throw new Error('refresh failed'); }
          if (!result.data.active) {
            // Stopped somewhere else, or closed for running too long.
            window.location.reload();
            return;
          }
          totalNode.textContent = result.data.total;
          devicesNode.textContent = result.data.devices;
          chartBox.innerHTML = result.data.html;
          [].forEach.call(chartBox.querySelectorAll('[data-tspr-chart]'), bindChart);
          if (updated) { updated.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' }); }
        })
        .catch(function () {
          if (updated) { updated.textContent = 'Could not refresh. Trying again…'; }
        })
        .then(function () { busy = false; });
    };
    setInterval(refresh, 5000);
  }

  [].forEach.call(document.querySelectorAll('form[data-tspr-confirm]'), function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm(form.getAttribute('data-tspr-confirm'))) { event.preventDefault(); }
    });
  });
})();
