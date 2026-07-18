/* === SHARED GLOBALS === */
if (typeof window.SPOTLIGHT_DISABLED === 'undefined') {
    window.SPOTLIGHT_DISABLED = localStorage.getItem('spotlight_disabled') === '1';
}

/* === UTILITIES === */

function debounce(fn, ms) {
    var timer = null;
    return function() {
        var ctx = this, args = arguments;
        if (timer) clearTimeout(timer);
        timer = setTimeout(function() { timer = null; fn.apply(ctx, args); }, ms);
    };
}

function htmlspecialchars(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function fmtDate(isoStr) {
    var p = isoStr.split('-');
    return parseInt(p[2]) + ' ' + MONTHS[parseInt(p[1]) - 1] + ' ' + p[0];
}

function repositionPastDateMsg() {
    var el = document.getElementById('past-date-msg');
    if (!el || el.style.display === 'none') return;
    var btn = document.querySelector('#booking-form button[type="submit"]');
    if (btn) {
        var r = btn.getBoundingClientRect();
        var left = Math.max(8, Math.min(r.left + r.width / 2 - 45 + 30 + window.scrollX, document.documentElement.scrollWidth - 220));
        el.style.top = (r.bottom + window.scrollY - 4) + 'px';
        el.style.left = left + 'px';
    }
}

function showPastDateMsg(msg) {
    var el = document.getElementById('past-date-msg');
    if (!el) {
        el = document.createElement('div');
        el.id = 'past-date-msg';
        el.className = 'jagged-bubble top';
        el.style.cssText = 'position:absolute;font-size:1.35rem;max-width:400px;pointer-events:auto;z-index:9999;';
        document.body.appendChild(el);
    }
    el.textContent = msg || "Pick a valid date.";
    repositionPastDateMsg();
    el.style.display = 'block';
    el.classList.remove('shaky-pop');
    void el.offsetWidth;
    el.classList.add('shaky-pop');
}

function hidePastDateMsg() {
    var el = document.getElementById('past-date-msg');
    if (el) el.style.display = 'none';
}

/* === VACATION BADGES === */

function isOnVacation(mechId, date) {
    var vacs = VACATION_DATA[mechId] || [];
    for (var i = 0; i < vacs.length; i++) {
        if (vacs[i].start_date <= date && vacs[i].end_date >= date) return true;
    }
    return false;
}

function updateVacationBadges(date) {
    var checkDate = date || new Date().toISOString().slice(0, 10);
    document.querySelectorAll('.vacation-badge').forEach(function(b) { b.remove(); });
    document.querySelectorAll('.mechanic-card').forEach(function(card) {
        var mechId = parseInt(card.querySelector('input[name="mechanic_id"]').value);
        if (isOnVacation(mechId, checkDate)) {
            var badge = document.createElement('span');
            badge.className = 'status-badge status-cancelled vacation-badge';
            badge.textContent = 'ON VACATION';
            card.appendChild(badge);
        }
    });
}

/* === BOOKING PAGE === */

function updateQuotePosition(card) {
    var qt = document.getElementById('quote-tooltip');
    if (!qt || qt.classList.contains('hidden')) return;
    var quote = card.getAttribute('data-quote');
    var name = card.querySelector('h3')?.textContent || '';
    if (!quote) { qt.classList.add('hidden'); return; }
    var doodle = card.getAttribute('data-doodle');
    var dClass = 'doodle doodle-mech-quote';
    if (doodle && doodle.indexOf('stiletto') > -1) dClass += ' doodle-mech-quote-lg';
    else if (doodle && doodle.indexOf('lightning') > -1) dClass += ' doodle-mech-quote-lg doodle-mech-quote-lg-noflip';
    qt.innerHTML = (doodle ? '<img class="' + dClass + '" src="' + htmlspecialchars(doodle) + '" alt="">' : '') + '<span class="qt-text">"' + htmlspecialchars(quote) + '"</span><span class="qt-author">- ' + htmlspecialchars(name) + '</span>';
    var rect = card.getBoundingClientRect();
    var tooltipWidth = qt.offsetWidth;
    var tooltipHeight = qt.offsetHeight;
    var cardTopInDoc = rect.top + window.scrollY;
    var cardLeftInDoc = rect.left + window.scrollX;
    var cardWidth = rect.width;
    qt.style.top = (cardTopInDoc - tooltipHeight - 12) + 'px';
    var anchorX = cardLeftInDoc + cardWidth - 150;
    qt.style.left = Math.max(10, Math.min(anchorX - 35, window.innerWidth - tooltipWidth - 10)) + 'px';
}

function selectMechanic(id) {
    var selectedCard = null;
    document.querySelectorAll('.mechanic-card').forEach(function(c) { c.classList.remove('selected'); });
    document.querySelectorAll('input[name="mechanic_id"]').forEach(function(r) {
        if (parseInt(r.value) === id) { r.checked = true; selectedCard = r.closest('.mechanic-card'); selectedCard.classList.add('selected'); }
    });
    var qt = document.getElementById('quote-tooltip');
    if (!qt) {
        qt = document.createElement('div');
        qt.id = 'quote-tooltip';
        qt.className = 'quote-tooltip above hidden';
        document.body.appendChild(qt);
    }
    if (selectedCard) {
        qt.classList.remove('hidden');
        updateQuotePosition(selectedCard);
        qt.classList.remove('comic-pop');
        void qt.offsetHeight;
        qt.classList.add('comic-pop');
    } else {
        qt.classList.add('hidden');
        qt.classList.remove('comic-pop');
    }
    fetchAvailability();
}

function hideTooltip() {
    var t = document.getElementById('slot-tooltip');
    if (t) t.classList.add('hidden');
}

function showTooltip(el) {
    var slotIndex = parseInt(el.dataset.slot);
    var mechIdEl = document.querySelector('input[name="mechanic_id"]:checked');
    if (!mechIdEl) return;
    var currentMechId = parseInt(mechIdEl.value);
    var date = document.getElementById('date').value;
    var t = document.getElementById('slot-tooltip');
    if (!t) { t = document.createElement('div'); t.id = 'slot-tooltip'; t.className = 'slot-tooltip'; document.body.appendChild(t); }
    var params = new URLSearchParams({ mechanic_id: currentMechId, date: date, slot_index: slotIndex });
    fetch('availability.php?' + params).then(function(r) { return r.json(); }).then(function(data) {
        var html = '<div class="tt-title">' + htmlspecialchars(data.mechanic_first_name) + ' is unavailable at that time. But here are some close alternatives for you:</div>';
        var hasMechSlots = (data.adjacent_slot !== null || data.nearby_prev_date || data.nearby_next_date);
        var hasOtherSlots = false;
        var otherHtml = '';
        if (data.all_slots) {
            Object.keys(data.all_slots).forEach(function(mid) {
                var id = parseInt(mid);
                if (id === currentMechId) return;
                var slots = data.all_slots[mid];
                for (var i = 0; i < slots.length; i++) {
                    if (slots[i].available && slots[i].index === slotIndex) {
                        var name = data.all_names[mid] || ('Mechanic #' + mid);
                        otherHtml += '<button type="button" class="suggestion-chip" onclick="fillSuggestion(' + mid + ', \'' + date + '\', ' + slotIndex + ', true)"><strong>' + htmlspecialchars(name) + '</strong></button>';
                        hasOtherSlots = true;
                        break;
                    }
                }
            });
        }
        if (hasMechSlots) {
            html += '<div class="tt-section">If you want to stick with <strong>' + htmlspecialchars(data.mechanic_nickname) + '</strong>:</div>';
            html += '<div class="tt-chips">';
            if (data.adjacent_slot !== null) {
                html += '<button type="button" class="suggestion-chip" onclick="fillSuggestion(' + currentMechId + ', \'' + date + '\', ' + data.adjacent_slot + ')"><span class="chip-label">' + SLOT_LABELS[data.adjacent_slot] + '</span></button>';
            }
            if (data.nearby_prev_date) {
                html += '<button type="button" class="suggestion-chip" onclick="fillSuggestion(' + currentMechId + ', \'' + data.nearby_prev_date + '\', ' + slotIndex + ')">' + fmtDate(data.nearby_prev_date) + ' <span class="chip-label">' + SLOT_LABELS[slotIndex] + '</span></button>';
            }
            if (data.nearby_next_date) {
                html += '<button type="button" class="suggestion-chip" onclick="fillSuggestion(' + currentMechId + ', \'' + data.nearby_next_date + '\', ' + slotIndex + ')">' + fmtDate(data.nearby_next_date) + ' <span class="chip-label">' + SLOT_LABELS[slotIndex] + '</span></button>';
            }
            html += '</div>';
        }
        if (hasOtherSlots) {
            html += '<div class="tt-section">If you want to stick with the slot:</div>';
            html += '<div class="tt-chips">' + otherHtml + '</div>';
        }
        t.innerHTML = html;
        t._target = el;
        t.classList.remove('hidden');
        var rect = el.getBoundingClientRect();
        var spaceAbove = rect.top;
        var needsBelow = spaceAbove < t.offsetHeight + 8;
        var top = needsBelow ? rect.bottom + 8 : rect.top - t.offsetHeight - 8;
        if (needsBelow) t.classList.add('below'); else t.classList.remove('below');
        var left = Math.min(rect.left + rect.width - 60, window.innerWidth - t.offsetWidth - 10);
        t.style.top = Math.max(4, top) + 'px';
        t.style.left = Math.max(4, left) + 'px';
    }).catch(function() { hideTooltip(); });
}

var _fetchId = 0;
function fetchAvailability() {
    var mechIdEl = document.querySelector('input[name="mechanic_id"]:checked');
    var dateEl = document.getElementById('date');
    var container = document.getElementById('slot-container');
    hideTooltip();
    if (!mechIdEl || !dateEl.value) {
        container.innerHTML = '<p style="font-style:italic;color:#888;">Select a date and mechanic to see slots.</p>';
        updateVacationBadges(dateEl.value);
        return;
    }
    container.innerHTML = '<div class="burst burst-right" style="font-size:0.6rem;position:static;margin:0 auto 8px;animation:popIn 0.2s ease-out;">LOADING!</div><p style="font-style:italic;color:#888;text-align:center;">Checking slots&hellip;</p>';
    updateVacationBadges(dateEl.value);
    var mechParams = new URLSearchParams({ mechanic_id: mechIdEl.value, date: dateEl.value });
    var reqId = ++_fetchId;
    return fetch('availability.php?' + mechParams).then(function(r) { return r.json(); }).then(function(data) {
        if (reqId !== _fetchId) return;
        if (data.error) { container.innerHTML = '<p style="color:var(--rust);font-weight:bold;">' + htmlspecialchars(data.error) + '</p>'; return; }
        var html = '';
        data.slots.forEach(function(slot) {
            var taken = slot.available ? '' : 'taken';
            html += '<div class="slot-chip ' + taken + '" data-slot="' + slot.index + '">' + SLOT_LABELS[slot.index] + '<br><small>' + SLOT_NAMES[slot.index] + '</small></div>';
        });
        container.innerHTML = html;
        container.querySelectorAll('.slot-chip').forEach(function(chip) {
            if (chip.classList.contains('taken')) {
                chip.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var t = document.getElementById('slot-tooltip');
                    if (t && !t.classList.contains('hidden') && t._target === chip) { hideTooltip(); }
                    else { showTooltip(chip); }
                });
            } else {
                chip.addEventListener('click', function() { selectSlot(this, parseInt(this.dataset.slot)); });
            }
        });
    }).catch(function() { if (reqId === _fetchId) container.innerHTML = '<p style="color:var(--rust);font-weight:bold;">Could not load slots.</p>'; });
}

