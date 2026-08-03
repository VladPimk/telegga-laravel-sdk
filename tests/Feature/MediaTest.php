<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Exceptions\MediaException;
use Telegga\Laravel\Exceptions\TeleggaApiException;

it('загружает медиафайл multipart запросом без потери полей ответа', function (): void {
    $path = tempnam(directory: sys_get_temp_dir(), prefix: 'telegga-media-');

    if ($path === false) {
        test()->fail('Не удалось создать временный файл.');
    }

    file_put_contents(filename: $path, data: 'file-content');

    try {
        Http::preventStrayRequests();
        Http::fake([
            'api.telegga.net/api/v1/media' => Http::response([
                'media_id' => 'media-1',
                'mime_type' => 'image/jpeg',
                'size' => 12,
                'filename' => basename(path: $path),
                'new_api_field' => 'new-value',
            ], 201),
        ]);

        $media = app(TeleggaInterface::class)->uploadMedia(
            path: $path,
        );

        expect($media)
            ->toBeInstanceOf(stdClass::class)
            ->and($media->media_id)
            ->toBe('media-1')
            ->and($media->mime_type)
            ->toBe('image/jpeg')
            ->and($media->new_api_field)
            ->toBe('new-value');

        Http::assertSent(function (Request $request) use ($path): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.telegga.net/api/v1/media'
                && $request->isMultipart()
                && $request->hasFile(
                    name: 'file',
                    filename: basename(path: $path),
                );
        });
    } finally {
        if (is_file(filename: $path)) {
            unlink(filename: $path);
        }
    }
});

it('получает метаданные медиафайла без потери новых полей ответа', function (): void {
    $mediaId = 'e97d00ad-0000-4000-8000-000000000000';

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/media/{$mediaId}" => Http::response([
            'media_id' => $mediaId,
            'mime_type' => 'image/jpeg',
            'size' => 123456,
            'filename' => 'photo.jpg',
            'new_api_field' => 'new-value',
        ]),
    ]);

    $media = app(TeleggaInterface::class)->getMedia(
        mediaId: $mediaId,
    );

    expect($media)
        ->toBeInstanceOf(stdClass::class)
        ->and($media->media_id)
        ->toBe($mediaId)
        ->and($media->filename)
        ->toBe('photo.jpg')
        ->and($media->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request) use ($mediaId): bool {
        return $request->method() === 'GET'
            && $request->url() === "https://api.telegga.net/api/v1/media/{$mediaId}";
    });
});

it('не отправляет запрос с пустым путём к медиафайлу', function (): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->uploadMedia(path: '   ');
    } catch (MediaException $exception) {
        expect($exception->getMessage())
            ->toBe('Media file path cannot be empty.')
            ->and($exception->filePath)
            ->toBe('   ')
            ->and($exception->mediaId)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeNull();

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение MediaException.');
});

it('скрывает ошибку недоступного локального файла', function (): void {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'missing-telegga-media-file';

    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->uploadMedia(path: $path);
    } catch (MediaException $exception) {
        expect($exception->filePath)
            ->toBe($path)
            ->and($exception->mediaId)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeInstanceOf(InvalidArgumentException::class);

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение MediaException.');
});

it('скрывает ошибку api при загрузке медиафайла', function (): void {
    $path = tempnam(directory: sys_get_temp_dir(), prefix: 'telegga-media-');

    if ($path === false) {
        test()->fail('Не удалось создать временный файл.');
    }

    file_put_contents(filename: $path, data: 'file-content');

    try {
        Http::preventStrayRequests();
        Http::fake([
            'api.telegga.net/api/v1/media' => Http::response([
                'error' => [
                    'code' => 'invalid_request',
                    'message' => 'Media file is invalid.',
                ],
            ], 400),
        ]);

        try {
            app(TeleggaInterface::class)->uploadMedia(
                path: $path,
            );
        } catch (MediaException $exception) {
            expect($exception->filePath)
                ->toBe($path)
                ->and($exception->mediaId)
                ->toBeNull()
                ->and($exception->getPrevious())
                ->toBeInstanceOf(TeleggaApiException::class)
                ->and($exception->getPrevious()?->apiCode)
                ->toBe('invalid_request')
                ->and($exception->getPrevious()?->status)
                ->toBe(400);

            return;
        }

        test()->fail('Ожидалось исключение MediaException.');
    } finally {
        if (is_file(filename: $path)) {
            unlink(filename: $path);
        }
    }
});

it('не отправляет запрос с пустым идентификатором медиафайла', function (): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->getMedia(mediaId: '   ');
    } catch (MediaException $exception) {
        expect($exception->getMessage())
            ->toBe('Media identifier cannot be empty.')
            ->and($exception->mediaId)
            ->toBe('   ')
            ->and($exception->filePath)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeNull();

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение MediaException.');
});

it('скрывает ошибку api при получении метаданных медиафайла', function (): void {
    $mediaId = 'e97d00ad-0000-4000-8000-000000000000';

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/media/{$mediaId}" => Http::response([
            'error' => [
                'code' => 'not_found',
                'message' => 'Media was not found.',
            ],
        ], 404),
    ]);

    try {
        app(TeleggaInterface::class)->getMedia(
            mediaId: $mediaId,
        );
    } catch (MediaException $exception) {
        expect($exception->mediaId)
            ->toBe($mediaId)
            ->and($exception->filePath)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('not_found')
            ->and($exception->getPrevious()?->status)
            ->toBe(404);

        return;
    }

    test()->fail('Ожидалось исключение MediaException.');
});

it('отклоняет успешный ответ медиа с некорректным json', function (): void {
    $mediaId = 'e97d00ad-0000-4000-8000-000000000000';

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/media/{$mediaId}" => Http::response(
            body: 'not-json',
            status: 200,
        ),
    ]);

    try {
        app(TeleggaInterface::class)->getMedia(
            mediaId: $mediaId,
        );
    } catch (MediaException $exception) {
        expect($exception->mediaId)
            ->toBe($mediaId)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('invalid_response');

        return;
    }

    test()->fail('Ожидалось исключение MediaException.');
});
