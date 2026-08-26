/** @type {import('tailwindcss').Config} */
module.exports = {
    // Varre tudo que pode conter classes. Todas as classes do projeto sao
    // literais (nao ha concatenacao de strings), entao o purge e seguro.
    content: [
        './resources/views/**/*.php',
        './resources/js/**/*.js',
        './app/**/*.php',
        './public/**/*.php',
        // JS tambem monta classes Tailwind em runtime (badges, tooltips,
        // toggles) - sem varrer aqui, uma classe usada so no JS nunca
        // entraria no CSS compilado e o utilitario simplesmente nao existiria.
        './public/assets/js/**/*.js',
    ],
    theme: {
        extend: {
            colors: {
                primary: '#c8102e',   // vermelho SPB - DESIGN.md secao 2
                secondary: '#000000',
                bglight: '#f9fafb',
            },
        },
    },
    plugins: [],
};
