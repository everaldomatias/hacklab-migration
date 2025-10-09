let mix = require('laravel-mix');

mix.sass('src/scss/style.scss', 'dist/style.css')
    .js('src/js/main.js', 'dist/main.js')
    .setPublicPath('/')
    .options({
        processCssUrls: false
    });
