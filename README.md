# Plugin Template

## Changelog

0.0.3 - Adiciona `setup-plugin.sh` script

0.0.2 - Adiciona compilador de assets (webpack laravel mix)

0.0.1 - Versão inicial

## Compilação dos assets

Na pasta do plugin instale as dependências com `npm install`. Para rodar o compilador em formato de desenvolvimento, execute `npm run dev`. E para compilar os assets para produção, execute `npm run production`.

## Criando um novo plugin a partir do template

Dê permissão de execução ao script `setup-plugin.sh` com `chmod +x ./setup-plugin.sh`.

Na pasta do plugin execute `./setup-plugin.sh Nome do Novo Plugin`, ele vai criar uma pasta ao lado da atual com todos arquivos do template já renomeados para o novo plugin.
