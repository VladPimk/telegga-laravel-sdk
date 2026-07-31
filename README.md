# Telegga Laravel SDK

Laravel-пакет для интеграции с API сервиса Telegga.

## Требования

- PHP 8.2 или новее.
- Laravel 11, 12 или 13.

## Установка

После публикации стабильной версии пакет будет устанавливаться через Composer:

```bash
composer require telegga/laravel-sdk
```

Laravel автоматически зарегистрирует сервис-провайдер пакета.

Опубликуйте конфигурацию и миграцию:

```bash
php artisan vendor:publish --tag=telegga-config
php artisan vendor:publish --tag=telegga-migrations
php artisan migrate
```

Укажите API-ключ и собственный токен для входящих webhook:

```dotenv
TELEGGA_API_KEY=tg_live_XXXXXXXXXXXXXXXX
TELEGGA_WEBHOOK_TOKEN=случайная-секретная-строка
```

Значение `TELEGGA_WEBHOOK_TOKEN` необходимо указать в админ-панели Telegga как bearer-токен webhook.

## Доступные Telegram-боты

Перед созданием подключений зарегистрируйте локально бота, доступного сервису Telegga:

```php
$bot = $telegga->addTelegramBot(
    botName: 'auctiongate_notification_bot',
);
```

Пакет принимает и сохраняет имя без символа `@`, как его возвращает API. При проверке через `GET /bots` полученный `username` строго сравнивается с локальным именем с учётом регистра. Пакет не сохраняет `bot_id` или другие данные API, а поле `uuid` модели генерируется локально.

Получение локально зарегистрированных ботов не выполняет запрос к API:

```php
$bots = $telegga->getAvailableBots();
```

Неиспользуемого бота можно удалить по локальному UUID:

```php
$telegga->deleteTelegramBot(uuid: $bot->uuid);
```

Удаление запрещено, если бот связан хотя бы с одним подключением.

## Создание подключения

Подключение может существовать независимо от пользователя проекта:

```php
$result = $telegga->createConnection(
    name: 'Иван',
    telegramBotUuid: $bot->uuid,
    email: 'ivan@example.com',
);
```

При необходимости передайте идентификатор пользователя проекта:

```php
$result = $telegga->createConnection(
    name: 'Иван',
    telegramBotUuid: $bot->uuid,
    email: 'ivan@example.com',
    userId: 42,
);
```

Поля `link_url` и `link_code` доступны в объекте результата.

## Повтор подключения

Повтор выполняется только явным вызовом и использует существующий UUID:

```php
$result = $telegga->retryConnection(uuid: $uuid);
```

Автоматические повторы пакет не выполняет.

Если локальная запись успела создаться, её UUID доступен в исключении:

```php
try {
    $result = $telegga->createConnection(
        name: 'Иван',
        telegramBotUuid: $bot->uuid,
        email: 'ivan@example.com',
    );
} catch (\Telegga\Laravel\Exceptions\ConnectionException $exception) {
    $uuid = $exception->connectionUuid;
}
```

## Управление подключением

Все операции принимают UUID локальной записи. Пакет строго сравнивает возвращённый Telegga `bot_username` с локальным `bot_name` с учётом регистра. Оба значения используются без символа `@`. Внутренние идентификаторы пользователя и бота локально не сохраняются.

Получение пользователя с привязками и группами:

```php
$connection = $telegga->getConnection(uuid: $uuid);
```

Обновление имени, email или статуса:

```php
$connection = $telegga->updateConnection(
    uuid: $uuid,
    data: [
        'display_name' => 'Иван Петров',
        'email' => 'new@example.com',
        'status' => 'disabled',
    ],
);
```

После успешного ответа API поля `display_name` и `email` синхронизируются с локальными `name` и `email`. Пустая строка в `email` очищает локальное значение. Статус пользователя локально не сохраняется.

Новый код для существующей привязки выпускается явно:

```php
$result = $telegga->regenerateConnectionCode(uuid: $uuid);
```

Отвязка от бота сохраняет локальную запись и устанавливает `is_connected` в `false`:

```php
$telegga->unlinkConnection(uuid: $uuid);
```

