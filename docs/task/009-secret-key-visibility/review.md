# Ревью: 009-secret-key-visibility

## Вердикт: approved

Блокирующих дефектов в коде и diff не найдено. Изменения укладываются в
границы задачи (Presentation/templates/assets/tests/docs), серверный контракт
поля `SecretKey` не изменён, AC1/AC8/AC9/AC11 закрыты, AC10 подтверждён по
коду. AC2–AC7, инструментальная часть AC10 и AC12 требуют ручного
подтверждения на G3: результаты чек-листа в артефактах отсутствуют, см. N1.

**Условие допуска к коммиту (G3):** человек обязан выполнить и зафиксировать
ручной чек-лист AC2–AC8/AC10/AC12 (`plan.md:196-247`) — прежде всего
iOS Safari/Android Chrome (прокрутка, контекстное меню, «залипание»),
клавиатуру/скринридер, no-JS, 360 px, Edge и DevTools (Console/Network/
Application). До этой фиксации вердикт означает «готово к G3», а не «готово к
коммиту».

## Соответствие requirements.md

| AC | Результат | Основание |
|---|---|---|
| AC1 | выполнен | `templates/form/secret_key_theme.html.twig:21-41` выводит input + кнопку; `block_prefix => 'secret_key'` в `CreateSecretType.php:23` и `RevealSecretType.php:18`; серверный HTML: `type="password"`, `hidden` на кнопке. Тесты: `CreateSecretFlowTest.php:70-101`, `RevealSecretFlowTest.php:86-120` проверяют не просто наличие, а контракт: ровно один `input[type="password"]`, `name`, `maxlength`, `autocomplete`, `required`, `class`, placeholder, `aria-controls === id`, `aria-label`, `aria-pressed`, `hidden`, отсутствие `name` у кнопки, `aria-hidden` у всех SVG. |
| AC2 | реализован, нужен ручной прогон | `public/secret-key-visibility.js:73-99` (`pointerdown` → показать, `pointerup` → скрыть); `preventDefault()` сохраняет фокус/каретку. |
| AC3 | реализован, нужен ручной прогон | `public/secret-key-visibility.js:83-133`: `pointermove` + bounds-check, `pointerleave`, `pointercancel`, `lostpointercapture`, `blur` кнопки, `visibilitychange`, `Escape`, `window.blur`. |
| AC4 | реализован, нужен ручной прогон | `public/styles/app.css:58` (`touch-action: manipulation`, `user-select: none`, `-webkit-touch-callout: none`), `contextmenu` только на кнопке (`secret-key-visibility.js:105-109`). Остаточный риск по прокрутке — N3. |
| AC5 | реализован, нужен ручной прогон | `click` с `detail === 0` (`secret-key-visibility.js:111-117`), синхронные `aria-pressed`/`aria-label` (`:42-44`), сброс на `Escape`/`blur` (`:119-125`). |
| AC6 | выполнен (серверная часть) + ручной no-JS прогон | кнопка `hidden` в HTML; JS снимает `hidden` только после привязки обработчиков (`secret-key-visibility.js:135`) и при наличии `PointerEvent` (`:4-6`). |
| AC7 | реализован, нужен визуальный прогон | `public/styles/app.css:55-66` (44×44, `padding-right: 52px`, hover/pressed/focus-visible, иконки eye/eye-off), `:104-108` (`forced-colors`), `:57` (`::-ms-reveal`/`::-ms-clear`), `:109-111` (`prefers-reduced-motion`). |
| AC8 | выполнен | разметка кнопки/иконок только в `templates/form/secret_key_theme.html.twig`; в `templates/paste/create.html.twig:4` и `templates/paste/view.html.twig:4` — только `{% form_theme %}`. |
| AC9 | выполнен | `git diff --name-only`: только `src/Secret/Presentation/*`, `templates/`, `public/`, `tests/Functional/`, `docs/`. Нет изменений в Domain/Application/Infrastructure/Crypto/Access, `migrations/`, маршрутах, валидации, CSRF, rate limiter, `no-store`. |
| AC10 | подтверждён по коду, нужен ручной DevTools-прогон | JS не читает `input.value`, нет `console`, storage/cookie, сети, clipboard, URL; `data-*`/`aria-*` статические; серверный HTML остаётся `type="password"`. |
| AC11 | выполнен | существующие тесты не переписаны (diff — только добавления); `make qa` по отчёту QA: 175 tests, 1732 assertions, зелёный. |
| AC12 | нужен ручной прогон | браузерная матрица не автоматизирована (Q10-A), результатов в артефактах нет. |

