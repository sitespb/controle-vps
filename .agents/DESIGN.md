# DESIGN.md — SPB Finanças Lite

> Design system **extraído do código existente**, não proposto do zero. Cada padrão abaixo foi apurado por varredura das 31 views (`app/views/`), e a contagem de ocorrências indica qual variante é canônica quando há divergência.
>
> **Regra de ouro:** ao criar um elemento novo, copie a classe canônica desta página. Não invente variante — se precisar de uma, adicione-a aqui primeiro.

**Última atualização:** 14/08/2026 · Complementa [PROGRESS.md](PROGRESS.md)

---

## 1. Stack de UI

| Item | Valor |
|---|---|
| CSS | **Tailwind CSS compilado localmente** → `public/assets/css/app.css` (`npm run build:css`) |
| Interatividade | **Alpine.js 3.x** (CDN) + JS vanilla |
| Ícones | **SVG inline**, estilo Heroicons outline — sem biblioteca |
| Gráficos | **Chart.js** (único) |
| QR Code | `qrcode-generator@1.4.4` (usado na conexão do WhatsApp) |
| Fonte | `font-sans` — stack padrão do Tailwind, sem webfont |

Tokens da marca em `tailwind.config.js`:

```js
// tailwind.config.js
module.exports = {
    content: ['./app/views/**/*.php', './app/controllers/**/*.php', './public/**/*.php'],
    theme: { extend: { colors: {
        primary:   '#c8102e',   // vermelho SPB
        secondary: '#000000',
        bglight:   '#f9fafb',
    }}},
};
```

### Build do CSS

| Comando | O que faz |
|---|---|
| `npm run build:css` | Compila e minifica → `public/assets/css/app.css` |
| `npm run watch:css` | Recompila a cada alteração durante o desenvolvimento |

**Rode `npm run build:css` sempre que adicionar uma classe nova.** Sem isso a classe não existe no CSS compilado e o estilo simplesmente não aparece — foi o preço de sair do CDN. O `<link>` no layout tem cache-busting por `filemtime`, então o navegador pega a versão nova sozinho.

O purge é seguro porque **todas as classes do projeto são literais** — não há concatenação do tipo `'bg-' + cor`. Se um dia precisar de uma classe montada dinamicamente, adicione-a ao `safelist` do `tailwind.config.js`.

### Como o CSS é servido

O `<link>` aponta para `/financas-lite/public/assets/css/app.css`, o mesmo prefixo absoluto que todos os links da aplicação usam. Isso funciona direto em `localhost/financas-lite/public/`, mas **no vhost `financas-lite.test` o DocumentRoot é a raiz do projeto** e esse prefixo não existe no disco — a requisição cairia no front controller e retornaria 404 em HTML.

A regra que resolve isso está no `.htaccess` da raiz e **precisa acompanhar qualquer deploy**:

```apache
RewriteRule ^financas-lite/public/(.*)$ /public/$1 [L]
```

Links de página sobrevivem sem ela (devem mesmo ir para o `index.php`); **arquivos estáticos, não**. Se algum dia a página aparecer sem estilo, o primeiro teste é este:

```bash
curl -I http://financas-lite.test/financas-lite/public/assets/css/app.css   # tem que ser 200 text/css
```

---

## 2. Cores

### Tokens da marca

| Token | Hex | Uso |
|---|---|---|
| `primary` | `#c8102e` | Ações principais, item ativo da navegação, destaque de marca |
| `secondary` | `#000000` | Reservado — pouco usado na prática |
| `bglight` | `#f9fafb` | Fundo do `<body>` |

**Hover do primário:** `hover:bg-red-800`. Este é o par fixo — `bg-primary` **sempre** anda com `hover:bg-red-800`. Nunca use `hover:bg-red-700` com `bg-primary` (esse par pertence ao botão de perigo).

### Escala neutra

| Papel | Classe |
|---|---|
| Fundo da aplicação | `bg-bglight` (`#f9fafb`) |
| Superfície (cards, modais, topbar) | `bg-white` |
| Fundo de cabeçalho de tabela / zebra | `bg-gray-50` |
| Borda padrão | `border-gray-200` |
| Borda de campo de formulário | `border-gray-300` |
| Texto principal | `text-gray-900` |
| Texto secundário | `text-gray-600` |
| Texto de rótulo | `text-gray-700` |
| Texto auxiliar / cabeçalho de tabela | `text-gray-500` |
| Texto desabilitado | `text-gray-400` |
| **Fundo da sidebar** | `bg-gray-900` (único bloco escuro da UI) |

