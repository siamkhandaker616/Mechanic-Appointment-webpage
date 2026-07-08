document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('booking-form');
    if (!form) return;

    var nameInput = document.getElementById('name');
    var phoneInput = document.getElementById('phone');
    var licenseInput = document.getElementById('license_no');
    var engineInput = document.getElementById('engine_no');
    var dateInput = document.getElementById('date');
    var slotInput = document.getElementById('slot_index');
    var mechanicRadios = document.querySelectorAll('input[name="mechanic_id"]');

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

    nameInput.addEventListener('blur', function () {
        showError(this, this.value.trim() ? '' : 'Name is required.');
    });

    phoneInput.addEventListener('blur', function () {
        var v = this.value.trim();
        if (!v) { showError(this, 'Phone is required.'); return; }
        if (!/^[\d\s\-+()]+$/.test(v)) { showError(this, 'Digits only.'); return; }
        showError(this, '');
    });

    licenseInput.addEventListener('blur', function () {
        showError(this, this.value.trim() ? '' : 'License number is required.');
    });

    engineInput.addEventListener('blur', function () {
        var v = this.value.trim();
        if (!v) { showError(this, 'Engine number is required.'); return; }
        if (!/^[a-zA-Z0-9]+$/.test(v)) { showError(this, 'Alphanumeric only.'); return; }
        showError(this, '');
    });

    form.addEventListener('submit', function (e) {
        clearErrors();
        var valid = true;

        if (!nameInput.value.trim()) {
            showError(nameInput, 'Required');
            valid = false;
        }
        if (!phoneInput.value.trim()) {
            showError(phoneInput, 'Required');
            valid = false;
        } else if (!/^[\d\s\-+()]+$/.test(phoneInput.value.trim())) {
            showError(phoneInput, 'Digits only');
            valid = false;
        }
        if (!licenseInput.value.trim()) {
            showError(licenseInput, 'Required');
            valid = false;
        }
        if (!engineInput.value.trim()) {
            showError(engineInput, 'Required');
            valid = false;
        } else if (!/^[a-zA-Z0-9]+$/.test(engineInput.value.trim())) {
            showError(engineInput, 'Alphanumeric only');
            valid = false;
        }
        if (!dateInput.value) {
            showError(dateInput, 'Select a date');
            valid = false;
        }
        var mechSelected = Array.from(mechanicRadios).some(function (r) { return r.checked; });
        if (!mechSelected) {
            e.preventDefault();
            alert('Please select a mechanic.');
            valid = false;
        }
        if (!slotInput.value && slotInput.value !== 0) {
            e.preventDefault();
            alert('Please select a time slot.');
            valid = false;
        }

        if (!valid) e.preventDefault();
    });
});
