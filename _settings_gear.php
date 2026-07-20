    <div class="settings-gear">
        <img src="images/doodles/gear.svg" alt="Settings" id="settings-btn">
        <div class="settings-dropdown hidden" id="settings-dropdown">
            <div class="settings-header">Disable —</div>
            <label><input type="checkbox" id="spotlight-toggle" class="custom-checkbox"> Spotlight of Shame</label>
            <label><input type="checkbox" id="doodles-toggle" class="custom-checkbox"> decorative doodles</label>
            <label><input type="checkbox" id="bg-toggle" class="custom-checkbox"> background</label>
            <label><input type="checkbox" id="animations-toggle" class="custom-checkbox"> animations</label>
            <div class="settings-divider"></div>
            <div class="settings-header">Display Tuning</div>
            <input type="range" id="sat-slider" min="0" max="2" step="0.01" value="1" hidden>
            <input type="range" id="temp-slider" min="-100" max="100" step="1" value="0" hidden>
            <div class="display-row">
                <label>Saturation</label>
                <div class="display-slider-row">
                    <div class="display-custom-slider" data-for="sat-slider">
                        <div class="display-custom-track"></div>
                        <img class="display-custom-thumb" src="images/doodles/star.svg" draggable="false">
                    </div>
                    <button class="display-reset-btn" data-slider="sat-slider">↺</button>
                </div>
            </div>
            <div class="display-row">
                <label>Warmth</label>
                <div class="display-slider-row">
                    <div class="display-custom-slider" data-for="temp-slider">
                        <div class="display-custom-track"></div>
                        <img class="display-custom-thumb" src="images/doodles/star.svg" draggable="false">
                    </div>
                    <button class="display-reset-btn" data-slider="temp-slider">↺</button>
                </div>
            </div>
        </div>
    </div>