function selectSlot(el, index) {
    document.querySelectorAll('.slot-chip').forEach(function(c) { c.classList.remove('selected'); });
    el.classList.add('selected');
    document.getElementById('slot_index').value = index;
}

function fillSuggestion(mechId, date, slotIndex, scrollToCard) {
    hideTooltip();
    document.getElementById('date').value = date;
    selectMechanic(mechId);
    if (scrollToCard) {
        var card = document.querySelector('.mechanic-card.selected');
        if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    (function pollSlot(slot, tries) {
        var chips = document.querySelectorAll('.slot-chip');
        if (chips.length > 0 || tries <= 0) {
            chips.forEach(function(c) {
                if (parseInt(c.dataset.slot) === slot && !c.classList.contains('taken')) selectSlot(c, slot);
            });
            return;
        }
        setTimeout(function() { pollSlot(slot, tries - 1); }, 100);
    })(slotIndex, 30);
}

/* === TABLE FILTERS === */

function filterAppTable() {
    var name = (document.getElementById('filter-name')?.value || '').toLowerCase();
    var phone = (document.getElementById('filter-phone')?.value || '').toLowerCase();
    var car = (document.getElementById('filter-car')?.value || '').toLowerCase();
    var status = document.getElementById('filter-status')?.value || '';
    var mechanic = (document.getElementById('filter-mechanic')?.value || '').toLowerCase();
    var dateFrom = document.getElementById('filter-date-from')?.value || '';
    var dateTo = document.getElementById('filter-date-to')?.value || '';

    document.querySelectorAll('#appt-table tbody tr[data-name]').forEach(function(tr) {
        var show = true;
        if (name && (tr.dataset.name || '').indexOf(name) === -1) show = false;
        if (show && phone && (tr.dataset.phone || '').indexOf(phone) === -1) show = false;
        if (show && car && (tr.dataset.car || '').indexOf(car) === -1) show = false;
        if (show && status && tr.dataset.status !== status) show = false;
        if (show && mechanic && (tr.dataset.mechanic || '').indexOf(mechanic) === -1) show = false;
        if (show && dateFrom && tr.dataset.date < dateFrom) show = false;
        if (show && dateTo && tr.dataset.date > dateTo) show = false;
        tr.style.display = show ? '' : 'none';
        var editRow = tr.nextElementSibling;
        if (editRow && editRow.classList.contains('edit-row')) editRow.style.display = show ? '' : 'none';
    });
    saveFilterState();
}

function openSearchModal() {
    document.getElementById('search-modal').classList.remove('hidden');
}

function closeSearchModal(event) {
    if (!event || event.target === event.currentTarget) {
        document.getElementById('search-modal').classList.add('hidden');
    }
}

function clearFilters() {
    document.getElementById('filter-name').value = '';
    document.getElementById('filter-phone').value = '';
    document.getElementById('filter-car').value = '';
    document.getElementById('filter-status').selectedIndex = 0;
    document.getElementById('filter-mechanic').selectedIndex = 0;
    document.getElementById('filter-date-from').value = '';
    document.getElementById('filter-date-to').value = '';
    var fromWrap = document.getElementById('filter-date-from').closest('.datepicker-wrap');
    if (fromWrap) fromWrap.querySelector('.datepicker-display').value = '';
    var toWrap = document.getElementById('filter-date-to').closest('.datepicker-wrap');
    if (toWrap) toWrap.querySelector('.datepicker-display').value = '';
    var mechWrap = document.getElementById('filter-mechanic').closest('.custom-select-wrap');
    if (mechWrap) {
        var triggerText = mechWrap.querySelector('.custom-select-trigger-inner .label');
        if (triggerText) triggerText.textContent = 'All Mechanics';
        mechWrap.querySelectorAll('.custom-select-option').forEach(function(o) { o.classList.remove('selected'); });
        var first = mechWrap.querySelector('.custom-select-option');
        if (first) first.classList.add('selected');
    }
    var statusWrap = document.getElementById('filter-status').closest('.custom-select-wrap');
    if (statusWrap) {
        var triggerText2 = statusWrap.querySelector('.custom-select-trigger-inner .label');
        if (triggerText2) triggerText2.textContent = 'All Status';
        statusWrap.querySelectorAll('.custom-select-option').forEach(function(o) { o.classList.remove('selected'); });
        var first2 = statusWrap.querySelector('.custom-select-option');
        if (first2) first2.classList.add('selected');
    }
    filterAppTable();
    try { sessionStorage.removeItem('adminFilters'); } catch(e) {}
    updateFilterCross();
}

var filterAppTableDebounced = debounce(function() {
    filterAppTable();
}, 200);

function saveFilterState() {
    var state = {
        name: document.getElementById('filter-name')?.value || '',
        phone: document.getElementById('filter-phone')?.value || '',
        car: document.getElementById('filter-car')?.value || '',
        status: document.getElementById('filter-status')?.value || '',
        mechanic: (document.getElementById('filter-mechanic')?.value || '').toLowerCase(),
        dateFrom: document.getElementById('filter-date-from')?.value || '',
        dateTo: document.getElementById('filter-date-to')?.value || '',
    };
    try { sessionStorage.setItem('adminFilters', JSON.stringify(state)); } catch(e) {}
    updateFilterCross();
}

function updateFilterCross() {
    var cross = document.getElementById('filter-cross');
    if (!cross) return;
    try {
        var raw = sessionStorage.getItem('adminFilters');
        if (!raw) { cross.style.display = 'none'; return; }
        var state = JSON.parse(raw);
        cross.style.display = (state.name || state.phone || state.car || state.status || state.mechanic || state.dateFrom || state.dateTo) ? 'inline' : 'none';
    } catch(e) { cross.style.display = 'none'; }
}

/* === UNSAVED CHANGES GUARD === */

var _unsavedCb = null;
function confirmUnsaved(callback) {
    var form = document.getElementById('booking-form');
    if (!form) { callback(); return true; }
    var hasVal = false;
    for (var i = 0; i < form.elements.length; i++) {
        var el = form.elements[i];
        if (el.type === 'text' || el.type === 'tel' || el.type === 'textarea' || el.type === 'date') {
            if (el.value.trim()) { hasVal = true; break; }
        }
    }
    if (!hasVal) { callback(); return true; }
    _unsavedCb = callback;
    document.getElementById('unsaved-modal').classList.remove('hidden');
    return false;
}
function confirmUnsavedGo() {
    document.getElementById('unsaved-modal').classList.add('hidden');
    var cb = _unsavedCb; _unsavedCb = null;
    if (cb) cb();
}
function cancelUnsaved() {
    document.getElementById('unsaved-modal').classList.add('hidden');
    _unsavedCb = null;
}

/* === ADMIN === */

var _pendingAction = '';
var _pendingForm = null;
var _pendingCallback = null;
var _origEditValues = null;
var _hadUnsavedChanges = false;

/* === STEPPER === */

function initNumStepper(input) {
    var _isInput = input.dataset.stepper === 'edit';
    var _setting = false;
    var _wrap = input.dataset.stepperWrap !== undefined;
    var _padLen = parseInt(input.dataset.stepperPad, 10) || 0;
    function pad(v) { return _padLen ? String(parseInt(v, 10) || 0).padStart(_padLen, '0') : String(v); }

    var val = _isInput ? document.createElement('input') : document.createElement('span');
    val.className = 'num-step-value';
    if (_isInput) { val.type = 'text'; val.inputMode = 'numeric'; val.value = input.value; }
    else { val.textContent = input.value; }
    var _val = pad(input.value);
    var _nativeValueSetter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
    Object.defineProperty(input, 'value', {
        get: function() { return _val; },
        set: function(v) {
            _val = pad(v);
            _nativeValueSetter.call(input, _val);
            if (!val) return;
            if (_isInput) { if (!_setting) { _setting = true; val.value = _val; _setting = false; } }
            else { val.textContent = _val; }
        },
        configurable: true
    });

    var btns = document.createElement('div');
    btns.className = 'num-step-btns';

    function clickBtn(dir) {
        if (input.readOnly || input.disabled) {
            if (input.onclick) input.onclick.call(input);
            return;
        }
        var step = parseFloat(input.step) || 1;
        var min = input.min !== '' ? parseFloat(input.min) : -Infinity;
        var max = input.max !== '' ? parseFloat(input.max) : Infinity;
        var v = parseFloat(input.value) || 0;
        v = v + dir * step;
        if (_wrap) {
            if (v > max) v = min;
            if (v < min) v = max;
        } else {
            v = Math.max(min, Math.min(max, v));
        }
        input.value = v;
        var evt = new Event('change', { bubbles: true });
        input.dispatchEvent(evt);
    }

    var up = document.createElement('button');
    up.type = 'button';
    up.className = 'num-step-btn';
    up.innerHTML = '▲';
    up.onclick = function() { clickBtn(1); };

    var down = document.createElement('button');
    down.type = 'button';
    down.className = 'num-step-btn';
    down.innerHTML = '▼';
    down.onclick = function() { clickBtn(-1); };

    btns.appendChild(up);
    btns.appendChild(down);

    var wrap = document.createElement('div');
    wrap.className = 'num-stepper';
    wrap.appendChild(val);
    wrap.appendChild(btns);

    input._stepperWrap = wrap;
    input.style.display = 'none';
    input.parentNode.insertBefore(wrap, input.nextSibling);

    if (_isInput) {
        val.addEventListener('input', function() {
            if (_setting) return;
            var n = parseFloat(val.value);
            if (!isNaN(n) && val.value !== '') {
                var min = input.min !== '' ? parseFloat(input.min) : -Infinity;
                var max = input.max !== '' ? parseFloat(input.max) : Infinity;
                n = Math.max(min, Math.min(max, n));
                if (n !== parseFloat(input.value)) { input.value = n; }
            }
        });
        val.addEventListener('blur', function() {
            var n = parseFloat(val.value);
            if (isNaN(n) || val.value === '') {
                n = input.min !== '' ? parseFloat(input.min) : 0;
            }
            var min = input.min !== '' ? parseFloat(input.min) : -Infinity;
            var max = input.max !== '' ? parseFloat(input.max) : Infinity;
            n = Math.max(min, Math.min(max, n));
            input.value = n;
        });
        val.addEventListener('focus', function() {
            if (input.readOnly && input.onclick) input.onclick.call(input);
        });
        val.addEventListener('wheel', function(e) { e.preventDefault(); clickBtn(e.deltaY > 0 ? -1 : 1); });
    }
    val.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowUp') { e.preventDefault(); clickBtn(1); }
        if (e.key === 'ArrowDown') { e.preventDefault(); clickBtn(-1); }
    });
    if (!_isInput) val.tabIndex = 0;
}