### Cores semânticas

| Estado | Fundo | Texto | Borda |
|---|---|---|---|
| Sucesso | `bg-green-50` / `bg-green-100` | `text-green-700` / `text-green-800` | `border-green-500` |
| Erro | `bg-red-50` / `bg-red-100` | `text-red-700` / `text-red-800` | `border-red-500` |
| Aviso | `bg-yellow-50` / `bg-yellow-100` | `text-yellow-800` | `border-yellow-400` |
| Informação | `bg-blue-50` / `bg-blue-100` | `text-blue-700` / `text-blue-800` | — |
| Neutro | `bg-gray-100` | `text-gray-600` / `text-gray-800` | — |
| Destaque | `bg-purple-100` | `text-purple-800` | — |

> Convenção: tom **50** para superfícies de alerta, **100** para fundo de badge, **500** para bordas, **700/800** para texto sobre fundo claro.

---

## 3. Tipografia

| Papel | Classe | Ocorrências |
|---|---|---:|
| Título de página | `text-2xl font-bold text-gray-900` | 4 |
| Título de seção / modal | `text-lg font-semibold text-gray-900 mb-4` | **38** ← único |
| Subtítulo | `text-base font-semibold text-gray-900 mb-4` | 3 |
| Corpo padrão | `text-sm` | dominante |
| Rótulo de campo | `block text-sm font-medium text-gray-700 mb-1` | **110** ← canônico |
| Cabeçalho de tabela | `text-xs font-medium text-gray-500 uppercase tracking-wider` | 12 |
| Badge / metadado | `text-xs font-semibold` | — |
| Microlabel | `text-[10px] font-bold uppercase tracking-wider` | 3 |

**`text-sm` (14px) é o tamanho base da interface**, não `text-base`. Formulários, tabelas e botões são todos `text-sm`.

---

## 4. Espaçamento, Raio e Sombra

### Raio de borda

| Classe | Ocorrências | Uso |
|---|---:|---|
| `rounded-lg` | 337 | **Padrão** — botões, campos, badges retangulares |
| `rounded-xl` | 94 | Cards e modais |
| `rounded-full` | 63 | Pills de status, avatares, toggles |
| `rounded-md` | 24 | Alertas |

Hierarquia: **campo/botão = `lg`, container = `xl`**.

### Sombra

| Classe | Uso |
|---|---|
| `shadow-sm` | Cards e topbar (padrão) |
| `shadow-lg` | Card de login, FAB |
| `shadow-xl` | Modais |
| `shadow-2xl` | Modal de upgrade (único) |

### Espaçamento

- Padding de card: `p-6` (compacto: `p-4`)
- Padding de `<main>`: `p-4 sm:p-6 lg:p-8`
- Gap vertical entre campos: `space-y-4`
- Célula de tabela: `px-6 py-4` (densa: `px-4 py-3`)
- Altura da topbar e do header da sidebar: `h-16`

### Ícones

Todos SVG inline: `viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"` com `stroke-linecap="round" stroke-linejoin="round"`.

| Tamanho | Ocorrências | Uso |
|---|---:|---|
| `h-4 w-4` | 73 | **Padrão** — dentro de botões, campos, tabelas |
| `h-5 w-5` | 62 | Navegação, fechar modal |
| `h-3 w-3` | 15 | Badges |
| `h-6 w-6` | 10 | Toggle mobile, topbar |
| `h-8 w-8` / `h-12 w-12` | 5 / 4 | Ilustrações e estados vazios |

---

## 5. Layout

### Shell da aplicação (`layouts/app.php`)

```
┌─────────────────────────────────────────────┐
│ [botão ☰ fixo — apenas < lg]                │
├──────────┬──────────────────────────────────┤
│ Sidebar  │ Topbar (h-16, bg-white)          │
│ fixed    ├──────────────────────────────────┤
│ bg-gray- │ [Banner de limite/bloqueio]      │
│ 900      ├──────────────────────────────────┤
│ w-64     │ <main> p-4 sm:p-6 lg:p-8         │
│ (w-20    │   conteúdo da página             │
│ colapsa- │                                  │
│ da)      │                                  │
└──────────┴──────────────────────────────────┘
```

O `<div>` de conteúdo acompanha o estado da sidebar:

