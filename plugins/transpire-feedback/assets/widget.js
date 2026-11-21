/* Transpire Feedback: live reactions and the feedback form.
 *
 * Everything is drawn from the /status answer, never from the page HTML, so a
 * cached page still shows today's settings. All text from the server is set
 * with textContent.
 */
(function () {
  'use strict';

  var mounts = [].slice.call(document.querySelectorAll('[data-tspr-fb]'));
  if (!mounts.length || !window.fetch) { return; }

  var apiBase = mounts[0].getAttribute('data-api');
  var status = null;
  var skew = 0; // server clock minus this device's clock, in ms
  var timer = null;
  var widgets = [];
  var uid = 0;

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null) { node.textContent = text; }
    return node;
  }

  function endpoint(path) {
    var url = apiBase + path;
    return url + (url.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
  }

  // A random id kept on this device. It lets the server pace taps and count
  // devices without knowing who anyone is.
  var client = (function () {
    var key = 'tsprFbClient';
    var id = null;
    try { id = window.localStorage.getItem(key); } catch (e) { /* private mode */ }
    if (!id || !/^[a-f0-9]{32}$/.test(id)) {
      var bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      id = [].map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
      try { window.localStorage.setItem(key, id); } catch (e) { /* kept for this page only */ }
    }
    return id;
  })();

  function post(path, body) {
    return fetch(endpoint(path), {
      method: 'POST',
      credentials: 'omit',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        return { status: response.status, data: data || {} };
      });
    });
  }

  function loadStatus() {
    return fetch(endpoint('status'), { credentials: 'omit' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data || !data.phase) { throw new Error('bad status'); }
        status = data;
        skew = data.now * 1000 - Date.now();
        widgets.forEach(function (widget) { widget.render(status); });
        schedule();
      })
      .catch(function () {
        if (!status) {
          widgets.forEach(function (widget) { widget.offline(); });
        }
        clearTimeout(timer);
        timer = setTimeout(loadStatus, 30000);
      });
  }

  // The organizers open and close reactions with a Start / Stop button, so
  // check often enough that a press reaches every phone within seconds: every
  // 15 seconds while closed, every 45 while open (a tap on a closed session
  // also triggers a check). Also check just after the scheduled start.
  function schedule() {
    clearTimeout(timer);
    var now = Date.now() + skew;
    var wait = status.reactions_open ? 45000 : 15000;
    if (status.start * 1000 > now) { wait = Math.min(wait, status.start * 1000 - now + 1500); }
    timer = setTimeout(function () {
      if (document.hidden) { schedule(); } else { loadStatus(); }
    }, wait);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { loadStatus(); }
  });

  function clock(ts, tz, seconds) {
    var options = { hour: 'numeric', minute: '2-digit' };
    if (seconds) { options.second = '2-digit'; }
    try {
      options.timeZone = tz;
      return new Date(ts * 1000).toLocaleTimeString([], options);
    } catch (e) {
      delete options.timeZone; // an unknown zone name
      return new Date(ts * 1000).toLocaleTimeString([], options);
    }
  }

  /* -------------------------------------------------------------------- chart */

  // The room's reactions over time, under the buttons. One row of columns per
  // reaction, all to the same height scale, with every reaction together on
  // top. Tap or point at a column to read its numbers.
  function RoomChart() {
    var SVG = 'http://www.w3.org/2000/svg';
    var box = el('div', 'tspr-fb-chart');
    var data = null;
    var timer = null;
    var busy = false;
    var wanted = false;
    var open = false;

    function unit(bucket) {
      if (bucket < 60) { return bucket + ' seconds'; }
      if (bucket === 60) { return 'minute'; }
      if (bucket < 3600) { return (bucket / 60) + ' minutes'; }
      return 'hour';
    }

    function plot(counts, max, label) {
      var svg = document.createElementNS(SVG, 'svg');
      svg.setAttribute('class', 'tspr-fb-plot');
      svg.setAttribute('viewBox', '0 0 ' + (counts.length * 10) + ' 30');
      svg.setAttribute('preserveAspectRatio', 'none');
      svg.setAttribute('role', 'img');
      svg.setAttribute('aria-label', label);
      svg.setAttribute('focusable', 'false');
      counts.forEach(function (count, index) {
        if (!count) { return; }
        var height = Math.max(2, count / max * 29);
        var bar = document.createElementNS(SVG, 'rect');
        bar.setAttribute('x', index * 10 + 1.5);
        bar.setAttribute('width', 7);
        bar.setAttribute('y', 30 - height);
        bar.setAttribute('height', height);
        svg.appendChild(bar);
      });
      return svg;
    }

    function row(className, name, total, counts, max, label) {
      var line = el('div', 'tspr-fb-chart-row' + (className ? ' ' + className : ''));
      var head = el('div', 'tspr-fb-chart-name');
      var mark = el('span', 'tspr-fb-chart-mark', name);
      mark.setAttribute('aria-hidden', 'true');
      head.appendChild(mark);
      var number = el('span', 'tspr-fb-chart-total', String(total));
      number.setAttribute('aria-hidden', 'true');
      head.appendChild(number);
      line.appendChild(head);
      line.appendChild(plot(counts, max, label));
      return line;
    }

    function draw() {
      box.textContent = '';
      if (!data || !data.run || !status) { box.hidden = true; return; }
      box.hidden = false;

      var byKey = {};
      status.reactions.forEach(function (reaction) { byKey[reaction.key] = reaction; });
      var series = data.series.filter(function (item) { return byKey[item.key]; });
      var all = [];
      var max = 1;
      series.forEach(function (item) {
        item.counts.forEach(function (count, index) {
          all[index] = (all[index] || 0) + count;
          max = Math.max(max, count);
        });
      });
      var allMax = Math.max.apply(null, [1].concat(all));

      box.appendChild(el('p', 'tspr-fb-chart-title', data.open ? 'The room so far' : 'How the room responded'));
      if (!data.total) {
        box.appendChild(el('p', 'tspr-fb-chart-sub', data.open ? 'No reactions yet. Each tap shows up here within a few seconds.' : 'No reactions were sent.'));
        return;
      }
      box.appendChild(el('p', 'tspr-fb-chart-sub', data.total + (data.total === 1 ? ' reaction' : ' reactions') + ', counted per ' + unit(data.bucket)));

      var rows = el('div', 'tspr-fb-chart-rows');
      rows.appendChild(row('is-all', 'All', data.total, all, allMax, 'All reactions over time: ' + data.total + ' in total'));
      series.forEach(function (item) {
        var reaction = byKey[item.key];
        var total = item.counts.reduce(function (sum, count) { return sum + count; }, 0);
        rows.appendChild(row('', reaction.emoji, total, item.counts, max, reaction.label + ' over time: ' + total + ' in total'));
      });

      var axis = el('div', 'tspr-fb-chart-row tspr-fb-chart-axis');
      axis.setAttribute('aria-hidden', 'true');
      axis.appendChild(el('div', 'tspr-fb-chart-name'));
      var ends = el('div', 'tspr-fb-chart-ends');
      ends.appendChild(el('span', null, clock(data.start, data.tz)));
      ends.appendChild(el('span', null, data.open ? 'now' : clock(data.end, data.tz)));
      axis.appendChild(ends);
      rows.appendChild(axis);

      var band = el('div', 'tspr-fb-chart-band');
      band.hidden = true;
      rows.appendChild(band);
      box.appendChild(rows);

      var readout = el('p', 'tspr-fb-chart-readout', 'Tap a column to see its numbers.');
      readout.setAttribute('aria-hidden', 'true');
      box.appendChild(readout);

      function point(event) {
        var plotNode = rows.querySelector('.tspr-fb-plot');
        var plotBox = plotNode.getBoundingClientRect();
        var step = plotBox.width / data.buckets;
        var index = Math.floor((event.clientX - plotBox.left) / step);
        if (index < 0 || index >= data.buckets) { return; }
        var from = data.start + index * data.bucket;
        var parts = [];
        series.forEach(function (item) {
          if (item.counts[index]) { parts.push(byKey[item.key].emoji + ' ' + item.counts[index]); }
        });
        readout.textContent = clock(from, data.tz, data.bucket < 60) + '–' + clock(from + data.bucket, data.tz, data.bucket < 60) + ': ' +
          (parts.length ? parts.join('  ') + '  (' + (all[index] || 0) + ' in all)' : 'no reactions');
        band.style.left = (plotBox.left - rows.getBoundingClientRect().left + index * step) + 'px';
        band.style.width = Math.max(step, 2) + 'px';
        band.hidden = false;
      }
      rows.addEventListener('pointermove', point);
      rows.addEventListener('click', point);
    }

    function fetchTimeline() {
      clearTimeout(timer);
      if (!wanted) { return; }
      if (busy || document.hidden) { later(); return; }
      busy = true;
      fetch(endpoint('timeline'), { credentials: 'omit' })
        .then(function (response) { return response.json(); })
        .then(function (next) {
          data = next;
          draw();
          // Stopped from WP Admin: tell the buttons now rather than at the next status check.
          if (open && (!next.run || !next.open)) { loadStatus(); }
        })
        .catch(function () { /* keep the last drawing; try again next time */ })
        .then(function () { busy = false; later(); });
    }

    function later() {
      clearTimeout(timer);
      if (wanted && open) { timer = setTimeout(fetchTimeline, 10000); }
    }

    this.node = box;

    // Open: redraw every 10 seconds. After the session: draw once.
    this.show = function (isOpen) {
      var changed = !wanted || open !== isOpen;
      wanted = true;
      open = isOpen;
      draw();
      if (changed) { fetchTimeline(); }
    };

    this.hide = function () {
      wanted = false;
      clearTimeout(timer);
      data = null;
      draw();
    };

    // Soon after this device taps, so people see their own reaction land.
    this.nudge = function () {
      clearTimeout(timer);
      timer = setTimeout(fetchTimeline, 1500);
    };

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && wanted && open) { fetchTimeline(); }
    });
  }

  function header(root, s, title) {
    var head = el('div', 'tspr-fb-head');
    head.appendChild(el('p', 'tspr-fb-eyebrow', s.session));
    head.appendChild(el('h3', 'tspr-fb-title', title));
    root.appendChild(head);
    if (s.test) {
      root.appendChild(el('p', 'tspr-fb-test', 'Rehearsal: anything sent now is saved as practice data, apart from the real session.'));
    }
  }

  /* ---------------------------------------------------------------- reactions */

  function Reactions(root) {
    var signature = '';
    var buttons = [];
    var lockTimer = null;
    var live = null;
    var tally = null;
    var bar = null;
    var sent = 0;
    var chart = new RoomChart();

    try { sent = parseInt(window.sessionStorage.getItem('tsprFbSent') || '0', 10) || 0; } catch (e) { /* ignore */ }

    function say(message) {
      if (live) { live.textContent = message; }
    }

    function showTally() {
      if (!tally) { return; }
      tally.textContent = sent === 0 ? '' : (sent === 1 ? 'You have shared 1 reaction.' : 'You have shared ' + sent + ' reactions.');
    }

    function unlock() {
      clearTimeout(lockTimer);
      buttons.forEach(function (button) { button.disabled = false; });
      if (bar) { bar.classList.remove('is-running'); }
    }

    function lock(seconds) {
      clearTimeout(lockTimer);
      if (!seconds || seconds <= 0) { unlock(); return; }
      buttons.forEach(function (button) { button.disabled = true; });
      if (bar) {
        bar.classList.remove('is-running');
        bar.style.setProperty('--tspr-fb-cool', seconds + 's');
        void bar.offsetWidth; // restart the animation
        bar.classList.add('is-running');
      }
      lockTimer = setTimeout(unlock, seconds * 1000);
    }

    function burst(button, emoji) {
      var float = el('span', 'tspr-fb-float', emoji);
      float.setAttribute('aria-hidden', 'true');
      button.appendChild(float);
      setTimeout(function () { if (float.parentNode) { float.parentNode.removeChild(float); } }, 900);
    }

    function send(reaction, button) {
      lock(status.cooldown);
      burst(button, reaction.emoji);
      post('react', { reaction: reaction.key, client: client }).then(function (result) {
        if (result.status === 200 && result.data.ok) {
          sent += 1;
          try { window.sessionStorage.setItem('tsprFbSent', String(sent)); } catch (e) { /* ignore */ }
          say('Sent: ' + reaction.label);
          showTally();
          chart.nudge();
        } else if (result.status === 429 && result.data.code === 'cooldown') {
          lock(result.data.retry_after);
          say('One moment, then you can react again.');
        } else if (result.status === 403) {
          say('Reactions are closed right now.');
          loadStatus();
        } else {
          unlock();
          say('That one did not send. Please try again.');
        }
      }).catch(function () {
        unlock();
        say('No connection. Please try again.');
      });
    }

    this.offline = function () {
      root.textContent = '';
      root.appendChild(el('p', 'tspr-fb-message', 'The live reactions could not load. Check your connection and refresh the page.'));
    };

    this.render = function (s) {
      var next = JSON.stringify([s.phase, s.reactions_open, s.test, s.session, s.start_label, s.end_label, s.reactions]);
      if (next === signature) { return; }
      signature = next;

      clearTimeout(lockTimer);
      buttons = [];
      live = tally = bar = null;
      root.textContent = '';
      header(root, s, 'How is this landing for you?');

      if (!s.reactions_open) {
        var pill = el('p', 'tspr-fb-pill', s.phase === 'before' ? 'Not open yet' : 'Session ended');
        root.appendChild(pill);
        if (s.phase === 'before') {
          root.appendChild(el('p', 'tspr-fb-message', 'Reactions open when the session begins, ' + s.start_label + '. Keep this page open: the buttons will appear by themselves.'));
        } else {
          root.appendChild(el('p', 'tspr-fb-message', 'Thank you for listening. Reactions for this session are closed.'));
          if (document.getElementById('transpire-feedback')) {
            var link = el('a', 'tspr-fb-link', 'Share your written feedback');
            link.href = '#transpire-feedback';
            root.appendChild(link);
          }
          root.appendChild(chart.node);
          chart.show(false);
          return;
        }
        chart.hide();
        return;
      }

      root.appendChild(el('p', 'tspr-fb-pill is-open', 'Reactions are open'));
      root.appendChild(el('p', 'tspr-fb-message', 'Tap whenever something moves you. You can react as often as you like.'));

      var grid = el('div', 'tspr-fb-grid');
      grid.setAttribute('role', 'group');
      grid.setAttribute('aria-label', 'Reactions');
      s.reactions.forEach(function (reaction) {
        var button = el('button', 'tspr-fb-btn');
        button.type = 'button';
        var emoji = el('span', 'tspr-fb-emoji', reaction.emoji);
        emoji.setAttribute('aria-hidden', 'true');
        button.appendChild(emoji);
        button.appendChild(el('span', 'tspr-fb-label', reaction.label));
        button.addEventListener('click', function () { send(reaction, button); });
        buttons.push(button);
        grid.appendChild(button);
      });
      root.appendChild(grid);

      bar = el('div', 'tspr-fb-cool');
      bar.setAttribute('aria-hidden', 'true');
      bar.appendChild(el('span'));
      root.appendChild(bar);

      live = el('p', 'tspr-fb-live');
      live.setAttribute('role', 'status');
      live.setAttribute('aria-live', 'polite');
      root.appendChild(live);

      tally = el('p', 'tspr-fb-tally');
      root.appendChild(tally);
      showTally();

      root.appendChild(chart.node);
      chart.show(true);

      root.appendChild(el('p', 'tspr-fb-note', 'Reactions are anonymous. Everyone on this page sees the room\'s totals over time, never who sent what.'));
    };
  }

  /* ----------------------------------------------------------------- feedback */

  function Feedback(root) {
    var signature = '';
    var done = false;

    function field(labelText, control, hint) {
      var wrap = el('div', 'tspr-fb-field');
      var id = 'tspr-fb-' + (++uid);
      var label = el('label', 'tspr-fb-q', labelText);
      label.setAttribute('for', id);
      control.id = id;
      wrap.appendChild(label);
      if (hint) {
        var hintNode = el('p', 'tspr-fb-hint', hint);
        hintNode.id = id + '-hint';
        control.setAttribute('aria-describedby', hintNode.id);
        wrap.appendChild(hintNode);
      }
      wrap.appendChild(control);
      return wrap;
    }

    function thanks() {
      done = true;
      root.textContent = '';
      var box = el('div', 'tspr-fb-thanks');
      box.setAttribute('role', 'status');
      box.appendChild(el('h3', 'tspr-fb-title', 'Thank you.'));
      box.appendChild(el('p', 'tspr-fb-message', 'Your feedback has been recorded and will go to the organizers.'));
      root.appendChild(box);
    }

    this.offline = function () {
      root.textContent = '';
      root.appendChild(el('p', 'tspr-fb-message', 'The feedback form could not load. Check your connection and refresh the page.'));
    };

    this.render = function (s) {
      if (done) { return; }
      var next = JSON.stringify([s.feedback_open, s.phase, s.test, s.session, s.start_label, s.feedback]);
      if (next === signature) { return; } // keeps anything already typed
      signature = next;

      root.textContent = '';
      header(root, s, 'Share your feedback');

      if (!s.feedback_open) {
        root.appendChild(el('p', 'tspr-fb-message', s.phase === 'before'
          ? 'The feedback form opens when the session begins, ' + s.start_label + '.'
          : 'Feedback for this session is now closed. Thank you.'));
        return;
      }

      var form = el('form', 'tspr-fb-form');
      form.noValidate = true;
      var config = s.feedback;
      var ratingName = 'tspr-fb-rating-' + (++uid);
      var textareas = [];
      var role = null;
      var email = null;

      if (config.rating_label) {
        var set = el('fieldset', 'tspr-fb-field tspr-fb-rating');
        set.appendChild(el('legend', 'tspr-fb-q', config.rating_label));
        set.appendChild(el('p', 'tspr-fb-hint', '1 is not at all. 5 is very much.'));
        var row = el('div', 'tspr-fb-scale');
        for (var n = 1; n <= 5; n++) {
          var option = el('label', 'tspr-fb-scale-item');
          var radio = el('input');
          radio.type = 'radio';
          radio.name = ratingName;
          radio.value = String(n);
          option.appendChild(radio);
          option.appendChild(el('span', null, String(n)));
          row.appendChild(option);
        }
        set.appendChild(row);
        form.appendChild(set);
      }

      config.questions.forEach(function (question) {
        var area = el('textarea');
        area.rows = 4;
        area.maxLength = 2000;
        textareas.push(area);
        form.appendChild(field(question, area));
      });

      if (config.roles.length) {
        role = el('select');
        role.appendChild(new Option('Prefer not to say', ''));
        config.roles.forEach(function (name) { role.appendChild(new Option(name, name)); });
        form.appendChild(field('I am here as', role));
      }

      if (config.ask_email) {
        email = el('input');
        email.type = 'email';
        email.autocomplete = 'email';
        email.maxLength = 190;
        form.appendChild(field('Email (optional)', email, 'Leave this blank to stay anonymous. Add it only if you would like the organizers to follow up with you.'));
      }

      // Hidden from people. Bots that fill every field give themselves away.
      var trap = el('div', 'tspr-fb-trap');
      trap.setAttribute('aria-hidden', 'true');
      var trapLabel = el('label', null, 'Website');
      var website = el('input');
      website.type = 'text';
      website.tabIndex = -1;
      website.autocomplete = 'off';
      trapLabel.appendChild(website);
      trap.appendChild(trapLabel);
      form.appendChild(trap);

      var message = el('p', 'tspr-fb-live');
      message.setAttribute('role', 'alert');
      form.appendChild(message);

      var submit = el('button', 'tspr-fb-submit', 'Send feedback');
      submit.type = 'submit';
      form.appendChild(submit);

      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var picked = form.querySelector('input[name="' + ratingName + '"]:checked');
        var answers = textareas.map(function (area) { return area.value.trim(); });
        var hasText = answers.some(function (answer) { return answer !== ''; });

        if (!picked && !hasText) {
          message.textContent = 'Please choose a rating or write an answer before sending.';
          return;
        }
        if (email && email.value.trim() !== '' && !email.checkValidity()) {
          message.textContent = 'That email address does not look right. Fix it or leave it blank.';
          email.focus();
          return;
        }

        submit.disabled = true;
        message.textContent = '';
        submit.textContent = 'Sending…';

        post('feedback', {
          client: client,
          rating: picked ? parseInt(picked.value, 10) : 0,
          role: role ? role.value : '',
          answers: answers,
          email: email ? email.value.trim() : '',
          website: website.value
        }).then(function (result) {
          if (result.status === 200 && result.data.ok) {
            thanks();
            return;
          }
          submit.disabled = false;
          submit.textContent = 'Send feedback';
          if (result.status === 429) {
            message.textContent = 'We already have feedback from this device. Thank you.';
          } else if (result.status === 403) {
            message.textContent = 'Feedback is closed right now.';
            loadStatus();
          } else {
            message.textContent = 'Your feedback did not send. Please try again.';
          }
        }).catch(function () {
          submit.disabled = false;
          submit.textContent = 'Send feedback';
          message.textContent = 'No connection. Your answers are still here. Please try again.';
        });
      });

      root.appendChild(form);
      root.appendChild(el('p', 'tspr-fb-note', 'Every question is optional. Answers go only to the organizers.'));
    };
  }

  mounts.forEach(function (mount) {
    var kind = mount.getAttribute('data-tspr-fb');
    widgets.push(kind === 'feedback' ? new Feedback(mount) : new Reactions(mount));
  });

  loadStatus();
})();
