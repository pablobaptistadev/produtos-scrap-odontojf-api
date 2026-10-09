<?php
/**
 * Plugin Name: OdontoJF Woo Bridge
 * Description: Recebe produtos do Worker OdontoJF numa fila própria (api_queue) com timing/retry, cria/atualiza no WooCommerce com ATRIBUTOS MANUAIS (não globais) e serve imagens via R2 (fila de imagens, WebP, AWS SigV4). Dashboards de tempo de cadastro/update.
 * Version: 1.0.85
 * Author: OdontoJF
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 6.0
 *
 * CHANGELOG (mais recente primeiro):
 *  1.0.85 - Avise-me na pagina do produto diz "este produto" (o nome ja esta a
 *          vista, e o do ERP as vezes vem com acento quebrado); na variacao
 *          escolhida, o nome dela. Cache do LiteSpeed limpo a cada versao nova.
 *  1.0.83 - Pagina da origem sem produto proprio cujo codigo do ERP ja e de
 *          outra pagina: o push e recusado (409 sibling_same_code), em vez de
 *          sobrescrever o produto da outra pagina ou criar um com o preco errado.
 *  1.0.82 - SKU. Duas paginas da origem com o mesmo codigo do ERP (704, 7091,
 *          3418, 5537, 10110) eram tratadas como duplicata e se roubavam SKU e
 *          variacoes a cada push, ate ficarem sem SKU. Agora sao "irmaos": cada
 *          pagina tem o seu produto e o segundo leva <sku>-p<id> (o codigo real
 *          segue em _ojf_erp_code). sku-fix.php devolve SKU aos 17 sem SKU.
 *  1.0.81 - AVISE-ME NA PAGINA + CATEGORIAS v2. Avise-me: formulario (nome,
 *          e-mail, WhatsApp) na propria pagina do produto, logo depois do
 *          "fora de estoque e indisponivel"; "indisponivel" agora inclui item
 *          sem preco (o Woo esconde variacao sem preco), no grid tambem; quem
 *          espera e avisado quando o item fica compravel. Categorias v2: cria
 *          a unica categoria da origem que faltava (Agua para Injecao),
 *          corrige 43 produtos (os que so tinham categoria-lixo, os que a
 *          listagem da origem mostra em outra categoria), religa 13 itens de
 *          menu a categoria equivalente da origem e apaga o lixo que sobrou.
 *          _ojf_cat_listing: categoria em que a listagem da origem mostra o
 *          produto sem a pagina dele declarar; o push nao tira.
 *  1.0.80 - AVISE-ME + ESTOQUE DA ORIGEM. Disponibilidade espelha a origem: o
 *          push aplica stock_status sem quantidade (manage_stock=false), tambem
 *          nas variacoes (antes elas nem recebiam status); carga inicial em
 *          lote (data/stock-sync.json: 664 simples e 1.952 variacoes
 *          esgotadas). Avise-me: botao com sino no widget de compra (simples
 *          esgotado, ou a variacao escolhida esgotada) e no grid; popup novo
 *          (nome, e-mail, WhatsApp com mascara); lista de espera em
 *          {prefix}ojf_avise_me; quando o item volta ao estoque, e-mail
 *          "Chegou!" e marca como avisado. WooCommerce > Avise-me: lista, mais
 *          esperados, CSV e e-mail de teste. Orcamento tem prioridade sobre o
 *          Avise-me. jobs.php: lotes sem WP-Cron reutilizaveis.
 *  1.0.79 - 301 para 2 links do menu que ja estavam quebrados (Moldagem e Modelo,
 *          Cadeira Odontologica "-consultorio-odontologico-2").
 *  1.0.78 - Categoria Orcamento vai para o 1o nivel (era filha de Cadeira
 *          Odontologica e a bomba de vacuo aparecia entre as cadeiras).
 *  1.0.77 - Produto na categoria Orcamento: no grid (listagem JetEngine com o
 *          widget "Adicionar ao carrinho" do Elementor, e listas padrao do Woo)
 *          o botao vira "Solicitar orcamento" e leva para a pagina do produto;
 *          sem preco no grid.
 *  1.0.76 - Pagina do produto: Marca na PRIMEIRA linha da tabela de atributos,
 *          com rotulo "Marca" (era a ultima, escondida no bloco recolhido, e
 *          saia "marca"). Sem o atributo, usa o meta _odontojf_brand.
 *  1.0.75 - Limpeza em 2 etapas: backup em uploads/ojf-backup, arvore + produtos,
 *          PARA; apagar so com allow_delete no arquivo de dados. Protege as
 *          categorias do plugin Listas de Estudantes ("Listas estudantes",
 *          "Brindes" e toda lista criada depois) e "Lancamentos": nunca entram
 *          na fila de apagar e o push nao as tira do produto. Devolve 2 produtos
 *          que a etapa 1 tirou da categoria da lista. "Loja" vira "Principais".
 *  1.0.74 - CATEGORIAS = AS DA ORIGEM. O push so MARCA: cada categoria do
 *          payload e procurada pelo slug da origem dentro da arvore "Loja";
 *          nao achou = ignora; nunca cria (era o breadcrumb por nome que criava
 *          os "-2" e as arvores do ERP). needs_budget da origem -> Orcamento.
 *          Lote unico (data/category-fix.json): corrige pai/nome das categorias
 *          da origem, remarca os produtos, apaga o que sobrou vazio fora da
 *          arvore (religa item de menu ao equivalente; sem equivalente, fica).
 *          URL antiga "-2" -> 301 para a categoria certa.
 *  1.0.73 - Lote titulo+marca se encadeia sozinho (admin-ajax interno nao
 *          bloqueante, 15s entre lotes, uma corrente so). Carona em requisicao
 *          dava 2,5 produtos/min: quase tudo sai do cache sem tocar o PHP.
 *  1.0.72 - Lote titulo+marca pega carona em qualquer requisicao PHP (nao so
 *          REST): com o Worker parado eram ~3 lotes/hora. Continua 1 lote a
 *          cada ~55s, depois da resposta.
 *  1.0.71 - Lote titulo+marca nao dependia de nada que rodasse: a loja tem
 *          DISABLE_WP_CRON e ninguem chama o wp-cron.php. Agora pega carona no
 *          fim das requisicoes REST (como a fila da API), 1 lote a cada ~55s,
 *          depois que a resposta ja saiu.
 *  1.0.70 - Lote titulo+marca: erro num produto fica registrado no estado (e o
 *          lote pula o produto) em vez de travar em silencio; GET
 *          /odontojf/v1/title-brand-status mostra o andamento sem admin.
 *  1.0.69 - Marca no fim do titulo: "Nome do produto - MARCA". A busca do WP
 *          procura no titulo, nao no atributo, e "6B" nao achava a 6B INVENT.
 *          Regra no momento de gravar (push do Worker) e num lote em WP-Cron
 *          (~25/min) para os produtos existentes. Nao repete a marca se o nome
 *          ja a cita (inteira, ou a 1a palavra com 3+ letras/digito); ignora
 *          marca com caractere quebrado do ERP; slug nao muda. Nome da origem
 *          fica em _ojf_title_base: desligar em Configuracoes devolve o
 *          original. Titulo proprio da variacao ganha a mesma marca.
 *  1.0.68 - PILAR C so apaga variacao com payload COMPLETO. Na mesma rajada de
 *          01/10 a origem tambem bloqueou a consulta de dados das variacoes; o
 *          Worker mandou variacoes sem codigo, o sync pulou cada uma e o PILAR C
 *          leu "nao veio" como "sumiu da origem": 24 produtos ficaram sem as
 *          variacoes (14 de 14 num caso), sem opcao de compra. Agora, se alguma
 *          variacao do payload vem sem codigo (ou nenhuma tem), nada e apagado.
 *  1.0.67 - NUNCA grava nome de pagina de erro. Em 01/10 a origem devolveu 403 ao
 *          Worker e o scraper aceitou a pagina de bloqueio como produto: 169
 *          produtos na loja ficaram "403: Forbidden" ou "Pagina nao encontrada".
 *          ojf_reject_error_page_payload() recusa com 422 qualquer payload cujo
 *          nome (do pai ou de variacao) pareca titulo de erro HTTP ou esteja
 *          vazio — no handler, que e por onde passam os jobs ja enfileirados, e
 *          no interceptor, antes de enfileirar. Ultima barreira: vale mesmo que o
 *          Worker regrida. Testado contra os 3.666 nomes do catalogo: zero falso
 *          positivo.
 *  1.0.66 - ERP trocou de endereco: 45.227.82.180 parou de responder (ETIMEDOUT)
 *          e o fornecedor passou a atender por cc210ff240a8.sn.mynetname.net.
 *          Porta 8082, caminho e protocolo iguais. Alem do novo padrao, migra a
 *          option ojf_erp_base_url de quem ja tinha o IP salvo nos ajustes — sem
 *          isso o padrao novo nao valeria nada.
 *  1.0.65 - A adocao ainda morria em "SKU invalido ou duplicado". O 1.0.61 solta o
 *          codigo procurando o dono pelo postmeta _sku — so que a PRIMEIRA
 *          tentativa (1.0.60) ja tinha zerado esse postmeta sem conseguir mexer no
 *          lookup. Resultado: #791839 e #770435 com postmeta vazio e
 *          wc_product_meta_lookup ainda ocupado, e o Woo recusando o canonico por
 *          um dono que nao existe mais. ojf_release_sku_from_lookup() agora limpa
 *          o lookup por ele mesmo, sem depender do postmeta.
 *  1.0.64 - FIX do 1.0.63: quem escolhe o CANONICO e o slug, nao o id de origem.
 *          O _odontojf_scrape_id marca onde escrevemos por ultimo e, num par ja
 *          duplicado, quem recebeu os pushes foi o GEMEO — usar o id para escolher
 *          adotaria o gemeo e cimentaria a URL com -2 de vez. O id continua sendo
 *          ancora, mas so quando o slug nao acha ninguem (origem renomeou, ou o
 *          WordPress sufixou o nosso post_name). Tudo que sobra e gemeo a absorver;
 *          mais de um gemeo distinto vira 409.
 *  1.0.63 - A identidade do produto passa a ser o ID IMUTAVEL da origem
 *          (`id: "KDznXluDKlRQwI2mdOvo"`), que o scrape ja gravava em
 *          `_odontojf_scrape_id` e ninguem usava. O slug era a melhor ancora que
 *          tinhamos, mas a origem pode reescreve-lo e o WordPress sufixa com -2
 *          quando o post_name esta ocupado — nos dois casos o produto viraria
 *          duplicata. O id nao muda. Ordem agora: id de origem -> slug -> dono das
 *          variacoes -> criar. Vale tambem no interceptor do /update-product.
 *  1.0.62 - O interceptor do /update-product recusava com 404 quando nenhum produto
 *          tinha aquele _sku — e era exatamente o caso do re-chaveamento, porque o
 *          codigo sintetico do pai muda quando a origem reordena. O handler de
 *          update e um UPSERT e sabe achar o produto pelo slug; agora o
 *          interceptor tenta o slug antes de desistir e so devolve 404 quando o
 *          payload tambem nao da para criar.
 *  1.0.61 - FIX da adocao: soltar o SKU do gemeo com update_post_meta nao bastava.
 *          O Woo valida "SKU invalido ou duplicado" contra a tabela
 *          wc_product_meta_lookup, que so o save() do produto atualiza — o
 *          postmeta ficava vazio, o lookup continuava com o codigo e o save do
 *          canonico morria. Agora solta pelo CRUD e ainda zera o lookup na mao.
 *          Era isso que travava #773421 (dente-pop) e #770465 (ponta diamantada).
 *  1.0.60 - "Mostrar o preco" do widget passa a vir LIGADO. Nasceu desligado e o
 *          produto simples continuou sem preco nenhum na pagina — nao ha bloco de
 *          variacao ali para o Woo preencher. Com ele ligado, o preco do bloco de
 *          variacao e escondido junto, entao continua saindo UM preco so.
 *  1.0.59 - Troca \$.trim() por String().trim() no product-page: o jQuery 4 removeu
 *          \$.trim, e uma excecao ali derrubava o handler inteiro do
 *          show_variation (preco, SKU, "Ler mais" e URL de uma vez so). O
 *          WordPress ainda manda o jQuery 3, entao e prevencao — mas foi
 *          exatamente esse o modo de falha simulado no jsdom.
 *  1.0.58 - Widget Carrinho OdontoJF ganha "Mostrar o preço". Produto SIMPLES nao
 *          tem bloco de variacao, entao nao havia nada imprimindo preco na pagina
 *          — o widget agora pode imprimir. Em variavel o elemento sai com
 *          data-ojf-field="price" e o script da pagina troca o valor a cada
 *          selecao. Secao de estilo propria (tipografia, cor, alinhamento,
 *          espaco).
 *  1.0.57 - FIX preco sumido na variacao: o Woo so manda price_html por variacao
 *          quando o menor e o maior preco do produto DIFEREM, e compara ja
 *          arredondado pelas casas decimais da loja. Com a loja em 1 casa, 4,97 e
 *          4,98 viram o mesmo numero, o Woo conclui "preco unico" e manda
 *          price_html vazio — a linha de preco ficava em branco (ABRIDOR DE BOCA
 *          ABRITEC, #788865). O [preco_info] agora monta o valor a partir do
 *          display_price, no formato da loja, quando o price_html vem vazio.
 *          Botao adicionar ao carrinho: zera o margin-left que o tema aplicava
 *          (.cart .single_add_to_cart_button). Ele so fazia sentido quando o campo
 *          de quantidade era o do tema; com o nosso, o espacamento ja vem do gap
 *          do flex. Nenhuma outra regra do botao mudou.
 *  1.0.56 - IDENTIDADE DO PRODUTO PASSA A SER O SLUG DA ORIGEM. O _sku do pai
 *          variavel e sintetico (OD-<codigo da 1a variacao>) e a origem reordena e
 *          remove tamanhos, entao esse codigo muda sozinho. Quando mudava, o create
 *          nao achava o pai, o Woo criava um SEGUNDO produto e o save de cada
 *          variacao reescrevia o post_parent dela — o original ficava publicado e
 *          VAZIO (#773421 OD-19722 -> #791839 OD-20591, 12 variacoes).
 *          ojf_resolve_target_product() agora casa por slug (post_name, publicado,
 *          _seller=odontojf) e, sem slug, pelo dono atual das variacoes; achando,
 *          ADOTA e re-chaveia o _sku no lugar. Quando slug e sku apontam para
 *          produtos DIFERENTES (duplicata ja existente), o do slug vence — o sync
 *          puxa as variacoes de volta e o gemeo e despublicado (rascunho,
 *          _ojf_duplicate_of). Nunca apaga: wp_delete_post levaria os anexos e os
 *          objetos no R2 junto. Recusa com 409 quando absorver seria destrutivo:
 *          variacoes espalhadas por mais de um pai, gemeo que nao e nosso, gemeo
 *          com variacoes fora do payload, ou sobreposicao < 50% com o candidato.
 *          E o codigo de variacao deixa de ser roubado entre produtos DIFERENTES:
 *          a origem reaproveita o mesmo codigo em kits/promos (resina-filtek-z350-xt
 *          e ...-4g-promo-sof-lex dividem 10), e como o Woo exige _sku unico os dois
 *          se revezavam a cada push. Agora ojf_sync_variations() casa primeiro pela
 *          variacao do PROPRIO pai (via _ojf_erp_code, sobrevive ao sufixo) e, se o
 *          codigo estiver preso em algo vivo de outro produto, usa _sku sufixado
 *          (<codigo>-p<pai>) em vez de tomar. O codigo real segue em _ojf_erp_code,
 *          que e o que o carrinho e os shortcodes leem. ojf_free_orphan_sku()
 *          substitui ojf_free_sku_global() nesse caminho: solta so orfao, nunca
 *          mexe em variacao de pai publicado nem apaga produto publicado.
 *  1.0.55 - FIX: a pagina tem DUAS form.variations_form (a do tema e a do nosso
 *          widget) e o script prendia em .first(), a errada — por isso SKU,
 *          "Ler mais" e URL nao reagiam. Eventos agora sao delegados e valem
 *          para qualquer form da pagina.
 *  1.0.54 - Pre-selecao de variacao ao abrir a pagina: a primeira em estoque,
 *          preferindo uma em oferta. A variacao que vier na URL sempre vence.
 *  1.0.53 - Sucesso do carrinho: "Carrinho atualizado", UM icone so (o .added
 *          do tema trazia um segundo) e volta ao padrao em 5s. Gap 0 entre
 *          quantidade e botao, com controle. Troca de SKU passa a achar
 *          tambem o codigo do ERP, nao so o _sku.
 *  1.0.52 - FIX CRITICO: adicionar ao carrinho levava ~21s com o ERP fora do
 *          ar (duas consultas pagando o timeout inteiro). Disjuntor: apos uma
 *          falha o ERP fica marcado como fora por 120s e as consultas voltam
 *          na hora, usando o preco do catalogo. Timeout de 12s para 6s.
 *  1.0.51 - FIX: botao ficava preso em "Atualizando carrinho...". Um listener
 *          de terceiro estourando em added_to_cart abortava o callback antes
 *          de restaurar o botao. Estado de sucesso agora vem antes, o trigger
 *          vai em try/catch e ha timeout + watchdog. Tambem: quantidade ao
 *          lado do botao (20/80 configuravel) e botao 100%% quando o estoque e 1.
 *  1.0.50 - Campo de quantidade proprio com - e +, alinhado ao botao, com
 *          controles no widget. O input nativo do Woo continua no form, so
 *          envolvido: min/max/step, estoque e venda individual seguem valendo.
 *  1.0.49 - Estilo do seletor de variacao (swatches do CommerceKit) no widget:
 *          tipografia, espacamentos, raio e cores nos tres estados, mais a
 *          chave que solta o tamanho fixo que cortava rotulos como N°18R.
 *  1.0.48 - Widget do carrinho ganhou controles de estilo do botao (largura,
 *          alinhamento, tipografia, cores normal/hover, borda, sombra, raio,
 *          padding) e o icone de carrinho do cliente, com posicao e tamanho.
 *  1.0.47 - Widget Elementor "Campo OdontoJF": titulo do produto, titulo da
 *          variacao, preco, SKU, peso e dimensoes dentro de loop de produtos.
 *          Um Dynamic Field apontado para _odontojf_variation_title volta
 *          vazio no loop porque o post e o PAI e o meta esta na variacao.
 *  1.0.46 - Widget Elementor "Carrinho OdontoJF": renderiza o proprio template
 *          do Woo (botao e seletor identicos ao tema), esconde o preco que o
 *          bloco de variacao repetia e adiciona ao carrinho por AJAX com
 *          estado "Atualizando carrinho..." no botao.
 *  1.0.45 - Texto do botao "Ler mais" centralizado (o tema estica o botao).
 *  1.0.44 - URL e titulo do documento acompanham a variacao selecionada
 *          (history.replaceState), para link copiado abrir no item certo.
 *  1.0.43 - Shortcode [titulo_info]: titulo do produto simples, ou o titulo
 *          proprio da variacao selecionada (_odontojf_variation_title).
 *  1.0.42 - Shortcode [preco_info]: preco do produto simples, ou o da variacao
 *          selecionada em produto variavel. Aceita placeholder= para nao
 *          mostrar a faixa do pai antes da escolha.
 *  1.0.41 - "Ler mais" na descricao da variacao: colapsa em 220px (filtro
 *          ojf_pp_description_max_height) com degrade na cor real do fundo e
 *          expande com transicao, para o botao de compra nao ficar abaixo de
 *          2.000 caracteres de texto.
 *  1.0.40 - Modulo product-page: titulo, SKU, preco, peso e dimensoes passam a
 *          seguir a variacao selecionada, com transicao. Incorpora o snippet
 *          [producto_info] (mesmo shortcode e mesmos atributos) ao plugin.
 *  1.0.39 - PDP da variacao: titulo proprio no topo do bloco (e no H1), SKU do
 *          pai trocado pelo da variacao selecionada, e preco reordenado para
 *          ACIMA da descricao (flex order, sem sobrescrever template).
 *  1.0.38 - Faltava o hook que importa: WC_Product_Variation::get_title()
 *          devolve parent_data['title'] direto, sem passar por get_name(), e e
 *          por ele que a Store API e os templates leem o nome. Filtro
 *          woocommerce_product_title adicionado.
 *  1.0.37 - Titulo proprio da variacao passa a valer de fato. set_name() nao
 *          gruda: o data store do Woo regenera o post_title no save. Agora os
 *          filtros woocommerce_product_variation_title (geracao) e
 *          ..._get_name (leitura) devolvem _odontojf_variation_title.
 *  1.0.36 - Variacoes fieis a origem: aceita name/description por variacao
 *          (set_name/set_description) e variations[].images[] (galeria). A 1a
 *          imagem vira a thumbnail nativa e as demais vao para o meta
 *          _odontojf_variation_gallery e espelhado em commercekit_image_gallery
 *          (o CommerceKit e quem desenha a galeria da PDP; a Attributes Gallery
 *          dele ja funciona com nosso atributo MANUAL). Editavel na aba
 *          Variacoes. ojf_collect_product_attachment_ids()
 *          passou a enxergar esses anexos (PILAR B) — sem isso a varredura de
 *          orfaos apagaria a galeria e os objetos no R2 no update seguinte.
 *  1.0.35 - Endpoint /update-price (SINCRONO, fora da api-queue): atualiza SO
 *          preco/estoque (regular_price/sale_price [+ stock_quantity]) do produto
 *          e/ou de cada variacao (casada por _sku ou _ojf_erp_code). NAO mexe em
 *          nome, categoria, imagem nem apaga variacao. Update de oferta em ~ms.
 *  1.0.34 - /sync-categories modo root_parent: poe TODAS as raizes sob uma
 *          categoria mae (ex: Loja id 91656). Rodar so no final.
 *  1.0.33 - /sync-categories: grava SLUG EXATO da origem (URL igual ao menu) +
 *          flag wipe (apaga categorias antes de recriar 100% igual ao menu).
 *  1.0.32 - /sync-categories aceita modo PATHS (caminhos breadcrumb) alem de nodes.
 *          Cria a arvore hierarquica direto dos caminhos reais dos produtos.
 *  1.0.31 - Endpoint /sync-categories: cria a ARVORE inteira de categorias de uma
 *          vez (pai->filho, ondas), ANTES dos produtos. Sincrono. Idempotente.
 *  1.0.30 - FIX HIERARQUIA: revertido o slug-global da 1.0.28 (que achatava a
 *          arvore). Volta a buscar o nome DENTRO do pai do breadcrumb -> arvore
 *          correta (Equipamentos > Compressor de Ar), reusa no mesmo pai, sem
 *          duplicar. Mesmo nome em ramos diferentes = categorias distintas.
 *  1.0.29 - Orcamento NAO e mais adicionado em TODO produto (revertido o 1.0.24).
 *          Categoria vem so do breadcrumb que o worker manda; orcamento agora e
 *          so quem vier na categoria certa (OLSEN/Cadeira Odontologica).
 *  1.0.28 - Categorias ANTI-DUPLICATA: ojf_get_or_create_category_id agora checa
 *          pela SLUG (global) e REUSA o term existente; nunca cria nome/slug
 *          duplicado (nada de 'compressor-de-ar-2'). Se existe, so associa.
 *  1.0.27 - Metabox 'Campos do produto (OdontoJF)' no editor: surfa os metas
 *          customizados _odontojf_* (sku/marca/REF/EAN/peso/dimensoes/parcelas/
 *          videos/pdf/origem) + ERP (_ojf_erp_code/_ojf_last_erp_price/
 *          _ojf_price_history) que o WP esconde por serem '_'-prefixados.
 *          Tabela curada + dump cru de todos. Somente leitura (geridos pelo sync).
 *  1.0.26 - MIDIA R2 na biblioteca do WP (cinza puro): (1) coluna R2 + filtro
 *          'Off R2' + acao 'Migrar pro R2' (individual e em massa) na lista de
 *          midia; (2) pagina 'Midia R2' com grid paginado + migrar selecionados;
 *          (3) pagina 'Upload R2 -> /assets/' com largura max. POR upload;
 *          (4) auto-upload opcional de TODO upload do WP pro R2 (toggle no admin
 *          + apagar-local configuravel). Roteamento: anexo de produto ->
 *          products/{id}/, upload solto -> assets/. Reusa 100% ojf_r2_upload/
 *          ojf_cdn_build_object_key/metas _ojf_* (mesmas chaves R2 dos produtos).
 *  1.0.25 - Imagens do corpo: limpeza no UPDATE (apaga do R2 as que sairam da
 *          description). Com a 1.0.24 (limpeza no delete) = ZERO lixo no R2 em
 *          qualquer cenario (delete OU description encurtada/trocada).
 *  1.0.24 - (1) Categoria 'Orcamento' (top-level) em TODO produto no create/update.
 *          (2) Limpeza das imagens do CORPO no R2 ao deletar o produto (vao direto
 *          pro R2 sem virar attachment; agora apaga via meta _ojf_body_img_*).
 *  1.0.23 - Fix definitivo 'SKU duplicado' nos variaveis: libera o SKU de QUALQUER
 *          orfao (draft/variacao/lixo de tentativa anterior) antes do pai E da
 *          variacao reivindicar. ojf_free_sku_global apaga produto-orfao e
 *          re-sufixa variacao-orfa.
 *  1.0.22 - Video responsivo WIDESCREEN 16:9 (aspect-ratio no iframe com !important).
 *          Antes ficava quadrado quando o tema/Elementor forcava height no iframe.
 *  1.0.21 - Ajustes de texto: metabox 'Videos do produto' (sem emoji/prefixo) e
 *          texto do log do carrinho ('mostramos:'). Inclui tudo da 1.0.20.
 *  1.0.20 - Categorias HIERARQUICAS: o breadcrumco [raiz..folha] vira pai/filho
 *          (Dentistica e Estetica -> Adesivo), nao flat. Busca por nome NO MESMO
 *          pai (evita confundir nomes iguais em ramos diferentes).
 *  1.0.19 - Video REPEATER no metabox: campo URL + embed (assiste ali mesmo),
 *          adicionar/remover varios videos (um abaixo do outro), salvar via AJAX.
 *          Shortcode [ojf_video] renderiza TODOS. Array em _odontojf_video_urls.
 *  1.0.18 - Metabox do video no editor do produto (ver/editar a URL + preview da
 *          thumb). O campo _odontojf_video_url eh protegido (prefixo _) e ficava
 *          escondido da caixa 'Campos personalizados'.
 *  1.0.17 - Card Economizado completo: valor INTEGRAL (antes), % economizado,
 *          GB economizados e quanto esta no R2 (MB otimizado).
 *  1.0.16 - Video do produto via SHORTCODE [ojf_video] (le o custom field
 *          _odontojf_video_url e renderiza embed YouTube limpo no render -
 *          driblando o kses do Woo que removeria iframe da description).
 *  1.0.15 - Dashboards (API + Image Queue): (1) botao 'Ver todo historico' (carrega
 *          ate 5000, nao so hoje); (2) filtro por DATA agora busca no backend;
 *          (3) botoes 100/500/1500 re-buscam (antes travavam em 10); (4) Economizado
 *          robusto + formatado (KB/MB/GB) no PHP; (5) nomes em LOTE (perf, sem N+1).
 *  1.0.14 - Imagens do CORPO (post_content) vao pro R2 da loja: baixa cada <img>
 *          externa (media.odontoapi etc.), otimiza WebP, sobe pro R2 e reescreve
 *          o src. Resolve imagens quebradas do corpo (hotlink/erro 1011). Dedup
 *          por meta. Roda no create/update sempre que vier 'description'.
 *  1.0.13 - (1) Fix VARIAVEIS: find-or-create so acha o PAI (ignora variacoes) +
 *          libera SKU sequestrado por variacao orfa -> resolve 'SKU invalido ou
 *          duplicado'. (2) Dedup de imagem: reusa attachment do mesmo produto+URL
 *          (para o bloat da biblioteca/R2 no re-push). (3) Image Queue dashboard:
 *          'Concluido (total)' + 'Economizado' agora TOTAL (nao so do dia).
 *  1.0.12 - Anti-cascata: worker (API + imagens) encadeia 1 sucessor por vez em vez
 *          de explodir 'concurrency' a cada ciclo. Para de afogar o MariaDB/CPU em
 *          servidores fracos. Processa gentil (~concurrency por vez via lock).
 *  1.0.11 - Fix CRITICO de throughput: worker disparado de forma NAO-BLOQUEANTE
 *          (loopback) em vez do shutdown bloqueante. Resolve timeout do Worker
 *          ('operation was aborted') em servidores sem fastcgi_finish_request.
 *  1.0.10 - Fix dashboards (API Queue + Image Queue): a tabela nao listava porque
 *          fazia JOIN na tabela productos_cache (do fullbai) que nao existe aqui.
 *          Removido o JOIN; nome/imagem vem do WooCommerce.
 *  1.0.9 - Log do carrinho mostra Preco Woo (catalogo) x Preco ERP (ao vivo) com
 *          delta - deixa claro o valor que era e o novo.
 *  1.0.8 - Log no produto: consulta ERP no carrinho (preco/estoque/aprovado) +
 *          historico de mudancas de preco (JSON em custom field) + metabox na
 *          pagina de edicao do produto.
 *  1.0.7 - Anti-sujeira R2: (A) excluir produto/variacao apaga as imagens (qualquer
 *          caminho, via before_delete_post); (B) update deleta anexos que sairam da
 *          galeria; (C) update remove variacoes stale + imagens. Tudo limpa o R2.
 *  1.0.6 - SKU do produto = codigo ERP (inclusive pai variavel). Colisao pai/variacao
 *          resolvida com _ojf_erp_code em meta. Fix: ERP unwrap produtos[0] (preco/estoque).
 *  1.0.5 - Produtos sempre PUBLICADOS. Feature: preco/estoque AO VIVO do ERP no
 *          carrinho (token renovavel via login). Campos ERP na pagina de config.
 *  1.0.4 - Object key das imagens volta a products/{id}/{slug}-{attach}.webp (sem /imagens/).
 *  1.0.3 - Página ÚNICA de Configurações no admin (Bearer + R2 account/access/secret/
 *          bucket + CDN). Tudo em options, sem wp-config.
 *  1.0.2 - CDN passa a ser o domínio custom https://arquivos.dentalodontocirurgica.com.br
 *          (Worker proxy → bucket odonto-loja, cross-account).
 *  1.0.1 - R2 bucket = odonto-loja; CDN público r2.dev; object key passa a ser
 *          products/{id}/imagens/{slug}-{attachment}.webp.
 *  1.0.0 - Versão inicial. Endpoints odontojf/v1 (create/update/delete/queue-status/
 *          queue-retry-failed), api_queue com duration_ms + retry + CAS, image_queue
 *          (download -> WebP -> R2 conta nova via SigV4 -> attachment leve), atributos
 *          manuais (WC_Product_Attribute set_id 0), upsert por _sku = código ERP,
 *          2 dashboards no admin.
 */