```html
<div class="transition-all duration-300 min-h-screen flex flex-col"
     :class="sidebarCollapsed ? 'lg:ml-20' : 'lg:ml-64'">
```

Estado global no `<body>` via Alpine, com persistência em `localStorage`:

```js
x-data="{ sidebarOpen: false,
          sidebarCollapsed: localStorage.getItem('sidebarCollapsed') === 'true',
          showUpgradeModal: false,
          toggleSidebar() { this.sidebarCollapsed = !this.sidebarCollapsed;
                            localStorage.setItem('sidebarCollapsed', this.sidebarCollapsed); } }"
```

### Modo autenticação

Sem sidebar nem topbar — o layout detecta `$_SESSION['usuario_id']` e renderiza tela cheia centrada:

```html
<main class="min-h-screen flex items-center justify-center py-12 px-4">
```

### Breakpoints

`lg` (1024px) é o corte principal: abaixo dele a sidebar vira overlay e o botão ☰ aparece. `sm` (640px) ajusta o padding e títulos duplicados para mobile.

---

## 6. Componentes — Botões

### Primário

```html
<button class="px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-red-800 transition-colors">
    Salvar
</button>
```

Variantes legítimas de tamanho — mantenha o resto idêntico:

| Contexto | Classe |
|---|---|
| Padrão | `px-4 py-2` |
| Largo (rodapé de formulário) | `px-6 py-2` ou `px-8 py-2.5 ... font-semibold shadow-sm` |
| Bloco (formulários de auth) | `w-full py-2 px-4` ou `w-full py-3 px-4` |
| Com ícone | prefixe `inline-flex items-center` |

### Secundário / Cancelar

```html
<button class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
    Cancelar
</button>
```

Compacto (toolbars): `inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors`

### Perigo

```html
<button class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
    Excluir
</button>
```

> ⚠️ `bg-red-600/hover:bg-red-700` é **exclusivo de ações destrutivas**. Como o primário também é vermelho, nunca coloque os dois lado a lado sem outro diferenciador (ícone ou posição).

### Contorno primário

```html
<button class="w-full py-3 px-4 border-2 border-primary text-primary font-medium rounded-lg hover:bg-red-50 transition-colors">
    Iniciar trial grátis
</button>
```

### Desabilitado

```html
<button class="w-full py-3 px-4 bg-gray-100 text-gray-400 font-medium rounded-lg cursor-not-allowed" disabled>
```

### Ícone puro (fechar modal)

```html
<button class="text-gray-400 hover:text-gray-600">
    <svg class="h-5 w-5" ...><path d="M6 18L18 6M6 6l12 12" /></svg>
</button>
```

### Terciário / link

```html
<button class="text-sm text-gray-500 hover:text-gray-700">Continuar com plano atual</button>
```

### FAB (dashboard)

```html
<button class="fixed bottom-8 right-8 bg-primary text-white rounded-xl shadow-lg p-4
               hover:bg-red-800 hover:shadow-xl transition-all z-40 transform hover:scale-105 border-0">
```

---

## 7. Componentes — Formulários

### Campo de texto (canônico — 73 ocorrências)

```html
<div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Descrição</label>
    <input type="text" name="descricao"
           class="w-full rounded-lg border-gray-300 border px-4 py-2 text-sm focus:ring-primary focus:border-primary">
</div>
```

Variante compacta (`px-3 py-2`) — 11 ocorrências — aceitável em modais e filtros.

> A ordem das classes na variante canônica é `border-gray-300 border` (invertida). Existe também `border border-gray-300`, funcionalmente idêntica. Prefira a canônica por consistência de busca.

### Select

Idêntico ao input:

```html
<select class="w-full rounded-lg border-gray-300 border px-4 py-2 text-sm focus:ring-primary focus:border-primary">
```

### Textarea

Mesmas classes do input, mais `rows`.

### Campo somente leitura

```html
<input readonly class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50 text-gray-500">
```

Borda mais clara (`gray-200`) e fundo `gray-50` sinalizam não-editável.

### Campo com ícone à esquerda

```html
<div class="relative">
    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
        <svg class="h-4 w-4 text-gray-400" ...></svg>
    </div>
    <input class="w-full pl-10 pr-4 py-2 rounded-lg border border-gray-300 text-sm focus:ring-primary focus:border-primary">
</div>
```

### Campo agrupado (input + botão)

O input recebe `rounded-l-lg` e o botão fecha o grupo à direita:

```html
<input class="w-full rounded-l-lg border-gray-300 border px-4 py-2 text-sm focus:ring-primary focus:border-primary">
```

### Upload de arquivo

```html
<input type="file" class="w-full text-sm text-gray-500
       file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0
       file:text-sm file:font-medium file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200">
```

### Checkbox

```html
<input type="checkbox" class="rounded border-gray-300 text-primary focus:ring-primary">
```

### Toggle switch

Padrão `peer` do Tailwind, 7 ocorrências:

```html
<label class="relative inline-flex items-center cursor-pointer">
    <input type="checkbox" class="sr-only peer">
    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-red-100
                rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white
                after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white
                after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5
                after:transition-all peer-checked:bg-primary"></div>
</label>
```

### Opção em cartão (radio/checkbox destacado)

```html
<label class="flex items-start p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50">
```

### Item de menu suspenso

```html
<label class="flex items-center px-3 py-1.5 hover:bg-gray-50 cursor-pointer text-sm text-gray-700">
```

### Mensagem de erro de campo

```html
<p class="text-xs text-red-600 mt-1">CNPJ inválido.</p>
```

### Estrutura de formulário

```html
<form class="space-y-4">                          <!-- ou space-y-6 em cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">  <!-- campos lado a lado -->
        ...
    </div>
    <div class="flex justify-end gap-3">          <!-- rodapé de ações -->
        <button class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">Cancelar</button>
        <button class="px-6 py-2 bg-primary text-white font-medium rounded-lg hover:bg-red-800 transition-colors">Salvar</button>
    </div>
</form>
```

**Ordem das ações:** secundária à esquerda, primária à direita.

---

## 8. Componentes — Superfícies e Dados

### Card (49 ocorrências)

```html
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-4">Título da seção</h3>
    ...
</div>
```

Variantes: `p-4` (compacto), `p-6 space-y-6` (seções empilhadas), `overflow-hidden` (quando contém tabela até a borda).

### Tabela

```html
<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vencimento</th>
                <th class="px-4 py-3 whitespace-nowrap text-right">Ações</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">01/09/2026</td>
            </tr>
        </tbody>
    </table>
</div>
```

Coluna ordenável: acrescente `cursor-pointer hover:text-gray-700` ao `<th>`.

### Estado vazio

```html
<td colspan="6" class="px-6 py-12 text-center text-gray-500">Nenhum registro encontrado.</td>
```

Erro de carregamento usa a mesma célula com `text-red-500`.

### Badge / pill de status

```html
<span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
    Pago
</span>
```

#### Mapa de status — Contas a Pagar

Fonte: `contas-pagar/index.php:461-475` (`getStatusBadge`). **Use este mapa; não crie cores novas para status.**

| Status | Classes |
|---|---|
| `rascunho` | `bg-gray-100 text-gray-800` |
| `pendente` | `bg-yellow-100 text-yellow-800` |
| `aprovado` | `bg-blue-100 text-blue-800` |
| `agendado` | `bg-purple-100 text-purple-800` |
| `pago` | `bg-green-100 text-green-800` |
| `cancelado` | `bg-gray-100 text-gray-600` |
| `contestado` | `bg-red-100 text-red-800` |
| **Vencido** (derivado) | `bg-red-100 text-red-800` |

"Vencido" não é um status persistido — é calculado quando `data_vencimento < hoje` e `status !== 'pago'`, e **tem precedência** sobre o status real na exibição.

#### Outros mapas de badge

| Contexto | Regra |
|---|---|
| Papel do usuário | `empresa_admin` → `bg-purple-100 text-purple-800`; demais → `bg-blue-100 text-blue-800` |
| Ativo / inativo | `bg-green-100 text-green-800` / `bg-red-100 text-red-800` |
| Plano | `Pro` → `bg-purple-100 text-purple-800`; `Free` → `bg-blue-100 text-blue-800` |
| Log de auditoria | `DELETE` → vermelho; `UPDATE` → amarelo; demais → azul |

### Alerta / flash message

```html
<!-- Sucesso -->
<div class="bg-green-50 border-l-4 border-green-500 p-4 rounded-md mb-4">
    <p class="text-sm text-green-700">Registro salvo com sucesso.</p>
</div>

<!-- Erro -->
<div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-md mb-4">
    <p class="text-sm text-red-700">Não foi possível salvar.</p>
</div>
```

