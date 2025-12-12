// app.core.js
// -- Examples --
// App.core.bus.on('cart:checkout', (e, p) => console.log(p));
// App.core.http.get('/api/cart').then(items => App.core.store.set('cart.items', items));
// const unsub = App.core.store.subscribe('cart.items', items => console.log(items), { immediate: true });
(function(ns, $) {
    // 1) BUS
    const $bus = $({});
    const bus = {
        on: function(evt, fn) { $bus.on(evt, fn); return () => $bus.off(evt, fn); },
        once: function(evt, fn) { $bus.one(evt, fn); },
        off: function(evt, fn) { $bus.off(evt, fn); },
        emit: function(evt, data) { $bus.trigger(evt, data); }
    };



    // 2) HTTP
    const http = {
        get: function(url, data, opt) {
            return $.ajax(Object.assign({
                url,
                method: 'GET',
                data,
                dataType: 'json',
                cache: false
            }, opt));
        },
        post: function(url, data, opt = {}) {
          const isForm = typeof FormData !== 'undefined' && data instanceof FormData;
          const sendJSON = opt.json === true; // явный флаг

          return $.ajax(Object.assign({
            url,
            method: 'POST',
            dataType: 'json',
            processData: isForm ? false : !sendJSON,
            contentType: isForm ? false :
              (sendJSON ? 'application/json; charset=utf-8'
                        : 'application/x-www-form-urlencoded; charset=UTF-8'),
            data: isForm ? data : (sendJSON ? JSON.stringify(data || {}) : (data || {}))
          }, opt));
        },
        // удобные хелперы
        put: function(url, data, opt) { 
            return http.post(url, data, Object.assign({ method: 'PUT' }, opt));
        },
        del: function(url, data, opt) { 
            return http.post(url, data, Object.assign({ method: 'DELETE' }, opt)); 
        }
    };

    // 3) STORE — ключевой-value стор с подписками
    const _state = Object.create(null);
    const _subs = Object.create(null);

    function _notify(key) {
        const val = _state[key];
        (_subs[key] || []).forEach(fn => fn(val));
        // шлём ещё и через bus, чтобы можно было ловить глобально
        bus.emit('store:changed', { key, value: val });
        bus.emit(`store:${key}:changed`, val);
    }

    const store = {
        get: function(key, fallback) { return key in _state ? _state[key] : fallback; },
        set: function(key, value) { _state[key] = value;
            _notify(key); return value; },
        patch: function(key, patcher) {
            const prev = store.get(key);
            const next = typeof patcher === 'function' ? patcher(prev) : { ...prev, ...patcher };
            _state[key] = next;
            _notify(key);
            return next;
        },
        subscribe: function(key, fn, opts) {
            _subs[key] = _subs[key] || [];
            _subs[key].push(fn);
            if (opts && opts.immediate) fn(_state[key]);
            return () => { _subs[key] = (_subs[key] || []).filter(f => f !== fn); };
        }
    };

    ns.widgets = ns.widgets || {};
    ns.createWidget = function(name, data, extraProps){
        const def = ns.widgets[name];
        if (!def || typeof def.create !== 'function') {
          console.error('[App.createWidget] Виджет не найден:', name);
          return null;
        }
        const props = Object.assign({}, extraProps || {}, data ? { state: data } : {});
        return def.create(props);
    };
    ns.setWidget = function(name, widget){
        ns.widgets[name] = widget;
    };
    // 4) Реестр компонентов и общая шина действий
    const components = {
        _list: Object.create(null),

        // регистрируем инстанс компонента
        register(api) {
            const name = api && api.name ? String(api.name) : 'Anon';
            if (!this._list[name]) this._list[name] = [];
            this._list[name].push(api);
            return api;
        },

        // все компоненты (если без имени) или все по имени
        all(name) {
            if (!name) return this._list;
            return this._list[name] || [];
        },

        // первая инстанса по имени
        first(name) {
            const arr = this._list[name] || [];
            return arr.length ? arr[0] : null;
        }
    };
    // 5) Экспорт ядра
    ns.core = { bus, http, store, components };

})(window.App = window.App || {}, jQuery);