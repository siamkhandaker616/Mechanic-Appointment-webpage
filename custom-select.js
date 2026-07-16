(function() {
    'use strict';

    function createCustomSelect(selectEl) {
        if (selectEl._customSelect) return;
        var wrap = document.createElement('div');
        wrap.className = 'custom-select-wrap';

        if (selectEl.name === 'override_mechanic') {
            wrap.classList.add('theme-cyan');
        }

        if (selectEl.classList.contains('fire-swap')) {
            wrap.classList.add('fire-swap');
        }

        var trigger = document.createElement('div');
        trigger.className = 'custom-select-trigger';
        trigger.tabIndex = 0;

        var inner = document.createElement('div');
        inner.className = 'custom-select-trigger-inner';

        var textSpan = document.createElement('span');
        textSpan.className = 'label';
        inner.appendChild(textSpan);

        var arrow = document.createElement('span');
        arrow.className = 'arrow';
        inner.appendChild(arrow);

        var dropdown = document.createElement('div');
        dropdown.className = 'custom-select-dropdown';

        var opts = selectEl.options;
        var optEls = [];

        function buildOptions() {
            dropdown.innerHTML = '';
            optEls = [];
            for (var i = 0; i < opts.length; i++) {
                (function(idx) {
                    var div = document.createElement('div');
                    div.className = 'custom-select-option';

                    var l = document.createElement('span');
                    l.textContent = opts[idx].text;
                    div.appendChild(l);

                    div.addEventListener('click', function(e) {
                        e.stopPropagation();
                        selectOption(idx);
                        closeDropdown();
                        trigger.focus();
                    });

                    dropdown.appendChild(div);
                    optEls.push(div);
                })(i);
            }
        }

        buildOptions();

        selectEl.parentNode.insertBefore(wrap, selectEl);
        wrap.appendChild(selectEl);
        wrap.appendChild(trigger);
        trigger.appendChild(inner);
        wrap.appendChild(dropdown);

        selectEl.style.position = 'absolute';
        selectEl.style.opacity = '0';
        selectEl.style.pointerEvents = 'none';
        selectEl.style.width = '1px';
        selectEl.style.height = '1px';
        selectEl.style.overflow = 'hidden';
        selectEl.tabIndex = -1;

        var selectedIdx = selectEl.selectedIndex;
        if (selectedIdx < 0) selectedIdx = 0;
        textSpan.textContent = opts[selectedIdx] ? opts[selectedIdx].text : '';
        if (optEls[selectedIdx]) optEls[selectedIdx].classList.add('selected');

        var isOpen = false;

        function selectOption(idx) {
            if (idx < 0 || idx >= opts.length) return;
            optEls.forEach(function(el, j) {
                el.classList.toggle('selected', j === idx);
            });
            selectedIdx = idx;
            textSpan.textContent = opts[idx].text;
            selectEl.selectedIndex = idx;
            var evt = new Event('change', { bubbles: true });
            selectEl.dispatchEvent(evt);
        }

        function syncFromNative() {
            var idx = selectEl.selectedIndex;
            if (idx < 0) idx = 0;
            selectedIdx = idx;
            textSpan.textContent = opts[idx] ? opts[idx].text : '';
            optEls.forEach(function(el, j) {
                el.classList.toggle('selected', j === idx);
            });
        }

        function openDropdown() {
            if (isOpen) return;
            syncFromNative();
            isOpen = true;
            trigger.classList.add('open');
            dropdown.classList.add('open');
            if (optEls[selectedIdx]) {
                optEls[selectedIdx].scrollIntoView({ block: 'nearest' });
            }
        }

        function closeDropdown() {
            if (!isOpen) return;
            isOpen = false;
            trigger.classList.remove('open');
            dropdown.classList.remove('open');
        }

        function toggleDropdown() {
            if (isOpen) closeDropdown();
            else openDropdown();
        }

        trigger.addEventListener('click', toggleDropdown);

        trigger.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'Space') {
                e.preventDefault();
                toggleDropdown();
            } else if (e.key === 'Escape') {
                closeDropdown();
                trigger.focus();
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!isOpen) { openDropdown(); return; }
                var next = Math.min(selectedIdx + 1, opts.length - 1);
                selectOption(next);
                if (optEls[next]) optEls[next].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (!isOpen) { openDropdown(); return; }
                var prev = Math.max(selectedIdx - 1, 0);
                selectOption(prev);
                if (optEls[prev]) optEls[prev].scrollIntoView({ block: 'nearest' });
            }
        });

        selectEl._customSelect = true;
    }

    var selects = document.querySelectorAll('select.custom-select');
    for (var i = 0; i < selects.length; i++) {
        createCustomSelect(selects[i]);
    }

    document.addEventListener('click', function(e) {
        var wraps = document.querySelectorAll('.custom-select-wrap');
        for (var w = 0; w < wraps.length; w++) {
            var trig = wraps[w].querySelector('.custom-select-trigger');
            if (trig && trig.classList.contains('open') && !wraps[w].contains(e.target)) {
                trig.classList.remove('open');
                var dd = wraps[w].querySelector('.custom-select-dropdown');
                if (dd) dd.classList.remove('open');
            }
        }
    });
})();
