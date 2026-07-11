var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
var DAY_HEADERS = ['Su','Mo','Tu','We','Th','Fr','Sa'];

var DPM = {
    openPickers: new Set(),
    onScroll: function () {
        DPM.openPickers.forEach(function (p) {
            if (p.popup && !p.popup.classList.contains('hidden')) p.positionPopup();
        });
    },
    onResize: function () {
        DPM.openPickers.forEach(function (p) {
            if (p.popup && !p.popup.classList.contains('hidden')) p.positionPopup();
        });
    },
    onClick: function (e) {
        DPM.openPickers.forEach(function (p) {
            if (p.popup && !p.popup.classList.contains('hidden') &&
                !p.wrapper.contains(e.target) && !p.popup.contains(e.target)) {
                p.close();
            }
        });
    },
    register: function (picker) {
        DPM.openPickers.add(picker);
        if (DPM.openPickers.size === 1) {
            window.addEventListener('scroll', DPM.onScroll);
            window.addEventListener('resize', DPM.onResize);
            document.addEventListener('click', DPM.onClick);
        }
    },
    unregister: function (picker) {
        DPM.openPickers.delete(picker);
        if (DPM.openPickers.size === 0) {
            window.removeEventListener('scroll', DPM.onScroll);
            window.removeEventListener('resize', DPM.onResize);
            document.removeEventListener('click', DPM.onClick);
        }
    },
};

class DatePicker {
    constructor(input) {
        this.input = input;
        this.isDateTime = input.type === 'datetime-local';
        this.confirm = input.dataset.confirm === '' || input.dataset.confirm === 'true';
        this.placement = input.dataset.placement || 'auto';
        this.date = null;
        this.viewDate = new Date();
        this.popup = null;
        this.display = null;
        this.wrapper = null;

        if (this.input.value) {
            this.date = new Date(this.input.value);
        }

        this.build();
        this.bindEvents();
    }

    build() {
        this.wrapper = document.createElement('div');
        this.wrapper.className = 'datepicker-wrap';
        this.input.parentNode.insertBefore(this.wrapper, this.input);
        this.wrapper.appendChild(this.input);

        this.display = document.createElement('input');
        this.display.type = 'text';
        this.display.className = 'datepicker-display' + (this.isDateTime ? ' datepicker-display--dt' : '');
        this.display.readOnly = true;
        this.display.placeholder = this.input.placeholder || (this.isDateTime ? 'Pick date & time' : 'Pick a date');
        this.wrapper.appendChild(this.display);

        if (this.input.value) {
            var formatted = this._formatDisplay(this.input.value);
            this.display.value = formatted;
        }

        this.input.style.display = 'none';

        this.popup = document.createElement('div');
        this.popup.className = 'datepicker-popup hidden' + (this.isDateTime ? ' datepicker-popup--dt' : '');
        this.popup.style.position = 'fixed';
        document.body.appendChild(this.popup);

        this.render();
    }

    _formatDisplay(isoStr) {
        var parts = isoStr.split('T');
        var ymd = parts[0].split('-');
        var year = ymd[0];
        var monthIndex = parseInt(ymd[1], 10) - 1;
        var monthStr = MONTHS[monthIndex] || '';
        var day = parseInt(ymd[2], 10);
        var display = day + ' ' + monthStr + ' ' + year;
        if (parts[1]) display += ' • ' + parts[1];
        return display;
    }

    render() {
        var year = this.viewDate.getFullYear();
        var month = this.viewDate.getMonth();

        var firstDay = new Date(year, month, 1).getDay();
        var daysInMonth = new Date(year, month + 1, 0).getDate();
        var today = new Date();

        var html = '';

        html += '<div class="dp-header">';
        html += '<button type="button" class="dp-nav" data-action="prev">◀</button>';
        html += '<span class="dp-title">' + MONTHS[month] + ' ' + year + '</span>';
        html += '<button type="button" class="dp-nav" data-action="next">▶</button>';
        html += '</div>';

        if (this.isDateTime) {
            html += '<div class="dp-body">';
            html += '<div class="dp-grid-col">';
        }

        html += '<div class="dp-days-header">';
        for (var i = 0; i < DAY_HEADERS.length; i++) {
            html += '<span class="dp-dow">' + DAY_HEADERS[i] + '</span>';
        }
        html += '</div>';

        html += '<div class="dp-days-grid">';
        for (var i = 0; i < firstDay; i++) {
            html += '<span class="dp-day dp-empty"></span>';
        }
        for (var d = 1; d <= daysInMonth; d++) {
            var isToday = d === today.getDate() && month === today.getMonth() && year === today.getFullYear();
            var isSelected = this.date && d === this.date.getDate() && month === this.date.getMonth() && year === this.date.getFullYear();
            var cls = 'dp-day';
            if (isToday) cls += ' dp-today';
            if (isSelected) cls += ' dp-selected';
            html += '<span class="' + cls + '" data-day="' + d + '">' + d + '</span>';
        }
        html += '</div>';

        if (this.isDateTime) {
            html += '</div>';

            var h = this.date ? String(this.date.getHours()).padStart(2, '0') : '08';
            var m = this.date ? String(this.date.getMinutes()).padStart(2, '0') : '00';
            html += '<div class="dp-time-col">';
            html += '<div class="dp-time">';
            html += '<span class="dp-time-label">Time</span>';
            html += '<div class="dp-time-inputs">';
            html += '<input type="number" class="dp-hour" value="' + h + '" min="0" max="23" step="1" data-stepper data-stepper-wrap data-stepper-pad="2">';
            html += '<span class="dp-time-sep">:</span>';
            html += '<input type="number" class="dp-min" value="' + m + '" min="0" max="55" step="5" data-stepper data-stepper-wrap data-stepper-pad="2">';
            html += '</div>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
        }

        html += '<div class="dp-footer">';
        if (this.confirm || this.isDateTime) {
            html += '<button class="btn btn-sm" data-action="ok">OK</button>';
            if (!this.isDateTime) {
                html += '<button class="btn btn-sm" data-action="today">Today</button>';
            }
        } else {
            html += '<button class="btn btn-sm" data-action="today">Today</button>';
        }
        html += '<button class="btn btn-sm btn-pink" data-action="close">Close</button>';
        html += '</div>';

        this.popup.innerHTML = html;
        this.popup.querySelectorAll('input[data-stepper]').forEach(initNumStepper);
    }

