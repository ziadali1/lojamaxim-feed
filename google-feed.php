<?php
/**
 * Converte o XML de produtos da Tray em feeds para o Google Merchant Center.
 *
 *   google-feed.php?tipo=local      inventário local (store_code, id, availability, price)
 *   google-feed.php?tipo=produtos   feed de produtos (cópia limpa da Tray + excluded_destination)
 *
 * Também roda pela linha de comando:  php google-feed.php local > local.xml
 */

// ---------------------------------------------------------------- Configuração

// No GitHub a URL vem do segredo TRAY_URL. Em hospedagem comum, cole a URL entre as aspas.
define('TRAY_URL', getenv('TRAY_URL') ?: '');
const STORE_CODE = 'LOJAMAXIM01';   // igual ao código da loja no Perfil da Empresa (diferencia maiúsculas)
const MOEDA      = 'BRL';
const CACHE_TTL  = 3600;            // segundos entre leituras da Tray
const MIN_ITENS  = 50;              // abaixo disso a leitura é tratada como falha e o último XML bom é usado

// Produtos que NÃO são vendidos na loja física. Ficam fora do inventário local e,
// no feed de produtos, recebem excluded_destination. Aceita g:id ou item_group_id.
const EXCLUIR_LOCAL_IDS = [
    // '5395',
    // '6283',      // exclui todas as variações do produto 6283
];
// Mesmo efeito, por categoria: início do g:product_type (ex.: 'Skate > Shape').
const EXCLUIR_LOCAL_CATEGORIAS = [
    // 'Skate > Shape',
];

// ---------------------------------------------------------------- Execução

const NS_G = 'http://base.google.com/ns/1.0';

$tipo = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : ($_GET['tipo'] ?? '');
if (!in_array($tipo, ['local', 'produtos'], true)) {
    falhar(400, 'Use ?tipo=local ou ?tipo=produtos');
}
if (TRAY_URL === '') {
    falhar(500, 'Defina a URL do XML da Tray (segredo TRAY_URL ou no topo do arquivo).');
}

$tray = carregarTray();
if ($tray === null) {
    falhar(503, 'Não foi possível ler o XML da Tray e não há cópia salva.');
}

$xml = $tipo === 'local' ? gerarLocal($tray) : gerarProdutos($tray);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=900');
}
echo $xml;

// ---------------------------------------------------------------- Leitura da Tray com cache

function carregarTray(): ?DOMDocument
{
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        $dir = sys_get_temp_dir();
    }
    $arquivo = $dir . '/tray.xml';

    if (is_file($arquivo) && time() - filemtime($arquivo) < CACHE_TTL) {
        $doc = lerXml(file_get_contents($arquivo));
        if ($doc !== null) {
            return $doc;
        }
    }

    $conteudo = baixar(TRAY_URL);
    $doc = $conteudo === null ? null : lerXml($conteudo);
    if ($doc !== null) {
        @file_put_contents($arquivo, $conteudo, LOCK_EX);
        return $doc;
    }

    // Tray fora do ar ou XML incompleto: melhor servir a última cópia boa do que um feed vazio,
    // que faria o Google remover os produtos.
    return is_file($arquivo) ? lerXml(file_get_contents($arquivo)) : null;
}

function baixar(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_USERAGENT      => 'LojaMaxim-GoogleFeed/1.0',
        ]);
        $corpo  = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($corpo !== false && $status === 200) {
            return $corpo;
        }
    }
    $ctx   = stream_context_create(['http' => ['timeout' => 90, 'user_agent' => 'LojaMaxim-GoogleFeed/1.0']]);
    $corpo = @file_get_contents($url, false, $ctx);
    return $corpo === false ? null : $corpo;
}

