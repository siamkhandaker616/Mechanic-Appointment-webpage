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
        var animating = false;
        var foldTimeouts = [];

        function animateRotate(el, fromDeg, toDeg, duration, onDone) {
            var startTime = performance.now();
            function step(now) {
                var elapsed = now - startTime;
                var t = Math.min(elapsed / duration, 1);
                var eased = 1 - Math.pow(1 - t, 3);
                el.style.transform = 'rotateX(' + (fromDeg + (toDeg - fromDeg) * eased) + 'deg)';
                if (t < 1) {
                    requestAnimationFrame(step);
                } else {
                    el.style.transform = 'rotateX(' + toDeg + 'deg)';
                    if (onDone) onDone();
                }
            }
            requestAnimationFrame(step);
        }

        function buildOptions() {
            dropdown.innerHTML = '';
            optEls = [];
            for (var i = 0; i < opts.length; i++) {
                (function(idx) {
                    var panel = document.createElement('div');
                    panel.className = 'fold-panel';

                    var paper = document.createElement('div');
                    paper.className = 'fold-paper';
                    paper.style.transform = 'rotateX(92deg)';

                    var div = document.createElement('div');
                    div.className = 'custom-select-option';
                    div.dataset.value = opts[idx].value;

                    var l = document.createElement('span');
                    l.textContent = opts[idx].text;
                    div.appendChild(l);

                    div.addEventListener('click', function(e) {
                        e.stopPropagation();
                        selectOption(idx);
                        closeDropdown();
                        trigger.focus();
                    });

                    paper.appendChild(div);
                    panel.appendChild(paper);
                    dropdown.appendChild(panel);
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

        function measurePanelHeights() {
            var panels = dropdown.querySelectorAll('.fold-panel');
            var heights = [];
            dropdown.style.overflow = 'visible';
            panels.forEach(function(p) {
                var paper = p.querySelector('.fold-paper');
                paper.style.position = 'relative';
                paper.style.transform = 'none';
                p.style.height = 'auto';
                p.style.overflow = 'visible';
                heights.push(p.scrollHeight);
                p.style.height = '';
                p.style.overflow = '';
                paper.style.position = '';
                paper.style.transform = '';
            });
            dropdown.style.overflow = '';
            return heights;
        }

        function openDropdown() {
            if (isOpen || animating) return;
            animating = true;
            syncFromNative();

            trigger.classList.add('open');
            dropdown.classList.add('open');
            dropdown.style.clipPath = 'inset(0 0 100% 0)';

            var panels = dropdown.querySelectorAll('.fold-panel');

            panels.forEach(function(p) {
                p.style.height = '0';
                p.style.overflow = 'hidden';
                var paper = p.querySelector('.fold-paper');
                paper.style.transform = 'rotateX(92deg)';
                paper.style.position = '';
            });

            var heights = measurePanelHeights();

            panels.forEach(function(p, i) {
                p.style.height = heights[i] + 'px';
                p.style.overflow = 'hidden';
                var paper = p.querySelector('.fold-paper');
                paper.style.position = 'absolute';
                paper.style.transform = 'rotateX(92deg)';
            });

            var revealStart = performance.now();

            function revealStep(now) {
                if (!animating) return;
                var elapsed = now - revealStart;
                var t = Math.min(elapsed / 170, 1);
                var eased = 1 - Math.pow(1 - t, 3);
                dropdown.style.clipPath = 'inset(0 0 ' + (100 * (1 - eased)) + '% 0)';
                if (t < 1) {
                    requestAnimationFrame(revealStep);
                } else {
                    dropdown.style.clipPath = 'none';
                    dropdown.style.overflowY = 'auto';

                    var completed = 0;
                    var total = panels.length;

                    Array.prototype.forEach.call(panels, function(p, i) {
                        var paper = p.querySelector('.fold-paper');
                        var delay = 80 + i * 110;
                        var t2 = setTimeout(function() {
                            p.classList.add('folding');
                            animateRotate(paper, 92, 0, 340, function() {
                                paper.classList.add('unfolded');
                                p.classList.remove('folding');
                                completed++;
                                if (completed === total) {
                                    isOpen = true;
                                    animating = false;
                                    setTimeout(function() { dropdown.classList.add('borders-visible'); }, 80);
                                    if (optEls[selectedIdx]) {
                                        optEls[selectedIdx].scrollIntoView({ block: 'nearest' });
                                    }
                                }
                            });
                        }, delay);
                        foldTimeouts.push(t2);
                    });
                }
            }
            requestAnimationFrame(revealStep);
        }

        function abortOpen() {
            dropdown.classList.remove('borders-visible');
            foldTimeouts.forEach(function(t) { clearTimeout(t); });
            foldTimeouts = [];
            var panels = dropdown.querySelectorAll('.fold-panel');
            panels.forEach(function(p) {
                var paper = p.querySelector('.fold-paper');
                paper.classList.remove('unfolded');
                paper.style.transform = 'rotateX(92deg)';
                paper.style.position = 'absolute';
                p.classList.remove('folding');
            });
            dropdown.style.overflow = 'hidden';
            dropdown.style.clipPath = 'inset(0 0 100% 0)';
            trigger.classList.remove('open');
            dropdown.classList.remove('open');
            dropdown.style.overflowY = '';
            isOpen = false;
            animating = false;
        }

        function closeDropdown() {
            if (!isOpen && !animating) return;
            if (animating) { if (!isOpen) abortOpen(); return; }
            dropdown.classList.remove('borders-visible');

            animating = true;

            foldTimeouts.forEach(function(t) { clearTimeout(t); });
            foldTimeouts = [];

            var panels = dropdown.querySelectorAll('.fold-panel');
            var panelArr = Array.prototype.slice.call(panels);
            var total = panelArr.length;
            var completed = 0;

            panelArr.reverse().forEach(function(p, ri) {
                var paper = p.querySelector('.fold-paper');
                var delay = ri * 85;
                var t = setTimeout(function() {
                    p.classList.add('folding');
                    paper.classList.remove('unfolded');
                    animateRotate(paper, 0, 92, 270, function() {
                        p.classList.remove('folding');
                        completed++;
                        if (completed === total) {
                            dropdown.style.overflow = 'hidden';
                            var foldStart = performance.now();

                            function foldStep(now) {
                                if (!animating) return;
                                var elapsed = now - foldStart;
                                var t2 = Math.min(elapsed / 150, 1);
                                var eased = 1 - Math.pow(1 - t2, 3);
                                dropdown.style.clipPath = 'inset(0 0 ' + (100 * eased) + '% 0)';
                                if (t2 < 1) {
                                    requestAnimationFrame(foldStep);
                                } else {
                                    dropdown.style.clipPath = 'inset(0 0 100% 0)';
                                    trigger.classList.remove('open');
                                    dropdown.classList.remove('open');
                                    dropdown.style.overflowY = '';
                                    isOpen = false;
                                    animating = false;
                                }
                            }
                            requestAnimationFrame(foldStep);
                        }
                    });
                }, delay);
                foldTimeouts.push(t);
            });
        }

        function toggleDropdown() {
            if (isOpen) closeDropdown();
            else openDropdown();
        }

        dropdown._close = closeDropdown;

        trigger.addEventListener('click', toggleDropdown);

        trigger.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
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
                var dd = wraps[w].querySelector('.custom-select-dropdown');
                if (dd && dd._close) dd._close();
            }
        }
    });
})();