Отдельно проверено, что `templates/form/secret_key_theme.html.twig:3-17`
дословно повторяет логику `form_row` из Symfony 7.4.18
(`label → errors → widget → help`, `aria-describedby`, `aria-invalid`), а
`form_widget(form, {attr: attr})` мёржит локальные ARIA-атрибуты с `attr`
поля: `FormRenderer::searchAndRenderBlock()` делает
`array_replace($scopeVariables['attr'], $variables['attr'])`. Поэтому
`class="input"`, placeholder, `maxlength`, `autocomplete`, `required` и
`row_attr: {class: 'field'}` сохраняются — это же подтверждают AC1-тесты.

Новый UI-термин добавлен: `docs/UBIQUITOUS_LANGUAGE.md:31`
(`Secret key visibility toggle`), значения существующих терминов не изменены.

## Соответствие plan.md

- Шаги 1–4 выполнены в заявленных файлах; состав diff совпадает с
  `plan.md:26-53`.
- Отклонений по существу нет: поле остаётся `PasswordType`, тема подключается
  per-form, кнопка `hidden`, JS `defer`, новых зависимостей/сборщика/inline-JS
  нет, ADR не требуется, миграций нет.
- Реализованные варианты соответствуют рекомендациям плана (per-form
  `{% form_theme %}`, 44×44, две inline-SVG).
- **Пробел артефактов (не блокирует код):** в `plan.md:376-390` есть
  «Вопросы для G2», но ответы человека нигде не зафиксированы. Реализация
  совпадает с рекомендованными вариантами, поэтому считаю план де-факто
  исполненным; на G3 стоит подтвердить, что G2 действительно был пройден.
- **Пробел артефактов (условие G3):** DoD п.4 (`plan.md:398-400`) требует
  выполнить и зафиксировать ручной чек-лист; `notes.md` в задаче отсутствует,
  результаты в `review.md` не переданы. Это N1.

## Соответствие docs/ARCHITECTURE.md и docs/CONVENTIONS.md

- Направление зависимостей не нарушено: изменения только в
  `Secret/Presentation`, шаблонах, публичных ассетах, тестах и документации
  (`ARCHITECTURE.md` §3.3). Domain/Application/Infrastructure/Crypto/Access не
  затронуты, фреймворк в домен не проникает.
- `block_prefix` и form-theme — штатные rendering-механизмы Symfony в
  Presentation, а не новый архитектурный паттерн; новых модулей, портов,
  событий, репозиториев и зависимостей нет.
- Deptrac не менялся, новых PHP-классов в `src/` нет, поэтому правил
  `docs/QUALITY.md` §2.2 изменение не касается; нарушений 0.
- Имена соответствуют `CONVENTIONS.md` §2 и `UBIQUITOUS_LANGUAGE.md`:
  `SecretKey`/`secret-key-visibility`, без `password`/`paste` в новом коде;
  легаси wire-имена `create_paste`/`unlock_paste` не менялись.
- `strict_types`/`final` не применимы: новых PHP-классов нет; существующие
  формы не потеряли `declare(strict_types=1)` и `final`.
- `Manager`/`Helper`/`Util` не вводились; бизнес-логики в шаблонах/JS нет.
- Форматирование не рецензируется (`CONVENTIONS.md` §9); `git diff --check`
  чист.

## Найденные проблемы (блокирующие / неблокирующие)

### Блокирующие

Нет.

### Неблокирующие

**N1. Ручной чек-лист AC2–AC8/AC10/AC12 не выполнен и не зафиксирован.**
`docs/task/009-secret-key-visibility/plan.md:398-400` (DoD п.4) требует
результатов в `notes.md` или `review.md`; `docs/task/009-secret-key-visibility/notes.md`
отсутствует, в переданных материалах QA есть только результат `make qa`
(175 tests / 1732 assertions). Это не дефект кода, но незакрытый пункт DoD и
условие G3. **Требуемый результат:** человек прогоняет чек-лист
`plan.md:196-247` и фиксирует результаты (успех/расхождения) в `notes.md` или
`review.md` до коммита.

**N2. Переключение клавиатуры/AT опирается только на `event.detail === 0`.**
`public/secret-key-visibility.js:111-117`; риск двойного срабатывания
pointer-последовательности + синтетического `click` описан в
`requirements.md:538` и `plan.md:360`, где мера — «подавлять синтетический
click после pointer-последовательности». Явного флага «только что был
pointer» в коде нет: подавление достигается `preventDefault()` на
`pointerdown` и фильтром `detail`. На части touch/AT-окружений `click` от
указателя/AT может прийти с `detail === 0`, что приведёт к повторному
переключению и видимому ключу после отпускания (нарушение AC4/AC5). **Что
проверить:** AC4 (iOS Safari 15+, Android Chrome) и AC5 (VoiceOver/NVDA,
программный click). Если воспроизводится — добавить guard по факту
pointer-взаимодействия, не меняя контракт.

