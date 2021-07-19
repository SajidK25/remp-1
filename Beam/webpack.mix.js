const mix = require('laravel-mix');

mix.override((config) => {
    delete config.watchOptions;
}).webpackConfig({
    resolve: {
        symlinks: false,
    },
    watchOptions: {
        ignored: /node_modules([\\]+|\/)+(?!@remp)/
    }
}).version();

require('laravel-mix-polyfill');

if (process.env.REMP_TARGET === 'iota') {
    // we're not using mix.extract() due to issues with splitting of banner.js + vue.js; basically we need not to have manifest.js
    mix
        .options({
            publicPath: "public/assets/iota/",
            resourceRoot: "/assets/iota/",
            postCss: [
                require('autoprefixer'),
            ],
        })
        .js("resources/assets/js/iota.js", "js/iota.js")
        .vue()
} else if (process.env.REMP_TARGET === 'lib') {
    // we're not using mix.extract() due to issues with splitting of banner.js + vue.js; basically we need not to have manifest.js
    mix
        .options({
            publicPath: "public/assets/lib/",
            resourceRoot: "/assets/lib/",
            postCss: [
                require('autoprefixer'),
            ],
        })
        .js("resources/assets/js/remplib.js", "js/remplib.js")
        .vue()
        .polyfill({
            enabled: true,
            useBuiltIns: "usage",
            targets: {"ie": 11},
            debug: false,
        });

} else {
    mix
        .options({
            publicPath: "public/assets/vendor/",
            resourceRoot: "/assets/vendor/",
            postCss: [
                require('autoprefixer'),
            ],
        })
        .js("resources/assets/js/app.js", "js/app.js")
        .js("resources/assets/js/remplib.js", "js/remplib.js")
        .sass("resources/assets/sass/vendor.scss", "css/vendor.css")
        .sass("resources/assets/sass/app.scss", "css/app.css")
        .vue()
        .extract();
}
