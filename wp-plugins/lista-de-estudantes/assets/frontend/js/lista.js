    jQuery(document).ready(function($) {
        const BRINDES_VALOR_MINIMO = ListasFrontendConfig.brindesValorMinimo;
        
        function atualizarTotal() {
            let total = 0;
            let qtd = 0;
            
            $('.listas-produto-check:checked').each(function() {
                const price = parseFloat($(this).data('price')) || 0;
                const qty = parseInt($(this).closest('.listas-produto-wrapper').find('.qty-input').val()) || 1;
                total += price * qty;
                qtd++;
            });
            
            // Incluir produtos similares selecionados
            $('.listas-similar-check:checked').each(function() {
                const price = parseFloat($(this).data('price')) || 0;
                const qty = parseInt($(this).closest('.listas-similar-item').find('.similar-qty-input').val()) || 1;
                total += price * qty;
                qtd++;
            });
            
            $('#listas-total-selecionados').text(total.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
            $('#listas-qtd-selecionados').text(qtd);
            $('#listas-btn-comprar-selecionados').prop('disabled', qtd === 0);
            
            // Atualizar seção de brindes
            atualizarBrindes(total);
        }
        
        function verificarBrindeNoCarrinho() {
            // Verificar se já tem brinde no carrinho via AJAX
            return $.ajax({
                url: ListasFrontendConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'listas_check_brinde_in_cart'
                },
                async: false
            }).responseJSON;
        }
        
        function atualizarBrindes(total) {
            const progressBar = $('#listas-brindes-progress-bar');
            const progressText = $('#listas-brindes-progress-text');
            const faltamText = $('#listas-brindes-faltam');
            const brindesItems = $('.listas-brinde-item');
            
            if (total >= BRINDES_VALOR_MINIMO) {
                // Brindes desbloqueados - verificar se já tem brinde no carrinho
                $.ajax({
                    url: ListasFrontendConfig.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'listas_check_brinde_in_cart'
                    },
                    success: function(response) {
                        const hasBrinde = response.success && response.data.has_brinde;
                        
                        if (hasBrinde) {
                            // Já tem brinde no carrinho - desabilitar TODOS os brindes
                            brindesItems.removeClass('brinde-locked').addClass('brinde-disabled');
                            
                            // Desabilitar todos os checkboxes
                            brindesItems.find('.listas-brinde-check').prop('disabled', true).prop('checked', false);
                            
                            // Desabilitar todos os botões de adicionar (mesmo que ainda existam)
                            brindesItems.find('.listas-btn-adicionar-brinde').prop('disabled', true);
                            
                            // Garantir que todos os itens tenham a classe disabled para escala de cinza
                            brindesItems.each(function() {
                                const item = $(this);
                                // Se o item não tem a mensagem de sucesso, significa que ainda tem botão ativo
                                if (!item.find('.listas-produto-adicionado').length) {
                                    item.addClass('brinde-disabled');
                                }
                            });
                            
                            progressBar.css('width', '100%');
                            progressText.html('<strong style="color: #fff;">✓ Você já escolheu seu brinde! Apenas 1 brinde por lista.</strong>');
                        } else {
                            // Pode escolher 1 brinde - verificar se já tem algum checkbox marcado
                            const checkedBrinde = $('.listas-brinde-check:checked');
                            
                            if (checkedBrinde.length > 0) {
                                // Já tem um brinde selecionado - travar os outros
                                brindesItems.removeClass('brinde-locked');
                                brindesItems.find('.listas-brinde-check').each(function() {
                                    if (!$(this).is(':checked')) {
                                        $(this).prop('disabled', true);
                                        $(this).closest('.listas-brinde-item').addClass('brinde-disabled');
                                        $(this).closest('.listas-brinde-item').find('.listas-btn-adicionar-brinde').prop('disabled', true);
                                    } else {
                                        $(this).prop('disabled', false);
                                        $(this).closest('.listas-brinde-item').removeClass('brinde-disabled');
                                        $(this).closest('.listas-brinde-item').find('.listas-btn-adicionar-brinde').prop('disabled', false);
                                    }
                                });
                                progressBar.css('width', '100%');
                                progressText.html('<strong style="color: #fff;">✓ Escolha 1 brinde grátis</strong>');
                            } else {
                                // Nenhum brinde selecionado - todos disponíveis
                                brindesItems.removeClass('brinde-locked brinde-disabled');
                                brindesItems.find('.listas-brinde-check').prop('disabled', false);
                                brindesItems.find('.listas-btn-adicionar-brinde').prop('disabled', false);
                                progressBar.css('width', '100%');
                                progressText.html('<strong style="color: #fff;">✓ Escolha 1 brinde grátis</strong>');
                            }
                        }
                    }
                });
            } else {
                // Brindes bloqueados
                brindesItems.addClass('brinde-locked').removeClass('brinde-disabled');
                brindesItems.find('.listas-brinde-check').prop('disabled', true).prop('checked', false);
                brindesItems.find('.listas-btn-adicionar-brinde').prop('disabled', true);
                
                const faltam = BRINDES_VALOR_MINIMO - total;
                const percent = Math.min(100, (total / BRINDES_VALOR_MINIMO) * 100);
                
                progressBar.css('width', percent + '%');
                faltamText.text(faltam.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                progressText.html('Selecione produtos no valor de R$ <strong>' + faltam.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</strong> para liberar os brindes');
            }
        }
        
        $('#listas-selecionar-tudo').on('change', function() {
            $('.listas-produto-check').prop('checked', $(this).is(':checked'));
            atualizarTotal();
        });
        
        $(document).on('change', '.listas-produto-check', function() {
            const checkbox = $(this);
            const item = checkbox.closest('.listas-produto-item');
            
            // Adicionar/remover classe para fundo verde
            if (checkbox.is(':checked')) {
                item.addClass('listas-produto-item-checked');
            } else {
                item.removeClass('listas-produto-item-checked');
            }
            
            atualizarTotal();
            const total = $('.listas-produto-check').length;
            const checked = $('.listas-produto-check:checked').length;
            $('#listas-selecionar-tudo').prop('checked', total === checked);
        });
        
        // Tornar o grid do produto clicável para marcar/desmarcar checkbox
        $(document).on('click', '.listas-produto-item', function(e) {
            // Não acionar se clicar em botões, inputs ou links
            if ($(e.target).is('button, input, a, .listas-btn-adicionar, .listas-ver-similares, .listas-btn-selecionar-variacao')) {
                return;
            }
            
            const checkbox = $(this).find('.listas-produto-check');
            if (checkbox.length && !checkbox.is(':disabled')) {
                checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
            }
        });
        
        // Tornar o grid do produto similar clicável para marcar/desmarcar checkbox
        $(document).on('click', '.listas-similar-item', function(e) {
            // Não acionar se clicar em botões, inputs ou links
            if ($(e.target).is('button, input, a, .listas-similar-btn-adicionar')) {
                return;
            }
            
            const checkbox = $(this).find('.listas-similar-check');
            if (checkbox.length && !checkbox.is(':disabled')) {
                checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
            }
        });
        
        // Aplicar fundo verde quando checkbox de produto similar for marcado
        $(document).on('change', '.listas-similar-check', function() {
            const checkbox = $(this);
            const item = checkbox.closest('.listas-similar-item');
            
            // Adicionar/remover classe para fundo verde
            if (checkbox.is(':checked')) {
                item.addClass('listas-similar-item-checked');
            } else {
                item.removeClass('listas-similar-item-checked');
            }
            
            atualizarTotal();
        });
        
        $('.qty-minus').on('click', function() {
            const input = $(this).siblings('.qty-input');
            const val = parseInt(input.val());
            if (val > 1) { input.val(val - 1); atualizarTotal(); }
        });
        
        $('.qty-plus').on('click', function() {
            const input = $(this).siblings('.qty-input');
            const val = parseInt(input.val());
            const max = parseInt(input.attr('max'));
            if (val < max) { input.val(val + 1); atualizarTotal(); }
        });
        
        $('.qty-input').on('change', function() { atualizarTotal(); });
        
        // Selecionar variação para produtos variáveis
        $('.listas-btn-selecionar-variacao').on('click', function() {
            const productId = $(this).data('product-id');
            const wrapper = $('#variacoes-' + productId);
            wrapper.toggleClass('active');
        });
        
        // Atualizar variação quando clicar em tag
        $(document).on('click', '.listas-variacao-tag', function() {
            const tag = $(this);
            const attrName = tag.data('attribute');
            const productId = tag.closest('.listas-variacao-tags').data('product-id');
            const wrapper = tag.closest('.listas-produto-wrapper');
            const infoBox = $('#variacao-info-' + productId);
            
            // Remover active de todas as tags do mesmo atributo
            tag.siblings('.listas-variacao-tag').removeClass('active');
            // Adicionar active na tag clicada
            tag.addClass('active');
            
            // Coletar todos os atributos selecionados usando o nome exato do atributo
            const attributes = {};
            let selectedCount = 0;
            
            wrapper.find('.listas-variacao-tags').each(function() {
                const activeTag = $(this).find('.listas-variacao-tag.active');
                if (activeTag.length) {
                    const attr = $(this).data('attribute');
                    // SEMPRE usar data-value que contém o slug
                    let value = activeTag.data('value');
                    
                    // Se o valor ainda for o nome (primeira letra maiúscula), converter para slug
                    if (value && value.charAt(0) === value.charAt(0).toUpperCase() && value !== value.toLowerCase()) {
                        value = value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
                    }
                    
                    // Enviar com prefixo attribute_ (formato WooCommerce) - formato principal
                    attributes['attribute_' + attr] = value;
                    
                    // Também enviar sem prefixo para garantir compatibilidade
                    attributes[attr] = value;
                    
                    selectedCount++;
                }
            });
            
            // Verificar se todos os atributos foram selecionados (usar contador, não Object.keys)
            const totalAttrs = wrapper.find('.listas-variacao-tags').length;
            const selectedAttrs = selectedCount;
            
            if (totalAttrs === selectedAttrs) {
                // Buscar variação correspondente
                $.ajax({
                    url: ListasFrontendConfig.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'listas_find_variation',
                        product_id: productId,
                        attributes: attributes
                    },
                    success: function(response) {
                        if (response.success && response.data && response.data.variation_id) {
                            const variation = response.data;
                            wrapper.attr('data-variation-id', variation.variation_id);
                            wrapper.find('.listas-produto-check').attr('data-variation-id', variation.variation_id).attr('data-price', variation.price);
                            
                            // Mostrar preço com tag de desconto
                            const priceContainer = wrapper.find('.listas-produto-price');
                            let priceHtml = variation.price_html;
                            
                            // Adicionar tag de desconto se houver
                            if (variation.discount_percent) {
                                priceHtml += ' <span class="listas-desconto-tag">' + variation.discount_percent + '% OFF</span>';
                            }
                            
                            priceContainer.html(priceHtml);
                            
                            // Atualizar botão de adicionar dentro do painel de variações
                            const btnAdicionar = $('#btn-adicionar-var-' + productId);
                            if (btnAdicionar.length) {
                                btnAdicionar.attr('data-variation-id', variation.variation_id).prop('disabled', false).removeClass('disabled');
                            }
                            
                            // Atualizar botão principal também se existir
                            wrapper.find('.listas-btn-adicionar').attr('data-variation-id', variation.variation_id).prop('disabled', false);
                            
                            const variationInfoHtml = '<div class="listas-variacao-price-label">Preço da variação</div>' +
                                '<div class="listas-variacao-price-value">' + priceHtml + '</div>' +
                                (variation.variation_name ? '<div class="listas-variacao-selected-name">' + variation.variation_name + '</div>' : '');
                            
                            infoBox.removeClass('is-empty').html(variationInfoHtml);
                        } else {
                            const errorMsg = response.data && response.data.message ? response.data.message : 'Variação não encontrada. Verifique as opções selecionadas.';
                            console.error('Erro ao encontrar variação:', response);
                            
                            // Desabilitar botão em caso de erro
                            $('#btn-adicionar-var-' + productId).prop('disabled', true);
                            
                            infoBox.addClass('is-empty').text('Selecione as opções para ver o preço');
                            alert(errorMsg);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Erro AJAX:', status, error, xhr.responseText);
                        infoBox.addClass('is-empty').text('Selecione as opções para ver o preço');
                        alert('Erro ao buscar variação. Verifique o console para mais detalhes.');
                    }
                });
            } else {
                // Desabilitar botão se nem todos os atributos foram selecionados
                $('#btn-adicionar-var-' + productId).prop('disabled', true);
                infoBox.addClass('is-empty').text('Selecione as opções para ver o preço');
            }
        });
        
        // Adicionar ao carrinho
        function adicionarAoCarrinho(productId, variationId, quantity) {
            return $.ajax({
                url: ListasFrontendConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'listas_add_to_cart',
                    product_id: productId,
                    variation_id: variationId || 0,
                    quantity: quantity
                }
            });
        }
        
        
        $(document).on('click', '.listas-btn-adicionar', function() {
            const btn = $(this);
            const productId = btn.data('product-id');
            let variationId = btn.data('variation-id') || 0;
            const wrapper = btn.closest('.listas-produto-wrapper');
            
            // Se variation_id é 0, tentar pegar do wrapper
            if (variationId === 0) {
                variationId = wrapper.attr('data-variation-id') || 0;
            }
            
            // Quantidade padrão é 1
            const qty = 1;
            
            // Verificar se é produto variável e se a variação foi selecionada
            if (wrapper.attr('data-is-variable') === '1' && variationId === 0) {
                alert('Por favor, selecione uma variação antes de adicionar ao carrinho');
                return;
            }
            
            // Guardar texto original e estado do botão
            const btnOriginalText = btn.text();
            
            // Desabilitar botão e mostrar feedback
            btn.prop('disabled', true).text('Adicionando...');
            
            adicionarAoCarrinho(productId, variationId, qty).done(function(response) {
                    if (response.success) {
                    const successMsg = '<div class="listas-produto-adicionado">✓ Produto adicionado ao carrinho</div>';
                    const cartBtn = '<a href="' + ListasFrontendConfig.cartUrl + '" class="listas-ver-similares listas-btn-ver-carrinho" title="Ver carrinho">' +
                        '<svg viewBox="0 0 16 16"><path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41zm-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9z"/><path fill-rule="evenodd" d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3zM3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9H3.1z"/></svg>' +
                        'Ver carrinho</a>';
                    const feedbackHtml = '<div class="listas-produto-feedback-inline">' + successMsg + cartBtn + '</div>';
                    // Procura dentro do próprio card antes de cair no id
                    // global: desde a 2.1.0 a mesma lista pode ter dois cards
                    // do mesmo produto (variações diferentes), e o id sozinho
                    // acertaria sempre o primeiro.
                    const feedbackLocal = wrapper.find('.listas-produto-feedback').first();
                    const feedbackContainer = feedbackLocal.length ? feedbackLocal : $('#listas-feedback-' + productId);
                    
                    if (feedbackContainer.length) {
                        feedbackContainer.html(feedbackHtml).addClass('is-visible');
                    } else {
                        wrapper.append('<div class="listas-produto-feedback is-visible">' + feedbackHtml + '</div>');
                    }
                    
                    btn.text(btnOriginalText).prop('disabled', false);
                    
                        if (typeof wc_add_to_cart_params !== 'undefined') {
                            $(document.body).trigger('wc_fragment_refresh');
                        }
                    } else {
                    // Restaurar botão em caso de erro
                    alert('Erro: ' + (response.data || 'Não foi possível adicionar o produto ao carrinho'));
                    btn.text(btnOriginalText).prop('disabled', false);
                }
            }).fail(function(xhr, status, error) {
                console.error('Erro AJAX:', status, error, xhr.responseText);
                alert('Erro ao adicionar produto. Verifique o console para mais detalhes.');
                // Restaurar botão em caso de erro
                btn.text(btnOriginalText).prop('disabled', false);
            });
        });
        
        $('.listas-ver-similares').on('click', function() {
            const btn = $(this);
            const productId = btn.data('product-id');
            const cardWrapper = btn.closest('.listas-produto-wrapper');
            const similaresLocal = cardWrapper.find('.listas-similares-expanded').first();
            const similares = similaresLocal.length ? similaresLocal : $('#similares-' + productId);
            
            if (similares.hasClass('active')) {
                similares.removeClass('active').find('.listas-similares-grid').empty();
                btn.html('<svg viewBox="0 0 16 16"><path d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2z"/></svg> Ver similares');
                return;
            }
            
            btn.text('Carregando...');
            
            $.ajax({
                url: ListasFrontendConfig.ajaxUrl,
                type: 'POST',
                data: { action: 'listas_ver_similares', product_id: productId },
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        let html = '';
                        response.data.forEach(function(produto) {
                            const price = parseFloat(produto.price_value) || 0;
                            html += '<div class="listas-similar-item" data-product-id="'+produto.id+'" data-price="'+price+'">';
                            html += '<div class="listas-similar-checkbox"><input type="checkbox" class="listas-similar-check" value="'+produto.id+'" data-price="'+price+'"></div>';
                            html += '<div class="listas-similar-image"><img src="'+produto.image+'" alt="'+produto.name+'"></div>';
                            html += '<div class="listas-similar-info"><h3 class="listas-similar-name">'+produto.name+'</h3><div class="listas-similar-meta">ID: '+produto.id+(produto.sku ? ' | SKU: '+produto.sku : '')+'</div></div>';
                            html += '<div class="listas-similar-actions">';
                            html += '<div class="listas-similar-qty"><button type="button" class="similar-qty-minus">−</button><input type="number" class="similar-qty-input" value="1" min="1"><button type="button" class="similar-qty-plus">+</button></div>';
                            html += '<div class="listas-similar-price">'+produto.price+'</div>';
                            html += '<button type="button" class="listas-similar-btn-adicionar" data-product-id="'+produto.id+'">Adicionar</button>';
                            html += '</div>';
                            html += '</div>';
                        });
                        similares.find('.listas-similares-grid').html(html);
                        similares.addClass('active');
                        btn.html('<svg viewBox="0 0 16 16"><path d="M2 8a.5.5 0 0 1 .5-.5h11a.5.5 0 0 1 0 1h-11A.5.5 0 0 1 2 8z"/></svg> Fechar similares');
                        
                        // Adicionar eventos para produtos similares
                        $(document).off('click', '.similar-qty-minus, .similar-qty-plus').on('click', '.similar-qty-minus', function() {
                            const input = $(this).siblings('.similar-qty-input');
                            const val = parseInt(input.val());
                            if (val > 1) { input.val(val - 1); atualizarTotal(); }
                        }).on('click', '.similar-qty-plus', function() {
                            const input = $(this).siblings('.similar-qty-input');
                            const val = parseInt(input.val());
                            input.val(val + 1);
                            atualizarTotal();
                        });
                        
                        $(document).off('click', '.listas-similar-btn-adicionar').on('click', '.listas-similar-btn-adicionar', function() {
                            const btnSimilar = $(this);
                            const productIdSimilar = btnSimilar.data('product-id');
                            const wrapperSimilar = btnSimilar.closest('.listas-similar-item');
                            const qtySimilar = wrapperSimilar.find('.similar-qty-input').val();
                            
                            btnSimilar.prop('disabled', true).text('Adicionando...');
                            
                            adicionarAoCarrinho(productIdSimilar, 0, qtySimilar).done(function(response) {
                                if (response.success) {
                                    btnSimilar.parent().html('<div class="listas-produto-adicionado">✓ Produto adicionado ao carrinho</div>');
                                    if (typeof wc_add_to_cart_params !== 'undefined') {
                                        $(document.body).trigger('wc_fragment_refresh');
                                    }
                                } else {
                                    alert('Erro: ' + (response.data || 'Não foi possível adicionar'));
                                    btnSimilar.text('Adicionar').prop('disabled', false);
                                }
                            }).fail(function() {
                                alert('Erro ao adicionar produto');
                                btnSimilar.text('Adicionar').prop('disabled', false);
                            });
                        });
                        
                        $(document).off('change', '.listas-similar-check').on('change', '.listas-similar-check', function() {
                            atualizarTotal();
                        });
                    } else {
                        alert('Nenhum produto similar encontrado');
                        btn.html('<svg viewBox="0 0 16 16"><path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41zm-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9z"/><path fill-rule="evenodd" d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3zM3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9H3.1z"/></svg> Ver similares');
                    }
                }
            });
        });
        
        // Adicionar brinde ao carrinho
        $(document).on('click', '.listas-btn-adicionar-brinde', function() {
            const btn = $(this);
            const productId = btn.data('product-id');
            
            // Verificar se o total atingiu o mínimo
            let total = 0;
            $('.listas-produto-check:checked').each(function() {
                const price = parseFloat($(this).data('price')) || 0;
                const qty = parseInt($(this).closest('.listas-produto-wrapper').find('.qty-input').val()) || 1;
                total += price * qty;
            });
            
            $('.listas-similar-check:checked').each(function() {
                const price = parseFloat($(this).data('price')) || 0;
                const qty = parseInt($(this).closest('.listas-similar-item').find('.similar-qty-input').val()) || 1;
                total += price * qty;
            });
            
            if (total < BRINDES_VALOR_MINIMO) {
                alert('Você precisa selecionar produtos no valor mínimo de R$ ' + BRINDES_VALOR_MINIMO.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' para adicionar brindes.');
                return;
            }
            
            // Verificar se já tem brinde no carrinho
            $.ajax({
                url: ListasFrontendConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'listas_check_brinde_in_cart'
                },
                success: function(checkResponse) {
                    if (checkResponse.success && checkResponse.data.has_brinde) {
                        alert('Você já escolheu seu brinde! Apenas 1 brinde por lista.');
                        return;
                    }
                    
                    // Prosseguir com a adição
                    const btnOriginalText = btn.text();
                    btn.prop('disabled', true).text('Adicionando...');
                    
                    // Adicionar brinde com flag especial
                    $.ajax({
                        url: ListasFrontendConfig.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'listas_add_brinde_to_cart',
                            product_id: productId,
                            quantity: 1
                        },
                        success: function(response) {
                            if (response.success) {
                                const currentBrindeItem = btn.closest('.listas-brinde-item');
                                
                                // Substituir botão por mensagem de sucesso
                                btn.parent().html('<div class="listas-produto-adicionado">✓ Brinde adicionado ao carrinho</div>');
                                
                                // Desabilitar checkbox do item atual
                                currentBrindeItem.find('.listas-brinde-check').prop('checked', true).prop('disabled', true);
                                
                                // Desabilitar TODOS os outros brindes (incluindo checkboxes e botões)
                                $('.listas-brinde-item').each(function() {
                                    const item = $(this);
                                    if (!item.is(currentBrindeItem)) {
                                        // Adicionar classe disabled para escala de cinza
                                        item.addClass('brinde-disabled');
                                        // Desabilitar checkbox
                                        item.find('.listas-brinde-check').prop('disabled', true).prop('checked', false);
                                        // Desabilitar botão de adicionar (se ainda existir)
                                        const addBtn = item.find('.listas-btn-adicionar-brinde');
                                        if (addBtn.length) {
                                            addBtn.prop('disabled', true).css('opacity', '0.5').css('cursor', 'not-allowed');
                                        }
                                    }
                                });
                                
                                // Forçar atualização visual de todos os brindes (a classe brinde-disabled já faz isso via CSS)
                                
                                // Atualizar texto do header
                                $('#listas-brindes-progress-text').html('<strong style="color: #fff;">✓ Você já escolheu seu brinde! Apenas 1 brinde por lista.</strong>');
                                
                                if (typeof wc_add_to_cart_params !== 'undefined') {
                                    $(document.body).trigger('wc_fragment_refresh');
                                }
                            } else {
                                alert('Erro: ' + (response.data || 'Não foi possível adicionar o brinde'));
                                btn.text(btnOriginalText).prop('disabled', false);
                            }
                        },
                        error: function() {
                            alert('Erro ao adicionar brinde');
                            btn.text(btnOriginalText).prop('disabled', false);
                        }
                    });
                }
            });
        });
        
        // Incluir brindes selecionados no total e na compra em lote
        $(document).on('change', '.listas-brinde-check', function() {
            const checkbox = $(this);
            const isChecked = checkbox.is(':checked');
            const brindeItem = checkbox.closest('.listas-brinde-item');
            
            if (isChecked) {
                // Se este brinde foi marcado, desmarcar e desabilitar todos os outros
                $('.listas-brinde-check').not(checkbox).each(function() {
                    $(this).prop('checked', false).prop('disabled', true);
                    $(this).closest('.listas-brinde-item').addClass('brinde-disabled');
                    $(this).closest('.listas-brinde-item').find('.listas-btn-adicionar-brinde').prop('disabled', true);
                });
                
                // Remover classe disabled do item selecionado
                brindeItem.removeClass('brinde-disabled');
                brindeItem.find('.listas-btn-adicionar-brinde').prop('disabled', false);
            } else {
                // Se este brinde foi desmarcado, reabilitar todos os outros (se o total ainda for >= 1000)
                let total = 0;
                $('.listas-produto-check:checked').each(function() {
                    const price = parseFloat($(this).data('price')) || 0;
                    const qty = parseInt($(this).closest('.listas-produto-wrapper').find('.qty-input').val()) || 1;
                    total += price * qty;
                });
                $('.listas-similar-check:checked').each(function() {
                    const price = parseFloat($(this).data('price')) || 0;
                    const qty = parseInt($(this).closest('.listas-similar-item').find('.similar-qty-input').val()) || 1;
                    total += price * qty;
                });
                
                if (total >= BRINDES_VALOR_MINIMO) {
                    // Reabilitar todos os outros brindes
                    $('.listas-brinde-check').not(checkbox).each(function() {
                        $(this).prop('disabled', false);
                        $(this).closest('.listas-brinde-item').removeClass('brinde-disabled');
                        $(this).closest('.listas-brinde-item').find('.listas-btn-adicionar-brinde').prop('disabled', false);
                    });
                }
            }
            
            atualizarTotal();
        });
        
        // Modificar função de comprar selecionados para incluir brindes
        $('#listas-btn-comprar-selecionados').off('click').on('click', function() {
            const btn = $(this);
            const originalText = btn.text();
            btn.prop('disabled', true).text('Adicionando...');
            
            const produtos = [];
            let missingVariation = null;
            
            // Calcular total primeiro
            let total = 0;
            $('.listas-produto-check:checked').each(function() {
                const wrapper = $(this).closest('.listas-produto-wrapper');
                const isVariable = wrapper.attr('data-is-variable') === '1';
                const wrapperVariationId = wrapper.attr('data-variation-id') || 0;
                
                if (isVariable && (!wrapperVariationId || wrapperVariationId === '0')) {
                    missingVariation = wrapper.find('.listas-produto-title').text();
                    return false;
                }
                
                const price = parseFloat($(this).data('price')) || 0;
                total += price;
                
                produtos.push({
                    id: $(this).val(),
                    variation_id: wrapperVariationId,
                    qty: 1
                });
            });
            
            if (missingVariation) {
                alert('Selecione uma variação para o produto "' + missingVariation + '" antes de continuar.');
                btn.prop('disabled', false).text(originalText);
                return;
            }
            
            // Incluir produtos similares selecionados
            $('.listas-similar-check:checked').each(function() {
                const wrapper = $(this).closest('.listas-similar-item');
                const price = parseFloat($(this).data('price')) || 0;
                total += price;
                
                produtos.push({
                    id: $(this).val(),
                    variation_id: 0,
                    qty: wrapper.find('.similar-qty-input').val()
                });
            });
            
            // Verificar se pode adicionar brindes
            if (total >= BRINDES_VALOR_MINIMO) {
                $('.listas-brinde-check:checked').each(function() {
                    produtos.push({
                        id: $(this).val(),
                        variation_id: 0,
                        qty: 1,
                        is_brinde: true
                    });
                });
            }
            
            if (produtos.length === 0) {
                btn.prop('disabled', false).text(originalText);
                return;
            }
            
            let adicionados = 0;
            produtos.forEach((produto, index) => {
                setTimeout(() => {
                    if (produto.is_brinde) {
                        // Adicionar brinde com flag especial
                        $.ajax({
                            url: ListasFrontendConfig.ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'listas_add_brinde_to_cart',
                                product_id: produto.id,
                                quantity: produto.qty
                            },
                            success: function(response) {
                                adicionados++;
                                if (adicionados === produtos.length) {
                                    window.location.href = ListasFrontendConfig.cartUrl;
                                }
                            },
                            error: function() {
                                adicionados++;
                                if (adicionados === produtos.length) {
                                    window.location.href = ListasFrontendConfig.cartUrl;
                                }
                            }
                        });
                    } else {
                        adicionarAoCarrinho(produto.id, produto.variation_id, produto.qty).done(function() {
                            adicionados++;
                            if (adicionados === produtos.length) {
                                window.location.href = ListasFrontendConfig.cartUrl;
                            }
                        }).fail(function() {
                            adicionados++;
                            if (adicionados === produtos.length) {
                                window.location.href = ListasFrontendConfig.cartUrl;
                            }
                        });
                    }
                }, index * 100);
            });
        });
        
        // Aplicar classe de fundo verde aos produtos já marcados ao carregar
        $('.listas-produto-check:checked').each(function() {
            $(this).closest('.listas-produto-item').addClass('listas-produto-item-checked');
        });
        
        // Aplicar classe de fundo verde aos produtos similares já marcados ao carregar
        $('.listas-similar-check:checked').each(function() {
            $(this).closest('.listas-similar-item').addClass('listas-similar-item-checked');
        });
        
        // Inicializar brindes ao carregar
        atualizarBrindes(0);
        
        // Atualizar brindes quando o carrinho for atualizado
        $(document.body).on('wc_fragment_refresh', function() {
            let total = 0;
            $('.listas-produto-check:checked').each(function() {
                const price = parseFloat($(this).data('price')) || 0;
                const qty = parseInt($(this).closest('.listas-produto-wrapper').find('.qty-input').val()) || 1;
                total += price * qty;
            });
            $('.listas-similar-check:checked').each(function() {
                const price = parseFloat($(this).data('price')) || 0;
                const qty = parseInt($(this).closest('.listas-similar-item').find('.similar-qty-input').val()) || 1;
                total += price * qty;
            });
            atualizarBrindes(total);
        });
    });