**N3. Безусловный `preventDefault()` на `pointerdown` для touch.**
`public/secret-key-visibility.js:73-81`. `plan.md:359` прямо предусматривает:
если pan блокируется, не вызывать `preventDefault()` для
`pointerType !== 'mouse'`, а сохранять/восстанавливать selection. AC4 и
`requirements.md:289` требуют, чтобы страница оставалась прокручиваемой.
Статически это не доказать; риск не блокирует ревью, но обязан быть проверен
на реальных iOS/Android. **Требуемый результат:** прокрутка страницы при
начале жеста на кнопке работает; при провале — применить условный
`preventDefault()` из плана.

## Протечки абстракций между слоями

Протечек не обнаружено.

- `src/Secret/Presentation/Http/Form/*` передаёт только rendering-опцию
  `block_prefix`; это не публичный серверный контракт и не доменное знание
  (`plan.md:76-85`).
- Form-theme не обращается к `Infrastructure`, репозиторию, ORM или SQL;
  разметка и ARIA локализованы в Presentation.
- Публичный JS не знает о домене: работает с `[data-secret-key-visibility]` и
  связанным input, не читает и не пишет значение ключа.
- Единственная осознанная связанность — копия логики `form_row` из Symfony
  7.4 (`templates/form/secret_key_theme.html.twig:3-17`). Это Presentation-
  уровень, риск дрейфа при апгрейде Symfony уже отмечен в `plan.md:356`;
  на архитектурные границы не влияет.

## Тестовое покрытие

- AC1 закрыт functional-тестами в обоих flow-тестах
  (`CreateSecretFlowTest.php:70-101`, `RevealSecretFlowTest.php:86-120`);
  проверяется именно контракт, а не факт наличия элемента.
- AC11 подтверждён неизменными существующими тестами (diff — только
  добавления) и отчётом QA: `make qa` зелёный, 175 tests / 1732 assertions.
- Новых `@phpstan-ignore` нет; PHP-классов в `src/` не добавлено, поэтому
  PHPStan L10/deptrac/покрытие Domain/Application не затронуты.
- Unit/application-тесты не требуются: Domain/Application не менялись.
- **Информационно (не блокирует):** AC1-тесты не проверяют
  `aria-describedby`/`aria-invalid` и сохранение `row_attr: {class: 'field'}`.
  Риск низкий, потому что блок дословно повторяет официальный `form_row`
  Symfony 7.4.18; при желании эти проверки можно добавить, чтобы ловить дрейф
  при апгрейде Symfony.
- **Достаточность доказательств для G3:** серверная часть (AC1, AC6-hidden,
  AC9, AC11) подтверждена автоматически; AC2–AC5, AC7, AC10-runtime и AC12
  автоматически не проверяются по решению Q10-A. Результатов ручного
  чек-листа в артефактах нет (N1); ревьюер их не подменяет собственными
  предположениями.

## Замечания по безопасности

- Ключ не попадает в DOM-атрибуты: `data-secret-key-visibility`,
  `aria-controls`, `aria-label`, `aria-pressed` статичны; `aria-controls`
  содержит только `id` поля. Значение в `data-*`/`aria-*`/`title` не
  записывается.
- JS не читает `input.value`, не логирует, не пишет в
  `localStorage`/`sessionStorage`/cookie, не копирует в clipboard, не делает
  сетевых запросов; меняется только `input.type` и ARIA-состояние.
- Серверный HTML обоих полей остаётся `type="password"`, `name`, CSRF,
  `maxlength`, `autocomplete` и `required` не изменены (AC1-тесты).
- `Cache-Control: no-store`, rate limiting, CSRF, срок жизни и крипто-формат
  не затронуты (`git diff --name-only`, AC9). Новых лог-вызовов нет.
- CSP не менялся, inline-JS не добавлен; скрипт — отдельный same-origin
  `defer`-файл.
- **Остаточное ручное требование:** AC10 в рантайме (Console, Network,
  Application, URL, clipboard) должно быть проверено человеком на G3;
  статически утечек не найдено.
- Остаточный риск «ключ виден на экране/скриншоте» осознанно вне модели
  угроз (`requirements.md:468-470`) и задачей не расширяется.