Assinatura do alerta: **`border-l-4` + `rounded-md`** — é o único componente que usa `rounded-md`.

### Banner de limite de plano (`layouts/app.php:62-74`)

```html
<!-- Bloqueio -->
<div class="bg-red-600 text-white px-4 py-3 text-center">
    <p class="font-medium">...</p>
    <button @click="showUpgradeModal = true" class="underline font-bold mt-1">Fazer upgrade para o Pro</button>
</div>

<!-- Aviso -->
<div class="bg-yellow-50 border-l-4 border-yellow-400 px-4 py-3">
    <div class="flex items-center justify-between">
        <p class="text-sm text-yellow-800">...</p>
        <button class="text-sm text-yellow-600 hover:text-yellow-800 font-medium">Upgrade Pro</button>
    </div>
</div>
```

### Stat card (dashboard)

```html
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex flex-col justify-between">
    <div class="flex items-start justify-between">
        <span class="px-2 py-0.5 bg-blue-50 text-blue-700 text-[10px] font-bold rounded uppercase tracking-wider">Mês</span>
        <div class="p-2 bg-blue-50 rounded-md"><svg class="h-4 w-4 text-blue-600" ...></svg></div>
    </div>
    <p class="text-2xl font-bold text-gray-900">R$ 12.480,00</p>
    <div class="mt-4 w-1/2 h-1 bg-blue-600 rounded-full"></div>   <!-- barra de proporção -->
</div>
```

---

## 9. Componentes — Navegação e Sobreposição

### Sidebar (`partials/sidebar.php`)

Container — **único bloco escuro da interface**:

```html
<aside class="fixed top-0 left-0 h-full bg-gray-900 text-white z-40 flex flex-col transition-all duration-300">
```

Item de navegação, com estado ativo por `strpos($uri, ...)`:

```html
<a class="group relative flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors duration-150
   <?= strpos($uri, '/contas-pagar') !== false
       ? 'bg-primary text-white'
       : 'text-gray-300 hover:bg-gray-800 hover:text-white' ?>">
```

Header da sidebar: `h-16 flex items-center px-6 border-b border-gray-800 justify-between overflow-hidden`
Logo: `h-8 w-8 bg-primary rounded-lg flex items-center justify-center`
Avatar: `h-9 w-9 rounded-full bg-primary flex items-center justify-center text-white font-bold text-sm`

### Topbar (`partials/topbar.php`)

```html
<header class="bg-white shadow-sm border-b border-gray-200 h-16 flex items-center justify-between px-4 sm:px-6 lg:px-8">
```

Título: `text-[17px] font-bold text-gray-900 border-none`
Botão mobile: `lg:hidden p-2 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary`

### Modal

**Padrão único**, baseado no store Alpine `$store.modal` registrado em `layouts/app.php`. Todos os 12 modais da aplicação usam esta forma.

```html
<div id="modalResetSenha" x-data x-show="$store.modal.is('modalResetSenha')" x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     @keydown.escape.window="$store.modal.close()"
     class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-black bg-opacity-50 backdrop-blur-sm" @click="$store.modal.close()"></div>
    <div class="relative flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full max-h-[90vh] overflow-y-auto relative z-10">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-semibold text-gray-900">Redefinir Senha</h3>
                    <button onclick="fecharModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="h-5 w-5" ...><path d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                ...
            </div>
        </div>
    </div>
</div>
```

**API de controle** — funções globais definidas no layout:

```js
abrirModal('modalResetSenha');   // abre e trava o scroll do body
fecharModal();                   // fecha o modal ativo e libera o scroll
```

O store aceita **um modal ativo por vez**, o que é adequado a todos os usos atuais (nenhum modal abre outro). Fechar por `Esc` e por clique no backdrop vem de graça — antes só existia o clique.

```js
// layouts/app.php
Alpine.store('modal', {
    active: null,
    is(id)   { return this.active === id; },
    open(id) { this.active = id; document.body.classList.add('overflow-hidden'); },
    close()  { this.active = null; document.body.classList.remove('overflow-hidden'); },
});
```

Larguras: `max-w-md` (formulários curtos, confirmações) · `max-w-lg` (conteúdo rico).

