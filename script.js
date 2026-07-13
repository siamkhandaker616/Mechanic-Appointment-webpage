/* === SHARED GLOBALS === */
if (typeof window.SPOTLIGHT_DISABLED === 'undefined') {
    window.SPOTLIGHT_DISABLED = localStorage.getItem('spotlight_disabled') === '1';
}

/* === UTILITIES === */

function htmlspecialchars(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function formatSuggestDate(dateStr) {
    var parts = dateStr.split('-');
    var year = parts[0];
    var monthNum = parseInt(parts[1], 10);
    var day = parseInt(parts[2], 10);
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var monthStr = months[monthNum - 1] || '';
    return day + ' ' + monthStr + ' ' + year;
}

function repositionPastDateMsg() {
    var el = document.getElementById('past-date-msg');
    if (!el || el.style.display === 'none') return;
    var btn = document.querySelector('#booking-form button[type="submit"]');
    if (btn) {
        var r = btn.getBoundingClientRect();
        var left = Math.max(8, Math.min(r.left + r.width / 2 - 45 + 30 + window.scrollX, window.innerWidth - 220));
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
    qt.innerHTML = '<span class="qt-text">"' + htmlspecialchars(quote) + '"</span><span class="qt-author">- ' + htmlspecialchars(name) + '</span>';
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
                html += '<button type="button" class="suggestion-chip" onclick="fillSuggestion(' + currentMechId + ', \'' + data.nearby_prev_date + '\', ' + slotIndex + ')">' + formatSuggestDate(data.nearby_prev_date) + ' <span class="chip-label">' + SLOT_LABELS[slotIndex] + '</span></button>';
            }
            if (data.nearby_next_date) {
                html += '<button type="button" class="suggestion-chip" onclick="fillSuggestion(' + currentMechId + ', \'' + data.nearby_next_date + '\', ' + slotIndex + ')">' + formatSuggestDate(data.nearby_next_date) + ' <span class="chip-label">' + SLOT_LABELS[slotIndex] + '</span></button>';
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
    setTimeout(function() {
        document.querySelectorAll('.slot-chip').forEach(function(c) {
            if (parseInt(c.dataset.slot) === slotIndex && !c.classList.contains('taken')) selectSlot(c, slotIndex);
        });
    }, 0);
}

/* === ADMIN === */

var _pendingAction = '';
var _pendingForm = null;

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

/* === PASSWORD MODAL === */

function requirePw(actionUrl) {
    _pendingAction = actionUrl; _pendingForm = null; _pendingField = null; openPwModal();
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
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        var resp = JSON.parse(xhr.responseText);
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
            } else if (_pendingAction) {
                window.location.href = _pendingAction;
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
    xhr.send('verify_pw=1&admin_pw=' + encodeURIComponent(pw));
}
function closePwModal() {
    document.getElementById('pw-modal').classList.add('hidden');
    _pendingAction = ''; _pendingForm = null; _pendingField = null;
}

/* === TOGGLES === */

function toggleEdit(id) { document.getElementById('edit-' + id).classList.toggle('show'); }
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

function openScheduleModal(id, name) {
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
function closeScheduleModal(event) { if (event.target === event.currentTarget) document.getElementById('schedule-modal').classList.add('hidden'); }
function validateOverrideForm() {
    var err = document.getElementById('override-error');
    var mech = document.querySelector('[name="override_mechanic"]').value;
    var date = document.querySelector('[name="override_date"]').value;
    var slots = document.querySelectorAll('[name="slots[]"]:checked');
    if (!mech) { err.textContent = 'Select a mechanic first.'; err.style.display = 'block'; return false; }
    if (!date) { err.textContent = 'Select a date first.'; err.style.display = 'block'; return false; }
    if (date < TODAY) { err.textContent = 'Date cannot be in the past.'; err.style.display = 'block'; return false; }
    if (slots.length === 0) { err.textContent = 'Block at least one slot.'; err.style.display = 'block'; return false; }
    err.style.display = 'none';
    return true;
}
function clearOverrideError() {
    document.getElementById('override-error').style.display = 'none';
}

/* === CONFIRMATION MODALS === */

function showCancelModal(id) { _pendingAction = '?cancel=' + id; document.getElementById('cancel-modal').classList.remove('hidden'); }
function closeCancelModal(event) { if (event.target === event.currentTarget) document.getElementById('cancel-modal').classList.add('hidden'); }
function showFireModal(id, name) { _pendingAction = '?fire=' + id; document.getElementById('fire-modal-title').textContent = 'Fire ' + name + '?'; document.getElementById('fire-modal').classList.remove('hidden'); }
function closeFireModal(event) { if (event.target === event.currentTarget) document.getElementById('fire-modal').classList.add('hidden'); }
function showRemoveModal(id) { _pendingAction = '?remove=' + id; document.getElementById('remove-modal').classList.remove('hidden'); }
function closeRemoveModal(event) { if (event.target === event.currentTarget) document.getElementById('remove-modal').classList.add('hidden'); }
function showUnblockModal(id, name, date) {
    document.getElementById('unblock-confirm-link').href = '?unblock=' + id;
    document.getElementById('unblock-msg').textContent = 'Unblock ' + name + ' on ' + date + '?';
    document.getElementById('unblock-modal').classList.remove('hidden');
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
            var label = htmlspecialchars(v.start_date + ' to ' + v.end_date);
            if (v.reason) label += ' (' + htmlspecialchars(v.reason) + ')';
            html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;padding:4px 8px;background:var(--cyan);border:2px solid var(--ink);font-size:0.8rem;">';
            html += '<span style="flex:1;">' + label + '</span>';
            html += '<a href="?remove_vacation=' + v.id + '&mech_name=' + encodeURIComponent(mechName || '') + '" class="btn btn-sm btn-rust" style="font-size:0.65rem;padding:2px 8px;">End</a>';
            html += '</div>';
        });
        list.innerHTML = html;
    }
}
function addVacation() {
    var err = document.getElementById('vac-error');
    if (err) err.style.display = 'none';
    var id = document.getElementById('modal-mech-id').value;
    var start = document.getElementById('vac-start').value;
    var end = document.getElementById('vac-end').value;
    if (!id || !start || !end) return;
    if (!err) {
        err = document.createElement('div');
        err.id = 'vac-error';
        err.className = 'field-error';
        document.getElementById('vac-end').parentNode.appendChild(err);
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
    var reason = document.getElementById('vac-reason').value;
    var newHireName = window._newHireName || '';
    var f = document.createElement('form');
    f.method = 'POST';
    f.style.display = 'none';
    f.innerHTML = '<input name="add_vacation" value="1"><input name="vac_mech_id" value="' + htmlspecialchars(id) + '"><input name="vac_start" value="' + htmlspecialchars(start) + '"><input name="vac_end" value="' + htmlspecialchars(end) + '"><input name="vac_reason" value="' + htmlspecialchars(reason) + '">' + (newHireName ? '<input name="_new_hire_name" value="' + htmlspecialchars(newHireName) + '">' : '');
    document.body.appendChild(f);
    f.submit();
}
function closeConflictModal(event) { if (event.target === event.currentTarget) document.getElementById('conflict-modal').classList.add('hidden'); }
function closeMsgModal(event) { if (event.target === event.currentTarget) document.getElementById('msg-modal').classList.add('hidden'); }

/* === DOMCONTENTLOADED === */

document.addEventListener('DOMContentLoaded', function() {

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
                location.reload();
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
            if (errs === null) {
                e.preventDefault();
                showPastDateMsg("Pick a valid date.");
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

    /* Admin page */
    if (document.getElementById('pw-modal')) {
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !document.getElementById('pw-modal').classList.contains('hidden')) closePwModal();
            if (e.key === 'Enter' && !document.getElementById('pw-modal').classList.contains('hidden')) confirmPw();
        });

    }

});
