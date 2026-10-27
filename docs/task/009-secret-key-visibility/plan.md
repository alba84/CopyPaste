# План: 009-secret-key-visibility

## Связь с требованиями

Задача добавляет чисто клиентский показ значения `SecretKey` по удержанию
иконки «глаз» и переключение с клавиатуры/AT. Серверный HTML поля остаётся
`type="password"`, серверная логика, криптография, схема БД, маршруты,
валидация, CSRF, rate limiting и `no-store` не меняются. Решения G1 — все
варианты **A** (Q1–Q10).

| AC | Как закрывается в решении | Проверка |
|---|---|---|
| AC1 | Единый form-theme-блок `secret_key_row` выводит input + кнопку; обе формы остаются `PasswordType`; в серверном HTML кнопка `hidden` | Functional: новые тесты в `CreateSecretFlowTest` и `RevealSecretFlowTest` |
| AC2 | JS: `pointerdown` → показать, `pointerup` → скрыть; `type` меняется только в рантайме | Ручной чек-лист (Q10-A) |
| AC3 | JS скрывает по `pointerleave`, `pointercancel`, `lostpointercapture`, `visibilitychange`, `Escape`, `blur` кнопки; фокус поля не трогаем | Ручной чек-лист |
| AC4 | Pointer Events, `touch-action: manipulation`, `user-select: none`, `-webkit-touch-callout: none`, `contextmenu` только на кнопке | Ручной чек-лист iOS/Android |
| AC5 | `click` с `detail === 0` — toggle для клавиатуры/AT; синхронные `aria-pressed`/`aria-label`; `Escape`/`blur` сбрасывают | Ручной чек-лист клавиатура/скринридер |
| AC6 | Кнопка `hidden` в серверном HTML; JS снимает `hidden` только после успешной привязки и только при наличии `PointerEvent` | Ручной чек-лист no-JS + AC1-тест на `hidden` |
| AC7 | CSS: кнопка внутри поля справа, 44×44, правый padding, состояния hover/pressed/focus-visible, `forced-colors` | Ручной/инструментальный чек-лист (360 px, DevTools) |
| AC8 | Разметка иконки/кнопки только в `templates/form/secret_key_theme.html.twig`; `create.html.twig`/`view.html.twig` содержат только ссылку на тему | Ревью diff + ручная проверка |
| AC9 | Изменения только в Presentation/templates/public/tests/docs; никаких Domain/Application/Infrastructure/Crypto/Access/БД/маршрутов | Ревью diff (`git diff --name-only`) |
| AC10 | JS не читает и не пишет значение ключа, нет `console`/storage/network; в `data-*`/`aria-*`/`title` значения нет | Ручной/инструментальный чек-лист + ревью |
| AC11 | Существующие `CreateSecretFlowTest`, `RevealSecretFlowTest`, `PasteFlowTest`, `AccessRateLimitFlowTest` не меняются по смыслу | `make qa` |
| AC12 | Браузерная матрица evergreen + iOS Safari 15+ / Android Chrome | Ручной чек-лист |

## Затрагиваемые модули и слои

Затрагивается только контекст `Secret`, слой `Presentation`, а также шаблоны,
публичные ассеты, functional-тесты и документация задачи.

- `src/Secret/Presentation/Http/Form/CreateSecretType.php` — добавляется
  rendering-опция `block_prefix => 'secret_key'`; поле остаётся
  `PasswordType::class`.
- `src/Secret/Presentation/Http/Form/RevealSecretType.php` — то же.
- `templates/form/secret_key_theme.html.twig` — **новый** единый источник
  разметки поля `SecretKey` и кнопки видимости.
- `templates/paste/create.html.twig`, `templates/paste/view.html.twig` —
  добавляется только подключение темы:
  `{% form_theme form 'form/secret_key_theme.html.twig' %}`. Копий разметки
  кнопки/иконки в шаблонах нет.
- `templates/base.html.twig` — подключение `secret-key-visibility.js` с
  `defer` рядом с существующим `copy.js`.
- `public/secret-key-visibility.js` — **новый** независимый скрипт.
- `public/styles/app.css` — стили обёртки, кнопки, иконок и состояний.
- `tests/Functional/CreateSecretFlowTest.php`,
  `tests/Functional/RevealSecretFlowTest.php` — новые AC1-тесты.