Полное удаление сначала удаляет пользователя в Telegga и только после успешного ответа удаляет локальную запись:

```php
$telegga->deleteConnection(uuid: $uuid);
```

## Отправка сообщений

Все типы сообщений отправляются через единый метод. Пакет получает активную привязку выбранного бота и добавляет `external_id`, `bot_id` и `type` в запрос:

```php
$result = $telegga->sendMessage(
    uuid: $connectionUuid,
    type: 'text',
    data: [
        'text' => 'Заказ <b>#1234</b> отправлен',
        'parse_mode' => 'HTML',
        'buttons' => [
            [
                [
                    'text' => 'Отследить',
                    'url' => 'https://example.com/track/1234',
                ],
            ],
        ],
        'disable_web_page_preview' => true,
        'disable_notification' => true,
    ],
);
```

Медиа отправляется через тот же метод после загрузки файла:

```php
$result = $telegga->sendMessage(
    uuid: $connectionUuid,
    type: 'photo',
    data: [
        'media_id' => $mediaId,
        'text' => 'Подпись к фотографии',
    ],
);
```

Для `location` в `data` передаются `latitude` и `longitude`, а для `contact` — `phone_number`, `first_name` и необязательный `last_name`.

Метод поддерживает типы `text`, `photo`, `video`, `document`, `audio`, `voice`, `animation`, `sticker`, `location` и `contact`. Набор `data` передаётся в API без жёсткого DTO, поэтому новые поля и типы можно использовать без обновления пакета. Переданные в `data` значения `external_id`, `bot_id` и `type` всегда заменяются значениями, определёнными пакетом, а `user_id` удаляется. Получателя сообщения определяет только UUID локального подключения.

Метод возвращает исходный объект ответа API с `message_id`, `status` и `created_at`. Сообщение и его статус локально не сохраняются.

## Статус сообщения

Статус доставки запрашивается по `message_id`, полученному при отправке:

```php
$message = $telegga->getMessage(
    messageId: $messageId,
);
```

Метод возвращает исходный объект ответа API со статусом, количеством попыток, временем доставки и `delivery_attempts`.

## История сообщений пользователя

История всегда запрашивается по UUID локального подключения. Пакет находит пользователя Telegga по локальному `external_id`, получает его внутренний `user_id` и обязательно передаёт этот идентификатор в `GET /messages`:

```php
$page = $telegga->getMessages(
    uuid: $connectionUuid,
    status: 'sent',
    from: new DateTimeImmutable('2026-07-01T00:00:00+03:00'),
    to: new DateTimeImmutable('2026-07-30T23:59:59+03:00'),
    cursor: $cursor,
);

foreach ($page->data as $message) {
    $messageId = $message->message_id;
}

$nextCursor = $page->next_cursor;
```

Параметры `status`, `from`, `to` и `cursor` необязательны. Даты передаются в API в формате RFC 3339.

Поле `data` возвращается как `Collection` объектов без жёсткого DTO, поэтому новые поля API остаются доступными. `next_cursor` содержит курсор следующей страницы или `null`. Получение полной истории сервиса без указания локального подключения публичным интерфейсом не поддерживается.

## Медиафайлы

Файл загружается multipart-запросом из доступного для чтения локального пути:

```php
$media = $telegga->uploadMedia(
    path: storage_path('app/photo.jpg'),
);

$mediaId = $media->media_id;
```

Метаданные ранее загруженного файла запрашиваются по `media_id`:

```php
$metadata = $telegga->getMedia(
    mediaId: $mediaId,
);
```

Оба метода возвращают исходные объекты API без жёсткого DTO. Пакет не определяет MIME-тип и не проверяет ограничения размера самостоятельно: содержимое, допустимый тип и лимиты проверяет Telegga API. Файл и `media_id` локально не сохраняются.

## Группы

Группа создаётся для бота локального подключения. Пакет получает `bot_id` самостоятельно:

```php
$group = $telegga->createGroup(
    uuid: $connectionUuid,
    name: 'VIP',
    description: 'VIP-клиенты',
);

$groups = $telegga->getGroups(uuid: $connectionUuid);
```

Получение, изменение и удаление используют `group_id`, возвращённый API:

