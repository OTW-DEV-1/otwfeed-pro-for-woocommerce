/* global jQuery, otwfeedAdmin */
(function ($) {
    'use strict';

    const cfg     = window.otwfeedAdmin || {};
    const ajaxUrl = cfg.ajaxurl || '';
    const nonce   = cfg.nonce   || '';
    const i18n    = cfg.i18n    || {};

    // Cached WC fields loaded once via AJAX.
    let wcFields = null;

    // ── Helpers ──────────────────────────────────────────────────────────────

    function post(action, data) {
        return $.post(ajaxUrl, Object.assign({ action, nonce }, data));
    }

    function setStatus($el, msg, type) {
        $el.text(msg).attr('class', 'otwfeed-save-status' + (type ? ' is-' + type : ''));
    }

    /**
     * Fetch WC fields (attributes / meta_keys / taxonomies) once then cache.
     * @param {Function} cb  called with the data object
     */
    function loadWcFields(cb) {
        if (wcFields) { cb(wcFields); return; }
        post('otwfeed_get_wc_fields', {})
            .done(function (res) {
                if (res.success) {
                    wcFields = res.data;
                    cb(wcFields);
                }
            });
    }

    // ── Smart source-value cell ───────────────────────────────────────────────

    /**
     * Rebuild the source-value cell for one mapping row after a type change.
     *
     * Rules:
     *  attribute  → grouped Select2 (standard fields + WC PA attributes)
     *  meta       → Select2 with tags=true (known meta keys + free-type custom)
     *  taxonomy   → Select2 (product taxonomies)
     *  static     → plain text input
     */
    function initSourceValCell($row, fields) {
        const type     = $row.find('.otwfeed-source-type').val();
        const $cell    = $row.find('.otwfeed-source-val-cell');
        const $wrap    = $row.closest('.otwfeed-wrap');

        // Grab current value before we destroy anything.
        const current  = $cell.find('.otwfeed-source-key-select').data('current')
                      || $cell.find('.otwfeed-source-key-select').val()
                      || $cell.find('.otwfeed-source-static').val()
                      || '';

        // Preserve price_round checkbox state into data attribute before rebuilding.
        const $existingRound = $cell.find('.otwfeed-round-wrap input[type="checkbox"]');
        if ($existingRound.length) {
            $row.data('price-round', $existingRound.is(':checked') ? '1' : '0');
        }

        // Tear down existing Select2 instance.
        const $old = $cell.find('.otwfeed-source-key-select');
        if ($old.length && $.fn.select2 && $old.data('select2')) {
            $old.select2('destroy');
        }

        if (type === 'static') {
            $cell.html(
                '<input type="text"' +
                '       name="mappings[source_key][]"' +
                '       class="form-control form-control-sm otwfeed-source-static"' +
                '       value="' + escAttr(current) + '"' +
                '       placeholder="' + escAttr(i18n.staticPlaceholder || 'Enter static value…') + '"' +
                '       aria-label="' + escAttr(i18n.staticLabel || 'Static value') + '">'
            );
            return;
        }

        // Build <select> options based on type.
        const $select = $('<select>', {
            name:          'mappings[source_key][]',
            class:         'form-select form-select-sm otwfeed-source-key-select',
            'data-current': current,
            'data-type':    type,
            'aria-label':   i18n.sourceLabel || 'Source field',
        });

        if (type === 'attribute') {
            const groups = {};
            (fields.attributes || []).forEach(function (a) {
                if (!groups[a.group]) groups[a.group] = [];
                groups[a.group].push(a);
            });
            Object.keys(groups).forEach(function (groupName) {
                const $g = $('<optgroup>', { label: groupName });
                groups[groupName].forEach(function (a) {
                    $g.append($('<option>', { value: a.value, text: a.label }));
                });
                $select.append($g);
            });

        } else if (type === 'meta') {
            const $g = $('<optgroup>', { label: i18n.metaKeysLabel || 'Known Meta Keys' });
            (fields.meta_keys || []).forEach(function (k) {
                $g.append($('<option>', { value: k, text: k }));
            });
            $select.append($g);

        } else if (type === 'taxonomy') {
            (fields.taxonomies || []).forEach(function (t) {
                $select.append($('<option>', { value: t.value, text: t.label }));
            });
        }

        $cell.html($select);

        // Set selected value — add as custom option if not in list (covers saved custom meta keys).
        if (current && !$select.find('option[value="' + current + '"]').length) {
            $select.prepend($('<option>', { value: current, text: current }));
        }
        $select.val(current);

        // Init Select2.
        if ($.fn.select2) {
            const s2opts = {
                width:         '100%',
                dropdownParent: $wrap,
                tags:           type === 'meta',   // free-type for meta keys
                placeholder:    type === 'meta' ? (i18n.metaPlaceholder || 'Select or type a meta key…') : '',
            };
            if (type !== 'meta') {
                s2opts.minimumResultsForSearch = 5;
            }
            $select.select2(s2opts);
        }

        // Show price-round checkbox when attribute=price.
        if (type === 'attribute') {
            _updatePriceRoundCheckbox($row, $cell, current);
            $select.on('select2:select', function (e) {
                _updatePriceRoundCheckbox($row, $cell, e.params.data.id);
            });
        }
    }

    function _updatePriceRoundCheckbox($row, $cell, selectedKey) {
        $cell.find('.otwfeed-round-wrap').remove();
        if (selectedKey === 'price') {
            const isRound = String($row.data('price-round')) === '1';
            $cell.append(
                '<label class="otwfeed-round-wrap">' +
                '<input type="checkbox" name="mappings[price_round][]" value="1"' +
                (isRound ? ' checked' : '') +
                '><span>' + (i18n.roundPrice || 'Round price') + '</span></label>'
            );
        }
    }

    /** Initialise every existing mapping row in a tbody after WC fields are loaded. */
    function initAllMappingRows($tbody, fields) {
        $tbody.find('.otwfeed-mapping-row').each(function () {
            const $row = $(this);
            // Only rebuild rows that already have the select variant (not static text inputs).
            if ($row.find('.otwfeed-source-key-select').length) {
                initSourceValCell($row, fields);
            }
        });
    }

    /** Simple attribute escaping for inline HTML construction. */
    function escAttr(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // ── source_type change handler (delegated) ────────────────────────────────

    function bindSourceTypeChange($scope) {
        $scope.on('change', '.otwfeed-source-type', function () {
            const $row = $(this).closest('.otwfeed-mapping-row');
            loadWcFields(function (fields) {
                initSourceValCell($row, fields);
            });
        });
    }

    // ── collectTableRows — skips .otwfeed-source-static in non-static rows ────

    function collectTableRows(tbodySelector, rowSelector) {
        const rows = {};
        let idx = 0;
        $(tbodySelector).find(rowSelector).each(function () {
            rows[idx] = {};
            $(this).find('input:not([disabled]), select:not([disabled])').each(function () {
                const rawName = $(this).attr('name') || '';
                const match   = rawName.match(/\[([^\]]+)\]\[\]/);
                if (!match) return;
                if ($(this).attr('type') === 'checkbox') {
                    rows[idx][match[1]] = $(this).is(':checked') ? '1' : '0';
                } else {
                    rows[idx][match[1]] = $(this).val();
                }
            });
            idx++;
        });
        return rows;
    }

    // ── addMappingRow — creates a new row then inits it ───────────────────────

    function addMappingRow(tbodySelector, $wrap) {
        const $row = $(`
            <tr class="otwfeed-mapping-row" draggable="true" data-price-round="0">
                <td class="otwfeed-drag-handle">
                    <span class="dashicons dashicons-menu" aria-hidden="true"></span>
                </td>
                <td>
                    <input type="text" name="mappings[channel_tag][]"
                           class="form-control form-control-sm"
                           aria-label="Channel tag">
                </td>
                <td>
                    <select name="mappings[source_type][]"
                            class="form-select form-select-sm otwfeed-source-type"
                            aria-label="Source type">
                        <option value="attribute" selected>Attribute</option>
                        <option value="meta">Meta Field</option>
                        <option value="taxonomy">Taxonomy</option>
                        <option value="static">Static Value</option>
                    </select>
                </td>
                <td class="otwfeed-source-val-cell">
                    <select name="mappings[source_key][]"
                            class="form-select form-select-sm otwfeed-source-key-select"
                            data-current=""
                            data-type="attribute"
                            aria-label="Source field">
                    </select>
                </td>
                <td>
                    <button type="button" class="btn btn-xs btn-ghost-danger otwfeed-remove-row"
                            aria-label="Remove row">&times;</button>
                </td>
            </tr>`);

        $(tbodySelector).append($row);

        loadWcFields(function (fields) {
            initSourceValCell($row, fields);
        });
    }

    // ── Filter Group Builder ──────────────────────────────────────────────────

    let _filterGroupCounter = 0;

    function _buildAttrOptions(selected) {
        const attrs = cfg.filterAttrs || {};
        return Object.entries(attrs).map(function ([v, l]) {
            return '<option value="' + escAttr(v) + '"' + (v === selected ? ' selected' : '') + '>' + escAttr(l) + '</option>';
        }).join('');
    }

    function _buildCondOptions(selected) {
        const conds = cfg.filterConds || {};
        return Object.entries(conds).map(function ([v, l]) {
            return '<option value="' + escAttr(v) + '"' + (v === selected ? ' selected' : '') + '>' + escAttr(l) + '</option>';
        }).join('');
    }

    function _makeConditionRow(attr, condOp, value, caseSensitive) {
        attr          = attr          || 'price';
        condOp        = condOp        || 'lt';
        value         = value         !== undefined ? value : '';
        caseSensitive = caseSensitive || 0;
        const noVal   = (condOp === 'is_empty' || condOp === 'is_not_empty');
        return '<div class="otwfeed-fc-row">' +
            '<div class="otwfeed-fc-attr"><select class="form-select form-select-sm otwfeed-fc-attr-sel" aria-label="' + escAttr(i18n.attribute || 'Attribute') + '">' + _buildAttrOptions(attr) + '</select></div>' +
            '<div class="otwfeed-fc-op"><select class="form-select form-select-sm otwfeed-fc-op-sel" aria-label="' + escAttr(i18n.condition || 'Condition') + '">' + _buildCondOptions(condOp) + '</select></div>' +
            '<div class="otwfeed-fc-val"><input type="text" class="form-control form-control-sm otwfeed-fc-val-input" value="' + escAttr(value) + '" placeholder="' + escAttr(i18n.enterValue || 'Enter value') + '"' + (noVal ? ' disabled' : '') + '></div>' +
            '<label class="otwfeed-fc-case" title="' + escAttr(i18n.caseSensitive || 'Case sensitive') + '"><input type="checkbox" class="otwfeed-fc-case-input"' + (caseSensitive ? ' checked' : '') + '> Aa</label>' +
            '<button type="button" class="btn btn-xs btn-ghost-danger otwfeed-fc-remove" aria-label="Remove">&times;</button>' +
            '</div>';
    }

    function makeFilterGroup(groupId, groupAction, conditions) {
        if (groupId === undefined) groupId = _filterGroupCounter++;
        groupAction = groupAction || 'exclude';
        conditions  = conditions  || [];

        const condHtml = conditions.length
            ? conditions.map(function (c) { return _makeConditionRow(c.attribute, c.condition_op, c.value, c.case_sensitive); }).join('')
            : _makeConditionRow();

        return '<div class="otwfeed-filter-group" data-group-id="' + groupId + '">' +
            '<div class="otwfeed-fg-header">' +
                '<select class="form-select form-select-sm otwfeed-fg-action" aria-label="Group action">' +
                    '<option value="exclude"' + (groupAction === 'exclude' ? ' selected' : '') + '>' + escAttr(i18n.exclude || 'Exclude') + '</option>' +
                    '<option value="include"' + (groupAction === 'include' ? ' selected' : '') + '>' + escAttr(i18n.include || 'Include') + '</option>' +
                '</select>' +
                '<button type="button" class="btn btn-xs btn-ghost-danger otwfeed-fg-remove" aria-label="Remove group"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>' +
            '</div>' +
            '<div class="otwfeed-fg-body">' +
                '<div class="otwfeed-fg-if-label">IF...</div>' +
                '<div class="otwfeed-fg-conditions">' + condHtml + '</div>' +
                '<button type="button" class="btn btn-xs btn-outline-secondary otwfeed-fg-add-cond mt-2">+ ' + escAttr(i18n.addCondition || 'Add Condition') + '</button>' +
            '</div>' +
        '</div>';
    }

    function collectFilterGroups(containerSelector) {
        const result = {};
        let idx = 0;
        $(containerSelector).find('.otwfeed-filter-group').each(function () {
            const groupId     = $(this).data('group-id');
            const groupAction = $(this).find('.otwfeed-fg-action').val();
            $(this).find('.otwfeed-fc-row').each(function () {
                result[idx] = {
                    group_id:      groupId,
                    group_action:  groupAction,
                    attribute:     $(this).find('.otwfeed-fc-attr-sel').val()       || 'price',
                    condition_op:  $(this).find('.otwfeed-fc-op-sel').val()         || 'lt',
                    value:         $(this).find('.otwfeed-fc-val-input').val()      || '',
                    case_sensitive: $(this).find('.otwfeed-fc-case-input').is(':checked') ? '1' : '0',
                };
                idx++;
            });
        });
        return result;
    }

    function initFilterBuilder(containerSelector) {
        const $c = $(containerSelector);
        if (!$c.length) return;

        // Sync counter with any PHP-rendered groups.
        $c.find('.otwfeed-filter-group').each(function () {
            const id = parseInt($(this).data('group-id')) || 0;
            if (id >= _filterGroupCounter) _filterGroupCounter = id + 1;
        });

        // Condition operator change → disable/enable value input.
        $c.on('change', '.otwfeed-fc-op-sel', function () {
            const op    = $(this).val();
            const noVal = (op === 'is_empty' || op === 'is_not_empty');
            $(this).closest('.otwfeed-fc-row').find('.otwfeed-fc-val-input').prop('disabled', noVal);
        });

        // Add condition within group.
        $c.on('click', '.otwfeed-fg-add-cond', function () {
            $(this).closest('.otwfeed-filter-group').find('.otwfeed-fg-conditions').append(_makeConditionRow());
        });

        // Remove single condition (remove whole group if it was the last one).
        $c.on('click', '.otwfeed-fc-remove', function () {
            const $row   = $(this).closest('.otwfeed-fc-row');
            const $group = $row.closest('.otwfeed-filter-group');
            if ($group.find('.otwfeed-fc-row').length > 1) {
                $row.remove();
            } else {
                $group.remove();
                _syncFilterEmpty(containerSelector);
            }
        });

        // Remove entire group.
        $c.on('click', '.otwfeed-fg-remove', function () {
            $(this).closest('.otwfeed-filter-group').remove();
            _syncFilterEmpty(containerSelector);
        });
    }

    function _syncFilterEmpty(containerSelector) {
        const $c      = $(containerSelector);
        const hasGrps = $c.find('.otwfeed-filter-group').length > 0;
        $c.find('.otwfeed-filter-empty').toggleClass('d-none', hasGrps);
    }

    function addFilterGroup(containerSelector) {
        $(containerSelector).find('.otwfeed-filter-empty').addClass('d-none');
        $(containerSelector).append(makeFilterGroup());
    }

    // ── Drag/sort rows ────────────────────────────────────────────────────────

    function initDragSort(tbodySelector) {
        const $tbody = $(tbodySelector);
        let $dragRow = null;

        $tbody.on('dragstart', '.otwfeed-mapping-row', function (e) {
            $dragRow = $(this);
            $dragRow.addClass('dragging');
            e.originalEvent.dataTransfer.effectAllowed = 'move';
        });

        $tbody.on('dragend', '.otwfeed-mapping-row', function () {
            $(this).removeClass('dragging');
            $dragRow = null;
        });

        $tbody.on('dragover', '.otwfeed-mapping-row', function (e) {
            e.preventDefault();
            if (!$dragRow || $dragRow[0] === this) return;
            const $target = $(this);
            const midY    = $target.offset().top + $target.outerHeight() / 2;
            if (e.originalEvent.clientY < midY) {
                $dragRow.insertBefore($target);
            } else {
                $dragRow.insertAfter($target);
            }
        });
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    function initDashboard() {
        const $wrap      = $('.otwfeed-wrap');
        const activePolls = {}; // feed_id → setInterval handle

        // ── Progress bar helpers ──────────────────────────────────────────────

        function makeProgressBar() {
            return $(
                '<div class="otwfeed-progress">' +
                    '<div class="otwfeed-progress-track"><div class="otwfeed-progress-bar"></div></div>' +
                    '<div class="otwfeed-progress-label"></div>' +
                '</div>'
            );
        }

        function setProgress($bar, pct, label, isError) {
            $bar.find('.otwfeed-progress-bar').css('width', pct + '%');
            $bar.find('.otwfeed-progress-label')
                .text(label)
                .toggleClass('otwfeed-progress-label--error', !!isError);
        }

        function attachProgressBar($btn, initialLabel) {
            const $bar = makeProgressBar();
            $btn.closest('td').append($bar);
            $btn.prop('disabled', true);
            setProgress($bar, 0, initialLabel || (i18n.queued || 'Queued…'));
            return $bar;
        }

        function stopPolling(feedId, $btn, $bar, delay) {
            clearInterval(activePolls[feedId]);
            delete activePolls[feedId];
            setTimeout(() => {
                $bar.remove();
                $btn.prop('disabled', false);
            }, delay || 0);
        }

        // ── Core poller ───────────────────────────────────────────────────────

        function startPolling(feedId, $btn, $bar) {
            if (activePolls[feedId]) clearInterval(activePolls[feedId]);

            activePolls[feedId] = setInterval(function () {
                post('otwfeed_get_progress', { id: feedId })
                    .done(function (res) {
                        if (!res.success) return;
                        const p = res.data;

                        if (p.status === 'queued') {
                            setProgress($bar, 0, i18n.queued || 'Queued…');

                        } else if (p.status === 'running') {
                            const pct   = p.total > 0 ? Math.round((p.processed / p.total) * 100) : 0;
                            const label = p.processed + ' / ' + p.total + ' (' + pct + '%)';
                            setProgress($bar, pct, label);

                        } else if (p.status === 'done') {
                            setProgress($bar, 100, (i18n.done || 'Done') + ' — ' + p.products_written + ' products');
                            const now = new Date();
                            const ts  = now.toLocaleDateString() + ' ' + now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                            $btn.closest('tr').find('td.text-muted.small').text(ts);
                            stopPolling(feedId, $btn, $bar, 4000);

                        } else if (p.status === 'error') {
                            setProgress($bar, 0, (i18n.error || 'Error') + (p.error ? ': ' + p.error : ''), true);
                            stopPolling(feedId, $btn, $bar, 6000);

                        } else {
                            // idle or unknown — stop quietly
                            stopPolling(feedId, $btn, $bar, 0);
                        }
                    });
            }, 3000);
        }

        // ── Generate button ───────────────────────────────────────────────────

        $wrap.on('click', '.otwfeed-btn-generate', function () {
            const $btn  = $(this);
            const id    = $btn.data('id');
            if (!confirm(i18n.confirmRegen || 'Regenerate?')) return;

            const $bar = attachProgressBar($btn, i18n.queued || 'Queued…');

            post('otwfeed_generate_async', { id })
                .done(function (res) {
                    if (!res.success) {
                        alert((res.data && res.data.message) || i18n.error || 'Error');
                        $bar.remove();
                        $btn.prop('disabled', false);
                        return;
                    }
                    startPolling(id, $btn, $bar);
                })
                .fail(function () {
                    alert(i18n.error || 'Error');
                    $bar.remove();
                    $btn.prop('disabled', false);
                });
        });

        // ── Delete button ─────────────────────────────────────────────────────

        $wrap.on('click', '.otwfeed-btn-delete', function () {
            const $btn = $(this);
            if (!confirm(i18n.confirmDelete || 'Delete?')) return;
            const id = $btn.data('id');
            // Stop any running poll for this feed.
            if (activePolls[id]) {
                clearInterval(activePolls[id]);
                delete activePolls[id];
            }
            post('otwfeed_delete_feed', { id })
                .done(function (res) {
                    if (res.success) {
                        $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
                    } else {
                        alert((res.data && res.data.message) || i18n.error || 'Error');
                    }
                });
        });

        // ── Resume in-progress bars on page load ──────────────────────────────

        if (window.otwfeedActiveGenerations) {
            Object.entries(window.otwfeedActiveGenerations).forEach(function ([feedId, progress]) {
                feedId = parseInt(feedId, 10);
                const $btn = $wrap.find('.otwfeed-btn-generate[data-id="' + feedId + '"]');
                if (!$btn.length) return;
                const pct   = progress.total > 0 ? Math.round((progress.processed / progress.total) * 100) : 0;
                const label = progress.status === 'queued'
                    ? (i18n.queued || 'Queued…')
                    : progress.processed + ' / ' + progress.total + ' (' + pct + '%)';
                const $bar = attachProgressBar($btn, label);
                setProgress($bar, pct, label);
                startPolling(feedId, $btn, $bar);
            });
        }
    }

    // ── Wizard ────────────────────────────────────────────────────────────────

    function initWizard() {
        const $form = $('#otwfeed-wizard-form');
        if (!$form.length) return;

        let currentStep  = 1;
        const totalSteps = 4;
        let savedFeedId  = parseInt($('#otwfeed-feed-id').val()) || 0;
        const $wrap      = $form.closest('.otwfeed-wrap');

        function showStep(step) {
            $('.otwfeed-step-panel').addClass('d-none');
            $('#otwfeed-step-' + step).removeClass('d-none');

            $('.otwfeed-wizard-step').each(function () {
                const s = parseInt($(this).data('step'));
                $(this)
                    .toggleClass('active',    s === step)
                    .toggleClass('completed', s < step)
                    .attr('aria-selected', s === step ? 'true' : 'false');
            });

            $('#otwfeed-prev').css('visibility', step > 1 ? 'visible' : 'hidden');
            $('#otwfeed-next').toggleClass('d-none', step === totalSteps);
            $('#otwfeed-save-btn').toggleClass('d-none', step !== totalSteps);

            // Lazy-init source-val selects when mapping step becomes visible.
            if (step === 3) {
                loadWcFields(function (fields) {
                    initAllMappingRows($('#otwfeed-mapping-tbody'), fields);
                });
            }
        }

        showStep(1);

        // Step tab clicks.
        $('.otwfeed-wizard-step').on('click', function () {
            const s = parseInt($(this).data('step'));
            if (s <= currentStep || savedFeedId) {
                currentStep = s;
                showStep(s);
            }
        });

        $('#otwfeed-next').on('click', function () {
            if (currentStep === 1) {
                const title = $.trim($('#otwfeed-title').val());
                if (!title) {
                    setStatus($('#otwfeed-save-status'), i18n.titleRequired || 'Feed title is required.', 'error');
                    $('#otwfeed-title').focus();
                    return;
                }
                setStatus($('#otwfeed-save-status'), '', '');
            }
            currentStep = Math.min(currentStep + 1, totalSteps);
            showStep(currentStep);
        });

        $('#otwfeed-prev').on('click', function () {
            currentStep = Math.max(currentStep - 1, 1);
            showStep(currentStep);
        });

        $('#otwfeed-save-btn').on('click', function () { saveFeedComplete(); });

        // Add mapping rows.
        $('#otwfeed-add-mapping').on('click', function () {
            addMappingRow('#otwfeed-mapping-tbody', $wrap);
        });

        // Filter group builder in step 4.
        initFilterBuilder('#otwfeed-filter-groups');
        _syncFilterEmpty('#otwfeed-filter-groups');
        $('#otwfeed-add-filter-group').on('click', function () {
            addFilterGroup('#otwfeed-filter-groups');
        });

        $form.on('click', '.otwfeed-remove-row', function () {
            $(this).closest('tr').remove();
        });

        // source_type change on wizard.
        bindSourceTypeChange($form);

        function saveFeedComplete() {
            const $status  = $('#otwfeed-save-status');
            const mappings = collectTableRows('#otwfeed-mapping-tbody', '.otwfeed-mapping-row');
            const filters  = collectFilterGroups('#otwfeed-filter-groups');

            setStatus($status, '…', '');

            post('otwfeed_save_feed', {
                id:                savedFeedId,
                title:             $('#otwfeed-title').val(),
                channel:                $('#otwfeed-channel').val(),
                status:                 $('#otwfeed-status').val(),
                expand_variations:      $('#otwfeed-expand-variations').val(),
                include_gallery_images: $('#otwfeed-include-gallery-images').val(),
                country:                $('#otwfeed-country').val(),
                currency:               $('#otwfeed-currency').val(),
                tax_mode:               $('#otwfeed-tax-mode').val(),
                skip_country_param:     $('#otwfeed-skip-country-param').is(':checked') ? 1 : 0,
                skip_currency_param:    $('#otwfeed-skip-currency-param').is(':checked') ? 1 : 0,
            }).done(function (res) {
                if (!res.success) {
                    setStatus($status, (res.data && res.data.message) || i18n.error, 'error');
                    return;
                }
                const feedId = res.data.id;
                savedFeedId  = feedId;

                $.when(
                    $.post(ajaxUrl, { action: 'otwfeed_save_mappings', nonce, feed_id: feedId, mappings }),
                    $.post(ajaxUrl, { action: 'otwfeed_save_filters',  nonce, feed_id: feedId, filters  })
                ).done(function (mapRes, filRes) {
                    const mapOk = mapRes && mapRes[0] && mapRes[0].success;
                    const filOk = filRes && filRes[0] && filRes[0].success;
                    if (!mapOk || !filOk) {
                        const msg = (!mapOk ? (mapRes[0].data && mapRes[0].data.message) || i18n.error : '')
                                  + (!filOk ? (filRes[0].data && filRes[0].data.message) || i18n.error : '');
                        setStatus($status, msg || i18n.error, 'error');
                        return;
                    }
                    setStatus($status, res.data.message, 'success');
                    // Kick off background generation immediately, then go to dashboard.
                    post('otwfeed_generate_async', { id: feedId }).always(function () {
                        window.location.href = ajaxUrl.replace('admin-ajax.php', '') + 'admin.php?page=otwfeed-pro';
                    });
                }).fail(function (xhr) {
                    setStatus($status, 'Server error ' + xhr.status + ': ' + (xhr.responseText || xhr.statusText), 'error');
                });
            });
        }
    }

    // ── Channel Mapping page ──────────────────────────────────────────────────

    function initMappingPage() {
        const $wrap  = $('.otwfeed-wrap');
        const $tbody = $('#otwfeed-mapping-tbody');
        if (!$tbody.length) return;

        // Feed switcher.
        $('#otwfeed-mapping-feed-select').on('change', function () {
            window.location.href = $(this).data('base-url') + $(this).val();
        });

        // Load WC fields then init all existing rows.
        loadWcFields(function (fields) {
            initAllMappingRows($tbody, fields);
        });

        // source_type change.
        bindSourceTypeChange($wrap);

        // Add row.
        $('#otwfeed-add-mapping').on('click', function () {
            addMappingRow('#otwfeed-mapping-tbody', $wrap);
        });

        // Remove row.
        $wrap.on('click', '.otwfeed-remove-row', function () {
            $(this).closest('tr').remove();
        });

        // Save.
        $('#otwfeed-save-mappings').on('click', function () {
            const feedId  = $('#otwfeed-mapping-feed-id').val();
            const $status = $('#otwfeed-mapping-status');
            const mappings = collectTableRows('#otwfeed-mapping-tbody', '.otwfeed-mapping-row');

            setStatus($status, '…', '');
            post('otwfeed_save_mappings', { feed_id: feedId, mappings }).done(function (res) {
                setStatus(
                    $status,
                    res.success ? res.data.message : (res.data && res.data.message) || i18n.error,
                    res.success ? 'success' : 'error'
                );
            });
        });

        initDragSort('#otwfeed-mapping-tbody');
    }

    // ── Filter Manager page ───────────────────────────────────────────────────

    function initFilterPage() {
        const $container = $('#otwfeed-filter-groups');
        if (!$container.length) return;
        if (!$('#otwfeed-save-filters').length) return; // not the filter manager page

        $('#otwfeed-filter-feed-select').on('change', function () {
            window.location.href = $(this).data('base-url') + $(this).val();
        });

        initFilterBuilder('#otwfeed-filter-groups');
        _syncFilterEmpty('#otwfeed-filter-groups');

        $('#otwfeed-add-filter-group').on('click', function () {
            addFilterGroup('#otwfeed-filter-groups');
        });

        $('#otwfeed-save-filters').on('click', function () {
            const feedId  = $('#otwfeed-filter-feed-id').val();
            const $status = $('#otwfeed-filter-status');
            const filters = collectFilterGroups('#otwfeed-filter-groups');

            setStatus($status, '…', '');
            post('otwfeed_save_filters', { feed_id: feedId, filters })
                .done(function (res) {
                    setStatus(
                        $status,
                        res.success ? res.data.message : (res.data && res.data.message) || i18n.error,
                        res.success ? 'success' : 'error'
                    );
                })
                .fail(function (xhr) {
                    setStatus($status, 'Server error ' + xhr.status + ': ' + (xhr.responseText || xhr.statusText), 'error');
                });
        });
    }

    // ── Price Preview ─────────────────────────────────────────────────────────

    function initPricePreview() {
        $(document).on('click', '#otwfeed-preview-btn', function () {
            const productId = parseInt($('#otwfeed-preview-product').val()) || 0;
            const $result   = $('#otwfeed-price-result');
            if (!productId) { $result.text('Enter a product ID'); return; }

            $result.text('…');
            post('otwfeed_preview_price', {
                product_id: productId,
                tax_mode:   $('#otwfeed-tax-mode').val()   || 'include',
                country:    $('#otwfeed-country').val()    || 'IT',
                currency:   $('#otwfeed-currency').val()   || 'EUR',
            }).done(function (res) {
                if (res.success) {
                    const d = res.data;
                    let html = '<strong>' + $('<span>').text(d.name).html() + '</strong><br>' + d.price;
                    if (d.regular) html += ' <s class="text-muted">' + d.regular + '</s>';
                    $result.html(html);
                } else {
                    $result.text((res.data && res.data.message) || i18n.error);
                }
            });
        });
    }

    // ── Settings misc ─────────────────────────────────────────────────────────

    function initSettings() {
        $(document).on('click', '.otwfeed-copy-url', function () {
            const val = $(this).prev('input').val();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(val).then(() => {
                    $(this).text('Copied!');
                    setTimeout(() => $(this).text('Copy'), 2000);
                });
            }
        });
    }

    // ── Select2 on ordinary selects (non-mapping) ─────────────────────────────

    function initSelect2($scope) {
        if (!$.fn.select2) return;
        $scope.find('select.otwfeed-select2').each(function () {
            if (!$(this).data('select2')) {
                $(this).select2({
                    width:                   '100%',
                    dropdownParent:          $scope,
                    minimumResultsForSearch: $(this).find('option').length > 8 ? 0 : Infinity,
                });
            }
        });
    }

    // ── Init ──────────────────────────────────────────────────────────────────

    $(function () {
        const $wrap = $('.otwfeed-wrap');
        initSelect2($wrap);
        initDashboard();
        initWizard();
        initMappingPage();
        initFilterPage();
        initPricePreview();
        initSettings();
    });

}(jQuery));