function updateStepperBg(input) {
    var wrap = input._stepperWrap;
    if (!wrap) return;
    var ro = input.readOnly;
    var bg = ro ? 'var(--paper)' : 'var(--cyan)';
    wrap.style.background = bg;
    var v = wrap.querySelector('.num-step-value');
    if (v) { v.style.background = bg; v.readOnly = ro; }
}

var _pendingField = null;
var _pendingNewTab = false;

/* === PASSWORD MODAL === */

function checkSimGuard() {
    if (typeof SIM_MODE_ACTIVE !== 'undefined' && SIM_MODE_ACTIVE) {
        document.getElementById('sim-block-modal').classList.remove('hidden');
        return false;
    }
    return true;
}
function closeSimBlockModal(event) {
    if (!event || event.target === event.currentTarget) {
        document.getElementById('sim-block-modal').classList.add('hidden');
    }
}
function requirePw(actionOrCallback, guardSim) {
    if (guardSim !== false && !checkSimGuard()) return;
    if (typeof actionOrCallback === 'function') {
        _pendingCallback = actionOrCallback; _pendingAction = ''; _pendingForm = null; _pendingField = null; _pendingNewTab = false;
    } else {
        _pendingAction = actionOrCallback; _pendingForm = null; _pendingField = null; _pendingNewTab = false; _pendingCallback = null;
    }
    openPwModal();
}
function requirePwNewTab(actionUrl) {
    _pendingAction = actionUrl; _pendingForm = null; _pendingField = null; _pendingNewTab = true; openPwModal();
}
function requirePwForForm(form) {
    _pendingForm = form; _pendingAction = ''; _pendingField = null; openPwModal(); return false;
}
function requirePwForField(fieldId) {
    var field = document.getElementById(fieldId);
    if (!field.readOnly) return;
    _pendingField = field; _pendingAction = ''; _pendingForm = null; openPwModal();
}
function openPwModal() {
    document.getElementById('admin-pw-input').value = '';
    document.getElementById('admin-pw-input').type = 'password';
    document.getElementById('pw-toggle').innerHTML = '<img src="images/doodles/eye-closed.svg" alt="Show password" style="display:block;">';
    document.getElementById('pw-error').style.display = 'none';
    document.getElementById('pw-modal').classList.remove('hidden');
    document.getElementById('admin-pw-input').focus();
}
function togglePwVisibility() {
    var input = document.getElementById('admin-pw-input');
    var btn = document.getElementById('pw-toggle');
    if (input.type === 'password') {
        input.type = 'text';
        btn.innerHTML = '<img src="images/doodles/eye-open.svg" alt="Hide password" style="display:block;">';
    } else {
        input.type = 'password';
        btn.innerHTML = '<img src="images/doodles/eye-closed.svg" alt="Show password" style="display:block;">';
    }
}
function confirmPw() {
    var pw = document.getElementById('admin-pw-input').value;
    var btn = document.querySelector('#pw-modal .modal-btn-row .btn-pink');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed'; }
    function restoreBtn() { if (btn) { btn.disabled = false; btn.style.opacity = ''; btn.style.cursor = ''; } }
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        restoreBtn();
        try { var resp = JSON.parse(xhr.responseText); } catch(e) { document.getElementById('pw-error').textContent = 'Invalid server response.'; document.getElementById('pw-error').style.display = 'block'; return; }
        if (resp.success) {
            document.getElementById('pw-modal').classList.add('hidden');
            if (_pendingField) {
                ['modal-mech-name', 'modal-mech-exp'].forEach(function(id) {
                    var f = document.getElementById(id);
                    if (f && f.readOnly) { f.readOnly = false; f.style.cursor = 'text'; f.style.backgroundColor = ''; updateStepperBg(f); }
                });
                var tgt = _pendingField._stepperWrap
                    ? _pendingField._stepperWrap.querySelector('.num-step-value')
                    : _pendingField;
                if (tgt) tgt.focus();
                _pendingField = null;
            } else if (_pendingCallback) {
                _pendingCallback();
                _pendingCallback = null;
            } else if (_pendingAction) {
                if (_pendingNewTab) {
                    window.open(_pendingAction, '_blank');
                    _pendingNewTab = false;
                } else {
                    window.location.href = _pendingAction;
                }
            } else if (_pendingForm) {
                var btn = _pendingForm.querySelector('button[type="submit"][name]');
                if (btn) {
                    var h = document.createElement('input');
                    h.type = 'hidden'; h.name = btn.name; h.value = '';
                    _pendingForm.appendChild(h);
                }
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = 'admin_pw'; input.value = pw;
                _pendingForm.appendChild(input);
                _pendingForm.submit();
            }
        } else {
            document.getElementById('pw-error').style.display = 'block';
            document.getElementById('admin-pw-input').focus();
        }
    };
    xhr.onerror = function() {
        restoreBtn();
        document.getElementById('pw-error').textContent = 'Network error. Try again.';
        document.getElementById('pw-error').style.display = 'block';
    };
    xhr.onloadend = restoreBtn;
    xhr.send('verify_pw=1&admin_pw=' + encodeURIComponent(pw));
}
function closePwModal() {
    document.getElementById('pw-modal').classList.add('hidden');
    _pendingAction = ''; _pendingForm = null; _pendingField = null; _pendingNewTab = false; _pendingCallback = null;
}