> ⚠️ `x-cloak` é obrigatório no root — sem ele o modal pisca na tela antes de o Alpine hidratar. A regra `[x-cloak]{display:none!important}` está em `resources/css/app.css`.
>
> ⚠️ Nem tudo que se chama `modal*` é modal: `modalFornecedorAlerta`, `modalCategoriaAlerta` (alertas inline) e `modalContent` (container) continuam sendo `<div>` comuns com `classList.toggle('hidden')`. Não os converta.
Camadas `z-index`: backdrop `z-50` → painel `z-10` (dentro do backdrop) · sidebar `z-40` · overlay mobile `z-30` · FAB `z-40` · botão ☰ `z-50`.

### Abas (`config/index.php`)

```html
<button class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors">
```

Ativa: `border-primary text-primary` · Inativa: `border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300`.
Contêiner com scroll horizontal no overflow — a página de Configurações tem 8 abas.

### Paginação

```html
<!-- página atual -->
<button class="px-3 py-1.5 rounded-lg border text-sm bg-primary text-white border-primary">1</button>
<!-- demais -->
<button class="px-3 py-1.5 rounded-lg border text-sm border-gray-300 text-gray-700 hover:bg-gray-50">2</button>
<!-- desabilitada -->
<button class="px-3 py-1.5 rounded-lg border text-sm border-gray-200 text-gray-300 cursor-not-allowed">‹</button>
```

Barra de paginação: `bg-white rounded-xl shadow-sm border border-gray-200 px-4 py-3 flex flex-col sm:flex-row items-center justify-between gap-4`

### Card de autenticação (`auth/login.php`)

```html
<div class="w-full max-w-md bg-white p-8 rounded-xl shadow-lg">
    <div class="text-center mb-6">
        <div class="mx-auto h-12 w-12 bg-primary rounded-xl flex items-center justify-center
                    text-white font-bold text-2xl shadow-lg border border-red-800">
            <svg class="h-6 w-6" ...></svg>
        </div>
        <h1 class="mt-4 text-2xl font-bold text-gray-900">Entrar</h1>
        <p class="mt-1 text-sm text-gray-600">Acesse sua conta</p>
    </div>
    <!-- flash messages -->
    <form class="space-y-4">...</form>
</div>
```

---

## 10. Interação e Movimento

| Efeito | Classe |
|---|---|
| Transição de cor (botões, links) | `transition-colors` |
| Transição de layout (sidebar) | `transition-all duration-300` |
| Transição de navegação | `transition-colors duration-150` |
| Hover de linha de tabela | `hover:bg-gray-50` |
| Hover elevado (FAB) | `transition-all transform hover:scale-105` |
| Entrada de modal (Alpine) | `ease-out duration-300` |
| Saída de modal (Alpine) | `ease-in duration-200` |

**Anel de foco:** `focus:ring-primary focus:border-primary` em campos; `focus:ring-2 focus:ring-inset focus:ring-primary` em botões de ícone; `peer-focus:ring-4 peer-focus:ring-red-100` em toggles.

---

## 11. Inconsistências — Todas Corrigidas (14/08/2026)

As 8 divergências levantadas na auditoria inicial foram corrigidas. Registro do que mudou, para que ninguém as reintroduza:

| # | Problema original | Correção aplicada | Alcance |
|---|---|---|:---:|
| 1 | Anel de foco azul em vez do vermelho da marca | → `focus:ring-primary focus:border-primary` (inputs) e `text-primary … focus:ring-primary` (checkboxes) | 9 |
| 2 | Borda de card `gray-100` × `gray-200` | → `border border-gray-200` | 6 |
| 3 | ApexCharts **e** Chart.js no projeto | Gráfico do dashboard portado para **Chart.js**; ApexCharts eliminado | 1 gráfico |
| 4 | Três padrões de modal concorrentes | Todos migrados para o store Alpine `$store.modal` (§9) | 12 modais, 26 pontos de chamada |
| 5 | `peer-focus:ring-red-300` × `red-100` | → `peer-focus:ring-red-100` | 1 |
| 6 | Título de seção com `font-bold`/`gray-800` | → `text-lg font-semibold text-gray-900` | 13 |
| 7 | Tailwind via CDN, sem purge nem versão fixa | Build local com `tailwindcss@3` → `public/assets/css/app.css` (37 KB minificado) | projeto |
| 8 | Ordem de classes `border-gray-300 border` | → `border border-gray-300` (idiomático) | 91 |

