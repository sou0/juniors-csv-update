jQuery(document).ready(function($) {
    const $terminal = $('#ycu-terminal');
    
    // Tabs
    $('.nav-tab').on('click', function(e) {
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

    $('.ycu-card').on('click', function() {
        $('.ycu-card').removeClass('selected');
        $(this).addClass('selected');
        selectedService = $(this).data('service');
        $selectedServiceLabel.text($(this).find('h3').text());
        
        $stepService.slideUp();
        $stepUpload.slideDown();
        logToTerminal('Serviço selecionado: ' + $(this).find('h3').text(), 'info');
    });

    $btnChangeService.on('click', function(e) {
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

    $btnClear.on('click', function() {
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
            success: function(res) {
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
                        tr.append(`<td><button class="button ycu-btn-revert" data-batch="${batch.batch_id}">Desfazer Tudo</button></td>`);
                        $historyBody.append(tr);
                    });
                }
            }
        });
    }

    $btnRefreshHistory.on('click', loadHistory);

    $(document).on('click', '.ycu-btn-revert', function() {
        if (!confirm('ATENÇÃO! Isso irá desfazer todas as alterações de metadados, títulos e termos feitas neste lote. O arquivo voltará ao estado exato que estava antes da importação. Deseja continuar?')) return;
        
        const batchId = $(this).data('batch');
        const $btn = $(this);
        $btn.prop('disabled', true).text('Revertendo...');

        $.ajax({
            url: ycu_ajax.ajax_url,
            type: 'POST',
            data: { action: 'ycu_revert_batch', nonce: ycu_ajax.nonce, batch_id: batchId },
            success: function(res) {
                if (res.success) {
                    alert('Lote revertido com sucesso!');
                    loadHistory();
                } else {
                    alert('Erro ao reverter lote: ' + res.data);
                    $btn.prop('disabled', false).text('Desfazer Tudo');
                }
            }
        });
    });

    // Initial load history when tab clicked
    $('a[data-tab="history"]').on('click', function() { loadHistory(); });

    $btnExport.on('click', function() {
        logToTerminal('Iniciando exportação de CSV...', 'info');
        $btnExport.prop('disabled', true);
        
        $.ajax({
            url: ycu_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ycu_export_csv',
                nonce: ycu_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    logToTerminal('Exportação concluída com sucesso! Baixando arquivo...', 'success');
                    window.location.href = response.data.url;
                } else {
                    logToTerminal('Erro na exportação.', 'error');
                }
            },
            error: function() {
                logToTerminal('Erro fatal de servidor ao exportar.', 'error');
            },
            complete: function() {
                $btnExport.prop('disabled', false);
            }
        });
    });

    $form.on('submit', function(e) {
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
            success: function(response) {
                if (response.success) {
                    parsedRows = response.data.rows;
                    csvHeaders = response.data.headers;
                    const total = response.data.total;
                    
                    if (total > 0) {
                        logToTerminal(`Arquivo lido. ${total} linhas e ${csvHeaders.length} colunas encontradas. Por favor, faça o mapeamento.`, 'success');
                        
                        if (selectedService === 'media') {
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
            error: function() {
                logToTerminal('Erro fatal ao ler o arquivo.', 'error');
            },
            complete: function() {
                $btnUpload.prop('disabled', false);
            }
        });
    });

    $btnCancelMapping.on('click', function() {
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
                <option value="acf">Campo ACF (Personalizado)</option>
                <option value="slug">Alterar Slug (URL)</option>
                <option value="post_content">Conteúdo do Post (Post Content)</option>
                <option value="post_excerpt">Resumo (Post Excerpt)</option>
            `;
        } else if (service === 'media') {
            options += `
                <option value="alt_text">Texto Alternativo (Alt de Imagem)</option>
                <option value="media_title">Nome do Arquivo (Mídia) / Título</option>
                <option value="slug">Alterar Slug da Mídia</option>
                <option value="acf">Campo ACF (Personalizado)</option>
            `;
        } else if (service === 'terms') {
            options += `
                <option value="seo_title">SEO Title de Termo</option>
                <option value="seo_desc">SEO Description de Termo</option>
                <option value="term_name">Nome do Termo/Categoria</option>
                <option value="term_description">Descrição do Termo/Categoria</option>
                <option value="acf">Campo ACF (Personalizado)</option>
                <option value="slug">Alterar Slug (URL)</option>
            `;
        }
        return options;
    }

    function buildMappingUI(headers, service) {
        $mappingContainer.empty();
        const mappingOptions = getMappingOptionsForService(service);
        
        headers.forEach((header, index) => {
            const hName = header || `Coluna ${index + 1}`;
            
            // Auto-guess fields
            let defaultSel = 'ignore';
            const lowerH = hName.toLowerCase();
            if (lowerH === 'site' || lowerH === 'url' || lowerH === 'slug' || lowerH === 'id' || lowerH === 'identificador') defaultSel = 'identifier';
            else if (lowerH.includes('title') && lowerH.includes('seo') && service !== 'media') defaultSel = 'seo_title';
            else if (lowerH.includes('desc') && service !== 'media') defaultSel = 'seo_desc';
            else if ((lowerH === 'alt' || lowerH.includes('alt text')) && service === 'media') defaultSel = 'alt_text';
            else if (lowerH.includes('cat') && service === 'posts') defaultSel = 'category';
            
            const $row = $('<div>').addClass('ycu-mapping-row').attr('data-header', header);
            const $name = $('<div>').addClass('ycu-mapping-col-name').text(hName);
            
            const $select = $('<select>').addClass('ycu-mapping-select').html(mappingOptions).val(defaultSel);
            const $colSelect = $('<div>').addClass('ycu-mapping-col-select').append($select);
            
            const $extra = $('<div>').addClass('ycu-mapping-col-extra');
            const $extraInput = $('<input>').attr('type', 'text').addClass('ycu-extra-key').hide();
            $extra.append($extraInput);
            
            $select.on('change', function() {
                const val = $(this).val();
                if (val === 'acf') {
                    $extraInput.attr('placeholder', 'Nome do Campo ACF (ex: meu_campo)').show();
                } else if (val === 'category') {
                    $extraInput.attr('placeholder', 'Taxonomia (vazio = category)').show();
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

    const $stepValidation = $('#ycu-step-validation');
    const $btnCancelValidation = $('#ycu-btn-cancel-validation');
    const $btnConfirmValidation = $('#ycu-btn-confirm-validation');
    const $validationResults = $('#ycu-validation-results');

    $btnProcess.on('click', function() {
        const mapping = {};
        let hasIdentifier = false;
        let missingExtraInfo = false;
        let categoryMappedColumn = null;
        let categoryTaxonomy = '';
        
        $('.ycu-mapping-row').each(function() {
            const header = $(this).attr('data-header');
            const type = $(this).find('.ycu-mapping-select').val();
            const extraKey = $(this).find('.ycu-extra-key').val().trim();
            
            if (type !== 'ignore') {
                if (type === 'identifier') hasIdentifier = true;
                if (type === 'category') {
                    categoryMappedColumn = header;
                    categoryTaxonomy = extraKey || 'category';
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
            alert('Você DEVE mapear uma das colunas como "Identificador (Referência)".');
            return;
        }
        
        if (missingExtraInfo) {
            alert('Você selecionou "' + missingExtraInfo + '" para uma coluna, mas não informou o identificador do conteúdo (parâmetro extra).');
            return;
        }

        globalSeoPlugin = $('#ycu-seo-plugin').val();
        globalMapping = mapping;

        if (categoryMappedColumn) {
            // Need to validate categories first
            logToTerminal('Verificando categorias/termos encontrados na planilha...', 'info');
            $btnProcess.prop('disabled', true);
            
            try {
                let allCategories = new Set();
                parsedRows.forEach(row => {
                    if (row[categoryMappedColumn]) {
                        const val = String(row[categoryMappedColumn]);
                        const cats = val.split(',').map(c => c.trim()).filter(c => c);
                        cats.forEach(c => allCategories.add(c));
                    }
                });

                const uniqueCategories = Array.from(allCategories);

                if (uniqueCategories.length === 0) {
                     // No categories found to process, proceed directly
                     startBatchProcessing();
                     return;
                }

                $.ajax({
                    url: ycu_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ycu_verify_categories',
                        nonce: ycu_ajax.nonce,
                        categories: uniqueCategories,
                        taxonomy: categoryTaxonomy
                    },
                    success: function(res) {
                        try {
                            if (typeof res === 'string') {
                                try { res = JSON.parse(res); } catch(e) {}
                            }
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
                                            <td>
                                                <select class="ycu-term-map-select" style="max-width:350px;">
                                                    ${options}
                                                </select>
                                            </td>
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
                        } catch(err) {
                            logToTerminal('Erro ao processar resposta: ' + err.message, 'error');
                            console.error(err);
                            $btnProcess.prop('disabled', false);
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        logToTerminal('Erro na requisição AJAX: ' + textStatus + ' - ' + errorThrown, 'error');
                        console.error('AJAX Error:', textStatus, errorThrown, jqXHR.responseText);
                        $btnProcess.prop('disabled', false);
                    }
                });
            } catch (err) {
                logToTerminal('Erro interno no plugin: ' + err.message, 'error');
                console.error(err);
                $btnProcess.prop('disabled', false);
            }
        } else {
            startBatchProcessing();
        }
    });

    $btnCancelValidation.on('click', function() {
        $stepValidation.slideUp();
        $stepMapping.slideDown();
        $btnProcess.prop('disabled', false);
        logToTerminal('Confirmação de categorias cancelada. Retornou ao mapeamento.', 'warning');
    });

    $btnConfirmValidation.on('click', function() {
        $stepValidation.slideUp();
        $stepMapping.slideDown(); // we show mapping panel again to show progress bar
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
            success: function(res) {
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

    $('#ycu-btn-retry').on('click', function() {
        if (failedRowsData.length === 0) return;
        $('#ycu-retry-container').hide();
        const rowsToRetry = failedRowsData;
        failedRowsData = [];
        logToTerminal(`Tentando novamente ${rowsToRetry.length} linhas que falharam...`, 'warning');
        processRowsSequentially(rowsToRetry, 0, rowsToRetry.length, globalMapping, globalSeoPlugin, selectedService, currentBatchId);
    });

    $('#ycu-btn-ignore-errors').on('click', function() {
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
                seo_plugin: seoPlugin,
                service_type: service,
                batch_id: batchId
            },
            success: function(response) {
                if (response.success) {
                    logToTerminal(response.data, 'success');
                } else {
                    logToTerminal('Falha: ' + response.data, 'error');
                    failedRowsData.push(row);
                }
                
                processRowsSequentially(rows, currentIndex + 1, total, mapping, seoPlugin, service, batchId);
            },
            error: function() {
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
