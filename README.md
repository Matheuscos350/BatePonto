# Ponto Eletrônico em PHP

## Requisitos
- PHP 8.1+ com extensões `pdo_sqlite`, `iconv` e `mbstring` (padrão na maioria das hospedagens)
- **HTTPS obrigatório em produção** (câmera e GPS só funcionam em HTTPS ou em `localhost`)

## Como rodar
```
cd ponto
php -S localhost:8000
```
Logins iniciais (troque em **Funcionários**): `admin / admin123` e `funcionario / 123456`.
- **Funcionário** entra em `index.php` com login **ou e-mail**. **Administrador** tem entrada própria em `admin_login.php`
  (aceita só contas de administrador; link "Acesso do administrador" na tela de login).
- O e-mail é obrigatório no cadastro (autocadastro e cadastro pelo admin), único por pessoa e aparece no PDF.
  O sistema só guarda o e-mail: não envia mensagens (não há servidor de e-mail configurado).
O banco (`data/ponto.sqlite`) e a pasta `uploads/` são criados automaticamente.
Bancos da versão anterior são atualizados sozinhos (colunas de obra).

## Como usar
1. **Obras** (admin): cadastre nome e endereço de cada obra (existe uma "Obra Exemplo" para editar).
2. **Funcionários** (admin): cadastre nome, CPF, função, login, senha e jornada semanal.
   **Autocadastro:** o funcionário acessa `register.php` (link "Criar cadastro" na tela de login), preenche nome, CPF,
   e-mail, função, login e senha e **já entra**, sem esperar o admin. Para exigir aprovação do admin:
   `REQUIRE_SIGNUP_APPROVAL = true` no config.php. Limite de 20 cadastros por hora. O **salário mensal** é informado
   só pelo admin (campo na lista de Funcionários).
3. **Bater ponto** (funcionário): **digita o nome da obra** (ex.: Mercado Livre, Accenture; aparecem sugestões das já usadas)
   e toca no botão do momento. Obra nova é cadastrada na hora (endereço opcional) e o admin ajusta em **Obras**.
   Para só permitir obras cadastradas pelo admin: `ALLOW_FREE_SITE_NAME = false` no config.php. Botões:
   Entrada → Saída para almoço → Volta do almoço → Saída.
   (Se não houver almoço, pode ir direto de Entrada para Saída.)
4. **Chamados** (funcionário): pede reajuste de ponto ou envia atestado (PDF/JPG/PNG, até 5 MB) para abonar horas.
   O funcionário **nunca altera o ponto**: quem decide e ajusta é o administrador.
5. **Ajustar ponto** (só admin): incluir, alterar ou excluir marcações de qualquer funcionário. Exige motivo e grava
   auditoria (quem, quando, antes/depois). Se o ajuste vem de um chamado, ele é aprovado automaticamente.
6. **Chamados** (admin): atestado aprovado abona seg–sex do período (horas/dia editável); recusa exige motivo;
   aprovação pode ser desfeita.
7. **Relatórios / PDF**: horas por dia e semana, obra e endereço de cada dia, horas extras e limite de 20%.

## Relatório de Gastos (menu "Relatório de Gastos")
- Calendário do mês: cada dia mostra quantos gastos e o total. Toque no dia para lançar e ver os gastos dele.
- Campos: Data, Fornecedor, Descrição da despesa, Departamento, Cidade, Estado (UF), Projeto e Valor (R$).
- Fotos das notas: botão "Tirar foto" (câmera do celular) ou "Escolher da galeria / PDF". Até 8 arquivos por gasto;
  as fotos são reduzidas no navegador (máx. 1600 px) e ficam salvas em `uploads/notas/`, só acessíveis pelo dono e pelo admin.
- Cada pessoa vê, edita e exclui os próprios gastos; o administrador vê e edita os de todos (com filtro por funcionário).
- **PDF só das notas**: botão "PDF das notas (N) — só as fotos" (mês) e "PDF das notas deste dia". Gera um PDF só com as fotos anexadas, uma por página, com fornecedor, data, valor e projeto no topo. Notas enviadas em PDF não são incorporadas (aparece uma página de aviso). A tabela de gastos continua em "PDF do relatório (tabela)".
- **Selecionar notas/gastos específicos**: o card "Exportar gastos selecionados" lista os gastos do mês atual e do anterior (select de período, "marcar todos", "só os que têm nota"). Marque os que quiser e gere: PDF das notas, PDF da tabela ou Excel (.xlsx, com total em fórmula). Sem a extensão `zip` do PHP o Excel sai como CSV.
- Resumo do mês com total, subtotal por projeto e por departamento, e exportação em **PDF** e **CSV** (abre no Excel).
- Dica de servidor: o limite de upload depende de `upload_max_filesize`/`post_max_size` no php.ini (8 MB no total é suficiente).

