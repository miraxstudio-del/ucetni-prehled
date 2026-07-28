(function () {
    var stored = localStorage.getItem('ucetni-prehled-theme');
    var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;

    document.documentElement.classList.toggle('dark', dark);
})();