- `docs/task/009-secret-key-visibility/plan.md` — этот план.

Не затрагиваются: `src/*/Domain`, `src/*/Application`,
`src/*/Infrastructure`, `src/Crypto`, `src/Access`, `migrations/`,
`config/routes.yaml`, `config/packages/rate_limiter.yaml`,
`config/packages/twig.yaml` (глобальные `form_themes` не меняем),
`public/copy.js`, контроллеры, `SecretSchemaAnchor`.

**Почему не глобальный `form_themes`.** Глобальная регистрация темы
потребовала бы правки `config/packages/twig.yaml`, которая не входит в
разрешённые AC9 пути. Используется эквивалентный механизм Symfony: тема
подключается к конкретной форме через `{% form_theme %}`; глобальные темы
остаются fallback для блоков, которых нет в per-form теме. Сейчас глобальная
тема — тот же `form_div_layout.html.twig`, поэтому поведение не меняется. Тема
определяет один собственный блок `secret_key_row`, поэтому не «захватывает»
другие поля. Это решение можно
поменять на глобальное одной строкой в `config/packages/twig.yaml` только по
отдельному решению G2 (см. «Вопросы для G2»).

## Изменение модели/контрактов (агрегаты, VO, порты)

Изменений модели и серверных контрактов **нет**.

- Агрегат `Secret`, VO (`Token`, `Ttl`, `Hint`, `Plaintext`, `SecretKey`,
  `EncryptedPayload`), доменные события, порты `SecretRepository`/
  `TokenGenerator`, use cases и адаптеры не затрагиваются.
- Схема БД, `SecretSchemaAnchor`, миграции, SQL — не затрагиваются.
- HTTP-контракт не меняется: те же маршруты, статусы, редиректы,
  `Cache-Control: no-store`, тексты ошибок и подсказки.
- Wire-контракт форм не меняется: `create_paste[secret]` / `unlock_paste[secret]`,
  `id`, `maxlength="255"`, `autocomplete` (`new-password` / `current-password`),
  `required`, CSRF-токен, валидация. `block_prefix` — только rendering-опция
  Symfony Form, она не влияет на имя, id, значение и валидацию.
- Новый внутренний Presentation-контракт: form-theme-блок `secret_key_row` и
  DOM-атрибуты `data-secret-key-visibility`, `aria-controls`, `aria-label`,
  `aria-pressed`, `hidden`. Это не публичный wire-контракт и не серверное
  состояние.
- Кнопка имеет `type="button"` и не имеет `name`, поэтому не участвует в
  отправке формы и не добавляет полей в запрос.

### Единый источник разметки (Q6-A)

`templates/form/secret_key_theme.html.twig` начинается с
`{% use 'form_div_layout.html.twig' %}` (нужно, чтобы внутри темы был доступен
`block('attributes')` и стандартные блоки) и определяет один собственный блок
`secret_key_row`, который повторяет порядок и ARIA-логику стандартного
`form_row_render` Symfony 7.4 (label → errors → widget → help) и добавляет
кнопку внутри обёртки поля:

```twig
{% use 'form_div_layout.html.twig' %}

{% block secret_key_row %}
    {%- set attr = {} -%}
    {%- set aria_describedby = [] -%}
    {%- if help -%}
        {%- set aria_describedby = aria_describedby|merge([id ~ '_help']) -%}
    {%- endif -%}
    {%- if errors|length > 0 -%}
        {%- set aria_describedby = aria_describedby|merge(errors|map((_, index) => id ~ '_error' ~ (index + 1))) -%}
    {%- endif -%}
    {%- if aria_describedby|length > 0 -%}
        {%- set attr = attr|merge({'aria-describedby': aria_describedby|join(' ')}) -%}
    {%- endif -%}
    {%- if errors|length > 0 -%}
        {%- set attr = attr|merge({'aria-invalid': 'true'}) -%}
    {%- endif -%}
    <div{% with {attr: row_attr} %}{{ block('attributes') }}{% endwith %}>
        {{- form_label(form) -}}
        {{- form_errors(form) -}}
        <div class="secret-key-field">
            {{- form_widget(form, {attr: attr}) -}}
            <button type="button"
                    class="secret-key-visibility"
                    data-secret-key-visibility
                    aria-controls="{{ id }}"
                    aria-label="Показать секретный ключ"
                    aria-pressed="false"
                    hidden>
                <svg class="secret-key-visibility__icon secret-key-visibility__icon--show" ... aria-hidden="true" focusable="false">…eye…</svg>
                <svg class="secret-key-visibility__icon secret-key-visibility__icon--hide" ... aria-hidden="true" focusable="false">…eye-off…</svg>
            </button>
        </div>
        {{- form_help(form) -}}
    </div>
{% endblock %}
```