/* === TOGGLES === */

function toggleEdit(id, btn) {
    var el = document.getElementById('edit-' + id);
    el.classList.toggle('show');
    btn.textContent = el.classList.contains('show') ? 'Hide' : 'Edit';
}
function toggleOverrides() {
    var panel = document.getElementById('overrides-panel');
    var btn = document.getElementById('overrides-toggle');
    var open = panel.style.display !== 'none';
    panel.style.display = open ? 'none' : 'block';
    btn.textContent = open ? 'Show All Blocks' : 'Hide All Blocks';
}
/* === MECHANIC MODAL === */

function openMechModal(btn) {
    ['modal-mech-name', 'modal-mech-exp'].forEach(function(id) {
        var f = document.getElementById(id);
        if (f) { f.readOnly = true; f.style.cursor = 'pointer'; f.style.background = 'var(--paper)'; updateStepperBg(f); }
    });
    document.getElementById('modal-mech-id').value = btn.dataset.mid;
    document.getElementById('modal-mech-name').value = btn.dataset.mname;
    document.getElementById('modal-mech-nickname').value = btn.dataset.mnick;
    document.getElementById('modal-mech-quote').value = btn.dataset.mquote;
    document.getElementById('modal-mech-specialties').value = btn.dataset.mspec;
    document.getElementById('modal-mech-exp').value = btn.dataset.experience;
    renderVacations(parseInt(btn.dataset.mid), btn.dataset.mname);
    ['vac-start', 'vac-end'].forEach(function(id) {
        var picker = DatePicker.instances.find(function(p) { return p.input.id === id; });
        if (picker) picker.clear(); else document.getElementById(id).value = '';
    });
    document.getElementById('vac-reason').value = '';
    var ve = document.getElementById('vac-error'); if (ve) ve.style.display = 'none';
    _origEditValues = { name: btn.dataset.mname, nickname: btn.dataset.mnick, quote: btn.dataset.mquote, specialties: btn.dataset.mspec, experience: btn.dataset.experience };
    document.getElementById('mech-modal').classList.remove('hidden');
}
function openMechModalById(id, name, nickname, quote, specialties, experience) {
    ['modal-mech-name', 'modal-mech-exp'].forEach(function(fid) {
        var f = document.getElementById(fid);
        if (f) { f.readOnly = true; f.style.cursor = 'pointer'; f.style.background = 'var(--paper)'; updateStepperBg(f); }
    });
    document.getElementById('modal-mech-id').value = id;
    document.getElementById('modal-mech-name').value = name;
    document.getElementById('modal-mech-nickname').value = nickname;
    document.getElementById('modal-mech-quote').value = quote;
    document.getElementById('modal-mech-specialties').value = specialties;
    document.getElementById('modal-mech-exp').value = experience;
    renderVacations(parseInt(id), name);
    ['vac-start', 'vac-end'].forEach(function(id) {
        var picker = DatePicker.instances.find(function(p) { return p.input.id === id; });
        if (picker) picker.clear(); else document.getElementById(id).value = '';
    });
    document.getElementById('vac-reason').value = '';
    var ve = document.getElementById('vac-error'); if (ve) ve.style.display = 'none';
    _origEditValues = { name: name, nickname: nickname, quote: quote, specialties: specialties, experience: String(experience) };
    document.getElementById('mech-modal').classList.remove('hidden');
}
function closeMechModal(event) {
    if (event.target === event.currentTarget) {
        ['modal-mech-name', 'modal-mech-exp'].forEach(function(id) {
            var f = document.getElementById(id);
            if (f) { f.readOnly = true; f.style.cursor = 'pointer'; f.style.background = 'var(--paper)'; updateStepperBg(f); }
        });
        document.getElementById('mech-modal').classList.add('hidden');
        if (window._newHireName) {
            var n = window._newHireName;
            window._newHireName = null;
            window.location.href = 'admin.php?msg=' + encodeURIComponent(n + ' has been hired!');
        }
    }
}
/* === SCHEDULE MODAL === */

function hasUnsavedEditChanges() {
    return _origEditValues && (document.getElementById('modal-mech-name').value !== _origEditValues.name
        || document.getElementById('modal-mech-nickname').value !== _origEditValues.nickname
        || document.getElementById('modal-mech-quote').value !== _origEditValues.quote
        || document.getElementById('modal-mech-specialties').value !== _origEditValues.specialties
        || document.getElementById('modal-mech-exp').value !== _origEditValues.experience);
}
function openScheduleModal(id, name) {
    if (!checkSimGuard()) return;
    _hadUnsavedChanges = hasUnsavedEditChanges();
    document.getElementById('mech-modal').classList.add('hidden');
    document.getElementById('schedule-mech-id').value = id;
    document.getElementById('schedule-mech-name').textContent = 'Schedule — ' + name;
    document.getElementById('sched-mech-name').value = document.getElementById('modal-mech-name').value;
    document.getElementById('sched-mech-nickname').value = document.getElementById('modal-mech-nickname').value;
    document.getElementById('sched-mech-quote').value = document.getElementById('modal-mech-quote').value;
    document.getElementById('sched-mech-specialties').value = document.getElementById('modal-mech-specialties').value;
    document.getElementById('sched-mech-years').value = document.getElementById('modal-mech-exp').value;
    var cbs = document.querySelectorAll('#schedule-form .sched-cb');
    cbs.forEach(function(cb) { cb.checked = false; });
    var sched = SCHEDULE_DATA[id] || {};
    cbs.forEach(function(cb) { var dow = parseInt(cb.dataset.dow); var slot = parseInt(cb.dataset.slot); if (sched[dow] && sched[dow][slot]) cb.checked = true; });
    document.getElementById('schedule-modal').classList.remove('hidden');
}
function saveScheduleCheck() {
    if (!checkSimGuard()) return;
    if (_hadUnsavedChanges) {
        document.getElementById('schedule-confirm-modal').classList.remove('hidden');
    } else {
        document.getElementById('schedule-form').submit();
    }
}
function submitScheduleForm(useOriginal) {
    if (!checkSimGuard()) return;
    document.getElementById('schedule-confirm-modal').classList.add('hidden');
    document.getElementById('sched-saved-both').value = useOriginal ? '' : '1';
    if (useOriginal && _origEditValues) {
        document.getElementById('sched-mech-name').value = _origEditValues.name;
        document.getElementById('sched-mech-nickname').value = _origEditValues.nickname;
        document.getElementById('sched-mech-quote').value = _origEditValues.quote;
        document.getElementById('sched-mech-specialties').value = _origEditValues.specialties;
        document.getElementById('sched-mech-years').value = _origEditValues.experience;
    }
    document.getElementById('schedule-form').submit();
}
function toggleUpdateApptBtn(el) {
    var form = el.closest('form');
    var btn = form.querySelector('[name="update_appointment"]');
    var dateInput = form.querySelector('[name="new_date"]');
    var slotSelect = form.querySelector('[name="new_slot"]');
    var mechSelect = form.querySelector('[name="new_mechanic"]');
    var changed = dateInput.value !== dateInput.dataset.originalDate
               || parseInt(slotSelect.value) !== parseInt(slotSelect.dataset.originalSlot)
               || parseInt(mechSelect.value) !== parseInt(mechSelect.dataset.originalMechanic);
    btn.disabled = !changed;
    btn.classList.toggle('disabled', !changed);
}
function closeScheduleModal(event) { if (event.target === event.currentTarget) { document.getElementById('schedule-modal').classList.add('hidden'); document.getElementById('mech-modal').classList.remove('hidden'); } }
function validateOverrideForm() {
    if (!checkSimGuard()) return false;
    var err = document.getElementById('override-error');
    var mech = document.querySelector('[name="override_mechanic"]').value;
    var date = document.querySelector('[name="override_date"]').value;
    var slots = document.querySelectorAll('[name="slots[]"]:checked');
    if (!mech) { err.textContent = 'Select a mechanic first.'; err.style.display = 'block'; return false; }
    if (!date) { err.textContent = 'Select a date first.'; err.style.display = 'block'; return false; }
    if (date < TODAY) { err.textContent = 'Date cannot be in the past.'; err.style.display = 'block'; return false; }
    if (slots.length === 0) { err.textContent = 'Block at least one slot.'; err.style.display = 'block'; return false; }
    var mechId = parseInt(mech);
    var sched = SCHEDULE_DATA[mechId];
    var dow = new Date(date + 'T00:00:00').getDay();
    var mechName = document.querySelector('[name="override_mechanic"] option:checked').textContent;
    if (sched && !sched[dow]) { document.getElementById('action-fail-heading').textContent = 'NOPE!'; document.getElementById('action-fail-msg').textContent = mechName + ' does not work on ' + DAY_NAMES[dow] + ' — no override needed.'; document.getElementById('action-fail-modal').classList.remove('hidden'); return false; }
    var vacs = VACATION_DATA[mechId] || [];
    for (var vi = 0; vi < vacs.length; vi++) {
        if (date >= vacs[vi].start_date && date <= vacs[vi].end_date) { document.getElementById('action-fail-heading').textContent = 'NOPE!'; document.getElementById('action-fail-msg').textContent = mechName + ' is on vacation — no override needed.'; document.getElementById('action-fail-modal').classList.remove('hidden'); return false; }
    }
    if (sched && sched[dow]) {
        var slotVals = [];
        slots.forEach(function(cb) { slotVals.push(parseInt(cb.value)); });
        var blocked = [];
        for (var si = 0; si < slotVals.length; si++) {
            if (!sched[dow][slotVals[si]]) blocked.push(slotVals[si] + 1);
        }
        if (blocked.length > 0) { document.getElementById('action-fail-heading').textContent = 'NOPE!'; document.getElementById('action-fail-msg').textContent = mechName + ' is not scheduled for slot(s) ' + blocked.join(', ') + ' on ' + DAY_NAMES[dow] + ' — cannot block them.'; document.getElementById('action-fail-modal').classList.remove('hidden'); return false; }
    }
    err.style.display = 'none';
    return true;
}
function clearOverrideError() {
    document.getElementById('override-error').style.display = 'none';
}

