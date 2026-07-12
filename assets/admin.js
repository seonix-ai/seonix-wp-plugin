(function () {
  'use strict';

  // ── Copy API key to clipboard ──
  // The full key is no longer rendered into the DOM. We fetch it on-demand via
  // an authenticated AJAX call (nonce + manage_options check), copy it to the
  // clipboard, and clear it from memory after a short window.

  var copyBtn = document.getElementById('seonix-copy-key-btn');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var nonce = copyBtn.getAttribute('data-nonce');
      if (!nonce) return;

      copyBtn.disabled = true;
      var originalText = copyBtn.textContent;
      copyBtn.textContent = 'Loading…';

      var body = new URLSearchParams();
      body.append('action', 'seonix_get_api_key');
      body.append('nonce', nonce);

      fetch(seonixConnector.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.success) {
            var msg = res.data && res.data.message ? res.data.message : 'Failed to load API key.';
            showNotice('error', msg);
            copyBtn.disabled = false;
            copyBtn.textContent = originalText;
            return;
          }
          var key = res.data.key;
          if (!key) {
            showNotice('error', 'API key is empty.');
            copyBtn.disabled = false;
            copyBtn.textContent = originalText;
            return;
          }

          var done = function () {
            copyBtn.disabled = false;
            showCopied(copyBtn);
            // Best-effort wipe of local references so the key does not linger
            // as a closed-over string longer than necessary.
            setTimeout(function () { key = ''; }, 30000);
          };

          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(key).then(done).catch(function () {
              fallbackCopy(key, copyBtn);
              setTimeout(function () { key = ''; }, 30000);
            });
          } else {
            fallbackCopy(key, copyBtn);
            setTimeout(function () { key = ''; }, 30000);
          }
        })
        .catch(function () {
          showNotice('error', 'Network error.');
          copyBtn.disabled = false;
          copyBtn.textContent = originalText;
        });
    });
  }

  function fallbackCopy(text, btn) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    try {
      document.execCommand('copy');
      showCopied(btn);
    } catch (e) {
      showNotice('error', 'Failed to copy. Please select and copy manually.');
    }
    document.body.removeChild(textarea);
  }

  function showCopied(btn) {
    var original = btn.textContent;
    btn.textContent = 'Copied!';
    btn.classList.add('seonix-btn--copied');
    setTimeout(function () {
      btn.textContent = original;
      btn.classList.remove('seonix-btn--copied');
    }, 2000);
  }

  // ── Regenerate Key ──

  var regenBtn = document.getElementById('seonix-regenerate-key-btn');
  if (regenBtn) {
    regenBtn.addEventListener('click', function () {
      if (!confirm('Are you sure? This will invalidate the current API key. You will need to update it in Seonix.')) {
        return;
      }

      regenBtn.disabled = true;
      regenBtn.innerHTML = '<span class="ce-spinner ce-spinner--dark"></span> Regenerating\u2026';

      var body = new URLSearchParams();
      body.append('action', 'seonix_regenerate_key');
      body.append('_wpnonce', seonixConnector.nonce);

      fetch(seonixConnector.ajaxUrl, { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) {
            // Update the masked preview only — the full key never lives in the DOM.
            // The Copy button re-fetches the key via the seonix_get_api_key AJAX action.
            var keyPreview = document.getElementById('seonix-key-preview');
            if (keyPreview) keyPreview.textContent = res.data.key_preview;
            showNotice('success', 'API key regenerated. Update it in Seonix.');
          } else {
            var msg = res.data && res.data.message ? res.data.message : 'Failed to regenerate key.';
            showNotice('error', msg);
          }
          regenBtn.disabled = false;
          regenBtn.textContent = 'Regenerate Key';
        })
        .catch(function () {
          showNotice('error', 'Network error.');
          regenBtn.disabled = false;
          regenBtn.textContent = 'Regenerate Key';
        });
    });
  }

  // ── Save Author ──

  var saveAuthorBtn = document.getElementById('seonix-save-author-btn');
  if (saveAuthorBtn) {
    saveAuthorBtn.addEventListener('click', function () {
      var select = document.getElementById('seonix-author-select');
      if (!select) return;

      saveAuthorBtn.disabled = true;
      saveAuthorBtn.innerHTML = '<span class="ce-spinner"></span> Saving\u2026';

      var body = new URLSearchParams();
      body.append('action', 'seonix_save_author');
      body.append('_wpnonce', seonixConnector.nonce);
      body.append('author_id', select.value);

      fetch(seonixConnector.ajaxUrl, { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) {
            showNotice('success', 'Post author saved.');
          } else {
            var msg = res.data && res.data.message ? res.data.message : 'Failed to save author.';
            showNotice('error', msg);
          }
          saveAuthorBtn.disabled = false;
          saveAuthorBtn.textContent = 'Save';
        })
        .catch(function () {
          showNotice('error', 'Network error.');
          saveAuthorBtn.disabled = false;
          saveAuthorBtn.textContent = 'Save';
        });
    });
  }

  // ── Save Schema Mode ──

  var saveSchemaModeBtn = document.getElementById('seonix-save-schema-mode-btn');
  if (saveSchemaModeBtn) {
    saveSchemaModeBtn.addEventListener('click', function () {
      var select = document.getElementById('seonix-schema-mode-select');
      if (!select) return;

      saveSchemaModeBtn.disabled = true;
      saveSchemaModeBtn.innerHTML = '<span class="ce-spinner"></span> Saving…';

      var body = new URLSearchParams();
      body.append('action', 'seonix_save_schema_mode');
      body.append('_wpnonce', seonixConnector.nonce);
      body.append('mode', select.value);

      fetch(seonixConnector.ajaxUrl, { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) {
            showNotice('success', 'Structured data setting saved.');
          } else {
            var msg = res.data && res.data.message ? res.data.message : 'Failed to save setting.';
            showNotice('error', msg);
          }
          saveSchemaModeBtn.disabled = false;
          saveSchemaModeBtn.textContent = 'Save';
        })
        .catch(function () {
          showNotice('error', 'Network error.');
          saveSchemaModeBtn.disabled = false;
          saveSchemaModeBtn.textContent = 'Save';
        });
    });
  }

  // ── Sync Now ──

  var syncBtn = document.getElementById('seonix-sync-btn');
  if (syncBtn) {
    syncBtn.addEventListener('click', function () {
      syncBtn.disabled = true;
      syncBtn.innerHTML = '<span class="ce-spinner ce-spinner--dark"></span> Syncing\u2026';

      var body = new URLSearchParams();
      body.append('action', 'seonix_sync_now');
      body.append('_wpnonce', seonixConnector.nonce);

      fetch(seonixConnector.ajaxUrl, { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) {
            showNotice('success', 'Site data synced.');
            if (res.data.counts) {
              var c = res.data.counts;
              setText('seonix-sync-pages', c.pages || 0);
              setText('seonix-sync-posts', c.posts || 0);
              setText('seonix-sync-products', c.products || 0);
            }
            if (res.data.last_synced_at) {
              setText('seonix-last-synced', 'Last synced ' + new Date(res.data.last_synced_at).toLocaleString());
            }
          } else {
            showNotice('error', res.data && res.data.message ? res.data.message : 'Sync failed.');
          }
          syncBtn.disabled = false;
          syncBtn.textContent = 'Sync Now';
        })
        .catch(function () {
          showNotice('error', 'Network error.');
          syncBtn.disabled = false;
          syncBtn.textContent = 'Sync Now';
        });
    });
  }

  // ── IndexNow auto-submit toggle (standalone feature card) ──
  // Saves on change (no separate button); the status pill next to the card
  // title flips together with the checkbox. On failure the checkbox reverts so
  // the UI never lies about the stored setting.

  var indexnowToggle = document.getElementById('seonix-indexnow-auto');
  if (indexnowToggle) {
    indexnowToggle.addEventListener('change', function () {
      var enabled = indexnowToggle.checked;
      indexnowToggle.disabled = true;

      var body = new URLSearchParams();
      body.append('action', 'seonix_save_indexnow');
      body.append('_wpnonce', seonixConnector.nonce);
      body.append('enabled', enabled ? '1' : '0');

      fetch(seonixConnector.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) {
            showNotice('success', i18n('indexnowSaved', 'IndexNow setting saved.'));
            var pill = document.getElementById('seonix-indexnow-status');
            if (pill) {
              pill.classList.toggle('seonix-featstatus--on', enabled);
              var dot = pill.querySelector('.seonix-status__dot');
              if (dot) dot.classList.toggle('seonix-status__dot--green', enabled);
              var txt = pill.querySelector('.seonix-featstatus__txt');
              if (txt) txt.textContent = enabled ? i18n('indexnowEnabled', 'Enabled') : i18n('indexnowDisabled', 'Disabled');
            }
          } else {
            indexnowToggle.checked = !enabled;
            var msg = res.data && res.data.message ? res.data.message : i18n('saveFailed', 'Failed to save setting.');
            showNotice('error', msg);
          }
          indexnowToggle.disabled = false;
        })
        .catch(function () {
          indexnowToggle.checked = !enabled;
          indexnowToggle.disabled = false;
          showNotice('error', i18n('networkError', 'Network error.'));
        });
    });
  }

  // ── Refresh tasks (Dashboard) ──
  // Server-side pulls the latest TaskView from the connected Seonix backend
  // and replaces the local copy, then reloads so the freshly-stored rows
  // render. Capability + nonce checked server-side in ajax_refresh_tasks.

  var refreshBtn = document.getElementById('seonix-refresh-tasks-btn');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', function () {
      refreshBtn.disabled = true;
      var originalText = refreshBtn.textContent;
      refreshBtn.innerHTML = '<span class="ce-spinner"></span> ' + escapeHtml(i18n('refreshing', 'Refreshing…'));

      var body = new URLSearchParams();
      body.append('action', 'seonix_refresh_tasks');
      body.append('nonce', seonixConnector.refreshNonce);

      fetch(seonixConnector.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) {
            showNotice('success', i18n('tasksRefreshed', 'Tasks refreshed.'));
            // Reload so the new rows + summary render from the local table.
            setTimeout(function () { window.location.reload(); }, 600);
          } else {
            var msg = res.data && res.data.message ? res.data.message : i18n('refreshFailed', 'Failed to refresh tasks.');
            showNotice('error', msg);
            refreshBtn.disabled = false;
            refreshBtn.textContent = originalText;
          }
        })
        .catch(function () {
          showNotice('error', i18n('networkError', 'Network error.'));
          refreshBtn.disabled = false;
          refreshBtn.textContent = originalText;
        });
    });
  }

  // ── Connect / Reconnect (Dashboard) ──
  // The one-time connect nonce is no longer baked into the page. On click we
  // mint a fresh connect URL just-in-time via an authenticated AJAX call
  // (capability + nonce checked server-side in ajax_connect_url), then redirect
  // the browser to it to hand off to app.seonix.ai.

  var connectBtn = document.getElementById('seonix-connect-btn');
  var reconnectBtn = document.getElementById('seonix-reconnect-btn');
  [connectBtn, reconnectBtn].forEach(function (btn) {
    if (!btn) return;
    btn.addEventListener('click', function () {
      btn.disabled = true;
      var originalText = btn.textContent;
      btn.textContent = i18n('connecting', 'Connecting…');

      var body = new URLSearchParams();
      body.append('action', 'seonix_connect_url');
      body.append('nonce', seonixConnector.connectNonce);

      fetch(seonixConnector.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success && res.data && res.data.url) {
            window.location.href = res.data.url;
          } else {
            var msg = res.data && res.data.message ? res.data.message : i18n('connectFailed', 'Could not start the connection. Please try again.');
            showNotice('error', msg);
            btn.disabled = false;
            btn.textContent = originalText;
          }
        })
        .catch(function () {
          showNotice('error', i18n('networkError', 'Network error.'));
          btn.disabled = false;
          btn.textContent = originalText;
        });
    });
  });

  // ── Task list: By issue / By page view toggle ──
  // Clicking a toggle button sets is-active on it (clearing its siblings) and
  // shows the matching .seonix-view[data-view=…] while hiding the other. No
  // reload — both views are rendered server-side. Default = issues (the markup
  // ships with the issues button pre-active and the pages view pre-hidden).
  var viewToggleBtns = document.querySelectorAll('.seonix-viewtoggle__btn');
  var views = document.querySelectorAll('.seonix-view');
  if (viewToggleBtns.length && views.length) {
    var applyView = function (view) {
      views.forEach(function (el) {
        el.hidden = el.getAttribute('data-view') !== view;
      });
    };
    viewToggleBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        viewToggleBtns.forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        applyView(btn.getAttribute('data-view') || 'issues');
      });
    });
  }

  // ── Task list: lifecycle tabs + category filter ──
  // Two orthogonal filters AND together to decide a By-issue row's visibility:
  //   1. Lifecycle tab (data-status). Mirrors the web app's IssueTaskListPanel:
  //        active    → status open OR regressed
  //        solved    → status solved   (the "Fixed" tab)
  //        regressed → status regressed (the "Came back" tab)
  //        all       → everything
  //   2. Category filter (data-category) — driven by the Site Health bars
  //      (SEO / Technical / AI Search). 'all' = no category filter.
  // No reload: every row is in the DOM already (rendered server-side); we just
  // toggle visibility. The category filter affects ONLY the By-issue view; the
  // By-page view is left alone.
  var lifeTabs = document.querySelectorAll('.seonix-lifetab');
  var taskTable = document.querySelector('.seonix-tasktable');
  var categoryBars = document.querySelectorAll('.seonix-bar[data-category]');
  var catFilterBar = document.querySelector('.seonix-catfilter-bar');

  if (lifeTabs.length && taskTable) {
    var taskItems = taskTable.querySelectorAll('.seonix-task-item');
    var tableEmpty = taskTable.querySelector('.seonix-tasktable__empty');

    // Current filter state. activeTab starts from the table's default; the
    // category starts unfiltered.
    var activeTab = taskTable.getAttribute('data-default-tab') || 'active';
    var activeCategory = 'all';

    var rowMatchesTab = function (status, tab) {
      if (tab === 'all') return true;
      if (tab === 'active') return status === 'open' || status === 'regressed';
      if (tab === 'solved') return status === 'solved';
      if (tab === 'regressed') return status === 'regressed';
      return true;
    };

    // Recompute every row's visibility from the combined (tab AND category)
    // filter, then toggle the per-tab empty state on the resulting count.
    var applyFilters = function () {
      var visible = 0;
      taskItems.forEach(function (item) {
        var status = item.getAttribute('data-status') || 'open';
        var category = item.getAttribute('data-category') || 'seo';
        var show = rowMatchesTab(status, activeTab) &&
          (activeCategory === 'all' || category === activeCategory);
        item.hidden = !show;
        if (show) visible++;
      });
      if (tableEmpty) tableEmpty.hidden = visible !== 0;
    };

    // Reflect the active lifecycle tab in the pill UI.
    var markActiveTab = function (tab) {
      lifeTabs.forEach(function (b) {
        var on = (b.getAttribute('data-tab') || 'active') === tab;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    };

    // Reflect the active category in the bar UI + the clear chip.
    var markActiveCategory = function (category) {
      categoryBars.forEach(function (bar) {
        var on = category !== 'all' && bar.getAttribute('data-category') === category;
        bar.classList.toggle('is-filtering', on);
        bar.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      if (catFilterBar) {
        if (category === 'all') {
          catFilterBar.hidden = true;
        } else {
          var labelEl = catFilterBar.querySelector('.seonix-catfilter__label');
          if (labelEl) {
            // The localized category labels are stamped on the bar as
            // data-label-<key> so the JS never hard-codes English.
            labelEl.textContent = catFilterBar.getAttribute('data-label-' + category) || category;
          }
          catFilterBar.hidden = false;
        }
      }
    };

    var setTab = function (tab) {
      activeTab = tab;
      markActiveTab(tab);
      applyFilters();
    };

    var setCategory = function (category) {
      activeCategory = category;
      markActiveCategory(category);
      // Picking a category jumps to the All-statuses tab so the owner sees the
      // full picture for that area (active AND already-fixed) — mirrors the web
      // app. Clearing the filter leaves the current tab as-is.
      if (category !== 'all') {
        setTab('all');
      } else {
        applyFilters();
      }
    };

    lifeTabs.forEach(function (btn) {
      btn.addEventListener('click', function () {
        setTab(btn.getAttribute('data-tab') || 'active');
      });
    });

    categoryBars.forEach(function (bar) {
      bar.addEventListener('click', function () {
        var category = bar.getAttribute('data-category') || 'all';
        // Clicking the active category again clears the filter (toggle).
        setCategory(activeCategory === category ? 'all' : category);
      });
    });

    // The clear chip (and its ✕) resets the category filter.
    if (catFilterBar) {
      var clearChip = catFilterBar.querySelector('.seonix-catfilter');
      if (clearChip) {
        clearChip.addEventListener('click', function () {
          setCategory('all');
        });
      }
    }

    // Apply the default tab on load so the initial view matches the active pill.
    markActiveTab(activeTab);
    applyFilters();
  }

  // ── Problem / page detail modal (replaces the old inline accordion) ──
  // Clicking a task row (.seonix-trow) or a page row (.seonix-prow) opens ONE
  // shared modal: the header pill/title/code come from the row's data attrs and
  // the body is a clone of that row's hidden detail block (.seonix-task-detail
  // for tasks, .seonix-page-detail for pages — the server already escaped it).
  // Close via the ×, the footer Close, a backdrop click, or Esc. Mirrors the web
  // app's IssueDetailModal.
  var modal = document.getElementById('seonix-modal');
  if (modal) {
    var modalPill = modal.querySelector('.seonix-modal__pill');
    var modalTitle = modal.querySelector('.seonix-modal__title');
    var modalCode = modal.querySelector('.seonix-modal__code');
    var modalBody = modal.querySelector('.seonix-modal__body');

    // status → { tone class suffix, localized pill label }.
    var pillFor = function (status) {
      if (status === 'open') return { tone: 'open', label: i18n('activeTask', 'Active task') };
      if (status === 'regressed') return { tone: 'regressed', label: i18n('cameBack', 'Came back') };
      if (status === 'solved') return { tone: 'solved', label: i18n('fixed', 'Fixed') };
      return { tone: 'page', label: i18n('pageLabel', 'Page') };
    };

    var openModal = function (opts) {
      // Pill: reset tone classes, set the active one + its text.
      if (modalPill) {
        modalPill.className = 'seonix-modal__pill seonix-modal__pill--' + opts.tone;
        modalPill.textContent = opts.label;
      }
      if (modalTitle) modalTitle.textContent = opts.title || '';
      // Code line: shown for task rows, blank (CSS-hidden) for page rows.
      if (modalCode) modalCode.textContent = opts.code || '';
      // Body: clone the hidden detail block's content (already escaped server-side).
      // The source block carries the `hidden` attribute (so it never shows
      // inline); the clone inherits it, so strip it on the clone — otherwise the
      // modal body would render empty.
      if (modalBody) {
        modalBody.innerHTML = '';
        if (opts.detail) {
          var clone = opts.detail.cloneNode(true);
          clone.removeAttribute('hidden');
          modalBody.appendChild(clone);
        }
        modalBody.scrollTop = 0;
      }
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('seonix-modal-open');
    };

    var closeModal = function () {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('seonix-modal-open');
      if (modalBody) modalBody.innerHTML = '';
    };

    // Task rows → modal (pill from data-status, code from data-code).
    document.querySelectorAll('.seonix-task-item .seonix-trow').forEach(function (rowBtn) {
      rowBtn.addEventListener('click', function () {
        var item = rowBtn.closest('.seonix-task-item');
        if (!item) return;
        var p = pillFor(item.getAttribute('data-status') || 'open');
        openModal({
          tone: p.tone,
          label: p.label,
          title: item.getAttribute('data-title') || '',
          code: item.getAttribute('data-code') || '',
          detail: item.querySelector('.seonix-task-detail'),
        });
      });
    });

    // Page rows → modal (neutral "Page" pill, no code line).
    document.querySelectorAll('.seonix-page-item .seonix-prow').forEach(function (rowBtn) {
      rowBtn.addEventListener('click', function () {
        var item = rowBtn.closest('.seonix-page-item');
        if (!item) return;
        var p = pillFor('page');
        openModal({
          tone: p.tone,
          label: p.label,
          title: item.getAttribute('data-title') || '',
          code: '',
          detail: item.querySelector('.seonix-page-detail'),
        });
      });
    });

    // Close affordances: the ×, the footer Close, and the backdrop all carry
    // data-seonix-modal-close.
    modal.querySelectorAll('[data-seonix-modal-close]').forEach(function (el) {
      el.addEventListener('click', closeModal);
    });

    // Esc closes when the modal is open.
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) {
        closeModal();
      }
    });
  }

  // ── One-click SEO fix (inside the task modal) ──
  // The "Fix it for me" button lives in the hidden .seonix-task-detail block and
  // is CLONED into the modal, so the clone carries no listeners — we delegate the
  // click from document. WordPress proxies the call to the Seonix backend, which
  // gates it on a paid subscription: a 402 closes the detail modal and opens the
  // upgrade popup, mirroring the web app's AI paywall. On success we re-pull the
  // canonical task view from the backend and reload so the list reflects it.
  document.addEventListener('click', function (e) {
    var btn = (e.target && e.target.closest) ? e.target.closest('.seonix-fix-btn') : null;
    if (!btn || btn.disabled) return;
    var code = btn.getAttribute('data-code') || '';
    if (!code || !seonixConnector.fixNonce) return;

    btn.disabled = true;
    var originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="ce-spinner"></span> ' + escapeHtml(i18n('fixing', 'Fixing…'));

    var body = new URLSearchParams();
    body.append('action', 'seonix_seo_fix');
    body.append('nonce', seonixConnector.fixNonce);
    body.append('issue_code', code);

    fetch(seonixConnector.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }); })
      .then(function (res) {
        if (res.json && res.json.success) {
          // HTTP 200 only means the run finished — NOT that anything was fixed.
          // The backend reports the honest outcome in data.status; when every
          // item errored ('failed') or there was nothing to do, don't claim
          // success and keep the button enabled for a retry.
          var d = (res.json && res.json.data) ? res.json.data : {};
          var st = d.status || 'applied';
          if (st === 'failed' || st === 'nothing_to_do') {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            showNotice('error', i18n('fixNothingApplied', 'Nothing could be applied automatically — this one needs a manual look.'));
            return;
          }
          showNotice(
            'success',
            st === 'partial'
              ? i18n('fixPartial', 'Some items were fixed; the rest need attention. It will refine on the next scan.')
              : i18n('fixApplied', 'Fix applied. It will clear on the next scan.')
          );
          // Re-pull the canonical task view from the backend, then reload.
          var rb = new URLSearchParams();
          rb.append('action', 'seonix_refresh_tasks');
          rb.append('nonce', seonixConnector.refreshNonce);
          var reload = function () { setTimeout(function () { window.location.reload(); }, 800); };
          fetch(seonixConnector.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: rb })
            .then(reload).catch(reload);
          return;
        }
        // Restore the button so the action can be retried.
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        if (res.status === 402) {
          // No paid subscription → close this modal, open the upgrade popup.
          var taskModal = document.getElementById('seonix-modal');
          if (taskModal) {
            taskModal.hidden = true;
            taskModal.setAttribute('aria-hidden', 'true');
          }
          var pop = document.getElementById('seonix-paywall-modal');
          if (pop) {
            pop.hidden = false;
            pop.setAttribute('aria-hidden', 'false');
            document.body.classList.add('seonix-modal-open');
          } else {
            showNotice('error', i18n('fixPaywall', 'An active subscription is required to apply fixes.'));
          }
          return;
        }
        var msg = (res.json && res.json.data && res.json.data.message) ? res.json.data.message : i18n('fixFailed', 'Could not apply the fix.');
        showNotice('error', msg);
      })
      .catch(function () {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        showNotice('error', i18n('networkError', 'Network error.'));
      });
  });

  // ── By page view: URL search ──
  // Filtering by URL substring (lowercased). Non-matching page items are hidden;
  // a friendly empty state shows when nothing matches.
  var pageSearch = document.getElementById('seonix-page-search');
  var pageTable = document.querySelector('.seonix-pagetable');
  if (pageSearch && pageTable) {
    var pageItems = pageTable.querySelectorAll('.seonix-page-item');
    var pageEmpty = pageTable.querySelector('.seonix-pagetable__empty');
    pageSearch.addEventListener('input', function () {
      var q = pageSearch.value.toLowerCase();
      var visible = 0;
      pageItems.forEach(function (item) {
        var url = item.getAttribute('data-url') || '';
        var show = q === '' || url.indexOf(q) !== -1;
        item.hidden = !show;
        if (show) visible++;
      });
      if (pageEmpty) pageEmpty.hidden = visible !== 0;
    });
  }

  // ── Plan card + "Open in Seonix" deep links ──
  // Pull the connected project's plan + exact dashboard/billing URLs from the
  // Seonix backend (server-side proxy: the Bearer key never touches the
  // browser). Fills the plan badge + AI-features subline and repoints every
  // "Open in Seonix" / "Upgrade" link at the backend-built URL (correct in dev
  // too). Best-effort: a failure leaves the PHP-side fallback links + a soft
  // error line.
  (function () {
    var planCard = document.getElementById('seonix-plan-card');
    var openApp = document.getElementById('seonix-open-app');
    if ((!planCard && !openApp) || !seonixConnector.accountNonce) return;

    var setHref = function (id, url) {
      if (!url) return;
      var el = document.getElementById(id);
      if (el) el.setAttribute('href', url);
    };
    var planError = function () {
      var sub = document.getElementById('seonix-plan-sub');
      if (sub) sub.textContent = i18n('planError', 'Could not load your plan.');
    };

    var body = new URLSearchParams();
    body.append('action', 'seonix_account');
    body.append('nonce', seonixConnector.accountNonce);
    fetch(seonixConnector.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success || !res.data) { planError(); return; }
        var d = res.data;
        var plan = d.plan || {};
        var isPaid = !!plan.is_paid;
        var name = plan.name || i18n('planFree', 'Free');

        setHref('seonix-open-app', d.dashboard_url);
        setHref('seonix-plan-open', d.dashboard_url);
        setHref('seonix-plan-upgrade', d.billing_url);
        setHref('seonix-paywall-cta', d.billing_url);

        var badge = document.getElementById('seonix-plan-badge');
        if (badge) {
          badge.setAttribute('data-tier', isPaid ? 'paid' : 'free');
          var txt = badge.querySelector('.seonix-planbadge__txt');
          if (txt) txt.textContent = name;
        }
        var sub = document.getElementById('seonix-plan-sub');
        if (sub) {
          sub.textContent = isPaid
            ? i18n('planActiveSub', 'AI features are active — generate, refine and auto-publish from Seonix or right here.')
            : i18n('planFreeSub', 'This project is on the Free plan. Upgrade to unlock AI generation, refinement and one-click SEO fixes.');
        }
        var upgrade = document.getElementById('seonix-plan-upgrade');
        if (upgrade) upgrade.hidden = isPaid;
      })
      .catch(planError);
  })();

  // ── Paid-AI popup — mirrors the web app's AI_PAYWALL modal ──
  // The "What's included?" link opens a small dialog explaining the paid AI
  // features; its CTA jumps to this project's billing page inside Seonix.
  (function () {
    var pop = document.getElementById('seonix-paywall-modal');
    if (!pop) return;
    var trigger = document.getElementById('seonix-aifeat-more');
    var openPop = function () {
      pop.hidden = false;
      pop.setAttribute('aria-hidden', 'false');
      document.body.classList.add('seonix-modal-open');
    };
    var closePop = function () {
      pop.hidden = true;
      pop.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('seonix-modal-open');
    };
    if (trigger) trigger.addEventListener('click', openPop);
    pop.querySelectorAll('[data-seonix-paywall-close]').forEach(function (el) {
      el.addEventListener('click', closePop);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !pop.hidden) closePop();
    });
  })();

  // ── Helpers ──

  // Resolve a localized string from the wp_localize_script i18n bag, falling
  // back to the English literal when the key is missing (e.g. an older cached
  // localization payload).
  function i18n(key, fallback) {
    if (seonixConnector && seonixConnector.i18n && seonixConnector.i18n[key]) {
      return seonixConnector.i18n[key];
    }
    return fallback;
  }

  function setText(id, text) {
    var el = document.getElementById(id);
    if (el) el.textContent = text;
  }

  function showNotice(type, message) {
    var container = document.getElementById('seonix-notices');
    if (!container) return;
    var cssClass = type === 'error' ? 'notice-error' : 'notice-success';
    container.innerHTML = '<div class="notice ' + cssClass + ' is-dismissible"><p>' + escapeHtml(message) + '</p></div>';
    setTimeout(function () { container.innerHTML = ''; }, 6000);
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }
})();