Важно:

- `attr` из вызова `form_row(form.secret, {attr: {class: 'input', placeholder: …}})`
  не теряется: Symfony FormRenderer передаёт его в scope, а
  `form_widget(form, {attr: attr})` мёржит локальные `aria-*` с исходными
  `class`/`placeholder`. Поэтому `class="input"` и placeholder остаются
  прежними.
- `row_attr: {class: 'field'}` из шаблонов сохраняется: внешний `<div>`
  рендерится через `block('attributes')`, поэтому CSS-селекторы
  `.field > label` и `.field:has(.form-error-message) .input` продолжают
  работать.
- `form_errors` выводится в том же месте, что и в стандартном `form_row`
  Symfony 7.4, поэтому рамка/сообщения об ошибках не меняются.
- Иконки — два inline-SVG (`eye` и `eye-off`), обе `aria-hidden="true"` и
  `focusable="false"`; переключение видимости иконок делает CSS по
  `[aria-pressed]`. Скринридер не объявляет иконки, только кнопку.
- Кнопка не дублируется в `create.html.twig`/`view.html.twig`; в шаблонах
  только `{% form_theme %}`.
- `{% use 'form_div_layout.html.twig' %}` нужен именно для `block('attributes')`:
  это Twig-блок текущего шаблона, а не form-renderer-вызов, поэтому без
  импорта layout он не найдётся. Сейчас глобальная тема — тот же
  `form_div_layout.html.twig`, так что поведение не меняется. Если в будущем
  появится глобальная кастомная тема, приоритет блоков per-form темы нужно
  перепроверить (см. риски).

## Тест-план

- unit: нет — Domain/Application не меняются.
- application: нет — use cases, порты и in-memory адаптеры не меняются.
- functional: новые тесты в существующих flow-тестах (без переписывания
  текущих сценариев):
  - `tests/Functional/CreateSecretFlowTest.php` —
    `testCreateFormRendersSecretKeyVisibilityToggleWithoutChangingPasswordContract()`:
    `GET /`; проверить ровно один
    `input[type="password"][name="create_paste[secret]"]` с прежними
    `maxlength="255"`, `autocomplete="new-password"`, `required`, `class="input"`,
    placeholder; ровно одну
    `button[type="button"][data-secret-key-visibility]` с `hidden`,
    `aria-controls`, равным `id` input, `aria-label="Показать секретный ключ"`,
    `aria-pressed="false"`, без `name`; input и button — прямые дети
    `.secret-key-field`; все `svg` внутри кнопки имеют `aria-hidden="true"`
    (например, `assertSame($button->filter('svg')->count(),
    $button->filter('svg[aria-hidden="true"]')->count())`).
  - `tests/Functional/RevealSecretFlowTest.php` —
    `testRevealFormRendersSecretKeyVisibilityToggleWithoutChangingPasswordContract()`:
    тот же набор для `GET /p/{token}` (использовать существующий
    `persistSecret()`) с `unlock_paste[secret]`,
    `autocomplete="current-password"`, placeholder `"Введите ключ"`.
  - Существующие `CreateSecretFlowTest`, `RevealSecretFlowTest`,
    `PasteFlowTest`, `AccessRateLimitFlowTest` не меняются и закрывают AC11:
    wire-контракты, статусы `200/302/404/422`, русские тексты, валидация,
    лимиты, TTL, `no-store`.
  - `Crawler::selectButton()` в существующих тестах продолжает находить
    submit-кнопки по тексту: новая кнопка не имеет текста и имеет
    `type="button"`, поэтому не конфликтует с выбором формы.
- static: `make qa` (cs-check, PHPStan L10, deptrac, PHPUnit). Новых PHP-классов
  в `src/` нет; deptrac не меняется. В тестах не добавляем `@phpstan-ignore`:
  используем существующие паттерны `WebTestCase`/`Crawler`, при необходимости
  сужаем nullable-атрибуты через `assertNotNull`/локальные переменные.
  CSS/JS не входят в PHPStan/deptrac; отдельных линтеров/сборщика не вводим.