```php
$group = $telegga->getGroup(groupId: $groupId);

$group = $telegga->updateGroup(
    groupId: $groupId,
    data: [
        'name' => 'Premium',
        'description' => 'Premium-клиенты',
    ],
);

$telegga->deleteGroup(groupId: $groupId);
```

Управлять членством одного подключения можно через маршруты пользователя:

```php
$result = $telegga->addConnectionToGroup(
    uuid: $connectionUuid,
    groupId: $groupId,
);

$telegga->removeConnectionFromGroup(
    uuid: $connectionUuid,
    groupId: $groupId,
);
```

Групповые маршруты принимают локальные UUID, которые пакет преобразует во внутренние `user_id` Telegga:

```php
$result = $telegga->addGroupMembers(
    groupId: $groupId,
    uuids: [$firstUuid, $secondUuid],
);

$telegga->removeGroupMember(
    groupId: $groupId,
    uuid: $firstUuid,
);
```

Повторяющиеся UUID удаляются перед отправкой. API принимает до 10 000 участников за запрос, но для каждого уникального локального UUID пакет сначала выполняет поиск пользователя Telegga. При больших объёмах передавайте подключения отдельными порциями с учётом лимита API. Автоматические повторы пакет не выполняет.

Группы и членство локально не сохраняются. Объекты и коллекции возвращаются без жёстких DTO.

## Рассылки

Рассылка всем подключённым пользователям бота запускается по UUID локального подключения:

```php
$broadcast = $telegga->startBroadcast(
    uuid: $connectionUuid,
    type: 'text',
    data: [
        'text' => 'Акция!',
    ],
);
```

Чтобы ограничить получателей участниками группы, передайте `groupId`:

```php
$broadcast = $telegga->startBroadcast(
    uuid: $connectionUuid,
    type: 'photo',
    data: [
        'media_id' => $mediaId,
        'text' => 'Новая акция',
    ],
    groupId: $groupId,
);
```

Поля сообщения передаются через открытый `data`, как в `sendMessage()`. Значения `external_id`, `user_id`, `bot_id`, `group_id` и `type` из `data` удаляются или заменяются параметрами, которые определил пакет.

Прогресс и отмена запрашиваются по `broadcast_id`:

```php
$broadcast = $telegga->getBroadcast(
    broadcastId: $broadcastId,
);

$result = $telegga->cancelBroadcast(
    broadcastId: $broadcastId,
);
```

Рассылки и их прогресс локально не сохраняются. Все методы возвращают исходные объекты API без жёстких DTO.

## Входящие webhook

Пакет автоматически регистрирует маршрут:

```text
POST /webhooks/v1/telegram/connect-account
```

В админ-панели Telegga укажите базовый адрес проекта. Telegga самостоятельно добавит к нему путь webhook.

Каждый запрос должен содержать токен проекта:

```http
Authorization: Bearer <TELEGGA_WEBHOOK_TOKEN>
```

Пустой, отсутствующий или неверный токен возвращает `401`. Для сравнения токенов используется безопасное сравнение через `hash_equals`.

Событие `user.linked` находит локальную запись по совпадению `external_id` с `telegram_connected_users.uuid`. Полученный `bot_username` также напрямую сопоставляется с именем связанного локального бота. Оба значения используются без символа `@`. После этого пакет устанавливает `is_connected = true`. Повторная доставка события безопасна и возвращает `204`.

Событие `test` и неизвестные типы событий возвращают `204`. Неизвестный `external_id` также подтверждается кодом `204`, потому что повторная доставка не сможет создать отсутствующую локальную запись.

Некорректный payload возвращает `400`. Ошибка локальной базы данных преобразуется в серверный ответ `500`, чтобы Telegga повторила доставку согласно своей политике.

## Статус

Реализованы HTTP-клиент, локальное управление доступными ботами, модель подключения с явно выбранным ботом, создание и управление подключением, явный повтор неудачной отправки, отправка всех типов сообщений, получение статуса сообщения, история сообщений пользователя, загрузка медиафайлов, группы, управление участниками, рассылки и входящие webhook.

## Лицензия

Пакет распространяется по лицензии MIT.