function validateRecruitForm() {
    if (!checkSimGuard()) return false;
    var name = document.querySelector('[name="mech_name"]');
    if (!name.value.trim()) {
        name.focus();
        name.classList.add('shake');
        showBurstOver(name);
        setTimeout(function() { name.classList.remove('shake'); }, 600);
        return false;
    }
    return true;
}

function showBurstOver(el) {
    var old = document.getElementById('recruit-burst');
    if (old) old.remove();
    var bursts = ['blank', 'zilch', 'nada', 'bzzt', 'nope'];
    var pick = bursts[Math.floor(Math.random() * bursts.length)];
    var r = el.getBoundingClientRect();
    var d = document.createElement('div');
    d.id = 'recruit-burst';
    d.className = 'error-burst burst-' + pick + ' active';
    d.style.cssText = 'position:fixed;width:125px;height:125px;left:' + (r.left + r.width/2 - 62.5) + 'px;top:' + (r.top + r.height/2 - 62.5) + 'px;z-index:300;pointer-events:none;';
    document.body.appendChild(d);
    setTimeout(function() { if (d.parentNode) d.remove(); }, 1500);
}

/* === CONFIRMATION MODALS === */

function showCancelModal(id) {
    document.getElementById('cancel-modal').classList.remove('hidden');
    document.getElementById('cancel-confirm-btn').onclick = function() { document.getElementById('cancel-modal').classList.add('hidden'); requirePw(function(){ cancelAppointmentAjax(id); }, false); };
}
function closeCancelModal(event) { if (event.target === event.currentTarget) document.getElementById('cancel-modal').classList.add('hidden'); }
function showFireModal(id, name, count) { _pendingAction = '?fire=' + id; document.getElementById('fire-modal-title').textContent = 'Fire ' + name + '?'; var el = document.getElementById('fire-modal-cancel-count'); if (count > 0) { el.textContent = count + ' booking' + (count > 1 ? 's' : '') + ' will be cancelled.'; el.style.display = 'block'; } else { el.style.display = 'none'; } document.getElementById('fire-modal').classList.remove('hidden'); }
function closeFireModal(event) { if (event.target === event.currentTarget) document.getElementById('fire-modal').classList.add('hidden'); }
function showRemoveModal(id) {
    document.getElementById('remove-modal').classList.remove('hidden');
    document.getElementById('remove-confirm-btn').onclick = function() { document.getElementById('remove-modal').classList.add('hidden'); requirePw(function(){ removeAppointmentAjax(id); }); };
}
function closeRemoveModal(event) { if (event.target === event.currentTarget) document.getElementById('remove-modal').classList.add('hidden'); }
function showUnblockModal(id, name, date) {
    document.getElementById('unblock-msg').textContent = 'Unblock ' + name + ' on ' + date + '?';
    document.getElementById('unblock-modal').classList.remove('hidden');
    document.getElementById('unblock-confirm-btn').onclick = function() { document.getElementById('unblock-modal').classList.add('hidden'); requirePw(function(){ unblockOverrideAjax(id); }); };
}
function closeUnblockModal(event) { if (event.target === event.currentTarget) document.getElementById('unblock-modal').classList.add('hidden'); }
/* === VACATIONS === */

function renderVacations(id, mechName) {
    var list = document.getElementById('vacation-list');
    list.innerHTML = '';
    var vacs = VACATION_DATA[id] || [];
    if (vacs.length === 0) {
        list.innerHTML = '<p style="font-size:0.85rem;opacity:0.7;">No vacations scheduled.</p>';
    } else {
        var html = '';
        vacs.forEach(function(v) {
            var label = htmlspecialchars(fmtDate(v.start_date) + ' — ' + fmtDate(v.end_date));
            if (v.reason) label += ' (' + htmlspecialchars(v.reason) + ')';
            html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;padding:4px 8px;background:var(--cyan);border:2px solid var(--ink);font-size:0.8rem;">';
            html += '<span style="flex:1;">' + label + '</span>';
            html += '<button type="button" class="btn btn-sm btn-rust" style="font-size:0.65rem;padding:2px 8px;" data-vac-id="' + v.id + '" data-mech-name="' + htmlspecialchars(mechName || '') + '" onclick="removeVacation(this)">End</button>';
            html += '</div>';
        });
        list.innerHTML = html;
    }
}
function addVacation() {
    if (!checkSimGuard()) return;
    var err = document.getElementById('vac-error');
    if (err) err.style.display = 'none';
    var id = document.getElementById('modal-mech-id').value;
    var start = document.getElementById('vac-start').value;
    var end = document.getElementById('vac-end').value;
    if (!err) {
        err = document.createElement('div');
        err.id = 'vac-error';
        err.className = 'field-error';
        document.getElementById('vac-end').parentNode.appendChild(err);
    }
    if (!start || !end) {
        err.textContent = 'Please select both start and end dates.';
        err.style.display = 'block';
        return;
    }
    if (start < TODAY) {
        err.textContent = 'Vacation cannot start in the past.';
        err.style.display = 'block';
        return;
    }
    if (start > end) {
        err.textContent = 'Start date must be before end date.';
        err.style.display = 'block';
        return;
    }
    var vacs = VACATION_DATA[id] || [];
    var sched = SCHEDULE_DATA[id];
    var hasAvailability = false;
    if (sched) {
        for (var dow in sched) {
            if (sched.hasOwnProperty(dow) && sched[dow]) {
                for (var si = 0; si < sched[dow].length; si++) {
                    if (sched[dow][si]) { hasAvailability = true; break; }
                }
            }
            if (hasAvailability) break;
        }
    }
    if (!hasAvailability) {
        var mechName = document.getElementById('modal-mech-name') ? document.getElementById('modal-mech-name').value : 'This mechanic';
        document.getElementById('vac-limit-heading').textContent = 'No Schedule — No Vacation!';
        document.getElementById('vac-limit-msg').innerHTML = mechName + ' has zero availability on the books — can\'t send \'em packing if they\'ve never turned a wrench! Set up a schedule via the <strong>Schedule</strong> button first.';
        document.getElementById('vac-limit-modal').classList.remove('hidden');
        return;
    }
    if (vacs.length >= 3) { document.getElementById('vac-limit-heading').textContent = 'Maximum Vacations Reached'; document.getElementById('vac-limit-msg').innerHTML = 'A mechanic can only have <strong>3 active vacations</strong> at a time. Let them actually work once in a while!'; document.getElementById('vac-limit-modal').classList.remove('hidden'); return; }
    for (var vi = 0; vi < vacs.length; vi++) {
        if (start <= vacs[vi].end_date && end >= vacs[vi].start_date) {
            var mechName = document.getElementById('modal-mech-name') ? document.getElementById('modal-mech-name').value : 'This mechanic';
            document.getElementById('vac-limit-heading').textContent = 'Whoa there!';
            document.getElementById('vac-limit-msg').innerHTML = mechName + ' is already soaking up the sun that week — try different dates!';
            document.getElementById('vac-limit-modal').classList.remove('hidden');
            return;
        }
    }
    var reason = document.getElementById('vac-reason').value;
    var fd = new FormData();
    fd.set('add_vacation', '1');
    fd.set('vac_mech_id', id);
    fd.set('vac_start', start);
    fd.set('vac_end', end);
    fd.set('vac_reason', reason);
    if (window._newHireName) fd.set('_new_hire_name', window._newHireName);
    fetch('', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                VACATION_DATA[id] = VACATION_DATA[id] || [];
                var vac = d.vacation || { id: Date.now(), start_date: start, end_date: end, reason: reason || null };
                VACATION_DATA[id].push(vac);
                VACATION_DATA[id].sort(function(a, b) { return a.start_date < b.start_date ? -1 : 1; });
                renderVacations(parseInt(id), document.getElementById('modal-mech-name').value);
                ['vac-start', 'vac-end'].forEach(function(x) {
                    var p = DatePicker.instances.find(function(p) { return p.input.id === x; });
                    if (p) p.clear(); else document.getElementById(x).value = '';
                });
                document.getElementById('vac-reason').value = '';
                showModal(d.msg, 'success');
            } else {
                showModal(d.msg, 'error');
            }
        })
        .catch(function() { showModal('Could not reach server.', 'error'); });
}
function closeConflictModal(event) { if (event.target === event.currentTarget) document.getElementById('conflict-modal').classList.add('hidden'); }
function closeMsgModal(event) { if (event.target === event.currentTarget) document.getElementById('msg-modal').classList.add('hidden'); }

