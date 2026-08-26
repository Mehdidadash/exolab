/*
 * EXOLAB - Case Teeth Picker
 * assets/js/case-teeth-picker.js
 *
 * Lightweight dental tooth selector for the case modal.
 *
 * Stored format:
 *   13,14          -> two independent restorations
 *   13_14          -> one connected two-unit restoration
 *   13_14_15,16    -> connected 13-14-15 + independent 16
 */

(function (window, document) {

    'use strict';

    /*
     * ---------------------------------------------------------
     * Tooth layout
     * ---------------------------------------------------------
     *
     * Upper:
     * 18 17 16 15 14 13 12 11 | 21 22 23 24 25 26 27 28
     *
     * Lower:
     * 48 47 46 45 44 43 42 41 | 31 32 33 34 35 36 37 38
     *
     * The arrays are intentionally in visual order.
     */

    var ARCHES = [
        [18, 17, 16, 15, 14, 13, 12, 11],
        [21, 22, 23, 24, 25, 26, 27, 28],
        [48, 47, 46, 45, 44, 43, 42, 41],
        [31, 32, 33, 34, 35, 36, 37, 38]
    ];


    /*
     * ---------------------------------------------------------
     * Valid bridge connections
     * ---------------------------------------------------------
     *
     * There is intentionally NO connection between:
     *
     * 11 | 21
     * 41 | 31
     *
     * because those are the midlines of the arches.
     */

    var BRIDGE_PAIRS = [
        // Upper right
        [18, 17],
        [17, 16],
        [16, 15],
        [15, 14],
        [14, 13],
        [13, 12],
        [12, 11],

        // Upper left
        [21, 22],
        [22, 23],
        [23, 24],
        [24, 25],
        [25, 26],
        [26, 27],
        [27, 28],

        // Lower right
        [48, 47],
        [47, 46],
        [46, 45],
        [45, 44],
        [44, 43],
        [43, 42],
        [42, 41],

        // Lower left
        [31, 32],
        [32, 33],
        [33, 34],
        [34, 35],
        [35, 36],
        [36, 37],
        [37, 38]
    ];


    /*
     * ---------------------------------------------------------
     * Internal state
     * ---------------------------------------------------------
     */

    var selectedTeeth = new Set();
    var bridgeConnections = new Set();

    var lastClickedTooth = null;

    var inputElement = null;
    var pickerElement = null;
    var selectedValueElement = null;

    var initialized = false;


    /*
     * ---------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------
     */

    function makePairKey(a, b) {

        a = Number(a);
        b = Number(b);

        if (a < b) {
            return a + '-' + b;
        }

        return b + '-' + a;
    }


    function getArchForTooth(tooth) {

        tooth = Number(tooth);

        for (var i = 0; i < ARCHES.length; i++) {

            if (ARCHES[i].indexOf(tooth) !== -1) {
                return ARCHES[i];
            }

        }

        return null;
    }


    function areAdjacentTeeth(a, b) {

        var pairKey = makePairKey(a, b);

        return BRIDGE_PAIRS.some(function (pair) {

            return makePairKey(pair[0], pair[1]) === pairKey;

        });
    }


    /*
     * ---------------------------------------------------------
     * Create tooth button
     * ---------------------------------------------------------
     */

    function createToothSlot(tooth) {

        var slot = document.createElement('div');

        slot.className = 'case-tooth-slot';
        slot.dataset.tooth = String(tooth);


        /*
         * Tooth button
         */

        var toothButton = document.createElement('button');

        toothButton.type = 'button';

        toothButton.className = 'case-tooth-button';

        toothButton.dataset.tooth = String(tooth);

        toothButton.textContent = String(tooth);

        toothButton.setAttribute(
            'aria-label',
            'دندان ' + tooth
        );

        toothButton.setAttribute(
            'aria-pressed',
            'false'
        );


        toothButton.addEventListener('click', function (event) {

            event.preventDefault();


            /*
             * Shift + click:
             * select the range between the last clicked
             * tooth and this tooth.
             */

            if (
                event.shiftKey &&
                lastClickedTooth !== null
            ) {

                selectRange(
                    lastClickedTooth,
                    tooth
                );

            } else {

                toggleTooth(tooth);

            }


            lastClickedTooth = tooth;

        });


        /*
         * Number below the tooth
         */

        var number = document.createElement('span');

        number.className = 'case-tooth-number';

        number.textContent = String(tooth);


        slot.appendChild(toothButton);

        slot.appendChild(number);


        return slot;
    }


    /*
     * ---------------------------------------------------------
     * Create bridge / piano key
     * ---------------------------------------------------------
     */

    function createBridgeButton(a, b) {

        var button = document.createElement('button');

        button.type = 'button';

        button.className = 'case-bridge-key';

        button.dataset.a = String(a);

        button.dataset.b = String(b);


        button.title =
            'اتصال دندان ' +
            a +
            ' و ' +
            b;


        button.setAttribute(
            'aria-label',
            'اتصال دندان ' + a + ' و ' + b
        );

        button.setAttribute(
            'aria-pressed',
            'false'
        );


        button.addEventListener('click', function (event) {

            event.preventDefault();

            event.stopPropagation();


            /*
             * A bridge key is only usable when
             * both adjacent teeth have been selected.
             */

            if (
                !selectedTeeth.has(Number(a)) ||
                !selectedTeeth.has(Number(b))
            ) {

                return;

            }


            var pairKey = makePairKey(a, b);


            if (bridgeConnections.has(pairKey)) {

                bridgeConnections.delete(pairKey);

            } else {

                bridgeConnections.add(pairKey);

            }


            render();

        });


        return button;
    }


    /*
     * ---------------------------------------------------------
     * Create one half of an arch
     * ---------------------------------------------------------
     */

    function createHalf(teeth) {

        var half = document.createElement('div');

        half.className =
            'case-teeth-picker__half';


        teeth.forEach(function (tooth, index) {

            var slot = createToothSlot(tooth);


            /*
             * Put the piano key between this tooth
             * and the next tooth.
             */

            if (index < teeth.length - 1) {

                slot.appendChild(
                    createBridgeButton(
                        tooth,
                        teeth[index + 1]
                    )
                );

            }


            half.appendChild(slot);

        });


        return half;
    }


    /*
     * ---------------------------------------------------------
     * Create complete upper/lower arch
     * ---------------------------------------------------------
     */

    function createArch(
        title,
        leftSide,
        rightSide
    ) {

        var section =
            document.createElement('section');

        section.className =
            'case-teeth-picker__arch';


        /*
         * Arch title
         */

        var heading =
            document.createElement('h4');

        heading.className =
            'case-teeth-picker__arch-title';

        heading.textContent = title;


        /*
         * Row
         */

        var row =
            document.createElement('div');

        row.className =
            'case-teeth-picker__row';


        /*
         * Left half
         */

        row.appendChild(
            createHalf(leftSide)
        );


        /*
         * Midline
         */

        var midline =
            document.createElement('div');

        midline.className =
            'case-teeth-picker__midline';

        midline.setAttribute(
            'aria-hidden',
            'true'
        );


        row.appendChild(midline);


        /*
         * Right half
         */

        row.appendChild(
            createHalf(rightSide)
        );


        section.appendChild(heading);

        section.appendChild(row);


        return section;
    }


    /*
     * ---------------------------------------------------------
     * Build picker UI
     * ---------------------------------------------------------
     */

    function buildPicker() {

        if (!pickerElement) {
            return;
        }


        /*
         * Header
         */

        var header =
            document.createElement('div');

        header.className =
            'case-teeth-picker__header';


        var headerText =
            document.createElement('div');


        /*
         * Title
         */

        var title =
            document.createElement('h4');

        title.className =
            'case-teeth-picker__title';

        title.textContent =
            'انتخاب دندان‌ها';


        /*
         * Help text
         */

        var help =
            document.createElement('p');

        help.className =
            'case-teeth-picker__help';

        help.textContent =
            'کلیک = انتخاب · Shift + کلیک = انتخاب محدوده · کلید بین دندان‌ها = اتصال';


        headerText.appendChild(title);

        headerText.appendChild(help);


        /*
         * Clear button
         */

        var clearButton =
            document.createElement('button');

        clearButton.type = 'button';

        clearButton.className =
            'case-teeth-picker__clear';

        clearButton.textContent =
            'پاک کردن';


        clearButton.addEventListener(
            'click',
            function () {

                selectedTeeth.clear();

                bridgeConnections.clear();

                lastClickedTooth = null;

                render();

            }
        );


        header.appendChild(headerText);

        header.appendChild(clearButton);


        /*
         * Upper arch
         */

        var upperArch =
            createArch(
                'فک بالا',
                ARCHES[0],
                ARCHES[1]
            );


        /*
         * Lower arch
         */

        var lowerArch =
            createArch(
                'فک پایین',
                ARCHES[2],
                ARCHES[3]
            );


        /*
         * Selected value
         */

        var selectedBox =
            document.createElement('div');

        selectedBox.className =
            'case-teeth-picker__selected';


        var selectedLabel =
            document.createElement('span');

        selectedLabel.className =
            'case-teeth-picker__selected-label';

        selectedLabel.textContent =
            'دندان‌های انتخاب‌شده:';


        selectedValueElement =
            document.createElement('strong');

        selectedValueElement.className =
            'case-teeth-picker__selected-value';

        selectedValueElement.textContent =
            '—';


        selectedBox.appendChild(
            selectedLabel
        );

        selectedBox.appendChild(
            selectedValueElement
        );


        /*
         * Note
         */

        var note =
            document.createElement('div');

        note.className =
            'case-teeth-picker__note';

        note.textContent =
            'برای بریج، دندان‌ها را انتخاب کنید و سپس کلید کوچک بین دندان‌های مجاور را فعال کنید.';


        /*
         * Add everything
         */

        pickerElement.appendChild(header);

        pickerElement.appendChild(upperArch);

        pickerElement.appendChild(lowerArch);

        pickerElement.appendChild(selectedBox);

        pickerElement.appendChild(note);

    }


    /*
     * ---------------------------------------------------------
     * Toggle one tooth
     * ---------------------------------------------------------
     */

    function toggleTooth(tooth) {

        tooth = Number(tooth);


        if (selectedTeeth.has(tooth)) {

            /*
             * Remove tooth
             */

            selectedTeeth.delete(tooth);


            /*
             * Remove all bridge connections
             * involving this tooth.
             */

            Array.from(
                bridgeConnections
            ).forEach(function (pairKey) {

                var parts =
                    pairKey
                        .split('-')
                        .map(Number);


                if (
                    parts[0] === tooth ||
                    parts[1] === tooth
                ) {

                    bridgeConnections.delete(
                        pairKey
                    );

                }

            });


        } else {

            /*
             * Select tooth
             */

            selectedTeeth.add(tooth);

        }


        render();

    }


    /*
     * ---------------------------------------------------------
     * Shift + click range selection
     * ---------------------------------------------------------
     */

    function selectRange(from, to) {

        var fromArch =
            getArchForTooth(from);

        var toArch =
            getArchForTooth(to);


        /*
         * Do not allow Shift selection
         * across different arches.
         */

        if (
            !fromArch ||
            !toArch ||
            fromArch !== toArch
        ) {

            toggleTooth(to);

            return;

        }


        var fromIndex =
            fromArch.indexOf(
                Number(from)
            );

        var toIndex =
            fromArch.indexOf(
                Number(to)
            );


        if (
            fromIndex === -1 ||
            toIndex === -1
        ) {

            toggleTooth(to);

            return;

        }


        var start =
            Math.min(
                fromIndex,
                toIndex
            );

        var end =
            Math.max(
                fromIndex,
                toIndex
            );


        for (
            var i = start;
            i <= end;
            i++
        ) {

            selectedTeeth.add(
                Number(fromArch[i])
            );

        }


        render();

    }


    /*
     * ---------------------------------------------------------
     * Convert selected teeth + bridges to database format
     * ---------------------------------------------------------
     *
     * Examples:
     *
     * 13 + 14
     * => 13,14
     *
     * 13 + 14 + bridge(13,14)
     * => 13_14
     *
     * 13 + 14 + 15
     * with bridges 13-14 and 14-15
     * => 13_14_15
     */

    function serialize() {

        if (selectedTeeth.size === 0) {
            return '';
        }


        /*
         * Copy selected teeth.
         */

        var remaining =
            new Set(
                Array.from(
                    selectedTeeth
                )
            );


        var groups = [];


        /*
         * Find connected components.
         */

        while (remaining.size > 0) {

            var first =
                remaining.values()
                    .next()
                    .value;


            var queue = [first];

            var group = [];


            remaining.delete(first);


            while (queue.length > 0) {

                var current =
                    queue.shift();


                group.push(current);


                /*
                 * Find every bridge connected
                 * to the current tooth.
                 */

                Array.from(
                    bridgeConnections
                ).forEach(function (pairKey) {

                    var parts =
                        pairKey
                            .split('-')
                            .map(Number);


                    var a = parts[0];

                    var b = parts[1];


                    if (
                        a === current &&
                        remaining.has(b)
                    ) {

                        remaining.delete(b);

                        queue.push(b);

                    } else if (
                        b === current &&
                        remaining.has(a)
                    ) {

                        remaining.delete(a);

                        queue.push(a);

                    }

                });

            }


            /*
             * Sort teeth inside each connected group NUMERICALLY so bridges read
             * the way the user entered them (42_43_44_45), not by arch position
             * (which would output 45_44_43_42 for the lower-right arch).
             */

            group.sort(function (a, b) {

                return Number(a) - Number(b);

            });


            groups.push(group);

        }


        /*
         * Stable order between independent groups.
         */

        groups.sort(function (a, b) {

            return (
                Number(a[0]) -
                Number(b[0])
            );

        });


        /*
         * Convert groups:
         *
         * [13]       -> 13
         * [13,14]    -> 13_14
         * [13,14,15] -> 13_14_15
         */

        return groups
            .map(function (group) {

                if (group.length > 1) {

                    return group.join('_');

                }

                return String(group[0]);

            })
            .join(',');

    }


    /*
     * ---------------------------------------------------------
     * Render visual state
     * ---------------------------------------------------------
     */

    function render() {

        if (
            !pickerElement ||
            !inputElement
        ) {

            return;

        }


        /*
         * Tooth buttons
         */

        pickerElement
            .querySelectorAll(
                '.case-tooth-button'
            )
            .forEach(function (button) {

                var tooth =
                    Number(
                        button.dataset.tooth
                    );


                var active =
                    selectedTeeth.has(tooth);


                button.classList.toggle(
                    'is-selected',
                    active
                );


                button.setAttribute(
                    'aria-pressed',
                    active
                        ? 'true'
                        : 'false'
                );

            });


        /*
         * Bridge keys
         */

        pickerElement
            .querySelectorAll(
                '.case-bridge-key'
            )
            .forEach(function (button) {

                var a =
                    Number(
                        button.dataset.a
                    );

                var b =
                    Number(
                        button.dataset.b
                    );


                var bothSelected =
                    selectedTeeth.has(a) &&
                    selectedTeeth.has(b);


                var active =
                    bridgeConnections.has(
                        makePairKey(a, b)
                    );


                button.classList.toggle(
                    'is-disabled',
                    !bothSelected
                );


                button.classList.toggle(
                    'is-active',
                    active
                );


                button.setAttribute(
                    'aria-pressed',
                    active
                        ? 'true'
                        : 'false'
                );

            });


        /*
         * Database value
         */

        var value =
            serialize();


        inputElement.value = value;


        /*
         * Visible selected value
         */

        if (selectedValueElement) {

            selectedValueElement.textContent =
                value || '—';

        }

    }


    /*
     * ---------------------------------------------------------
     * Restore existing database value
     * ---------------------------------------------------------
     *
     * Examples:
     *
     * 13,14
     * 13_14
     * 13_14_15,16
     */

    function parseStoredValue(value) {

        selectedTeeth.clear();

        bridgeConnections.clear();

        lastClickedTooth = null;


        if (!value) {

            render();

            return;

        }


        /*
         * Legacy data may contain Persian separators and digits (e.g. a value
         * typed into the old text field like "26،42_43_44_45"). Normalize them
         * so those values load correctly.
         */

        var normalized = String(value)
            .replace(/،/g, ',')
            .replace(/[۰-۹]/g, function (d) {

                return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d));

            })
            .replace(/\s+/g, '');


        normalized
            .split(',')
            .map(function (group) {

                return group.trim();

            })
            .filter(Boolean)
            .forEach(function (group) {

                var teeth =
                    group
                        .split('_')
                        .map(function (tooth) {

                            return Number(
                                tooth.trim()
                            );

                        })
                        .filter(function (tooth) {

                            return !!getArchForTooth(
                                tooth
                            );

                        });


                /*
                 * Select all teeth.
                 */

                teeth.forEach(function (tooth) {

                    selectedTeeth.add(tooth);

                });


                /*
                 * Every "_" means a bridge
                 * between consecutive teeth.
                 */

                for (
                    var i = 0;
                    i < teeth.length - 1;
                    i++
                ) {

                    var a = teeth[i];

                    var b = teeth[i + 1];


                    if (
                        areAdjacentTeeth(
                            a,
                            b
                        )
                    ) {

                        bridgeConnections.add(
                            makePairKey(a, b)
                        );

                    }

                }

            });


        render();

    }


    /*
     * ---------------------------------------------------------
     * Initialize
     * ---------------------------------------------------------
     */

    function init() {

        if (initialized) {
            return true;
        }


        inputElement =
            document.getElementById(
                'case-teeth'
            );


        pickerElement =
            document.getElementById(
                'case-teeth-picker'
            );


        /*
         * If the required elements are not
         * in the DOM yet, wait for cases.php.
         */

        if (
            !inputElement ||
            !pickerElement
        ) {

            return false;

        }


        buildPicker();


        /*
         * Restore existing value.
         */

        parseStoredValue(
            inputElement.value || ''
        );


        initialized = true;


        return true;

    }


    /*
     * ---------------------------------------------------------
     * Public API
     * ---------------------------------------------------------
     *
     * cases.php can use:
     *
     * CaseTeethPicker.init()
     * CaseTeethPicker.reset()
     * CaseTeethPicker.setValue('13_14')
     * CaseTeethPicker.getValue()
     */

    window.CaseTeethPicker = {

        init: function () {

            return init();

        },


        reset: function () {

            if (!init()) {
                return;
            }


            selectedTeeth.clear();

            bridgeConnections.clear();

            lastClickedTooth = null;

            render();

        },


        setValue: function (value) {

            if (!init()) {
                return;
            }


            parseStoredValue(
                value || ''
            );

        },


        getValue: function () {

            if (!init()) {
                return '';
            }


            return serialize();

        }

    };


    /*
     * ---------------------------------------------------------
     * Auto initialization
     * ---------------------------------------------------------
     */

    if (
        document.readyState === 'loading'
    ) {

        document.addEventListener(
            'DOMContentLoaded',
            init
        );

    } else {

        init();

    }


})(window, document);