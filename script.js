document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('booking-form');
    if (!form) return;

    var dateInput = document.getElementById('date');
    var slotInput = document.getElementById('slot_index');
    var mechanicRadios = document.querySelectorAll('input[name="mechanic_id"]');

    var inputs = ['name', 'phone', 'license_no', 'engine_no']
        .map(function (id) { return document.getElementById(id); })
        .filter(Boolean);

    function validateField(input) {
        var val = input.value.trim();
        var rules = (input.dataset.validate || '').split('|');
        for (var i = 0; i < rules.length; i++) {
            var rule = rules[i];
            if (rule === 'required' && !val) return input.dataset.errRequired || 'Required';
            if (rule === 'phone' && val && !/^[\d\s\-+()]+$/.test(val)) return input.dataset.errPhone || 'Digits only';
            if (rule === 'alphanumeric' && val && !/^[a-zA-Z0-9]+$/.test(val)) return input.dataset.errAlphanumeric || 'Alphanumeric only';
        }
        return '';
    }

    function showError(input, msg) {
        var parent = input.closest('.form-group');
        var existing = parent.querySelector('.field-error');
        if (existing) existing.remove();
        if (msg) {
            var err = document.createElement('div');
            err.className = 'field-error';
            err.style.cssText = 'color:var(--rust);font-size:0.78rem;font-weight:bold;margin-top:4px;text-transform:uppercase;';
            err.textContent = msg;
            parent.appendChild(err);
        }
    }

    function clearErrors() {
        document.querySelectorAll('.field-error').forEach(function (e) { e.remove(); });
    }

    inputs.forEach(function (input) {
        input.addEventListener('blur', function () {
            showError(this, validateField(this));
        });
    });

    form.addEventListener('submit', function (e) {
        clearErrors();
        var valid = true;

        inputs.forEach(function (input) {
            var msg = validateField(input);
            if (msg) { showError(input, msg); valid = false; }
        });

        if (!dateInput.value) {
            showError(dateInput, 'Select a date');
            valid = false;
        }
        if (!Array.from(mechanicRadios).some(function (r) { return r.checked; })) {
            alert('Please select a mechanic.');
            valid = false;
        }
        if (slotInput.value === "") {
            alert('Please select a time slot.');
            valid = false;
        }

        if (!valid) e.preventDefault();
    });
});
