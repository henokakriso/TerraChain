/* TerraChain frontend — pure JavaScript SPA (no frameworks). */
(function () {
  'use strict';

  var csrf = null;
  var me = null;

  /* ---------------- API client ---------------- */

  function api(method, path, body) {
    var opts = { method: method, headers: { 'Content-Type': 'application/json' } };
    if (body !== undefined && body !== null) {
      body._csrf = csrf;
      opts.body = JSON.stringify(body);
    }
    return fetch(path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (b) {
        return { status: r.status, body: b };
      });
    });
  }

  var get = function (path) { return api('GET', path); };

  /* ---------------- UI helpers ---------------- */

  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        if (k === 'class') n.className = attrs[k];
        else if (k === 'text') n.textContent = attrs[k];
        else if (k === 'html') n.innerHTML = attrs[k];
        else if (k === 'onclick') n.addEventListener('click', attrs[k]);
        else n.setAttribute(k, attrs[k]);
      });
    }
    (children || []).forEach(function (c) { n.appendChild(c); });
    return n;
  }

  function msg(text, type, append) {
    var m = document.getElementById('msg');
    m.textContent = text;
    m.className = 'msg show msg-' + (type || 'error');
    if (!append) setTimeout(function () { m.className = 'msg msg-error'; }, 6000);
  }

  function badge(status) {
    var nice = String(status || 'unknown').toLowerCase();
    var cls = {
      approved: 'b-approved', published: 'b-published', active: 'b-active',
      draft: 'b-draft', pending: 'b-pending', submitted: 'b-submitted',
      awarded: 'b-awarded', closed: 'b-closed', cancelled: 'b-cancelled',
      rejected: 'b-rejected', sealed: 'b-sealed', opened: 'b-approved',
      opened_ok: 'b-published', paid: 'b-approved', failed: 'b-failed'
    }[nice];
    return '<span class="badge ' + (cls || 'b-submitted') + '">' + String(status).toUpperCase() + '</span>';
  }

  function table(headers, rows) {
    var t = el('table', { class: 'tbl' });
    var tr = el('tr');
    headers.forEach(function (h) { tr.appendChild(el('th', { text: h })); });
    t.appendChild(el('thead', null, [tr]));
    var tb = el('tbody');
    if (!rows.length) {
      tb.appendChild(el('tr', null, [el('td', { class: 'empty', text: 'No records', colspan: headers.length })]));
    }
    rows.forEach(function (row) {
      var r = el('tr');
      row.forEach(function (cell) { r.appendChild(el('td', { html: cell })); });
      tb.appendChild(r);
    });
    t.appendChild(tb);
    return t;
  }

  function field(label, input, note) {
    var l = el('label', { text: label });
    var wrap = el('div');
    wrap.appendChild(l);
    wrap.appendChild(input);
    if (note) wrap.appendChild(el('div', { class: 'form-note', text: note }));
    return wrap;
  }

  function textInput(name, attrs) {
    var a = Object.assign({ name: name }, attrs || {});
    return document.createElement('input');
  }

  function fmtMoney(v) {
    var n = parseFloat(v);
    return isNaN(n) ? '-' : 'ETB ' + n.toLocaleString('en-US', { maximumFractionDigits: 2 });
  }

  function fmtDate(s) { return s ? String(s).slice(0, 10) : '-'; }

  /* ---------------- views ---------------- */

  var views = {};

  views.dashboard = function (done) {
    Promise.all([get('/api/v1/reports/dashboard'), get('/api/v1/system/integrity')]).then(function (rs) {
      var d = rs[0].body.data || {};
      var integ = rs[1].body.data;
      var v = el('div');
      var box = el('div', { class: 'grid-3' });
      var land = d.land || {}, proc = d.procurement || {};
      [
        ['Total parcels', land.total_parcels], ['Registered', land.registered],
        ['Pending applications', land.pending], ['Active tenders', proc.active_tenders],
        ['Open bids', proc.open_bids], ['Contract value', fmtMoney(proc.contract_value)]
      ].forEach(function (kpi) {
        box.appendChild(el('div', { class: 'card kpi' }, [
          el('div', { class: 'n', text: String(kpi[1] === undefined ? '-' : kpi[1]) }),
          el('div', { class: 'l', text: kpi[0] })
        ]));
      });
      v.appendChild(box);

      var st = el('div', { class: 'card' });
      st.appendChild(el('h3', { text: 'Integrity chains' }));
      var rows = [];
      var all = true;
      if (integ && typeof integ.valid === 'boolean') all = integ.valid;
      if (integ && integ.chains) {
        Object.keys(integ.chains).forEach(function (c) {
          var r = integ.chains[c];
          all = all && !!r.valid;
          rows.push([el('div', { text: c }).outerHTML + '', r.valid ? '<span class="ok">VALID</span>' : '<span class="err">INVALID</span>', 'links: ' + (r.entries || 0), (r.problems || []).join('<br>')]);
        });
      }
      if (rows.length) {
        st.appendChild(table(['Chain', 'Status', 'Entries', 'Problems'], rows));
        st.appendChild(el('p', { class: 'form-note', html: 'Overall: <b>' + (all ? '<span class="ok">VALID</span>' : '<span class="err">INVALID</span>') + '</b>' }));
      } else {
        st.appendChild(el('div', { class: 'empty', text: 'No chain data' }));
      }
      v.appendChild(st);
      done(v);
    }).catch(function (e) { msg('Dashboard failed: ' + e.message); done(el('div')); });
  };

  views.parcels = function (done) {
    get('/api/v1/parcels').then(function (r) {
      var v = el('div');
      if (r.status !== 200) { v.appendChild(el('p', { class: 'empty', text: r.body.message || 'Forbidden' })); return done(v); }
      var list = r.body.data.parcels || [];
      var rows = list.map(function (p) {
        return [
          p.parcel_no,
          p.kebele_name || p.kebele_id,
          p.land_use || '-',
          (p.area === null || p.area === undefined ? '-' : p.area + ' ' + (p.area_unit || '')),
          p.status,
          el('div', { class: 'row-actions' }, [
            el('button', { class: 'btn btn-secondary btn-sm', onclick: function () { parcelDetail(p.id); }, text: 'Detail' })
          ]).outerHTML
        ];
      });
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Parcels (' + list.length + ')' }), table(['Parcel no', 'Admin unit', 'Land use', 'Area', 'Status', ''], rows)]));
      done(v);
    });
  };

  function parcelDetail(id) {
    get('/api/v1/parcels/' + id).then(function (r) {
      var p = r.body.data;
      var v = document.getElementById('view');
      var card = el('div', { class: 'card' });
      card.appendChild(el('h3', { text: 'Parcel ' + (p.parcel_no || id) }));
      var rows = [
        ['Location', [p.kebele_name, p.woreda_name, p.zone_name, p.region_name].filter(Boolean).join(' — ') || '-'],
        ['Description', p.location_description || '-'],
        ['Area', (p.area === null || p.area === undefined ? '-' : p.area + ' ' + (p.area_unit || ''))],
        ['Land use', p.land_use || '-'],
        ['Version', 'v' + p.current_version],
        ['Status', badge(p.status)],
        ['Created', fmtDate(p.created_at)]
      ];
      card.appendChild(table(['Field', 'Value'], rows));
      if (p.versions && p.versions.length) {
        card.appendChild(el('h3', { text: 'Land record versions (' + p.versions.length + ')' }));
        card.appendChild(table(['Version', 'Title', 'Status', 'Type', 'Reason', 'At'], p.versions.map(function (rv) {
          return ['v' + rv.version, rv.title, rv.status, rv.record_type, rv.reason || '-', fmtDate(rv.created_at)];
        })));
      }
      if (p.owners && p.owners.length) {
        card.appendChild(el('h3', { text: 'Owners' }));
        card.appendChild(table(['Owner', 'Share', 'Start', 'End'], p.owners.map(function (o) {
          return [(o.first_name || '') + ' ' + (o.father_name || ''), o.share_pct + '%', fmtDate(o.start_date), fmtDate(o.end_date)];
        })));
      }
      card.appendChild(el('div', { class: 'btn-row' }, [el('button', { class: 'btn btn-secondary', onclick: function () { views.parcels(function (x) { v.replaceWith(x); }); }, text: '\u2190 Back' })]));
      v.replaceWith(card);
    });
  }

  views.applications = function (done) {
    get('/api/v1/applications').then(function (r) {
      var v = el('div');
      if (r.status !== 200) { v.appendChild(el('p', { class: 'empty', text: r.body.message || 'Forbidden' })); return done(v); }
      var list = r.body.data.applications || [];
      var rows = list.map(function (a) {
        return [
          a.application_no,
          a.application_type,
          a.parcel_no || '-',
          (a.first_name || '') + ' ' + (a.father_name || ''),
          badge(a.status),
          'step ' + (a.current_step || 0) + '/7',
          el('div', { class: 'row-actions' }, [
            el('button', { class: 'btn btn-secondary btn-sm', onclick: function () { appDetail(a.id); }, text: 'Detail' }),
            (a.status === 'pending' || a.status === 'submitted') ?
              el('button', { class: 'btn btn-sm', onclick: function () { advance(a.id); }, text: 'Advance' }) : '',
            (a.status === 'pending' || a.status === 'submitted') ?
              el('button', { class: 'btn btn-danger btn-sm', onclick: function () { cancelApplication(a.id); }, text: 'Cancel' }) : ''
          ]).outerHTML
        ];
      });
      v.appendChild(createAppForm());
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Applications (' + list.length + ')' }), table(['No', 'Type', 'Parcel', 'Applicant', 'Status', 'Step', ''], rows)]));
      done(v);
    });
  };

  function createAppForm() {
    var f = el('div', { class: 'card' });
    f.appendChild(el('h3', { text: 'Submit new application' }));
    var type = el('select');
    ['land_correction', 'certificate_issue', 'transfer', 'dispute'].forEach(function (t) {
      var o = el('option', { value: t, text: t });
      type.appendChild(o);
    });
    var par = el('input', { placeholder: 'parcel_id (e.g. 3)' });
    var title = el('input', { placeholder: 'Title / description opt' });
    var btn = el('button', { class: 'btn', text: 'Submit application', onclick: function () {
      api('POST', '/api/v1/applications', {
        parcel_id: parseInt(par.value, 10) || undefined, application_type: type.value, title: title.value || undefined
      }).then(function (r) {
        if (r.status === 201) { msg('Application ' + r.body.data.application_no + ' submitted', 'ok'); views.applications(function (x) { document.getElementById('view').replaceWith(x); }); }
        else msg((r.body.message || 'Failed') + (r.body.errors ? ': ' + r.body.errors.join(', ') : ''));
      });
    } });
    var row = el('div', { class: 'form-row' });
    row.appendChild(field('Type', type));
    row.appendChild(field('Parcel ID', par));
    row.appendChild(field('Title', title, 'Optional'));
    f.appendChild(row);
    f.appendChild(btn);
    return f;
  }

  function advance(id) {
    api('POST', '/api/v1/applications/' + id + '/workflow', {}).then(function (r) {
      if (r.status === 200) { msg('Workflow advanced', 'ok'); views.applications(function (x) { document.getElementById('view').replaceWith(x); }); }
      else msg(r.body.message || 'Cannot advance', '', true);
    });
  }

  function cancelApplication(id) {
    var reason = window.prompt('Reason for cancelling this application:', '');
    if (reason === null) return;
    api('POST', '/api/v1/applications/' + id + '/workflow', { action: 'cancel', comment: reason }).then(function (r) {
      if (r.status === 200) { msg('Application cancelled', 'ok'); views.applications(function (x) { document.getElementById('view').replaceWith(x); }); }
      else msg(r.body.message || 'Cancel failed');
    });
  }

  function appDetail(id) {
    get('/api/v1/applications/' + id).then(function (r) {
      var d = r.body.data;
      var v = document.getElementById('view');
      var card = el('div', { class: 'card' });
      card.appendChild(el('h3', { text: 'Application ' + d.application_no }));
      card.appendChild(table(['Field', 'Value'], [
        ['Application no', d.application_no],
        ['Applicant', (d.first_name || '') + ' ' + (d.father_name || '') + ' ' + (d.grand_father_name || '')],
        ['Type', d.application_type],
        ['Parcel', d.parcel_no || '-'],
        ['Status', badge(d.status)],
        ['Step', d.current_step + ' / 7' + (d.workflow && d.workflow.label ? ' — ' + d.workflow.label : '')],
        ['Applied', fmtDate(d.applied_date)],
        ['Reason / note', d.decision_reason || '-']
      ]));
      if (d.approvals && d.approvals.length) {
        card.appendChild(el('h3', { text: 'Approvals (' + d.approvals.length + ')' }));
        card.appendChild(table(['Step', 'Approver', 'Status', 'Comment', 'At'], d.approvals.map(function (s) {
          return [s.step_name, s.approver_name || '-', badge(s.status), s.comment || '-', fmtDate(s.decided_at)];
        })));
      }
      card.appendChild(el('div', { class: 'btn-row' }, [
        el('button', { class: 'btn btn-secondary', onclick: function () { views.applications(function (x) { v.replaceWith(x); }); }, text: '\u2190 Back' }),
        (d.status === 'pending' || d.status === 'submitted') ? el('button', { class: 'btn', onclick: function () { advance(d.id); }, text: 'Advance workflow' }) : ''
      ]));
      v.replaceWith(card);
    });
  }

  views.tenders = function (done) {
    get('/api/v1/tenders').then(function (r) {
      var v = el('div');
      if (r.status !== 200) { v.appendChild(el('p', { class: 'empty', text: r.body.message || 'Forbidden' })); return done(v); }
      var list = r.body.data.tenders || [];
      var rows = list.map(function (t) {
        return [
          t.tender_no,
          t.title,
          badge(t.status),
          'v' + t.current_version,
          fmtDate(t.publication_date) + ' \u2192 ' + fmtDate(t.closing_date),
          fmtMoney(t.budget_estimate),
          el('div', { class: 'row-actions' }, [
            el('button', { class: 'btn btn-secondary btn-sm', onclick: function () { tenderDetail(t.id); }, text: 'Detail' }),
            t.status === 'draft' ? el('button', { class: 'btn btn-sm', onclick: function () { publishTender(t.id); }, text: 'Publish' }) : '',
            t.status === 'published' ? el('button', { class: 'btn btn-sm', onclick: function () { amendTender(t.id); }, text: 'Amend' }) : '',
            (t.status === 'draft' || t.status === 'pending_approval' || t.status === 'published' || t.status === 'closed') ?
              el('button', { class: 'btn btn-danger btn-sm', onclick: function () { cancelTender(t.id); }, text: 'Cancel' }) : ''
          ]).outerHTML
        ];
      });
      v.appendChild(createTenderForm());
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Tenders (' + list.length + ')' }), table(['No', 'Title', 'Status', 'Ver', 'Publish \u2192 Close', 'Budget', ''], rows)]));
      done(v);
    });
  };

  function createTenderForm() {
    var f = el('div', { class: 'card' });
    f.appendChild(el('h3', { text: 'Create tender draft' }));
    var title = el('input', { placeholder: 'Title' });
    var budget = el('input', { type: 'number', placeholder: 'Budget estimate (ETB)' });
    var criteria = el('input', { placeholder: 'Evaluation criteria (e.g. Lowest price)' });
    var cat = el('input', { placeholder: 'Category (optional)' });
    var row = el('div', { class: 'form-row' });
    row.appendChild(field('Title', title));
    row.appendChild(field('Budget estimate', budget));
    row.appendChild(field('Evaluation criteria', criteria));
    row.appendChild(field('Category', cat));
    f.appendChild(row);
    f.appendChild(el('button', { class: 'btn', text: 'Create draft', onclick: function () {
      api('POST', '/api/v1/tenders', {
        title: title.value, budget_estimate: parseFloat(budget.value) || undefined,
        evaluation_criteria: criteria.value || undefined, category: cat.value || undefined
      }).then(function (r) {
        if (r.status === 201) { msg('Tender ' + r.body.data.tender_no + ' created', 'ok'); views.tenders(function (x) { document.getElementById('view').replaceWith(x); }); }
        else msg(r.body.message || 'Failed');
      });
    } }));
    return f;
  }

  function publishTender(id) {
    var closing = window.prompt('Closing date (YYYY-MM-DD):', '');
    if (!closing || !/^\d{4}-\d{2}-\d{2}$/.test(closing)) return;
    api('POST', '/api/v1/tenders/' + id + '/publish', { closing_date: closing }).then(function (r) {
      if (r.status === 200) { msg('Tender published (v' + r.body.data.current_version + ')', 'ok'); views.tenders(function (x) { document.getElementById('view').replaceWith(x); }); }
      else msg(r.body.message || 'Publish failed');
    });
  }

  function amendTender(id) {
    var fieldName = window.prompt('Field to amend (title | description | evaluation_criteria | closing_date | category):', 'title');
    if (!fieldName) return;
    var val = window.prompt('New value:');
    if (val === null) return;
    var body = {};
    body[fieldName] = val;
    api('PUT', '/api/v1/tenders/' + id, body).then(function (r) {
      if (r.status === 200) { msg('Tender amended (v' + r.body.data.current_version + ')', 'ok'); views.tenders(function (x) { document.getElementById('view').replaceWith(x); }); }
      else msg(r.body.message || 'Amend failed');
    });
  }

  function cancelTender(id) {
    var reason = window.prompt('Reason for cancelling this tender:', '');
    if (reason === null) return;
    api('POST', '/api/v1/tenders/' + id + '/cancel', { reason: reason || 'No reason given' }).then(function (r) {
      if (r.status === 200) { msg('Tender cancelled (v' + r.body.data.current_version + ')', 'ok'); views.tenders(function (x) { document.getElementById('view').replaceWith(x); }); }
      else msg(r.body.message || 'Cancel failed');
    });
  }

  function tenderDetail(id) {
    get('/api/v1/tenders/' + id).then(function (r) {
      var t = r.body.data;
      var v = document.getElementById('view');
      var card = el('div', { class: 'card' });
      card.appendChild(el('h3', { text: t.tender_no + ' — ' + t.title }));
      card.appendChild(table(['Field', 'Value'], [
        ['Status', badge(t.status)], ['Version', 'v' + t.current_version], ['Category', t.category || '-'],
        ['Budget', fmtMoney(t.budget_estimate)], ['Currency', t.currency],
        ['Published', fmtDate(t.publication_date)], ['Closes', fmtDate(t.closing_date)],
        ['Criteria', t.evaluation_criteria || '-'], ['Description', t.description || '-']
      ]));
      if (t.versions && t.versions.length) {
        card.appendChild(el('h3', { text: 'Versions' }));
        card.appendChild(table(['Version', 'Changed at', 'By', 'FSnapshot'], t.versions.map(function (x) {
          return ['v' + x.version, fmtDate(x.created_at), x.changed_by, (x.fsnapshot || '')];
        })));
      }
      card.appendChild(el('div', { class: 'btn-row' }, [
        el('button', { class: 'btn btn-secondary', onclick: function () { views.tenders(function (x) { v.replaceWith(x); }); }, text: '\u2190 Back' }),
        t.status === 'draft' ? el('button', { class: 'btn', onclick: function () { publishTender(t.id); }, text: 'Publish' }) : '',
        t.status === 'published' ? el('button', { class: 'btn', onclick: function () { amendTender(t.id); }, text: 'Amend' }) : '',
        t.status === 'published' ? el('button', { class: 'btn btn-secondary', onclick: function () { window.location.hash = '#bids'; }, text: 'Bids on this tender' }) : ''
      ]));
      v.replaceWith(card);
    });
  }

  views.bids = function (done) {
    get('/api/v1/bids').then(function (r) {
      var v = el('div');
      if (r.status !== 200) { v.appendChild(el('p', { class: 'empty', text: r.body.message || 'Forbidden' })); return done(v); }
      var list = r.body.data.bids || [];
      var rows = list.map(function (b) {
        return [
          b.bid_no,
          b.tender_no,
          b.supplier_name,
          badge(b.opening_status || b.status),
          b.amount === null ? '<i class="muted">sealed</i>' : fmtMoney(b.amount),
          b.status === 'evaluated' ? b.evaluation_score + ' / 100' : '-',
          el('div', { class: 'row-actions' }, [
            b.opening_status === 'evaluated' || b.status !== 'submitted' ? '' : el('button', { class: 'btn btn-sm', onclick: function () { evaluateBid(b.id); }, text: 'Evaluate' })
          ]).outerHTML
        ];
      });
      v.appendChild(openBidsForm(list));
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Bids (' + list.length + ')' }), table(['No', 'Tender', 'Supplier', 'Opening', 'Amount', 'Score', ''], rows)]));
      done(v);
    });
  };

  function openBidsForm(list) {
    var f = el('div', { class: 'card' });
    f.appendChild(el('h3', { text: 'Open bids' }));
    var sel = el('select');
    var seen = {};
    list.forEach(function (b) {
      if (!seen[b.tender_id]) {
        seen[b.tender_id] = 1;
        var opt = el('option', { value: b.tender_id, text: b.tender_no + ' (tender ' + b.tender_id + ')' });
        sel.appendChild(opt);
      }
    });
    f.appendChild(field('Tender', sel));
    f.appendChild(el('button', { class: 'btn', text: 'Open all bids for tender', onclick: function () {
      api('POST', '/api/v1/tenders/' + sel.value + '/open-bids', {}).then(function (r) {
        if (r.status === 200) { msg('Bids opened (revealed: ' + r.body.data.opened.length + ')', 'ok'); views.bids(function (x) { document.getElementById('view').replaceWith(x); }); }
        else msg(r.body.message || 'Open failed');
      });
    } }));
    return f;
  }

  function evaluateBid(id) {
    var score = window.prompt('Evaluation score (0-100):', '80');
    if (score === null || !/^\d+$/.test(score)) return;
    score = Math.max(0, Math.min(100, parseInt(score, 10)));
    api('POST', '/api/v1/bids/' + id + '/evaluate', { score: score }).then(function (r) {
      if (r.status === 200) { msg('Bid evaluated', 'ok'); views.bids(function (x) { document.getElementById('view').replaceWith(x); }); }
      else msg(r.body.message || 'Evaluate failed');
    });
  }

  views.contracts = function (done) {
    get('/api/v1/contracts').then(function (r) {
      var v = el('div');
      var list = r.status === 200 ? (r.body.data.contracts || []) : [];
      var rows = list.map(function (c) {
        return [
          c.contract_no,
          c.tender_no || '-',
          c.supplier_name || '-',
          fmtMoney(c.value_amount) + ' ' + c.currency,
          badge(c.status),
          fmtDate(c.start_date),
          el('div', { class: 'row-actions' }, [
            c.status === 'pending' ? el('button', { class: 'btn btn-sm', onclick: function () { approveContract(c.id); }, text: 'Approve' }) : '',
            (c.status === 'active' || c.status === 'pending') ? el('button', { class: 'btn btn-danger btn-sm', onclick: function () { terminateContract(c.id); }, text: 'Terminate' }) : ''
          ]).outerHTML
        ];
      });
      v.appendChild(createContractForm());
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Contracts (' + list.length + ')' }), table(['No', 'Tender', 'Supplier', 'Value', 'Status', 'Signed', ''], rows)]));
      done(v);
    });
  };

  function createContractForm() {
    return Promise.resolve(null).then(function () {
      return get('/api/v1/tenders');
    }).then(function (r) {
      var f = el('div', { class: 'card' });
      f.appendChild(el('h3', { text: 'Award contract' }));
      var tenders = (r.body.data.tenders || []).filter(function (t) { return t.status === 'published'; });
      if (!tenders.length) {
        f.appendChild(el('div', { class: 'empty', text: 'No published tenders available for award' }));
        return f;
      }
      var sel = el('select');
      tenders.forEach(function (t) {
        var o = el('option', { value: t.id, text: t.tender_no + ' — ' + t.title, 'data-budget': t.budget_estimate });
        sel.appendChild(o);
      });
      var budget = sel.options[0].dataset.budget;
      sel.addEventListener('change', function () { budget = sel.options[sel.selectedIndex].dataset.budget; });
      var value = el('input', { type: 'number', placeholder: 'Contract value (ETB)' });
      var supplier = el('input', { placeholder: 'Supplier name' });
      var org = el('input', { placeholder: 'supplier_org_id (e.g. 3)' });
      var row = el('div', { class: 'form-row' });
      row.appendChild(field('Tender', sel, 'Budget ' + fmtMoney(budget)));
      row.appendChild(field('Value', value));
      row.appendChild(field('Supplier name', supplier));
      row.appendChild(field('Supplier org ID', org));
      f.appendChild(row);
      f.appendChild(el('button', { class: 'btn', text: 'Create contract', onclick: function () {
        api('POST', '/api/v1/contracts', {
          tender_id: parseInt(sel.value, 10),
          supplier_org_id: parseInt(org.value, 10) || undefined,
          supplier_name: supplier.value || undefined,
          value: parseFloat(value.value) || null
        }).then(function (rr) {
          if (rr.status === 201) { msg('Contract ' + rr.body.data.contract_no + ' created', 'ok'); views.contracts(function (x) { document.getElementById('view').replaceWith(x); }); }
          else msg(rr.body.message || 'Contract creation failed');
        });
      } }));
      return f;
    });
  }

  function approveContract(id) {
    api('POST', '/api/v1/contracts/' + id + '/approve', {}).then(function (r) {
      if (r.status === 200) { msg('Contract approved', 'ok'); views.contracts(function (x) { document.getElementById('view').replaceWith(x); }); }
      else msg(r.body.message || 'Approve failed');
    });
  }

  function terminateContract(id) {
    var reason = window.prompt('Reason for terminating this contract:', '');
    if (reason === null) return;
    api('POST', '/api/v1/contracts/' + id + '/terminate', { reason: reason || 'No reason given' }).then(function (r) {
      if (r.status === 200) { msg('Contract terminated', 'ok'); views.contracts(function (x) { document.getElementById('view').replaceWith(x); }); }
      else msg(r.body.message || 'Terminate failed');
    });
  }

  views.payments = function (done) {
    get('/api/v1/payments').then(function (r) {
      var v = el('div');
      var list = r.status === 200 ? (r.body.data.payments || []) : [];
      var rows = list.map(function (p) {
        return [p.payment_no || '-', p.contract_no || '-', fmtMoney(p.amount) + ' ' + p.currency, p.payment_date, p.payment_type || '-', p.reference || '-'];
      });
      v.appendChild(createPaymentForm());
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Payments (' + list.length + ')' }), table(['No', 'Contract', 'Amount', 'Date', 'Type', 'Reference'], rows)]));
      done(v);
    });
  };

  function createPaymentForm() {
    return get('/api/v1/contracts').then(function (r) {
      var f = el('div', { class: 'card' });
      f.appendChild(el('h3', { text: 'Record payment' }));
      var contracts = (r.body.data.contracts || []).filter(function (c) { return c.status === 'approved' || c.status === 'active'; });
      if (!contracts.length) {
        f.appendChild(el('div', { class: 'empty', text: 'No approved contracts yet' }));
        return f;
      }
      var sel = el('select');
      contracts.forEach(function (c) {
        var o = el('option', { value: c.id, text: c.contract_no });
        sel.appendChild(o);
      });
      var amount = el('input', { type: 'number', placeholder: 'Amount (ETB)' });
      var date = el('input', { type: 'date' });
      var row = el('div', { class: 'form-row' });
      row.appendChild(field('Contract', sel));
      row.appendChild(field('Amount', amount));
      row.appendChild(field('Payment date', date));
      f.appendChild(row);
      f.appendChild(el('button', { class: 'btn', text: 'Record payment', onclick: function () {
        api('POST', '/api/v1/payments', {
          contract_id: parseInt(sel.value, 10),
          amount: parseFloat(amount.value) || null,
          payment_date: date.value || undefined
        }).then(function (rr) {
          if (rr.status === 201) { msg('Payment recorded', 'ok'); views.payments(function (x) { document.getElementById('view').replaceWith(x); }); }
          else msg(rr.body.message || 'Payment failed');
        });
      } }));
      return f;
    });
  }

  views.audit = function (done) {
    get('/api/v1/audit').then(function (r) {
      var v = el('div');
      if (r.status !== 200) { v.appendChild(el('p', { class: 'empty', text: r.body.message || 'Forbidden' })); return done(v); }
      var list = r.body.data.audit_logs || [];
      var rows = list.map(function (e) {
        return [e.created_at, e.action, e.username || '-', e.resource_type || '-', e.resource_id || '-', (e.reason || (e.new_state ? String(e.new_state).slice(0, 60) : '-')) + (e.is_high_risk ? ' <b>HIGH RISK</b>' : '')];
      });
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Audit events (' + list.length + ')' }), table(['At', 'Action', 'User', 'Type', 'Resource', 'Detail'], rows)]));
      done(v);
    });
  };

  views.integrity = function (done) {
    get('/api/v1/system/integrity').then(function (r) {
      var v = el('div');
      var data = r.body.data;
      var card = el('div', { class: 'card' });
      card.appendChild(el('h3', { text: 'Hash-linked integrity chains' }));
      var rows = [];
      if (data && data.chains) {
        Object.keys(data.chains).forEach(function (c) {
          var ch = data.chains[c];
          var btn = el('button', { class: 'btn btn-secondary btn-sm', text: 'C verify', onclick: function () { cVerify(c, card, btn, ch); } });
          rows.push([
            c,
            ch.valid ? '<span class="ok">VALID</span>' : '<span class="err">INVALID</span>',
            ch.entries + ' entries',
            (ch.problems || []).join('<br>'),
            el('div', { class: 'row-actions' }, [
              btn,
              el('button', { class: 'btn btn-secondary btn-sm', onclick: function () { exportChain(c); }, text: 'Export JSON' })
            ]).outerHTML
          ]);
        });
      }
      card.appendChild(table(['Chain', 'Status', 'Entries', 'Problems', ''], rows));
      v.appendChild(card);
      done(v);
    });
  };

  function cVerify(chain, card, btn, current) {
    btn.disabled = true;
    btn.textContent = 'Running\u2026';
    get('/api/v1/system/integrity/' + chain + '/verify-c').then(function (r) {
      btn.disabled = false;
      btn.textContent = 'C verify';
      var d = r.body.data || {};
      var box = el('div', { class: 'card' });
      box.appendChild(el('h3', { text: 'C verifier result — ' + chain }));
      box.appendChild(el('p', { html: 'Verdict: ' + (d.valid ? '<span class="ok">VALID</span>' : '<span class="err">INVALID</span>') + ' (C exit ' + d.c_exit + ')' }));
      box.appendChild(el('pre', { class: 'dump', text: d.output || '(no output)' }));
      card.appendChild(box);
      box.scrollIntoView();
    });
  }

  function exportChain(chain) {
    get('/api/v1/system/integrity/' + chain + '/export').then(function (r) {
      if (r.status !== 200) return msg(r.body.message || 'Export failed');
      var data = r.body.data;
      var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'chain-' + chain + '.json';
      a.click();
      URL.revokeObjectURL(a.href);
      msg('Exported chain "' + chain + '" (' + data.count + ' entries)', 'ok');
    });
  }

  /* ---------------- documents ---------------- */

  views.documents = function (done) {
    get('/api/v1/documents').then(function (r) {
      var v = el('div');
      if (r.status !== 200) { v.appendChild(el('p', { class: 'empty', text: r.body.message || 'Forbidden' })); return done(v); }
      var list = r.body.data.documents || [];
      v.appendChild(createDocumentForm());
      var rows = list.map(function (d) {
        return [
          d.document_no,
          d.document_type,
          d.title,
          badge(d.status),
          'v' + d.current_version,
          d.file_name || '-',
          el('div', { class: 'row-actions' }, [
            el('button', { class: 'btn btn-secondary btn-sm', onclick: function () { documentDetail(d.id); }, text: 'Detail' }),
            d.status === 'active' ? el('button', { class: 'btn btn-danger btn-sm', onclick: function () { revokeDocument(d.id); }, text: 'Revoke' }) : ''
          ]).outerHTML
        ];
      });
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Documents (' + list.length + ')' }), table(['No', 'Type', 'Title', 'Status', 'Ver', 'File', ''], rows)]));
      done(v);
    });
  };

  function createDocumentForm() {
    var f = el('div', { class: 'card' });
    f.appendChild(el('h3', { text: 'Upload document' }));
    var type = el('select');
    ['land_certificate', 'ownership_document', 'survey_document', 'tender_document', 'bid_document', 'evaluation_document', 'contract', 'approval', 'correspondence', 'other'].forEach(function (t) {
      type.appendChild(el('option', { value: t, text: t }));
    });
    var title = el('input', { placeholder: 'Title' });
    var owner = el('input', { placeholder: 'owner_id (optional)' });
    var file = el('input', { type: 'file' });
    var row = el('div', { class: 'form-row' });
    row.appendChild(field('Type', type));
    row.appendChild(field('Title', title));
    row.appendChild(field('Owner ID', owner, 'citizen/organization/parcel/... id'));
    f.appendChild(row);
    f.appendChild(field('File (text, PDF, images)', file, 'Uploaded safely outside the web root (section 36)'));
    f.appendChild(el('button', { class: 'btn', text: 'Upload', onclick: function () {
      var fr = new FileReader();
      fr.onload = function () {
        api('POST', '/api/v1/documents/upload', {
          document_type: type.value, title: title.value,
          owner_type: 'other', owner_id: owner.value ? parseInt(owner.value, 10) : undefined,
          file_name: file.files[0].name, file_contents: (fr.result || '').split(',')[1] || '',
          file_mime: file.files[0].type || 'application/octet-stream'
        }).then(function (r) {
          if (r.status === 201) {
            msg('Document ' + r.body.data.document_no + ' uploaded (token ' + r.body.data.verification_token + ')', 'ok');
            views.documents(function (x) { document.getElementById('view').replaceWith(x); });
          } else msg(r.body.message || 'Upload failed');
        });
      };
      if (file.files[0]) fr.readAsDataURL(file.files[0]);
      else msg('Choose a file first');
    } }));
    return f;
  }

  function documentDetail(id) {
    get('/api/v1/documents/' + id).then(function (r) {
      var d = r.body.data;
      var v = document.getElementById('view');
      var card = el('div', { class: 'card' });
      card.appendChild(el('h3', { text: d.document_no + ' — ' + d.title }));
      card.appendChild(table(['Field', 'Value'], [
        ['Type', d.document_type], ['Status', badge(d.status)], ['Version', 'v' + d.current_version],
        ['Content hash', '<code>' + (d.content_hash || '-') + '</code>'],
        ['Verification token', '<code>' + (d.verification_token || '-') + '</code>'],
        ['Created', fmtDate(d.created_at)]
      ]));
      if (d.verification_token) {
        card.appendChild(el('p', { class: 'form-note', html: 'Public verification link: <a href="/verify.html?token=' + d.verification_token + '" target="_blank">/verify.html?token=' + d.verification_token + '</a>' }));
      }
      if (d.versions && d.versions.length) {
        card.appendChild(el('h3', { text: 'Versions' }));
        card.appendChild(table(['Ver', 'File', 'MIME', 'Size', 'Hash', 'Signature', 'At'], d.versions.map(function (x) {
          return ['v' + x.version, x.file_name, x.mime_type, x.file_size + ' B', '<code>' + String(x.content_hash).slice(0, 12) + '\u2026</code>', x.signature ? '<code>' + String(x.signature).slice(0, 28) + '\u2026</code>' : '-', fmtDate(x.created_at)];
        })));
      }
      card.appendChild(el('div', { class: 'btn-row' }, [
        el('button', { class: 'btn btn-secondary', onclick: function () { views.documents(function (x) { v.replaceWith(x); }); }, text: '\u2190 Back' }),
        d.status === 'active' ? el('button', { class: 'btn', onclick: function () { signDocument(d.id); }, text: 'Sign' }) : '',
        d.status === 'active' ? el('button', { class: 'btn btn-danger', onclick: function () { revokeDocument(d.id); }, text: 'Revoke' }) : ''
      ]));
      v.replaceWith(card);
    });
  }

  function signDocument(id) {
    api('POST', '/api/v1/documents/' + id + '/sign', {}).then(function (r) {
      if (r.status === 200) { msg('Document signed: ' + String(r.body.data.signature).slice(0, 40) + '\u2026', 'ok'); documentDetail(id); }
      else msg(r.body.message || 'Sign failed');
    });
  }

  function revokeDocument(id) {
    var reason = window.prompt('Reason for revoking this document:', '');
    if (reason === null) return;
    api('POST', '/api/v1/documents/' + id + '/revoke', { reason: reason || 'Revoked' }).then(function (r) {
      if (r.status === 200) { msg('Document revoked', 'ok'); views.documents(function (x) { document.getElementById('view').replaceWith(x); }); }
      else msg(r.body.message || 'Revoke failed');
    });
  }

  /* ---------------- organizations ---------------- */

  views.organizations = function (done) {
    get('/api/v1/organizations').then(function (r) {
      var v = el('div');
      if (r.status !== 200) { v.appendChild(el('p', { class: 'empty', text: r.body.message || 'Forbidden' })); return done(v); }
      var list = r.body.data.organizations || [];
      v.appendChild(createOrganizationForm());
      var rows = list.map(function (o) {
        return [
          o.name,
          o.tin_number || '-',
          o.org_type,
          o.contact_person || '-',
          o.phone || '-',
          badge(o.status),
          el('div', { class: 'row-actions' }, [
            el('select', { 'data-org': o.id, class: 'status-pick' }, [
              el('option', { value: 'active', text: 'active' }),
              el('option', { value: 'inactive', text: 'inactive' }),
              el('option', { value: 'blacklisted', text: 'blacklisted' })
            ])
          ]).outerHTML
        ];
      });
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Organizations (' + list.length + ')' }), table(['Name', 'TIN', 'Type', 'Contact', 'Phone', 'Status', 'Set status'], rows)]));
      done(v);
      setTimeout(function () {
        document.querySelectorAll('#view .status-pick').forEach(function (sel) {
          var cur = sel.closest('tr');
          sel.value = (cur.cells[5] || {}).textContent ? 'active' : 'active';
          sel.addEventListener('change', function () {
            api('POST', '/api/v1/organizations/' + sel.dataset.org + '/status', { status: sel.value }).then(function (r) {
              if (r.status === 200) msg('Organization status updated', 'ok');
              else msg(r.body.message || 'Update failed');
            });
          });
        });
      }, 0);
    });
  };

  function createOrganizationForm() {
    var f = el('div', { class: 'card' });
    f.appendChild(el('h3', { text: 'Register organization' }));
    var name = el('input', { placeholder: 'Name' });
    var tin = el('input', { placeholder: 'TIN number' });
    var type = el('select');
    ['government', 'private', 'ngo', 'supplier'].forEach(function (t) { type.appendChild(el('option', { value: t, text: t })); });
    var contact = el('input', { placeholder: 'Contact person' });
    var email = el('input', { type: 'email', placeholder: 'Email' });
    var row = el('div', { class: 'form-row' });
    row.appendChild(field('Name', name));
    row.appendChild(field('TIN', tin));
    row.appendChild(field('Type', type));
    row.appendChild(field('Contact', contact));
    row.appendChild(field('Email', email));
    f.appendChild(row);
    f.appendChild(el('button', { class: 'btn', text: 'Register', onclick: function () {
      api('POST', '/api/v1/organizations', { name: name.value, tin_number: tin.value || undefined, org_type: type.value, contact_person: contact.value || undefined, email: email.value || undefined }).then(function (r) {
        if (r.status === 201) { msg('Organization registered', 'ok'); views.organizations(function (x) { document.getElementById('view').replaceWith(x); }); }
        else msg(r.body.message || (r.body.errors ? r.body.errors.join(', ') : 'Failed'));
      });
    } }));
    return f;
  }

  /* ---------------- institution integration ---------------- */

  views.integrations = function (done) {
    Promise.all([get('/api/v1/integrations/keys'), get('/api/v1/integrations/logs')]).then(function (rs) {
      var v = el('div');
      var keys = rs[0].body.data.keys || [];
      var logs = rs[1].body.data.logs || [];
      createIntegrationKeyForm().then(function (f) { v.appendChild(f); });
      var keyRows = keys.map(function (k) {
        return [
          k.label,
          k.organization_name,
          '<code>' + k.api_key + '</code>',
          badge(k.status),
          String(k.permissions || '').replace(/[\[\]"]/g, ' '),
          k.rate_limit_per_minute + '/min',
          k.status === 'active' ? el('button', { class: 'btn btn-danger btn-sm', onclick: function () { revokeKey(k.id); }, text: 'Revoke' }).outerHTML : '-'
        ];
      });
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Institution API keys (' + keys.length + ')' }), table(['Label', 'Institution', 'API key', 'Status', 'Permissions', 'Limit', ''], keyRows)]));
      var logRows = logs.map(function (l) {
        return [l.created_at, l.organization_name, l.method, l.endpoint, badge(l.response_status), l.status_code, l.payload_hash ? '<code>' + l.payload_hash.slice(0, 12) + '\u2026</code>' : '-'];
      });
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Integration log (' + logs.length + ')' }), table(['At', 'Institution', 'Method', 'Endpoint', 'Status', 'Code', 'Payload hash'], logRows)]));
      v.appendChild(hmacCTestForm());
      done(v);
    }).catch(function (e) { msg('Integrations load failed: ' + e.message); done(el('div')); });
  };

  function createIntegrationKeyForm() {
    return get('/api/v1/organizations').then(function (r) {
      var f = el('div', { class: 'card' });
      f.appendChild(el('h3', { text: 'Issue institution API key' }));
      var orgs = (r.body.data.organizations || []).filter(function (o) { return o.org_type === 'government'; });
      if (!orgs.length) { f.appendChild(el('div', { class: 'empty', text: 'No government organizations registered' })); return f; }
      var sel = el('select');
      orgs.forEach(function (o) { sel.appendChild(el('option', { value: o.id, text: o.name })); });
      var label = el('input', { placeholder: 'Label (e.g. Court verification node)' });
      var perms = el('input', { placeholder: 'Permissions, comma separated (parcels.verify, applications.verify, documents.verify, payments.confirm)' });
      var row = el('div', { class: 'form-row' });
      row.appendChild(field('Institution', sel));
      row.appendChild(field('Label', label));
      f.appendChild(row);
      f.appendChild(field('Permissions', perms));
      f.appendChild(el('button', { class: 'btn', text: 'Create key', onclick: function () {
        api('POST', '/api/v1/integrations/keys', {
          organization_id: parseInt(sel.value, 10), label: label.value,
          permissions: perms.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean)
        }).then(function (rr) {
          if (rr.status === 201) { msg('Key created: ' + rr.body.data.api_key, 'ok'); views.integrations(function (x) { document.getElementById('view').replaceWith(x); }); }
          else msg(rr.body.message || 'Key creation failed');
        });
      } }));
      return f;
    });
  }

  function revokeKey(id) {
    if (!window.confirm('Revoke this integration key? The institution loses access immediately.')) return;
    api('POST', '/api/v1/integrations/keys/' + id + '/revoke', {}).then(function (r) {
      if (r.status === 200) { msg('Key revoked', 'ok'); views.integrations(function (x) { document.getElementById('view').replaceWith(x); }); }
      else msg(r.body.message || 'Revoke failed');
    });
  }

  function hmacCTestForm() {
    var f = el('div', { class: 'card' });
    f.appendChild(el('h3', { text: 'C HMAC verification (independent check)' }));
    var data = el('input', { placeholder: 'data to sign' });
    var key = el('input', { placeholder: 'key' });
    var sig = el('input', { placeholder: 'signature (64 hex)' });
    var row = el('div', { class: 'form-row' });
    row.appendChild(field('Data', data));
    row.appendChild(field('Key', key));
    row.appendChild(field('Signature', sig));
    f.appendChild(row);
    var out = el('pre', { class: 'dump' });
    f.appendChild(out);
    f.appendChild(el('button', { class: 'btn', text: 'Verify with C', onclick: function () {
      get('/api/v1/system/security/hmac-c?data=' + encodeURIComponent(data.value) + '&key=' + encodeURIComponent(key.value) + '&signature=' + encodeURIComponent(sig.value)).then(function (r) {
        var d = r.body.data || {};
        out.textContent = d.output || JSON.stringify(d);
        out.className = 'dump ' + (d.ok === true ? 'ok' : 'err');
      });
    } }));
    return f;
  }

  /* ---------------- chat (active, polling) ---------------- */

  var chatState = { conversationId: null, lastMessageId: 0, timer: null };
  var chatUnreadTotal = 0;

  views.chat = function (done) {
    if (chatState.timer) { clearInterval(chatState.timer); chatState.timer = null; }
    get('/api/v1/chat/conversations').then(function (r) {
      var v = el('div');
      if (r.status !== 200) { v.appendChild(el('p', { class: 'empty', text: r.body.message || 'Forbidden' })); return done(v); }
      var list = r.body.data.conversations || [];
      chatUnreadTotal = list.reduce(function (s, c) { return s + (Number(c.unread) || 0); }, 0);
      updateChatBadge();
      createConversationForm().then(function (f) { v.insertBefore(f, v.firstChild); });
      var rows = list.map(function (c) {
        return [
          c.conversation_no,
          c.title || '(no title)',
          (c.last_sender || '-') + ': ' + (c.last_message || ''),
          fmtDate(c.last_at),
          Number(c.unread) > 0 ? '<span class="badge b-published">' + c.unread + ' new</span>' : '-',
          el('button', { class: 'btn btn-secondary btn-sm', onclick: function () { openConversation(c.id); }, text: 'Open' }).outerHTML
        ];
      });
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Conversations (' + list.length + ')' }), table(['No', 'Title', 'Last message', 'At', 'Unread', ''], rows)]));
      v.appendChild(el('div', { id: 'chatPane' }));
      done(v);
    });
  };

  function createConversationForm() {
    return get('/api/v1/users').then(function (r) {
      var f = el('div', { class: 'card' });
      f.appendChild(el('h3', { text: 'Start conversation' }));
      var users = (r.body.data.users || []).filter(function (u) { return u.id !== ((me && me.id) || 0); });
      var sel = el('select', { multiple: true, size: 4 });
      users.forEach(function (u) { sel.appendChild(el('option', { value: u.id, text: u.full_name || u.username })); });
      var title = el('input', { placeholder: 'Title (optional)' });
      var row = el('div', { class: 'form-row' });
      row.appendChild(field('Participants (hold Ctrl to select)', sel));
      row.appendChild(field('Title', title));
      f.appendChild(row);
      f.appendChild(chatComposer());
      f.appendChild(el('button', { class: 'btn', text: 'Start conversation', onclick: function () {
        var ids = Array.prototype.map.call(sel.selectedOptions, function (o) { return parseInt(o.value, 10); });
        if (!ids.length) return msg('Select at least one participant');
        api('POST', '/api/v1/chat/conversations', { title: title.value || undefined, participant_ids: ids }).then(function (rr) {
          if (rr.status === 201) { msg('Conversation ' + rr.body.data.conversation_no + ' started', 'ok'); views.chat(function (x) { document.getElementById('view').replaceWith(x); }); }
          else msg(rr.body.message || 'Cannot start conversation');
        });
      } }));
      return f;
    });
  }

  function openConversation(id) {
    if (chatState.timer) { clearInterval(chatState.timer); chatState.timer = null; }
    chatState.conversationId = id;
    chatState.lastMessageId = 0;
    var pane = document.getElementById('chatPane');
    pane.innerHTML = '';
    var box = el('div', { class: 'card chat-box' });
    box.appendChild(el('h3', { text: 'Conversation #' + id }));
    pane.appendChild(box);
    pane.appendChild(chatComposer());
    pollMessages(id, box);
    chatState.timer = setInterval(function () { pollMessages(id, box); }, 3000);
  }

  function pollMessages(id, box) {
    api('POST', '/api/v1/chat/conversations/' + id + '/read', {}).then(function () {
      chatUnreadTotal = 0;
      updateChatBadge();
    }).catch(function () {});
    get('/api/v1/chat/conversations/' + id + '/messages?after=' + chatState.lastMessageId).then(function (r) {
      if (r.status !== 200 || !box.isConnected) return;
      var msgs = r.body.data.messages || [];
      msgs.forEach(function (m) {
        if (m.id > chatState.lastMessageId) chatState.lastMessageId = m.id;
        var mine = m.sender_id === ((me && me.id) || -1);
        var bubble = el('div', { class: 'chat-bubble ' + (mine ? 'mine' : 'theirs') });
        bubble.appendChild(el('div', { class: 'chat-meta', text: (mine ? 'You' : (m.sender_name || m.username)) + ' \u00b7 ' + m.created_at }));
        bubble.appendChild(el('div', { class: 'chat-body', text: m.body }));
        box.appendChild(bubble);
      });
      box.scrollTop = box.scrollHeight;
    }).catch(function () {});
  }

  function chatComposer() {
    var f = el('div', { class: 'card' });
    f.appendChild(el('h3', { text: 'Send message' }));
    var input = el('textarea', { rows: 3, placeholder: 'Type a message\u2026' });
    f.appendChild(input);
    f.appendChild(el('button', { class: 'btn', text: 'Send', onclick: function () {
      if (!chatState.conversationId) return msg('Open a conversation first');
      api('POST', '/api/v1/chat/conversations/' + chatState.conversationId + '/messages', { body: input.value }).then(function (r) {
        if (r.status === 201) {
          input.value = '';
          var b = document.querySelector('.chat-box');
          if (b) pollMessages(chatState.conversationId, b);
        } else msg(r.body.message || 'Cannot send');
      });
    } }));
    return f;
  }

  function updateChatBadge() {
    var badge = document.getElementById('chatBadge');
    if (!badge) return;
    badge.textContent = chatUnreadTotal > 0 ? String(chatUnreadTotal) : '';
    badge.className = 'chip' + (chatUnreadTotal > 0 ? '' : ' hidden');
  }

  function pollUnread() {
    get('/api/v1/chat/conversations').then(function (r) {
      if (r.status !== 200) return;
      chatUnreadTotal = (r.body.data.conversations || []).reduce(function (s, c) { return s + (Number(c.unread) || 0); }, 0);
      updateChatBadge();
      var name = (window.location.hash || '#dashboard').slice(1);
      if (name !== 'chat') {
        document.title = chatUnreadTotal > 0 ? '(' + chatUnreadTotal + ') TerraChain' : 'TerraChain';
      }
    }).catch(function () {});
  }

/* ---------------- admin ---------------- */

  views.admin = function (done) {
    Promise.all([get('/api/v1/users'), get('/api/v1/roles'), get('/api/v1/admin-units/tree'), get('/api/v1/system/settings')]).then(function (rs) {
      var v = el('div');
      var users = rs[0].body.data.users || [];
      var roles = rs[1].body.data.roles || [];
      var tree = rs[2].body.data.tree || rs[2].body.data;
      var settings = rs[3].body.data.settings || {};
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Users (' + users.length + ')' }), table(['Username', 'Name', 'Role', 'Admin unit', 'Active', ''], users.map(function (u) {
        return [u.username, u.full_name || '-', u.role_name || u.role || '-', u.admin_unit_name || u.admin_unit_id || '-', u.is_active ? '<span class="ok">yes</span>' : '<span class="err">no</span>',
          (u.is_active && u.id !== (me && me.id)) ? el('button', { class: 'btn btn-danger btn-sm', onclick: function () { deactivateUser(u.id); }, text: 'Deactivate' }).outerHTML : ''];
      }))]));
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Roles (' + roles.length + ')' }), table(['Role', 'Description', 'Users', ''], roles.map(function (r) {
        return [r.name, r.description || '-', r.user_count || 0, r.is_system ? '' : el('button', { class: 'btn btn-danger btn-sm', onclick: function () { deleteRole(r.id); }, text: 'Delete' }).outerHTML];
      })), createRoleForm()]));
      v.appendChild(createUnitForm());
      v.appendChild(el('div', { class: 'card' }, [el('h3', { text: 'Administrative unit hierarchy' }), el('pre', { class: 'dump', text: JSON.stringify(tree, null, 2) })]));
      v.appendChild(settingsForm(settings));
      done(v);
    }).catch(function (e) { msg('Admin load failed: ' + e.message); done(el('div')); });
  };

  function deactivateUser(id) {
    if (!window.confirm('Deactivate this user? They will no longer be able to log in.')) return;
    api('POST', '/api/v1/users/' + id + '/deactivate', {}).then(function (r) {
      if (r.status === 200) { msg('User deactivated', 'ok'); views.admin(function (x) { document.getElementById('view').replaceWith(x); }); }
      else msg(r.body.message || 'Deactivate failed');
    });
  }

  function createRoleForm() {
    var f = el('div', { class: 'form-row' });
    var name = el('input', { placeholder: 'New role name' });
    var desc = el('input', { placeholder: 'Description' });
    f.appendChild(field('Name', name));
    f.appendChild(field('Description', desc));
    f.appendChild(el('button', { class: 'btn', text: 'Create role', onclick: function () {
      api('POST', '/api/v1/roles', { name: name.value, description: desc.value || undefined }).then(function (r) {
        if (r.status === 201) { msg('Role ' + r.body.data.id + ' created', 'ok'); views.admin(function (x) { document.getElementById('view').replaceWith(x); }); }
        else msg(r.body.message || 'Create failed');
      });
    } }));
    return f;
  }

  function deleteRole(id) {
    if (!window.confirm('Delete this role?')) return;
    api('DELETE', '/api/v1/roles/' + id).then(function (r) {
      if (r.status === 200) { msg('Role deleted', 'ok'); views.admin(function (x) { document.getElementById('view').replaceWith(x); }); }
      else msg(r.body.message || 'Delete failed', '', true);
    });
  }

  function createUnitForm() {
    return get('/api/v1/admin-units?type=woreda').then(function (r) {
      var f = el('div', { class: 'card' });
      f.appendChild(el('h3', { text: 'Create administrative unit' }));
      var type = el('select');
      ['country', 'region', 'zone', 'woreda', 'kebele'].forEach(function (t) { type.appendChild(el('option', { value: t, text: t })); });
      var name = el('input', { placeholder: 'Name (EN)' });
      var code = el('input', { placeholder: 'Code' });
      var parent = el('input', { placeholder: 'parent_id (optional)' });
      var row = el('div', { class: 'form-row' });
      row.appendChild(field('Type', type));
      row.appendChild(field('Name', name));
      row.appendChild(field('Code', code));
      row.appendChild(field('Parent ID', parent));
      f.appendChild(row);
      f.appendChild(el('button', { class: 'btn', text: 'Create unit', onclick: function () {
        api('POST', '/api/v1/admin-units', { unit_type: type.value, name_en: name.value, code: code.value, parent_id: parent.value ? parseInt(parent.value, 10) : undefined }).then(function (rr) {
          if (rr.status === 201) { msg('Unit ' + rr.body.data.id + ' created', 'ok'); views.admin(function (x) { document.getElementById('view').replaceWith(x); }); }
          else msg(rr.body.message || 'Create failed');
        });
      } }));
      return f;
    });
  }

  function settingsForm(settings) {
    var f = el('div', { class: 'card' });
    f.appendChild(el('h3', { text: 'System settings (section resources)' }));
    var keys = [
      ['system.name', 'System name'], ['system.region', 'Region'], ['org.display_name', 'Display name'],
      ['security.password_min_length', 'Min password length'], ['security.max_login_attempts', 'Max login attempts'],
      ['security.lockout_minutes', 'Lockout minutes'], ['session.timeout_minutes', 'Session timeout'],
      ['language.default', 'Default language'], ['calendar.primary', 'Primary calendar']
    ];
    var row = el('div', { class: 'form-row' });
    var inputs = {};
    keys.forEach(function (k) {
      var inp = el('input', { value: settings[k[0]] || '', placeholder: k[1] });
      inputs[k[0]] = inp;
      row.appendChild(field(k[1], inp));
    });
    f.appendChild(row);
    f.appendChild(el('button', { class: 'btn', text: 'Save settings', onclick: function () {
      var map = {};
      Object.keys(inputs).forEach(function (k) { map[k] = inputs[k].value; });
      api('PUT', '/api/v1/system/settings', { settings: map }).then(function (r) {
        if (r.status === 200) msg('Settings saved', 'ok');
        else msg(r.body.message || 'Save failed');
      });
    } }));
    return f;
  }

  /* ---------------- router ---------------- */

  var NAV = {
    dashboard: ['Dashboard', 'Current state of the federation platform'],
    parcels: ['Land parcels', 'Cadastral registry with versioned records'],
    applications: ['Land applications', '10-step workflow across admin hierarchy'],
    tenders: ['Tenders', 'Public procurement tenders'],
    bids: ['Bids', 'Sealed bidding — prices revealed at opening'],
    contracts: ['Contracts', 'Award contracts and approvals'],
    payments: ['Payments', 'Contract payment register'],
    documents: ['Documents', 'Digital documents — upload, sign, revoke, verify'],
    organizations: ['Organizations', 'Institutions, suppliers and their status'],
    integrations: ['Institutions', 'API keys, HMAC machine access and integration log'],
    chat: ['Chat', 'Internal messaging between staff (active)'],
    audit: ['Audit log', 'Every action, attributed'],
    integrity: ['Integrity', 'Hash-linked chains + independent C verification'],
    admin: ['Administration', 'Users, roles, administrative units and settings']
  };

  function navigate() {
    var hash = (window.location.hash || '#dashboard').slice(1);
    var name = NAV[hash] ? hash : 'dashboard';
    var navLinks = document.querySelectorAll('#nav a');
    navLinks.forEach(function (a) {
      a.classList.toggle('active', a.getAttribute('href') === '#' + name);
    });
    var meta = NAV[name];
    document.getElementById('pageTitle').textContent = meta[0];
    document.getElementById('pageSub').textContent = meta[1];
    var view = document.getElementById('view');
    views[name](function (v) { view.replaceWith(v); });
  }

  /* ---------------- boot ---------------- */

  function boot() {
    api('GET', '/api/v1/auth/me').then(function (r) {
      if (r.status !== 200) {
        window.location.href = '/login.html';
        return;
      }
      me = r.body.data || {};
      document.getElementById('whoName').textContent = me.full_name || me.username;
      document.getElementById('whoRole').textContent = me.role || '';
      return api('GET', '/api/v1/auth/csrf').then(function (c) {
        csrf = c.body.data.csrf;
        navigate();
        if (me.permissions && (me.permissions.indexOf('chat.view') !== -1 || me.role === 'system_admin')) {
          pollUnread();
          setInterval(pollUnread, 10000);
        }
      });
    }).catch(function () { window.location.href = '/login.html'; });

    document.getElementById('logoutLink').addEventListener('click', function (e) {
      e.preventDefault();
      api('POST', '/api/v1/auth/logout', {}).then(function () { window.location.href = '/login.html'; });
    });
    window.addEventListener('hashchange', navigate);
  }

  document.addEventListener('DOMContentLoaded', boot);
})();