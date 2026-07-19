/* === SPOTLIGHT OF SHAME === */

window.SPOTLIGHT_DISABLED = localStorage.getItem('spotlight_disabled') === '1';

var _spotlightErrors = null;
var _spotlightIndex = -1;
var _fieldBurstMap = {};
var _lastBlurHandler = null;
var _lastKeydownHandler = null;
var SPOTLIGHT_FIELDS = '#booking-form input[type="text"], #booking-form input[type="tel"], #booking-form input[type="date"], #booking-form textarea';

var FIELD_PRIORITY = ['name', 'license_no', 'phone', 'engine_no', 'address'];
var PHONE_BURST_KEYS = ['zilch', 'nada', 'bzzt', 'nope'];

function validateBookingForm() {
    var errors = [];
    for (var i = 0; i < FIELD_PRIORITY.length; i++) {
        var el = document.getElementById(FIELD_PRIORITY[i]);
        if (!el || !el.dataset.validate) continue;
        var val = el.value.trim();
        var rules = (el.dataset.validate || '').split('|');
        for (var r = 0; r < rules.length; r++) {
            var rule = rules[r];
            if (rule === 'required' && !val) {
                errors.push({ el: el, msg: el.dataset.errRequired || 'This field is required.' });
                break;
            }
            if (rule === 'phone' && val && !/^[\d\s\-\+\(\)]+$/.test(val)) {
                errors.push({ el: el, msg: el.dataset.errPhone || 'Invalid phone format.' });
                break;
            }
            if (rule === 'alphanumeric' && val && !/^[a-zA-Z0-9]+$/.test(val)) {
                errors.push({ el: el, msg: el.dataset.errAlphanumeric || 'Alphanumeric only.' });
                break;
            }
        }
    }
    if (errors.length) return errors;
    var dateEl = document.getElementById('date');
    if (!dateEl.value) {
        return { dateMsg: "We might be amazing, but even we at least need a date to work. So go ahead and enter one." };
    }
    if (dateEl.value < TODAY) {
        return { dateMsg: "We can't travel to the past! Pick a present or later date." };
    }
    return [];
}

