    jQuery(document).ready(function($) {
        const ListasProdutos = {
            postId: ListasAdminConfig.postId,
            categoriaId: ListasAdminConfig.categoriaId,
            nonce: ListasAdminConfig.nonce,
            searchTimeout: null,
            
            init() {
                this.loadProdutos();
                this.initSearch();
            },
            
            initSearch() {
                $('#listas-produto-search').on('input', (e) => {
                    clearTimeout(this.searchTimeout);
                    this.searchTimeout = setTimeout(() => {
                        this.loadProdutos(e.target.value);
                    }, 500);
                });
            },
            
            loadProdutos(search = '') {
                this.lastSearch = String(search || '').trim();
                const grid = $('#listas-produtos-grid');
                grid.html('<div class="listas-loading">Buscando produtos...</div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'listas_search_produtos',
                        nonce: this.nonce,
                        categoria_id: this.categoriaId,
                        search: search
                    },
                    success: (response) => {
                        if (response.success) {
                            this.renderProdutos(response.data);
                        } else {
                            grid.html('<div class="listas-empty">Nenhum produto encontrado</div>');
                        }
                    },
                    error: () => {
                        grid.html('<div class="listas-empty">Erro ao carregar produtos</div>');
                    }
                });
            },
            
            renderProdutos(produtos) {
                const grid = $('#listas-produtos-grid');
                
                if (produtos.length === 0) {
                    grid.html('<div class="listas-empty">Nenhum produto encontrado</div>');
                    return;
                }
                
                // Agrupar por produto: o PAI encabeça e as variações dele vêm logo
                // abaixo, recuadas. Sem isso a busca devolvia uma lista plana de
                // "Fórceps Adulto - N° 16, N° 18L, N° 23..." e o pai se perdia no
                // meio (ou ficava lá em cima, no bloco dos já adicionados).
                const grupos = new Map();
                produtos.forEach(p => {
                    if (!grupos.has(p.id)) grupos.set(p.id, []);
                    grupos.get(p.id).push(p);
                });

                const ordenados = [];
                Array.from(grupos.values())
                    .map(itens => {
                        itens.sort((a, b) => {
                            // o pai (sem variação) encabeça o grupo
                            const va = a.variation_id ? 1 : 0;
                            const vb = b.variation_id ? 1 : 0;
                            if (va !== vb) return va - vb;
                            return (a.menu_order || 9999) - (b.menu_order || 9999)
                                || String(a.name).localeCompare(String(b.name), 'pt-BR', { numeric: true });
                        });
                        return itens;
                    })
                    .sort((ga, gb) => {
                        const addA = ga.some(i => i.in_category) ? 0 : 1;
                        const addB = gb.some(i => i.in_category) ? 0 : 1;
                        if (addA !== addB) return addA - addB;
                        const posA = Math.min(...ga.map(i => i.menu_order || 9999));
                        const posB = Math.min(...gb.map(i => i.menu_order || 9999));
                        return posA - posB;
                    })
                    .forEach(itens => itens.forEach(i => ordenados.push(i)));

                produtos = ordenados;

                // O divisor só faz sentido na listagem da lista. Numa busca ele
                // partia o grupo no meio, separando o pai das variações dele.
                const emBusca = !!(this.lastSearch && this.lastSearch.length);
                const hasAdded = produtos.some(p => p.in_category);
                let dividerInserted = emBusca;

                let html = '';
                produtos.forEach(produto => {
                    const isAdded = produto.in_category;
                    const isVariacao = !!produto.variation_id;

                    // Divisor "Produtos sugeridos" entre os itens da lista e as sugestões
                    if (!isAdded && hasAdded && !dividerInserted) {
                        html += '<div class="listas-produtos-divider">Produtos sugeridos</div>';
                        dividerInserted = true;
                    }

                    const itemClass = (isAdded ? 'listas-produto-item added-item' : 'listas-produto-item')
                        + (isVariacao ? ' listas-produto-item--variacao' : '');
                    const similaresCount = produto.similares_count || 0;

                    html += `
                        <div class="${itemClass}" data-product-id="${produto.id}">
                            ${isAdded ? `<div class="listas-produto-drag" title="Arraste para reordenar">⋮⋮</div>` : ''}
                            
                            <div class="listas-produto-image-small">
                                <img src="${produto.image}" alt="${produto.name}">
                            </div>
                            
                            <div class="listas-produto-info-horizontal">
                                <h4 class="listas-produto-title-horizontal">${produto.name}</h4>
                                <div class="listas-produto-id">
                                    SKU: ${produto.sku || 'N/A'}
                                    ${produto.variation_id ? `<span class="listas-badge-variacao" title="Esta lista fixou uma variação específica">variação</span>` : ''}
                                </div>
                                ${(produto.weight || produto.dimensions) ? `<div class="listas-produto-medidas">${[produto.weight, produto.dimensions].filter(Boolean).join(' · ')}</div>` : ''}
                            </div>
                            
                            <div class="listas-produto-price-horizontal">${produto.price}</div>
                            
                            <div class="listas-produto-actions">
                                <button 
                                    type="button" 
                                    class="listas-produto-btn-horizontal ${isAdded ? 'added' : ''}" 
                                    data-product-id="${produto.id}"
                                    data-variation-id="${produto.variation_id || 0}"
                                    ${isAdded ? 'disabled' : ''}
                                >
                                    ${isAdded ? '✓ Adicionado' : '+ Adicionar'}
                                </button>
                                ${isAdded ? `
                                <button 
                                    type="button" 
                                    class="listas-btn-similares" 
                                    data-product-id="${produto.id}"
                                    title="Gerenciar produtos similares"
                                >
                                    Adicionar produtos similares ${similaresCount > 0 ? '(' + similaresCount + ')' : ''}
                                </button>
                                ` : ''}
                            </div>
                            
                            <button 
                                type="button" 
                                class="listas-produto-eye-btn" 
                                onclick="window.open('${produto.link}', '_blank')"
                                title="Ver produto na loja"
                            >
                                <svg viewBox="0 0 24 24">
                                    <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                                </svg>
                            </button>
                            
                            ${isAdded ? `
                            <button 
                                type="button" 
                                class="listas-produto-remove-btn" 
                                data-product-id="${produto.id}"
                                data-variation-id="${produto.variation_id || 0}"
                                title="Remover da lista"
                            >✕</button>
                            ` : ''}
                        </div>
                    `;
                });
                
                grid.html(html);
                this.initProductButtons();
                this.initSimilaresButtons();
                this.initRemoveButtons();
                this.initProductsSortable();
            },
            
            initProductButtons() {
                $('.listas-produto-btn-horizontal').not('.added').off('click').on('click', (e) => {
                    const btn = $(e.target);
                    const productId = btn.data('product-id');
                    this.addProduto(productId, btn, btn.data('variation-id') || 0);
                });
            },
            
            initSimilaresButtons() {
                $('.listas-btn-similares').off('click').on('click', (e) => {
                    const btn = $(e.target);
                    const productId = btn.data('product-id');
                    this.openSimilaresModal(productId);
                });
            },
            
            initRemoveButtons() {
                const self = this;
                $('.listas-produto-remove-btn').off('click').on('click', function() {
                    const btn = $(this);
                    const productId = btn.data('product-id');
                    
                    if (!confirm('Tem certeza que deseja remover este produto da lista?')) {
                        return;
                    }
                    
                    btn.prop('disabled', true).text('...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'listas_remove_produto',
                            nonce: self.nonce,
                            post_id: self.postId,
                            product_id: productId,
                            variation_id: btn.data('variation-id') || 0,
                            categoria_id: self.categoriaId
                        },
                        success: (response) => {
                            if (response.success) {
                                // Recarregar a lista de produtos
                                self.loadProdutos($('#listas-produto-search').val());
                            } else {
                                alert('Erro: ' + (response.data || 'Não foi possível remover'));
                                btn.prop('disabled', false).text('✕');
                            }
                        },
                        error: () => {
                            alert('Erro ao remover produto');
                            btn.prop('disabled', false).text('✕');
                        }
                    });
                });
            },
            
            initProductsSortable() {
                const self = this;
                
                // Verificar se jQuery UI Sortable está disponível
                if (typeof $.fn.sortable === 'undefined') {
                    return;
                }
                
                // Só aplicar sortable se tiver categoria
                if (!this.categoriaId) {
                    return;
                }
                
                $('#listas-produtos-grid').sortable({
                    handle: '.listas-produto-drag',
                    items: '.listas-produto-item.added-item',
                    placeholder: 'listas-produto-item ui-sortable-placeholder',
                    tolerance: 'pointer',
                    axis: 'y',
                    update: function(event, ui) {
                        // Coletar nova ordem dos produtos adicionados
                        const newOrder = [];
                        $('#listas-produtos-grid .listas-produto-item.added-item').each(function(index) {
                            newOrder.push({
                                product_id: $(this).data('product-id'),
                                position: index
                            });
                        });
                        
                        // Salvar nova ordem via AJAX
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'listas_reorder_produtos',
                                nonce: self.nonce,
                                categoria_id: self.categoriaId,
                                order: JSON.stringify(newOrder)
                            },
                            success: function(response) {
                                if (!response.success) {
                                    alert('Erro ao salvar a ordem');
                                }
                            }
                        });
                    }
                });
            },
            
            openSimilaresModal(productId) {
                // Buscar dados do produto e seus similares atuais
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'listas_get_similares_admin',
                        nonce: this.nonce,
                        product_id: productId
                    },
                    success: (response) => {
                        if (response.success) {
                            this.renderSimilaresModal(productId, response.data);
                        } else {
                            alert('Erro ao carregar similares');
                        }
                    }
                });
            },
            
            renderSimilaresModal(productId, data) {
                // Remover modal anterior se existir
                $('#listas-similares-modal').remove();
                
                let similaresHtml = '';
                if (data.similares && data.similares.length > 0) {
                    data.similares.forEach((similar, index) => {
                        similaresHtml += `
                            <div class="listas-similar-row" data-similar-id="${similar.id}" data-position="${index}">
                                <div class="listas-similar-drag" title="Arraste para reordenar">⋮⋮</div>
                                <img src="${similar.image}" alt="${similar.name}" class="listas-similar-thumb">
                                <div class="listas-similar-info">
                                    <strong>${similar.name}</strong>
                                    <span>SKU: ${similar.sku || 'N/A'} | ${similar.price}</span>
                                </div>
                                <button type="button" class="listas-remove-similar" data-product-id="${productId}" data-similar-id="${similar.id}">✕</button>
                            </div>
                        `;
                    });
                } else {
                    similaresHtml = '<div class="listas-no-similares">Nenhum produto similar adicionado ainda</div>';
                }
                
                const modalHtml = `
                    <div id="listas-similares-modal" class="listas-modal-overlay">
                        <div class="listas-modal-content">
                            <div class="listas-modal-header">
                                <h3>Produtos Similares - ${data.product_name}</h3>
                                <button type="button" class="listas-modal-close">✕</button>
                            </div>
                            
                            <div class="listas-modal-body">
                                <div class="listas-similares-search-section">
                                    <h4>Adicionar produto similar</h4>
                                    <input type="text" id="listas-similares-search" class="listas-search-input" placeholder="Buscar produto por nome ou ID...">
                                    <div id="listas-similares-results" class="listas-similares-results"></div>
                                    
                                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e5e5;">
                                        <h4 style="margin-bottom: 10px;">Importar produtos similares em massa</h4>
                                        <p style="font-size: 12px; color: #666; margin-bottom: 10px;">Cole uma lista de SKUs separados por vírgula (ex: SKU1, SKU2, SKU3)</p>
                                        <textarea id="listas-similares-bulk-import" class="listas-bulk-import-textarea" placeholder="SKU1, SKU2, SKU3, ..."></textarea>
                                        <button type="button" class="listas-btn-bulk-import" data-product-id="${productId}" data-type="similares" style="margin-top: 10px;">
                                            Importar Produtos Similares
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="listas-similares-list-section">
                                    <h4>Produtos similares adicionados (${data.similares ? data.similares.length : 0})</h4>
                                    <div id="listas-similares-list" class="listas-similares-list">
                                        ${similaresHtml}
                                    </div>
                                </div>
                            </div>
                            
                            <div class="listas-modal-footer">
                                <button type="button" class="listas-modal-btn-close">Fechar</button>
                            </div>
                        </div>
                    </div>
                `;
                
                $('body').append(modalHtml);
                
                // Eventos do modal
                this.initModalEvents(productId);
            },
            
            initSortable(productId) {
                const self = this;
                
                // Verificar se jQuery UI Sortable está disponível
                if (typeof $.fn.sortable === 'undefined') {
                    return;
                }
                
                $('#listas-similares-list').sortable({
                    handle: '.listas-similar-drag',
                    items: '.listas-similar-row',
                    placeholder: 'listas-similar-row ui-sortable-placeholder',
                    tolerance: 'pointer',
                    axis: 'y',
                    update: function(event, ui) {
                        // Coletar nova ordem
                        const newOrder = [];
                        $('#listas-similares-list .listas-similar-row').each(function(index) {
                            newOrder.push({
                                similar_id: $(this).data('similar-id'),
                                position: index
                            });
                        });
                        
                        // Salvar nova ordem via AJAX
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'listas_reorder_similares',
                                nonce: self.nonce,
                                product_id: productId,
                                order: JSON.stringify(newOrder)
                            },
                            success: function(response) {
                                if (!response.success) {
                                    alert('Erro ao salvar a ordem');
                                }
                            }
                        });
                    }
                });
            },
            
            initModalEvents(productId) {
                const self = this;
                let searchTimeout = null;
                
                // Inicializar sortable
                this.initSortable(productId);
                
                // Fechar modal
                $('.listas-modal-close, .listas-modal-btn-close, .listas-modal-overlay').on('click', function(e) {
                    if (e.target === this) {
                        $('#listas-similares-modal').remove();
                        self.loadProdutos($('#listas-produto-search').val());
                    }
                });
                
                // Busca de produtos
                $('#listas-similares-search').on('input', function() {
                    clearTimeout(searchTimeout);
                    const searchTerm = $(this).val();
                    
                    if (searchTerm.length < 2) {
                        $('#listas-similares-results').empty();
                        return;
                    }
                    
                    searchTimeout = setTimeout(() => {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'listas_search_similares',
                                nonce: self.nonce,
                                search: searchTerm,
                                exclude_product_id: productId
                            },
                            success: (response) => {
                                if (response.success) {
                                    self.renderSimilaresResults(response.data, productId);
                                }
                            }
                        });
                    }, 300);
                });
                
                // Remover similar
                $(document).on('click', '.listas-remove-similar', function() {
                    const btn = $(this);
                    const similarId = btn.data('similar-id');
                    const prodId = btn.data('product-id');
                    
                    btn.prop('disabled', true);
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'listas_remove_similar',
                            nonce: self.nonce,
                            product_id: prodId,
                            similar_id: similarId
                        },
                        success: (response) => {
                            if (response.success) {
                                btn.closest('.listas-similar-row').fadeOut(200, function() {
                                    $(this).remove();
                                    const count = $('#listas-similares-list .listas-similar-row').length;
                                    if (count === 0) {
                                        $('#listas-similares-list').html('<div class="listas-no-similares">Nenhum produto similar adicionado ainda</div>');
                                    }
                                    // Atualizar título
                                    $('.listas-similares-list-section h4').text('Produtos similares adicionados (' + count + ')');
                                });
                            }
                        }
                    });
                });
                
                // Importação em massa de similares
                $(document).on('click', '.listas-btn-bulk-import[data-type="similares"]', function() {
                    const btn = $(this);
                    const productId = btn.data('product-id');
                    const textarea = $('#listas-similares-bulk-import');
                    const skus = textarea.val().trim();
                    
                    if (!skus) {
                        alert('Por favor, cole uma lista de SKUs (separados por vírgula ou um por linha)');
                        return;
                    }

                    // Aceita SKUs separados por vírgula, ponto-e-vírgula, quebra de linha ou espaço
                    const skuList = skus.split(/[\s,;]+/).map(s => s.trim()).filter(s => s.length > 0);

                    if (skuList.length === 0) {
                        alert('Nenhum SKU válido encontrado');
                        return;
                    }

                    btn.prop('disabled', true).text('Importando...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'listas_bulk_import_similares',
                            nonce: self.nonce,
                            product_id: productId,
                            skus: JSON.stringify(skuList)
                        },
                        success: function(response) {
                            if (response.success) {
                                textarea.val('');
                                alert('Importação concluída! ' + response.data.added + ' produtos similares adicionados.');
                                // Recarregar lista de similares
                                self.refreshSimilaresList(productId);
                            } else {
                                alert('Erro: ' + (response.data || 'Não foi possível importar os produtos'));
                            }
                            btn.prop('disabled', false).text('Importar Produtos Similares');
                        },
                        error: function() {
                            alert('Erro ao importar produtos');
                            btn.prop('disabled', false).text('Importar Produtos Similares');
                        }
                    });
                });
            },
            
            renderSimilaresResults(produtos, mainProductId) {
                const results = $('#listas-similares-results');
                const self = this;
                
                if (produtos.length === 0) {
                    results.html('<div class="listas-no-results">Nenhum produto encontrado</div>');
                    return;
                }
                
                let html = '';
                produtos.forEach(produto => {
                    const isAlreadyAdded = produto.is_similar;
                    html += `
                        <div class="listas-similar-result-item ${isAlreadyAdded ? 'already-added' : ''}" data-product-id="${produto.id}">
                            <img src="${produto.image}" alt="${produto.name}">
                            <div class="listas-similar-result-info">
                                <strong>${produto.name}</strong>
                                <span>SKU: ${produto.sku || 'N/A'} | ${produto.price}</span>
                            </div>
                            <button 
                                type="button" 
                                class="listas-add-similar-btn ${isAlreadyAdded ? 'added' : ''}"
                                data-product-id="${mainProductId}"
                                data-similar-id="${produto.id}"
                                ${isAlreadyAdded ? 'disabled' : ''}
                            >
                                ${isAlreadyAdded ? '✓ Adicionado' : '+ Adicionar'}
                            </button>
                        </div>
                    `;
                });
                
                results.html(html);
                
                // Evento de adicionar similar
                $('.listas-add-similar-btn').not('.added').off('click').on('click', function() {
                    const btn = $(this);
                    const prodId = btn.data('product-id');
                    const similarId = btn.data('similar-id');
                    
                    btn.prop('disabled', true).text('Adicionando...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'listas_add_similar',
                            nonce: self.nonce,
                            product_id: prodId,
                            similar_id: similarId
                        },
                        success: (response) => {
                            if (response.success) {
                                btn.addClass('added').text('✓ Adicionado');
                                // Recarregar a lista de similares
                                self.refreshSimilaresList(prodId);
                            } else {
                                alert(response.data || 'Erro ao adicionar');
                                btn.prop('disabled', false).text('+ Adicionar');
                            }
                        }
                    });
                });
            },
            
            refreshSimilaresList(productId) {
                const self = this;
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'listas_get_similares_admin',
                        nonce: self.nonce,
                        product_id: productId
                    },
                    success: (response) => {
                        if (response.success && response.data.similares) {
                            let similaresHtml = '';
                            response.data.similares.forEach((similar, index) => {
                                similaresHtml += `
                                    <div class="listas-similar-row" data-similar-id="${similar.id}" data-position="${index}">
                                        <div class="listas-similar-drag" title="Arraste para reordenar">⋮⋮</div>
                                        <img src="${similar.image}" alt="${similar.name}" class="listas-similar-thumb">
                                        <div class="listas-similar-info">
                                            <strong>${similar.name}</strong>
                                            <span>SKU: ${similar.sku || 'N/A'} | ${similar.price}</span>
                                        </div>
                                        <button type="button" class="listas-remove-similar" data-product-id="${productId}" data-similar-id="${similar.id}">✕</button>
                                    </div>
                                `;
                            });
                            
                            if (similaresHtml === '') {
                                similaresHtml = '<div class="listas-no-similares">Nenhum produto similar adicionado ainda</div>';
                            }
                            
                            $('#listas-similares-list').html(similaresHtml);
                            $('.listas-similares-list-section h4').text('Produtos similares adicionados (' + response.data.similares.length + ')');
                            
                            // Reinicializar o sortable após atualizar a lista
                            self.initSortable(productId);
                        }
                    }
                });
            },
            
            addProduto(productId, btn, variationId) {
                btn.prop('disabled', true).text('Adicionando...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'listas_add_produto',
                        nonce: this.nonce,
                        post_id: this.postId,
                        product_id: productId,
                        variation_id: variationId || 0,
                        categoria_id: this.categoriaId
                    },
                    success: (response) => {
                        if (response.success) {
                            btn.addClass('added').text('✓ Adicionado');
                            if (response.data.categoria_id) {
                                this.categoriaId = response.data.categoria_id;
                                $('#listas_categoria_id').val(this.categoriaId);
                            }
                        } else {
                            alert('Erro: ' + response.data);
                            btn.prop('disabled', false).text('+ Adicionar');
                        }
                    },
                    error: () => {
                        alert('Erro ao adicionar produto');
                        btn.prop('disabled', false).text('+ Adicionar');
                    }
                });
            }
        };
        
        ListasProdutos.init();
        
        // Importação em massa de produtos à lista
        $(document).on('click', '.listas-btn-bulk-import[data-type="produtos"]', function() {
            const btn = $(this);
            const textarea = $('#listas-produtos-bulk-import');
            const skus = textarea.val().trim();
            
            if (!skus) {
                alert('Por favor, cole uma lista de SKUs (separados por vírgula ou um por linha)');
                return;
            }

            // Aceita SKUs separados por vírgula, ponto-e-vírgula, quebra de linha ou espaço
            const skuList = skus.split(/[\s,;]+/).map(s => s.trim()).filter(s => s.length > 0);

            if (skuList.length === 0) {
                alert('Nenhum SKU válido encontrado');
                return;
            }

            btn.prop('disabled', true).text('Importando...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'listas_bulk_import_produtos',
                    nonce: ListasProdutos.nonce,
                    post_id: ListasProdutos.postId,
                    categoria_id: ListasProdutos.categoriaId,
                    skus: JSON.stringify(skuList)
                },
                success: function(response) {
                    if (response.success) {
                        textarea.val('');
                        
                        // Atualizar categoria_id se foi criado
                        if (response.data.categoria_id && response.data.categoria_id !== ListasProdutos.categoriaId) {
                            ListasProdutos.categoriaId = response.data.categoria_id;
                            $('#listas_categoria_id').val(response.data.categoria_id);
                        }
                        
                        // Montar mensagem de feedback
                        let message = 'Importação concluída! ';
                        if (response.data.added > 0) {
                            message += response.data.added + ' produto(s) adicionado(s) à lista.';
                        }
                        if (response.data.already_in_list > 0) {
                            message += ' ' + response.data.already_in_list + ' produto(s) já estava(m) na lista.';
                        }
                        if (response.data.errors && response.data.errors.length > 0) {
                            message += ' ' + response.data.errors.length + ' SKU(s) não encontrado(s).';
                        }
                        
                        alert(message);
                        
                        // Limpar busca e recarregar lista de produtos para mostrar todos
                        $('#listas-produto-search').val('');
                        ListasProdutos.loadProdutos('');
                    } else {
                        alert('Erro: ' + (response.data || 'Não foi possível importar os produtos'));
                    }
                    btn.prop('disabled', false).text('Importar Produtos à Lista');
                },
                error: function() {
                    alert('Erro ao importar produtos');
                    btn.prop('disabled', false).text('Importar Produtos à Lista');
                }
            });
        });
    });

    jQuery(document).ready(function($) {
        $('#listas_cupom_ativo').on('change', function() {
            if ($(this).is(':checked')) {
                $('#listas-cupom-fields').slideDown();
            } else {
                $('#listas-cupom-fields').slideUp();
            }
        });
    });