if (!defined('ABSPATH')) exit;

define('OJF_BRIDGE_VERSION', '1.0.85');
define('OJF_BRIDGE_FILE', __FILE__);
define('OJF_BRIDGE_DIR', plugin_dir_path(__FILE__));

// Ordem importa: config + auth primeiro; depois os arquivos VALIDADOS portados
// (api-queue, image-handler) que se auto-configuram (constantes + tabelas +
// crons + dashboards próprios). product-handler provê os handlers + rotas.
require_once OJF_BRIDGE_DIR . 'includes/config.php';
require_once OJF_BRIDGE_DIR . 'includes/auth.php';
require_once OJF_BRIDGE_DIR . 'includes/erp-client.php';      // cliente ERP (login + token renovável)
require_once OJF_BRIDGE_DIR . 'includes/product-log.php';     // log ERP + histórico de preços (custom field + metabox)
require_once OJF_BRIDGE_DIR . 'includes/image-handler.php';   // fila de imagens + R2 (verbatim)
require_once OJF_BRIDGE_DIR . 'includes/product-handler.php'; // handlers + rotas (atributo manual, _sku=ERP)
require_once OJF_BRIDGE_DIR . 'includes/categories.php';      // categorias = as da origem, por slug; nunca cria + limpeza
require_once OJF_BRIDGE_DIR . 'includes/jobs.php';            // lotes em segundo plano sem WP-Cron (corrente admin-ajax)
require_once OJF_BRIDGE_DIR . 'includes/budget.php';          // sob orçamento: "Solicitar orçamento" no grid
require_once OJF_BRIDGE_DIR . 'includes/avise-me.php';        // Avise-me: botão sem estoque, lista de espera, e-mail
require_once OJF_BRIDGE_DIR . 'includes/stock-sync.php';      // disponibilidade = a da origem (carga inicial)
require_once OJF_BRIDGE_DIR . 'includes/sku-fix.php';       // devolve SKU a quem ficou sem (pares de páginas com o mesmo código)
require_once OJF_BRIDGE_DIR . 'includes/title-brand.php';     // "Nome - MARCA" no título (push + lote dos existentes)
require_once OJF_BRIDGE_DIR . 'includes/cart-erp.php';        // preço/estoque ao vivo no carrinho
require_once OJF_BRIDGE_DIR . 'includes/video.php';          // shortcode [ojf_video] (vídeo via custom field)
require_once OJF_BRIDGE_DIR . 'includes/api-queue.php';       // interceptor + fila API + worker + dashboard (verbatim)
require_once OJF_BRIDGE_DIR . 'includes/image-dashboard.php'; // dashboard da fila de imagens (verbatim)
require_once OJF_BRIDGE_DIR . 'includes/media-r2.php';        // mídia R2: auto-upload + migrar (núcleo, religado ao ojf_r2_*)
require_once OJF_BRIDGE_DIR . 'includes/variation-gallery.php'; // galeria por variação (front + admin) — _odontojf_variation_gallery
require_once OJF_BRIDGE_DIR . 'includes/product-page.php';     // PDP: título/SKU/preço/peso/dimensões seguem a variação + [producto_info]
require_once OJF_BRIDGE_DIR . 'includes/elementor.php';        // widget Elementor "Carrinho OdontoJF" + add-to-cart AJAX
if (is_admin()) {
    require_once OJF_BRIDGE_DIR . 'includes/settings.php';    // página ÚNICA de configurações (todas as chaves)
    require_once OJF_BRIDGE_DIR . 'includes/media-r2-admin.php'; // biblioteca: coluna/filtro/grid + upload /assets/ (cinza)
    require_once OJF_BRIDGE_DIR . 'includes/product-fields-box.php'; // metabox: campos customizados (_odontojf_*/_ojf_*) no editor
}

