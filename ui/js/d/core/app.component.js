// app.component.js (rData + keys, узкая синхронизация по нескольким веткам)
(function (ns, $, Ractive) {
  const { bus, store, http } = ns.core;

  function deepMerge(a, b) {
    if ($.isArray(a) && $.isArray(b)) return a.concat(b);
    if ($.isPlainObject(a) && $.isPlainObject(b)) {
      const out = { ...a };
      Object.keys(b).forEach(k => { out[k] = k in out ? deepMerge(out[k], b[k]) : b[k]; });
      return out;
    }
    return b;
  }

  // утилиты для путей
  const hasPath = (obj, path) => {
    if (!path) return true;
    const parts = path.split('.');
    let cur = obj;
    for (let i = 0; i < parts.length; i++) {
      if (cur == null || !Object.prototype.hasOwnProperty.call(cur, parts[i])) return false;
      cur = cur[parts[i]];
    }
    return true;
  };

  function isNativeEventLike(value) {
    if (!value || typeof value !== 'object') return false;

    if (typeof Event !== 'undefined' && value instanceof Event) {
      return true;
    }

    const hasType =
      typeof value.type === 'string';

    const hasTarget =
      !!(value.target || value.currentTarget || value.srcElement);

    const hasEventApi =
      typeof value.preventDefault === 'function' ||
      typeof value.stopPropagation === 'function';

    return hasType && hasTarget && hasEventApi;
  }


  function getNativeEvent(value) {
    if (isNativeEventLike(value)) return value;
    if (value && isNativeEventLike(value.original)) return value.original;
    if (value && isNativeEventLike(value.originalEvent)) return value.originalEvent;
    if (value && isNativeEventLike(value.event)) return value.event;
    if (value && isNativeEventLike(value.domEvent)) return value.domEvent;
    return null;
  }

  function isRactiveContext(value) {
    if (!value || typeof value !== 'object') return false;
    if (isNativeEventLike(value)) return false;

    return !!(
      value.node ||
      value.component ||
      value.keypath !== undefined ||
      value.index !== undefined ||
      value.name !== undefined ||
      value.context !== undefined ||
      value.event ||
      value.original ||
      value.originalEvent ||
      value.domEvent
    );
  }

  function mergeEventContext(ctx, event, payload) {
    const out = (ctx && typeof ctx === 'object' && !isNativeEventLike(ctx)) ? ctx : {};
    const e = getNativeEvent(event) || getNativeEvent(ctx);

    if (e) {
      out.event = e;
      out.original = e;
      out.originalEvent = e;
      out.domEvent = e;

      if (!out.node) out.node = e.currentTarget || e.target || null;
      if (!out.target) out.target = e.target || null;

      if (!out.preventDefault) {
        out.preventDefault = function () {
          if (e.preventDefault) e.preventDefault();
        };
      }

      if (!out.stopPropagation) {
        out.stopPropagation = function () {
          if (e.stopPropagation) e.stopPropagation();
        };
      }
    }

    if (
      payload &&
      typeof payload === 'object' &&
      !Array.isArray(payload) &&
      !isNativeEventLike(payload)
    ) {
      out.payload = payload;

      Object.keys(payload).forEach(function (key) {
        if (!(key in out)) out[key] = payload[key];
      });
    }

    return out;
  }

  function normalizeRactiveEventArgs(argsLike) {
    const args = Array.prototype.slice.call(argsLike || []);

    if (!args.length) {
      return [{}];
    }

    const first = args[0];
    const second = args[1];

    // Нормальный новый формат:
    // @this.fire('name', @context, @event, arg1, arg2)
    if (isRactiveContext(first)) {
      const e = getNativeEvent(second);

      if (e) {
        return [
          mergeEventContext(first, e),
          ...args.slice(2)
        ];
      }

      return [
        mergeEventContext(first, null, second),
        ...args.slice(1)
      ];
    }

    // Старый формат:
    // @this.fire('name', event, arg1)
    const e = getNativeEvent(first);
    if (e) {
      return [
        mergeEventContext({}, e),
        ...args.slice(1)
      ];
    }

    // Payload-only:
    // @this.fire('name', {id: id})
    return [
      mergeEventContext({}, null, first),
      ...args
    ];
  }

  function wrapOnHandlers(ractive, map) {
    const out = {};

    Object.keys(map || {}).forEach(function (name) {
      const fn = map[name];
      if (typeof fn !== 'function') return;

      out[name] = function () {
        return fn.apply(ractive, normalizeRactiveEventArgs(arguments));
      };
    });

    return out;
  }

  function bindRactiveEvents(ractive, map) {
    const wrapped = wrapOnHandlers(ractive, map || {});
    const handles = [];

    Object.keys(wrapped).forEach(function (name) {
      const handle = ractive.on(name, wrapped[name]);
      if (handle) handles.push(handle);
    });

    return handles;
  }
  
  const assignAt = (obj, path, value) => {
    if (!path) return value;
    const parts = path.split('.');
    let cur = obj;
    for (let i = 0; i < parts.length - 1; i++) {
      const p = parts[i];
      if (!cur[p] || typeof cur[p] !== 'object') cur[p] = {};
      cur = cur[p];
    }
    cur[parts[parts.length - 1]] = value;
    return obj;
  };
  const startsWithPath = (k, prefix) => k === prefix || k.startsWith(prefix + '.');


  function shallowMergeObjects() {
    const out = {};
    Array.prototype.slice.call(arguments).forEach(function (src) {
      if (!src || typeof src !== 'object' || Array.isArray(src)) return;
      Object.keys(src).forEach(function (k) {
        out[k] = src[k];
      });
    });
    return out;
  }

  function resolveRactiveComponents() {
    const registry = ns.core && ns.core.ractiveComponents;
    const out = {};

    function addMap(map) {
      if (!map) return;

      if (registry && typeof registry.resolve === 'function') {
        const resolved = registry.resolve(map);
        Object.keys(resolved).forEach(function (k) {
          out[k] = resolved[k];
        });
        return;
      }

      if (typeof map === 'string') return;
      if (Array.isArray(map)) return;

      if (typeof map === 'object') {
        Object.keys(map).forEach(function (k) {
          if (map[k]) out[k] = map[k];
        });
      }
    }

    Array.prototype.slice.call(arguments).forEach(addMap);
    return out;
  }


  function defineComponent(spec) {
    const normalized = deepMerge({
      name: 'Anon',
      rData: [], // rData: [{ code?, el, template, keys?, on?, options?, components? }]
      state: {},
      objects: {}, // долгоживущие сервисы компонента; objs остаётся DOM-реестром
      computed: {},
      components: {}, // Ractive child components: object/array/string, resolved through App.core.ractiveComponents
      ractiveComponents: {}, // alias for components, kept for clarity
      events: {},   // события будут навешаны на каждый child
      options: {},  // общие опции для каждого child Ractive
      hooks: { beforeInit(){}, afterInit(){}, beforeDestroy(){}, afterDestroy(){} },
      wires: { bus: {}, store: {} },
      actions: {}
    }, spec);

    function create(props) {
      const cfg = deepMerge(normalized, props || {});
      if (props && Object.prototype.hasOwnProperty.call(props, 'rData')) {
        cfg.rData = props.rData;
      }
      const baseState = $.extend(true, {}, cfg.state, props && props.state);

      // реактивный контейнер без рендера
      const rState = new Ractive({ template: '', data: baseState, computed: cfg.computed });

      // Сервисные объекты создаются до дочерних Ractive-компонентов.
      const objects = {};
      Object.keys(cfg.objects || {}).forEach(function (code) {
        const factory = cfg.objects[code];
        objects[code] = typeof factory === 'function'
          ? factory({ state: rState, bus, store, http, objects })
          : factory;
      });

      rState.r_objects = objects;
      rState.getObject = function (code) {
        return objects[code] || null;
      };

      // wires
      const busUnsubs = [];
      Object.keys(cfg.wires.bus || {}).forEach(evt => busUnsubs.push(bus.on(evt, cfg.wires.bus[evt].bind(rState))));
      const storeUnsubs = [];
      Object.keys(cfg.wires.store || {}).forEach(key => storeUnsubs.push(store.subscribe(key, cfg.wires.store[key].bind(rState))));

      const r = {};     // code -> child Ractive
      const objs = {};  // code -> $(el)
      const unlinks = [];

      cfg.hooks.beforeInit.call(rState, { bus, store, http, r, objs, objects });

      const actions = {};
      Object.keys(cfg.actions || {}).forEach(k => {
        actions[k] = cfg.actions[k].bind(
          rState
        );
      });

      rState.r_actions = Object.freeze(actions);

      (cfg.rData || []).forEach(item => {
        const { code, el, template, options = {} } = item;
        const onCfg = cfg.on;
        const userOninit = options.oninit;
        let rawKeys = item.keys; // может быть строкой или массивом

        if (!el || !template) { console.error('[component] rData: нужен el и template', item); return; }

        // нормализуем keys:
        // 1) если передали строку — в массив
        // 2) если ничего не передали, но есть code — [code]
        // 3) если нет ни keys, ни code — null (весь state)
        let keys = null;
        if (Array.isArray(rawKeys) && rawKeys.length) {
          keys = rawKeys.map(s => String(s).trim()).filter(Boolean);
        } else if (typeof rawKeys === 'string' && rawKeys.trim()) {
          keys = [rawKeys.trim()];
        } else {
          keys = null; // весь state
        }

        // валидация наличия веток, если keys есть
        if (keys && keys.length) {
          const st = rState.get();
          keys.forEach(k => {
            if (!hasPath(st, k)) {
              throw new Error(`[component] В state нет ключа "${k}". Добавь его в state при создании компонента.`);
            }
          });
        }

        const $el = $(el);

        // начальные данные ребёнка, если есть ключи (keys) то он их пробрасывает в детей Ractive, если нет то пробрасывает всё состояние rState
        let initialData;
        if (!keys) {
          initialData = rState.get() || {};
        } else {
          initialData = {};
          keys.forEach(k => assignAt(initialData, k, rState.get(k) || {}));
        }
        initialData['r_actions'] = actions;

        options.oninit = function (...args) {
          this.r_objects = objects;
          this.getObject = function (objectCode) {
            return objects[objectCode] || null;
          };

          // достаём обработчики: только для этого code
          const scoped = (onCfg && code && onCfg[code]) ? onCfg[code] : null;

          if (scoped && typeof scoped === 'object') {
            // чтобы потом снять при teardown (и не ловить двойные бинды)
            const handles = this.on(wrapOnHandlers(this, scoped));
            this.__cmpOnHandles = (this.__cmpOnHandles || []).concat(handles || []);
          }

          // дальше твой oninit как есть
          if (typeof userOninit === 'function') {
            return userOninit.apply(this, args);
          }
        };

        const resolvedChildComponents = resolveRactiveComponents(
          cfg.components,
          cfg.ractiveComponents,
          item.components,
          item.ractiveComponents,
          options.components
        );

        const childOptions = Object.assign({}, options);
        if (Object.keys(resolvedChildComponents).length) {
          childOptions.components = resolvedChildComponents;
        }

        const child = new Ractive(Object.assign({ el: $el[0], template, data: initialData }, childOptions));
        
        // навесим события компонента через общий нормализатор Ractive 1.3.x
        const eventHandles = bindRactiveEvents(child, cfg.events);
        unlinks.push(() => {
          eventHandles.forEach((h) => {
            if (h && typeof h.cancel === 'function') h.cancel();
          });
        });

        // двусторонняя синхронизация
        let guardParent = false, guardChild = false;

        // Родитель -> Ребёнок: наблюдаем только нужные ветки или весь state
        if (!keys) {
          const uAll = rState.observe('*', (n, o, k) => {
            if (guardChild) return;
            guardParent = true;
            child.set(k, n);
            guardParent = false;
          }, { init: false });
          unlinks.push(() => uAll.cancel());
        } else {
          keys.forEach(kroot => {
            const uRoot = rState.observe(kroot, (n) => {
              if (guardChild) return;
              guardParent = true;
              child.set(kroot, n);
              guardParent = false;
            }, { init: false });
            const uBranch = rState.observe(kroot + '.*', (n, o, k) => {
              if (guardChild) return;
              guardParent = true;
              child.set(k, n); // путь полный, т.к. в child корень такой же
              guardParent = false;
            }, { init: false });
            unlinks.push(() => { uRoot.cancel(); uBranch.cancel(); });
          });
        }

        // Ребёнок -> Родитель: фильтруем изменения вне разрешённых веток
        const uChild = child.observe('*', (n, o, k) => {
          if (guardParent) return;

          // если keys нет — всё отражаем
          if (!keys) {
            guardChild = true;
            rState.set(k, n);
            guardChild = false;
            return;
          }

          // с keys — только внутри разрешённых префиксов
          const ok = keys.some(prefix => startsWithPath(k, prefix));
          if (!ok) return;

          guardChild = true;
          rState.set(k, n);
          guardChild = false;
        }, { init: false });

        const slot = code || '_root';
        r[slot] = child;
        const ra = child;
        objs[slot] = $el;
        unlinks.push(() => uChild.cancel());
      });

      cfg.hooks.afterInit.call(rState, { bus, store, http, r, objs, objects });

      const api = {
        name: cfg.name,
        r, objs, objects, state: rState,
        actions,
        getObject(code) { return objects[code] || null; },
        setState(patch) { rState.set(patch); },
        getState(path) { return rState.get(path); },
        destroy() {
          cfg.hooks.beforeDestroy.call(rState, { bus, store, http, r, objs, objects });
          // снимаем наблюдателей/инстансы
          // unlinks набит выше для каждого child + ветки
          unlinks.forEach(fn => fn && fn());
          Object.keys(r).forEach(code => r[code].teardown());
          busUnsubs.forEach(u => u && u());
          storeUnsubs.forEach(u => u && u());
          Object.keys(objects).forEach(function (code) {
            const object = objects[code];
            if (object && typeof object.destroy === 'function') {
              object.destroy();
            }
          });
          rState.teardown();
          cfg.hooks.afterDestroy.call(null);
        }
      };


      if (ns.core) {
        if (ns.core.components && typeof ns.core.components.register === 'function') {
          ns.core.components.register(api);
        }
      }

      return api;
    }

    return { create, spec: normalized };
  }

  function cloneComponent(base, overrides) {
    const baseSpec = base.spec || base;
    return defineComponent(deepMerge(baseSpec, overrides || {}));
  }

  ns.components = { defineComponent, cloneComponent };
})(window.App = window.App || {}, jQuery, Ractive);
