/* Bouton afficher / masquer sur tous les champs mot de passe (utile sur mobile). */
(function () {
    var css = '.pw-wrap{position:relative;display:block;width:100%}' +
        '.pw-wrap>input{width:100%;padding-right:3rem!important}' +
        '.pw-toggle{position:absolute;top:50%;right:.4rem;transform:translateY(-50%);width:2.4rem;height:2.4rem;' +
        'display:flex;align-items:center;justify-content:center;background:none;border:0;border-radius:8px;' +
        'cursor:pointer;color:#94a3b8;padding:0;-webkit-tap-highlight-color:transparent}' +
        '.pw-toggle:hover,.pw-toggle:focus-visible{color:#e2e8f0;outline:0}' +
        '.pw-toggle svg{width:1.3rem;height:1.3rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}';
    var EYE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var EYE_OFF = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    function enhance(input) {
        if (input.dataset.pwToggle) return;
        input.dataset.pwToggle = '1';
        var wrap = document.createElement('div');
        wrap.className = 'pw-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-toggle';
        btn.setAttribute('aria-label', 'Afficher le mot de passe');
        btn.innerHTML = EYE;
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = show ? EYE_OFF : EYE;
            btn.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
            input.focus();
        });
        wrap.appendChild(btn);
    }

    function init() {
        var st = document.createElement('style');
        st.textContent = css;
        document.head.appendChild(st);
        document.querySelectorAll('input[type="password"]').forEach(enhance);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