/* === AJAX ACTIONS === */

function ajaxGet(url, cb) {
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(d) { if (cb) cb(d); })
        .catch(function() { showModal('Could not reach server.', 'error'); });
}

function removeVacation(btn) {
    if (!checkSimGuard()) return;
    var id = parseInt(btn.dataset.vacId);
    var mechName = btn.dataset.mechName;
    ajaxGet('?remove_vacation=' + id + '&mech_name=' + encodeURIComponent(mechName || ''), function(d) {
        if (d.ok) {
            var mechId = parseInt(document.getElementById('modal-mech-id').value);
            var vacs = VACATION_DATA[mechId] || [];
            for (var i = 0; i < vacs.length; i++) {
                if (vacs[i].id == id) { vacs.splice(i, 1); break; }
            }
            renderVacations(mechId, document.getElementById('modal-mech-name').value);
            var mechRow = document.querySelector('tr[data-mech-id="' + mechId + '"]');
            if (mechRow) {
                var statusCell = mechRow.querySelectorAll('td')[4];
                if (statusCell && statusCell.textContent.trim() === 'On Leave') {
                    var stillOnLeave = false;
                    var today = EFFECTIVE_DATE;
                    var remaining = VACATION_DATA[mechId] || [];
                    for (var j = 0; j < remaining.length; j++) {
                        if (remaining[j].start_date <= today && remaining[j].end_date >= today) {
                            stillOnLeave = true;
                            break;
                        }
                    }
                    if (!stillOnLeave) {
                        statusCell.innerHTML = '<span class="status-badge status-scheduled">Active</span>';
                    }
                }
            }
            showModal(d.msg, 'success');
        } else {
            showModal(d.msg, 'error');
        }
    });
}

function rehireMechanic(id, name, el) {
    ajaxGet('?restore=' + id, function(d) {
        if (d.ok) {
            showModal(d.msg, 'success');
            var tr = el.closest('tr');
            if (!tr) return;
            tr.dataset.mechId = id;
            var tdStatus = tr.querySelectorAll('td')[4];
            var onLeave = false;
            var today = EFFECTIVE_DATE;
            var vacs = VACATION_DATA[id] || [];
            for (var j = 0; j < vacs.length; j++) {
                if (vacs[j].start_date <= today && vacs[j].end_date >= today) { onLeave = true; break; }
            }
            tdStatus.innerHTML = onLeave ? '<span class="status-badge" style="background:var(--gold);color:var(--ink);white-space:nowrap;">On Leave</span>' : '<span class="status-badge status-scheduled">Active</span>';
            var tdActions = tr.querySelectorAll('td')[5];
            var booked = d.mechanic ? d.mechanic.bookings : 0;
            var m = d.mechanic || {};
            tdActions.innerHTML =
                '<button class="btn btn-sm btn-outline" onclick="openMechModal(this)" data-mid="' + id + '" data-mname="' + htmlspecialchars(m.name || name) + '" data-mnick="' + htmlspecialchars(m.nickname || '') + '" data-mquote="' + htmlspecialchars(m.quote || '') + '" data-mspec="' + htmlspecialchars(m.specialties || '') + '" data-experience="' + m.experience + '">Edit</button> '
                + '<button type="button" class="btn btn-sm btn-rust" data-bookings="' + booked + '" onclick="showFireModal(' + id + ', \'' + htmlspecialchars(name) + '\', this.dataset.bookings)">Fire</button>';
        } else {
            showModal(d.msg, 'error');
        }
    });
}

function removeMechanicAjax(id) {
    ajaxGet('?remove_mechanic=' + id, function(d) {
        if (d.ok) {
            showModal(d.msg, 'success');
            var tr = document.querySelector('tr[data-mech-id="' + id + '"]');
            if (tr) tr.remove();
        } else {
            showModal(d.msg, 'error');
        }
    });
}

function cancelAppointmentAjax(id) {
    ajaxGet('?cancel=' + id, function(d) {
        if (d.ok) {
            showModal(d.msg, 'success');
            var tr = document.querySelector('#appt-table tbody tr[data-appt-id="' + id + '"]');
            if (tr) {
                var tdActions = tr.querySelectorAll('td')[7];
                tdActions.innerHTML = '<button type="button" class="btn btn-sm btn-jade" onclick="rebookCheck(this, ' + id + ')">Rebook</button> <button type="button" class="btn btn-sm btn-rust" onclick="showRemoveModal(' + id + ')">Remove</button>';
                var tdStatus = tr.querySelector('.status-badge');
                if (tdStatus) { tdStatus.className = 'status-badge status-cancelled'; tdStatus.textContent = 'cancelled'; }
                tr.dataset.status = 'cancelled';
            }
        } else {
            showModal(d.msg, 'error');
        }
    });
}

function removeAppointmentAjax(id) {
    ajaxGet('?remove=' + id, function(d) {
        if (d.ok) {
            showModal(d.msg, 'success');
            var tr = document.querySelector('#appt-table tbody tr[data-appt-id="' + id + '"]');
            if (tr) {
                var editRow = tr.nextElementSibling;
                if (editRow && editRow.classList.contains('edit-row')) editRow.remove();
                tr.remove();
            }
        } else {
            showModal(d.msg, 'error');
        }
    });
}

function removeAllByStatus(status) {
    var url = '?remove_all_' + status;
    var btnIds = { cancelled: 'clear-cancelled-btn', completed: 'archive-completed-btn' };
    ajaxGet(url, function(d) {
        if (d.ok) {
            showModal(d.msg, 'success');
            var rows = document.querySelectorAll('#appt-table tbody tr[data-status="' + status + '"]');
            rows.forEach(function(r) {
                var editRow = r.nextElementSibling;
                if (editRow && editRow.classList.contains('edit-row')) editRow.remove();
                r.remove();
            });
            var btn = document.getElementById(btnIds[status]);
            if (btn) btn.style.display = 'none';
        } else {
            showModal(d.msg, 'error');
        }
    });
}

function unblockOverrideAjax(id) {
    ajaxGet('?unblock=' + id, function(d) {
        if (d.ok) {
            showModal(d.msg, 'success');
            var tr = document.querySelector('#overrides-panel table tbody tr[data-override-id="' + id + '"]');
            if (tr) tr.remove();
            var remaining = document.querySelectorAll('#overrides-panel table tbody tr').length;
            if (remaining === 0) {
                document.getElementById('overrides-panel').style.display = 'none';
                var toggle = document.getElementById('overrides-toggle');
                if (toggle) { toggle.textContent = 'Show All Blocks'; }
            }
        } else {
            showModal(d.msg, 'error');
        }
    });
}

