# Hacklab Migration

## Changelog

0.0.3 - Adiciona `setup-plugin.sh` script

0.0.2 - Adiciona compilador de assets (webpack laravel mix)

0.0.1 - Versão inicial

## Compilação dos assets

Na pasta do plugin instale as dependências com `npm install`. Para rodar o compilador em formato de desenvolvimento, execute `npm run dev`. E para compilar os assets para produção, execute `npm run production`.

## Sugestão de fluxo de importação

- Importar arquivos da pasta /uploads, via rSync ou FTP;
- Importar taxonomias e seus respectivos termos;
- Importar usuários;
- Importar CoAuthors;
- Importar posts;
- Rodar o corretor de relacionamento de imagens;
- Rodar o search/replace.
