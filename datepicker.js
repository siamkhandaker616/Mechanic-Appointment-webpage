class DatePicker {
    constructor(input) {
        this.input = input;
        this.isDateTime = input.type === 'datetime-local';
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
        this.display.className = 'datepicker-display';
        this.display.readOnly = true;
        this.display.placeholder = this.input.placeholder || (this.isDateTime ? 'Pick date & time' : 'Pick a date');
        this.wrapper.appendChild(this.display);

        if (this.input.value) {
            this.display.value = this.isDateTime
                ? this.input.value.replace('T', ' ')
                : this.input.value;
        }

        this.input.style.display = 'none';

        this.popup = document.createElement('div');
        this.popup.className = 'datepicker-popup hidden';
        this.popup.style.position = 'fixed';
        document.body.appendChild(this.popup);

        this.render();
    }

    render() {
        var year = this.viewDate.getFullYear();
        var month = this.viewDate.getMonth();

        var firstDay = new Date(year, month, 1).getDay();
        var daysInMonth = new Date(year, month + 1, 0).getDate();
        var today = new Date();

        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var dayHeaders = ['Su','Mo','Tu','We','Th','Fr','Sa'];

        var html = '';

        html += '<div class="dp-header">';
        html += '<button class="dp-nav" data-action="prev">◀</button>';
        html += '<span class="dp-title">' + months[month] + ' ' + year + '</span>';
        html += '<button class="dp-nav" data-action="next">▶</button>';
        html += '</div>';

        html += '<div class="dp-days-header">';
        for (var i = 0; i < dayHeaders.length; i++) {
            html += '<span class="dp-dow">' + dayHeaders[i] + '</span>';
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
            var h = this.date ? String(this.date.getHours()).padStart(2, '0') : '08';
            var m = this.date ? String(this.date.getMinutes()).padStart(2, '0') : '00';
            html += '<div class="dp-time">';
            html += '<span class="dp-time-label">Time</span>';
            html += '<input type="number" class="dp-hour" value="' + h + '" min="0" max="23" step="1">';
            html += '<span class="dp-time-sep">:</span>';
            html += '<input type="number" class="dp-min" value="' + m + '" min="0" max="59" step="5">';
            html += '</div>';
        }

        html += '<div class="dp-footer">';
        html += '<button class="btn btn-sm" data-action="today">Today</button>';
        html += '<button class="btn btn-sm btn-pink" data-action="close">Close</button>';
        html += '</div>';

        this.popup.innerHTML = html;
    }

    positionPopup() {
        var rect = this.display.getBoundingClientRect();
        var popupW = 300;
        var left = rect.left;
        var top = rect.bottom + 6;

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

        window.addEventListener('scroll', function () {
            if (!self.popup.classList.contains('hidden')) {
                self.positionPopup();
            }
        });

        window.addEventListener('resize', function () {
            if (!self.popup.classList.contains('hidden')) {
                self.positionPopup();
            }
        });

        this.popup.addEventListener('click', function (e) {
            var target = e.target;

            if (target.dataset.action === 'prev') {
                self.viewDate.setMonth(self.viewDate.getMonth() - 1);
                self.render();
            } else if (target.dataset.action === 'next') {
                self.viewDate.setMonth(self.viewDate.getMonth() + 1);
                self.render();
            } else if (target.dataset.action === 'today') {
                self.viewDate = new Date();
                self.date = new Date();
                self.render();
                self.updateValue();
                self.close();
            } else if (target.dataset.action === 'close') {
                self.close();
            } else if (target.classList.contains('dp-day') && !target.classList.contains('dp-empty')) {
                var day = parseInt(target.dataset.day);
                self.date = new Date(self.viewDate.getFullYear(), self.viewDate.getMonth(), day);
                self.render();
                self.updateValue();
                self.close();
            }
        });

        this.popup.addEventListener('change', function (e) {
            if (e.target.classList.contains('dp-hour') || e.target.classList.contains('dp-min')) {
                self.updateValue();
            }
        });

        document.addEventListener('click', function (e) {
            if (self.popup && !self.popup.classList.contains('hidden') &&
                !self.wrapper.contains(e.target) &&
                !self.popup.contains(e.target)) {
                self.close();
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
        var pickers = document.querySelectorAll('.datepicker-popup');
        for (var i = 0; i < pickers.length; i++) {
            pickers[i].classList.add('hidden');
        }
        this.viewDate = this.date ? new Date(this.date) : new Date();
        this.render();
        this.positionPopup();
        this.popup.classList.remove('hidden');
    }

    close() {
        this.popup.classList.add('hidden');
    }

    updateValue() {
        if (!this.date) return;

        var value;
        if (this.isDateTime) {
            var h = this.popup.querySelector('.dp-hour').value.padStart(2, '0');
            var m = this.popup.querySelector('.dp-min').value.padStart(2, '0');
            value = this.date.getFullYear() + '-' + String(this.date.getMonth()+1).padStart(2,'0') + '-' + String(this.date.getDate()).padStart(2,'0') + 'T' + h + ':' + m;
            this.display.value = this.date.getFullYear() + '-' + String(this.date.getMonth()+1).padStart(2,'0') + '-' + String(this.date.getDate()).padStart(2,'0') + ' ' + h + ':' + m;
        } else {
            value = this.date.getFullYear() + '-' + String(this.date.getMonth()+1).padStart(2,'0') + '-' + String(this.date.getDate()).padStart(2,'0');
            this.display.value = value;
        }

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
