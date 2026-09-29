# AutoTranslate for FreshRSS

[English](#english) | [Русский](#русский)

---

## English

A FreshRSS extension that automatically translates articles from the feeds you choose. Translations are written directly into the article title and content in the database, so they are visible **everywhere**: in the FreshRSS web UI and in any mobile client (CapyReader, FeedMe, Reeder, etc.) connected via the GReader API.

### How it works

1. When a new article arrives in a selected feed, the `entry_before_add` hook queues it by attaching a **pending label** (default `To translate`). The article is inserted into the database instantly — no delays.
2. A background script (cron) takes queued entries in batches, translates the title and the HTML content and replaces them in the database.
3. The pending label is swapped for a **translated label** (default `Translated`).

Articles that are already in the target language are detected and skipped. Articles labelled as advertisement (e.g. by an ad-filter extension) can be skipped as well.

### Translation engines

| Engine | Key | Quality | Speed | Cost |
|---|---|---|---|---|
| **Google Translate** (default) | not needed | very good for news | fast (~0.5 s/article) | free public endpoint |
| **LLM via OpenRouter** | required | best markup preservation | slower | ~$0.0001/article (default `openai/gpt-4o-mini`) |

The engine is a dropdown in the settings; you can switch at any time.

### Installation

1. Copy the `xExtension-AutoTranslate` folder into the FreshRSS `extensions` directory:
   ```
   ./extensions/xExtension-AutoTranslate/
   ```
2. Enable it: `Settings → Extensions → AutoTranslate`.
3. Open the extension settings and:
   - choose the **target language**;
   - select the **feeds to translate** (empty selection = all feeds);
   - optionally rename the labels and enable the ad-skip;
   - if you choose the LLM engine — paste your [OpenRouter API key](https://openrouter.ai/keys).
4. Add the background worker to cron (every 5 minutes):
   ```bash
   */5 * * * * docker exec freshrss php /var/www/FreshRSS/extensions/xExtension-AutoTranslate/scripts/process_pending.php
   ```

### Settings reference

| Setting | Default | Description |
|---|---|---|
| Translation engine | Google | `google` or `llm` |
| Translate into | English | target language, one of 35+ in the dropdown |
| Pending label | `To translate` | queue tag name (any language) |
| Translated label | `Translated` | done tag name |
| Advertisement label | `Advertisement` | tag name of your ad-filter, used for skipping |
| Skip advertisement | on | do not translate ad-labelled articles |
| Wait before translating | 10 min | give an ad-filter time to label fresh entries |
| LLM API key / model | — / `openai/gpt-4o-mini` | only for the LLM engine |
| Max content length | 6000 chars | truncation before the LLM |
| Batch size | 10 | entries per cron run |
| Request delay | 1000 ms | pause between translation requests |
| Logging | off | detailed logs for debugging |

### Notes

- The original title/content are **replaced** by the translation (they are not kept anywhere). If you also want to keep the original, back up your database.
- The free Google endpoint is unofficial (the same one used by many open-source tools). If it ever starts misbehaving, switch the engine to LLM in the settings — no code changes needed.
- HTML markup is preserved: Google chunks the content at `</p>` boundaries, the LLM is instructed to keep all tags intact.
- All user-facing strings are available in English and Russian; FreshRSS picks the language from your profile (add more in `i18n/`).
- FreshRSS 1.20+ / PHP 7.4+ recommended. MIT licensed — see [LICENSE](LICENSE).

---

## Русский

Расширение FreshRSS для автоматического перевода статей из выбранных каналов. Перевод записывается прямо в заголовок и текст статьи в базе, поэтому виден **везде**: в веб-интерфейсе FreshRSS и в любых мобильных клиентах (CapyReader, FeedMe, Reeder и т.д.), подключённых через GReader API.

### Как это работает

1. Когда в выбранный канал приходит новая статья, хук `entry_before_add` ставит ей **метку очереди** (по умолчанию `To translate`). Статья попадает в базу мгновенно, без задержек.
2. Фоновый скрипт (cron) берёт статьи из очереди пачками, переводит заголовок и HTML-текст и заменяет их в базе.
3. Метка очереди меняется на **метку переведённых** (по умолчанию `Translated`).

Статьи, уже написанные на целевом языке, определяются и пропускаются. Статьи с меткой «реклама» (например, от расширения-фильтра рекламы) тоже можно пропускать.

### Движки перевода

| Движок | Ключ | Качество | Скорость | Цена |
|---|---|---|---|---|
| **Google Translate** (по умолчанию) | не нужен | очень хорошее для новостей | быстро (~0.5 с/статья) | бесплатный публичный эндпоинт |
| **LLM через OpenRouter** | нужен | лучшее сохранение разметки | медленнее | ~$0.0001/статья (по умолчанию `openai/gpt-4o-mini`) |

Движок выбирается в настройках, переключаться можно в любой момент.

### Установка

1. Скопируйте папку `xExtension-AutoTranslate` в директорию `extensions` FreshRSS:
   ```
   ./extensions/xExtension-AutoTranslate/
   ```
2. Включите: `Настройки → Расширения → AutoTranslate`.
3. Откройте настройки расширения и:
   - выберите **язык перевода**;
   - отметьте **каналы для перевода** (пустой выбор = все каналы);
   - при необходимости переименуйте метки и включите пропуск рекламы;
   - если выбрали движок LLM — вставьте [API-ключ OpenRouter](https://openrouter.ai/keys).
4. Добавьте фоновый скрипт в cron (каждые 5 минут):
   ```bash
   */5 * * * * docker exec freshrss php /var/www/FreshRSS/extensions/xExtension-AutoTranslate/scripts/process_pending.php
   ```

### Настройки

| Параметр | По умолчанию | Описание |
|---|---|---|
| Движок перевода | Google | `google` или `llm` |
| Переводить на | English | целевой язык, 35+ вариантов в списке |
| Метка очереди | `To translate` | имя тега очереди (любой язык) |
| Метка переведённых | `Translated` | имя тега выполненных |
| Метка рекламы | `Advertisement` | имя тега вашего фильтра рекламы, для пропуска |
| Пропускать рекламу | вкл | не переводить статьи с меткой рекламы |
| Ожидание перед переводом | 10 мин | дать фильтру рекламы время пометить свежие статьи |
| LLM API ключ / модель | — / `openai/gpt-4o-mini` | только для движка LLM |
| Максимальная длина текста | 6000 симв. | обрезка перед отправкой в LLM |
| Размер пачки | 10 | статей за запуск cron |
| Задержка между запросами | 1000 мс | пауза между запросами перевода |
| Логирование | выкл | подробные логи для отладки |

### Примечания

- Оригинальные заголовок и текст **заменяются** переводом (нигде не сохраняются). Если хотите хранить оригинал — сделайте резервную копию базы.
- Бесплатный Google-эндпоинт неофициальный (им пользуются многие open-source инструменты). Если начнёт сбоить — переключите движок на LLM в настройках, без правки кода.
- HTML-разметка сохраняется: Google-движок режет текст на чанки по границам `</p>`, LLM получает инструкцию сохранять все теги.
- Все строки интерфейса доступны на английском и русском; FreshRSS берёт язык из профиля пользователя (добавить другие можно в `i18n/`).
- Рекомендуется FreshRSS 1.20+ / PHP 7.4+. Лицензия MIT — см. [LICENSE](LICENSE).