    positionPopup() {
        var rect = this.display.getBoundingClientRect();
        var popupW = 300;
        var popupH = this.popup.offsetHeight;
        var top;

        if (this.placement === 'top') {
            top = Math.max(4, rect.top - popupH - 6);
        } else if (rect.top > popupH + 10) {
            top = rect.top - popupH - 6;
        } else {
            top = rect.bottom + 6;
        }

        var left = rect.left;
        if (left + popupW > window.innerWidth) {
            left = window.innerWidth - popupW - 10;
        }
        if (left < 10) left = 10;

        this.popup.style.left = left + 'px';
        this.popup.style.top = top + 'px';
    }

    bindEvents() {
        var self = this;

        this.display.addEventListener('click', function () {
            self.toggle();
        });

        this.popup.addEventListener('click', function (e) {
            e.stopPropagation();
            var target = e.target;

            if (target.dataset.action === 'prev') {
                self.viewDate.setMonth(self.viewDate.getMonth() - 1);
                self.render();
            } else if (target.dataset.action === 'next') {
                self.viewDate.setMonth(self.viewDate.getMonth() + 1);
                self.render();
            } else if (target.dataset.action === 'ok') {
                self.updateValue();
                self.close();
            } else if (target.dataset.action === 'today') {
                self.viewDate = new Date();
                self.date = new Date();
                self.render();
                if (!self.confirm) {
                    self.updateValue();
                    self.close();
                }
            } else if (target.dataset.action === 'close') {
                self.close();
            } else if (target.classList.contains('dp-day') && !target.classList.contains('dp-empty')) {
                var day = parseInt(target.dataset.day);
                if (self.isDateTime || self.confirm) {
                    var h = self.popup.querySelector('.dp-hour');
                    var currH = h ? parseInt(h.value) : 0;
                    var currM = self.popup.querySelector('.dp-min');
                    var currMv = currM ? parseInt(currM.value) : 0;
                    self.date = new Date(self.viewDate.getFullYear(), self.viewDate.getMonth(), day, currH || 0, currMv || 0);
                    self.render();
                } else {
                    self.date = new Date(self.viewDate.getFullYear(), self.viewDate.getMonth(), day);
                    self.render();
                    self.updateValue();
                    self.close();
                }
            }
        });

        this.popup.addEventListener('change', function (e) {
            if (e.target.classList.contains('dp-hour') || e.target.classList.contains('dp-min')) {
                if (self.date) {
                    var h = parseInt(self.popup.querySelector('.dp-hour').value) || 0;
                    var m = parseInt(self.popup.querySelector('.dp-min').value) || 0;
                    self.date.setHours(h, m, 0, 0);
                }
            }
        });
    }

    toggle() {
        if (this.popup.classList.contains('hidden')) {
            this.open();
        } else {
            this.close();
        }
    }

    open() {
        var self = this;
        DPM.openPickers.forEach(function (p) { if (p !== self) p.close(); });
        this.viewDate = this.date ? new Date(this.date) : new Date();
        this.render();
        this.popup.classList.remove('hidden');
        DPM.register(this);
        requestAnimationFrame(function () {
            self.positionPopup();
        });
    }

    close() {
        this.popup.classList.add('hidden');
        DPM.unregister(this);
    }

    updateValue() {
        if (!this.date) return;

        var y = this.date.getFullYear();
        var mo = String(this.date.getMonth() + 1).padStart(2, '0');
        var d = String(this.date.getDate()).padStart(2, '0');
        var value;

        if (this.isDateTime) {
            var h = this.popup.querySelector('.dp-hour').value.padStart(2, '0');
            var m = this.popup.querySelector('.dp-min').value.padStart(2, '0');
            value = y + '-' + mo + '-' + d + 'T' + h + ':' + m;
        } else {
            value = y + '-' + mo + '-' + d;
        }

        this.display.value = this._formatDisplay(value);
        this.input.value = value;
        this.input.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var inputs = document.querySelectorAll('input[type="date"], input[type="datetime-local"]');
    for (var i = 0; i < inputs.length; i++) {
        new DatePicker(inputs[i]);
    }
});
