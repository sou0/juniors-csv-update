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
                            <p>Atualize SEO, Categorias, Slugs e campos ACF.</p>
                        </div>
                        <div class="ycu-card" data-service="conteudo">
                            <div class="ycu-card-icon"><span class="dashicons dashicons-editor-paste-text"></span></div>
                            <h3>Conteúdo (Páginas e Posts)</h3>
                            <p>Substitua o conteúdo, atualize Títulos H1 ou troque H2 (por localização ou ordem). Funciona com Elementor.</p>
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
                            <button type="button" class="button button-secondary" id="ycu-btn-bulk-replace" style="display:none;">Realizar o serviço em todos</button>
                        </div>
                    </form>
                </div>

                <!-- Step 2.5: Bulk Replace Setup -->
                <div class="ycu-panel" id="ycu-step-bulk-replace" style="display: none;">
                    <h2>Substituição em Massa (Sem CSV)</h2>
                    <p class="ycu-help">Defina abaixo os textos que deseja substituir em todo o site. Não é necessário upload de arquivo.</p>
                    
                    <div id="ycu-bulk-replace-list">
                        <div class="ycu-bulk-replace-row">
                            <input type="text" class="ycu-bulk-old" placeholder="Texto original">
                            <span>=</span>
                            <input type="text" class="ycu-bulk-new" placeholder="Texto novo">
                            <button type="button" class="ycu-btn-remove-replace">×</button>
                        </div>
                    </div>
                    <button type="button" class="button" id="ycu-btn-add-replace" style="margin-top:10px;">+ Adicionar outra substituição</button>

                    <div style="margin-top: 25px;">
                        <h3>Onde realizar a substituição?</h3>
                        <div class="ycu-checkbox-grid">
                            <label><input type="checkbox" name="ycu_bulk_targets" value="p" checked> Conteúdos (&lt;p&gt;)</label><br>
                            <label><input type="checkbox" name="ycu_bulk_targets" value="h1" checked> Títulos (&lt;h1&gt;)</label><br>
                            <label><input type="checkbox" name="ycu_bulk_targets" value="h2" checked> Subtítulos (&lt;h2&gt;)</label><br>
                            <label><input type="checkbox" name="ycu_bulk_targets" value="h3" checked> Subtítulos (&lt;h3&gt;)</label><br>
                            <label><input type="checkbox" name="ycu_bulk_targets" value="h4"> Subtítulos (&lt;h4&gt;)</label><br>
                            <label><input type="checkbox" name="ycu_bulk_targets" value="h5"> Subtítulos (&lt;h5&gt;)</label><br>
                            <label><input type="checkbox" name="ycu_bulk_targets" value="h6"> Subtítulos (&lt;h6&gt;)</label><br>
                            <label><input type="checkbox" name="ycu_bulk_targets" value="post_title"> Título do Post/Página</label>
                            <label><input type="checkbox" name="ycu_bulk_targets" value="excerpt"> Resumo / Descrição</label>
                            <label><input type="checkbox" name="ycu_bulk_targets" value="seo_title"> SEO Title</label>
                            <label><input type="checkbox" name="ycu_bulk_targets" value="seo_desc"> SEO Description</label>
                            <label><input type="checkbox" name="ycu_bulk_targets" value="acf"> Campos ACF</label>
                            <label><input type="checkbox" name="ycu_bulk_targets" value="slug"> Slugs (URLs)</label>
                            <label><input type="checkbox" name="ycu_bulk_targets" value="media_alt"> Alt Text (Imagens)</label>
                        </div>
                        <p class="ycu-help" style="margin-top:10px;">* As substituições em Parágrafos e Headings (H1 a H6) são compatíveis com o Elementor.</p>
                    </div>

                    <div style="margin-top: 25px;">
                        <h3>Opções Avançadas</h3>
                        <div class="ycu-checkbox-grid" style="grid-template-columns: 1fr;">
                            <label><input type="checkbox" id="ycu_bulk_opt_case" value="1"> Ignorar Maiúsculas/Minúsculas (Ex: "Como" será igual a "como")</label>
                            <label><input type="checkbox" id="ycu_bulk_opt_accents" value="1"> Ignorar Acentuação (Ex: "vovó" será igual a "vovo")</label>
                            <label><input type="checkbox" id="ycu_bulk_opt_partial" value="1"> Substituir trechos dentro de palavras (Ex: trocar "grama" dentro da palavra "gramado")</label>
                        </div>
                    </div>

                    <div class="ycu-actions" style="margin-top: 20px;">
                        <button type="button" class="button button-secondary" id="ycu-btn-cancel-bulk">Voltar</button>
                        <button type="button" class="button button-primary button-hero" id="ycu-btn-start-bulk">Iniciar Substituição em Massa</button>
                    </div>

                    <div id="ycu-bulk-progress-container" style="display: none;">
                        <p style="margin-top:15px; margin-bottom:5px;"><strong>Progresso Total:</strong> <span id="ycu-bulk-progress-text">0%</span></p>
                        <div class="ycu-progress-bar">
                            <div id="ycu-bulk-progress-fill"></div>
                        </div>
                    </div>
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

                <div class="ycu-panel ycu-validation-panel" id="ycu-step-validation" style="display: none;">
                    <h2>Confirmação de Categorias</h2>
                    <p class="ycu-help">Encontramos as seguintes categorias/termos na sua planilha. Verifique-as antes de iniciar a atualização em massa.</p>
                    
                    <div id="ycu-validation-results" style="margin-top: 15px;">
                        <!-- Validation results injected here -->
                    </div>

                    <div class="ycu-actions" style="margin-top: 20px;">
                        <button type="button" class="button button-secondary" id="ycu-btn-cancel-validation">Cancelar</button>
                        <button type="button" class="button button-primary button-hero" id="ycu-btn-confirm-validation">Confirmar Categorias</button>
                    </div>
                </div>

                <!-- Step 3.6: Author Validation -->
                <div class="ycu-panel ycu-validation-panel" id="ycu-step-author-validation" style="display: none;">
                    <h2>Confirmação de Autores</h2>
                    <p class="ycu-help">Associe os nomes de autores da planilha a usuários existentes no seu WordPress. O sistema tentou identificar automaticamente.</p>
                    
                    <div id="ycu-author-validation-results" style="margin-top: 15px;">
                        <!-- Author Validation results injected here -->
                    </div>

                    <div class="ycu-actions" style="margin-top: 20px;">
                        <button type="button" class="button button-secondary" id="ycu-btn-cancel-author-validation">Cancelar</button>
                        <button type="button" class="button button-primary button-hero" id="ycu-btn-confirm-author-validation">Confirmar Autores e Iniciar Atualização</button>
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
                    <p><em>Identificador: URL, Slug ou ID. (A coluna <code>title</code> também pode servir como identificador).</em></p>
                    <ul style="list-style-type: disc; margin-left: 20px;">
                        <li><strong>SEO Title:</strong> <code>seo_title</code>, <code>title</code></li>
                        <li><strong>SEO Description:</strong> <code>seo_desc</code>, <code>description</code></li>
                        <li><strong>Resumo (Excerpt):</strong> <code>post_excerpt</code>, <code>resumo</code></li>
                        <li><strong>Alterar Slug:</strong> <code>slug_update</code></li>
                        <li><strong>Categorias:</strong> <code>category</code>, <code>categorias</code></li>
                        <li><strong>Autor:</strong> <code>author</code>, <code>autor</code></li>
                        <li><strong>Campo ACF:</strong> <code>acf</code></li>
                    </ul>
                </div>

                <!-- Conteúdo -->
                <div style="flex: 1; min-width: 300px; background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                    <h3><span class="dashicons dashicons-editor-paste-text"></span> Conteúdo (Páginas e Posts)</h3>
                    <p><em>Identificador: URL, Slug ou ID.</em></p>
                    <ul style="list-style-type: disc; margin-left: 20px;">
                        <li><strong>Substituição Global de Conteúdo:</strong> <code>post_content_original</code> + <code>post_content_update</code></li>
                        <li><strong><code>allheading_original</code> e <code>allheading_update</code>:</strong> Substitui textos em todos os subtítulos (H2 a H6) da página de uma vez.</li>
                        <li><strong><code>h1_original</code> e <code>h1_update</code>:</strong> Substitui o texto do título H1.</li>
                        <li><strong><code>h2_original</code>, <code>h2_update</code> e <code>h2_order</code>:</strong> Idem para os subtítulos H2. (Ordem: 1, 2, 3...)</li>
                        <li><strong><code>h3</code> a <code>h6</code>:</strong> Possuem o mesmo padrão <code>_original</code>, <code>_update</code> e <code>_order</code> de H2.</li>
                    </ul>
                </div>

                <!-- Media -->
                <div style="flex: 1; min-width: 300px; background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                    <h3><span class="dashicons dashicons-admin-media"></span> Mídias e Imagens</h3>
                    <p><em>Identificador: URL da imagem ou ID.</em></p>
                    <ul style="list-style-type: disc; margin-left: 20px;">
                        <li><strong>Alt Text:</strong> <code>alt_text</code>, <code>alt</code></li>
                        <li><strong>Alterar Título da Mídia:</strong> <code>media_title_update</code></li>
                        <li><strong>Legenda da Mídia (Caption):</strong> <code>media_caption</code>, <code>legenda</code></li>
                        <li><strong>Descrição da Mídia (Description):</strong> <code>media_description</code>, <code>descricao</code></li>
                        <li><strong>Campo ACF:</strong> <code>acf</code></li>
                    </ul>
                </div>

                <!-- Terms -->
                <div style="flex: 1; min-width: 300px; background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                    <h3><span class="dashicons dashicons-category"></span> Categorias e Termos</h3>
                    <p><em>Identificador: URL, Slug ou ID do Termo.</em></p>
                    <ul style="list-style-type: disc; margin-left: 20px;">
                        <li><strong>Alterar Nome do Termo:</strong> <code>term_name_update</code></li>
                        <li><strong>Descrição:</strong> <code>term_description</code>, <code>descricao</code></li>
                        <li><strong>SEO Title Termo:</strong> <code>seo_title</code></li>
                        <li><strong>SEO Desc Termo:</strong> <code>seo_desc</code></li>
                        <li><strong>Alterar Slug:</strong> <code>slug_update</code></li>
                        <li><strong>Campo ACF:</strong> <code>acf</code></li>
                    </ul>
                </div>
            </div>

            <h3 style="margin-top: 30px;">Checklist de Funcionamento & Elementor</h3>
            <ul style="list-style-type: check; margin-left: 20px;">
                <li><input type="checkbox" checked readonly> <strong>Substituição de H1/H2:</strong> Funciona em conteúdo nativo e widgets de "Heading" do Elementor.</li>
                <li><input type="checkbox" checked readonly> <strong>Parágrafos (&lt;p&gt;):</strong> Funciona em conteúdo nativo e widgets "Text Editor" do Elementor.</li>
                <li><input type="checkbox" checked readonly> <strong>Bulk Replace (Em todos):</strong> Permite trocar múltiplos pares de texto de uma vez.</li>
                <li><input type="checkbox" checked readonly> <strong>ACF:</strong> Atualiza valores em Posts e Termos (Categorias).</li>
                <li><input type="checkbox" checked readonly> <strong>SEO:</strong> Compatível com Yoast SEO e Rank Math.</li>
            </ul>

            <h3 style="margin-top: 30px;">Dicas Importantes</h3>
            <ul>
                <li><strong>O Mapeamento é Inteligente:</strong> O plugin tentará adivinhar automaticamente a função da coluna com base no nome do cabeçalho da sua planilha (ex: uma coluna chamada "Alt Text" será detectada como "Texto Alternativo").</li>
                <li><strong>Reversão de Segurança:</strong> Se você errar um lote, vá na aba <em>"Histórico & Reversão"</em>. O sistema guardou o valor exato (seja um texto longo, um nome, ou uma associação de categoria) antes da modificação e pode restaurá-lo completamente.</li>
                <li><strong>Exportação Recomendada:</strong> Utilize a aba <em>"Exportação"</em> para gerar um CSV pré-formatado com todos os dados atuais do seu site. É o ponto de partida mais seguro para realizar as suas edições!</li>
            </ul>

        </div>
    </div>
</div>