## Valor das horas extras (estimativa)
- Valor da hora = salário mensal ÷ (jornada semanal × 5). Ex.: R$ 2.640 com 44h → ÷ 220 = R$ 12,00/h.
- Extras estimadas = horas extras × valor da hora × 1,5 (`OVERTIME_PREMIUM_PERCENT = 50` no config.php).
- Aparece na tela de ponto (semana atual), no relatório (por semana e total do período) e no PDF.
- É estimativa **bruta**: não inclui DSR, adicional noturno, domingos/feriados (100%) nem descontos (INSS/IR).

## Obras
- O funcionário corrige nome/endereço das obras que **ele** cadastrou em **Minhas obras**. Marcações antigas mantêm o
  nome da época (o admin corrige uma marcação antiga em **Ajustar ponto**).

## Regras de cálculo (config.php)
- `DEFAULT_WEEKLY_HOURS = 44` e `OVERTIME_LIMIT_PERCENT = 20`
- Semana = segunda a domingo. **O almoço não conta como trabalhado.**
- Horas extras = total trabalhado − jornada contratada. ATINGIU/ULTRAPASSOU quando extras ≥ 20% da jornada (44h → 8h48min).
- **Abono (atestado):** as horas abonadas entram no total creditado da semana, mas **não geram hora extra**
  (extras = trabalhado − jornada). Se a sua política for diferente, ajuste em `summarize_week()` (config.php).
- Nome e endereço da obra ficam gravados em cada marcação (editar a obra depois não altera o histórico).

## Produção
- Apache: o `.htaccess` bloqueia `data/` e `uploads/`; em Nginx bloqueie essas pastas.
- Fotos só são exibidas via `photo.php` (dono ou admin).
- Upload de atestado: o limite real também depende de `upload_max_filesize` / `post_max_size` no php.ini.
- LGPD: informe os funcionários sobre foto e localização e defina prazo de retenção.

## Não consigo entrar como administrador
- Use `admin_login.php` (ou o login normal: o admin agora é levado direto para Chamados). Usuário inicial: `admin` / `admin123`.
- Esqueceu a senha? No terminal do servidor: `php reset_admin.php NOVA_SENHA` (só roda por linha de comando, nunca pelo navegador).
- Se o login "passa" mas volta para a tela de entrada: a pasta de sessões do PHP não era gravável. O sistema agora usa `data/sessions` automaticamente; confirme que `data/` tem permissão de escrita.

## Google (geolocalização do ponto)
- A posição (lat/lng) continua vindo do GPS do aparelho. Com a chave do Google, o sistema converte isso em **endereço** (Geocoding API), mostra na tela de ponto, guarda em cada marcação e exibe no histórico e nos relatórios. Os pontos têm link **"ver no Google Maps"** (esse link não precisa de chave).
- Como ativar: no Google Cloud, crie um projeto, ative a **Geocoding API**, gere uma chave e **restrinja por IP do servidor** + só para a Geocoding API. Coloque em `config.php` (`GOOGLE_MAPS_API_KEY`) ou na variável de ambiente `GOOGLE_MAPS_API_KEY`. A chave fica só no servidor (o navegador nunca a vê).
- Sem chave, ou se o Google falhar/estiver lento (limite de 4 s), o ponto é registrado normalmente, só sem o endereço automático.
- A Geocoding API é paga após a cota gratuita mensal do Google; cada ponto bate 1 consulta (resultados repetidos na mesma sessão usam cache).

## Excel no modelo padrão
- O Excel (.xlsx) dos gastos usa o modelo `templates/modelo_gastos.xlsx` (logo, título "Relatório de despesas do cartão corporativo", campo Nome, colunas Data / Fornecedor / Descrição / Departamento / Cidade/UF / Projeto / valor + linha TOTAL). Para trocar o modelo, substitua esse arquivo mantendo o cabeçalho nas linhas 1–8 (a tabela começa na linha 9).

## Versão web e mobile (app instalável / PWA)
- O mesmo site funciona no computador e no celular. No celular ele pode ser **instalado** como app (ícone na tela inicial, abre em tela cheia, sem barra do navegador):
  - **Android/Chrome:** botão "📲 Instalar app" no topo (ou menu ⋮ → "Instalar app").
  - **iPhone/Safari:** Compartilhar → "Adicionar à Tela de Início" (o site mostra essa dica sozinho).
  - **Computador (Chrome/Edge):** ícone de instalar na barra de endereço.
- **Obrigatório em produção: HTTPS.** Sem HTTPS o navegador bloqueia a instalação, o GPS e a câmera.
- Arquivos do app: `manifest.webmanifest`, `sw.js` (service worker), `offline.html`, pasta `icons/`. Se o site ficar em uma subpasta, tudo já usa caminhos relativos.
- Sem internet aparece a página "Sem conexão". O ponto e os gastos exigem internet (hora, GPS e foto são registrados no servidor); o app **não guarda dados pessoais em cache**.
- Para trocar o ícone, substitua os PNG de `icons/` (192, 512, maskable-512, apple-touch-icon 180).
- Quer publicar nas lojas (Play Store / App Store)? Dá para empacotar este mesmo site (TWA no Android, Capacitor no iOS/Android) — exige contas de desenvolvedor e build no seu computador.