### Ручной чек-лист (Q10-A; AC2–AC8, AC10, AC12; AC9 — ревью diff)

Результаты фиксируются QA/ревьюером в
`docs/task/009-secret-key-visibility/notes.md` (или в `review.md`).

1. **AC2 — pointer hold.** На `/` и `/p/{token}` ввести ключ, оставить фокус в
   поле, нажать и удерживать иконку мышью/пером: `input.type === 'text'`,
   `aria-pressed="true"`, `aria-label="Скрыть секретный ключ"`, значение не
   изменилось, форма не отправлена, фокус и каретка остались в поле. Отпустить
   на кнопке: `type="password"`, `aria-pressed="false"`,
   `aria-label="Показать секретный ключ"`, значение прежнее.
2. **AC3 — прерывания.** Пока ключ виден, проверить по очереди: уход указателя
   с кнопки, `pointercancel` (начать прокрутку/перетаскивание на touch),
   потеря захвата, уход фокуса с кнопки (после keyboard-toggle), переключение
   вкладки/сворачивание окна (`visibilitychange`), `Escape`. В каждом случае
   поле снова `type="password"`, `aria-pressed="false"`, имя
   «Показать секретный ключ», значение не изменилось, навигации/отправки нет.
3. **AC4 — touch.** iOS Safari 15+ и Android Chrome: длительное нажатие
   показывает ключ; не появляются контекстное меню, выделение, «лупа»/callout;
   форма не отправляется; страница прокручивается при начале жеста на кнопке;
   уход пальца/скролл/отпускание скрывают ключ; «залипания» нет.
4. **AC5 — клавиатура и AT.** `Tab` доводит до кнопки, focus-ring виден;
   `Space`/`Enter` переключают видимость (первый аккорд — показать, повторный —
   скрыть); `aria-pressed`/`aria-label` меняются; фокус остаётся на кнопке;
   `Escape` и уход фокуса скрывают; форма не отправляется. VoiceOver/NVDA:
   озвучиваются имя «Показать/Скрыть секретный ключ» и состояние
   pressed/not pressed, иконки не объявляются.
5. **AC6 — no-JS.** Отключить JS (DevTools) и перезагрузить обе страницы:
   кнопки не видно, поле `type="password"`, форма создаётся/отправляется как
   раньше, вёрстка не ломается, «мёртвой» кнопки нет.
6. **AC7 — визуал.** 360 px и десктоп: иконка справа внутри поля, не перекрывает
   текст, зона нажатия ≥40×40 (проверить 44×44 в DevTools), высота поля не
   уменьшилась; hover, pressed (eye-off + акцент), focus-visible; курсор
   `pointer`; `title` не является единственным носителем смысла;
   `forced-colors` и `prefers-reduced-motion`; нет layout shift; рамка и
   сообщение ошибки выводятся как раньше. Проверить Edge: нет второй нативной
   иконки показа пароля.
7. **AC8 — единый источник.** Ревью/grep: разметка кнопки/иконок есть только в
   `templates/form/secret_key_theme.html.twig`; в `create.html.twig` и
   `view.html.twig` нет копий; изменение темы отражается на обеих страницах.
8. **AC9 — границы.** `git diff --name-only`: только разрешённые пути; нет
   изменений в Domain/Application/Infrastructure/Crypto/Access, `migrations/`,
   маршрутах, валидации, CSRF, rate limiting, `no-store`.
9. **AC10 — утечки.** При включённом показе проверить DevTools Console, Network,
   Application (localStorage/sessionStorage/cookies), URL, буфер обмена и логи:
   значение ключа нигде не появляется; новых лог-вызовов нет; `no-store` на
   ответе reveal не изменился.
10. **AC11 — регрессии.** `make qa` зелёный; перечисленные flow-тесты проходят;
    статусы, тексты, валидация, лимиты, TTL и `no-store` не изменились.
11. **AC12 — браузеры.** Chrome, Edge, Firefox, Safari (последние две версии),
    iOS Safari 15+, Android Chrome: прогнать AC2–AC7; ошибок JS в консоли нет,
    ключ не «залипает», вёрстка не ломается.

## Пошаговый план реализации