function showModal(msg, type) {
    var m = document.getElementById('msg-modal');
    m.querySelector('.modal-box').className = 'modal-box msg-box msg-' + type;
    document.getElementById('msg-modal-burst').style.display = type === 'error' ? '' : 'none';
    document.getElementById('msg-modal-content').textContent = msg;
    m.classList.remove('hidden');
}

function rebookCheck(btn, id) {
    var date = btn.closest('tr').dataset.date;
    var today = EFFECTIVE_TIME.split(' ')[0];
    if (date < today) {
        document.getElementById('action-fail-heading').textContent = 'Ghost!';
        document.getElementById('action-fail-msg').textContent = 'Can\'t rebook a ghost — that date\'s already in the rearview.';
        document.getElementById('action-fail-modal').classList.remove('hidden');
        return;
    }
    if (date === today) {
        var slot = parseInt(btn.closest('tr').dataset.slot);
        var currentHour = parseInt(EFFECTIVE_TIME.split(' ')[1].split(':')[0]);
        if (currentHour >= (slot + 5) * 2) {
            document.getElementById('action-fail-heading').textContent = 'Too Slow';
            document.getElementById('action-fail-msg').textContent = 'Too slow — that time slot already drove off without you.';
            document.getElementById('action-fail-modal').classList.remove('hidden');
            return;
        }
    }
    requirePw(function() { rebookAppointmentAjax(id); }, false);
}

function rebookAppointmentAjax(id) {
    ajaxGet('?rebook=' + id, function(d) {
        if (d.redirect) {
            window.location.href = d.redirect;
        } else if (d.ok) {
            updateApptRowScheduled(id);
            showModal(d.msg, 'success');
        } else {
            showModal(d.msg, 'error');
        }
    });
}

function updateApptRowScheduled(id) {
    var tr = document.querySelector('#appt-table tbody tr[data-appt-id="' + id + '"]');
    if (!tr) return;
    tr.dataset.status = 'scheduled';
    var tdBadge = tr.querySelector('.status-badge');
    if (tdBadge) {
        tdBadge.className = 'status-badge status-scheduled';
        tdBadge.textContent = 'scheduled';
    }
    var tdActions = tr.querySelectorAll('td')[7];
    if (tdActions) {
        tdActions.innerHTML = '<button class="btn btn-sm btn-outline" onclick="toggleEdit(' + id + ', this)">Edit</button> <button type="button" class="btn btn-sm btn-rust" onclick="showCancelModal(' + id + ')">Cancel</button>';
    }
}

/* === QUICK BOOK === */

function closeQbFailModal(event) {
    if (!event || event.target === event.currentTarget) document.getElementById('qb-fail-modal').classList.add('hidden');
}

function openQuickBook() {
    document.getElementById('qb-phone-input').value = '';
    document.getElementById('qb-phone-error').style.display = 'none';
    document.getElementById('qb-phone-modal').classList.remove('hidden');
    setTimeout(function() { document.getElementById('qb-phone-input').focus(); }, 100);
}

function lookupQuickBook() {
    var phone = document.getElementById('qb-phone-input').value.trim();
    var err = document.getElementById('qb-phone-error');
    if (!phone) {
        err.textContent = 'Please enter a phone number.';
        err.style.display = 'block';
        return;
    }
    err.style.display = 'none';
    fetch('availability.php?action=quickbook&phone=' + encodeURIComponent(phone))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.found) {
                document.getElementById('qb-phone-modal').classList.add('hidden');
                document.getElementById('qb-fail-msg').textContent = data.message || 'That number ain\'t in our grease-stained ledger, pal. First time? Fill out the form above.';
                document.getElementById('qb-fail-modal').classList.remove('hidden');
                return;
            }
            document.getElementById('qb-phone-modal').classList.add('hidden');
            document.getElementById('name').value = data.client.name;
            document.getElementById('phone').value = data.client.phone;
            document.getElementById('address').value = data.client.address;
            document.getElementById('license_no').value = data.car.license_no;
            document.getElementById('engine_no').value = data.car.engine_no;
            document.getElementById('car_model').value = data.car.model || '';
            var dateInput = document.getElementById('date');
            var dateStr = data.next_available ? data.next_available.date : '';
            dateInput.value = dateStr;
            var wrap = dateInput.closest('.datepicker-wrap');
            if (wrap) {
                var disp = wrap.querySelector('.datepicker-display');
                if (disp && dateStr) {
                    disp.value = fmtDate(dateStr);
                }
                if (!dateStr) disp.value = '';
            }
            dateInput.dispatchEvent(new Event('change', { bubbles: true }));
            var mechId = data.next_available && data.next_available.mechanic_id ? data.next_available.mechanic_id : data.last_mechanic_id;
            selectMechanic(mechId);
            if (data.next_available) {
                (function pollSlot(slot, tries) {
                    var chips = document.querySelectorAll('.slot-chip');
                    if (chips.length > 0 || tries <= 0) {
                        chips.forEach(function(c) {
                            if (parseInt(c.dataset.slot) === slot && !c.classList.contains('taken')) selectSlot(c, slot);
                        });
                        return;
                    }
                    setTimeout(function() { pollSlot(slot, tries - 1); }, 100);
                })(data.next_available.slot, 30);
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        })
        .catch(function() {
            err.textContent = 'Could not reach server. Try again.';
            err.style.display = 'block';
        });
}

/* === EDIT BOOKING === */

function openEditBooking() {
    document.getElementById('eb-phone-input').value = '';
    document.getElementById('eb-phone-error').style.display = 'none';
    document.getElementById('eb-phone-modal').classList.remove('hidden');
    setTimeout(function() { document.getElementById('eb-phone-input').focus(); }, 100);
}

function lookupEditBooking() {
    var phone = document.getElementById('eb-phone-input').value.trim();
    var err = document.getElementById('eb-phone-error');
    if (!phone) {
        err.textContent = 'Please enter a phone number.';
        err.style.display = 'block';
        return;
    }
    err.style.display = 'none';
    fetch('availability.php?action=edit_lookup&phone=' + encodeURIComponent(phone))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.found) {
                document.getElementById('eb-phone-modal').classList.add('hidden');
                document.getElementById('qb-fail-msg').textContent = data.message || 'No bookings found for that number.';
                document.getElementById('qb-fail-modal').classList.remove('hidden');
                return;
            }
            document.getElementById('eb-phone-modal').classList.add('hidden');
            if (data.appointments.length === 1) {
                openEditModal(data.appointments[0]);
            } else {
                var list = document.getElementById('eb-select-list');
                list.innerHTML = '';
                data.appointments.forEach(function(a) {
                    var card = document.createElement('div');
                    card.className = 'eb-select-card';
                    var mechName = a.mechanic_name || 'Unknown';
                    card.innerHTML = '<div style="font-weight:bold;">' + fmtDate(a.appointment_date) + ' &middot; ' + SLOT_NAMES[a.slot_index] + '</div>'
                        + '<div style="font-size:0.85rem;opacity:0.8;">' + a.car.license_no + ' &middot; ' + mechName + '</div>';
                    card.addEventListener('click', function() { openEditModal(a); });
                    list.appendChild(card);
                });
                document.getElementById('eb-select-modal').classList.remove('hidden');
            }
        })
        .catch(function() {
            err.textContent = 'Could not reach server. Try again.';
            err.style.display = 'block';
        });
}

function openEditModal(appt) {
    document.getElementById('eb-select-modal').classList.add('hidden');
    document.getElementById('eb-appt-id').value = appt.id;
    document.getElementById('eb-name').value = appt.client.name;
    document.getElementById('eb-address').value = appt.client.address;
    document.getElementById('eb-license').value = appt.car.license_no;
    document.getElementById('eb-engine').value = appt.car.engine_no;
    document.getElementById('eb-model').value = appt.car.model || '';
    document.getElementById('eb-display-phone').value = appt.client.phone;
    document.getElementById('eb-display-date').textContent = fmtDate(appt.appointment_date);
    document.getElementById('eb-display-slot').textContent = SLOT_NAMES[appt.slot_index] || 'Slot ' + (appt.slot_index + 1);
    document.getElementById('eb-display-mechanic').textContent = appt.mechanic_name || 'Unknown';
    document.getElementById('eb-edit-modal').classList.remove('hidden');
}

/* === DOMCONTENTLOADED === */

