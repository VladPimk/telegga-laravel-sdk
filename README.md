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

Укажите API-ключ:

```dotenv
TELEGGA_API_KEY=tg_live_XXXXXXXXXXXXXXXX
```

## Создание подключения

Подключение может существовать независимо от пользователя проекта:

```php
$result = $telegga->createConnection(
    name: 'Иван',
    email: 'ivan@example.com',
);
```

При необходимости передайте идентификатор пользователя проекта:

```php
$result = $telegga->createConnection(
    name: 'Иван',
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
        email: 'ivan@example.com',
    );
} catch (\Telegga\Laravel\Exceptions\ConnectionException $exception) {
    $uuid = $exception->connectionUuid;
}
```

## Отправка текстового сообщения

Сообщение отправляется по UUID локального подключения. Пакет самостоятельно получает активный бот пользователя:

```php
$result = $telegga->sendText(
    uuid: $connectionUuid,
    text: 'Заказ <b>#1234</b> отправлен',
    parseMode: 'HTML',
    buttons: [
        [
            [
                'text' => 'Отследить',
                'url' => 'https://example.com/track/1234',
            ],
        ],
    ],
    disableWebPagePreview: true,
    disableNotification: true,
);
```

Метод возвращает исходный объект ответа API с `message_id`, `status` и `created_at`. Сообщение и его статус локально не сохраняются.

## Статус сообщения

Статус доставки запрашивается по `message_id`, полученному при отправке:

```php
$message = $telegga->getMessage(
    messageId: $messageId,
);
```

Метод возвращает исходный объект ответа API со статусом, количеством попыток, временем доставки и `delivery_attempts`.

## Статус

Реализованы HTTP-клиент, локальная модель подключения, создание подключения, явный повтор неудачной отправки, отправка текстовых сообщений и получение статуса сообщения. Остальные маршруты API и обработка вебхуков добавляются поэтапно.

## Лицензия

Пакет распространяется по лицензии MIT.