Шаги атомарные и обратимы; шаги 1–2 можно объединить в один коммит, если
тесты пишутся вместе с разметкой.

1. **Разметка и стили (без JS).**
   - Создать `templates/form/secret_key_theme.html.twig` с блоком
     `secret_key_row` (см. выше).
   - В `CreateSecretType` и `RevealSecretType` добавить
     `'block_prefix' => 'secret_key'` к полю `secret`; тип остаётся
     `PasswordType::class`.
   - В `templates/paste/create.html.twig` и `templates/paste/view.html.twig`
     добавить `{% form_theme form 'form/secret_key_theme.html.twig' %}` в начале
     `{% block body %}`.
   - Добавить CSS в `public/styles/app.css`: `.secret-key-field`,
     `.secret-key-field .input { padding-right: 52px; }`, кнопка 44×44 справа,
     иконки 20×20, `[hidden]`, hover/pressed/focus-visible, `forced-colors`,
     `prefers-reduced-motion`, `touch-action`, `user-select`,
     `-webkit-touch-callout`, опционально `::-ms-reveal`/`::-ms-clear` для Edge.
   - Проверка: `make test` (существующие тесты зелёные); в серверном HTML
     кнопка есть, но `hidden`; без JS она не видна.
   - Коммит: `feat: render secret key visibility toggle`.

2. **AC1 functional-тесты.**
   - Добавить новые методы в `CreateSecretFlowTest` и `RevealSecretFlowTest`
     (см. «Тест-план»). Существующие тесты не переписывать.
   - Проверка: `make test`; оба новых теста зелёные; `make cs-check`.
   - Коммит: `test: cover secret key visibility markup`.

3. **JS-поведение.**
   - Создать `public/secret-key-visibility.js` (`defer`, без зависимостей):
     один проход по `[data-secret-key-visibility]`; если `PointerEvent`
     недоступен — не снимать `hidden`; найти input по `aria-controls`; если
     input не найден или не `type="password"` — оставить кнопку скрытой.
   - Обработчики:
     - `pointerdown` (primary, `button === 0`): `preventDefault()`, показать,
       запомнить `pointerId`;
     - `pointerup`/`pointercancel`/`pointerleave`/`lostpointercapture`:
       скрыть; при необходимости проверять `pointerId`;
     - `pointermove` во время удержания: если указатель вне
       `getBoundingClientRect()` — скрыть (страховка для implicit capture на
       touch);
     - `contextmenu` на кнопке: `preventDefault()` только во время
       взаимодействия/видимого состояния;
     - `click`: если `event.detail === 0` (клавиатура/AT/программный click) —
       toggle; клики от указателя (`detail > 0`) игнорировать, чтобы не было
       двойного срабатывания;
     - `keydown`/документный `Escape`: скрыть все видимые;
     - `blur` кнопки, `visibilitychange` → hidden, `window.blur`: скрыть;
     - снимать/ставить `hidden` только после успешной привязки обработчиков.
   - В `setVisible()`: сохранить `selectionStart/End/Direction` при фокусе в
     input, переключить `input.type` между `password`/`text`, восстановить
     selection в `try/catch`; синхронно обновить `aria-pressed` и `aria-label`;
     значение не читать, не логировать, не сохранять.
   - В `templates/base.html.twig` добавить
     `<script src="{{ asset('secret-key-visibility.js') }}" defer></script>`
     рядом с `copy.js`; inline-JS и CSP не менять.
   - Проверка: `make qa`; ручные AC2–AC6, AC10.
   - Коммит: `feat: toggle secret key visibility on hold`.

4. **Ручная проверка и документация.**
   - Прогнать чек-лист AC2–AC8, AC10, AC12; результаты записать в
     `docs/task/009-secret-key-visibility/notes.md` (создаётся на этапе QA, не
     в этом плане).
   - Проверить, что `docs/UBIQUITOUS_LANGUAGE.md` содержит строку
     `Secret key visibility toggle` (она уже добавлена аналитиком на G1); при
     отсутствии — добавить, значения существующих терминов не менять.
   - Убедиться, что ADR не требуется (см. ниже).
   - Коммит: `docs: record secret key visibility checklist` (если появится
     `notes.md`).

5. **Финальный гейт G3.** `make qa`, ревью diff, ручной чек-лист, commit
   выполняет человек.

## Миграции БД