function lerXml(string $conteudo): ?DOMDocument
{
    $doc = new DOMDocument();
    $anterior = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($conteudo, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($anterior);
    if (!$ok || $doc->getElementsByTagName('item')->length < MIN_ITENS) {
        return null;
    }
    return $doc;
}

// ---------------------------------------------------------------- Feeds

function gerarLocal(DOMDocument $tray): string
{
    [$saida, $channel] = novoRss('Loja Maxim - inventário local');

    foreach ($tray->getElementsByTagName('item') as $item) {
        $id = valor($item, 'id');
        if ($id === '' || excluidoDaLoja($item)) {
            continue;
        }
        $novo = $channel->appendChild($saida->createElement('item'));
        adicionar($saida, $novo, 'store_code', STORE_CODE);
        adicionar($saida, $novo, 'id', $id);
        adicionar($saida, $novo, 'availability', disponibilidadeLocal(valor($item, 'availability')));
        adicionar($saida, $novo, 'price', preco(valor($item, 'price')));
        $promo = preco(valor($item, 'sale_price'));
        if ($promo !== '') {
            adicionar($saida, $novo, 'sale_price', $promo);
            adicionar($saida, $novo, 'sale_price_effective_date', valor($item, 'sale_price_effective_date'));
        }
    }
    return $saida->saveXML();
}

function gerarProdutos(DOMDocument $tray): string
{
    [$saida, $channel] = novoRss('Loja Maxim');

    foreach ($tray->getElementsByTagName('item') as $item) {
        if (valor($item, 'id') === '') {
            continue;
        }
        $novo = $channel->appendChild($saida->createElement('item'));
        foreach ($item->childNodes as $filho) {
            copiar($saida, $novo, $filho);
        }
        if (excluidoDaLoja($item)) {
            adicionar($saida, $novo, 'excluded_destination', 'free_local_listings');
            adicionar($saida, $novo, 'excluded_destination', 'local_inventory_ads');
        }
    }
    return $saida->saveXML();
}

/** Copia um elemento da Tray, descartando tags vazias e corrigindo o formato de preço e disponibilidade. */
function copiar(DOMDocument $saida, DOMElement $destino, DOMNode $origem): void
{
    if (!$origem instanceof DOMElement) {
        return;
    }
    $nome   = $origem->localName;
    $netos  = array_filter(iterator_to_array($origem->childNodes), fn($n) => $n instanceof DOMElement);

    if ($netos) {                                   // ex.: g:installment > g:months, g:amount
        $novo = $saida->createElementNS($origem->namespaceURI, $origem->nodeName);
        foreach ($netos as $neto) {
            copiar($saida, $novo, $neto);
        }
        if ($novo->hasChildNodes()) {
            $destino->appendChild($novo);
        }
        return;
    }

    $texto = trim($origem->textContent);
    if (in_array($nome, ['price', 'sale_price', 'amount'], true)) {
        $texto = preco($texto);
    } elseif ($nome === 'availability') {
        $texto = str_replace(' ', '_', strtolower($texto));
    }
    if ($texto === '') {
        return;
    }
    $novo = $saida->createElementNS($origem->namespaceURI, $origem->nodeName);
    $novo->appendChild($saida->createTextNode($texto));
    $destino->appendChild($novo);
}

// ---------------------------------------------------------------- Auxiliares

function novoRss(string $titulo): array
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;
    $rss = $doc->appendChild($doc->createElement('rss'));
    $rss->setAttribute('version', '2.0');
    $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:g', NS_G);
    $channel = $rss->appendChild($doc->createElement('channel'));
    $channel->appendChild($doc->createElement('title', $titulo));
    $channel->appendChild($doc->createElement('link', 'https://www.lojamaxim.com.br'));
    $channel->appendChild($doc->createElement('description', 'Gerado a partir do XML da Tray'));
    return [$doc, $channel];
}

function adicionar(DOMDocument $doc, DOMElement $item, string $nome, string $texto): void
{
    if ($texto === '') {
        return;
    }
    $el = $doc->createElementNS(NS_G, 'g:' . $nome);
    $el->appendChild($doc->createTextNode($texto));
    $item->appendChild($el);
}

/** Texto de um filho g:<nome> direto do item. */
function valor(DOMElement $item, string $nome): string
{
    foreach ($item->childNodes as $filho) {
        if ($filho instanceof DOMElement && $filho->localName === $nome && $filho->namespaceURI === NS_G) {
            return trim($filho->textContent);
        }
    }
    return '';
}

/** "R$ 1.299,9" -> "1299.90 BRL" */
function preco(string $texto): string
{
    $numero = preg_replace('/[^\d,.]/', '', $texto);
    if ($numero === '') {
        return '';
    }
    if (str_contains($numero, ',')) {
        $numero = str_replace(['.', ','], ['', '.'], $numero);
    }
    return number_format((float) $numero, 2, '.', '') . ' ' . MOEDA;
}

/** O inventário local só aceita in_stock, limited_availability, on_display_to_order e out_of_stock. */
function disponibilidadeLocal(string $texto): string
{
    return str_replace(' ', '_', strtolower($texto)) === 'in_stock' ? 'in_stock' : 'out_of_stock';
}

function excluidoDaLoja(DOMElement $item): bool
{
    if (array_intersect([valor($item, 'id'), valor($item, 'item_group_id')], EXCLUIR_LOCAL_IDS)) {
        return true;
    }
    $categoria = html_entity_decode(valor($item, 'product_type'));
    foreach (EXCLUIR_LOCAL_CATEGORIAS as $prefixo) {
        if (stripos($categoria, $prefixo) === 0) {
            return true;
        }
    }
    return false;
}

function falhar(int $status, string $mensagem): never
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $mensagem, PHP_EOL;
    exit(1);
}