**Verificação:** as 31 views passam em `php -l`; os blocos JS das 9 views alteradas passam em `node --check`; a contagem de `<div>` bate com o backup pré-refatoração em todos os arquivos (exceto o dashboard, onde um `<div>` virou `<canvas>` por exigência do Chart.js); as páginas autenticadas — dashboard, contas a pagar, fornecedores, categorias, usuários, relatórios, configurações, empresas e planos — respondem `200` com o markup Alpine dos modais presente no HTML renderizado.

> Ganho colateral do item 7: o navegador deixa de baixar o runtime JS do Tailwind (~4 MB, que compilava CSS no cliente a cada carregamento) e passa a baixar **37 KB de CSS estático e cacheável**.

---

## 12. Checklist para Novos Elementos

Antes de escrever uma classe nova:

1. **O componente já existe aqui?** Copie a classe canônica literalmente.
2. **Cor:** usou apenas tokens da [seção 2](#2-cores)? Nada de hex solto ou tom fora da escala.
3. **Ação vermelha:** é primária (`bg-primary` + `hover:bg-red-800`) ou destrutiva (`bg-red-600` + `hover:bg-red-700`)? Não misture.
4. **Raio:** campo/botão `rounded-lg`, container `rounded-xl`, alerta `rounded-md`, pill `rounded-full`.
5. **Texto:** base é `text-sm`. Rótulo é sempre `block text-sm font-medium text-gray-700 mb-1`.
6. **Ícone:** SVG inline Heroicons outline, `stroke-width="2"`, `h-4 w-4` salvo motivo.
7. **Foco:** todo elemento interativo tem estado de foco visível em `primary`.
8. **Responsivo:** testou abaixo de `lg` (1024px), onde a sidebar vira overlay?
9. **Status de domínio:** usou o [mapa oficial](#mapa-de-status--contas-a-pagar) em vez de inventar cor?
10. **Modal:** usou `$store.modal` + `abrirModal()/fecharModal()`, com `x-cloak` no root?
11. **Rodou `npm run build:css`?** Classe nova sem rebuild não existe no CSS compilado.
12. **Variante nova de verdade?** Documente-a nesta página **antes** de usá-la no código.

---

## 13. Aplicação no Controle VPS

> As seções 1 a 12 acima são a **referência canônica**, extraída do SPB Finanças Lite. Esta seção registra como o **Controle VPS** aplica esse sistema e onde ele precisou estender — nada foi contrariado.

**Adicionado em:** 15/08/2026 · Complementa [PROGRESS.md](PROGRESS.md)

### 13.1 Caminhos deste projeto

| Item | Valor |
|---|---|
| Entrada do Tailwind | `resources/css/app.css` |
| CSS compilado | `public/assets/css/app.css` (23,3 KB minificado) |
| Views varridas pelo purge | `resources/views/**/*.php` |
| Build | `npm run build:css` · watch: `npm run watch:css` |
| Alpine.js | `public/assets/vendor/alpine.min.js` — **local, não CDN** |
| Chart.js | `public/assets/vendor/chart.umd.min.js` — **local, não CDN** |

**Divergência consciente:** a seção 1 prevê Alpine via CDN. Aqui as duas bibliotecas são vendorizadas. Um painel de monitoramento precisa abrir quando a internet está com problema — que é exatamente quando você mais precisa dele.

O `<link>` do CSS usa cache-busting por `filemtime`, conforme a seção 1.

### 13.2 O conflito do vermelho — e como foi resolvido

`primary` é `#c8102e`. Num painel de monitoramento, vermelho também é a cor de **crítico**. O conflito foi levantado com o usuário, que optou por **manter o vermelho da marca**.

A convivência segue a regra que a própria seção 6 já estabelece:

| Uso | Classe | Onde aparece |
|---|---|---|
| Ação primária | `bg-primary` + `hover:bg-red-800` | Salvar, Cadastrar, Entrar |
| Ação destrutiva | `bg-red-600` + `hover:bg-red-700` | Excluir servidor |
| Status crítico | `bg-red-100` + `text-red-800` | Badges de offline, expirado |
| Barra em nível crítico | `bg-red-500` | Medidores de CPU/RAM/disco |
| Borda de alerta | `border-red-500` | Flash de erro |

**Nunca** aparecem lado a lado sem diferenciador — o botão de exclusão fica isolado num card com borda `border-red-200`, separado das ações normais.

### 13.3 Extensão: mapa de status do monitoramento

A seção 8 traz o mapa de Contas a Pagar. Este projeto tem domínio diferente e precisou do próprio mapa. Ele vive em **código**, não espalhado nas views: `app/Helpers/functions.php` → `status_badge_class()`, `status_dot_class()`, `status_label()`.

| Estado | Badge | Ponto indicador |
|---|---|---|
| `online` · `valid` · `running` · `active` · `resolved` | `bg-green-100 text-green-800` | `bg-green-500` |
| `warning` · `expiring` · `acknowledged` | `bg-yellow-100 text-yellow-800` | `bg-yellow-400` |
| `offline` · `expired` · `stopped` · `critical` · `open` | `bg-red-100 text-red-800` | `bg-red-500` |
| `unknown` · `not_installed` · `inactive` | `bg-gray-100 text-gray-600` | `bg-gray-300` |

Segue a convenção da seção 2: tom **100** para fundo de badge, **500/400** para pontos e barras, **800** para texto.

> **Cinza é um estado legítimo, não uma falha de layout.** "Não foi possível verificar" é informação diferente de "está ruim" — e a seção 16 do PLAN exige essa distinção.

### 13.4 Extensão: níveis de recurso

Para CPU, RAM e disco, `threshold_level()` devolve `normal` / `warning` / `critical` / `unknown`, e dois helpers traduzem em classe:

```php
level_bar_class('critical')   // bg-red-500
level_bar_class('warning')    // bg-yellow-400
level_bar_class('normal')     // bg-green-500
level_bar_class('unknown')    // bg-gray-300
```

Os limites vêm de `config/monitoring.php` e podem ser editados na tela de Configurações — a cor acompanha automaticamente.

### 13.5 Extensão: cores de série nos gráficos

Verde, amarelo e vermelho estão **reservados ao significado**. Usá-los para "esta é a linha da CPU" tornaria o painel ambíguo. As séries usam uma paleta separada (`public/assets/js/charts.js`):

| Série | Cor |
|---|---|
| CPU | `#2563eb` (blue-600) |
| RAM | `#7c3aed` (violet-600) |
| Disco | `#0d9488` (teal-600) |
| Load | `#f59e0b` (amber-500) |

A **exceção** é o gráfico de alertas por severidade e a rosca de SSL: ali verde/amarelo/vermelho **são** o significado, e usá-los é o correto.

### 13.6 Componentes aplicados sem alteração

Card `bg-white rounded-xl shadow-sm border border-gray-200 p-6` · tabela com `thead bg-gray-50` e `th text-xs font-medium text-gray-500 uppercase tracking-wider` · badge pill `px-2 py-1 text-xs font-semibold rounded-full` · alerta `border-l-4` + `rounded-md` · sidebar `bg-gray-900 w-64` (colapsa para `w-20`, estado em `localStorage`) · topbar `h-16 bg-white shadow-sm` · paginação conforme a seção 9 · campo `w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:ring-primary focus:border-primary` · rótulo `block text-sm font-medium text-gray-700 mb-1`.

Ícones: SVG inline Heroicons outline, `stroke-width="2"`, `h-4 w-4` no padrão e `h-5 w-5` na navegação.

### 13.7 Responsividade (seção 26 do PLAN)

`lg` (1024px) é o corte principal, como manda a seção 5.

- **Sidebar** — abaixo de `lg` vira overlay com botão ☰ fixo em `z-50`.
- **Tabelas** — as duas listas densas (servidores e sites) têm **dupla renderização**: `hidden lg:block` para a tabela e `lg:hidden` para cards. Espremer 10 colunas em 375px seria ilegível; o card mostra o essencial e leva ao detalhe.
- **Cards** — `grid-cols-2` no celular, `lg:grid-cols-3`, `xl:grid-cols-6`.
- **Gráficos** — `maintainAspectRatio: false` dentro de container com altura fixa.

### 13.8 Checklist adicional deste projeto

Além dos 12 itens da seção 12:

13. **Status de monitoramento:** usou `status_badge_class()` / `status_dot_class()` em vez de escrever a classe à mão?
14. **Nível de recurso:** usou `threshold_level()` + `level_bar_class()`, respeitando os limites configuráveis?
15. **Cor de série em gráfico:** evitou verde/amarelo/vermelho, salvo quando a cor **é** o significado?
16. **Escape:** toda interpolação passou por `e()`? (Há teste automatizado verificando XSS.)
17. **Tabela densa:** existe a versão em card para abaixo de `lg`?
18. **JS da página:** foi empilhado com `View::pushScript()` em vez de ir para o layout?
