<div class="wrap ycu-wrap">
    <h1>Junior's SEO & Meta CSV Updater</h1>
    
    <h2 class="nav-tab-wrapper ycu-tabs">
        <a href="#tab-import" class="nav-tab nav-tab-active" data-tab="import">Importação / Mapeamento</a>
        <a href="#tab-history" class="nav-tab" data-tab="history">Histórico & Reversão</a>
        <a href="#tab-export" class="nav-tab" data-tab="export">Exportação</a>
        <a href="#tab-help" class="nav-tab" data-tab="help">Ajuda & Instruções</a>
    </h2>
    
    <div class="ycu-tab-content active" id="tab-import">
        <div class="ycu-grid">
            <!-- Left Side: Interactive Flow -->
            <div class="ycu-flow">
                
                <!-- Step 1: Service Selection -->
                <div class="ycu-panel" id="ycu-step-service">
                    <h2>1. O que você deseja atualizar?</h2>
                    <p class="ycu-help">Selecione o tipo de conteúdo para focar as opções de mapeamento.</p>
                    
                    <div class="ycu-cards">
                        <div class="ycu-card" data-service="posts">
                            <div class="ycu-card-icon"><span class="dashicons dashicons-admin-post"></span></div>
                            <h3>Posts, Páginas e CPTs</h3>
                            <p>Atualize SEO (Titles/Desc), Categorias, Slugs e campos ACF de conteúdos textuais.</p>
                        </div>
                        <div class="ycu-card" data-service="media">
                            <div class="ycu-card-icon"><span class="dashicons dashicons-admin-media"></span></div>
                            <h3>Mídias e Imagens</h3>
                            <p>Otimize suas imagens atualizando rapidamente o Texto Alternativo (Alt Text) e Títulos.</p>
                        </div>
                        <div class="ycu-card" data-service="terms">
                            <div class="ycu-card-icon"><span class="dashicons dashicons-category"></span></div>
                            <h3>Categorias e Termos</h3>
                            <p>Atualize o SEO e campos personalizados diretamente nas suas taxonomias.</p>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Upload -->
                <div class="ycu-panel" id="ycu-step-upload" style="display: none;">
                    <h2>2. Upload do Arquivo CSV</h2>
                    <p class="ycu-help">
                        Serviço Selecionado: <strong id="ycu-selected-service-label"></strong>. <a href="#" id="ycu-btn-change-service">Alterar</a><br>
                        Faça o upload do seu CSV. Na próxima etapa, você mapeará as colunas.
                    </p>
                    <form id="ycu-upload-form" enctype="multipart/form-data">
                        <input type="file" id="ycu-csv-file" name="ycu-csv-file" accept=".csv" required>
                        <div class="ycu-actions">
                            <button type="submit" class="button button-primary" id="ycu-btn-upload">Ler Arquivo e Continuar</button>
                        </div>
                    </form>
                </div>

                <!-- Step 3: Mapping -->
                <div class="ycu-panel ycu-mapping-panel" id="ycu-step-mapping" style="display: none;">
                    <h2>3. Mapeamento de Colunas</h2>
                    <p class="ycu-help">Você deve definir <strong>uma coluna</strong> como <strong>Identificador (Referência)</strong> (ex: URL ou ID). As opções abaixo foram filtradas para o serviço escolhido.</p>
                    
                    <div class="ycu-mapping-settings" id="ycu-seo-settings-container">
                        <label>
                            <strong>Plugin de SEO Alvo:</strong>
                            <select id="ycu-seo-plugin">
                                <option value="both">Yoast & Rank Math</option>
                                <option value="yoast">Apenas Yoast SEO</option>
                                <option value="rankmath">Apenas Rank Math</option>
                            </select>
                        </label>
                    </div>

                    <div id="ycu-mapping-container">
                        <!-- Dynamic rows go here -->
                    </div>
                    
                    <div class="ycu-actions" style="margin-top: 20px;">
                        <button type="button" class="button button-secondary" id="ycu-btn-cancel-mapping">Voltar</button>
                        <button type="button" class="button button-primary button-hero" id="ycu-btn-process">Iniciar Atualização em Massa</button>
                    </div>

                    <div id="ycu-progress-container" style="display: none;">
                        <p style="margin-top:15px; margin-bottom:5px;"><strong>Progresso:</strong> <span id="ycu-progress-text">0%</span></p>
                        <div class="ycu-progress-bar">
                            <div id="ycu-progress-fill"></div>
                        </div>
                    </div>

                    <!-- Auto-Retry Container -->
                    <div id="ycu-retry-container" style="display:none; margin-top: 15px; padding: 15px; border-left: 4px solid #d63638; background: #fff8f5;">
                        <p><strong id="ycu-retry-count">0</strong> linhas falharam ao serem processadas (Pode ocorrer devido à lentidão do servidor ao buscar o link ou link não encontrado).</p>
                        <div class="ycu-actions" style="margin-top: 10px;">
                            <button type="button" class="button button-primary" id="ycu-btn-retry">Tentar Novamente os Erros</button>
                            <button type="button" class="button button-secondary" id="ycu-btn-ignore-errors">Ignorar e Concluir</button>
                        </div>
                    </div>
                </div>

                <!-- Step 3.5: Category Validation -->
                <div class="ycu-panel ycu-validation-panel" id="ycu-step-validation" style="display: none;">
                    <h2>Confirmação de Categorias</h2>
                    <p class="ycu-help">Encontramos as seguintes categorias/termos na sua planilha. Verifique-as antes de iniciar a atualização em massa.</p>
                    
                    <div id="ycu-validation-results" style="margin-top: 15px;">
                        <!-- Validation results injected here -->
                    </div>

                    <div class="ycu-actions" style="margin-top: 20px;">
                        <button type="button" class="button button-secondary" id="ycu-btn-cancel-validation">Voltar ao Mapeamento</button>
                        <button type="button" class="button button-primary button-hero" id="ycu-btn-confirm-validation">Confirmar e Iniciar Atualização</button>
                    </div>
                </div>

            </div>

            <!-- Right Side: Terminal -->
            <div class="ycu-panel ycu-terminal-panel">
                <div class="ycu-terminal-header">
                    <span>Terminal de Execução</span>
                    <button id="ycu-btn-clear" class="button button-small">Limpar</button>
                </div>
                <div class="ycu-terminal" id="ycu-terminal">
                    <div class="ycu-log ycu-info">&gt; Sistema inicializado. Selecione o serviço à esquerda...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- History Tab -->
    <div class="ycu-tab-content" id="tab-history">
        <div class="ycu-panel">
            <h2>Histórico de Lotes Importados</h2>
            <p>Aqui você pode ver os CSVs que foram processados. Caso algo tenha dado errado, você pode reverter <strong>todas as alterações</strong> feitas por um lote específico. A reversão vai restaurar o valor exato que o campo possuía antes da importação.</p>
            <button class="button" id="ycu-btn-refresh-history">Atualizar Lista de Histórico</button>
            <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
                <thead>
                    <tr>
                        <th>Data do Upload</th>
                        <th>Nome do Arquivo CSV</th>
                        <th>Alterações Registradas</th>
                        <th>Reverter</th>
                    </tr>
                </thead>
                <tbody id="ycu-history-body">
                    <tr><td colspan="4">Carregando histórico...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Export Tab -->
    <div class="ycu-tab-content" id="tab-export">
        <div class="ycu-panel" style="max-width: 800px;">
            <h2>Exportar Dados Atuais</h2>
            <p>Gere um CSV com todos os Posts, Páginas, Termos (Categorias/Tags) e Imagens e seus respectivos dados atuais (SEO, Alt Texts, etc) para edição e re-upload.</p>
            <button type="button" class="button button-primary button-large" id="ycu-btn-export">Gerar e Baixar CSV Completo</button>
        </div>
    </div>

    <!-- Help Tab -->
    <div class="ycu-tab-content" id="tab-help">
        <div class="ycu-panel">
            <h2>Guia Rápido e Serviços Disponíveis</h2>
            
            <p>O <strong>Junior's SEO & Meta CSV Updater</strong> permite que você atualize dados em massa divididos em três serviços principais. Abaixo, confira o que cada serviço permite mapear e alterar através da sua planilha CSV:</p>

            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
                <!-- Posts/Pages -->
                <div style="flex: 1; min-width: 300px; background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                    <h3><span class="dashicons dashicons-admin-post"></span> Posts, Páginas e CPTs</h3>
                    <p><em>Identificador obrigatório: URL, Slug ou ID do Post.</em></p>
                    <ul style="list-style-type: disc; margin-left: 20px;">
                        <li><strong>SEO Title:</strong> Atualiza o título de SEO (Yoast ou Rank Math).</li>
                        <li><strong>SEO Description:</strong> Atualiza a meta descrição de SEO.</li>
                        <li><strong>Conteúdo do Post (Post Content):</strong> Sobrescreve o texto/HTML principal do post.</li>
                        <li><strong>Resumo (Post Excerpt):</strong> Sobrescreve o resumo manual.</li>
                        <li><strong>Alterar Slug (URL):</strong> Altera o link/slug de acesso.</li>
                        <li><strong>Associar a Categorias/Taxonomia:</strong> Vincula o post a categorias ou tags. Exige confirmação de mapeamento para associar ou criar novos termos.</li>
                        <li><strong>Campo ACF (Personalizado):</strong> Atualiza valores de campos avançados (exige o nome da key do ACF).</li>
                    </ul>
                </div>

                <!-- Media -->
                <div style="flex: 1; min-width: 300px; background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                    <h3><span class="dashicons dashicons-admin-media"></span> Mídias e Imagens</h3>
                    <p><em>Identificador obrigatório: URL da imagem ou ID da Mídia.</em></p>
                    <ul style="list-style-type: disc; margin-left: 20px;">
                        <li><strong>Texto Alternativo (Alt):</strong> Atualiza o texto alternativo da imagem.</li>
                        <li><strong>Nome do Arquivo (Mídia) / Título:</strong> Altera o título de exibição interno da mídia na biblioteca.</li>
                        <li><strong>Alterar Slug da Mídia:</strong> Altera a URL amigável da página de anexo da imagem.</li>
                        <li><strong>Campo ACF (Personalizado):</strong> Atualiza campos ACF atrelados ao arquivo de mídia.</li>
                    </ul>
                </div>

                <!-- Terms -->
                <div style="flex: 1; min-width: 300px; background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                    <h3><span class="dashicons dashicons-category"></span> Categorias e Termos</h3>
                    <p><em>Identificador obrigatório: URL da Categoria, Slug ou ID do Termo.</em></p>
                    <ul style="list-style-type: disc; margin-left: 20px;">
                        <li><strong>Nome do Termo/Categoria:</strong> Renomeia a categoria nativamente no WordPress.</li>
                        <li><strong>Descrição do Termo/Categoria:</strong> Atualiza o campo nativo de descrição da categoria.</li>
                        <li><strong>SEO Title de Termo:</strong> Título SEO da página da categoria.</li>
                        <li><strong>SEO Description de Termo:</strong> Meta descrição SEO da página da categoria.</li>
                        <li><strong>Alterar Slug (URL):</strong> Modifica o link da categoria.</li>
                        <li><strong>Campo ACF (Personalizado):</strong> Atualiza campos ACF associados a esta taxonomia.</li>
                    </ul>
                </div>
            </div>

            <h3 style="margin-top: 30px;">Dicas Importantes</h3>
            <ul>
                <li><strong>O Mapeamento é Inteligente:</strong> O plugin tentará adivinhar automaticamente a função da coluna com base no nome do cabeçalho da sua planilha (ex: uma coluna chamada "Alt Text" será detectada como "Texto Alternativo").</li>
                <li><strong>Reversão de Segurança:</strong> Se você errar um lote, vá na aba <em>"Histórico & Reversão"</em>. O sistema guardou o valor exato (seja um texto longo, um nome, ou uma associação de categoria) antes da modificação e pode restaurá-lo completamente.</li>
                <li><strong>Exportação Recomendada:</strong> Utilize a aba <em>"Exportação"</em> para gerar um CSV pré-formatado com todos os dados atuais do seu site. É o ponto de partida mais seguro para realizar as suas edições!</li>
            </ul>

        </div>
    </div>
</div>
