// app.core.js
// -- Examples --
// App.core.bus.on('cart:checkout', (e, p) => console.log(p));
// App.core.http.get('/api/cart').then(items => App.core.store.set('cart.items', items));
// const unsub = App.core.store.subscribe('cart.items', items => console.log(items), { immediate: true });
(function(ns, $) {
    // 1) BUS
    const $bus = $({});
    const bus = {
        on: function(evt, fn) { $bus.on(evt, fn); return () => $bus.off(evt,fn ); },
        once: function(evt, fn) { $bus.one(evt, fn); },
        off: function(evt, fn) { $bus.off(evt, fn); },
        emit: function(evt, data) { $bus.trigger(evt, data); }
    };

    class HttpPromise extends Promise {
        constructor(executor) {
            super(executor);
            this._xhr = null;
        }

        _withXhr(promise) {
            if (promise && promise instanceof HttpPromise) {
                promise._xhr = this._xhr;
            }
            return promise;
        }

        then(onFulfilled, onRejected) {
            return this._withXhr(super.then(onFulfilled, onRejected));
        }

        catch(onRejected) {
            return this._withXhr(super.catch(onRejected));
        }

        finally(onFinally) {
            return this._withXhr(super.finally(onFinally));
        }

        done(...callbacks) {
            callbacks.forEach((callback) => {
                if (typeof callback !== 'function') return;

                this.then(callback, function() {});
            });

            return this;
        }

        fail(...callbacks) {
            callbacks.forEach((callback) => {
                if (typeof callback !== 'function') return;

                this.catch(callback);
            });

            return this;
        }

        always(...callbacks) {
            callbacks.forEach((callback) => {
                if (typeof callback !== 'function') return;

                this.then(
                    (value) => callback(value),
                    (error) => callback(error)
                );
            });

            return this;
        }

        abort(statusText) {
            if (this._xhr && typeof this._xhr.abort === 'function') {
                this._xhr.abort(statusText);
            }

            return this;
        }

        static fromJqXHR(xhr) {
            const promise = new HttpPromise((resolve, reject) => {
                xhr.done(function(data) {
                    resolve(data);
                });

                xhr.fail(function(jqXHR, textStatus, errorThrown) {
                    reject(
                        jqXHR ||
                        errorThrown ||
                        new Error(textStatus || 'HTTP request failed')
                    );
                });
            });

            promise._xhr = xhr;

            return promise;
        }
    }

    // 2) HTTP
    const http = {
        get: function(url, data, opt) {
            return HttpPromise.fromJqXHR($.ajax(Object.assign({
                url,
                method: 'GET',
                data,
                dataType: 'json',
                cache: false
            }, opt)));
        },

        post: function(url, data, opt = {json:true}) {
            const isForm = typeof FormData !== 'undefined' && data instanceof FormData;
            const sendJSON = opt.json === true;

            return HttpPromise.fromJqXHR($.ajax(Object.assign({
                url,
                method: 'POST',
                dataType: 'json',
                processData: isForm ? false : !sendJSON,
                contentType: isForm ? false :
                    (sendJSON
                        ? 'application/json; charset=utf-8'
                        : 'application/x-www-form-urlencoded; charset=UTF-8'),
                data: isForm
                    ? data
                    : (sendJSON ? JSON.stringify(data || {}) : (data || {}))
            }, opt)));
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
    
    ns.setWidget = function(widget){
        const spec = widget.spec;
        if(typeof spec.name !== 'string') console.error('[App.createWidget] Виджет должен иметь "name"');
        ns.widgets[spec.name] = widget;
    };

    ns.clone = function (obj){
        var copy;
        if (null == obj || "object" != typeof obj) return obj;

        if (obj instanceof Date) {
            copy = new Date();
            copy.setTime(obj.getTime());
            return copy;
        }

        if (obj instanceof Array) {
            copy = [];
            for (var i = 0, len = obj.length; i < len; i++) {
                copy[i] = this.clone(obj[i]);
            }
            return copy;
        }

        if (obj instanceof Object) {
            copy = {};
            for (var attr in obj) {
                if (obj.hasOwnProperty(attr)) copy[attr] = this.clone(obj[attr]);
            }
            return copy;
        }

        throw new Error("Unable to copy obj! Its type isn't supported.");
    };

    // 4a) Реестр Ractive-компонентов для вложенных компонентов шаблонов.
    // Это НЕ инстансы App.components, а именно классы/конструкторы Ractive.extend,
    // которые потом резолвятся в options.components конкретного child Ractive.
    const ractiveComponents = {
        _list: Object.create(null),

        register(name, component) {
            name = String(name || '').trim();
            if (!name) {
                console.error('[App.core.ractiveComponents] empty component name');
                return null;
            }
            if (!component) {
                console.error('[App.core.ractiveComponents] empty component for', name);
                return null;
            }
            this._list[name] = component;
            return component;
        },

        has(name) {
            return !!this._list[String(name || '').trim()];
        },

        get(name) {
            name = String(name || '').trim();
            return this._list[name] || null;
        },

        resolve(spec) {
            const out = {};
            const self = this;

            function add(alias, value) {
                alias = String(alias || '').trim();
                if (!alias) return;

                let component = value;

                if (typeof value === 'string') {
                    component = self.get(value);
                } else if (!value || value === true) {
                    component = self.get(alias);
                }

                if (!component) {
                    console.error('[App.core.ractiveComponents] component not found:', alias, value);
                    return;
                }

                out[alias] = component;
            }

            if (!spec) return out;

            if (Array.isArray(spec)) {
                spec.forEach(function (name) {
                    add(name, name);
                });
                return out;
            }

            if (typeof spec === 'string') {
                add(spec, spec);
                return out;
            }

            if (typeof spec === 'object') {
                Object.keys(spec).forEach(function (alias) {
                    add(alias, spec[alias]);
                });
            }

            return out;
        },

        all() {
            return this._list;
        }
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
    ns.core = { bus, http, store, components, ractiveComponents };

})(window.App = window.App || {}, jQuery);