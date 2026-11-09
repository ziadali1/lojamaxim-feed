# Feed do Google a partir do XML da Tray

Um único arquivo, `google-feed.php`, gera dois feeds:

| Arquivo | Para quê |
|---|---|
| `local.xml` | Inventário local: `store_code`, `id`, `availability` e `price` de cada item |
| `produtos.xml` | Feed de produtos igual ao da Tray, com preços no formato `89.90 BRL`, sem tags vazias e com `excluded_destination` nos itens marcados |

## Opção 1 — GitHub (grátis, recomendado)
O GitHub Actions roda o PHP de hora em hora e publica os XMLs no GitHub Pages.
Não precisa de servidor.

1. Crie um repositório **público** (o Pages gratuito exige) e envie esta pasta inteira,
   incluindo `.github/workflows/atualizar-feed.yml`.
2. **Settings → Secrets and variables → Actions → New repository secret**
   - Name: `TRAY_URL`
   - Secret: a URL do XML da Tray (com a `Chave`).
   Assim a chave da Tray não fica visível no repositório.
3. **Settings → Pages → Build and deployment → Source: GitHub Actions**.
4. **Actions → Atualizar feed do Google → Run workflow** para gerar a primeira vez.
5. Os feeds ficam em:
   - `https://SEU-USUARIO.github.io/NOME-DO-REPO/local.xml`
   - `https://SEU-USUARIO.github.io/NOME-DO-REPO/produtos.xml`

Se a Tray falhar numa execução, nada é publicado e os XMLs anteriores continuam no ar.
O GitHub manda um e-mail quando uma execução falha.

**Atenção:** o GitHub desativa tarefas agendadas de repositórios públicos sem nenhuma
atividade por 60 dias. Ele avisa por e-mail antes; para reativar, basta abrir
**Actions → Atualizar feed do Google → Enable workflow** (ou fazer qualquer commit).

## Opção 2 — Hospedagem com PHP
Serve qualquer plano com **PHP 8.1 ou mais novo**.

1. No topo do `google-feed.php`, troque `getenv('TRAY_URL') ?: ''` por
   `getenv('TRAY_URL') ?: 'URL-DA-TRAY'`.
2. Envie o arquivo para `public_html/feed/`.
3. Os feeds ficam em `https://SEU-DOMINIO/feed/google-feed.php?tipo=local` e `?tipo=produtos`.

Nessa opção o arquivo lê a Tray no máximo uma vez por hora e guarda uma cópia em `cache/`.
Se a Tray falhar, ele usa a última cópia boa.

## Produtos que não são vendidos na loja física
No topo do arquivo, preencha `EXCLUIR_LOCAL_IDS` (ID do item ou do produto pai) ou
`EXCLUIR_LOCAL_CATEGORIAS` (início do `product_type`, ex.: `'Skate > Shape'`). Esses itens
saem do inventário local e recebem `excluded_destination` no feed de produtos.
A exclusão só vale no Google se a fonte "PRODUCTS SOURCE 2" passar a usar `produtos.xml`.

## Depois de publicar
Com o endereço funcionando, a fonte de inventário local pode ser criada no Merchant Center
apontando para `local.xml`, com busca diária.
