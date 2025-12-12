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

  function defineComponent(spec) {
    const normalized = deepMerge({
      name: 'Anon',
      rData: [], // rData: [{ code?, el, template, keys?, on?, options? }]
      state: {},
      computed: {},
      events: {},   // события будут навешаны на каждый child
      options: {},  // общие опции для каждого child Ractive
      hooks: { beforeInit(){}, afterInit(){}, beforeDestroy(){}, afterDestroy(){} },
      wires: { bus: {}, store: {} },
      actions: {}
    }, spec);

    function create(props) {
      const cfg = deepMerge(normalized, props || {});
      const baseState = $.extend(true, {}, cfg.state, props && props.state);

      // реактивный контейнер без рендера
      const rState = new Ractive({ template: '', data: baseState, computed: cfg.computed });

      // wires
      const busUnsubs = [];
      Object.keys(cfg.wires.bus || {}).forEach(evt => busUnsubs.push(bus.on(evt, cfg.wires.bus[evt].bind(rState))));
      const storeUnsubs = [];
      Object.keys(cfg.wires.store || {}).forEach(key => storeUnsubs.push(store.subscribe(key, cfg.wires.store[key].bind(rState))));

      const r = {};     // code -> child Ractive
      const objs = {};  // code -> $(el)
      const unlinks = [];


      const actions = {};
      Object.keys(cfg.actions || {}).forEach(k => {
        actions[k] = cfg.actions[k].bind(
          rState
        );
      });

      rState.r_actions = Object.freeze(actions);

      (cfg.rData || []).forEach(item => {
        const { code, el, template, on = {}, options = {} } = item;
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

        const child = new Ractive(Object.assign({ el: $el[0], template, data: initialData }, cfg.options, options));

        // навесим события: общие + локальные
        const bindEvents = (inst, map) => Object.keys(map || {}).forEach(evt => inst.on(evt, map[evt]));
        bindEvents(child, cfg.events);
        bindEvents(child, on);

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

      cfg.hooks.afterInit.call(rState, { bus, store, http, r, objs });

      const api = {
        name: cfg.name,
        r, objs, state: rState,
        actions,
        setState(patch) { rState.set(patch); },
        getState(path) { return rState.get(path); },
        destroy() {
          cfg.hooks.beforeDestroy.call(rState, { bus, store, http, r, objs });
          // снимаем наблюдателей/инстансы
          // unlinks набит выше для каждого child + ветки
          unlinks.forEach(fn => fn && fn());
          Object.keys(r).forEach(code => r[code].teardown());
          busUnsubs.forEach(u => u && u());
          storeUnsubs.forEach(u => u && u());
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
