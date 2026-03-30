'use strict';

const App = {

    // ─── State ─────────────────────────────────────────────────────────────────
    state: {
        rules:       null,
        folders:     [],
        currentView: '',
        dragSrcIdx:  null,
        modalSaveFn: null,
    },

    // ─── Passwort-Stärke ───────────────────────────────────────────────────────
    // Gibt HTML für Anforderungen + Stärke-Indikator zurück
    pwdHtml(inputId) {
        return `
<div class="text-sm text-muted" style="margin-top:6px;line-height:1.8">
  Mindestens: <strong>10 Zeichen</strong>,
  einen <strong>Großbuchstaben</strong>,
  einen <strong>Kleinbuchstaben</strong>,
  eine <strong>Zahl</strong> und
  ein <strong>Sonderzeichen</strong> (!@#$%^&amp;*-_=+?).
</div>
<div style="margin-top:8px">
  <div style="display:flex;align-items:center;gap:10px">
    <div style="flex:1;height:6px;background:var(--border);border-radius:3px;overflow:hidden">
      <div id="pwd-bar-${inputId}" style="height:100%;width:0%;border-radius:3px;transition:width .3s,background .3s"></div>
    </div>
    <span id="pwd-label-${inputId}" class="text-sm text-muted" style="min-width:80px"></span>
  </div>
</div>`;
    },

    pwdCheck(inputId) {
        const val   = document.getElementById(inputId)?.value || '';
        const bar   = document.getElementById('pwd-bar-' + inputId);
        const label = document.getElementById('pwd-label-' + inputId);
        if (!bar || !label) return;

        let score = 0;
        if (val.length >= 10)                    score++;
        if (val.length >= 14)                    score++;
        if (/[A-Z]/.test(val))                   score++;
        if (/[a-z]/.test(val))                   score++;
        if (/[0-9]/.test(val))                   score++;
        if (/[!@#$%^&*\-_=+?]/.test(val))       score++;

        const levels = [
            { pct: '0%',   bg: 'transparent', text: '' },
            { pct: '20%',  bg: '#ef4444',     text: 'Sehr schwach' },
            { pct: '35%',  bg: '#f97316',     text: 'Schwach' },
            { pct: '55%',  bg: '#eab308',     text: 'Mittel' },
            { pct: '75%',  bg: '#3b82f6',     text: 'Stark' },
            { pct: '90%',  bg: '#10b981',     text: 'Sehr stark' },
            { pct: '100%', bg: '#10b981',     text: 'Ausgezeichnet' },
        ];
        const lvl = levels[Math.min(score, levels.length - 1)];
        bar.style.width      = lvl.pct;
        bar.style.background = lvl.bg;
        label.textContent    = lvl.text;
        label.style.color    = lvl.bg;
    },

    pwdValidate(val) {
        const errs = [];
        if (val.length < 10)                       errs.push('mindestens 10 Zeichen');
        if (!/[A-Z]/.test(val))                    errs.push('einen Großbuchstaben');
        if (!/[a-z]/.test(val))                    errs.push('einen Kleinbuchstaben');
        if (!/[0-9]/.test(val))                    errs.push('eine Zahl');
        if (!/[!@#$%^&*\-_=+?]/.test(val))        errs.push('ein Sonderzeichen (!@#$%^&*-_=+?)');
        return errs;
    },

    // ─── Init ──────────────────────────────────────────────────────────────────
    init() {
        window.addEventListener('hashchange', () => this.navigate());
        this.navigate();
    },

    // ─── Router ────────────────────────────────────────────────────────────────
    navigate() {
        const valid = ['rules', 'folders', 'run', 'editor', 'settings', 'password', 'admin', 'dispatcher'];
        const hash  = location.hash.slice(1) || 'rules';
        const view  = valid.includes(hash) ? hash : 'rules';

        document.querySelectorAll('.nav-item[data-view]').forEach(el => {
            el.classList.toggle('active', el.dataset.view === view);
        });
        document.querySelectorAll('.view').forEach(el => {
            el.hidden = (el.id !== `view-${view}`);
        });

        if (view !== this.state.currentView) {
            this.state.currentView = view;
            const fn = this[`init${view.charAt(0).toUpperCase() + view.slice(1)}`];
            if (fn) fn.call(this);
        }
    },

    // ─── API helpers ───────────────────────────────────────────────────────────
    async apiGet(url) {
        const r = await fetch(url);
        if (!r.ok && r.status === 401) { location.href = 'login.php'; return null; }
        return r.json();
    },

    async apiPost(url, data = {}) {
        const r = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.CSRF_TOKEN || '',
            },
            body: JSON.stringify(data),
        });
        if (!r.ok && r.status === 401) { location.href = 'login.php'; return null; }
        return r.json();
    },

    // ─── Toast ─────────────────────────────────────────────────────────────────
    toast(msg, type = 'ok') {
        const icons = { ok: '✅', error: '❌', warn: '⚠️' };
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.innerHTML = `<span class="toast-icon">${icons[type] || '💬'}</span><span class="toast-msg">${this.esc(msg)}</span>`;
        document.getElementById('toast-container').appendChild(el);
        setTimeout(() => el.remove(), 4000);
    },

    // ─── Modal ─────────────────────────────────────────────────────────────────
    openModal(title, bodyHTML, saveFn, saveLabel = 'Speichern') {
        document.getElementById('modal-title').textContent = title;
        document.getElementById('modal-body').innerHTML    = bodyHTML;
        const btn = document.getElementById('modal-save-btn');
        btn.textContent = saveLabel;
        if (saveFn) {
            btn.style.display = '';
            this.state.modalSaveFn = saveFn;
            btn.onclick = () => saveFn();
        } else {
            btn.style.display = 'none';
        }
        document.getElementById('modal-overlay').hidden = false;
    },

    closeModal() {
        document.getElementById('modal-overlay').hidden = true;
        this.state.modalSaveFn = null;
    },

    // ─── Escape helper ─────────────────────────────────────────────────────────
    esc(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // RULES VIEW
    // ═══════════════════════════════════════════════════════════════════════════

    async initRules() {
        if (!this.state.rules) await this.loadRules();
        if (!this.state.folders.length) await this.loadFolders();
        this.renderRules();
    },

    async loadRules() {
        const res = await this.apiGet('api/rules.php');
        if (res && res.ok) {
            this.state.rules = res.data;
        } else {
            this.state.rules = { version: 1, spam: { enabled: true, header_field: 'X-KasSpamfilter', header_value: 'rSpamD', whitelist: [], target: 'Spam' }, rules: [] };
        }
    },

    async saveRules() {
        const res = await this.apiPost('api/rules.php', this.state.rules);
        if (res && res.ok) {
            if (res.warning) {
                this.toast('Regeln gespeichert. ⚠️ ' + res.warning, 'warn');
            }
            // Kein Toast bei normalem Speichern — Lua wird still im Hintergrund generiert
        } else {
            this.toast(res?.error || 'Fehler beim Speichern.', 'error');
        }
    },

    renderRules() {
        const el = document.getElementById('rules-content');
        if (!this.state.rules) { el.innerHTML = '<div class="empty-state">Lädt…</div>'; return; }
        el.innerHTML = this.buildSpamCard() + this.buildRulesListCard();
        this.bindRuleDragDrop();
    },

    buildSpamCard() {
        const s   = this.state.rules.spam || {};
        const wl  = (s.whitelist || []).map(w => `<span class="tag">${this.esc(w)}<button class="tag-remove" onclick="App.removeWhitelistEntry('${this.esc(w)}')" title="Entfernen">×</button></span>`).join('');
        const en  = s.enabled ? 'checked' : '';
        return `
<div class="card" id="spam-card">
  <div class="card-title">🚫 Spam-Filter <span class="badge">Globale Regel</span></div>
  <div class="toggle-row">
    <div><div class="toggle-label">Spam-Erkennung aktiv</div></div>
    <label class="toggle"><input type="checkbox" ${en} onchange="App.setSpamEnabled(this.checked)"><span class="toggle-slider"></span></label>
  </div>
  <div id="spam-details" ${s.enabled ? '' : 'style="opacity:.4;pointer-events:none"'}>
    <div class="form-row" style="margin-top:14px">
      <div class="form-group">
        <label class="form-label">Header-Feld</label>
        <input class="form-input" id="spam-field" value="${this.esc(s.header_field||'X-KasSpamfilter')}" oninput="App.updateSpamField()">
      </div>
      <div class="form-group">
        <label class="form-label">Header-Wert</label>
        <input class="form-input" id="spam-value" value="${this.esc(s.header_value||'rSpamD')}" oninput="App.updateSpamValue()">
      </div>
      <div class="form-group">
        <label class="form-label">Zielordner</label>
        ${this.buildFolderSelect('spam-target', s.target || 'Spam', 'App.updateSpamTarget(this.value)')}
      </div>
    </div>
    <div class="form-group" style="margin-top:4px">
      <label class="form-label">Whitelist (werden nie als Spam behandelt)</label>
      <div class="tag-list" id="whitelist-tags">${wl || '<span class="text-muted text-sm">Keine Einträge</span>'}</div>
      <div class="tag-input-row" style="margin-top:8px">
        <input class="form-input" id="wl-input" placeholder="@domain.de, name@domain.com, …" onkeydown="if(event.key==='Enter'){App.addWhitelistEntry();event.preventDefault()}">
        <button class="btn btn-secondary btn-sm" onclick="App.addWhitelistEntry()">+ Hinzufügen</button>
      </div>
    </div>
  </div>
</div>`;
    },

    buildRulesListCard() {
        const rules = this.state.rules.rules || [];
        let list = '';
        if (rules.length === 0) {
            list = `<div class="empty-state">
                      <div class="empty-state-icon">📭</div>
                      <div>Noch keine Regeln vorhanden.</div>
                      <div class="text-muted" style="margin-top:4px;font-size:.8rem">Klicke auf „+ Regel hinzufügen"</div>
                    </div>`;
        } else {
            list = '<div class="rules-list" id="rules-list">';
            rules.forEach((r, i) => {
                const from    = (r.from_addresses || []).join(', ') || '–';
                const to      = (r.to_addresses   || []).join(', ');
                const subj    = (r.subjects || []).join(', ');
                const meta    = [
                    from !== '–' ? `Von: ${from}` : null,
                    to           ? `An: ${to}`     : null,
                    subj         ? `Betreff: ${subj}` : null,
                ].filter(Boolean).join(' · ') || 'Keine Bedingungen';
                const enClass = r.enabled === false ? ' disabled' : '';
                const enIcon  = r.enabled === false ? '⏸' : '▶';
                list += `
<div class="rule-item${enClass}" draggable="true" data-idx="${i}"
     ondragstart="App.onDragStart(event,${i})" ondragover="App.onDragOver(event,${i})"
     ondrop="App.onDrop(event,${i})" ondragleave="App.onDragLeave(event)">
  <span class="drag-handle" title="Verschieben">⋮⋮</span>
  <div class="rule-info">
    <div class="rule-name">${this.esc(r.name || 'Unbenannte Regel')}</div>
    <div class="rule-meta">${this.esc(meta)}</div>
  </div>
  <span class="rule-target">📁 ${this.esc(r.target || '?')}</span>
  <div class="rule-actions">
    <button class="btn btn-sm btn-secondary btn-icon" title="${r.enabled === false ? 'Aktivieren' : 'Deaktivieren'}" onclick="App.toggleRule(${i})">${enIcon}</button>
    <button class="btn btn-sm btn-secondary btn-icon" title="Bearbeiten" onclick="App.openRuleModal(${i})">✏️</button>
    <button class="btn btn-sm btn-danger btn-icon" title="Löschen" onclick="App.deleteRule(${i})">🗑</button>
  </div>
</div>`;
            });
            list += '</div>';
        }

        return `<div class="card">
  <div class="card-title">📋 Filterregeln <span class="badge">${rules.length} Regel${rules.length !== 1 ? 'n' : ''}</span></div>
  <p class="text-muted text-sm" style="margin-bottom:12px">Reihenfolge per Drag & Drop ändern. Unterordner-Regeln müssen vor Root-Ordner-Regeln stehen.</p>
  ${list}
</div>`;
    },

    buildFolderSelect(id, value, onchange) {
        const folders = this.state.folders;
        if (!folders.length) {
            return `<input class="form-input" id="${id}" value="${this.esc(value)}" placeholder="Ordnername" oninput="${onchange ? onchange.replace('this.value', 'this.value') : ''}">`;
        }
        const opts = folders.map(f => `<option value="${this.esc(f)}" ${f === value ? 'selected' : ''}>${this.esc(f)}</option>`).join('');
        return `<select class="form-select" id="${id}" onchange="${onchange || ''}">${opts}</select>`;
    },

    // ── Debounce helper ────────────────────────────────────────────────────────
    _debounceTimers: {},
    debounce(key, fn, ms = 600) {
        clearTimeout(this._debounceTimers[key]);
        this._debounceTimers[key] = setTimeout(fn, ms);
    },

    // ── Spam helpers ────────────────────────────────────────────────────────────
    setSpamEnabled(v) {
        this.state.rules.spam.enabled = v;
        document.getElementById('spam-details').style.cssText = v ? '' : 'opacity:.4;pointer-events:none';
        this.saveRules();
    },
    updateSpamField()  { this.state.rules.spam.header_field = document.getElementById('spam-field').value; this.debounce('spam', () => this.saveRules()); },
    updateSpamValue()  { this.state.rules.spam.header_value = document.getElementById('spam-value').value; this.debounce('spam', () => this.saveRules()); },
    updateSpamTarget(v){ this.state.rules.spam.target = v; this.saveRules(); },

    addWhitelistEntry() {
        const inp = document.getElementById('wl-input');
        const values = inp.value
            .split(/[,;\n]+/)
            .map(v => v.trim())
            .filter(v => v.length > 0);
        if (!values.length) return;
        if (!this.state.rules.spam.whitelist) this.state.rules.spam.whitelist = [];
        let added = false;
        values.forEach(val => {
            if (!this.state.rules.spam.whitelist.includes(val)) {
                this.state.rules.spam.whitelist.push(val);
                added = true;
            }
        });
        if (added) this.saveRules();
        inp.value = '';
        this.renderRules();
    },

    removeWhitelistEntry(val) {
        this.state.rules.spam.whitelist = (this.state.rules.spam.whitelist || []).filter(w => w !== val);
        this.saveRules();
        this.renderRules();
    },

    // ── Rule CRUD ───────────────────────────────────────────────────────────────
    openRuleModal(idx = null) {
        const isEdit = idx !== null;
        const rule   = isEdit ? { ...this.state.rules.rules[idx] } : { name: '', enabled: true, from_addresses: [], subjects: [], logic: 'OR', target: '' };

        const folderOpts = this.state.folders.length
            ? this.state.folders.map(f => `<option value="${this.esc(f)}" ${f === rule.target ? 'selected' : ''}>${this.esc(f)}</option>`).join('')
            : `<option value="${this.esc(rule.target)}">${this.esc(rule.target || 'Kein Ordner')}</option>`;

        const fromTags  = (rule.from_addresses || []).map(a => `<span class="tag" data-val="${this.esc(a)}">${this.esc(a)}<button class="tag-remove" onclick="App._modalRemoveTag('from','${this.esc(a)}')">×</button></span>`).join('');
        const toTags    = (rule.to_addresses   || []).map(a => `<span class="tag" data-val="${this.esc(a)}">${this.esc(a)}<button class="tag-remove" onclick="App._modalRemoveTag('to','${this.esc(a)}')">×</button></span>`).join('');
        const subjTags  = (rule.subjects || []).map(s => `<span class="tag" data-val="${this.esc(s)}">${this.esc(s)}<button class="tag-remove" onclick="App._modalRemoveTag('subj','${this.esc(s)}')">×</button></span>`).join('');

        const body = `
<div class="form-group">
  <label class="form-label">Regelname</label>
  <input class="form-input" id="m-name" value="${this.esc(rule.name)}" placeholder="z. B. Familie, Arbeit, Newsletter…">
</div>

<div class="form-group">
  <label class="form-label">Absender-Adressen (Von:)</label>
  <div class="tag-list" id="m-from-tags">${fromTags || '<span class="text-muted text-sm" id="m-from-empty">Keine</span>'}</div>
  <div class="tag-input-row">
    <input class="form-input" id="m-from-input" placeholder="@domain.de, name@domain.com, …" onkeydown="if(event.key==='Enter'){App._modalAddTag('from');event.preventDefault()}">
    <button class="btn btn-secondary btn-sm" onclick="App._modalAddTag('from')">+ Hinzufügen</button>
  </div>
</div>

<div class="form-group">
  <label class="form-label">Empfänger-Adressen (An:)</label>
  <div class="tag-list" id="m-to-tags">${toTags || '<span class="text-muted text-sm" id="m-to-empty">Keine</span>'}</div>
  <div class="tag-input-row">
    <input class="form-input" id="m-to-input" placeholder="empfaenger@domain.de, …" onkeydown="if(event.key==='Enter'){App._modalAddTag('to');event.preventDefault()}">
    <button class="btn btn-secondary btn-sm" onclick="App._modalAddTag('to')">+ Hinzufügen</button>
  </div>
</div>

<div class="form-group">
  <label class="form-label">Betreff-Schlüsselwörter (Betreff enthält…)</label>
  <div class="tag-list" id="m-subj-tags">${subjTags || '<span class="text-muted text-sm" id="m-subj-empty">Keine</span>'}</div>
  <div class="tag-input-row">
    <input class="form-input" id="m-subj-input" placeholder="Stichwort im Betreff" onkeydown="if(event.key==='Enter'){App._modalAddTag('subj');event.preventDefault()}">
    <button class="btn btn-secondary btn-sm" onclick="App._modalAddTag('subj')">+ Hinzufügen</button>
  </div>
</div>

<div class="form-row">
  <div class="form-group" style="flex:2">
    <label class="form-label">Zielordner</label>
    ${this.state.folders.length
        ? `<select class="form-select" id="m-target">${folderOpts}</select>`
        : `<input class="form-input" id="m-target" value="${this.esc(rule.target)}" placeholder="Ordnername">`
    }
  </div>
  <div class="form-group" style="flex:1">
    <label class="form-label">Logik (Von + Betreff)</label>
    <div class="logic-toggle">
      <button onclick="App._setLogic('OR',this)"  class="${rule.logic !== 'AND' ? 'active' : ''}">ODER</button>
      <button onclick="App._setLogic('AND',this)" class="${rule.logic === 'AND' ? 'active' : ''}">UND</button>
    </div>
  </div>
</div>

<div class="toggle-row" style="border-top:1px solid var(--border);padding-top:12px;margin-top:4px">
  <div class="toggle-label">Regel aktiv</div>
  <label class="toggle">
    <input type="checkbox" id="m-enabled" ${rule.enabled !== false ? 'checked' : ''}>
    <span class="toggle-slider"></span>
  </label>
</div>
<input type="hidden" id="m-logic" value="${rule.logic || 'OR'}">`;

        this.openModal(
            isEdit ? `Regel bearbeiten` : 'Neue Regel',
            body,
            () => this._saveRuleModal(idx)
        );
    },

    _setLogic(val, btn) {
        document.getElementById('m-logic').value = val;
        btn.closest('.logic-toggle').querySelectorAll('button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    },

    _modalTags: { from: null, subj: null },

    _modalAddTag(type) {
        const inputId     = type === 'from' ? 'm-from-input' : type === 'to' ? 'm-to-input' : 'm-subj-input';
        const containerId = type === 'from' ? 'm-from-tags'  : type === 'to' ? 'm-to-tags'  : 'm-subj-tags';
        const inp = document.getElementById(inputId);
        if (!inp) return;

        const values = inp.value
            .split(/[,;\n]+/)
            .map(v => v.trim())
            .filter(v => v.length > 0);
        if (!values.length) return;

        const container = document.getElementById(containerId);
        const empty = container.querySelector(`#m-${type}-empty`);
        if (empty) empty.remove();

        const existing = new Set(
            Array.from(container.querySelectorAll('.tag')).map(t => t.dataset.val)
        );

        values.forEach(val => {
            if (existing.has(val)) return;
            existing.add(val);
            const tag = document.createElement('span');
            tag.className = 'tag';
            tag.dataset.val = val;
            tag.innerHTML = `${this.esc(val)}<button class="tag-remove" onclick="App._modalRemoveTag('${type}','${this.esc(val)}')">×</button>`;
            container.appendChild(tag);
        });

        inp.value = '';
    },

    _modalRemoveTag(type, val) {
        const containerId = type === 'from' ? 'm-from-tags' : type === 'to' ? 'm-to-tags' : 'm-subj-tags';
        const container   = document.getElementById(containerId);
        if (!container) return;
        container.querySelectorAll('.tag').forEach(t => {
            if (t.dataset.val === val) t.remove();
        });
    },

    _getModalTags(containerId) {
        const container = document.getElementById(containerId);
        if (!container) return [];
        return Array.from(container.querySelectorAll('.tag')).map(t => t.dataset.val).filter(Boolean);
    },

    _saveRuleModal(idx) {
        const name    = document.getElementById('m-name').value.trim();
        const target  = document.getElementById('m-target').value.trim();
        const logic   = document.getElementById('m-logic').value;
        const enabled = document.getElementById('m-enabled').checked;
        const from    = this._getModalTags('m-from-tags');
        const to      = this._getModalTags('m-to-tags');
        const subj    = this._getModalTags('m-subj-tags');

        if (!name)   { this.toast('Bitte einen Regelnamen angeben.', 'warn'); return; }
        if (!target) { this.toast('Bitte einen Zielordner angeben.', 'warn'); return; }
        if (!from.length && !to.length && !subj.length) { this.toast('Mindestens eine Bedingung (Absender, Empfänger oder Betreff) angeben.', 'warn'); return; }

        const rule = { name, enabled, from_addresses: from, to_addresses: to, subjects: subj, logic, target };

        if (idx !== null) {
            rule.id = this.state.rules.rules[idx].id;
            this.state.rules.rules[idx] = rule;
        } else {
            rule.id = Math.random().toString(36).slice(2, 10);
            this.state.rules.rules.push(rule);
        }

        this.saveRules();
        this.closeModal();
        this.renderRules();
    },

    toggleRule(idx) {
        const r = this.state.rules.rules[idx];
        r.enabled = r.enabled === false ? true : false;
        this.saveRules();
        this.renderRules();
    },

    deleteRule(idx) {
        const r = this.state.rules.rules[idx];
        this.openModal(
            'Regel löschen',
            `<p>Regel <strong>${this.esc(r.name)}</strong> wirklich löschen?</p>`,
            () => {
                this.state.rules.rules.splice(idx, 1);
                this.saveRules();
                this.closeModal();
                this.renderRules();
            },
            'Löschen'
        );
        document.getElementById('modal-save-btn').classList.replace('btn-primary', 'btn-danger');
    },

    // ── Drag & drop ────────────────────────────────────────────────────────────
    bindRuleDragDrop() {},   // Handlers already inline via data-idx

    onDragStart(e, idx) {
        this.state.dragSrcIdx = idx;
        e.target.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    },

    onDragOver(e, idx) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        document.querySelectorAll('.rule-item').forEach(el => el.classList.remove('drag-over'));
        e.currentTarget.classList.add('drag-over');
    },

    onDragLeave(e) {
        e.currentTarget.classList.remove('drag-over');
    },

    onDrop(e, toIdx) {
        e.preventDefault();
        const fromIdx = this.state.dragSrcIdx;
        if (fromIdx === null || fromIdx === toIdx) {
            document.querySelectorAll('.rule-item').forEach(el => {
                el.classList.remove('dragging','drag-over');
            });
            return;
        }
        const rules = this.state.rules.rules;
        const [moved] = rules.splice(fromIdx, 1);
        rules.splice(toIdx, 0, moved);
        this.state.dragSrcIdx = null;
        this.saveRules();
        this.renderRules();
    },

    // ── Generate Lua ────────────────────────────────────────────────────────────
    async generateLua() {
        const res = await this.apiPost('api/generate.php');
        if (res && res.ok) {
            this.toast(res.message || 'Lua-Dateien generiert.');
        } else {
            this.toast(res?.error || 'Fehler beim Generieren.', 'error');
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // FOLDERS VIEW
    // ═══════════════════════════════════════════════════════════════════════════

    async initFolders() {
        if (!this.state.folders.length) await this.loadFolders();
        this.renderFolders();
    },

    async loadFolders(force = false) {
        const el = document.getElementById('folders-content');
        if (el) el.innerHTML = '<div class="empty-state">📡 Verbinde mit IMAP-Server…</div>';

        const res = await this.apiGet('api/folders.php');
        if (res && res.ok) {
            this.state.folders = res.folders || [];
        } else {
            if (el) el.innerHTML = `<div class="card"><div class="empty-state">⚠️ ${this.esc(res?.error || 'Fehler beim Laden der Ordner.')}<br><span class="text-sm text-muted" style="margin-top:6px;display:block">Bitte IMAP-Einstellungen prüfen.</span></div></div>`;
            return;
        }
        if (this.state.currentView === 'folders') this.renderFolders();
    },

    renderFolders() {
        const el = document.getElementById('folders-content');
        if (!el) return;
        const folders = this.state.folders;

        if (!folders.length) {
            el.innerHTML = '<div class="card"><div class="empty-state">Keine Ordner gefunden oder IMAP nicht verbunden.</div></div>';
            return;
        }

        const items = folders.map(f => {
            const depth    = (f.match(/\//g) || []).length;
            const cls      = depth > 0 ? ` indent${Math.min(depth, 3)}` : '';
            const icon     = f === 'INBOX' ? '📥' : f.toLowerCase().includes('spam') || f.toLowerCase().includes('junk') ? '🚫' : depth > 0 ? '📂' : '📁';
            const isInbox  = f === 'INBOX';
            const actions  = isInbox ? '' : `
  <div style="display:flex;gap:6px;margin-left:auto">
    <button class="btn btn-sm btn-secondary btn-icon" title="Umbenennen" onclick="App.openRenameFolderModal('${this.esc(f)}')">✏️</button>
    <button class="btn btn-sm btn-danger btn-icon"    title="Löschen"    onclick="App.openDeleteFolderModal('${this.esc(f)}')">🗑</button>
  </div>`;
            return `<div class="folder-item${cls}" style="justify-content:space-between"><div style="display:flex;align-items:center;gap:8px"><span class="folder-icon">${icon}</span>${this.esc(f)}</div>${actions}</div>`;
        }).join('');

        el.innerHTML = `<div class="card">
  <div class="card-title">📁 IMAP-Ordner <span class="badge">${folders.length}</span></div>
  <div class="folder-list">${items}</div>
</div>`;
    },

    openRenameFolderModal(name) {
        const body = `
<div class="form-group">
  <label class="form-label">Aktueller Name</label>
  <input class="form-input" value="${this.esc(name)}" readonly style="opacity:.6">
</div>
<div class="form-group">
  <label class="form-label">Neuer Name</label>
  <input class="form-input" id="rename-folder-new" value="${this.esc(name)}" autofocus
         onkeydown="if(event.key==='Enter') document.getElementById('modal-save-btn').click()">
  <div class="text-sm text-muted mt-2">Unterordner mit <code>/</code> trennen.</div>
</div>`;
        this.openModal('Ordner umbenennen', body, () => this._renameFolder(name));
    },

    async _renameFolder(oldName) {
        const newName = document.getElementById('rename-folder-new')?.value.trim();
        if (!newName || newName === oldName) { this.closeModal(); return; }
        const res = await this.apiPost('api/folders.php?action=rename', { old_name: oldName, new_name: newName });
        if (res && res.ok) {
            this.toast(res.message || 'Ordner umbenannt.');
            this.closeModal();
            await this.loadFolders(true);
        } else {
            this.toast(res?.error || 'Fehler beim Umbenennen.', 'error');
        }
    },

    openDeleteFolderModal(name) {
        const body = `
<p>Ordner <strong>${this.esc(name)}</strong> wirklich löschen?</p>
<p class="text-muted text-sm" style="margin-top:8px">
  Alle Mails in diesem Ordner werden vorher in die <strong>INBOX</strong> verschoben.
</p>`;
        this.openModal('Ordner löschen', body, () => this._deleteFolder(name), 'Löschen');
        document.getElementById('modal-save-btn').classList.replace('btn-primary', 'btn-danger');
    },

    async _deleteFolder(name) {
        const res = await fetch('api/folders.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: JSON.stringify({ name }),
        }).then(r => r.json());
        if (res && res.ok) {
            this.toast(res.message || 'Ordner gelöscht.');
            this.closeModal();
            await this.loadFolders(true);
        } else {
            this.toast(res?.error || 'Fehler beim Löschen.', 'error');
        }
    },

    openCreateFolderModal() {
        const body = `
<div class="form-group">
  <label class="form-label">Ordnername</label>
  <input class="form-input" id="new-folder-name" placeholder="z. B. Newsletter oder Familie/Max" autofocus
         onkeydown="if(event.key==='Enter') document.getElementById('modal-save-btn').click()">
  <div class="text-sm text-muted mt-2">Unterordner mit <code>/</code> trennen, z. B. <code>#servermails/Grafana</code></div>
</div>`;
        this.openModal('Neuen Ordner anlegen', body, () => this.createFolder());
    },

    async createFolder() {
        const name = document.getElementById('new-folder-name')?.value.trim();
        if (!name) { this.toast('Bitte Ordnernamen eingeben.', 'warn'); return; }

        const res = await this.apiPost('api/folders.php?action=create', { name });
        if (res && res.ok) {
            this.toast(res.message || 'Ordner erstellt.');
            this.closeModal();
            await this.loadFolders(true);
            this.renderFolders();
        } else {
            this.toast(res?.error || 'Fehler beim Erstellen.', 'error');
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // RUN VIEW
    // ═══════════════════════════════════════════════════════════════════════════

    async initRun() {
        await this.loadLog();
    },

    async loadLog() {
        const el  = document.getElementById('log-output');
        if (!el) return;
        const res = await this.apiGet('api/run.php?lines=100');
        if (res && res.ok) {
            const text = res.log || '';
            el.textContent = text || '(Logdatei leer)';
            el.scrollTop   = el.scrollHeight;
        } else {
            el.innerHTML = `<span class="log-empty">${this.esc(res?.error || 'Fehler beim Laden.')}</span>`;
        }
    },

    async runImapfilter() {
        const btn = document.getElementById('btn-run');
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Läuft…'; }

        const res = await this.apiPost('api/run.php');
        if (btn) { btn.disabled = false; btn.innerHTML = '▶ imapfilter starten'; }

        if (res) {
            if (res.ok) {
                this.toast(res.message || 'imapfilter ausgeführt.');
            } else {
                this.toast(res.error || `Fehler (Code ${res.exit_code}).`, 'error');
            }
            // Append output to log
            const el = document.getElementById('log-output');
            if (el && res.output) {
                el.textContent += '\n--- Manueller Lauf ---\n' + res.output;
                el.scrollTop = el.scrollHeight;
            }
        }
        await this.loadLog();
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // EDITOR VIEW
    // ═══════════════════════════════════════════════════════════════════════════

    async initEditor() {
        await this.loadEditorFile('filters');
    },

    async switchEditorTab(key, btn) {
        document.querySelectorAll('#view-editor .tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('editor-current-file').value = key;
        await this.loadEditorFile(key);
    },

    async loadEditorFile(key) {
        const ta  = document.getElementById('editor-textarea');
        if (!ta) return;
        ta.value = '-- Lädt…';
        const res = await this.apiGet(`api/editor.php?file=${key}`);
        ta.value  = res?.content ?? '-- Fehler beim Laden.';
    },

    async saveEditorFile() {
        const key     = document.getElementById('editor-current-file').value;
        const content = document.getElementById('editor-textarea').value;
        const res     = await this.apiPost(`api/editor.php?file=${key}`, { content });
        if (res && res.ok) {
            this.toast(res.message || 'Datei gespeichert.');
        } else {
            this.toast(res?.error || 'Fehler beim Speichern.', 'error');
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // SETTINGS VIEW
    // ═══════════════════════════════════════════════════════════════════════════

    async initSettings() {
        await this.loadSettings();
        await this.loadInterval();
    },

    async loadSettings() {
        const res = await this.apiGet('api/settings.php');
        if (res && res.ok) {
            const s = res.settings;
            document.getElementById('s-host').value    = s.host  || '';
            document.getElementById('s-port').value    = s.port  || 993;
            document.getElementById('s-ssl').checked          = !!s.ssl;
            document.getElementById('s-ssl-novalidate').checked = !!s.ssl_novalidate;
            document.getElementById('s-user').value    = s.user  || '';
            document.getElementById('s-pass').value    = '';  // never prefill password
            if (s.pass_set) {
                document.getElementById('s-pass').placeholder = '(gesetzt — leer lassen = nicht ändern)';
            }
        } else {
            this.toast(res?.error || 'Fehler beim Laden der Einstellungen.', 'error');
        }
    },

    async saveSettings() {
        const data = {
            host:           document.getElementById('s-host').value.trim(),
            port:           parseInt(document.getElementById('s-port').value, 10),
            ssl:            document.getElementById('s-ssl').checked,
            ssl_novalidate: document.getElementById('s-ssl-novalidate').checked,
            user:           document.getElementById('s-user').value.trim(),
            pass:           document.getElementById('s-pass').value,
        };
        const res = await this.apiPost('api/settings.php?action=save', data);
        if (res && res.ok) {
            this.toast('Einstellungen gespeichert.');
            this.state.folders = []; // Cache leeren, da Verbindung ggf. geändert
        } else {
            this.toast(res?.error || 'Fehler beim Speichern.', 'error');
        }
    },

    async testConnection() {
        const btn = document.getElementById('btn-test');
        const statusEl = document.getElementById('settings-status');
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Teste…'; }

        const res = await this.apiPost('api/settings.php?action=test');
        if (btn) { btn.disabled = false; btn.innerHTML = '🔌 Verbindung testen'; }

        if (res && res.ok) {
            statusEl.innerHTML = `<span class="status-dot ok"></span> ${this.esc(res.message || 'Verbindung OK')}`;
            this.toast(res.message || 'Verbindung erfolgreich.', 'ok');
            await this.loadFolders();
        } else {
            statusEl.innerHTML = `<span class="status-dot error"></span> ${this.esc(res?.error || 'Verbindung fehlgeschlagen.')}`;
            this.toast(res?.error || 'Verbindung fehlgeschlagen.', 'error');
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // PASSWORD VIEW
    // ═══════════════════════════════════════════════════════════════════════════

    initPassword() {
        // Stärke-Indikator ins View einbauen
        const el = document.getElementById('cp-strength');
        if (el) el.innerHTML = this.pwdHtml('cp-new');
        // Felder leeren
        ['cp-current','cp-new','cp-new2'].forEach(id => {
            const f = document.getElementById(id);
            if (f) f.value = '';
        });
        const status = document.getElementById('cp-status');
        if (status) status.innerHTML = '';
    },

    async changePassword() {
        const current = document.getElementById('cp-current').value;
        const newPwd  = document.getElementById('cp-new').value;
        const newPwd2 = document.getElementById('cp-new2').value;
        const status  = document.getElementById('cp-status');

        if (!current) { this.toast('Bitte aktuelles Passwort eingeben.', 'warn'); return; }

        const errs = this.pwdValidate(newPwd);
        if (errs.length) { this.toast('Neues Passwort benötigt: ' + errs.join(', ') + '.', 'warn'); return; }
        if (newPwd !== newPwd2) { this.toast('Neue Passwörter stimmen nicht überein.', 'warn'); return; }
        if (newPwd === current) { this.toast('Neues Passwort muss sich vom aktuellen unterscheiden.', 'warn'); return; }

        const res = await this.apiPost('api/users.php?action=change_password', {
            current_password: current,
            new_password:     newPwd,
        });

        if (res && res.ok) {
            this.toast('Passwort erfolgreich geändert.');
            ['cp-current','cp-new','cp-new2'].forEach(id => {
                const f = document.getElementById(id);
                if (f) f.value = '';
            });
            this.pwdCheck('cp-new');
        } else {
            this.toast(res?.error || 'Fehler beim Ändern.', 'error');
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // ADMIN VIEW
    // ═══════════════════════════════════════════════════════════════════════════

    async initAdmin() {
        if (!window.IS_ADMIN) return;
        await this.loadUsers();
    },

    async loadUsers() {
        const el  = document.getElementById('users-list');
        if (!el) return;
        const res = await this.apiGet('api/users.php');
        if (!res || !res.ok) {
            el.innerHTML = `<div class="empty-state">⚠️ ${this.esc(res?.error || 'Fehler beim Laden.')}</div>`;
            return;
        }
        this.renderUsers(res.users);
    },

    renderUsers(users) {
        const el = document.getElementById('users-list');
        if (!el) return;
        if (!users.length) {
            el.innerHTML = '<div class="empty-state">Keine Benutzer gefunden.</div>';
            return;
        }
        el.innerHTML = users.map(u => `
<div class="rule-item" style="margin-bottom:6px">
  <div class="rule-info">
    <div class="rule-name">
      ${this.esc(u.username)}
      ${u.is_admin ? '<span style="font-size:.7rem;background:rgba(59,130,246,.2);color:#93c5fd;padding:1px 6px;border-radius:3px;margin-left:6px">Admin</span>' : ''}
      ${u.username === window.CURRENT_USER ? '<span style="font-size:.7rem;color:var(--muted);margin-left:4px">(du)</span>' : ''}
    </div>
    <div class="rule-meta">/srv/imapfilter/${this.esc(u.username)}/</div>
  </div>
  <div class="rule-actions">
    <button class="btn btn-sm btn-secondary" onclick="App.openResetPasswordModal('${this.esc(u.username)}')">🔑 Passwort</button>
    ${u.username !== window.CURRENT_USER
        ? `<button class="btn btn-sm btn-danger" onclick="App.deleteUser('${this.esc(u.username)}')">🗑 Löschen</button>`
        : ''}
  </div>
</div>`).join('');
    },

    openCreateUserModal() {
        const body = `
<div class="form-group">
  <label class="form-label">Benutzername</label>
  <input class="form-input" id="nu-username" placeholder="kleinbuchstaben, zahlen, - _ ." autofocus
         pattern="[a-zA-Z0-9_\\-\\.]+" onkeydown="if(event.key==='Enter') document.getElementById('modal-save-btn').click()">
</div>
<div class="form-group">
  <label class="form-label">Passwort</label>
  <input type="password" class="form-input" id="nu-password" autocomplete="new-password"
         oninput="App.pwdCheck('nu-password')">
  ${this.pwdHtml('nu-password')}
</div>
<div class="form-group">
  <label class="form-label">Passwort wiederholen</label>
  <input type="password" class="form-input" id="nu-password2" autocomplete="new-password">
</div>
<div class="toggle-row">
  <div class="toggle-label">Admin-Rechte</div>
  <label class="toggle"><input type="checkbox" id="nu-admin"><span class="toggle-slider"></span></label>
</div>`;
        this.openModal('Benutzer anlegen', body, () => this._saveCreateUser());
    },

    async _saveCreateUser() {
        const username  = document.getElementById('nu-username').value.trim();
        const password  = document.getElementById('nu-password').value;
        const password2 = document.getElementById('nu-password2').value;
        const is_admin  = document.getElementById('nu-admin').checked;

        if (!username)             { this.toast('Benutzername eingeben.', 'warn'); return; }
        if (!/^[a-zA-Z0-9_\-\.]+$/.test(username)) { this.toast('Ungültige Zeichen im Benutzernamen.', 'warn'); return; }

        const errs = this.pwdValidate(password);
        if (errs.length) { this.toast('Passwort benötigt: ' + errs.join(', ') + '.', 'warn'); return; }
        if (password !== password2) { this.toast('Passwörter stimmen nicht überein.', 'warn'); return; }

        const res = await this.apiPost('api/users.php?action=create', { username, password, is_admin });
        if (res && res.ok) {
            this.toast(res.message || 'Benutzer angelegt.');
            this.closeModal();
            await this.loadUsers();
        } else {
            this.toast(res?.error || 'Fehler beim Anlegen.', 'error');
        }
    },

    openResetPasswordModal(username) {
        const body = `
<p class="text-muted text-sm" style="margin-bottom:14px">Neues Passwort für <strong>${this.esc(username)}</strong> setzen.</p>
<div class="form-group">
  <label class="form-label">Neues Passwort</label>
  <input type="password" class="form-input" id="rp-password" autocomplete="new-password" autofocus
         oninput="App.pwdCheck('rp-password')">
  ${this.pwdHtml('rp-password')}
</div>
<div class="form-group">
  <label class="form-label">Wiederholen</label>
  <input type="password" class="form-input" id="rp-password2" autocomplete="new-password">
</div>`;
        this.openModal(`Passwort zurücksetzen`, body, () => this._saveResetPassword(username));
    },

    async _saveResetPassword(username) {
        const password  = document.getElementById('rp-password').value;
        const password2 = document.getElementById('rp-password2').value;
        const errs = this.pwdValidate(password);
        if (errs.length)            { this.toast('Passwort benötigt: ' + errs.join(', ') + '.', 'warn'); return; }
        if (password !== password2) { this.toast('Passwörter stimmen nicht überein.', 'warn'); return; }

        const res = await this.apiPost('api/users.php?action=reset_password', { username, password });
        if (res && res.ok) {
            this.toast(res.message || 'Passwort zurückgesetzt.');
            this.closeModal();
        } else {
            this.toast(res?.error || 'Fehler.', 'error');
        }
    },

    // ── Interval ────────────────────────────────────────────────────────────────
    async loadInterval() {
        const res = await this.apiGet('api/dispatcher.php');
        if (!res || !res.ok) return;

        const inp = document.getElementById('s-interval');
        if (inp) inp.value = res.interval ?? 5;

        const statusEl = document.getElementById('dispatcher-status-user');
        if (statusEl) {
            if (res.last_run) {
                const ago  = Math.round((Date.now() / 1000 - res.last_run) / 60);
                const exit = res.last_exit === 0
                    ? '<span class="status-dot ok"></span> OK'
                    : `<span class="status-dot error"></span> Exit ${res.last_exit}`;
                statusEl.innerHTML = `${exit} &nbsp;·&nbsp; Letzter Lauf: vor ${ago} Min. &nbsp;·&nbsp; Dauer: ${res.last_duration}s`;
            } else {
                statusEl.innerHTML = '<span class="text-muted text-sm">Noch kein Lauf aufgezeichnet.</span>';
            }
        }
    },

    async saveInterval() {
        const val = parseInt(document.getElementById('s-interval')?.value, 10);
        if (isNaN(val) || val < 0) { this.toast('Bitte eine gültige Minutenzahl eingeben.', 'warn'); return; }
        const res = await this.apiPost('api/dispatcher.php', { interval: val });
        if (res && res.ok) {
            this.toast(res.message || 'Intervall gespeichert.');
        } else {
            this.toast(res?.error || 'Fehler beim Speichern.', 'error');
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // DISPATCHER VIEW (Admin)
    // ═══════════════════════════════════════════════════════════════════════════

    async initDispatcher() {
        if (!window.IS_ADMIN) return;
        await this.loadDispatcherStatus();
    },

    switchDispatcherTab(key, btn) {
        ['systemd','crond','hoster'].forEach(k => {
            const el = document.getElementById('dt-' + k);
            if (el) el.hidden = (k !== key);
        });
        document.querySelectorAll('#dispatcher-setup-tabs .tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');
    },

    async loadDispatcherStatus() {
        const el = document.getElementById('dispatcher-status-table');
        if (!el) return;
        el.innerHTML = '<div class="empty-state">Lädt…</div>';

        const res = await this.apiGet('api/dispatcher.php?all=1');
        if (!res || !res.ok) {
            el.innerHTML = `<div class="empty-state">⚠️ ${this.esc(res?.error || 'Fehler.')}</div>`;
            return;
        }

        const now  = Math.floor(Date.now() / 1000);
        const rows = res.users.map(u => {
            const ago = u.last_run
                ? (() => { const m = Math.round((now - u.last_run) / 60); return m < 60 ? `vor ${m} Min.` : `vor ${Math.round(m/60)} Std.`; })()
                : '–';
            const exitDot = u.last_run === null ? ''
                : u.last_exit === 0
                    ? '<span class="status-dot ok"></span>'
                    : '<span class="status-dot error"></span>';
            const configWarn = !u.config_exists
                ? ' <span style="color:var(--warning);font-size:.75rem" title="config.lua fehlt — Lua generieren!">⚠️</span>'
                : '';
            return `
<div class="rule-item" style="margin-bottom:6px">
  <div class="rule-info">
    <div class="rule-name">${this.esc(u.username)}${configWarn}</div>
    <div class="rule-meta">
      ${exitDot} Letzter Lauf: ${ago}
      ${u.last_duration ? `· ${u.last_duration}s` : ''}
    </div>
  </div>
  <span class="rule-target">🕐 ${this.esc(u.interval_label)}</span>
</div>`;
        }).join('');

        el.innerHTML = rows || '<div class="empty-state">Keine Benutzer.</div>';
    },

    async deleteUser(username) {
        this.openModal(
            'Benutzer löschen',
            `<p>Benutzer <strong>${this.esc(username)}</strong> wirklich löschen?</p>
             <p class="text-muted text-sm" style="margin-top:8px">Die Dateien unter <code>/srv/imapfilter/${this.esc(username)}/</code> bleiben erhalten.</p>`,
            async () => {
                const res = await fetch('api/users.php', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
                    body: JSON.stringify({ username }),
                }).then(r => r.json());
                if (res && res.ok) {
                    this.toast(res.message || 'Benutzer gelöscht.');
                    this.closeModal();
                    await this.loadUsers();
                } else {
                    this.toast(res?.error || 'Fehler beim Löschen.', 'error');
                }
            },
            'Löschen'
        );
        document.getElementById('modal-save-btn').classList.replace('btn-primary', 'btn-danger');
    },

};

// Close modal on overlay click
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('modal-overlay').addEventListener('click', e => {
        if (e.target === document.getElementById('modal-overlay')) App.closeModal();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') App.closeModal();
    });
    App.init();
});