/**
 * O ERP trocou de endereço (08/09): o IP 45.227.82.180 parou de responder e o
 * fornecedor passou a atender pelo nome. Mudar só o padrão do plugin não
 * bastaria — quem já salvou a URL nos ajustes tem o IP gravado na option, e é
 * ela que vale. Roda a cada carga, custa uma leitura de option autoloaded, e é
 * inofensiva depois da primeira vez.
 */
add_action('plugins_loaded', function () {
    $antigo = '45.227.82.180';
    $novo   = 'cc210ff240a8.sn.mynetname.net';
    $url    = (string) get_option('ojf_erp_base_url', '');
    if ($url !== '' && strpos($url, $antigo) !== false) {
        update_option('ojf_erp_base_url', str_replace($antigo, $novo, $url), true);
        error_log('[ojf] ERP_BASE_URL migrada do IP antigo para ' . $novo);
    }
}, 5);

/**
 * Activation: cria as tabelas (funções definidas nos arquivos portados) e
 * semeia o segredo a partir do fallback de wp-config, se houver.
 */
register_activation_hook(__FILE__, function () {
    if (function_exists('ojf_aq_create_table')) ojf_aq_create_table();
    if (function_exists('ojf_iq_create_table')) ojf_iq_create_table();
    if (!get_option('ojf_api_secret') && defined('OJF_API_SECRET_FALLBACK') && OJF_API_SECRET_FALLBACK !== '') {
        update_option('ojf_api_secret', OJF_API_SECRET_FALLBACK, false);
    }
    update_option('ojf_bridge_version', OJF_BRIDGE_VERSION, true);
});

// Versão nova no ar: o HTML em cache do LiteSpeed ainda é o da anterior (botões,
// formulários, textos). Limpa uma vez por versão.
add_action('init', function () {
    if (get_option('ojf_bridge_cache_purged') === OJF_BRIDGE_VERSION) return;
    update_option('ojf_bridge_cache_purged', OJF_BRIDGE_VERSION, true);
    add_action('shutdown', function () { do_action('litespeed_purge_all'); }, 5);
}, 50);
