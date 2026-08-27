(() => {
    let settings = {};

    function Fields() {
        const el = window.wp.element.createElement;
        const children = [];

        if (settings.logoUrl) {
            children.push(el('img', {
                key: 'logo',
                src: settings.logoUrl,
                alt: 'PayU',
                className: 'give-payu-gateway-logo',
            }));
        }

        children.push(el('p', {key: 'message', style: {marginBottom: 0}}, settings.message));

        return el('div', {className: 'give-payu-gateway-help-text'}, children);
    }

    window.givewp.gateways.register({
        id: 'payu',
        initialize() {
            settings = this.settings || {};
        },
        Fields() {
            return window.wp.element.createElement(Fields);
        },
    });
})();