function launchSpotlight(errors) {
    _spotlightErrors = errors;
    _spotlightIndex = 0;
    var gearEl = document.querySelector('.settings-gear');
    if (gearEl) gearEl.style.display = 'none';
    (function smoothScroll(duration) {
        var startY = window.scrollY;
        if (startY === 0) return;
        var startTime = null;
        function step(now) {
            if (!startTime) startTime = now;
            var t = Math.min(1, (now - startTime) / duration);
            window.scrollTo(0, startY * (1 - t));
            if (t < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    })(80);
    var overlay = document.getElementById('spotlight-overlay');
    var sides = ['top', 'bottom', 'left', 'right'];
    sides.forEach(function(s) {
        var c = document.createElement('div');
        c.className = 'overlay-curtain';
        c.id = 'overlay-curtain-' + s;
        overlay.appendChild(c);
    });
    var beam = document.createElement('div');
    beam.className = 'spotlight-beam';
    beam.id = 'spotlight-beam';
    overlay.appendChild(beam);
    var glow = document.createElement('div');
    glow.className = 'spotlight-glow';
    glow.id = 'spotlight-glow';
    overlay.appendChild(glow);
    var burst = document.createElement('div');
    burst.className = 'error-burst';
    burst.id = 'spotlight-burst';
    overlay.appendChild(burst);
    var shuffled = BURST_KEYS.slice();
    for (var si = shuffled.length - 1; si > 0; si--) {
        var sj = Math.floor(Math.random() * (si + 1));
        var st = shuffled[si]; shuffled[si] = shuffled[sj]; shuffled[sj] = st;
    }
    _fieldBurstMap = {};
    for (var fi = 0; fi < errors.length; fi++) {
        _fieldBurstMap[errors[fi].el.id] = shuffled[fi % shuffled.length];
    }
    var bannerSub = document.getElementById('banner-sub');
    if (bannerSub) bannerSub.textContent = "The spotlight will guide you to each field — fix it, then move on.";
    var banner = document.getElementById('shame-banner');
    if (banner && errors.length >= 2) {
        banner.classList.remove('hidden');
        banner.style.setProperty('--banner-bg-opacity', 0.4 + 0.2 * Math.min(3, errors.length - 1));
    }
    requestAnimationFrame(function() {
        overlay.classList.add('active');
        document.querySelectorAll('.overlay-curtain').forEach(function(c) { c.classList.add('active'); });
        glow.classList.add('active');
        burst.classList.add('active');
        document.querySelectorAll(SPOTLIGHT_FIELDS).forEach(function(el) { el.readOnly = true; });
        errors[0].el.readOnly = false;
        _focusCooldownTimer = setTimeout(function() { _focusCooldownTimer = null; }, 600);
        errors[0].el.focus();
        positionSpotlight(errors[0]);
    });
    window.addEventListener('scroll', repositionOnScroll, { passive: true });
    document.addEventListener('focusin', handleSpotlightFocus);
}

var _scrollBurstTimer = null;
var _focusCooldownTimer = null;

function repositionOnScroll() {
    if (_spotlightErrors && _spotlightIndex >= 0 && _spotlightIndex < _spotlightErrors.length) {
        var burst = document.getElementById('spotlight-burst');
        if (burst) burst.classList.add('scroll-hide');
        positionSpotlight(_spotlightErrors[_spotlightIndex]);
        clearTimeout(_scrollBurstTimer);
        _scrollBurstTimer = setTimeout(function() {
            if (burst && !burst.classList.contains('fade-out')) {
                burst.classList.remove('scroll-hide');
            }
        }, 120);
    }
}

function advanceSpotlight() {
    _spotlightErrors[_spotlightIndex].el.readOnly = true;
    _spotlightIndex++;
    var bannerSub = document.getElementById('banner-sub');
    if (bannerSub) {
        if (_spotlightIndex === 1) {
            bannerSub.textContent = "Follow the spotlight, it's your only way out.";
        } else if (_spotlightIndex === 2) {
            bannerSub.textContent = "Wow, another one? This is embarrassing.";
        }
    }
    var bannerMaxSteps = Math.min(3, _spotlightErrors.length - 1);
    var banner = document.getElementById('shame-banner');
    if (banner) {
        if (_spotlightIndex >= bannerMaxSteps) {
            banner.classList.add('hidden');
        } else {
            banner.style.setProperty('--banner-bg-opacity', 0.4 + 0.2 * (bannerMaxSteps - _spotlightIndex));
        }
    }
    if (_spotlightIndex >= _spotlightErrors.length) {
        var totalErrors = _spotlightErrors.length;
        dismissSpotlight(function() {
            if (totalErrors >= 2) {
                document.getElementById('thank-you-modal').classList.remove('hidden');
            }
        });
        return;
    }
    positionSpotlight(_spotlightErrors[_spotlightIndex]);
    _spotlightErrors[_spotlightIndex].el.readOnly = false;
    _spotlightErrors[_spotlightIndex].el.focus();
    var burst = document.getElementById('spotlight-burst');
    if (burst) {
        clearTimeout(burst._fadeTimer);
        burst.classList.remove('scroll-hide', 'fade-out');
        burst.style.animation = 'none';
        void burst.offsetWidth;
        burst.style.animation = '';
    }
    _focusCooldownTimer = setTimeout(function() { _focusCooldownTimer = null; }, 300);
}

function dismissSpotlight(callback) {
    var burst = document.getElementById('spotlight-burst');
    if (burst) clearTimeout(burst._fadeTimer);
    clearTimeout(_scrollBurstTimer);
    clearTimeout(_focusCooldownTimer);
    _focusCooldownTimer = null;
    _lastBlurHandler = null;
    _lastKeydownHandler = null;
    var banner = document.getElementById('shame-banner');
    if (banner) banner.classList.add('hidden');
    document.querySelectorAll(SPOTLIGHT_FIELDS).forEach(function(el) { el.readOnly = false; });
    var overlay = document.getElementById('spotlight-overlay');
    overlay.classList.remove('active');
    document.removeEventListener('focusin', handleSpotlightFocus);
    window.removeEventListener('scroll', repositionOnScroll);
    setTimeout(function() {
        _spotlightErrors = null;
        _spotlightIndex = -1;
        var beam = document.getElementById('spotlight-beam');
        var glow = document.getElementById('spotlight-glow');
        var burst = document.getElementById('spotlight-burst');
        if (beam) beam.remove();
        if (glow) glow.remove();
        if (burst) burst.remove();
        document.querySelectorAll('.overlay-curtain').forEach(function(c) { c.remove(); });
        var gearEl = document.querySelector('.settings-gear');
        if (gearEl) gearEl.style.display = '';
        if (callback) callback();
    }, 400);
}

function revalidateField(el) {
    var val = el.value.trim();
    var rules = (el.dataset.validate || '').split('|');
    for (var r = 0; r < rules.length; r++) {
        var rule = rules[r];
        if (rule === 'required' && !val) return el.dataset.errRequired || 'This field is required.';
        if (rule === 'phone' && val && !/^[\d\s\-\+\(\)]+$/.test(val)) return el.dataset.errPhone || 'Invalid phone format.';
        if (rule === 'alphanumeric' && val && !/^[a-zA-Z0-9]+$/.test(val)) return el.dataset.errAlphanumeric || 'Alphanumeric only.';
    }
    return null;
}

function handleSpotlightFocus(e) {
    var current = _spotlightErrors[_spotlightIndex];
    if (!current) return;
    if (e.target === current.el || current.el.contains(e.target)) {
        var burst = document.getElementById('spotlight-burst');
        if (burst && !burst.classList.contains('fade-out')) {
            clearTimeout(burst._fadeTimer);
            burst._fadeTimer = setTimeout(function() {
                burst.classList.add('fade-out');
            }, 1200);
        }

        if (_lastBlurHandler) current.el.removeEventListener('blur', _lastBlurHandler);
        if (_lastKeydownHandler) current.el.removeEventListener('keydown', _lastKeydownHandler);

        function onSpotlightBlur() {
            var err = revalidateField(current.el);
            if (err) {
                var burst = document.getElementById('spotlight-burst');
                if (burst) {
                    clearTimeout(burst._fadeTimer);
                    var bk = (current.el.id === 'phone')
                        ? PHONE_BURST_KEYS[Math.floor(Math.random() * PHONE_BURST_KEYS.length)]
                        : (_fieldBurstMap[current.el.id] || BURST_KEYS[0]);
                    burst.classList.remove('scroll-hide', 'fade-out', 'burst-blank', 'burst-zilch', 'burst-nada', 'burst-bzzt', 'burst-nope');
                    burst.classList.add('burst-' + bk);
                    burst.style.animation = 'none';
                    void burst.offsetWidth;
                    burst.style.animation = '';
                }
            } else {
                current.el.removeEventListener('blur', _lastBlurHandler);
                current.el.removeEventListener('keydown', _lastKeydownHandler);
                _lastBlurHandler = null;
                _lastKeydownHandler = null;
                advanceSpotlight();
            }
        }

        function onSpotlightKeydown(ke) {
            if (ke.key === 'Enter') {
                ke.preventDefault();
                var err = revalidateField(current.el);
                if (err) {
                    var burst = document.getElementById('spotlight-burst');
                    if (burst) {
                        clearTimeout(burst._fadeTimer);
                        var bk = (current.el.id === 'phone')
                            ? PHONE_BURST_KEYS[Math.floor(Math.random() * PHONE_BURST_KEYS.length)]
                            : (_fieldBurstMap[current.el.id] || BURST_KEYS[0]);
                        burst.classList.remove('scroll-hide', 'fade-out', 'burst-blank', 'burst-zilch', 'burst-nada', 'burst-bzzt', 'burst-nope');
                        burst.classList.add('burst-' + bk);
                        burst.style.animation = 'none';
                        void burst.offsetWidth;
                        burst.style.animation = '';
                    }
                } else {
                    current.el.removeEventListener('blur', _lastBlurHandler);
                    current.el.removeEventListener('keydown', _lastKeydownHandler);
                    _lastBlurHandler = null;
                    _lastKeydownHandler = null;
                    advanceSpotlight();
                }
            }
        }

        _lastBlurHandler = onSpotlightBlur;
        _lastKeydownHandler = onSpotlightKeydown;
        current.el.addEventListener('blur', _lastBlurHandler);
        current.el.addEventListener('keydown', _lastKeydownHandler);
    }
}

function positionSpotlight(error) {
    var rect = error.el.getBoundingClientRect();
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    var tipHalf = 6;
    var tipCenter = vw / 2;
    var bottomY = rect.bottom + 10;
    var left = Math.max(0, rect.left - 12);
    var right = Math.min(vw, rect.right + 12);

    var ct = document.getElementById('overlay-curtain-top');
    var cb = document.getElementById('overlay-curtain-bottom');
    var cl = document.getElementById('overlay-curtain-left');
    var cr = document.getElementById('overlay-curtain-right');
    if (ct) { ct.style.top = '0'; ct.style.left = '0'; ct.style.width = vw + 'px'; ct.style.height = Math.max(0, rect.top) + 'px'; }
    if (cb) { cb.style.top = rect.bottom + 'px'; cb.style.left = '0'; cb.style.width = vw + 'px'; cb.style.height = Math.max(0, vh - rect.bottom) + 'px'; }
    if (cl) { cl.style.top = rect.top + 'px'; cl.style.left = '0'; cl.style.width = Math.max(0, rect.left) + 'px'; cl.style.height = rect.height + 'px'; }
    if (cr) { cr.style.top = rect.top + 'px'; cr.style.left = rect.right + 'px'; cr.style.width = Math.max(0, vw - rect.right) + 'px'; cr.style.height = rect.height + 'px'; }

    var beam = document.getElementById('spotlight-beam');
    var glow = document.getElementById('spotlight-glow');
    var burst = document.getElementById('spotlight-burst');
    if (glow) {
        glow.style.left = rect.left + 'px';
        glow.style.top = rect.top + 'px';
        glow.style.width = (rect.right - rect.left) + 'px';
        glow.style.height = rect.height + 'px';
    }
    if (beam) {
        beam.style.height = Math.max(bottomY, 60) + 'px';
        beam.style.clipPath = 'polygon(' +
            (tipCenter - tipHalf) + 'px 0, ' +
            (tipCenter + tipHalf) + 'px 0, ' +
            right + 'px ' + bottomY + 'px, ' +
            left + 'px ' + bottomY + 'px)';
    }
    if (burst) {
        var rnd = _fieldBurstMap[error.el.id] || BURST_KEYS[0];
        burst.classList.remove('burst-blank', 'burst-zilch', 'burst-nada', 'burst-bzzt', 'burst-nope');
        burst.classList.add('burst-' + rnd);
        var bw = burst.offsetWidth || 170;
        var bh = burst.offsetHeight || 170;
        var fieldCenterX = rect.left + (rect.right - rect.left) / 2;
        burst.style.left = (fieldCenterX - bw / 2) + 'px';
        burst.style.top = Math.max(4, rect.top + rect.height / 2 - bh / 2) + 'px';
    }
}

window.showInlineErrors = function(errs) {
    (window.hideInlineErrors || function(){} )();
    for (var ei = 0; ei < errs.length; ei++) {
        var sp = document.createElement('span');
        sp.className = 'field-error';
        sp.textContent = errs[ei].msg;
        errs[ei].el.parentNode.appendChild(sp);
    }
};
window.hideInlineErrors = function() {
    document.querySelectorAll('.field-error').forEach(function(el) { el.remove(); });
};

/* Inline-error clear-on-input — only on booking page */
if (document.getElementById('booking-form')) {
    document.addEventListener('DOMContentLoaded', function() {
        var formFields = document.querySelectorAll('#booking-form input, #booking-form textarea');
        for (var fi = 0; fi < formFields.length; fi++) {
            formFields[fi].addEventListener('input', function() {
                var existing = this.parentNode.querySelector('.field-error');
                if (existing) existing.remove();
            });
        }
    });
}

window.setSpotlightDisabled = function(v) { window.SPOTLIGHT_DISABLED = v; };
