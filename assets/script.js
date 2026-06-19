jQuery(document).ready(function ($) {
    const $terminal = $('#ycu-terminal');

    // Tabs
    $('.nav-tab').on('click', function (e) {
        e.preventDefault();
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.ycu-tab-content').removeClass('active');
        $('#tab-' + $(this).data('tab')).addClass('active');
    });

    // Flow UI
    const $stepService = $('#ycu-step-service');
    const $stepUpload = $('#ycu-step-upload');
    const $stepMapping = $('#ycu-step-mapping');
    const $selectedServiceLabel = $('#ycu-selected-service-label');
    const $btnChangeService = $('#ycu-btn-change-service');

    let selectedService = '';

    $('.ycu-card').on('click', function () {
        $('.ycu-card').removeClass('selected');
        $(this).addClass('selected');
        selectedService = $(this).data('service');
        $selectedServiceLabel.text($(this).find('h3').text());

        $stepService.slideUp();
        $stepUpload.slideDown();

        // Show bulk replace button only for conteudo
        if (selectedService === 'conteudo') {
            $('#ycu-btn-bulk-replace').show();
        } else {
            $('#ycu-btn-bulk-replace').hide();
        }

        logToTerminal('Serviço selecionado: ' + $(this).find('h3').text(), 'info');
    });

    // Bulk Replace UI Logic
    const $stepBulkReplace = $('#ycu-step-bulk-replace');
    const $btnBulkReplace = $('#ycu-btn-bulk-replace');
    const $btnCancelBulk = $('#ycu-btn-cancel-bulk');
    const $btnAddReplace = $('#ycu-btn-add-replace');
    const $bulkReplaceList = $('#ycu-bulk-replace-list');
    const $btnStartBulk = $('#ycu-btn-start-bulk');
    const $bulkProgressContainer = $('#ycu-bulk-progress-container');
    const $bulkProgressFill = $('#ycu-bulk-progress-fill');
    const $bulkProgressText = $('#ycu-bulk-progress-text');

    $btnBulkReplace.on('click', function () {
        $stepUpload.slideUp();
        $stepBulkReplace.slideDown();
        logToTerminal('Iniciando configuração de substituição global.', 'info');
    });

    $btnCancelBulk.on('click', function () {
        $stepBulkReplace.slideUp();
        $stepUpload.slideDown();
    });

    $btnAddReplace.on('click', function () {
        const row = $(`
            <div class="ycu-bulk-replace-row">
                <input type="text" class="ycu-bulk-old" placeholder="Texto original">
                <span>=</span>
                <input type="text" class="ycu-bulk-new" placeholder="Texto novo">
                <button type="button" class="ycu-btn-remove-replace">×</button>
            </div>
        `);
        $bulkReplaceList.append(row);
    });

    $(document).on('click', '.ycu-btn-remove-replace', function () {
        if ($('.ycu-bulk-replace-row').length > 1) {
            $(this).parent().remove();
        } else {
            $(this).parent().find('input').val('');
        }
    });

    $btnStartBulk.on('click', function () {
        const replacements = [];
        $('.ycu-bulk-replace-row').each(function () {
            const oldVal = $(this).find('.ycu-bulk-old').val().trim();
            const newVal = $(this).find('.ycu-bulk-new').val(); // Can be empty
            if (oldVal) {
                replacements.push({ old: oldVal, new: newVal });
            }
        });

        if (replacements.length === 0) {
            alert('Adicione pelo menos um par de substituição.');
            return;
        }

        const targets = [];
        $('input[name="ycu_bulk_targets"]:checked').each(function () {
            targets.push($(this).val());
        });

        if (targets.length === 0) {
            alert('Selecione pelo menos um local para realizar a substituição.');
            return;
        }

        const options = {
            ignore_case: $('#ycu_bulk_opt_case').is(':checked') ? 1 : 0,
            ignore_accents: $('#ycu_bulk_opt_accents').is(':checked') ? 1 : 0,
            partial_match: $('#ycu_bulk_opt_partial').is(':checked') ? 1 : 0
        };

        if (!confirm('Deseja iniciar a substituição em massa? Isso pode levar algum tempo e alterará conteúdos em todo o site.')) return;

        $btnStartBulk.prop('disabled', true);
        $btnCancelBulk.prop('disabled', true);
        $bulkProgressContainer.show();
        $bulkProgressFill.css('width', '0%');
        $bulkProgressText.text('0%');

        logToTerminal('Iniciando substituição em massa em todo o site...', 'info');

        runBulkReplace(replacements, targets, options, 0);
    });

    function runBulkReplace(replacements, targets, options, offset) {
        $.ajax({
            url: ycu_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ycu_bulk_replace',
                nonce: ycu_ajax.nonce,
                replacements: replacements,
                targets: targets,
                options: options,
                offset: offset
            },
            success: function (res) {
                if (res.success) {
                    const data = res.data;
                    logToTerminal(`Processados ${data.processed} itens. ${data.updated} alterações feitas.`, 'success');

                    const progress = Math.round((data.offset / data.total) * 100);
                    $bulkProgressFill.css('width', progress + '%');
                    $bulkProgressText.text(progress + '%');

                    if (data.offset < data.total) {
                        runBulkReplace(replacements, targets, options, data.offset);
                    } else {
                        $bulkProgressFill.css('width', '100%');
                        $bulkProgressText.text('100%');
                        logToTerminal('Limpando cache global...', 'info');
                        $.ajax({
                            url: ycu_ajax.ajax_url,
                            type: 'POST',
                            data: { action: 'ycu_finish_batch', nonce: ycu_ajax.nonce },
                            success: function () {
                                logToTerminal('Cache limpo com sucesso.', 'success');
                            },
                            complete: function () {
                                logToTerminal('========= SUBSTITUIÇÃO EM MASSA CONCLUÍDA =========', 'success');
                                alert('Substituição em massa concluída com sucesso!');
                                setTimeout(() => {
                                    $btnStartBulk.prop('disabled', false);
                                    $btnCancelBulk.prop('disabled', false);
                                    $btnCancelBulk.trigger('click');
                                    $bulkProgressContainer.hide();
                                }, 2000);
                            }
                        });
                    }
                } else {
                    logToTerminal('Erro: ' + res.data, 'error');
                    $btnStartBulk.prop('disabled', false);
                    $btnCancelBulk.prop('disabled', false);
                }
            },
            error: function () {
                logToTerminal('Erro fatal de servidor durante a substituição.', 'error');
                $btnStartBulk.prop('disabled', false);
                $btnCancelBulk.prop('disabled', false);
            }
        });
    }

    $btnChangeService.on('click', function (e) {
        e.preventDefault();
        $stepMapping.hide();
        $stepUpload.hide();
        $stepService.slideDown();
        $fileInput.val('');
        parsedRows = [];
        csvHeaders = [];
        $mappingContainer.empty();
    });

    // Upload & Mapping UI
    const $form = $('#ycu-upload-form');
    const $fileInput = $('#ycu-csv-file');
    const $btnUpload = $('#ycu-btn-upload');
    const $mappingContainer = $('#ycu-mapping-container');
    const $btnCancelMapping = $('#ycu-btn-cancel-mapping');
    const $btnProcess = $('#ycu-btn-process');
    const $seoSettingsContainer = $('#ycu-seo-settings-container');

    // Progress
    const $progressContainer = $('#ycu-progress-container');
    const $progressFill = $('#ycu-progress-fill');
    const $progressText = $('#ycu-progress-text');

    const $btnExport = $('#ycu-btn-export');
    const $btnClear = $('#ycu-btn-clear');

    let parsedRows = [];
    let csvHeaders = [];
    let failedRowsData = [];
    let currentBatchId = '';
    let currentFileName = '';

    function logToTerminal(message, type = 'info') {
        const time = new Date().toLocaleTimeString();
        const $msg = $('<div>').addClass('ycu-log ycu-' + type).html(`[${time}] > ${message}`);
        $terminal.append($msg);
        $terminal.scrollTop($terminal[0].scrollHeight);
    }

    $btnClear.on('click', function () {
        $terminal.empty();
        logToTerminal('Terminal limpo.', 'info');
    });

    // History Functions
    const $btnRefreshHistory = $('#ycu-btn-refresh-history');
    const $historyBody = $('#ycu-history-body');

    function loadHistory() {
        $historyBody.html('<tr><td colspan="4">Carregando histórico...</td></tr>');
        $.ajax({
            url: ycu_ajax.ajax_url,
            type: 'POST',
            data: { action: 'ycu_get_history', nonce: ycu_ajax.nonce },
            success: function (res) {
                if (res.success) {
                    $historyBody.empty();
                    if (res.data.length === 0) {
                        $historyBody.html('<tr><td colspan="4">Nenhum histórico encontrado.</td></tr>');
                        return;
                    }
                    res.data.forEach(batch => {
                        const tr = $('<tr>');
                        tr.append(`<td>${new Date(batch.created_at).toLocaleString()}</td>`);
                        tr.append(`<td>${batch.file_name}</td>`);
                        tr.append(`<td>${batch.actions_count} alterações salva(s)</td>`);
                        tr.append(`<td>
                            <button class="button ycu-btn-revert" data-batch="${batch.batch_id}" data-total="${batch.actions_count}">Desfazer Tudo</button>
                            <div class="ycu-revert-progress-container" style="display:none; margin-top:5px; background:#ddd; height:10px; border-radius:5px; width: 100%;">
                                <div class="ycu-revert-progress-fill" style="background:#0073aa; height:100%; width:0%; border-radius:5px; transition: width 0.3s;"></div>
                            </div>
                            <small class="ycu-revert-progress-text" style="display:none; margin-top: 2px;">0%</small>
                        </td>`);
                        $historyBody.append(tr);
                    });
                }
            }
        });
    }

    $btnRefreshHistory.on('click', loadHistory);

    $(document).on('click', '.ycu-btn-revert', function () {
        if (!confirm('ATENÇÃO! Isso irá desfazer todas as alterações de metadados, títulos e termos feitas neste lote. O arquivo voltará ao estado exato que estava antes da importação. Deseja continuar?')) return;

        const $btn = $(this);
        const batchId = $btn.data('batch');
        const total = parseInt($btn.data('total')) || 0;
        const $container = $btn.siblings('.ycu-revert-progress-container');
        const $fill = $container.find('.ycu-revert-progress-fill');
        const $text = $btn.siblings('.ycu-revert-progress-text');

        $btn.prop('disabled', true).text('Revertendo...');
        $container.show();
        $text.show();

        function doRevertChunk() {
            $.ajax({
                url: ycu_ajax.ajax_url,
                type: 'POST',
                data: { action: 'ycu_revert_batch', nonce: ycu_ajax.nonce, batch_id: batchId },
                success: function (res) {
                    if (res.success) {
                        if (res.data.done) {
                            $fill.css('width', '100%');
                            $text.text('100% - Concluído');

                            $.ajax({
                                url: ycu_ajax.ajax_url,
                                type: 'POST',
                                data: { action: 'ycu_finish_batch', nonce: ycu_ajax.nonce },
                                success: function () {
                                    alert('Lote revertido com sucesso!');
                                    loadHistory();
                                }
                            });
                        } else {
                            const remaining = res.data.remaining;
                            const processed = total - remaining;
                            const progress = Math.min(Math.round((processed / total) * 100), 99);
                            $fill.css('width', progress + '%');
                            $text.text(progress + '% (' + processed + '/' + total + ')');
                            doRevertChunk();
                        }
                    } else {
                        alert('Erro ao reverter lote: ' + res.data);
                        $btn.prop('disabled', false).text('Desfazer Tudo');
                    }
                },
                error: function () {
                    alert('Erro de conexão ao reverter lote.');
                    $btn.prop('disabled', false).text('Desfazer Tudo');
                }
            });
        }

        doRevertChunk();
    });

    // Initial load history when tab clicked
    $('a[data-tab="history"]').on('click', function () { loadHistory(); });

    $btnExport.on('click', function () {
        logToTerminal('Iniciando exportação de CSV...', 'info');
        $btnExport.prop('disabled', true);

        $.ajax({
            url: ycu_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ycu_export_csv',
                nonce: ycu_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    logToTerminal('Exportação concluída com sucesso! Baixando arquivo...', 'success');
                    window.location.href = response.data.url;
                } else {
                    logToTerminal('Erro na exportação.', 'error');
                }
            },
            error: function () {
                logToTerminal('Erro fatal de servidor ao exportar.', 'error');
            },
            complete: function () {
                $btnExport.prop('disabled', false);
            }
        });
    });

    $form.on('submit', function (e) {
        e.preventDefault();

        const file = $fileInput[0].files[0];
        if (!file) {
            logToTerminal('Nenhum arquivo selecionado.', 'error');
            return;
        }

        currentFileName = file.name;

        const formData = new FormData();
        formData.append('action', 'ycu_upload_csv');
        formData.append('nonce', ycu_ajax.nonce);
        formData.append('file', file);

        $btnUpload.prop('disabled', true);
        logToTerminal(`Lendo arquivo: ${currentFileName}...`, 'info');

        $.ajax({
            url: ycu_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                if (response.success) {
                    parsedRows = response.data.rows;
                    csvHeaders = response.data.headers;
                    const total = response.data.total;

                    if (total > 0) {
                        logToTerminal(`Arquivo lido. ${total} linhas e ${csvHeaders.length} colunas encontradas. Por favor, faça o mapeamento.`, 'success');

                        if (selectedService === 'media' || selectedService === 'conteudo') {
                            $seoSettingsContainer.hide();
                        } else {
                            $seoSettingsContainer.show();
                        }

                        buildMappingUI(csvHeaders, selectedService);
                        $stepUpload.slideUp();
                        $stepMapping.slideDown();
                    } else {
                        logToTerminal('Nenhum dado válido encontrado no CSV.', 'warning');
                    }
                } else {
                    logToTerminal(response.data, 'error');
                }
            },
            error: function () {
                logToTerminal('Erro fatal ao ler o arquivo.', 'error');
            },
            complete: function () {
                $btnUpload.prop('disabled', false);
            }
        });
    });

    $btnCancelMapping.on('click', function () {
        $stepMapping.slideUp();
        $stepUpload.slideDown();
        $fileInput.val('');
        parsedRows = [];
        csvHeaders = [];
        failedRowsData = [];
        currentBatchId = '';
        $mappingContainer.empty();
        $('#ycu-retry-container').hide();
        logToTerminal('Mapeamento cancelado.', 'warning');
    });

    function getMappingOptionsForService(service) {
        let options = `
            <option value="ignore">Ignorar Coluna</option>
            <option value="identifier">Identificador (Referência) *</option>
        `;

        if (service === 'posts') {
            options += `
                <option value="seo_title">SEO Title</option>
                <option value="seo_desc">SEO Description</option>
                <option value="category">Associar a Categorias/Taxonomia</option>
                <option value="author">Alterar Autor (Author)</option>
                <option value="acf">Campo ACF (Personalizado)</option>
                <option value="slug_update">Alterar Slug (URL)</option>
                <option value="post_excerpt">Resumo (Post Excerpt)</option>
            `;
        } else if (service === 'conteudo') {
            options += `
                <option value="post_content_original">Conteúdo - Original (Substituição Global)</option>
                <option value="post_content_update">Conteúdo - Update (Substituição Global)</option>
                <option value="allheading_original">AllHeadings (H2-H6) - Original</option>
                <option value="allheading_update">AllHeadings (H2-H6) - Update</option>
                <option value="h1_original">Troca de H1 - Original</option>
                <option value="h1_update">Troca de H1 - Update</option>
                <option value="h2_original">Troca de H2 - Original</option>
                <option value="h2_update">Troca de H2 - Update</option>
                <option value="h2_order">Troca de H2 por Ordem</option>
                <option value="h3_original">Troca de H3 - Original</option>
                <option value="h3_update">Troca de H3 - Update</option>
                <option value="h3_order">Troca de H3 por Ordem</option>
                <option value="h4_original">Troca de H4 - Original</option>
                <option value="h4_update">Troca de H4 - Update</option>
                <option value="h4_order">Troca de H4 por Ordem</option>
                <option value="h5_original">Troca de H5 - Original</option>
                <option value="h5_update">Troca de H5 - Update</option>
                <option value="h5_order">Troca de H5 por Ordem</option>
                <option value="h6_original">Troca de H6 - Original</option>
                <option value="h6_update">Troca de H6 - Update</option>
                <option value="h6_order">Troca de H6 por Ordem</option>
            `;
        } else if (service === 'media') {
            options += `
                <option value="alt_text">Texto Alternativo (Alt de Imagem)</option>
                <option value="media_title_update">Alterar Nome do Arquivo / Título da Mídia</option>
                <option value="media_caption">Legenda da Mídia (Caption)</option>
                <option value="media_description">Descrição da Mídia (Description)</option>
                <option value="acf">Campo ACF (Personalizado)</option>
            `;
        } else if (service === 'terms') {
            options += `
                <option value="seo_title">SEO Title de Termo</option>
                <option value="seo_desc">SEO Description de Termo</option>
                <option value="term_name_update">Alterar Nome do Termo/Categoria</option>
                <option value="term_description">Descrição do Termo/Categoria</option>
                <option value="acf">Campo ACF (Personalizado)</option>
                <option value="slug_update">Alterar Slug (URL)</option>
            `;
        }
        return options;
    }

    function buildMappingUI(headers, service) {
        $mappingContainer.empty();
        const mappingOptions = getMappingOptionsForService(service);

        headers.forEach((header, index) => {
            const hName = header || `Coluna ${index + 1}`;
            let displayName = hName;
            if (/_\d+$/.test(displayName)) {
                displayName = displayName.replace(/_\d+$/, '');
            }

            // Auto-guess fields
            let defaultSel = 'ignore';
            const lowerH = hName.toLowerCase().trim();

            // Identificadores Básicos (Globais)
            if (['site', 'url', 'id', 'identificador', 'slug'].includes(lowerH)) {
                defaultSel = 'identifier';
            }
            // Slug Update
            else if (['slug_update'].includes(lowerH)) {
                defaultSel = 'slug_update';
            }
            // Posts / Pages
            else if (service === 'posts') {
                if (['title', 'post_title'].includes(lowerH)) defaultSel = 'identifier';
                else if (['seo_title'].includes(lowerH) || (lowerH.includes('title') && lowerH.includes('seo'))) defaultSel = 'seo_title';
                else if (['seo_desc', 'description'].includes(lowerH) || (lowerH.includes('desc') && lowerH.includes('seo'))) defaultSel = 'seo_desc';
                else if (['post_excerpt', 'resumo'].includes(lowerH)) defaultSel = 'post_excerpt';
                else if (['category', 'categorias', 'categoria', 'term_name'].includes(lowerH)) defaultSel = 'category';
                else if (['author', 'autor'].includes(lowerH)) defaultSel = 'author';
                else if (['acf'].includes(lowerH)) defaultSel = 'acf';
            }
            // Media
            else if (service === 'media') {
                if (['media_title', 'title'].includes(lowerH)) defaultSel = 'identifier';
                else if (['alt_text', 'alt', 'texto alternativo'].includes(lowerH) || lowerH.includes('alt text') || lowerH.includes('alt_text')) defaultSel = 'alt_text';
                else if (['media_title_update', 'title_update'].includes(lowerH)) defaultSel = 'media_title_update';
                else if (['legenda', 'caption', 'media_caption'].includes(lowerH)) defaultSel = 'media_caption';
                else if (['descrição', 'descricao', 'description', 'media_description'].includes(lowerH)) defaultSel = 'media_description';
                else if (['acf'].includes(lowerH)) defaultSel = 'acf';
            }
            // Terms
            else if (service === 'terms') {
                if (['term_name', 'nome'].includes(lowerH)) defaultSel = 'identifier';
                else if (['term_name_update', 'nome_update', 'nome do termo'].includes(lowerH)) defaultSel = 'term_name_update';
                else if (['term_description', 'descricao', 'descrição'].includes(lowerH)) defaultSel = 'term_description';
                else if (['seo_title'].includes(lowerH) || (lowerH.includes('title') && lowerH.includes('seo'))) defaultSel = 'seo_title';
                else if (['seo_desc'].includes(lowerH) || (lowerH.includes('desc') && lowerH.includes('seo'))) defaultSel = 'seo_desc';
                else if (['acf'].includes(lowerH)) defaultSel = 'acf';
            }
            // Conteudo
            else if (service === 'conteudo') {
                if (['title', 'post_title'].includes(lowerH)) defaultSel = 'identifier';
                else if (lowerH === 'post_content_original' || (lowerH.includes('conte') && lowerH.includes('ori'))) defaultSel = 'post_content_original';
                else if (lowerH === 'post_content_update' || (lowerH.includes('conte') && (lowerH.includes('upd') || lowerH.includes('nov')))) defaultSel = 'post_content_update';
                else if (lowerH === 'allheading_original' || (lowerH.includes('all') && lowerH.includes('head') && lowerH.includes('ori'))) defaultSel = 'allheading_original';
                else if (lowerH === 'allheading_update' || (lowerH.includes('all') && lowerH.includes('head') && (lowerH.includes('upd') || lowerH.includes('nov')))) defaultSel = 'allheading_update';
                else if (lowerH === 'h1_original' || (lowerH.includes('h1') && lowerH.includes('ori'))) defaultSel = 'h1_original';
                else if (lowerH === 'h1_update' || (lowerH.includes('h1') && (lowerH.includes('upd') || lowerH.includes('nov')))) defaultSel = 'h1_update';
                else if (lowerH === 'h2_original' || (lowerH.includes('h2') && lowerH.includes('ori'))) defaultSel = 'h2_original';
                else if (lowerH === 'h2_update' || (lowerH.includes('h2') && (lowerH.includes('upd') || lowerH.includes('nov')))) defaultSel = 'h2_update';
                else if (lowerH === 'h2_order' || lowerH.includes('h2')) defaultSel = 'h2_order';
                else if (lowerH === 'h3_original' || (lowerH.includes('h3') && lowerH.includes('ori'))) defaultSel = 'h3_original';
                else if (lowerH === 'h3_update' || (lowerH.includes('h3') && (lowerH.includes('upd') || lowerH.includes('nov')))) defaultSel = 'h3_update';
                else if (lowerH === 'h3_order' || lowerH.includes('h3')) defaultSel = 'h3_order';
                else if (lowerH === 'h4_original' || (lowerH.includes('h4') && lowerH.includes('ori'))) defaultSel = 'h4_original';
                else if (lowerH === 'h4_update' || (lowerH.includes('h4') && (lowerH.includes('upd') || lowerH.includes('nov')))) defaultSel = 'h4_update';
                else if (lowerH === 'h4_order' || lowerH.includes('h4')) defaultSel = 'h4_order';
                else if (lowerH === 'h5_original' || (lowerH.includes('h5') && lowerH.includes('ori'))) defaultSel = 'h5_original';
                else if (lowerH === 'h5_update' || (lowerH.includes('h5') && (lowerH.includes('upd') || lowerH.includes('nov')))) defaultSel = 'h5_update';
                else if (lowerH === 'h5_order' || lowerH.includes('h5')) defaultSel = 'h5_order';
                else if (lowerH === 'h6_original' || (lowerH.includes('h6') && lowerH.includes('ori'))) defaultSel = 'h6_original';
                else if (lowerH === 'h6_update' || (lowerH.includes('h6') && (lowerH.includes('upd') || lowerH.includes('nov')))) defaultSel = 'h6_update';
                else if (lowerH === 'h6_order' || lowerH.includes('h6')) defaultSel = 'h6_order';
            }

            const $row = $('<div>').addClass('ycu-mapping-row').attr('data-header', header);
            const $name = $('<div>').addClass('ycu-mapping-col-name').text(displayName);

            const $select = $('<select>').addClass('ycu-mapping-select').html(mappingOptions).val(defaultSel);
            const $colSelect = $('<div>').addClass('ycu-mapping-col-select').append($select);

            const $extra = $('<div>').addClass('ycu-mapping-col-extra');
            const $extraInput = $('<input>').attr('type', 'text').addClass('ycu-extra-key').hide();
            $extra.append($extraInput);

            $select.on('change', function () {
                const val = $(this).val();
                if (val === 'acf') {
                    $extraInput.attr('placeholder', 'Nome do Campo ACF (ex: meu_campo)').show();
                } else if (val === 'category') {
                    $extraInput.attr('placeholder', 'Taxonomia (vazio = category)').show();
                } else if (val && val.endsWith('_order')) {
                    $extraInput.attr('placeholder', 'Posição (Opcional, ordem automática por padrão)').show();
                } else {
                    $extraInput.hide().val('');
                }
            });

            $select.trigger('change');

            $row.append($name, $colSelect, $extra);
            $mappingContainer.append($row);
        });
    }

    let globalMapping = {};
    let globalSeoPlugin = '';
    let globalTermMapping = {};
    let globalAuthorMapping = {};

    const $stepValidation = $('#ycu-step-validation');
    const $btnCancelValidation = $('#ycu-btn-cancel-validation');
    const $btnConfirmValidation = $('#ycu-btn-confirm-validation');
    const $validationResults = $('#ycu-validation-results');

    const $stepAuthorValidation = $('#ycu-step-author-validation');
    const $btnCancelAuthorValidation = $('#ycu-btn-cancel-author-validation');
    const $btnConfirmAuthorValidation = $('#ycu-btn-confirm-author-validation');
    const $authorValidationResults = $('#ycu-author-validation-results');

    let currentCategoryMappedColumn = null;
    let currentCategoryTaxonomy = '';
    let currentAuthorMappedColumn = null;

    $btnProcess.on('click', function () {
        const mapping = {};
        let hasIdentifier = false;
        let missingExtraInfo = false;
        currentCategoryMappedColumn = null;
        currentCategoryTaxonomy = '';
        currentAuthorMappedColumn = null;

        $('.ycu-mapping-row').each(function () {
            const header = $(this).attr('data-header');
            const type = $(this).find('.ycu-mapping-select').val();
            const extraKey = $(this).find('.ycu-extra-key').val().trim();

            if (type !== 'ignore') {
                if (type === 'identifier') hasIdentifier = true;
                if (type === 'category') {
                    currentCategoryMappedColumn = header;
                    currentCategoryTaxonomy = extraKey || 'category';
                }
                if (type === 'author') {
                    currentAuthorMappedColumn = header;
                }

                if (type === 'acf' && !extraKey) {
                    missingExtraInfo = 'ACF';
                }

                mapping[header] = {
                    type: type,
                    extra_key: extraKey
                };
            }
        });

        if (!hasIdentifier) {
            alert('Você DEVE mapear uma das colunas como "Identificador (Referência)".\n\nIdentificador: URL, Slug ou ID. (A coluna title também pode servir como identificador).');
            return;
        }

        if (missingExtraInfo) {
            alert('Você selecionou "' + missingExtraInfo + '" para uma coluna, mas não informou o identificador do conteúdo (parâmetro extra).');
            return;
        }

        globalSeoPlugin = $('#ycu-seo-plugin').val();
        globalMapping = mapping;

        if (currentCategoryMappedColumn) {
            validateCategories();
        } else if (currentAuthorMappedColumn) {
            validateAuthors();
        } else {
            startBatchProcessing();
        }
    });

    function validateCategories() {
        logToTerminal('Verificando categorias/termos encontrados na planilha...', 'info');
        $btnProcess.prop('disabled', true);

        try {
            let allCategories = new Set();
            parsedRows.forEach(row => {
                if (row[currentCategoryMappedColumn]) {
                    const val = String(row[currentCategoryMappedColumn]);
                    const cats = val.split(',').map(c => c.trim()).filter(c => c);
                    cats.forEach(c => allCategories.add(c));
                }
            });

            const uniqueCategories = Array.from(allCategories);

            if (uniqueCategories.length === 0) {
                if (currentAuthorMappedColumn) { validateAuthors(); } else { startBatchProcessing(); }
                return;
            }

            $.ajax({
                url: ycu_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ycu_verify_categories',
                    nonce: ycu_ajax.nonce,
                    categories: uniqueCategories,
                    taxonomy: currentCategoryTaxonomy
                },
                success: function (res) {
                    try {
                        if (typeof res === 'string') { try { res = JSON.parse(res); } catch (e) { } }
                        if (res.success) {
                            $stepMapping.slideUp();

                            let html = `<p>Taxonomia Alvo: <strong>${res.data.taxonomy}</strong></p>`;
                            html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom:15px;">';
                            html += '<thead><tr><th>Valor na Planilha (CSV)</th><th>Ação / Mapeamento</th></tr></thead><tbody>';

                            const allTerms = res.data.all_terms || [];
                            const matches = res.data.matches || {};
                            const csvCategories = res.data.csv_categories || [];

                            csvCategories.forEach(cat => {
                                let selectedId = matches[cat];
                                let options = `<option value="CREATE:${cat}" ${!selectedId ? 'selected' : ''}>+ Criar novo termo: ${cat}</option>`;

                                allTerms.forEach(t => {
                                    let isSelected = (selectedId == t.id) ? 'selected' : '';
                                    options += `<option value="ID:${t.id}" ${isSelected}>= Associar a existente: ${t.name} (ID: ${t.id})</option>`;
                                });

                                html += `
                                    <tr class="ycu-term-map-row" data-csv-cat="${cat}">
                                        <td><strong>${cat}</strong></td>
                                        <td><select class="ycu-term-map-select" style="max-width:350px;">${options}</select></td>
                                    </tr>
                                `;
                            });

                            html += '</tbody></table>';
                            $validationResults.html(html);
                            $stepValidation.slideDown();
                            logToTerminal('Aguardando confirmação e mapeamento de categorias.', 'warning');
                        } else {
                            logToTerminal('Erro no backend: ' + (res.data || 'Erro desconhecido'), 'error');
                            $btnProcess.prop('disabled', false);
                        }
                    } catch (err) {
                        logToTerminal('Erro ao processar resposta: ' + err.message, 'error');
                        $btnProcess.prop('disabled', false);
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    logToTerminal('Erro na requisição AJAX.', 'error');
                    $btnProcess.prop('disabled', false);
                }
            });
        } catch (err) {
            logToTerminal('Erro interno no plugin: ' + err.message, 'error');
            $btnProcess.prop('disabled', false);
        }
    }

    $btnCancelValidation.on('click', function () {
        $stepValidation.slideUp();
        $stepMapping.slideDown();
        $btnProcess.prop('disabled', false);
        logToTerminal('Confirmação de categorias cancelada. Retornou ao mapeamento.', 'warning');
    });

    $btnConfirmValidation.on('click', function () {
        globalTermMapping = {};
        $('.ycu-term-map-row').each(function () {
            const csvCat = $(this).attr('data-csv-cat');
            const mapVal = $(this).find('.ycu-term-map-select').val();
            globalTermMapping[csvCat] = mapVal;
        });

        $stepValidation.slideUp();
        if (currentAuthorMappedColumn) {
            validateAuthors();
        } else {
            $stepMapping.slideDown();
            startBatchProcessing();
        }
    });

    function validateAuthors() {
        logToTerminal('Verificando autores encontrados na planilha...', 'info');
        $btnProcess.prop('disabled', true);

        try {
            let allAuthors = new Set();
            parsedRows.forEach(row => {
                if (row[currentAuthorMappedColumn]) {
                    const val = String(row[currentAuthorMappedColumn]).trim();
                    if (val !== '') allAuthors.add(val);
                }
            });

            const uniqueAuthors = Array.from(allAuthors);

            if (uniqueAuthors.length === 0) {
                startBatchProcessing();
                return;
            }

            $.ajax({
                url: ycu_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ycu_validate_authors',
                    nonce: ycu_ajax.nonce,
                    authors: uniqueAuthors
                },
                success: function (res) {
                    try {
                        if (typeof res === 'string') { try { res = JSON.parse(res); } catch (e) { } }
                        if (res.success) {
                            $stepMapping.slideUp();

                            let html = '<table class="wp-list-table widefat fixed striped" style="margin-bottom:15px;">';
                            html += '<thead><tr><th>Autor na Planilha (CSV)</th><th>Selecionar Usuário Correspondente</th></tr></thead><tbody>';

                            const siteAuthors = res.data.all_authors || [];
                            const matches = res.data.matches || {};
                            const csvAuthors = res.data.csv_authors || [];

                            csvAuthors.forEach(auth => {
                                let selectedId = matches[auth];
                                let options = `<option value="">[Não Encontrado / Ignorar]</option>`;

                                siteAuthors.forEach(a => {
                                    let isSelected = (selectedId == a.id) ? 'selected' : '';
                                    options += `<option value="${a.id}" ${isSelected}>${a.name} (${a.email})</option>`;
                                });

                                html += `
                                    <tr class="ycu-author-map-row" data-csv-auth="${auth}">
                                        <td><strong>${auth}</strong></td>
                                        <td><select class="ycu-author-map-select" style="max-width:350px;">${options}</select></td>
                                    </tr>
                                `;
                            });

                            html += '</tbody></table>';
                            $authorValidationResults.html(html);
                            $stepAuthorValidation.slideDown();
                            logToTerminal('Aguardando confirmação e mapeamento de autores.', 'warning');
                        } else {
                            logToTerminal('Erro no backend ao validar autores.', 'error');
                            $btnProcess.prop('disabled', false);
                        }
                    } catch (err) {
                        logToTerminal('Erro ao processar resposta: ' + err.message, 'error');
                        $btnProcess.prop('disabled', false);
                    }
                },
                error: function () {
                    logToTerminal('Erro na requisição AJAX (autores).', 'error');
                    $btnProcess.prop('disabled', false);
                }
            });
        } catch (err) {
            logToTerminal('Erro interno no plugin: ' + err.message, 'error');
            $btnProcess.prop('disabled', false);
        }
    }

    $btnCancelAuthorValidation.on('click', function () {
        $stepAuthorValidation.slideUp();
        $stepMapping.slideDown();
        $btnProcess.prop('disabled', false);
        logToTerminal('Confirmação de autores cancelada. Retornou ao mapeamento.', 'warning');
    });

    $btnConfirmAuthorValidation.on('click', function () {
        globalAuthorMapping = {};
        $('.ycu-author-map-row').each(function () {
            const csvAuth = $(this).attr('data-csv-auth');
            const mapVal = $(this).find('.ycu-author-map-select').val();
            globalAuthorMapping[csvAuth] = mapVal;
        });

        $stepAuthorValidation.slideUp();
        $stepMapping.slideDown();
        startBatchProcessing();
    });

    function startBatchProcessing() {
        $btnProcess.prop('disabled', true);
        $btnCancelMapping.prop('disabled', true);
        $('#ycu-seo-plugin').prop('disabled', true);
        $('.ycu-mapping-select, .ycu-extra-key').prop('disabled', true);
        $('#ycu-retry-container').hide();

        logToTerminal('Iniciando requisição de lote...', 'info');

        // Create Batch first
        $.ajax({
            url: ycu_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ycu_create_batch',
                nonce: ycu_ajax.nonce,
                file_name: currentFileName
            },
            success: function (res) {
                if (res.success) {
                    currentBatchId = res.data.batch_id;
                    logToTerminal(`Lote criado: ${currentBatchId}. Iniciando atualização...`, 'info');
                    failedRowsData = [];
                    processRowsSequentially(parsedRows, 0, parsedRows.length, globalMapping, globalSeoPlugin, selectedService, currentBatchId);
                } else {
                    logToTerminal('Erro ao criar lote.', 'error');
                }
            }
        });
    }

    $('#ycu-btn-retry').on('click', function () {
        if (failedRowsData.length === 0) return;
        $('#ycu-retry-container').hide();
        const rowsToRetry = failedRowsData;
        failedRowsData = [];
        logToTerminal(`Tentando novamente ${rowsToRetry.length} linhas que falharam...`, 'warning');
        processRowsSequentially(rowsToRetry, 0, rowsToRetry.length, globalMapping, globalSeoPlugin, selectedService, currentBatchId);
    });

    $('#ycu-btn-ignore-errors').on('click', function () {
        finishProcessingState();
    });

    function processRowsSequentially(rows, currentIndex, total, mapping, seoPlugin, service, batchId) {
        if (currentIndex >= total) {
            if (failedRowsData.length > 0) {
                $('#ycu-progress-container').hide();
                $('#ycu-retry-count').text(failedRowsData.length);
                $('#ycu-retry-container').show();
                logToTerminal('Existem linhas com erro. Aguardando ação do usuário...', 'warning');
            } else {
                logToTerminal('========= ATUALIZAÇÃO CONCLUÍDA =========', 'success');
                finishProcessingState();
            }
            return;
        }

        if (currentIndex === 0) {
            $progressContainer.show();
        }

        const percentage = Math.round((currentIndex / total) * 100);
        $progressFill.css('width', percentage + '%');
        $progressText.text(percentage + '%');

        const row = rows[currentIndex];

        $.ajax({
            url: ycu_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ycu_process_row',
                nonce: ycu_ajax.nonce,
                row: row,
                mapping: mapping,
                term_mapping: globalTermMapping,
                author_mapping: globalAuthorMapping,
                seo_plugin: seoPlugin,
                service_type: service,
                batch_id: batchId
            },
            success: function (response) {
                if (response.success) {
                    logToTerminal(response.data, 'success');
                } else {
                    logToTerminal('Falha: ' + response.data, 'error');
                    failedRowsData.push(row);
                }

                processRowsSequentially(rows, currentIndex + 1, total, mapping, seoPlugin, service, batchId);
            },
            error: function () {
                logToTerminal('Erro de Servidor/Timeout na linha ' + (currentIndex + 1) + '. Marcado como falha.', 'error');
                failedRowsData.push(row);
                processRowsSequentially(rows, currentIndex + 1, total, mapping, seoPlugin, service, batchId);
            }
        });
    }

    function finishProcessingState() {
        $progressFill.css('width', '100%');
        $progressText.text('100%');
        $('#ycu-retry-container').hide();

        logToTerminal('Limpando cache global...', 'info');
        $.ajax({
            url: ycu_ajax.ajax_url,
            type: 'POST',
            data: { action: 'ycu_finish_batch', nonce: ycu_ajax.nonce },
            success: function () {
                logToTerminal('Cache limpo.', 'success');
            },
            complete: function () {
                setTimeout(() => {
                    $btnCancelMapping.prop('disabled', false).trigger('click');
                    $btnProcess.prop('disabled', false);
                    $('#ycu-seo-plugin').prop('disabled', false);
                    $progressContainer.hide();
                    $progressFill.css('width', '0%');
                    failedRowsData = [];
                    currentBatchId = '';
                }, 3000);
            }
        });
    }
});