document.addEventListener('DOMContentLoaded', function() {

    /* Apply saved settings to body */
    var _settingKeys = { 'doodles_disabled': 'doodles-off', 'bg_disabled': 'bg-off', 'animations_disabled': 'no-anim' };
    Object.keys(_settingKeys).forEach(function(k) {
        if (localStorage.getItem(k) === '1') document.body.classList.add(_settingKeys[k]);
    });

    var sbInterval = null;

    document.querySelectorAll('input[data-stepper]').forEach(initNumStepper);

    /* Settings gear — appears on admin page */
    var gearBtn = document.getElementById('settings-btn');
    var dropdown = document.getElementById('settings-dropdown');
    if (gearBtn && dropdown) {
        var toggle = document.getElementById('spotlight-toggle');
        if (toggle) {
            toggle.checked = window.SPOTLIGHT_DISABLED || false;
            toggle.addEventListener('change', function() {
                if (this.checked) {
                    localStorage.setItem('spotlight_disabled', '1');
                } else {
                    localStorage.removeItem('spotlight_disabled');
                }
            });
        }
        /* Doodles toggle */
        var dt = document.getElementById('doodles-toggle');
        if (dt) {
            dt.checked = localStorage.getItem('doodles_disabled') === '1';
            dt.addEventListener('change', function() {
                if (this.checked) {
                    localStorage.setItem('doodles_disabled', '1');
                    document.body.classList.add('doodles-off');
                } else {
                    localStorage.removeItem('doodles_disabled');
                    document.body.classList.remove('doodles-off');
                }
            });
        }
        /* Background toggle */
        var bgt = document.getElementById('bg-toggle');
        if (bgt) {
            bgt.checked = localStorage.getItem('bg_disabled') === '1';
            bgt.addEventListener('change', function() {
                if (this.checked) {
                    localStorage.setItem('bg_disabled', '1');
                    document.body.classList.add('bg-off');
                } else {
                    localStorage.removeItem('bg_disabled');
                    document.body.classList.remove('bg-off');
                }
            });
        }
        /* Animations toggle */
        var an = document.getElementById('animations-toggle');
        if (an) {
            an.checked = localStorage.getItem('animations_disabled') === '1';
            an.addEventListener('change', function() {
                if (this.checked) {
                    localStorage.setItem('animations_disabled', '1');
                    document.body.classList.add('no-anim');
                    if (sbInterval) { clearInterval(sbInterval); sbInterval = null; }
                } else {
                    localStorage.removeItem('animations_disabled');
                    document.body.classList.remove('no-anim');
                    if (!sbInterval && document.getElementById('speech-bubble')) {
                        var sb = document.getElementById('speech-bubble');
                        var bubbles = ['speech-bubble-1', 'speech-bubble-2', 'speech-bubble-3'];
                        var bi = 0;
                        sbInterval = setInterval(function() {
                            bi = (bi + 1) % bubbles.length;
                            sb.src = 'images/doodles/' + bubbles[bi] + '.svg';
                        }, 600);
                    }
                }
            });
        }
        gearBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdown.classList.toggle('hidden');
        });
        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target) && e.target !== gearBtn) {
                dropdown.classList.add('hidden');
            }
        });
    }

    /* Thank-you modal — spotlight completion */
    var doneBtn = document.getElementById('spotlight-done-btn');
    if (doneBtn) {
        doneBtn.addEventListener('click', function() {
            document.getElementById('thank-you-modal').classList.add('hidden');
            dismissSpotlight();
        });
    }

    /* Booking page */
    if (document.getElementById('booking-form')) {
        document.addEventListener('click', function(e) {
            var t = document.getElementById('slot-tooltip');
            if (t && !t.classList.contains('hidden') && !t.contains(e.target) && e.target !== t._target && !e.target.closest('.slot-chip.taken')) hideTooltip();
        });
        window.addEventListener('scroll', function() {
            var t = document.getElementById('slot-tooltip');
            if (t && !t.classList.contains('hidden') && t._target) {
                var rect = t._target.getBoundingClientRect();
                var spaceAbove = rect.top;
                var needsBelow = spaceAbove < t.offsetHeight + 8;
                var top = needsBelow ? rect.bottom + 8 : rect.top - t.offsetHeight - 8;
                if (needsBelow) t.classList.add('below'); else t.classList.remove('below');
                t.style.top = Math.max(4, top) + 'px';
                t.style.left = Math.max(4, Math.min(rect.left + rect.width - 60, window.innerWidth - t.offsetWidth - 10)) + 'px';
            }
        });
        window.addEventListener('resize', function() {
            var selectedCard = document.querySelector('.mechanic-card.selected');
            if (selectedCard) updateQuotePosition(selectedCard);
            repositionPastDateMsg();
        });
        if (typeof initialMechId !== 'undefined' && initialMechId && initialDate) {
            selectMechanic(initialMechId);
            if (initialSlot !== null && typeof initialSlot !== 'undefined') {
                setTimeout(function() {
                    document.querySelectorAll('.slot-chip').forEach(function(c) {
                        if (parseInt(c.dataset.slot) === initialSlot && !c.classList.contains('taken')) selectSlot(c, initialSlot);
                    });
                }, 400);
            }
        }
        updateVacationBadges(typeof initialDate !== 'undefined' ? initialDate : '');

        document.getElementById('booking-form').addEventListener('submit', function(e) {
            if (_spotlightErrors) { e.preventDefault(); return; }
            var errs = validateBookingForm();
            if (errs && errs.dateMsg) {
                e.preventDefault();
                showPastDateMsg(errs.dateMsg);
                return;
            }

            if (errs.length) {
                e.preventDefault();
                hidePastDateMsg();
                if (window.SPOTLIGHT_DISABLED) {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    (window.showInlineErrors || function(){})(errs);
                } else {
                    launchSpotlight(errs);
                }
                return;
            }
            hidePastDateMsg();
        });

        document.getElementById('date').addEventListener('change', function() {
            var dateVal = document.getElementById('date').value;
            if (!dateVal || dateVal >= TODAY) hidePastDateMsg();
        });
    }

    /* Global keyboard shortcuts */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay').forEach(function(m) {
                if (!m.classList.contains('hidden')) m.classList.add('hidden');
            });
        }
        if (e.key === 'Enter') {
            var pw = document.getElementById('pw-modal');
            if (pw && !pw.classList.contains('hidden')) confirmPw();
        }
    });

    /* Speech bubble cycling */
    var sb = document.getElementById('speech-bubble');
    if (sb) {
        var bubbles = ['speech-bubble-1', 'speech-bubble-2', 'speech-bubble-3'];
        var bi = 0;
        if (localStorage.getItem('animations_disabled') !== '1') {
            sbInterval = setInterval(function() {
                bi = (bi + 1) % bubbles.length;
                sb.src = 'images/doodles/' + bubbles[bi] + '.svg';
            }, 600);
        }
    }

    setTimeout(function() {
        var raw = sessionStorage.getItem('adminFilters');
        if (!raw) return;
        try {
            var state = JSON.parse(raw);
            document.getElementById('filter-name').value = state.name || '';
            document.getElementById('filter-phone').value = state.phone || '';
            document.getElementById('filter-car').value = state.car || '';
            var statusEl = document.getElementById('filter-status');
            if (state.status && statusEl) {
                statusEl.value = state.status;
                var wrap = statusEl.closest('.custom-select-wrap');
                if (wrap) {
                    var triggerText = wrap.querySelector('.custom-select-trigger-inner .label');
                    var opt = wrap.querySelector('.custom-select-option[data-value="' + state.status + '"]');
                    if (triggerText && opt) {
                        triggerText.textContent = opt.textContent;
                        wrap.querySelectorAll('.custom-select-option').forEach(function(o) { o.classList.remove('selected'); });
                        opt.classList.add('selected');
                    }
                }
            }
            var mechEl = document.getElementById('filter-mechanic');
            if (state.mechanic && mechEl) {
                var mechWrap = mechEl.closest('.custom-select-wrap');
                if (mechWrap) {
                    var opts = mechWrap.querySelectorAll('.custom-select-option');
                    opts.forEach(function(o) {
                        if (o.textContent.toLowerCase() === state.mechanic) {
                            var t = mechWrap.querySelector('.custom-select-trigger-inner .label');
                            if (t) t.textContent = o.textContent;
                            opts.forEach(function(x) { x.classList.remove('selected'); });
                            o.classList.add('selected');
                        }
                    });
                }
            }
            document.getElementById('filter-date-from').value = state.dateFrom || '';
            document.getElementById('filter-date-to').value = state.dateTo || '';
            if (state.dateFrom) {
                var fw = document.getElementById('filter-date-from').closest('.datepicker-wrap');
                if (fw) { var fd = fw.querySelector('.datepicker-display'); if (fd) fd.value = fmtDate(state.dateFrom); }
            }
            if (state.dateTo) {
                var tw = document.getElementById('filter-date-to').closest('.datepicker-wrap');
                if (tw) { var td = tw.querySelector('.datepicker-display'); if (td) td.value = fmtDate(state.dateTo); }
            }
            filterAppTable();
            updateFilterCross();
        } catch(e) {}
    }, 0);

});