**Нет.** Схема `secret`, ORM schema-anchor, репозиторий, SQL и `migrations/`
не затрагиваются. `make migrate` для задачи не требуется; откат — revert
кода без операций с данными.

## Влияние на стандарты (ADR, если нужно)

**ADR не требуется.** Стандарты `docs/ARCHITECTURE.md` не меняются:

- зависимости по-прежнему направлены внутрь; `Domain`/`Application` не
  импортируют Symfony/Twig/JS-понятия;
- form theme — штатный механизм Symfony Presentation, а не новый
  архитектурный паттерн; новых модулей, портов, событий, репозиториев и
  зависимостей нет;
- `docs/CONVENTIONS.md` соблюдается: имена `SecretKey`/
  `secret-key-visibility`, без `Manager`/`Helper`/`Util`, `final`-классы и
  `strict_types` не затрагиваются;
- `docs/QUALITY.md` не меняется: `make qa` остаётся тем же, новых
  `@phpstan-ignore` и baseline нет, порог покрытия Domain/Application не
  затрагивается (кода там нет);
- `docs/UBIQUITOUS_LANGUAGE.md` уже содержит термин
  `Secret key visibility toggle`; новых терминов не вводим, `Reveal` для
  показа поля не используем.

Отдельного ADR-гейта и правки `docs/ARCHITECTURE.md` нет. G2 нужен только для
утверждения этого плана.

## Риски и обратимые шаги

| Риск | Влияние | Мера |
|---|---|---|
| Per-form `{% form_theme %}` требует строки в двух шаблонах; новая форма `SecretKey` может её забыть | Кнопка не появится на новой форме | Обе текущие формы покрыты AC1-тестами; тема — единственный источник; при появлении третьей формы добавить строку и тест. Альтернатива — глобальный `form_themes`, но это правка `config/packages/twig.yaml` вне AC9 (вопрос G2) |
| Переопределение `form_row` разойдётся с Symfony 7.4 (`aria-describedby`, `aria-invalid`, порядок label/errors/widget/help) | Потеря доступности/изменение вывода ошибок | Блок повторяет логику `form_row_render` 7.4; AC1-тесты проверяют контракт; ручная проверка ошибок валидации |
| `attr`/`row_attr` из шаблонов перестанут применяться | Потеря `class="input"`, placeholder, рамки ошибок | В блоке сохранены `row_attr` и `form_widget(form, {attr: attr})`; AC1-тесты проверяют `class`, placeholder, `required`; ручная проверка ошибок |
| Per-form тема с `{% use 'form_div_layout.html.twig' %}` импортирует стандартные блоки и может затенять будущую глобальную кастомную тему | Неожиданный приоритет шаблонов | Сейчас глобальная тема — тот же layout, поведение не меняется; при появлении глобальной темы перепроверить приоритет и, при необходимости, вынести тему в глобальные `form_themes` отдельной задачей |
| `preventDefault()` на `pointerdown` для touch может помешать pan-жесту в отдельных браузерах | Блокировка прокрутки страницы | `touch-action: manipulation`; ручная проверка iOS/Android; если pan блокируется — не вызывать `preventDefault()` для `pointerType !== 'mouse'`, а сохранять/восстанавливать selection в input |
| Двойное переключение pointer + click | «Мигание»/залипание | `click` обрабатывается только при `detail === 0`; pointer-клики игнорируются; ручная проверка |
| Фокус/каретка уходят из поля при нажатии иконки | Нельзя продолжить ввод, ключ скрывается в момент показа | `pointerdown` с `preventDefault()`, сохранение/восстановление selection; не скрывать по `blur` поля; ручная проверка |
| Touch: контекстное меню, выделение, «лупа», блокировка прокрутки | Сломанный мобильный UX | `contextmenu` только на кнопке, `user-select`/`-webkit-touch-callout` только на кнопке, `touch-action: manipulation`, без `pointermove`-блокировки; ручная проверка iOS/Android |
| `display` в CSS перекроет `hidden` | Кнопка видна без JS | Явное `.secret-key-visibility[hidden] { display: none; }` + `:not([hidden])`; AC1 и no-JS чек-лист |
| Edge показывает нативную иконку показа пароля | Две иконки, путаница | Опционально скрыть `::-ms-reveal`/`::-ms-clear` только внутри `.secret-key-field`; проверка Edge |
| Иконка перекрывает текст / layout shift | Ошибки ввода, прыжки вёрстки | `padding-right: 52px`, absolute-кнопка 44×44, ширина input 100%, без transition размеров; 360 px в чек-листе |
| Ключ утекает в JS/логи/storage | Компрометация секрета | JS не читает `value`, нет `console`/storage/network; статические `aria-label`; ручная проверка AC10 |
| Нет JS-тестов | Регрессии pointer/touch не ловятся автоматически | Q10-A: AC1/AC11 functional + обязательный ручной чек-лист; без новой JS-инфраструктуры |
| PHPStan L10/Deptrac | Красный `make qa` | Новых классов в `src` нет; тесты в существующем стиле; deptrac не меняется; без новых ignore |
| `PointerEvent` недоступен/JS упал | «Мёртвая» кнопка | Кнопка `hidden`; JS снимает `hidden` только после привязки и при наличии `PointerEvent`; graceful degradation |

**Обратимость.** Каждый шаг — отдельный коммит. Revert JS-коммита возвращает
кнопку в `hidden` и сохраняет рабочую форму; revert разметки/стилей/темы
возвращает исходный HTML и CSS; revert тестов/доков независим. Миграций и
изменений данных нет, поэтому откат не требует операций с БД.

### Вопросы для G2

1. **Подключение темы.** Выбран per-form `{% form_theme %}` в двух шаблонах,
   чтобы не менять `config/packages/twig.yaml` и остаться в границах AC9.
   Подтвердить, что это предпочтительнее глобального `form_themes`; если нужен
   глобальный вариант — потребуется одна строка в `config/packages/twig.yaml`
   и явное расширение границ задачи.
2. **Размер зоны нажатия.** Выбран 44×44 (рекомендация Q5-A) при сохранении
   высоты input 40 px: кнопка абсолютная и прозрачная, поэтому не меняет
   layout, но её focus-ring/зона на 2 px выходят за границы поля по вертикали.
   Подтвердить на визуальном ревью; альтернатива — 40×40 ровно по высоте поля.
3. **Две inline-SVG.** Для состояний используются две декоративные иконки
   (`eye`/`eye-off`), обе `aria-hidden="true"`; переключение — CSS по
   `aria-pressed`. Если предпочтительнее одна SVG с двумя путями, это не
   меняет контракт, но меняет разметку темы.

### Ответы человека (G2)

| Вопрос | Решение |
|---|---|
| Подключение темы | **Per-form** `{% form_theme %}` в `create.html.twig`/`view.html.twig`; `config/packages/twig.yaml` не меняем |
| Размер зоны нажатия | **44×44** (прозрачная кнопка, допускается выход на 2 px по вертикали) |
| Иконки | **Две inline-SVG** (`eye`/`eye-off`), переключение CSS по `aria-pressed` |

## Definition of Done для задачи

1. `plan.md` утверждён на G2; ADR не требуется.
2. `make qa` зелёный: `cs-check`, PHPStan L10, deptrac, PHPUnit.
3. AC1 закрыт functional-тестами в `CreateSecretFlowTest` и
   `RevealSecretFlowTest`; AC11 подтверждён существующими тестами и `make qa`.
4. Ручной чек-лист AC2–AC8, AC10, AC12 выполнен; результаты зафиксированы в
   `docs/task/009-secret-key-visibility/notes.md` или `review.md`; критичных
   расхождений нет.
5. Diff ограничен `src/Secret/Presentation`, `templates/`, `public/`, тестами
   и документацией задачи. Нет изменений в Domain/Application/Infrastructure/
   Crypto/Access, `migrations/`, маршрутах, валидации, CSRF, rate limiting,
   `no-store`. Нет новых зависимостей, сборщика, inline-JS и правок CSP.
6. `docs/UBIQUITOUS_LANGUAGE.md` содержит термин
   `Secret key visibility toggle` (проверено; при отсутствии — добавлено без
   изменения существующих значений).
7. Серверный HTML обоих полей остаётся `type="password"` с неизменными
   `name`, `maxlength`, `autocomplete`, `required`, CSRF; кнопка в серверном
   HTML имеет `hidden`.
8. Изменение обратимо revert-коммитами без миграций и операций с данными.
9. Ревью пройдено; артефакты задачи лежат в
   `docs/task/009-secret-key-visibility/`; commit выполняет человек на G3.
